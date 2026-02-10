<?php

/**
 * 包场预约相关接口
 * 处理包场申请、修改、计算费用等逻辑
 */

require_once __DIR__ . '/../functions.php';

/**
 * 接口分发
 */
function handle_booking_action(string $action): void
{
    switch ($action) {
        case 'apply':
            api_booking_apply();
            break;
        case 'update':
            api_booking_update();
            break;
        case 'my_list':
            api_booking_my_list();
            break;
        case 'detail':
            api_booking_detail();
            break;
        case 'public_list':
            api_booking_public_list();
            break;
        default:
            json_error('未知 action：' . $action);
    }
}

/**
 * 计算包场费用
 * 支持跨日场次（如 22:00 - 次日 10:00）
 * 原理：将预约时长切分为分钟，匹配 booking_slots 中的时段价格比例
 */
function calculate_booking_price(string $date, string $startTime, string $endTime): int
{
    // 获取所有启用的场次配置
    $slots = db_fetch_all('SELECT * FROM booking_slots WHERE status = 1');
    if (empty($slots)) {
        return 0; // 若未配置场次，则无法计费，默认 0
    }

    $startTs = strtotime("$date $startTime");
    $endTs = strtotime("$date $endTime");

    // 处理跨日：如果结束时间小于开始时间，认为是第二天
    if ($endTs <= $startTs) {
        $endTs = strtotime("$date $endTime +1 day");
    }

    $totalPrice = 0;
    
    // 采用分钟积分法计算费用，逻辑简单可靠
    $minutes = ($endTs - $startTs) / 60;
    
    // 提前计算每个 slot 的每分钟单价，缓存起来提高效率
    $slotConfigs = [];
    foreach ($slots as $slot) {
        $sStart = $slot['start_time']; 
        $sEnd = $slot['end_time'];
        
        // 计算该 Slot 本身跨越的总分钟数
        $sStartTs = strtotime("2000-01-01 $sStart");
        $sEndTs = strtotime("2000-01-01 $sEnd");
        if ($sEndTs <= $sStartTs) {
            $sEndTs += 86400; // 跨天
        }
        $slotDuration = ($sEndTs - $sStartTs) / 60;
        
        if ($slotDuration > 0) {
            $slotConfigs[] = [
                'start' => $sStart,
                'end' => $sEnd,
                'pricePerMin' => (float)$slot['price'] / $slotDuration,
                'isCross' => $sEnd <= $sStart
            ];
        }
    }

    // 遍历预约的每一分钟
    for ($i = 0; $i < $minutes; $i++) {
        $currTs = $startTs + $i * 60;
        $currHi = date('H:i:00', $currTs);
        
        foreach ($slotConfigs as $cfg) {
            $isIn = false;
            if (!$cfg['isCross']) {
                // 普通时段 08:00 - 20:00
                if ($currHi >= $cfg['start'] && $currHi < $cfg['end']) $isIn = true;
            } else {
                // 跨天时段 22:00 - 08:00
                if ($currHi >= $cfg['start'] || $currHi < $cfg['end']) $isIn = true;
            }
            
            if ($isIn) {
                $totalPrice += $cfg['pricePerMin'];
                break; // 匹配到一个场次即可，不重复计算
            }
        }
    }

    return (int)round($totalPrice);
}

/**
 * 提交包场申请
 */
function api_booking_apply(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $date = input('date');
    $startTime = input('start_time');
    $endTime = input('end_time');
    $purpose = input('purpose', '');
    $invitedList = input('invited', '');

    if (!$date || !$startTime || !$endTime) {
        json_error('日期和时间不能为空');
    }

    // 格式化时间
    $startTimeFull = (strlen($startTime) === 5) ? $startTime . ':00' : $startTime;
    $endTimeFull = (strlen($endTime) === 5) ? $endTime . ':00' : $endTime;

    // 计算价格
    $price = calculate_booking_price($date, $startTimeFull, $endTimeFull);
    
    // 余额检查
    if ($user['balance'] < $price) {
        json_error('余额不足，本次计费需 ' . number_format($price/100, 2) . ' 元，当前余额 ' . number_format($user['balance']/100, 2) . ' 元');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 预扣费
        $newBalance = $user['balance'] - $price;
        db_execute('UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?', [$newBalance, $user['id']]);

        // 插入订单（包含价格）
        $sql = 'INSERT INTO booking_orders 
                (user_id, date, start_time, end_time, purpose, status, price, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, NOW(), NOW())';
        db_execute($sql, [
            $user['id'],
            $date,
            $startTimeFull,
            $endTimeFull,
            $purpose,
            $price
        ]);
        $bookingId = (int)db_last_insert_id();

        // 记录消费流水
        db_execute(
            'INSERT INTO consume_records (user_id, type, related_id, amount, balance_after, remark, created_at)
             VALUES (?, 4, ?, ?, ?, ?, NOW())',
            [$user['id'], $bookingId, $price, $newBalance, '包场预扣费']
        );

        // 处理邀请名单（自动包含发起人）
        process_invited_users($bookingId, $invitedList, $user);

        $pdo->commit();
        json_response(['booking_id' => $bookingId, 'price' => $price], 0, '包场申请已提交，费用已预扣');
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('提交失败：' . $e->getMessage());
    }
}

/**
 * 修改包场申请（仅限待审核状态）
 */
function api_booking_update(): void
{
    $user = current_user();
    if (!$user) json_error('未登录', 401);

    $bookingId = (int)input('booking_id', 0);
    $date = input('date');
    $startTime = input('start_time');
    $endTime = input('end_time');
    $purpose = input('purpose', '');
    $invitedList = input('invited', '');

    if ($bookingId <= 0) json_error('参数错误');

    // 检查订单所属及状态
    $booking = db_fetch_one('SELECT * FROM booking_orders WHERE id = ? AND user_id = ?', [$bookingId, $user['id']]);
    if (!$booking) json_error('订单不存在');
    if ($booking['status'] != 0) json_error('只有待审核的订单可以修改');

    $startTimeFull = (strlen($startTime) === 5) ? $startTime . ':00' : $startTime;
    $endTimeFull = (strlen($endTime) === 5) ? $endTime . ':00' : $endTime;

    // 重新计算费用
    $newPrice = calculate_booking_price($date, $startTimeFull, $endTimeFull);
    $oldPrice = (int)$booking['price'];
    $diff = $newPrice - $oldPrice; // >0 补，<0 退

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $uInfo = db_fetch_one('SELECT balance FROM users WHERE id = ? FOR UPDATE', [$user['id']]);
        
        if ($diff > 0) {
            // 补差价
            if ($uInfo['balance'] < $diff) throw new Exception('余额不足补缴差价');
            $finalBalance = $uInfo['balance'] - $diff;
            db_execute('UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?', [$finalBalance, $user['id']]);
            db_execute(
                'INSERT INTO consume_records (user_id, type, related_id, amount, balance_after, remark, created_at) VALUES (?, 4, ?, ?, ?, ?, NOW())',
                [$user['id'], $bookingId, $diff, $finalBalance, '修改包场申请补缴差价']
            );
        } elseif ($diff < 0) {
            // 退差价
            $refund = abs($diff);
            $finalBalance = $uInfo['balance'] + $refund;
            db_execute('UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?', [$finalBalance, $user['id']]);
            db_execute(
                'INSERT INTO consume_records (user_id, type, related_id, amount, balance_after, remark, created_at) VALUES (?, 4, ?, ?, ?, ?, NOW())',
                [$user['id'], $bookingId, -$refund, $finalBalance, '修改包场申请退回差价']
            );
        }

        // 更新记录
        db_execute(
            'UPDATE booking_orders SET date=?, start_time=?, end_time=?, purpose=?, price=?, updated_at=NOW() WHERE id=?',
            [$date, $startTimeFull, $endTimeFull, $purpose, $newPrice, $bookingId]
        );

        // 重置邀请名单（自动包含发起人）
        process_invited_users($bookingId, $invitedList, $user);

        $pdo->commit();
        json_response(['booking_id' => $bookingId, 'new_price' => $newPrice], 0, '修改成功');
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('修改失败：' . $e->getMessage());
    }
}

/**
 * 内部方法：处理受邀人员名单入库
 */
function process_invited_users(int $bookingId, ?string $invitedList, ?array $initiator = null): void
{
    // 先删除旧的邀请记录
    db_execute('DELETE FROM booking_invited_users WHERE booking_id = ?', [$bookingId]);

    // 自动添加发起人到受邀名单
    if ($initiator) {
        db_execute(
            'INSERT INTO booking_invited_users (booking_id, mobile, qq, user_id, created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$bookingId, $initiator['mobile'] ?? null, $initiator['qq'] ?? null, $initiator['id'] ?? null]
        );
    }

    if (!$invitedList) return;
    $lines = preg_split('/\r\n|\r|\n/', $invitedList);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $mobile = null; $qq = null;
        if (preg_match('/^1[3-9]\d{9}$/', $line)) {
            $mobile = $line;
        } elseif (preg_match('/^\d{5,15}$/', $line)) {
            $qq = $line;
        } else {
            $qq = $line; // 其他格式暂存 QQ 字段
        }

        $userId = null;
        if ($mobile) {
            $u = db_fetch_one('SELECT id FROM users WHERE mobile = ?', [$mobile]);
            if ($u) $userId = $u['id'];
        } elseif ($qq) {
            $u = db_fetch_one('SELECT id FROM users WHERE qq = ?', [$qq]);
            if ($u) $userId = $u['id'];
        }

        db_execute(
            'INSERT INTO booking_invited_users (booking_id, mobile, qq, user_id, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$bookingId, $mobile, $qq, $userId]
        );
    }
}

/**
 * 获取我的包场列表
 */
function api_booking_my_list(): void
{
    $user = current_user();
    if (!$user) json_error('未登录', 401);

    $rows = db_fetch_all('SELECT * FROM booking_orders WHERE user_id = ? ORDER BY id DESC', [$user['id']]);
    json_response($rows);
}

/**
 * 获取包场详情
 */
function api_booking_detail(): void
{
    $user = current_user();
    if (!$user) json_error('未登录', 401);

    $bookingId = (int)input('booking_id', 0);
    $booking = db_fetch_one('SELECT * FROM booking_orders WHERE id = ? AND user_id = ?', [$bookingId, $user['id']]);
    if (!$booking) json_error('不存在该订单');

    $invited = db_fetch_all('SELECT * FROM booking_invited_users WHERE booking_id = ?', [$bookingId]);
    $actual = db_fetch_all(
        'SELECT a.*, u.mobile, u.qq FROM booking_actual_attendees a LEFT JOIN users u ON a.user_id = u.id WHERE a.booking_id = ?',
        [$bookingId]
    );

    json_response([
        'booking' => $booking,
        'invited' => $invited,
        'actual'  => $actual,
    ]);
}
/**
 * 公共包场列表（用户端公示）
 */
function api_booking_public_list(): void
{
    // 获取今天及以后的待审核 (0) 或 已通过 (1) 预约
    // 使用 CURRENT_DATE 确保与服务器日期同步，防止时差
    $sql = "SELECT date, start_time, end_time, status 
            FROM booking_orders 
            WHERE status IN (0, 1) AND date >= CURRENT_DATE() 
            ORDER BY date ASC, start_time ASC 
            LIMIT 50";
    $rows = db_fetch_all($sql);
    json_response($rows);
}
