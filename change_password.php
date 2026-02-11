<?php
/**
 * 临时脚本：更新管理员密码
 * 
 * 使用方法：
 * 1. 在浏览器访问：http://你的域名/change_password.php?username=admin&new_password=新密码
 * 2. 或者直接修改下面的 $username 和 $newPassword 变量后访问
 * 
 * 注意：使用完毕后请删除此文件，避免安全隐患！
 */

require_once __DIR__ . '/db.php';

// 方式1：通过 URL 参数传入（不安全，仅用于临时）
$username = $_GET['username'] ?? 'admin';
$newPassword = $_GET['new_password'] ?? '';

// 方式2：直接在这里修改（更安全）
// $username = 'admin';
// $newPassword = 'your_new_password_here';

if (empty($newPassword)) {
    die('请提供新密码！使用方法：?username=admin&new_password=你的新密码');
}

// 生成密码哈希
$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

try {
    // 更新数据库
    $sql = 'UPDATE admins SET password = :password, updated_at = NOW() WHERE username = :username';
    $affected = db_execute($sql, [
        ':password' => $passwordHash,
        ':username' => $username,
    ]);

    if ($affected > 0) {
        echo "✅ 密码更新成功！<br>";
        echo "用户名：{$username}<br>";
        echo "新密码哈希：{$passwordHash}<br>";
        echo "<br><strong style='color:red;'>请立即删除此文件（update_admin_password.php）！</strong>";
    } else {
        echo "❌ 未找到用户名为 '{$username}' 的管理员账号";
    }
} catch (Exception $e) {
    echo "❌ 更新失败：" . $e->getMessage();
}
