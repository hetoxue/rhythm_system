<?php

/**
 * 入场 / 出场 相关接口
 */

require_once __DIR__ . '/../functions.php';

function handle_entrance_action(string $action): void
{
    switch ($action) {
        case 'enter':
            api_enter();
            break;
        case 'exit':
            api_exit();
            break;
        case 'current':
            api_current_entrance();
            break;
        case 'heartbeat':
            api_heartbeat();
            break;
        case 'check_booking_status':
            api_check_booking_status();
            break;
        default:
            json_error('未知 action：' . $action);
    }
}

/**
 * 用户当前在场记录
 */
function api_current_entrance(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $record = db_fetch_one(
        'SELECT * FROM entrance_records WHERE user_id = ? AND status = 0 ORDER BY id DESC LIMIT 1',
        [$user['id']]
    );

    json_response($record ?: null);
}

/**
 * 入场接口
 */
function api_enter(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    // 检查账户状态
    if ((int)$user['status'] === 0) {
        json_error('账号已被封禁，无法入场');
    }
    if ((int)$user['is_locked'] === 1 && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
        json_error('账号已锁定，请稍后再试');
    }

    // 检查是否已有进行中的入场记录
    $record = db_fetch_one(
        'SELECT * FROM entrance_records WHERE user_id = ? AND status = 0 LIMIT 1',
        [$user['id']]
    );
    if ($record) {
        json_error('您已在场，无法重复入场');
    }

    // 包场权限检查
    $booking = find_current_active_booking();
    if ($booking) {
        // 检查是否在邀请名单中
        if (!is_user_invited_to_booking((int)$booking['id'], $user)) {
            json_error('当前为包场时段，您不在受邀名单中，无法入场');
        }
        // 记录包场入场，出场时免费
        $isBookingEntrance = true;
    } else {
        $isBookingEntrance = false;
    }

    // 余额检查：禁止欠费入场（包场入场除外）
    $minBalance = 0;
    if (!$isBookingEntrance && (int)$user['balance'] < $minBalance) {
        json_error('账户余额不足，请先充值后再入场');
    }

    // 入场记录
    $sql = 'INSERT INTO entrance_records (user_id, enter_time, status, is_booking, created_at, updated_at)
            VALUES (?, NOW(), 0, ?, NOW(), NOW())';
    db_execute($sql, [$user['id'], $isBookingEntrance ? 1 : 0]);
    $entranceId = (int)db_last_insert_id();

    // 若处于包场时段，则记录到实际入场表（booking_actual_attendees）
    if ($isBookingEntrance) {
        db_execute(
            'INSERT INTO booking_actual_attendees (booking_id, user_id, entrance_id, created_at)
             VALUES (?, ?, ?, NOW())',
            [
                $booking['id'],
                $user['id'],
                $entranceId,
            ]
        );
    }

    add_operation_log(1, (int)$user['id'], 'user_enter', ['entrance_id' => $entranceId, 'is_booking' => $isBookingEntrance]);

    json_response(['entrance_id' => $entranceId, 'is_booking' => $isBookingEntrance], 0, '入场成功');
}

/**
 * 出场并计费
 */
function api_exit(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $entrance = db_fetch_one(
        'SELECT * FROM entrance_records WHERE user_id = ? AND status = 0 ORDER BY id DESC LIMIT 1',
        [$user['id']]
    );
    if (!$entrance) {
        json_error('没有进行中的入场记录');
    }

    $now = time();
    $enterTime = strtotime($entrance['enter_time']);
    if ($enterTime <= 0) {
        json_error('入场时间异常');
    }

    $durationMin = (int)ceil(($now - $enterTime) / 60);
    if ($durationMin < 0) {
        $durationMin = 0;
    }

    // 获取免费分钟数
    $freeMinutes = (int)get_config_value('free_minutes', 5);

    // 包场检查（包场期间是否免费）
    $booking = find_booking_for_entrance_period($entrance['enter_time'], date('Y-m-d H:i:s', $now));

    // 检查是否为包场入场
    $isBookingEntrance = isset($entrance['is_booking']) && (int)$entrance['is_booking'] === 1;

    // 获取包场撤场阈值（强制刷新），默认 5 分钟
    $graceMinutes = (int)get_config_value('booking_grace_minutes', 5, true);

    $isFreeByBooking = false;
    $bookingEndTime = null;
    $overtimeMinutes = 0;

    // 如果是包场入场，完全免费
    if ($isBookingEntrance) {
        $isFreeByBooking = true;
    } elseif ($booking && is_user_invited_to_booking((int)$booking['id'], $user)) {
        // 在包场日期的结束时间
        $bookingEndTime = strtotime($booking['date'] . ' ' . $booking['end_time']);
        // 如果当前时间在 包场结束时间 + 缓冲时间内，则这一整段算作包场免费
        if ($now <= ($bookingEndTime + $graceMinutes * 60)) {
            $isFreeByBooking = true;
        } else {
            // 超过缓冲期，计算超出时间（从包场结束时间开始算，包括缓冲期）
            $overtimeStart = $bookingEndTime;
            $overtimeMinutes = (int)ceil(($now - $overtimeStart) / 60);
            // 确保至少计算缓冲期的分钟数
            if ($overtimeMinutes < $graceMinutes) {
                $overtimeMinutes = $graceMinutes;
            }
        }
    }

    $chargeAmount = 0;
    $discountAmount = 0;
    $actualAmount = 0;
    $useTimeCard = 0;
    $timeCardId = null;
    $newBalance = (int)$user['balance'];

    if ($isFreeByBooking) {
        // 完全在包场时间内，免费
        $chargeAmount = 0;
    } elseif ($overtimeMinutes > 0) {
        // 超过包场缓冲期，需要计费
        // 计算超时费用
        $billingMinutes = (int)get_config_value('billing_minutes', 60);
        $billingAmount = (int)get_config_value('billing_amount', 0);

        if ($billingAmount > 0) {
            // 使用计费规则：xx分钟内收费xx元
            // 不满一个计费单位按一个计费单位算
            $units = (int)ceil($overtimeMinutes / $billingMinutes);
            $chargeAmount = $units * $billingAmount * 100; // 转换为分
        } else {
            // 按分钟计费：1分钟1元
            $pricePerMin = (int)get_config_value('price_per_minute', 100);
            $chargeAmount = $overtimeMinutes * $pricePerMin;
        }

        // 优先使用时长卡
        $timeCard = find_available_time_card_for_user((int)$user['id']);
        if ($timeCard && (int)$timeCard['remaining_min'] > 0) {
            $useTimeCard = 1;
            $timeCardId = (int)$timeCard['id'];
            $remaining = (int)$timeCard['remaining_min'];
            if ($remaining >= $overtimeMinutes) {
                $discountAmount = $chargeAmount;
                $actualAmount = 0;
                db_execute('UPDATE user_time_cards SET used_min = used_min + ?, remaining_min = remaining_min - ?, updated_at = NOW() WHERE id = ?', [$overtimeMinutes, $overtimeMinutes, $timeCardId]);
                db_execute('INSERT INTO time_card_usage_logs (user_time_card_id, user_id, entrance_id, used_min, created_at) VALUES (?, ?, ?, ?, NOW())', [$timeCardId, $user['id'], $entrance['id'], $overtimeMinutes]);
            } else {
                $discountAmount = $remaining * ($chargeAmount / $overtimeMinutes);
                $actualAmount = $chargeAmount - $discountAmount;
                db_execute('UPDATE user_time_cards SET used_min = used_min + ?, remaining_min = 0, status = 0, updated_at = NOW() WHERE id = ?', [$remaining, $timeCardId]);
                db_execute('INSERT INTO time_card_usage_logs (user_time_card_id, user_id, entrance_id, used_min, created_at) VALUES (?, ?, ?, ?, NOW())', [$timeCardId, $user['id'], $entrance['id'], $remaining]);
            }
        } else {
            $actualAmount = $chargeAmount;
        }

        // 检查余额是否充足
        if ($actualAmount > $newBalance) {
            json_error('余额不足，需支付 ' . number_format($actualAmount / 100, 2) . ' 元');
        }

        // 扣款
        $newBalance = $newBalance - $actualAmount;
        db_execute('UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?', [$newBalance, $user['id']]);
    } else {
        // 非包场情况，走正常计费逻辑
        if ($durationMin <= $freeMinutes) {
            $chargeAmount = 0;
        } else {
            $billMinutes = $durationMin - $freeMinutes;
            $billingMinutes = (int)get_config_value('billing_minutes', 60);
            $billingAmount = (int)get_config_value('billing_amount', 0);

            if ($billingAmount > 0) {
                // 使用计费规则：xx分钟内收费xx元
                // 不满一个计费单位按一个计费单位算
                $units = (int)ceil($billMinutes / $billingMinutes);
                $chargeAmount = $units * $billingAmount * 100; // 转换为分
            } else {
                // 按分钟计费：1分钟1元
                $pricePerMin = (int)get_config_value('price_per_minute', 100);
                $chargeAmount = $billMinutes * $pricePerMin;
            }

            // 优先使用时长卡
            $timeCard = find_available_time_card_for_user((int)$user['id']);
            if ($timeCard && (int)$timeCard['remaining_min'] > 0) {
                $useTimeCard = 1;
                $timeCardId = (int)$timeCard['id'];
                $remaining = (int)$timeCard['remaining_min'];
                if ($remaining >= $billMinutes) {
                    $discountAmount = $chargeAmount;
                    $actualAmount = 0;
                    db_execute('UPDATE user_time_cards SET used_min = used_min + ?, remaining_min = remaining_min - ?, updated_at = NOW() WHERE id = ?', [$billMinutes, $billMinutes, $timeCardId]);
                    db_execute('INSERT INTO time_card_usage_logs (user_time_card_id, user_id, entrance_id, used_min, created_at) VALUES (?, ?, ?, ?, NOW())', [$timeCardId, $user['id'], $entrance['id'], $billMinutes]);
                } else {
                    $discountAmount = $remaining * ($chargeAmount / $billMinutes);
                    $actualAmount = $chargeAmount - $discountAmount;
                    db_execute('UPDATE user_time_cards SET used_min = used_min + ?, remaining_min = 0, status = 0, updated_at = NOW() WHERE id = ?', [$remaining, $timeCardId]);
                    db_execute('INSERT INTO time_card_usage_logs (user_time_card_id, user_id, entrance_id, used_min, created_at) VALUES (?, ?, ?, ?, NOW())', [$timeCardId, $user['id'], $entrance['id'], $remaining]);
                }
            } else {
                $actualAmount = $chargeAmount;
            }

            // 检查余额是否充足
            if ($actualAmount > $newBalance) {
                json_error('余额不足，需支付 ' . number_format($actualAmount / 100, 2) . ' 元');
            }

            // 扣款
            $newBalance = $newBalance - $actualAmount;
            db_execute('UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?', [$newBalance, $user['id']]);
        }
    }

    // 更新入场记录
    $sql = 'UPDATE entrance_records SET
                exit_time = :exit_time,
                duration_min = :duration_min,
                charge_amount = :charge_amount,
                discount_amount = :discount_amount,
                actual_amount = :actual_amount,
                balance_after = :balance_after,
                use_time_card = :use_time_card,
                time_card_id = :time_card_id,
                status = 1,
                updated_at = NOW()
            WHERE id = :id';

    db_execute($sql, [
        date('Y-m-d H:i:s', $now),
        $durationMin,
        $chargeAmount,
        $discountAmount,
        $actualAmount,
        $newBalance,
        $useTimeCard,
        $timeCardId,
        $entrance['id'],
    ]);

    add_operation_log(1, (int)$user['id'], 'user_exit_and_pay', [
        'entrance_id'    => $entrance['id'],
        'duration_min'   => $durationMin,
        'charge_amount'  => $chargeAmount,
        'discount_amount'=> $discountAmount,
        'actual_amount'  => $actualAmount,
        'balance_after'  => $newBalance,
    ]);

    json_response([
        'duration_min'   => $durationMin,
        'charge_amount'  => $chargeAmount,
        'discount_amount'=> $discountAmount,
        'actual_amount'  => $actualAmount,
        'balance_after'  => $newBalance,
        'deducted'       => $actualAmount > 0,
        'use_time_card'  => $useTimeCard,
        'is_booking_free'=> $isFreeByBooking,
        'overtime_min'   => $overtimeMinutes,
    ], 0, '出场结算成功');
}

/**
 * 查找当前时间是否存在生效的包场（已通过且未取消）
 */
function find_current_active_booking(): ?array
{
    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    $bookings = db_fetch_all(
        'SELECT * FROM booking_orders 
         WHERE date = :date AND status = 1',
        [':date' => $today]
    );

    foreach ($bookings as $b) {
        if ($nowTime >= $b['start_time'] && $nowTime <= $b['end_time']) {
            return $b;
        }
    }
    return null;
}

/**
 * 根据入场/出场时间查找所在的包场（若有）
 */
function find_booking_for_entrance_period(string $enterTime, string $exitTime): ?array
{
    $enterDate = substr($enterTime, 0, 10);
    $exitDate = substr($exitTime, 0, 10);

    if ($enterDate !== $exitDate) {
        // 简化：暂不支持跨天包场
        return null;
    }

    $date = $enterDate;
    $start = substr($enterTime, 11, 8);
    $end = substr($exitTime, 11, 8);

    $bookings = db_fetch_all(
        'SELECT * FROM booking_orders 
         WHERE date = :date AND status = 1',
        [':date' => $date]
    );

    foreach ($bookings as $b) {
        // 如果整个入场时段都在包场范围内，可认为受包场影响
        if ($start >= $b['start_time'] && $end <= $b['end_time']) {
            return $b;
        }
    }

    return null;
}

/**
 * 判断用户是否在包场邀请名单中
 */
function is_user_invited_to_booking(int $bookingId, array $user): bool
{
    $rows = db_fetch_all(
        'SELECT * FROM booking_invited_users WHERE booking_id = :booking_id',
        [':booking_id' => $bookingId]
    );

    foreach ($rows as $row) {
        if ($row['user_id'] && (int)$row['user_id'] === (int)$user['id']) {
            return true;
        }
        if ($row['mobile'] && $row['mobile'] === $user['mobile']) {
            return true;
        }
        if ($row['qq'] && $row['qq'] === $user['qq']) {
            return true;
        }
    }
    return false;
}

/**
 * 检查当前包场状态
 */
function api_check_booking_status(): void
{
    $user = current_user();
    if (!$user) {
        json_error('未登录', 401);
    }

    $booking = find_current_active_booking();
    if (!$booking) {
        json_response([
            'has_booking' => false,
            'can_enter' => true,
            'message' => '当前无包场，可自由入场'
        ]);
    }

    $isInvited = is_user_invited_to_booking((int)$booking['id'], $user);

    // 检查用户是否是发起人
    $isInitiator = (int)$booking['user_id'] === (int)$user['id'];

    if ($isInitiator) {
        json_response([
            'has_booking' => true,
            'can_enter' => true,
            'is_invited' => true,
            'is_initiator' => true,
            'message' => '你已包场，当前可免费入场',
            'booking' => [
                'date' => $booking['date'],
                'start_time' => substr($booking['start_time'], 0, 5),
                'end_time' => substr($booking['end_time'], 0, 5)
            ]
        ]);
    } elseif ($isInvited) {
        // 获取发起人信息
        $initiator = db_fetch_one('SELECT * FROM users WHERE id = ?', [$booking['user_id']]);
        $initiatorName = $initiator ? ($initiator['qq'] ?: $initiator['mobile'] ?: '未知') : '未知';

        json_response([
            'has_booking' => true,
            'can_enter' => true,
            'is_invited' => true,
            'is_initiator' => false,
            'message' => '你已被' . $initiatorName . '(QQ:' . ($initiator['qq'] ?: '未设置') . ')邀请包场，当前可免费入场',
            'booking' => [
                'date' => $booking['date'],
                'start_time' => substr($booking['start_time'], 0, 5),
                'end_time' => substr($booking['end_time'], 0, 5)
            ]
        ]);
    } else {
        json_response([
            'has_booking' => true,
            'can_enter' => false,
            'is_invited' => false,
            'is_initiator' => false,
            'message' => '当前已被包场，无法进入',
            'booking' => [
                'date' => $booking['date'],
                'start_time' => substr($booking['start_time'], 0, 5),
                'end_time' => substr($booking['end_time'], 0, 5)
            ]
        ]);
    }
}

/**
 * 获取用户可用的时长卡（简单策略：取一个最近到期且有剩余的）
 */
function find_available_time_card_for_user(int $userId): ?array
{
    $now = date('Y-m-d H:i:s');
    $card = db_fetch_one(
        'SELECT * FROM user_time_cards
         WHERE user_id = :user_id 
           AND status = 1 
           AND remaining_min > 0
           AND expire_time > :now
         ORDER BY expire_time ASC, id ASC
         LIMIT 1',
        [
            ':user_id' => $userId,
            ':now'     => $now,
        ]
    );
    return $card ?: null;
}

/**
 * 心跳接口：用于实时扣除时长卡分钟数
 */
function api_heartbeat(): void
{
    $user = current_user();
    if (!$user) json_error('未登录', 401);

    $entrance = db_fetch_one('SELECT * FROM entrance_records WHERE user_id = ? AND status = 0 LIMIT 1', [$user['id']]);
    if (!$entrance) json_response(['in_field' => false]);

    // 检查是否为包场入场
    $isBookingEntrance = isset($entrance['is_booking']) && (int)$entrance['is_booking'] === 1;

    // 计算当前入场时长和应付金额
    $now = time();
    $enterTime = strtotime($entrance['enter_time']);
    $durationMin = (int)ceil(($now - $enterTime) / 60);

    // 如果是包场入场，检查是否超时
    if ($isBookingEntrance) {
        $booking = find_booking_for_entrance_period($entrance['enter_time'], date('Y-m-d H:i:s', $now));
        if ($booking) {
            $graceMinutes = (int)get_config_value('booking_grace_minutes', 5, true);
            $bookingEndTime = strtotime($booking['date'] . ' ' . $booking['end_time']);

            // 如果在包场时间内或缓冲期内，免费
            if ($now <= ($bookingEndTime + $graceMinutes * 60)) {
                json_response([
                    'in_field' => true,
                    'is_booking' => true,
                    'is_free' => true,
                    'duration_minutes' => $durationMin,
                    'amount' => 0,
                    'amount_text' => '免费'
                ]);
            } else {
                // 超时，计算费用
                $overtimeMinutes = (int)ceil(($now - $bookingEndTime) / 60);
                if ($overtimeMinutes < $graceMinutes) {
                    $overtimeMinutes = $graceMinutes;
                }

                $billingMinutes = (int)get_config_value('billing_minutes', 60);
                $billingAmount = (int)get_config_value('billing_amount', 0);
                $chargeAmount = 0;

                if ($billingAmount > 0) {
                    $units = (int)ceil($overtimeMinutes / $billingMinutes);
                    $chargeAmount = $units * $billingAmount * 100;
                } else {
                    $pricePerMin = (int)get_config_value('price_per_minute', 100);
                    $chargeAmount = $overtimeMinutes * $pricePerMin;
                }

                json_response([
                    'in_field' => true,
                    'is_booking' => true,
                    'is_free' => false,
                    'duration_minutes' => $durationMin,
                    'overtime_minutes' => $overtimeMinutes,
                    'amount' => $chargeAmount,
                    'amount_text' => '￥' . number_format($chargeAmount / 100, 2)
                ]);
            }
        } else {
            // 没有包场记录，免费
            json_response([
                'in_field' => true,
                'is_booking' => true,
                'is_free' => true,
                'duration_minutes' => $durationMin,
                'amount' => 0,
                'amount_text' => '免费'
            ]);
        }
    }

    // 非包场入场，正常计费
    $freeMinutes = (int)get_config_value('free_minutes', 5);
    $chargeAmount = 0;
    if ($durationMin > $freeMinutes) {
        $billMinutes = $durationMin - $freeMinutes;
        $billingMinutes = (int)get_config_value('billing_minutes', 60);
        $billingAmount = (int)get_config_value('billing_amount', 0);

        if ($billingAmount > 0) {
            $units = (int)ceil($billMinutes / $billingMinutes);
            $chargeAmount = $units * $billingAmount * 100;
        } else {
            $pricePerMin = (int)get_config_value('price_per_minute', 100);
            $chargeAmount = $billMinutes * $pricePerMin;
        }
    }

    // 检查是否有可用时长卡
    $card = find_available_time_card_for_user((int)$user['id']);
    if ($card) {
        $lastUpdate = strtotime($entrance['updated_at']);
        $diffMin = (int)floor(($now - $lastUpdate) / 60);

        if ($diffMin >= 1) {
            // 扣除分钟数
            $toDeduct = min($diffMin, (int)$card['remaining_min']);
            db_execute('UPDATE user_time_cards SET used_min = used_min + ?, remaining_min = remaining_min - ?, updated_at = NOW() WHERE id = ?', [$toDeduct, $toDeduct, $card['id']]);
            db_execute('UPDATE entrance_records SET updated_at = NOW() WHERE id = ?', [$entrance['id']]);
            // 记录日志
            db_execute('INSERT INTO time_card_usage_logs (user_time_card_id, user_id, entrance_id, used_min, created_at) VALUES (?, ?, ?, ?, NOW())', [$card['id'], $user['id'], $entrance['id'], $toDeduct]);

            if ((int)$card['remaining_min'] <= $toDeduct) {
                db_execute('UPDATE user_time_cards SET status = 0 WHERE id = ?', [$card['id']]);
            }

            // 重新获取最新的卡片信息返回给前端
            $card = find_available_time_card_for_user((int)$user['id']);
        }
    }

    json_response([
        'in_field' => true,
        'is_booking' => false,
        'is_free' => $chargeAmount === 0,
        'remaining_min' => $card ? $card['remaining_min'] : null,
        'duration_minutes' => $durationMin,
        'amount' => $chargeAmount,
        'amount_text' => $chargeAmount === 0 ? '免费' : '￥' . number_format($chargeAmount / 100, 2)
    ]);
}

