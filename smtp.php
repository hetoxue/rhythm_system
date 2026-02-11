<?php

/**
 * SMTP邮件发送功能
 */

require_once __DIR__ . '/functions.php';

/**
 * 发送邮件验证码
 * @param string $email 收件人邮箱
 * @param string $code 验证码
 * @param string $type 验证类型（register/reset）
 * @return bool
 */
function send_email_verification(string $email, string $code, string $type = 'register'): bool
{
    $systemName = get_config_value('system_name', '场地计费系统');
    $subject = $type === 'register' ? $systemName . ' - 注册验证码' : $systemName . ' - 密码重置验证码';
    
    $body = "
    <html>
    <head>
        <meta charset='UTF-8'>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #333;'>{$systemName}</h2>
            <p style='color: #666;'>
                " . ($type === 'register' ? '您正在注册账号' : '您正在重置密码') . "，您的验证码是：
            </p>
            <div style='background: #f5f5f5; padding: 20px; text-align: center; margin: 20px 0;'>
                <span style='font-size: 32px; font-weight: bold; color: #007bff;'>{$code}</span>
            </div>
            <p style='color: #999; font-size: 14px;'>
                验证码有效期为5分钟，请勿泄露给他人。
            </p>
            <p style='color: #999; font-size: 14px;'>
                如果这不是您本人的操作，请忽略此邮件。
            </p>
        </div>
    </body>
    </html>
    ";
    
    return send_smtp_email($email, $subject, $body, $type);
}

/**
 * 发送SMTP邮件
 * @param string $to 收件人邮箱
 * @param string $subject 邮件主题
 * @param string $body 邮件内容（HTML格式）
 * @param string $type 邮件类型（用于日志记录）
 * @return bool
 */
function send_smtp_email(string $to, string $subject, string $body, string $type = 'general'): bool
{
    $status = 0;
    $errorMessage = null;
    
    try {
        // 检查PHPMailer是否存在，支持多种安装方式
        $phpmailerPaths = [
            __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',  // Composer安装
            __DIR__ . '/PHPMailer/src/PHPMailer.php',                    // 手动安装
            dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',  // 父目录的vendor
        ];
        
        // 检查PHP的include_path
        $includePaths = explode(PATH_SEPARATOR, get_include_path());
        foreach ($includePaths as $includePath) {
            $phpmailerPaths[] = $includePath . '/phpmailer/phpmailer/src/PHPMailer.php';
            $phpmailerPaths[] = $includePath . '/PHPMailer/src/PHPMailer.php';
        }
        
        $phpmailerPath = null;
        foreach ($phpmailerPaths as $path) {
            if (file_exists($path)) {
                $phpmailerPath = $path;
                error_log('找到PHPMailer: ' . $path);
                break;
            }
        }
        
        if ($phpmailerPath === null) {
            $errorMessage = 'PHPMailer未安装。已检查的路径: ' . implode(', ', $phpmailerPaths);
            throw new Exception($errorMessage);
        }
        
        // 获取SMTP配置
        $smtpHost = get_config_value('smtp_host', 'smtp.qq.com');
        $smtpPort = (int)get_config_value('smtp_port', 587);
        $smtpUser = get_config_value('smtp_user', '');
        $smtpPass = get_config_value('smtp_pass', '');
        $smtpFrom = get_config_value('smtp_from', $smtpUser);
        $systemName = get_config_value('system_name', '场地计费系统');
        $smtpFromName = get_config_value('smtp_from_name', $systemName);
        
        if (empty($smtpUser) || empty($smtpPass)) {
            $errorMessage = 'SMTP配置不完整';
            throw new Exception($errorMessage);
        }
        
        // 使用PHPMailer发送邮件
        $phpmailerDir = dirname($phpmailerPath);
        require_once $phpmailerPath;
        require_once $phpmailerDir . '/SMTP.php';
        require_once $phpmailerDir . '/Exception.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // 服务器设置
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        
        // 收件人
        $mail->setFrom($smtpFrom, $smtpFromName);
        $mail->addAddress($to);
        
        // 内容
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->CharSet = 'UTF-8';
        
        $mail->send();
        $status = 1;
        
    } catch (Exception $e) {
        $status = 0;
        $errorMessage = $e->getMessage();
        error_log('邮件发送失败: ' . $errorMessage);
    }
    
    // 记录邮件发送日志
    try {
        db_execute(
            'INSERT INTO email_logs (to_email, subject, status, error_message, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$to, $subject, $status, $errorMessage]
        );
    } catch (Exception $e) {
        error_log('记录邮件日志失败: ' . $e->getMessage());
    }
    
    return $status === 1;
}

/**
 * 生成验证码
 * @param int $length 验证码长度
 * @return string
 */
function generate_verification_code(int $length = 6): string
{
    return str_pad((string)random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

/**
 * 保存验证码到数据库
 * @param string $email 邮箱地址
 * @param string $code 验证码
 * @param string $type 验证类型
 * @return bool
 */
function save_verification_code(string $email, string $code, string $type = 'register'): bool
{
    $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5分钟有效期
    
    // 删除该邮箱旧的验证码
    db_execute('DELETE FROM verification_codes WHERE email = ? AND type = ?', [$email, $type]);
    
    // 插入新验证码
    $sql = 'INSERT INTO verification_codes (email, code, type, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())';
    db_execute($sql, [$email, $code, $type, $expiresAt]);
    
    return true;
}

/**
 * 验证验证码
 * @param string $email 邮箱地址
 * @param string $code 验证码
 * @param string $type 验证类型
 * @return bool
 */
function verify_code(string $email, string $code, string $type = 'register'): bool
{
    $row = db_fetch_one(
        'SELECT * FROM verification_codes WHERE email = ? AND code = ? AND type = ? AND expires_at > NOW() AND used = 0 ORDER BY id DESC LIMIT 1',
        [$email, $code, $type]
    );
    
    if (!$row) {
        return false;
    }
    
    // 标记为已使用
    db_execute('UPDATE verification_codes SET used = 1 WHERE id = ?', [$row['id']]);
    
    return true;
}

/**
 * 发送包场邀请邮件
 * @param string $email 收件人邮箱
 * @param string $inviterName 邀请人名称
 * @param string $date 包场日期
 * @param string $startTime 开始时间
 * @param string $endTime 结束时间
 * @return bool
 */
function send_booking_invitation_email(string $email, string $inviterName, string $date, string $startTime, string $endTime): bool
{
    $systemName = get_config_value('system_name', '场地计费系统');
    $subject = $systemName . ' - 包场邀请通知';
    $graceMinutes = (int)get_config_value('booking_grace_minutes', 5, true);
    
    $body = "
    <html>
    <head>
        <meta charset='UTF-8'>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #333;'>🎉 包场邀请通知</h2>
            <p style='color: #666;'>
                您好！<strong>{$inviterName}</strong> 邀请您参加包场活动。
            </p>
            <div style='background: #f5f5f5; padding: 20px; margin: 20px 0; border-left: 4px solid #007bff;'>
                <p><strong>包场日期：</strong>{$date}</p>
                <p><strong>时间段：</strong>{$startTime} - {$endTime}</p>
            </div>
            <p style='color: #666;'>
                在包场时间段内，您可以自由进出场地，无需额外付费。
                包场结束后有{$graceMinutes}分钟缓冲时间，请在此时间内完成出场，否则将开始计时收费。
            </p>
            <p style='color: #999; font-size: 14px;'>
                请登录网站查看详细信息。
            </p>
        </div>
    </body>
    </html>
    ";
    
    return send_smtp_email($email, $subject, $body, 'booking_invitation');
}

/**
 * 发送包场通过通知邮件
 * @param string $email 收件人邮箱
 * @param string $date 包场日期
 * @param string $startTime 开始时间
 * @param string $endTime 结束时间
 * @return bool
 */
function send_booking_approved_email(string $email, string $date, string $startTime, string $endTime): bool
{
    $systemName = get_config_value('system_name', '场地计费系统');
    $subject = $systemName . ' - 包场申请已通过';
    $graceMinutes = (int)get_config_value('booking_grace_minutes', 5, true);
    
    $body = "
    <html>
    <head>
        <meta charset='UTF-8'>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #28a745;'>✅ 包场申请已通过</h2>
            <p style='color: #666;'>
                您的包场申请已通过管理员审核！
            </p>
            <div style='background: #f5f5f5; padding: 20px; margin: 20px 0; border-left: 4px solid #28a745;'>
                <p><strong>包场日期：</strong>{$date}</p>
                <p><strong>时间段：</strong>{$startTime} - {$endTime}</p>
            </div>
            <p style='color: #666;'>
                被邀请人已收到邮件通知，请按时到场。
                包场结束后有{$graceMinutes}分钟缓冲时间，请在此时间内完成出场，否则将开始计时收费。
            </p>
        </div>
    </body>
    </html>
    ";;
    
    return send_smtp_email($email, $subject, $body, 'booking_approved');
}

/**
 * 发送包场取消通知邮件
 * @param string $email 收件人邮箱
 * @param string $date 包场日期
 * @param string $startTime 开始时间
 * @param string $endTime 结束时间
 * @param string $reason 取消原因
 * @return bool
 */
function send_booking_cancelled_email(string $email, string $date, string $startTime, string $endTime, string $reason = ''): bool
{
    $systemName = get_config_value('system_name', '场地计费系统');
    $subject = $systemName . ' - 包场已取消';
    
    $reasonText = $reason ? "<p><strong>取消原因：</strong>{$reason}</p>" : '';
    
    $body = "
    <html>
    <head>
        <meta charset='UTF-8'>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #dc3545;'>❌ 包场已取消</h2>
            <p style='color: #666;'>
                很抱歉，以下包场已被管理员取消，费用已原路退回。
            </p>
            <div style='background: #f5f5f5; padding: 20px; margin: 20px 0; border-left: 4px solid #dc3545;'>
                <p><strong>包场日期：</strong>{$date}</p>
                <p><strong>时间段：</strong>{$startTime} - {$endTime}</p>
            </div>
            {$reasonText}
            <p style='color: #999; font-size: 14px;'>
                如有疑问，请联系管理员。
            </p>
        </div>
    </body>
    </html>
    ";
    
    return send_smtp_email($email, $subject, $body, 'booking_cancelled');
}
