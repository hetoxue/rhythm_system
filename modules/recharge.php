<?php

/**
 * 充值相关接口（在线支付入口 + 卡券充值）
 * 
 * 在线支付部分仅提供订单创建与状态查询，具体支付跳转/回调需根据你的支付通道进行对接。
 */

require_once __DIR__ . '/../functions.php';

function handle_recharge_action(string $action): void
{
    switch ($action) {
        case 'create_order':
            api_recharge_create_order();
            break;
        case 'card_recharge':
            api_recharge_by_card();
            break;
        case 'list':
            api_recharge_list();
            break;
        default:
            json_error('未知 action：' . $action);
    }
}

/**
 * 创建在线充值订单（仅生成订单，具体支付请在前端 / 回调里处理）
 */
function api_recharge_create_order(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $amount = (int)input('amount', 0); // 单位：分
    $payMethod = (int)input('pay_method', 0); // 1=微信 2=微信易支付 3=支付宝 4=支付宝易支付

    if ($amount <= 0) {
        json_error('充值金额必须大于0');
    }

    if (!in_array($payMethod, [1, 2, 3, 4], true)) {
        json_error('不支持的支付方式');
    }

    $orderNo = 'RC' . date('YmdHis') . mt_rand(1000, 9999);

    $sql = 'INSERT INTO recharge_orders (order_no, user_id, amount, pay_method, status, created_at, updated_at)
            VALUES (:order_no, :user_id, :amount, :pay_method, 0, NOW(), NOW())';

    db_execute($sql, [
        ':order_no'   => $orderNo,
        ':user_id'    => $user['id'],
        ':amount'     => $amount,
        ':pay_method' => $payMethod,
    ]);

    // 返回订单信息，前端可根据 pay_method 调用对应支付接口
    json_response([
        'order_no'   => $orderNo,
        'amount'     => $amount,
        'pay_method' => $payMethod,
    ], 0, '充值订单创建成功');
}

/**
 * 卡券充值
 */
function api_recharge_by_card(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $cardNo = input('card_no');
    if (!$cardNo) {
        json_error('卡密不能为空');
    }

    $card = db_fetch_one('SELECT * FROM recharge_cards WHERE card_no = ?', [$cardNo]);
    if (!$card) {
        json_error('卡密不存在');
    }

    if ((int)$card['status'] === 1) {
        json_error('该卡密已被使用');
    }

    if ((int)$card['status'] === 2) {
        json_error('该卡密已作废');
    }

    if ($card['expire_time'] && strtotime($card['expire_time']) < time()) {
        json_error('该卡密已过期');
    }

    $amount = (int)$card['amount'];

    // 使用事务，确保余额与卡券状态原子更新
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 更新卡券为已使用
        $sql = 'UPDATE recharge_cards 
                SET status = 1, used_by = :user_id, used_at = NOW(), updated_at = NOW()
                WHERE id = :id AND status = 0';
        $affected = db_execute($sql, [
            ':user_id' => $user['id'],
            ':id'      => $card['id'],
        ]);
        if ($affected === 0) {
            throw new Exception('卡券状态异常，请稍后重试');
        }

        // 更新账户余额
        $newBalance = (int)$user['balance'] + $amount;
        db_execute(
            'UPDATE users SET balance = :balance, updated_at = NOW() WHERE id = :id',
            [
                ':balance' => $newBalance,
                ':id'      => $user['id'],
            ]
        );

        // 生成充值订单记录
        $orderNo = 'RC_CARD' . date('YmdHis') . mt_rand(1000, 9999);
        db_execute(
            'INSERT INTO recharge_orders (order_no, user_id, amount, pay_method, pay_channel_no, status, paid_at, created_at, updated_at)
             VALUES (:order_no, :user_id, :amount, 5, :card_no, 1, NOW(), NOW(), NOW())',
            [
                ':order_no' => $orderNo,
                ':user_id'  => $user['id'],
                ':amount'   => $amount,
                ':card_no'  => $cardNo,
            ]
        );

        // 插入流水记录 (对应 user.html 中的类型 5: 后台调整 或 3: 其他。建议此处记为 3 或新增类型)
        // 根据 user.html：typeMap = { 1: '入场计费', 2: '购买套餐', 3: '订单支付', 4: '包场费用', 5: '后台调整' }
        // 此处暂记为 3 订单支付，或者我们习惯把充值也记录在流水里（amount 为负代表余额增加）
        db_execute(
            'INSERT INTO consume_records (user_id, type, related_id, amount, balance_after, remark, created_at)
             VALUES (?, 3, ?, ?, ?, ?, NOW())',
            [$user['id'], 0, -$amount, $newBalance, '使用充值卡充值：' . $cardNo]
        );

        $pdo->commit();

        add_operation_log(1, (int)$user['id'], 'recharge_by_card', [
            'card_no' => $cardNo,
            'amount'  => $amount,
        ]);

        json_response([
            'amount'        => $amount,
            'balance_after' => $newBalance,
        ], 0, '充值成功');
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('充值失败：' . $e->getMessage());
    }
}

/**
 * 我的充值记录列表
 */
function api_recharge_list(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $page = max(1, (int)input('page', 1));
    $pageSize = max(1, min(50, (int)input('page_size', 20)));
    $offset = ($page - 1) * $pageSize;

    $totalRow = db_fetch_one(
        'SELECT COUNT(*) AS cnt FROM recharge_orders WHERE user_id = ?',
        [$user['id']]
    );
    $total = (int)($totalRow['cnt'] ?? 0);

    $rows = db_fetch_all(
        'SELECT * FROM recharge_orders WHERE user_id = ? ORDER BY id DESC LIMIT :offset, :limit',
        [
            $user['id'],
            // 由于 PDO 默认不支持命名参数绑定 LIMIT，简单写成 ? 占位
        ]
    );
    // 上面为了兼容，这里简化：重新写不带命名参数的 SQL
    $sql = 'SELECT * FROM recharge_orders WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int)$offset . ', ' . (int)$pageSize;
    $rows = db_fetch_all($sql, [$user['id']]);

    json_response([
        'total'      => $total,
        'page'       => $page,
        'page_size'  => $pageSize,
        'list'       => $rows,
    ]);
}

