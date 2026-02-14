<?php
/**
 * 音游窝计费管理系统 - 安装程序
 * 
 * 使用方法：
 * 1. 将此文件放在网站根目录
 * 2. 访问 http://your-domain/install.php
 * 3. 按照提示完成安装
 * 4. 安装完成后删除此文件
 */

// 禁用错误输出到页面
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();

// 安装步骤
define('STEP_WELCOME', 1);
define('STEP_ENV_CHECK', 2);
define('STEP_DB_CONFIG', 3);
define('STEP_INSTALL', 4);
define('STEP_COMPLETE', 5);

// 获取当前步骤
$step = isset($_GET['step']) ? (int)$_GET['step'] : STEP_WELCOME;

// 检查是否已安装
if (file_exists(__DIR__ . '/install.lock') && $step !== STEP_COMPLETE) {
    die('<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; text-align: center;">
        <h1 style="color: #e74c3c;">系统已安装</h1>
        <p>系统已经安装完成，请删除 install.php 文件以确保安全。</p>
        <a href="index.html" style="color: #3498db; text-decoration: none;">进入首页</a>
    </div>');
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === STEP_DB_CONFIG) {
        $_SESSION['db_config'] = [
            'host' => $_POST['db_host'],
            'port' => $_POST['db_port'],
            'database' => $_POST['db_name'],
            'username' => $_POST['db_user'],
            'password' => $_POST['db_pass'],
            'admin_username' => $_POST['admin_username'],
            'admin_password' => $_POST['admin_password'],
            'admin_email' => $_POST['admin_email']
        ];
        header('Location: install.php?step=' . STEP_INSTALL);
        exit;
    }
}

// 环境检查
function checkEnvironment() {
    $checks = [];
    
    // PHP版本
    $phpVersion = PHP_VERSION;
    $checks['php_version'] = [
        'name' => 'PHP版本',
        'required' => '>= 7.4',
        'current' => $phpVersion,
        'passed' => version_compare($phpVersion, '7.4', '>=')
    ];
    
    // 必需扩展
    $requiredExtensions = ['pdo', 'pdo_mysql', 'mysqli', 'json', 'mbstring'];
    foreach ($requiredExtensions as $ext) {
        $checks['ext_' . $ext] = [
            'name' => 'PHP扩展: ' . $ext,
            'required' => '已安装',
            'current' => extension_loaded($ext) ? '已安装' : '未安装',
            'passed' => extension_loaded($ext)
        ];
    }
    
    // 目录权限
    $dirs = ['config', 'uploads', 'logs'];
    foreach ($dirs as $dir) {
        $dirPath = __DIR__ . '/' . $dir;
        if (!is_dir($dirPath)) {
            @mkdir($dirPath, 0755, true);
        }
        $writable = is_writable($dirPath);
        $checks['dir_' . $dir] = [
            'name' => '目录权限: ' . $dir,
            'required' => '可写',
            'current' => $writable ? '可写' : '不可写',
            'passed' => $writable
        ];
    }
    
    return $checks;
}

// 测试数据库连接
function testDatabaseConnection($config) {
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // 测试创建数据库
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        return true;
    } catch (PDOException $e) {
        throw new Exception('数据库连接失败: ' . $e->getMessage());
    }
}

// 执行安装
function performInstall($config) {
    try {
        // 连接数据库（不指定数据库）
        $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // 创建数据库
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // 连接到指定数据库
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // 读取并执行SQL脚本
        $sqlFile = __DIR__ . '/init_db_new.sql';
        if (!file_exists($sqlFile)) {
            throw new Exception('找不到数据库初始化脚本: init_db_new.sql');
        }
        
        $sql = file_get_contents($sqlFile);
        
        // 按行分割并过滤
        $lines = explode("\n", $sql);
        $filteredLines = [];
        $skipNext = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // 跳过注释行
            if (preg_match('/^--/', $line)) {
                continue;
            }
            
            // 跳过CREATE DATABASE语句
            if (preg_match('/^CREATE DATABASE/', $line)) {
                $skipNext = true;
                continue;
            }
            
            // 跳过USE语句
            if (preg_match('/^USE `/', $line)) {
                continue;
            }
            
            // 如果之前遇到了CREATE DATABASE，跳过分号行
            if ($skipNext && $line === ';') {
                $skipNext = false;
                continue;
            }
            
            $filteredLines[] = $line;
        }
        
        // 重新组合SQL
        $filteredSql = implode("\n", $filteredLines);
        
        // 分割SQL语句
        $statements = array_filter(array_map('trim', explode(';', $filteredSql)));
        
        $executedCount = 0;
        $errorCount = 0;
        
        foreach ($statements as $statement) {
            if (!empty($statement) && !preg_match('/^--/', $statement)) {
                try {
                    $pdo->exec($statement . ';');
                    $executedCount++;
                } catch (PDOException $e) {
                    $errorCount++;
                    $errorMsg = $e->getMessage();
                    // 忽略已存在的表错误
                    if (strpos($errorMsg, 'already exists') === false && 
                        strpos($errorMsg, 'exists') === false) {
                        throw new Exception('执行SQL失败: ' . substr($statement, 0, 100) . '... 错误: ' . $errorMsg);
                    }
                }
            }
        }
        
        // 检查表是否存在
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('admins', $tables)) {
            throw new Exception("数据库表创建失败。执行了 {$executedCount} 条SQL语句，遇到 {$errorCount} 个错误。当前数据库中的表: " . implode(', ', $tables));
        }
        
        // 创建管理员账号
        $adminPassword = password_hash($config['admin_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT IGNORE INTO admins (username, password, role, status, created_at, updated_at) VALUES (?, ?, 9, 1, NOW(), NOW())");
        $stmt->execute([$config['admin_username'], $adminPassword]);
        
        // 检查是否插入成功，如果失败则更新现有管理员密码
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("UPDATE admins SET password = ?, updated_at = NOW() WHERE username = ?");
            $stmt->execute([$adminPassword, $config['admin_username']]);
        }
        
        // 创建安装锁文件
        file_put_contents(__DIR__ . '/install.lock', date('Y-m-d H:i:s'));
        
        // 更新config.php文件
        updateConfigFile($config);
        
        return true;
    } catch (Exception $e) {
        throw new Exception('安装失败: ' . $e->getMessage());
    }
}

// 更新config.php文件
function updateConfigFile($config) {
    $configFile = __DIR__ . '/config.php';
    
    // 检查文件是否存在
    if (!file_exists($configFile)) {
        // 如果config.php不存在，尝试从config.example.php复制
        $exampleFile = __DIR__ . '/config.example.php';
        if (file_exists($exampleFile)) {
            if (!copy($exampleFile, $configFile)) {
                throw new Exception('无法创建config.php文件，请手动复制config.example.php为config.php并设置权限');
            }
        } else {
            throw new Exception('config.php文件不存在，且config.example.php也不存在。请手动创建config.php文件');
        }
    }
    
    // 检查文件是否可写
    if (!is_writable($configFile)) {
        throw new Exception('config.php文件不可写，请检查文件权限。在Linux上请执行：chmod 666 config.php');
    }
    
    // 读取现有配置文件
    $content = file_get_contents($configFile);
    if ($content === false) {
        throw new Exception('无法读取config.php文件');
    }
    
    // 备份原文件
    $backupFile = $configFile . '.backup.' . date('YmdHis');
    file_put_contents($backupFile, $content);
    
    // 替换数据库配置 - 使用更精确的正则表达式
    $patterns = [
        "/'host'\s*=>\s*'.*?'/",
        "/'port'\s*=>\s*\d+/",
        "/'database'\s*=>\s*'.*?'/", 
        "/'username'\s*=>\s*'.*?'/",
        "/'password'\s*=>\s*'.*?'/",
        "/'charset'\s*=>\s*'.*?'/"
    ];
    
    $replacements = [
        "'host' => '{$config['db_host']}'",
        "'port' => {$config['db_port']}",
        "'database' => '{$config['db_name']}'",
        "'username' => '{$config['db_user']}'",
        "'password' => '{$config['db_password']}'",
        "'charset' => 'utf8mb4'"
    ];
    
    $newContent = preg_replace($patterns, $replacements, $content);
    
    // 检查是否有内容变化
    if ($newContent === $content) {
        // 可能是正则匹配失败，尝试更简单的方法
        $newContent = preg_replace(
            "/('db'\s*=>\s*\[[\s\S]*?'host'\s*=>\s*)'.*?'([\s\S]*?'port'\s*=>\s*)\d+([\s\S]*?'database'\s*=>\s*)'.*?'([\s\S]*?'username'\s*=>\s*)'.*?'([\s\S]*?'password'\s*=>\s*)'.*?'/",
            "\${1}'{$config['db_host']}'\${2}{$config['db_port']}\${3}'{$config['db_name']}'\${4}'{$config['db_user']}'\${5}'{$config['db_password']}'",
            $content
        );
    }
    
    // 写回文件
    if (file_put_contents($configFile, $newContent) === false) {
        throw new Exception('无法写入config.php文件，请检查文件权限');
    }
    
    // 验证写入结果
    $verifyContent = file_get_contents($configFile);
    if (strpos($verifyContent, $config['db_host']) === false) {
        throw new Exception('config.php文件写入验证失败，请检查文件内容');
    }
}

// HTML模板
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>音游窝计费管理系统 - 安装向导</title>
    <style>
        :root {
            --primary-color: #18a058;
            --primary-color-hover: #36ad6a;
            --primary-color-pressed: #0c7a43;
            --primary-color-focus: #36ad6a;
            --success-color: #28a745;
            --error-color: #d03050;
            --warning-color: #f0a020;
            --info-color: #2080f0;
            --text-color: #333;
            --text-color-2: #666;
            --text-color-3: #999;
            --border-color: #e0e0e6;
            --border-color-2: #d0d0d6;
            --background-color: #fff;
            --background-color-2: #fafafa;
            --background-color-3: #f5f5f5;
            --shadow-color: rgba(0, 0, 0, 0.1);
            --radius: 6px;
            --radius-small: 4px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--background-color-2);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-color);
        }
        
        .container {
            background: var(--background-color);
            border-radius: var(--radius);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            max-width: 600px;
            width: 100%;
            padding: 32px;
        }
        
        h1 {
            color: var(--text-color);
            margin-bottom: 8px;
            font-size: 24px;
            font-weight: 600;
        }
        
        .subtitle {
            color: var(--text-color-3);
            margin-bottom: 24px;
            font-size: 14px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 32px;
            position: relative;
        }
        
        .step {
            flex: 1;
            text-align: center;
            padding: 8px 0;
            color: var(--text-color-3);
            font-size: 12px;
            position: relative;
            z-index: 2;
        }
        
        .step.active {
            color: var(--primary-color);
            font-weight: 500;
        }
        
        .step.completed {
            color: var(--success-color);
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--border-color);
            z-index: 1;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-color);
            font-size: 14px;
            font-weight: 500;
        }
        
        input[type="text"],
        input[type="password"],
        input[type="email"],
        input[type="number"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 14px;
            transition: all 0.3s;
            background: var(--background-color);
        }
        
        input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(24, 160, 88, 0.2);
        }
        
        button {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        button:hover {
            background: var(--primary-color-hover);
        }
        
        button:active {
            background: var(--primary-color-pressed);
        }
        
        button:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(24, 160, 88, 0.2);
        }
        
        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        button:disabled:hover {
            background: var(--primary-color);
        }
        
        .btn-secondary {
            background: var(--background-color-3);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background: var(--background-color-2);
        }
        
        .btn-secondary:focus {
            box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.1);
        }
        
        .check-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            background: var(--background-color);
        }
        
        .check-item:last-child {
            border-bottom: none;
        }
        
        .check-passed {
            color: var(--success-color);
            font-weight: 500;
        }
        
        .check-failed {
            color: var(--error-color);
            font-weight: 500;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-error {
            background: #fef0f0;
            color: var(--error-color);
            border: 1px solid #f5c2c7;
        }
        
        .alert-success {
            background: #f0f9f0;
            color: var(--success-color);
            border: 1px solid #c3e6c3;
        }
        
        .progress-bar {
            width: 100%;
            height: 4px;
            background: var(--border-color);
            border-radius: 2px;
            overflow: hidden;
            margin: 20px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--primary-color);
            transition: width 0.3s;
        }
        
        .log {
            background: var(--background-color-3);
            padding: 16px;
            border-radius: var(--radius);
            max-height: 200px;
            overflow-y: auto;
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace;
            font-size: 12px;
            color: var(--text-color-2);
            border: 1px solid var(--border-color);
        }
        
        .log div {
            margin-bottom: 4px;
        }
        
        .log div:last-child {
            margin-bottom: 0;
        }
        
        .button-group {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        .section-title {
            color: var(--text-color);
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
        }
        
        ul {
            color: var(--text-color-2);
            margin-left: 20px;
            margin-top: 8px;
        }
        
        li {
            margin-bottom: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($step === STEP_WELCOME): ?>
            <h1>🎵 音游窝计费管理系统</h1>
            <p class="subtitle">欢迎使用安装向导</p>
            
            <div style="margin-bottom: 30px;">
                <p style="color: #666; line-height: 1.6;">
                    本安装程序将引导您完成系统的安装配置。<br>
                    安装前请确保您的服务器满足以下要求：
                </p>
                <ul style="color: #666; margin-left: 20px; margin-top: 10px;">
                    <li>PHP 7.4 或更高版本</li>
                    <li>MySQL 5.7 或更高版本</li>
                    <li>必需的PHP扩展：pdo, pdo_mysql, json, mbstring</li>
                    <li>config、uploads、logs 目录可写</li>
                </ul>
            </div>
            
            <div class="button-group">
                <button onclick="location.href='install.php?step=<?php echo STEP_ENV_CHECK; ?>'">开始安装</button>
            </div>
            
        <?php elseif ($step === STEP_ENV_CHECK): ?>
            <h1>环境检查</h1>
            <p class="subtitle">检查您的服务器环境是否满足安装要求</p>
            
            <?php
            $checks = checkEnvironment();
            $allPassed = true;
            foreach ($checks as $check) {
                if (!$check['passed']) {
                    $allPassed = false;
                    break;
                }
            }
            ?>
            
            <div style="margin-bottom: 20px;">
                <?php foreach ($checks as $check): ?>
                    <div class="check-item">
                        <span><?php echo htmlspecialchars($check['name']); ?></span>
                        <span class="<?php echo $check['passed'] ? 'check-passed' : 'check-failed'; ?>">
                            <?php echo htmlspecialchars($check['current']); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (!$allPassed): ?>
                <div class="alert alert-error">
                    ⚠️ 您的服务器环境未满足安装要求，请修复后重试。
                </div>
            <?php endif; ?>
            
            <div class="button-group">
                <button class="btn-secondary" onclick="location.href='install.php?step=<?php echo STEP_WELCOME; ?>'">上一步</button>
                <button <?php echo $allPassed ? '' : 'disabled'; ?> onclick="location.href='install.php?step=<?php echo STEP_DB_CONFIG; ?>'">
                    <?php echo $allPassed ? '下一步' : '环境检查未通过'; ?>
                </button>
            </div>
            
        <?php elseif ($step === STEP_DB_CONFIG): ?>
            <h1>数据库配置</h1>
            <p class="subtitle">请填写数据库连接信息</p>
            
            <form method="POST">
                <div class="form-group">
                    <label>数据库主机</label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                
                <div class="form-group">
                    <label>数据库端口</label>
                    <input type="number" name="db_port" value="3306" required>
                </div>
                
                <div class="form-group">
                    <label>数据库名称</label>
                    <input type="text" name="db_name" value="rhythm_system" required>
                </div>
                
                <div class="form-group">
                    <label>数据库用户名</label>
                    <input type="text" name="db_user" required>
                </div>
                
                <div class="form-group">
                    <label>数据库密码</label>
                    <input type="password" name="db_pass">
                </div>
                
                <div style="margin: 32px 0; padding-top: 24px; border-top: 1px solid var(--border-color);">
                    <div class="section-title">管理员账号</div>
                    
                    <div class="form-group">
                        <label>管理员用户名</label>
                        <input type="text" name="admin_username" value="admin" required>
                    </div>
                    
                    <div class="form-group">
                        <label>管理员密码</label>
                        <input type="password" name="admin_password" required minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label>管理员邮箱（可选）</label>
                        <input type="email" name="admin_email">
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="button" class="btn-secondary" onclick="location.href='install.php?step=<?php echo STEP_ENV_CHECK; ?>'">上一步</button>
                    <button type="submit">开始安装</button>
                </div>
            </form>
            
        <?php elseif ($step === STEP_INSTALL): ?>
            <h1>正在安装</h1>
            <p class="subtitle">正在安装系统，请稍候...</p>
            
            <?php
            $config = $_SESSION['db_config'];
            $errors = [];
            $logs = [];
            
            try {
                $logs[] = '正在测试数据库连接...';
                testDatabaseConnection($config);
                $logs[] = '✓ 数据库连接成功';
                
                $logs[] = '正在创建数据库表...';
                performInstall($config);
                $logs[] = '✓ 数据库表创建成功';
                
                $logs[] = '正在创建管理员账号...';
                $logs[] = '✓ 管理员账号创建成功';
                
                $logs[] = '✓ 安装完成！';
                
                // 延迟2秒后跳转
                echo '<script>setTimeout(function() { window.location.href = "install.php?step=' . STEP_COMPLETE . '"; }, 2000);</script>';
                
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
            ?>
            
            <div class="log">
                <?php foreach ($logs as $log): ?>
                    <div><?php echo htmlspecialchars($log); ?></div>
                <?php endforeach; ?>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
                
                <div class="button-group">
                    <button class="btn-secondary" onclick="location.href='install.php?step=<?php echo STEP_DB_CONFIG; ?>'">返回</button>
                </div>
            <?php endif; ?>
            
        <?php elseif ($step === STEP_COMPLETE): ?>
            <h1>🎉 安装完成</h1>
            <p class="subtitle">系统已成功安装</p>
            
            <div class="alert alert-success">
                <strong>安装成功！</strong><br>
                您现在可以登录系统了。
            </div>
            
            <div style="margin: 24px 0;">
                <div class="section-title">安全提示</div>
                <ul>
                    <li>请立即删除 install.php 文件</li>
                    <li>修改默认管理员密码</li>
                    <li>定期备份数据库</li>
                </ul>
            </div>
            
            <div class="button-group">
                <button onclick="location.href='admin.html'">进入管理后台</button>
                <button class="btn-secondary" onclick="location.href='index.html'">进入首页</button>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
