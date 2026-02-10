<?php
/**
 * 公共函数
 */

require_once __DIR__ . '/db.php';

/**
 * 输出 JSON 响应并结束脚本
 */
function json_response($data, int $code = 0, string $message = 'ok'): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code'    => $code,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 输出错误响应
 */
function json_error(string $message, int $code = 1, $data = null): void
{
    json_response($data, $code, $message);
}

/**
 * 安全获取 POST/GET 参数
 */
function input(string $key, $default = null)
{
    if (isset($_POST[$key])) {
        return trim((string)$_POST[$key]);
    }
    if (isset($_GET[$key])) {
        return trim((string)$_GET[$key]);
    }
    return $default;
}

/**
 * 获取客户端 IP
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    return $ip;
}

/**
 * 初始化会话
 */
function init_session(): void
{
    $config = load_config();
    $sess = $config['session'];

    if (session_status() === PHP_SESSION_NONE) {
        session_name($sess['name']);
        session_set_cookie_params([
            'lifetime' => $sess['lifetime'],
            'path'     => '/',
            'secure'   => $sess['cookie_secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * 获取当前登录用户（普通用户）
 */
function current_user(): ?array
{
    init_session();
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $userId = (int)$_SESSION['user_id'];
    $user = db_fetch_one('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user || (int)$user['status'] === 0) {
        return null;
    }
    return $user;
}

/**
 * 获取当前登录管理员
 */
function current_admin(): ?array
{
    init_session();
    if (!isset($_SESSION['admin_id'])) {
        return null;
    }
    $adminId = (int)$_SESSION['admin_id'];
    $admin = db_fetch_one('SELECT * FROM admins WHERE id = ?', [$adminId]);
    if (!$admin || (int)$admin['status'] === 0) {
        return null;
    }
    return $admin;
}

/**
 * 记录操作日志
 */
function add_operation_log(int $actorType, int $actorId, string $action, array $detail = []): void
{
    $sql = 'INSERT INTO operation_logs (actor_type, actor_id, action, ip, detail, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())';
    db_execute($sql, [
        $actorType,
        $actorId,
        $action,
        client_ip(),
        json_encode($detail, JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * 获取配置值（优先数据库）
 * @param string $key 配置键
 * @param mixed $default 默认值
 * @param bool $forceRefresh 是否强制刷新（跳过缓存）
 */
function get_config_value(string $key, $default = null, bool $forceRefresh = false)
{
    static $cache = [];
    
    // 如果强制刷新，清除缓存
    if ($forceRefresh) {
        unset($cache[$key]);
    }
    
    // 从缓存读取
    if (!$forceRefresh && isset($cache[$key])) {
        return $cache[$key];
    }
    
    // 从数据库读取
    $row = db_fetch_one('SELECT config_value FROM system_configs WHERE config_key = ?', [$key]);
    if ($row) {
        $value = $row['config_value'];
        $cache[$key] = $value;
        return $value;
    }

    // 数据库无值时，用本地默认配置
    $cfg = load_config();
    if ($key === 'free_minutes') {
        $value = $cfg['system']['default_free_minutes'] ?? $default;
        $cache[$key] = $value;
        return $value;
    }

    $cache[$key] = $default;
    return $default;
}

/**
 * 密码哈希
 */
function hash_password(string $password): string
{
    $config = load_config();
    $algo = $config['security']['password_algo'] ?? PASSWORD_DEFAULT;
    return password_hash($password, $algo);
}

/**
 * 验证明文密码与哈希
 */
function verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

