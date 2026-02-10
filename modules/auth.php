<?php

/**
 * 认证与账号安全相关接口
 */

require_once __DIR__ . '/../functions.php';

function handle_auth_action(string $action): void
{
    switch ($action) {
        case 'send_code':
            api_send_verification_code();
            break;
        case 'register':
            api_register();
            break;
        case 'login':
            api_login();
            break;
        case 'logout':
            api_logout();
            break;
        case 'me':
            api_me();
            break;
        case 'admin_login':
            api_admin_login();
            break;
        case 'reset_password':
            api_reset_password();
            break;
        default:
            json_error('未知 action：' . $action);
    }
}

/**
 * 发送验证码
 */
function api_send_verification_code(): void
{
    $qq = input('qq');
    $type = input('type', 'register'); // register 或 reset

    if (empty($qq)) {
        json_error('QQ号不能为空');
    }

    if (!preg_match('/^\d{5,12}$/', $qq)) {
        json_error('QQ号格式不正确');
    }

    // 重置密码时，检查QQ号是否已注册
    if ($type === 'reset') {
        $user = db_fetch_one('SELECT id FROM users WHERE qq = ?', [$qq]);
        if (!$user) {
            json_error('账号未注册');
        }
    }

    // 检查60秒内是否已发送过验证码
    $email = $qq . '@qq.com';
    $recentCode = db_fetch_one(
        'SELECT * FROM verification_codes WHERE email = ? AND type = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) ORDER BY id DESC LIMIT 1',
        [$email, $type]
    );

    if ($recentCode) {
        json_error('验证码发送过于频繁，请60秒后再试');
    }

    require_once __DIR__ . '/../smtp.php';

    // 生成验证码
    $code = generate_verification_code(6);

    // 保存验证码
    save_verification_code($email, $code, $type);

    // 发送邮件
    $sent = send_email_verification($email, $code, $type);

    if (!$sent) {
        json_error('验证码发送失败，请稍后重试');
    }

    json_response(null, 0, '验证码已发送到您的QQ邮箱');
}

/**
 * 用户注册
 */
function api_register(): void
{
    $qq = input('qq');
    $mobile = input('mobile');
    $password = input('password');
    $confirm = input('confirm_password');
    $code = input('code');

    if (!$qq || !$mobile || !$password || !$confirm) {
        json_error('参数不完整');
    }

    if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
        json_error('手机号格式不正确');
    }

    if (!preg_match('/^\d{6}$/', $password)) {
        json_error('密码必须为6位数字');
    }

    if ($password !== $confirm) {
        json_error('两次密码不一致');
    }

    // 使用QQ号@qq.com作为邮箱
    $email = $qq . '@qq.com';

    // 验证QQ验证码
    if (empty($code)) {
        json_error('请填写验证码');
    }

    require_once __DIR__ . '/../smtp.php';
    if (!verify_code($email, $code, 'register')) {
        json_error('验证码错误或已过期');
    }

    // 检查手机号是否已存在
    $exists = db_fetch_one('SELECT id FROM users WHERE mobile = ?', [$mobile]);
    if ($exists) {
        json_error('该手机号已注册');
    }

    $hash = hash_password($password);
    $ip = client_ip();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $sql = 'INSERT INTO users (qq, mobile, password, balance, status, is_locked, failed_login_count, register_ip, register_device, created_at, updated_at)
            VALUES (:qq, :mobile, :password, 0, 1, 0, 0, :ip, :ua, NOW(), NOW())';

    db_execute($sql, [
        ':qq'       => $qq,
        ':mobile'   => $mobile,
        ':password' => $hash,
        ':ip'       => $ip,
        ':ua'       => $userAgent,
    ]);

    $userId = (int)db_last_insert_id();
    add_operation_log(1, $userId, 'user_register', ['mobile' => $mobile, 'qq' => $qq]);

    json_response(['user_id' => $userId], 0, '注册成功');
}

/**
 * 用户登录（普通用户）
 */
function api_login(): void
{
    $mobile = input('mobile');
    $password = input('password');
    $ip = client_ip();

    if (!$mobile || !$password) {
        json_error('手机号和密码必填');
    }

    // 1. 检查 IP 是否被锁定
    $ipLock = db_fetch_one('SELECT * FROM ip_locks WHERE ip = ?', [$ip]);
    if ($ipLock && (int)$ipLock['is_locked'] === 1 && strtotime($ipLock['locked_until']) > time()) {
        json_error('当前IP已被锁定，请联系管理员解锁');
    }

    $user = db_fetch_one('SELECT * FROM users WHERE mobile = ?', [$mobile]);
    if (!$user) {
        record_login_log(1, 0, $ip, 0, '账号或密码错误');
        json_error('账号或密码错误');
    }

    // 检查是否封禁
    if ((int)$user['status'] === 0) {
        record_login_log(1, (int)$user['id'], $ip, 0, '账号已被封禁');
        json_error('账号已被封禁，请联系管理员');
    }

    // 检查是否被锁定
    if ((int)$user['is_locked'] === 1 && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
        record_login_log(1, (int)$user['id'], $ip, 0, '账号已锁定');
        json_error('账号已锁定，请稍后再试或联系管理员解锁');
    }

    // 校验密码
    if (!verify_password($password, $user['password'])) {
        $failed = (int)$user['failed_login_count'] + 1;
        $locked = 0;
        $lockedUntil = null;

        if ($failed >= 5) {
            $locked = 1;
            $lockedUntil = date('Y-m-d H:i:s', time() + 6 * 3600);
        }

        $sql = 'UPDATE users SET failed_login_count = :failed, is_locked = :locked, locked_until = :locked_until, updated_at = NOW() WHERE id = :id';
        db_execute($sql, [
            ':failed'       => $failed,
            ':locked'       => $locked,
            ':locked_until' => $lockedUntil,
            ':id'           => $user['id'],
        ]);

        record_login_log(1, (int)$user['id'], $ip, 0, '密码错误');

        if ($locked) {
            add_operation_log(1, (int)$user['id'], 'user_locked_by_password_error', ['mobile' => $mobile]);
            json_error('密码错误次数过多，账号已锁定6小时');
        }

        json_error('账号或密码错误');
    }

    // 密码正确，重置失败次数及锁定状态（若已过期）
    $sql = 'UPDATE users SET failed_login_count = 0, is_locked = 0, locked_until = NULL,
            last_login_ip = :ip, last_login_time = NOW(), updated_at = NOW()
            WHERE id = :id';
    db_execute($sql, [
        ':ip' => $ip,
        ':id' => $user['id'],
    ]);

    record_login_log(1, (int)$user['id'], $ip, 1, '登录成功');
    add_operation_log(1, (int)$user['id'], 'user_login', ['mobile' => $mobile]);

    // 设置 session
    $_SESSION['user_id'] = (int)$user['id'];

    // 获取待参加的包场邀请
    $pendingBookings = get_pending_booking_invitations((int)$user['id'], $user['mobile'], $user['qq']);

    json_response([
        'user_id' => (int)$user['id'],
        'mobile'  => $user['mobile'],
        'qq'      => $user['qq'],
        'pending_bookings' => $pendingBookings,
    ], 0, '登录成功');
}

/**
 * 用户登出
 */
function api_logout(): void
{
    init_session();
    unset($_SESSION['user_id']);
    json_response(null, 0, '已退出登录');
}

/**
 * 当前登录用户信息
 */
function api_me(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录或账号已失效', 401);
    }

    unset($user['password'], $user['salt']);
    json_response($user);
}

/**
 * 管理员登录
 */
function api_admin_login(): void
{
    $username = input('username');
    $password = input('password');
    $ip = client_ip();

    if (!$username || !$password) {
        json_error('用户名和密码必填');
    }

    $admin = db_fetch_one('SELECT * FROM admins WHERE username = ?', [$username]);
    if (!$admin) {
        record_login_log(2, 0, $ip, 0, '管理员账号或密码错误');
        json_error('账号或密码错误');
    }

    if ((int)$admin['status'] === 0) {
        record_login_log(2, (int)$admin['id'], $ip, 0, '管理员账号被禁用');
        json_error('账号已被禁用');
    }

    if (!verify_password($password, $admin['password'])) {
        record_login_log(2, (int)$admin['id'], $ip, 0, '管理员密码错误');
        json_error('账号或密码错误');
    }

    db_execute(
        'UPDATE admins SET last_login_ip = :ip, last_login_time = NOW(), updated_at = NOW() WHERE id = :id',
        [':ip' => $ip, ':id' => $admin['id']]
    );

    record_login_log(2, (int)$admin['id'], $ip, 1, '管理员登录成功');
    add_operation_log(2, (int)$admin['id'], 'admin_login', ['username' => $username]);

    $_SESSION['admin_id'] = (int)$admin['id'];

    json_response([
        'admin_id' => (int)$admin['id'],
        'username' => $admin['username'],
        'role'     => (int)$admin['role'],
    ], 0, '登录成功');
}

/**
 * 记录登录日志
 */
function record_login_log(int $userType, int $userId, string $ip, int $status, string $reason = ''): void
{
    $sql = 'INSERT INTO login_logs (user_type, user_id, ip, status, reason, created_at)
            VALUES (:user_type, :user_id, :ip, :status, :reason, NOW())';
    db_execute($sql, [
        ':user_type' => $userType,
        ':user_id'   => $userId,
        ':ip'        => $ip,
        ':status'    => $status,
        ':reason'    => $reason,
    ]);
}

/**
 * 找回密码（QQ号验证码）
 */
function api_reset_password(): void
{
    $qq = input('qq');
    $code = input('code');
    $newPassword = input('new_password');

    if (!$qq || !$code || !$newPassword) {
        json_error('请填写完整信息');
    }

    if (!preg_match('/^\d{5,12}$/', $qq)) {
        json_error('QQ号格式不正确');
    }

    // 自动生成QQ邮箱
    $email = $qq . '@qq.com';

    require_once __DIR__ . '/../smtp.php';
    if (!verify_code($email, $code, 'reset')) {
        json_error('验证码错误或已过期');
    }

    if (!preg_match('/^\d{6}$/', $newPassword)) {
        json_error('新密码必须为6位数字');
    }

    $user = db_fetch_one('SELECT id FROM users WHERE qq = ?', [$qq]);
    if (!$user) {
        json_error('账号未注册');
    }

    $hash = hash_password($newPassword);
    db_execute('UPDATE users SET password = ?, is_locked = 0, failed_login_count = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?',
        [$hash, $user['id']]);

    add_operation_log(1, (int)$user['id'], 'user_reset_password', ['qq' => $qq]);
    json_response(null, 0, '密码重置成功，请使用新密码登录');
}

/**
 * 获取用户待参加的包场邀请
 * @param int $userId 用户ID
 * @param string $mobile 手机号
 * @param string $qq QQ号
 * @return array
 */
function get_pending_booking_invitations(int $userId, string $mobile, string $qq): array
{
    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    // 查找用户被邀请的、今天及以后、已通过的包场
    $bookings = db_fetch_all(
        'SELECT b.id, b.date, b.start_time, b.end_time, b.purpose,
                u.mobile as inviter_mobile, u.qq as inviter_qq
         FROM booking_orders b
         INNER JOIN booking_invited_users bi ON b.id = bi.booking_id
         LEFT JOIN users u ON b.user_id = u.id
         WHERE b.status = 1
           AND b.date >= :today
           AND (bi.user_id = :user_id OR bi.mobile = :mobile OR bi.qq = :qq)
         ORDER BY b.date ASC, b.start_time ASC',
        [
            ':today' => $today,
            ':user_id' => $userId,
            ':mobile' => $mobile,
            ':qq' => $qq
        ]
    );

    $result = [];
    foreach ($bookings as $b) {
        // 判断包场状态
        $status = 'upcoming'; // 即将开始
        $startTime = strtotime($b['date'] . ' ' . $b['start_time']);
        $endTime = strtotime($b['date'] . ' ' . $b['end_time']);
        $now = time();

        if ($now >= $startTime && $now <= $endTime) {
            $status = 'in_progress'; // 进行中
        } elseif ($now > $endTime) {
            $status = 'ended'; // 已结束
        }

        $result[] = [
            'booking_id' => (int)$b['id'],
            'date' => $b['date'],
            'start_time' => substr($b['start_time'], 0, 5),
            'end_time' => substr($b['end_time'], 0, 5),
            'purpose' => $b['purpose'],
            'inviter' => $b['inviter_qq'] ?? $b['inviter_mobile'] ?? '未知',
            'status' => $status
        ];
    }

    return $result;
}

