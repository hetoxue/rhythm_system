<?php

/**
 * 管理员后台接口
 */

require_once __DIR__ . '/../functions.php';

function handle_admin_action(string $action): void
{
    switch ($action) {
        case 'me':
            api_admin_me();
            break;
        case 'update_avatar':
            api_admin_update_avatar();
            break;
        case 'user_list':
            api_admin_user_list();
            break;
        case 'user_detail':
            api_admin_user_detail();
            break;
        case 'user_update':
            api_admin_user_update();
            break;
        case 'user_ban':
            api_admin_user_ban();
            break;
        case 'user_unban':
            api_admin_user_unban();
            break;
        case 'user_unlock':
            api_admin_user_unlock();
            break;
        case 'online_users':
            api_admin_online_users();
            break;
        case 'set_free_minutes':
            api_admin_set_free_minutes();
            break;
        case 'save_smtp_config':
            api_admin_save_smtp_config();
            break;
        case 'test_smtp':
            api_admin_test_smtp();
            break;
        case 'email_logs':
            api_admin_email_logs();
            break;
        case 'stats_overview':
            api_admin_stats_overview();
            break;
        case 'booking_list':
            api_admin_booking_list();
            break;
        case 'booking_review':
            api_admin_review_booking();
            break;
        case 'slot_list':
            api_admin_slot_list();
            break;
        case 'slot_save':
            api_admin_slot_save();
            break;
        case 'slot_delete':
            api_admin_slot_delete();
            break;
        case 'force_exit':
            api_admin_force_exit();
            break;
        // 时长卡套餐管理
        case 'plan_list':
            api_admin_plan_list();
            break;
        case 'plan_save':
            api_admin_plan_save();
            break;
        case 'plan_delete':
            api_admin_plan_delete();
            break;
        // 充值卡管理
        case 'card_list':
            api_admin_card_list();
            break;
        case 'card_generate':
            api_admin_card_generate();
            break;
        case 'card_delete':
            api_admin_card_delete();
            break;
        // 公告管理
        case 'announcement_list':
            api_admin_announcement_list();
            break;
        case 'announcement_save':
            api_admin_announcement_save();
            break;
        case 'announcement_delete':
            api_admin_announcement_delete();
            break;
        // 轮播图管理
        case 'banner_list':
            api_admin_banner_list();
            break;
        case 'banner_save':
            api_admin_banner_save();
            break;
        case 'banner_delete':
            api_admin_banner_delete();
            break;
        // 用户时长卡管理
        case 'user_card_list':
            api_admin_user_card_list();
            break;
        case 'user_card_add':
            api_admin_user_card_add();
            break;
        case 'user_card_delete':
            api_admin_user_card_delete();
            break;
        // 操作日志
        case 'operation_logs':
            api_admin_operation_logs();
            break;
        // 系统配置
        case 'set_config':
            api_admin_set_config();
            break;
        case 'user_balance_adjust':
            api_admin_user_balance_adjust();
            break;
        default:
            json_error('未知 action：' . $action);
    }
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        json_error('管理员未登录', 401);
    }
    return $admin;
}

function api_admin_me(): void
{
    $admin = require_admin();
    unset($admin['password'], $admin['salt']);
    // 确保返回QQ和头像信息
    if (empty($admin['avatar']) && !empty($admin['qq'])) {
        $admin['avatar'] = "https://q1.qlogo.cn/g?b=qq&nk={$admin['qq']}&s=100";
    }
    json_response($admin);
}

/**
 * 更新管理员QQ头像
 */
function api_admin_update_avatar(): void
{
    $admin = require_admin();
    $qq = input('qq');
    
    if (!preg_match('/^\d{5,12}$/', $qq)) {
        json_error('QQ号格式错误');
    }
    
    $avatar = "https://q1.qlogo.cn/g?b=qq&nk={$qq}&s=100";
    db_execute('UPDATE admins SET qq=?, avatar=? WHERE id=?', [$qq, $avatar, $admin['id']]);
    
    json_response(['avatar' => $avatar], 0, '头像更新成功');
}

/**
 * 用户列表
 */
function api_admin_user_list(): void
{
    require_admin();

    $mobile = input('mobile', '');
    $qq = input('qq', '');
    $status = input('status', '');

    $where = 'WHERE 1=1';
    $params = [];
    if ($mobile !== '') {
        $where .= ' AND mobile LIKE :mobile';
        $params[':mobile'] = '%' . $mobile . '%';
    }
    if ($qq !== '') {
        $where .= ' AND qq LIKE :qq';
        $params[':qq'] = '%' . $qq . '%';
    }
    if ($status !== '') {
        $where .= ' AND status = :status';
        $params[':status'] = (int)$status;
    }

    $page = max(1, (int)input('page', 1));
    $pageSize = max(1, min(100, (int)input('page_size', 20)));
    $offset = ($page - 1) * $pageSize;

    $totalRow = db_fetch_one("SELECT COUNT(*) AS cnt FROM users {$where}", $params);
    $total = (int)($totalRow['cnt'] ?? 0);

    $sql = "SELECT * FROM users {$where} ORDER BY id DESC LIMIT " . (int)$offset . ', ' . (int)$pageSize;
    $rows = db_fetch_all($sql, array_values($params));

    json_response([
        'total'     => $total,
        'page'      => $page,
        'page_size' => $pageSize,
        'list'      => $rows,
    ]);
}

/**
 * 用户详情（含概要统计）
 */
function api_admin_user_detail(): void
{
    require_admin();
    $userId = (int)input('user_id', 0);
    if ($userId <= 0) {
        json_error('参数错误');
    }

    $user = db_fetch_one('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        json_error('用户不存在');
    }

    $entranceCountRow = db_fetch_one(
        'SELECT COUNT(*) AS cnt FROM entrance_records WHERE user_id = ?',
        [$userId]
    );
    $consumeTotalRow = db_fetch_one(
        'SELECT SUM(amount) AS total FROM consume_records WHERE user_id = ?',
        [$userId]
    );
    $rechargeTotalRow = db_fetch_one(
        'SELECT SUM(amount) AS total FROM recharge_orders WHERE user_id = ? AND status = 1',
        [$userId]
    );

    json_response([
        'user'            => $user,
        'entrance_count'  => (int)($entranceCountRow['cnt'] ?? 0),
        'consume_total'   => (int)($consumeTotalRow['total'] ?? 0),
        'recharge_total'  => (int)($rechargeTotalRow['total'] ?? 0),
    ]);
}

/**
 * 修改用户基础信息（手机号、QQ）
 */
function api_admin_user_update(): void
{
    $admin = require_admin();
    $userId = (int)input('user_id', 0);
    $mobile = input('mobile', '');
    $qq = input('qq', '');
    $password = input('password', '');

    if ($userId <= 0) {
        json_error('参数错误');
    }

    $user = db_fetch_one('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        json_error('用户不存在');
    }

    $updateFields = [];
    $params = [':id' => $userId];

    if ($mobile !== '') {
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) json_error('手机号格式错误');
        $updateFields[] = 'mobile = :mobile';
        $params[':mobile'] = $mobile;
    }
    if ($qq !== '') {
        $updateFields[] = 'qq = :qq';
        $params[':qq'] = $qq;
    }
    if ($password !== '') {
        if (strlen($password) < 6) json_error('密码至少6位');
        $updateFields[] = 'password = :password';
        $params[':password'] = hash_password($password);
    }

    if (empty($updateFields)) {
        json_error('没有要更新的内容');
    }

    $sql = 'UPDATE users SET ' . implode(', ', $updateFields) . ', updated_at = NOW() WHERE id = :id';
    db_execute($sql, $params);

    add_operation_log(2, (int)$admin['id'], 'admin_update_user', [
        'user_id' => $userId,
        'fields'  => array_keys($params),
    ]);

    json_response(null, 0, '更新成功');
}

/**
 * 封禁用户
 */
function api_admin_user_ban(): void
{
    $admin = require_admin();
    $userId = (int)input('user_id', 0);
    if ($userId <= 0) {
        json_error('参数错误');
    }

    db_execute(
        'UPDATE users SET status = 0, updated_at = NOW() WHERE id = :id',
        [':id' => $userId]
    );

    add_operation_log(2, (int)$admin['id'], 'admin_ban_user', ['user_id' => $userId]);
    json_response(null, 0, '用户已封禁');
}

/**
 * 解封用户
 */
function api_admin_user_unban(): void
{
    $admin = require_admin();
    $userId = (int)input('user_id', 0);
    if ($userId <= 0) {
        json_error('参数错误');
    }

    db_execute(
        'UPDATE users SET status = 1, updated_at = NOW() WHERE id = :id',
        [':id' => $userId]
    );

    add_operation_log(2, (int)$admin['id'], 'admin_unban_user', ['user_id' => $userId]);
    json_response(null, 0, '用户已解封');
}

/**
 * 解锁用户账号
 */
function api_admin_user_unlock(): void
{
    $admin = require_admin();
    $userId = (int)input('user_id', 0);
    if ($userId <= 0) {
        json_error('参数错误');
    }

    db_execute(
        'UPDATE users 
         SET is_locked = 0, failed_login_count = 0, locked_until = NULL, updated_at = NOW()
         WHERE id = :id',
        [':id' => $userId]
    );

    add_operation_log(2, (int)$admin['id'], 'admin_unlock_user', ['user_id' => $userId]);
    json_response(null, 0, '账号已解锁');
}

/**
 * 管理员调整用户余额
 */
function api_admin_user_balance_adjust(): void
{
    $admin = require_admin();
    $userId = (int)input('user_id', 0);
    $amountYuan = (float)input('amount_yuan', 0);
    $remark = input('remark', '管理员手动调整');

    if ($userId <= 0 || $amountYuan == 0) {
        json_error('参数错误');
    }

    $amount = (int)round($amountYuan * 100);
    $user = db_fetch_one('SELECT balance, mobile FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        json_error('用户不存在');
    }

    $newBalance = (int)$user['balance'] + $amount;
    
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_execute('UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?', [$newBalance, $userId]);
        
        db_execute(
            'INSERT INTO consume_records (user_id, type, related_id, amount, balance_after, remark, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$userId, 3, 0, abs($amount), $newBalance, $remark]
        );

        $pdo->commit();
        add_operation_log(2, (int)$admin['id'], 'admin_balance_adjust', [
            'user_id' => $userId,
            'amount' => $amount,
            'new_balance' => $newBalance
        ]);
        json_response(['new_balance' => $newBalance], 0, '调整成功');
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('调整失败：' . $e->getMessage());
    }
}

/**
 * 当前在场用户列表
 */
function api_admin_online_users(): void
{
    require_admin();

    $rows = db_fetch_all(
        'SELECT e.*, u.mobile, u.qq 
         FROM entrance_records e
         LEFT JOIN users u ON e.user_id = u.id
         WHERE e.status = 0
         ORDER BY e.enter_time ASC'
    );

    json_response($rows);
}

/**
 * 保存SMTP配置
 */
function api_admin_save_smtp_config(): void
{
    $admin = require_admin();
    
    $smtpHost = input('smtp_host', '');
    $smtpPort = input('smtp_port', 587);
    $smtpUser = input('smtp_user', '');
    $smtpPass = input('smtp_pass', '');
    $smtpFrom = input('smtp_from', '');
    $smtpFromName = input('smtp_from_name', '');
    
    $configs = [
        'smtp_host' => $smtpHost,
        'smtp_port' => $smtpPort,
        'smtp_user' => $smtpUser,
        'smtp_pass' => $smtpPass,
        'smtp_from' => $smtpFrom,
        'smtp_from_name' => $smtpFromName
    ];
    
    foreach ($configs as $key => $value) {
        $exists = db_fetch_one('SELECT * FROM system_configs WHERE config_key = ?', [$key]);
        if ($exists) {
            db_execute(
                'UPDATE system_configs SET config_value = :val, updated_at = NOW() WHERE config_key = :key',
                [':val' => (string)$value, ':key' => $key]
            );
        } else {
            db_execute(
                'INSERT INTO system_configs (config_key, config_value, updated_at) VALUES (:key, :val, NOW())',
                [':key' => $key, ':val' => (string)$value]
            );
        }
    }
    
    add_operation_log(2, (int)$admin['id'], 'admin_save_smtp_config', []);
    json_response(null, 0, 'SMTP配置已保存');
}

/**
 * 测试SMTP发送
 */
function api_admin_test_smtp(): void
{
    $admin = require_admin();
    $email = input('email');
    
    if (empty($email)) {
        json_error('请输入测试邮箱');
    }
    
    require_once __DIR__ . '/../smtp.php';
    $testCode = '123456';
    $sent = send_email_verification($email, $testCode, 'test');
    
    if ($sent) {
        json_response(null, 0, '测试邮件已发送');
    } else {
        json_error('测试邮件发送失败，请检查SMTP配置');
    }
}

/**
 * 获取邮件发送日志
 */
function api_admin_email_logs(): void
{
    $admin = require_admin();
    
    $page = (int)input('page', 1);
    $pageSize = (int)input('page_size', 50);
    $offset = ($page - 1) * $pageSize;
    
    $sql = "SELECT * FROM email_logs ORDER BY id DESC LIMIT $offset, $pageSize";
    $list = db_fetch_all($sql);
    
    $total = db_fetch_one('SELECT COUNT(*) as c FROM email_logs')['c'];
    
    json_response(['total' => $total, 'list' => $list]);
}

/**
 * 设置系统配置（免费时长、系统名称、计费规则等）
 */
function api_admin_set_free_minutes(): void
{
    $admin = require_admin();
    $minutes = input('minutes', -1);
    $systemName = input('system_name', null);
    $billingMinutes = input('billing_minutes', null);
    $billingAmount = input('billing_amount', null);
    $bookingGraceMinutes = input('booking_grace_minutes', null);

    // 处理免费时长
    if ($minutes !== -1) {
        $minutes = (int)$minutes;
        if ($minutes < 0) {
            json_error('分钟数必须为非负整数');
        }

        $exists = db_fetch_one('SELECT * FROM system_configs WHERE config_key = ?', ['free_minutes']);
        if ($exists) {
            db_execute(
                'UPDATE system_configs SET config_value = :val, updated_at = NOW() WHERE config_key = :key',
                [':val' => (string)$minutes, ':key' => 'free_minutes']
            );
        } else {
            db_execute(
                'INSERT INTO system_configs (config_key, config_value, updated_at) VALUES (:key, :val, NOW())',
                [':key' => 'free_minutes', ':val' => (string)$minutes]
            );
        }
    }

    // 处理系统名称
    if ($systemName !== null) {
        $systemName = trim($systemName);
        $exists = db_fetch_one('SELECT * FROM system_configs WHERE config_key = ?', ['system_name']);
        if ($exists) {
            db_execute(
                'UPDATE system_configs SET config_value = :val, updated_at = NOW() WHERE config_key = :key',
                [':val' => $systemName, ':key' => 'system_name']
            );
        } else {
            db_execute(
                'INSERT INTO system_configs (config_key, config_value, updated_at) VALUES (:key, :val, NOW())',
                [':key' => 'system_name', ':val' => $systemName]
            );
        }
    }

    // 处理计费规则
    if ($billingMinutes !== null && $billingAmount !== null) {
        $billingMinutes = (int)$billingMinutes;
        $billingAmount = (int)$billingAmount;

        if ($billingMinutes < 1) {
            json_error('计费分钟数必须大于0');
        }

        if ($billingAmount < 0) {
            json_error('计费金额不能为负数');
        }

        $exists = db_fetch_one('SELECT * FROM system_configs WHERE config_key = ?', ['billing_minutes']);
        if ($exists) {
            db_execute(
                'UPDATE system_configs SET config_value = :val, updated_at = NOW() WHERE config_key = :key',
                [':val' => (string)$billingMinutes, ':key' => 'billing_minutes']
            );
        } else {
            db_execute(
                'INSERT INTO system_configs (config_key, config_value, updated_at) VALUES (:key, :val, NOW())',
                [':key' => 'billing_minutes', ':val' => (string)$billingMinutes]
            );
        }

        $exists = db_fetch_one('SELECT * FROM system_configs WHERE config_key = ?', ['billing_amount']);
        if ($exists) {
            db_execute(
                'UPDATE system_configs SET config_value = :val, updated_at = NOW() WHERE config_key = :key',
                [':val' => (string)$billingAmount, ':key' => 'billing_amount']
            );
        } else {
            db_execute(
                'INSERT INTO system_configs (config_key, config_value, updated_at) VALUES (:key, :val, NOW())',
                [':key' => 'billing_amount', ':val' => (string)$billingAmount]
            );
        }
    }

    // 处理包场缓冲时间
    if ($bookingGraceMinutes !== null) {
        $bookingGraceMinutes = (int)$bookingGraceMinutes;
        if ($bookingGraceMinutes < 0) {
            json_error('包场缓冲时间必须为非负整数');
        }

        $exists = db_fetch_one('SELECT * FROM system_configs WHERE config_key = ?', ['booking_grace_minutes']);
        if ($exists) {
            db_execute(
                'UPDATE system_configs SET config_value = :val, updated_at = NOW() WHERE config_key = :key',
                [':val' => (string)$bookingGraceMinutes, ':key' => 'booking_grace_minutes']
            );
        } else {
            db_execute(
                'INSERT INTO system_configs (config_key, config_value, updated_at) VALUES (:key, :val, NOW())',
                [':key' => 'booking_grace_minutes', ':val' => (string)$bookingGraceMinutes]
            );
        }
    }

    // 处理favicon配置
    $loginFavicon = input('login_favicon', null);
    $userFavicon = input('user_favicon', null);
    $adminFavicon = input('admin_favicon', null);

    $faviconConfigs = [
        'login_favicon' => $loginFavicon,
        'user_favicon' => $userFavicon,
        'admin_favicon' => $adminFavicon
    ];

    foreach ($faviconConfigs as $key => $value) {
        if ($value !== null) {
            $value = trim($value);
            $exists = db_fetch_one('SELECT * FROM system_configs WHERE config_key = ?', [$key]);
            if ($exists) {
                db_execute(
                    'UPDATE system_configs SET config_value = :val, updated_at = NOW() WHERE config_key = :key',
                    [':val' => $value, ':key' => $key]
                );
            } else {
                db_execute(
                    'INSERT INTO system_configs (config_key, config_value, updated_at) VALUES (:key, :val, NOW())',
                    [':key' => $key, ':val' => $value]
                );
            }
        }
    }

    add_operation_log(2, (int)$admin['id'], 'admin_set_config', [
        'minutes' => $minutes,
        'system_name' => $systemName,
        'billing_minutes' => $billingMinutes,
        'billing_amount' => $billingAmount,
        'booking_grace_minutes' => $bookingGraceMinutes,
        'login_favicon' => $loginFavicon,
        'user_favicon' => $userFavicon,
        'admin_favicon' => $adminFavicon
    ]);

    json_response(null, 0, '设置成功');
}

/**
 * 简单统计：今日/本月营收、入场人数等
 */
function api_admin_stats_overview(): void
{
    require_admin();

    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');

    // 今日入场人数
    $todayEntranceRow = db_fetch_one(
        "SELECT COUNT(*) AS cnt FROM entrance_records WHERE DATE(enter_time) = ?",
        [$today]
    );
    $todayEntrance = (int)($todayEntranceRow['cnt'] ?? 0);

    // 今日充值金额
    $todayRechargeRow = db_fetch_one(
        "SELECT SUM(amount) AS total FROM recharge_orders WHERE status = 1 AND DATE(paid_at) = ?",
        [$today]
    );
    $todayRecharge = (int)($todayRechargeRow['total'] ?? 0);

    // 今日消费金额
    $todayConsumeRow = db_fetch_one(
        "SELECT SUM(amount) AS total FROM consume_records WHERE DATE(created_at) = ?",
        [$today]
    );
    $todayConsume = (int)($todayConsumeRow['total'] ?? 0);

    // 本月充值
    $monthRechargeRow = db_fetch_one(
        "SELECT SUM(amount) AS total FROM recharge_orders WHERE status = 1 AND paid_at >= ?",
        [$monthStart]
    );
    $monthRecharge = (int)($monthRechargeRow['total'] ?? 0);

    // 本月消费
    $monthConsumeRow = db_fetch_one(
        "SELECT SUM(amount) AS total FROM consume_records WHERE created_at >= ?",
        [$monthStart]
    );
    $monthConsume = (int)($monthConsumeRow['total'] ?? 0);

    json_response([
        'today' => [
            'entrance_count' => $todayEntrance,
            'recharge_total' => $todayRecharge,
            'consume_total'  => $todayConsume,
        ],
        'month' => [
            'recharge_total' => $monthRecharge,
            'consume_total'  => $monthConsume,
        ],
    ]);
}

/**
 * 包场申请列表（管理员）
 */
function api_admin_booking_list(): void
{
    require_admin();

    $status = input('status', '');
    $date = input('date', '');

    $where = 'WHERE 1=1';
    $params = [];

    if ($status !== '') {
        $where .= ' AND b.status = ?';
        $params[] = (int)$status;
    }
    if ($date !== '') {
        $where .= ' AND b.date = ?';
        $params[] = $date;
    }

    $sql = "SELECT b.*, u.mobile, u.qq
            FROM booking_orders b
            LEFT JOIN users u ON b.user_id = u.id
            {$where}
            ORDER BY b.id DESC";

    $rows = db_fetch_all($sql, $params);

    // 为每个包场添加受邀人列表
    foreach ($rows as &$booking) {
        $invitedUsers = db_fetch_all(
            'SELECT bi.*, u.mobile as user_mobile, u.qq as user_qq
             FROM booking_invited_users bi
             LEFT JOIN users u ON bi.user_id = u.id
             WHERE bi.booking_id = ?',
            [$booking['id']]
        );

        $invitedList = [];
        foreach ($invitedUsers as $inv) {
            $name = $inv['user_qq'] ?? $inv['user_mobile'] ?? $inv['qq'] ?? $inv['mobile'] ?? '未知';
            if ($name !== '未知') {
                $invitedList[] = $name;
            }
        }
        $booking['invited'] = implode("\n", $invitedList);
    }

    json_response($rows);
}

/**
 * 审核包场：通过 / 拒绝 / 取消
 * 价格在申请时已扣费并锁定，此处仅处理状态流转及退款
 */
function api_admin_review_booking(): void
{
    $admin = require_admin();
    $bookingId = (int)input('booking_id', 0);
    $actionType = input('review_action'); // approve / reject / cancel
    $remark = input('remark', '');

    if ($bookingId <= 0 || !in_array($actionType, ['approve', 'reject', 'cancel'], true)) {
        json_error('参数错误');
    }

    $booking = db_fetch_one('SELECT * FROM booking_orders WHERE id = ?', [$bookingId]);
    if (!$booking) {
        json_error('记录不存在');
    }

    $userId = $booking['user_id'];
    $oldStatus = $booking['status'];
    $bookingPrice = (int)$booking['price'];

    $newStatus = $oldStatus;
    if ($actionType === 'approve') {
        $newStatus = 1;
    } elseif ($actionType === 'reject') {
        $newStatus = 2;
    } elseif ($actionType === 'cancel') {
        $newStatus = 3;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $user = db_fetch_one('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId]);

        // 资金回写逻辑：如果之前是 待审核(0) 或 已通过(1)，且现在变更为 拒绝(2) 或 取消(3)
        if (($oldStatus == 0 || $oldStatus == 1) && ($newStatus == 2 || $newStatus == 3)) {
            $newBalance = $user['balance'] + $bookingPrice;
            db_execute('UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?', [$newBalance, $userId]);

            // 记录退款流水
            db_execute(
                'INSERT INTO consume_records (user_id, type, related_id, amount, balance_after, remark, created_at)
                 VALUES (?, 4, ?, ?, ?, ?, NOW())',
                [$userId, $bookingId, -$bookingPrice, $newBalance, '包场申请未通过/取消，已原路退款']
            );
        }

        db_execute(
            'UPDATE booking_orders SET status = ?, remark = ?, updated_at = NOW() WHERE id = ?',
            [$newStatus, $remark, $bookingId]
        );

        add_operation_log(2, (int)$admin['id'], 'admin_booking_review', [
            'booking_id' => $bookingId,
            'action'     => $actionType,
            'refund'     => ($newStatus == 2 || $newStatus == 3) ? $bookingPrice : 0
        ]);

        $pdo->commit();

        // 发送邮件通知
        require_once __DIR__ . '/../smtp.php';
        $invitedUsers = db_fetch_all('SELECT * FROM booking_invited_users WHERE booking_id = ?', [$bookingId]);

        if ($actionType === 'approve') {
            // 发送给发起人（使用QQ邮箱）
            if ($user && !empty($user['qq'])) {
                $email = $user['qq'] . '@qq.com';
                send_booking_approved_email($email, $booking['date'], $booking['start_time'], $booking['end_time']);
            }
            // 发送给被邀请人（使用QQ邮箱）
            foreach ($invitedUsers as $invited) {
                $inviteUser = null;
                if ($invited['user_id']) {
                    $inviteUser = db_fetch_one('SELECT * FROM users WHERE id = ?', [$invited['user_id']]);
                }
                if ($inviteUser && !empty($inviteUser['qq'])) {
                    $email = $inviteUser['qq'] . '@qq.com';
                    $inviterName = $user['qq'] ?? $user['mobile'] ?? '用户';
                    send_booking_invitation_email($email, $inviterName, $booking['date'], $booking['start_time'], $booking['end_time']);
                }
            }
        } elseif ($actionType === 'cancel' && $oldStatus == 1) {
            // 已通过后被取消，发送通知
            // 发送给发起人（使用QQ邮箱）
            if ($user && !empty($user['qq'])) {
                $email = $user['qq'] . '@qq.com';
                send_booking_cancelled_email($email, $booking['date'], $booking['start_time'], $booking['end_time'], $remark);
            }
            // 发送给被邀请人（使用QQ邮箱）
            foreach ($invitedUsers as $invited) {
                $inviteUser = null;
                if ($invited['user_id']) {
                    $inviteUser = db_fetch_one('SELECT * FROM users WHERE id = ?', [$invited['user_id']]);
                }
                if ($inviteUser && !empty($inviteUser['qq'])) {
                    $email = $inviteUser['qq'] . '@qq.com';
                    send_booking_cancelled_email($email, $booking['date'], $booking['start_time'], $booking['end_time'], $remark);
                }
            }
        }

        json_response(null, 0, '操作成功');
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('处理失败：' . $e->getMessage());
    }
}

/**
 * 强制退场
 */
function api_admin_force_exit(): void
{
    $admin = require_admin();
    $userId = (int)input('user_id', 0);
    $isCharge = (int)input('is_charge', 1);

    if ($userId <= 0) json_error('参数错误');

    $record = db_fetch_one('SELECT * FROM entrance_records WHERE user_id = ? AND status = 0 LIMIT 1', [$userId]);
    if (!$record) json_error('该用户当前不在场');

    if ($isCharge) {
        $_SESSION['user_id'] = $userId;
        require_once __DIR__ . '/entrance.php';
        api_exit();
    } else {
        db_execute('UPDATE entrance_records SET status = 1, exit_time = NOW(), updated_at = NOW() WHERE id = ?', [$record['id']]);
        json_response(null, 0, '强制退场成功（未扣费）');
    }
}

/**
 * 套餐列表
 */
function api_admin_plan_list(): void
{
    require_admin();
    $rows = db_fetch_all('SELECT * FROM time_card_plans ORDER BY id DESC');
    json_response($rows);
}

/**
 * 保存套餐（新增或编辑）
 */
function api_admin_plan_save(): void
{
    $admin = require_admin();
    $id = (int)input('id', 0);
    $name = input('name', '');
    $duration_min = (int)input('duration_min', 0);
    $price = (int)input('price', -1); // 明确区分 0 和 未传
    $valid_days = (int)input('valid_days', 0);
    $max_per_user = (int)input('max_per_user', 0);
    $status = (int)input('status', 1);

    if ($name === '' || $duration_min <= 0 || $price < 0 || $valid_days <= 0) {
        // 增加具体缺失项提示，方便调试
        $missing = [];
        if ($name === '') $missing[] = '名称';
        if ($duration_min <= 0) $missing[] = '时长';
        if ($price < 0) $missing[] = '价格';
        if ($valid_days <= 0) $missing[] = '有效期';
        json_error('参数错误: ' . implode(', ', $missing));
    }

    if ($id > 0) {
        db_execute(
            'UPDATE time_card_plans SET name = ?, duration_min = ?, price = ?, valid_days = ?, max_per_user = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [$name, $duration_min, $price, $valid_days, $max_per_user, $status, $id]
        );
    } else {
        db_execute(
            'INSERT INTO time_card_plans (name, duration_min, price, valid_days, max_per_user, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$name, $duration_min, $price, $valid_days, $max_per_user, $status]
        );
    }

    add_operation_log(2, (int)$admin['id'], 'admin_save_plan', ['name' => $name]);
    json_response(null, 0, '保存成功');
}

/**
 * 删除套餐
 */
function api_admin_plan_delete(): void
{
    $admin = require_admin();
    $id = (int)input('id', 0);
    if ($id <= 0) json_error('参数错误');

    db_execute('DELETE FROM time_card_plans WHERE id = ?', [$id]);
    add_operation_log(2, (int)$admin['id'], 'admin_delete_plan', ['id' => $id]);
    json_response(null, 0, '已删除');
}

/**
 * 卡券列表
 */
function api_admin_card_list(): void
{
    require_admin();
    $page = max(1, (int)input('page', 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;
    
    $totalRow = db_fetch_one('SELECT COUNT(*) as cnt FROM recharge_cards');
    $sql = "SELECT c.*, u.mobile as used_mobile 
            FROM recharge_cards c 
            LEFT JOIN users u ON c.used_by = u.id 
            ORDER BY c.id DESC LIMIT {$offset}, {$pageSize}";
    $rows = db_fetch_all($sql);
    
    json_response([
        'total' => (int)$totalRow['cnt'],
        'list' => $rows
    ]);
}

/**
 * 生成卡券
 */
function api_admin_card_generate(): void
{
    $admin = require_admin();
    $count = (int)input('count', 1);
    $amount = (int)input('amount', 0); // 分
    $valid_days = (int)input('valid_days', 30);

    if ($count <= 0 || $count > 100 || $amount <= 0) {
        json_error('参数错误（单次最多生成100张）');
    }

    $expire = date('Y-m-d H:i:s', time() + $valid_days * 86400);
    for ($i = 0; $i < $count; $i++) {
        $card_no = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 16));
        db_execute(
            'INSERT INTO recharge_cards (card_no, amount, status, expire_time, created_at, updated_at) VALUES (?, ?, 0, ?, NOW(), NOW())',
            [$card_no, $amount, $expire]
        );
    }

    add_operation_log(2, (int)$admin['id'], 'admin_generate_cards', ['amount' => $amount, 'count' => $count]);
    json_response(null, 0, "成功生成 {$count} 张卡券");
}

/**
 * 删除卡券
 */
function api_admin_card_delete(): void
{
    $admin = require_admin();
    $id = (int)input('id', 0);
    db_execute('DELETE FROM recharge_cards WHERE id = ? AND status = 0', [$id]);
    json_response(null, 0, '已删除');
}

/**
 * 公告列表
 */
function api_admin_announcement_list(): void
{
    require_admin();
    $rows = db_fetch_all('SELECT * FROM announcements ORDER BY sort_order ASC, id DESC');
    // 不转义HTML内容，允许管理员发布富文本公告（包括图片）
    json_response($rows);
}

/**
 * 保存公告
 */
function api_admin_announcement_save(): void
{
    $admin = require_admin();
    $id = (int)input('id', 0);
    $title = input('title', '');
    $content = input('content', '');
    $status = (int)input('status', 1);
    $sort = (int)input('sort_order', 0);

    if ($title === '') json_error('标题不能为空');

    if ($id > 0) {
        db_execute(
            'UPDATE announcements SET title = ?, content = ?, status = ?, sort_order = ?, updated_at = NOW() WHERE id = ?',
            [$title, $content, $status, $sort, $id]
        );
    } else {
        db_execute(
            'INSERT INTO announcements (title, content, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$title, $content, $status, $sort]
        );
    }
    json_response(null, 0, '保存成功');
}

/**
 * 删除公告
 */
function api_admin_announcement_delete(): void
{
    require_admin();
    $id = (int)input('id', 0);
    db_execute('DELETE FROM announcements WHERE id = ?', [$id]);
    json_response(null, 0, '已删除');
}

/**
 * 轮播图列表
 */
function api_admin_banner_list(): void
{
    require_admin();
    $rows = db_fetch_all('SELECT * FROM banners ORDER BY sort_order ASC, id DESC');
    json_response($rows);
}

/**
 * 保存轮播图
 */
function api_admin_banner_save(): void
{
    require_admin();
    $id = (int)input('id', 0);
    $title = input('title', '');
    $image_url = input('image_url', '');
    $link_url = input('link_url', '');
    $status = (int)input('status', 1);
    $sort = (int)input('sort_order', 0);

    if ($image_url === '') json_error('图片地址不能为空');

    if ($id > 0) {
        db_execute(
            'UPDATE banners SET title = ?, image_url = ?, link_url = ?, status = ?, sort_order = ?, updated_at = NOW() WHERE id = ?',
            [$title, $image_url, $link_url, $status, $sort, $id]
        );
    } else {
        db_execute(
            'INSERT INTO banners (title, image_url, link_url, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$title, $image_url, $link_url, $status, $sort]
        );
    }
    json_response(null, 0, '保存成功');
}

/**
 * 删除轮播图
 */
function api_admin_banner_delete(): void
{
    require_admin();
    $id = (int)input('id', 0);
    db_execute('DELETE FROM banners WHERE id = ?', [$id]);
    json_response(null, 0, '已删除');
}

/**
 * 操作日志
 */
function api_admin_operation_logs(): void
{
    require_admin();
    $page = max(1, (int)input('page', 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;
    $search = input('search', '');

    // 构建查询条件
    $where = '';
    $params = [];

    if ($search) {
        $where = " WHERE action LIKE :search1 OR detail LIKE :search2";
        $searchPattern = '%' . $search . '%';
        $params[':search1'] = $searchPattern;
        $params[':search2'] = $searchPattern;
    }

    // 获取总数
    $totalSql = "SELECT COUNT(*) as cnt FROM operation_logs{$where}";
    $totalRow = db_fetch_one($totalSql, $params);

    // 获取数据
    $sql = "SELECT * FROM operation_logs{$where} ORDER BY id DESC LIMIT {$offset}, {$pageSize}";
    $rows = db_fetch_all($sql, $params);

    json_response([
        'total' => (int)$totalRow['cnt'],
        'list' => $rows
    ]);
}

/**
 * 设置系统通用配置
 */
function api_admin_set_config(): void
{
    require_admin();
    $key = input('key', '');
    $val = input('value', '');
    if ($key === '') json_error('Key不能为空');

    $exists = db_fetch_one('SELECT id FROM system_configs WHERE config_key = ?', [$key]);
    if ($exists) {
        db_execute('UPDATE system_configs SET config_value = ?, updated_at = NOW() WHERE config_key = ?', [$val, $key]);
    } else {
        db_execute('INSERT INTO system_configs (config_key, config_value, updated_at) VALUES (?, ?, NOW())', [$key, $val]);
    }
    json_response(null, 0, '设置成功');
}

/**
 * 获取场次列表
 */
function api_admin_slot_list(): void
{
    require_admin();
    $rows = db_fetch_all('SELECT * FROM booking_slots ORDER BY start_time ASC');
    json_response($rows);
}

/**
 * 保存/编辑场次
 */
function api_admin_slot_save(): void
{
    $admin = require_admin();
    $id = (int)input('id', 0);
    $name = input('name', '');
    $start = input('start_time', '');
    $end = input('end_time', '');
    $price_yuan = (float)input('price_yuan', 0);
    $price = (int)round($price_yuan * 100);
    $max = (int)input('max_people', 0);
    $status = (int)input('status', 1);

    if (!$name || !$start || !$end) {
        json_error('请填写完整场次信息');
    }

    if ($id > 0) {
        db_execute(
            'UPDATE booking_slots SET name = ?, start_time = ?, end_time = ?, price = ?, max_people = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [$name, $start, $end, $price, $max, $status, $id]
        );
    } else {
        db_execute(
            'INSERT INTO booking_slots (name, start_time, end_time, price, max_people, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$name, $start, $end, $price, $max, $status]
        );
    }

    add_operation_log(2, (int)$admin['id'], 'admin_save_slot', ['name' => $name]);
    json_response(null, 0, '保存成功');
}

/**
 * 删除场次
 */
function api_admin_slot_delete(): void
{
    $admin = require_admin();
    $id = (int)input('id', 0);
    if ($id <= 0) json_error('参数错误');

    db_execute('DELETE FROM booking_slots WHERE id = ?', [$id]);
    add_operation_log(2, (int)$admin['id'], 'admin_delete_slot', ['id' => $id]);
    json_response(null, 0, '删除成功');
}

/**
 * 获取选定用户的时长卡列表
 */
function api_admin_user_card_list(): void
{
    require_admin();
    $userId = (int)input('user_id', 0);
    if ($userId <= 0) json_error('参数错误');

    $rows = db_fetch_all(
        'SELECT utc.*, p.name AS plan_name 
         FROM user_time_cards utc
         LEFT JOIN time_card_plans p ON utc.plan_id = p.id
         WHERE utc.user_id = ?
         ORDER BY utc.status DESC, utc.id DESC',
        [$userId]
    );
    json_response($rows);
}

/**
 * 管理员为用户添加定制时长卡
 */
function api_admin_user_card_add(): void
{
    $admin = require_admin();
    $userId = (int)input('user_id', 0);
    $minutes = (int)input('minutes', 0);
    $validDays = (int)input('valid_days', 30);
    $remark = input('remark', '管理员后台添加');

    if ($userId <= 0 || $minutes <= 0) {
        json_error('参数错误，分钟数必须大于0');
    }

    $now = date('Y-m-d H:i:s');
    $expire = date('Y-m-d H:i:s', time() + $validDays * 86400);

    db_execute(
        'INSERT INTO user_time_cards 
         (user_id, plan_id, total_min, used_min, remaining_min, price, status, start_time, expire_time, created_at, updated_at)
         VALUES (?, ?, ?, 0, ?, 0, 1, ?, ?, ?, ?)',
        [$userId, 0, $minutes, $minutes, $now, $expire, $now, $now]
    );

    add_operation_log(2, (int)$admin['id'], 'admin_add_user_card', [
        'target_user_id' => $userId,
        'minutes'        => $minutes,
        'expire'         => $expire,
        'remark'         => $remark
    ]);

    json_response(null, 0, '时长卡添加成功');
}

/**
 * 管理员删除用户的时长卡
 */
function api_admin_user_card_delete(): void
{
    $admin = require_admin();
    $cardId = (int)input('card_id', 0);
    
    if ($cardId <= 0) json_error('参数错误');

    $card = db_fetch_one('SELECT * FROM user_time_cards WHERE id = ?', [$cardId]);
    if (!$card) json_error('时长卡不存在');

    db_execute('DELETE FROM user_time_cards WHERE id = ?', [$cardId]);

    add_operation_log(2, (int)$admin['id'], 'admin_delete_user_card', [
        'card_id'        => $cardId,
        'target_user_id' => $card['user_id']
    ]);

    json_response(null, 0, '已成功删除该时长卡');
}
