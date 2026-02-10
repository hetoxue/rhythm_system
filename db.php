<?php
/**
 * 数据库连接与基础方法
 */

function load_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        // 若不存在 config.php，则尝试使用示例配置（仅用于开发调试）
        $configFile = __DIR__ . '/config.example.php';
    }

    $config = require $configFile;
    return $config;
}

/**
 * 获取 PDO 实例（单例）
 *
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = load_config();
    $db = $config['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['database'],
        $db['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $db['username'], $db['password'], $options);
    return $pdo;
}

/**
 * 执行查询并返回所有结果
 */
function db_fetch_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * 执行查询并返回一行
 */
function db_fetch_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 执行增删改，返回影响行数
 */
function db_execute(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * 获取最后插入ID
 */
function db_last_insert_id(): string
{
    return db()->lastInsertId();
}

