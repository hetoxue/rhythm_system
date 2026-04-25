<?php
/**
 * 系统配置示例文件
 * 
 * 使用方法：
 * 1. 复制本文件为 config.php
 * 2. 修改数据库连接信息
 * 3. 在服务器上确保 config.php 不对外暴露（仅后端可读）
 */

return [
    'env' => 'production',

    // 数据库配置
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'rhythm_system',
        'username' => 'rhythm_system',
        'password' => '1',
        'charset' => 'utf8mb4',
    ],

    // 会话配置
    'session' => [
        'name' => 'RH_SESSION_ID',
        'lifetime' => 86400,
        'cookie_secure' => false, // 若全站HTTPS可改为 true
    ],

    // 安全相关
    'security' => [
        'password_algo' => PASSWORD_DEFAULT,
        // 用于 JWT 或签名的应用密钥（需要自行修改）
        'app_key' => '8f9g89retns8fj2jk4hsdf734w5ht',
    ],

    // 其他系统级配置
    'system' => [
        // 默认免费分钟数，实际运行时会优先从 system_configs 表读取
        'default_free_minutes' => 5,
    ],
];

