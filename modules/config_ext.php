<?php

/**
 * 扩展配置模块 - 用于添加计费规则等配置
 */

require_once __DIR__ . '/../functions.php';

function handle_config_ext_action(string $action): void
{
    switch ($action) {
        case 'public':
            api_config_public();
            break;
        default:
            json_error('未知 action：' . $action);
    }
}

/**
 * 前台公共配置：轮播图、公告、部分系统参数（扩展版）
 */
function api_config_public(): void
{
    $banners = db_fetch_all(
        'SELECT * FROM banners WHERE status = 1 ORDER BY sort_order ASC, id DESC'
    );

    $announcements = db_fetch_all(
        'SELECT * FROM announcements WHERE status = 1 ORDER BY sort_order ASC, id DESC'
    );

    $slots = db_fetch_all(
        'SELECT * FROM booking_slots WHERE status = 1 ORDER BY start_time ASC'
    );

    $freeMinutes = (int)get_config_value('free_minutes', 5);
    $systemName = get_config_value('system_name', '场地计费系统');
    $themeConfig = get_config_value('theme_config', null);
    $billingMinutes = (int)get_config_value('billing_minutes', 60);
    $billingAmount = (int)get_config_value('billing_amount', 0);
    $bookingGraceMinutes = (int)get_config_value('booking_grace_minutes', 5);
    $smtpHost = get_config_value('smtp_host', '');
    $smtpPort = get_config_value('smtp_port', 587);
    $smtpUser = get_config_value('smtp_user', '');
    $smtpFrom = get_config_value('smtp_from', '');
    $smtpFromName = get_config_value('smtp_from_name', '场地计费系统');

    // 获取favicon配置
    $loginFavicon = get_config_value('login_favicon', '');
    $userFavicon = get_config_value('user_favicon', '');
    $adminFavicon = get_config_value('admin_favicon', '');

    json_response([
        'banners'       => $banners,
        'announcements' => $announcements,
        'booking_slots' => $slots,
        'system_name'   => $systemName,
        'configs'       => [
            'free_minutes' => $freeMinutes,
            'system_name'  => $systemName,
            'theme_config' => $themeConfig ? json_decode($themeConfig, true) : null,
            'billing_minutes' => $billingMinutes,
            'billing_amount' => $billingAmount,
            'booking_grace_minutes' => $bookingGraceMinutes,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_user' => $smtpUser,
            'smtp_from' => $smtpFrom,
            'smtp_from_name' => $smtpFromName,
            'login_favicon' => $loginFavicon,
            'user_favicon' => $userFavicon,
            'admin_favicon' => $adminFavicon,
        ],
    ]);
}
