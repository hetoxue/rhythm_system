<?php
/**
 * 简单 API 入口文件
 * 
 * 前端通过：
 *   POST /api.php?module=auth&action=login
 * 的形式调用。
 */

require_once __DIR__ . '/functions.php';

init_session();

$module = input('module', '');
$action = input('action', '');

if ($module === '' || $action === '') {
    json_error('缺少参数 module 或 action');
}

try {
    switch ($module) {
        case 'auth':
            require_once __DIR__ . '/modules/auth.php';
            handle_auth_action($action);
            break;
        case 'user':
            require_once __DIR__ . '/modules/user.php';
            handle_user_action($action);
            break;
        case 'entrance':
            require_once __DIR__ . '/modules/entrance.php';
            handle_entrance_action($action);
            break;
        case 'recharge':
            require_once __DIR__ . '/modules/recharge.php';
            handle_recharge_action($action);
            break;
        case 'timecard':
            require_once __DIR__ . '/modules/timecard.php';
            handle_timecard_action($action);
            break;
        case 'booking':
            require_once __DIR__ . '/modules/booking.php';
            handle_booking_action($action);
            break;
        case 'config':
            require_once __DIR__ . '/modules/config_ext.php';
            handle_config_ext_action($action);
            break;
        case 'admin':
            require_once __DIR__ . '/modules/admin.php';
            handle_admin_action($action);
            break;
        case 'product':
            require_once __DIR__ . '/modules/product.php';
            handle_product_action($action);
            break;
        default:
            json_error('未知模块：' . $module);
    }
} catch (Throwable $e) {
    // 生产环境中可隐藏详细错误，仅返回通用提示
    json_error('服务器异常：' . $e->getMessage(), 500);
}

