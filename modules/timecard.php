<?php

/**
 * 时长卡（套餐）相关接口
 */

require_once __DIR__ . '/../functions.php';

function handle_timecard_action(string $action): void
{
    switch ($action) {
        case 'plans':
            api_timecard_plans();
            break;
        case 'buy':
            api_timecard_buy();
            break;
        case 'my_cards':
            api_timecard_my_cards();
            break;
        case 'usage':
            api_timecard_usage();
            break;
        default:
            json_error('未知 action：' . $action);
    }
}

/**
 * 获取上架的时长卡套餐列表
 */
function api_timecard_plans(): void
{
    $rows = db_fetch_all(
        'SELECT * FROM time_card_plans WHERE status = 1 ORDER BY id ASC'
    );
    json_response($rows);
}

/**
 * 购买时长卡（示例：直接从余额扣费）
 * 若需走在线支付，可与 recharge_orders 关联，这里做的是简单直接扣费实现。
 */
function api_timecard_buy(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $planId = (int)input('plan_id', 0);
    if ($planId <= 0) {
        json_error('无效的套餐');
    }

    $plan = db_fetch_one('SELECT * FROM time_card_plans WHERE id = ?', [$planId]);
    if (!$plan || (int)$plan['status'] !== 1) {
        json_error('套餐不存在或已下架');
    }

    // 检查限购次数
    $maxPerUser = (int)$plan['max_per_user'];
    if ($maxPerUser > 0) {
        $row = db_fetch_one(
            'SELECT COUNT(*) AS cnt FROM user_time_cards WHERE user_id = ? AND plan_id = ?',
            [$user['id'], $planId]
        );
        $cnt = (int)($row['cnt'] ?? 0);
        if ($cnt >= $maxPerUser) {
            json_error('该套餐购买次数已达上限');
        }
    }

    $price = (int)$plan['price'];
    if ($price <= 0) {
        json_error('套餐价格异常');
    }

    // 检查余额是否足够
    if ((int)$user['balance'] < $price) {
        json_error('余额不足，请先充值');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 扣减余额
        $newBalance = (int)$user['balance'] - $price;
        db_execute(
            'UPDATE users SET balance = :balance, updated_at = NOW() WHERE id = :id',
            [
                ':balance' => $newBalance,
                ':id'      => $user['id'],
            ]
        );

        // 创建用户时长卡
        $now = date('Y-m-d H:i:s');
        $expire = date('Y-m-d H:i:s', time() + (int)$plan['valid_days'] * 86400);

        $sql = 'INSERT INTO user_time_cards 
                (user_id, plan_id, total_min, used_min, remaining_min, price, status, start_time, expire_time, created_at, updated_at)
                VALUES (?, ?, ?, 0, ?, ?, 1, ?, ?, ?, ?)';
        db_execute($sql, [
            $user['id'],
            $planId,
            $plan['duration_min'],
            $plan['duration_min'],
            $price,
            $now,
            $expire,
            $now,
            $now,
        ]);

        $userCardId = (int)db_last_insert_id();

        // 消费记录
        db_execute(
            'INSERT INTO consume_records (user_id, type, related_id, amount, balance_after, remark, created_at)
             VALUES (:user_id, 2, :related_id, :amount, :balance_after, :remark, NOW())',
            [
                ':user_id'       => $user['id'],
                ':related_id'    => $userCardId,
                ':amount'        => $price,
                ':balance_after' => $newBalance,
                ':remark'        => '购买时长卡：' . $plan['name'],
            ]
        );

        $pdo->commit();

        add_operation_log(1, (int)$user['id'], 'buy_time_card', [
            'plan_id'      => $planId,
            'user_card_id' => $userCardId,
            'price'        => $price,
        ]);

        json_response([
            'user_time_card_id' => $userCardId,
            'balance_after'     => $newBalance,
        ], 0, '购买成功');
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('购买失败：' . $e->getMessage());
    }
}

/**
 * 我的时长卡列表
 */
function api_timecard_my_cards(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    // 过滤掉失效且过期超过 3 天的记录
    $rows = db_fetch_all(
        'SELECT utc.*, p.name AS plan_name 
         FROM user_time_cards utc
         LEFT JOIN time_card_plans p ON utc.plan_id = p.id
         WHERE utc.user_id = ? 
           AND (utc.status = 1 OR utc.expire_time > DATE_SUB(NOW(), INTERVAL 3 DAY))
         ORDER BY utc.status DESC, utc.expire_time ASC',
        [$user['id']]
    );

    json_response($rows);
}

/**
 * 某张时长卡的使用记录
 */
function api_timecard_usage(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $userCardId = (int)input('user_time_card_id', 0);
    if ($userCardId <= 0) {
        json_error('参数错误');
    }

    // 简单校验归属
    $card = db_fetch_one(
        'SELECT * FROM user_time_cards WHERE id = ? AND user_id = ?',
        [$userCardId, $user['id']]
    );
    if (!$card) {
        json_error('时长卡不存在');
    }

    $rows = db_fetch_all(
        'SELECT u.*, e.enter_time, e.exit_time 
         FROM time_card_usage_logs u
         LEFT JOIN entrance_records e ON u.entrance_id = e.id
         WHERE u.user_time_card_id = ?
         ORDER BY u.id DESC',
        [$userCardId]
    );

    json_response($rows);
}

