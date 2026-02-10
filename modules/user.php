<?php

/**
 * 用户相关接口：主页信息、账户详情、记录列表
 */

require_once __DIR__ . '/../functions.php';

function handle_user_action(string $action): void
{
    switch ($action) {
        case 'dashboard':
            api_user_dashboard();
            break;
        case 'account':
            api_user_account();
            break;
        case 'entrance_records':
            api_user_entrance_records();
            break;
        case 'consume_records':
            api_user_consume_records();
            break;
        case 'update_profile':
            api_user_update_profile();
            break;
        case 'change_password':
            api_user_change_password();
            break;
        default:
            json_error('未知 action：' . $action);
    }
}

/**
 * 用户首页 / 控制台数据
 */
function api_user_dashboard(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $entrance = db_fetch_one(
        'SELECT * FROM entrance_records WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$user['id']]
    );

    $latestRecharge = db_fetch_one(
        'SELECT * FROM recharge_orders WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$user['id']]
    );

    json_response([
        'user'           => [
            'id'      => (int)$user['id'],
            'mobile'  => $user['mobile'],
            'qq'      => $user['qq'],
            'balance' => (int)$user['balance'],
        ],
        'latest_entrance' => $entrance,
        'latest_recharge' => $latestRecharge,
    ]);
}

/**
 * 账户详情（余额 + 统计）
 */
function api_user_account(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $totalRechargeRow = db_fetch_one(
        'SELECT SUM(amount) AS total FROM recharge_orders WHERE user_id = ? AND status = 1',
        [$user['id']]
    );
    $totalRecharge = (int)($totalRechargeRow['total'] ?? 0);

    $totalConsumeRow = db_fetch_one(
        'SELECT SUM(amount) AS total FROM consume_records WHERE user_id = ?',
        [$user['id']]
    );
    $totalConsume = (int)($totalConsumeRow['total'] ?? 0);

    json_response([
        'balance'        => (int)$user['balance'],
        'total_recharge' => $totalRecharge,
        'total_consume'  => $totalConsume,
    ]);
}

/**
 * 我的进出记录
 */
function api_user_entrance_records(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $page = max(1, (int)input('page', 1));
    $pageSize = max(1, min(50, (int)input('page_size', 20)));
    $offset = ($page - 1) * $pageSize;

    $totalRow = db_fetch_one(
        'SELECT COUNT(*) AS cnt FROM entrance_records WHERE user_id = ?',
        [$user['id']]
    );
    $total = (int)($totalRow['cnt'] ?? 0);

    $sql = 'SELECT * FROM entrance_records WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int)$offset . ', ' . (int)$pageSize;
    $rows = db_fetch_all($sql, [$user['id']]);

    json_response([
        'total'      => $total,
        'page'       => $page,
        'page_size'  => $pageSize,
        'list'       => $rows,
    ]);
}

/**
 * 我的消费记录
 */
function api_user_consume_records(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $page = max(1, (int)input('page', 1));
    $pageSize = max(1, min(50, (int)input('page_size', 20)));
    $offset = ($page - 1) * $pageSize;

    $totalRow = db_fetch_one(
        'SELECT COUNT(*) AS cnt FROM consume_records WHERE user_id = ?',
        [$user['id']]
    );
    $total = (int)($totalRow['cnt'] ?? 0);

    $sql = 'SELECT * FROM consume_records WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int)$offset . ', ' . (int)$pageSize;
    $rows = db_fetch_all($sql, [$user['id']]);

    json_response([
        'total'      => $total,
        'page'       => $page,
        'page_size'  => $pageSize,
        'list'       => $rows,
    ]);
}

/**
 * 更新个人信息（QQ）
 */
function api_user_update_profile(): void
{
    $user = current_user();
    if (!$user) json_error('未登录', 401);

    $qq = input('qq', '');
    if ($qq === '') json_error('QQ不能为空');

    db_execute('UPDATE users SET qq = ?, updated_at = NOW() WHERE id = ?', [$qq, $user['id']]);
    json_response(null, 0, '个人信息已更新');
}

/**
 * 修改密码
 */
function api_user_change_password(): void
{
    $user = current_user();
    if (!$user) json_error('未登录', 401);

    $oldPwd = input('old_password', '');
    $newPwd = input('new_password', '');
    $confirmPwd = input('confirm_password', '');

    if (strlen($newPwd) < 6) json_error('新密码长度不能少于6位');
    if ($newPwd !== $confirmPwd) json_error('两次输出的密码不一致');

    if (!verify_password($oldPwd, $user['password'])) {
        json_error('旧密码错误');
    }

    $hash = hash_password($newPwd);
    db_execute('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?', [$hash, $user['id']]);
    
    // 清除会话强制重新登录
    session_destroy();
    
    json_response(null, 0, '密码修改成功，请重新登录');
}

