<?php
/**
 * Email Dispatcher & Hostinger Anti-Blocking Mail Engine
 * 
 * Supports:
 * 1. Direct Socket SMTP with SSL/TLS (Optimized for Hostinger smtp.hostinger.com, Gmail, or custom SMTP)
 * 2. RFC 5322-compliant PHP mail() fallback configured with envelope sender (-f flag) to bypass Hostinger mail filters
 */

/**
 * Sends a HTML email to the specified recipient.
 * Specifically crafted to avoid Hostinger and cPanel spam/relay filters.
 */
function send_maintenance_email(
    string $to,
    string $subject,
    string $htmlBody,
    array $config = []
): array {
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => "Invalid recipient email: '{$to}'"];
    }

    $fromEmail = !empty($config['from_email']) ? trim($config['from_email']) : '';
    $fromName = !empty($config['from_name']) ? trim($config['from_name']) : 'School ERP System';
    $replyTo = !empty($config['reply_to']) ? trim($config['reply_to']) : 'razaainaan@gmail.com';

    // Check if SMTP is configured and enabled
    $smtpEnabled = !empty($config['smtp_enabled']);
    $smtpHost = !empty($config['smtp_host']) ? trim($config['smtp_host']) : '';
    $smtpUser = !empty($config['smtp_username']) ? trim($config['smtp_username']) : '';
    $smtpPass = !empty($config['smtp_password']) ? (string)$config['smtp_password'] : '';
    $smtpPort = !empty($config['smtp_port']) ? (int)$config['smtp_port'] : 465;
    $smtpEnc = !empty($config['smtp_encryption']) ? strtolower(trim($config['smtp_encryption'])) : 'ssl';

    // When SMTP is active on Hostinger, From address must match the authenticated mailbox
    if ($smtpEnabled && !empty($smtpUser)) {
        if (empty($fromEmail) || $fromEmail === 'noreply@school-erp.com') {
            $fromEmail = $smtpUser;
        }
    }

    // Hostinger anti-blocking rule: From email domain must resolve or match server host if not specified
    if (empty($fromEmail)) {
        $serverHost = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        $serverHost = preg_replace('/^www\./i', '', $serverHost);
        if ($serverHost === 'localhost' || filter_var($serverHost, FILTER_VALIDATE_IP)) {
            $fromEmail = 'noreply@school-erp.com';
        } else {
            $fromEmail = 'noreply@' . $serverHost;
        }
    }

    if ($smtpEnabled && !empty($smtpHost) && !empty($smtpUser) && !empty($smtpPass)) {
        $smtpResult = send_email_via_smtp_socket(
            $smtpHost,
            $smtpPort,
            $smtpEnc,
            $smtpUser,
            $smtpPass,
            $fromEmail,
            $fromName,
            $to,
            $replyTo,
            $subject,
            $htmlBody
        );

        if ($smtpResult['success']) {
            return $smtpResult;
        }

        // If SMTP failed, log and attempt PHP mail fallback with Hostinger envelope sender
        $fallbackResult = send_email_via_hostinger_mail(
            $to,
            $subject,
            $htmlBody,
            $fromEmail,
            $fromName,
            $replyTo
        );

        if ($fallbackResult['success']) {
            return [
                'success' => true,
                'message' => 'Email sent via PHP mail fallback (SMTP failed: ' . $smtpResult['message'] . ')',
            ];
        }

        return [
            'success' => false,
            'message' => 'SMTP failed: ' . $smtpResult['message'] . ' | Fallback failed: ' . $fallbackResult['message'],
        ];
    }

    // Default: Send via Hostinger-hardened PHP mail()
    return send_email_via_hostinger_mail($to, $subject, $htmlBody, $fromEmail, $fromName, $replyTo);
}

/**
 * Pure PHP SMTP Socket Client with SSL/TLS and AUTH LOGIN.
 * Zero external libraries required.
 */
function send_email_via_smtp_socket(
    string $host,
    int $port,
    string $encryption,
    string $username,
    string $password,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $replyTo,
    string $subject,
    string $htmlBody
): array {
    $timeout = 15;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);

    $protocolPrefix = ($encryption === 'ssl' || $port === 465) ? 'ssl://' : '';
    $socket = @stream_socket_client(
        $protocolPrefix . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return ['success' => false, 'message' => "Socket connection to {$host}:{$port} failed: {$errstr} ({$errno})"];
    }

    stream_set_timeout($socket, $timeout);

    $readResponse = function() use ($socket): string {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) break;
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };

    $sendCommand = function(string $cmd) use ($socket, $readResponse): string {
        fputs($socket, $cmd . "\r\n");
        return $readResponse();
    };

    // Read greeting
    $greeting = $readResponse();
    if (!str_starts_with($greeting, '220')) {
        fclose($socket);
        return ['success' => false, 'message' => 'Unexpected greeting from SMTP server: ' . trim($greeting)];
    }

    // EHLO - Use domain from username if localhost
    $clientHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    if ($clientHost === 'localhost' || filter_var($clientHost, FILTER_VALIDATE_IP)) {
        if (str_contains($username, '@')) {
            $clientHost = substr(strrchr($username, '@'), 1);
        }
    }
    $ehlo = $sendCommand('EHLO ' . $clientHost);

    // STARTTLS if configured
    if ($encryption === 'tls' || ($port === 587 && !str_starts_with($protocolPrefix, 'ssl://'))) {
        $tlsResp = $sendCommand('STARTTLS');
        if (str_starts_with($tlsResp, '220')) {
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) {
                fclose($socket);
                return ['success' => false, 'message' => 'Failed to negotiate STARTTLS encryption.'];
            }
            $sendCommand('EHLO ' . $clientHost);
        }
    }

    // AUTH LOGIN
    $authResp = $sendCommand('AUTH LOGIN');
    if (!str_starts_with($authResp, '334')) {
        fclose($socket);
        return ['success' => false, 'message' => 'AUTH LOGIN failed: ' . trim($authResp)];
    }

    $userResp = $sendCommand(base64_encode($username));
    if (!str_starts_with($userResp, '334')) {
        fclose($socket);
        return ['success' => false, 'message' => 'Username rejected: ' . trim($userResp)];
    }

    $passResp = $sendCommand(base64_encode($password));
    if (!str_starts_with($passResp, '235')) {
        fclose($socket);
        return ['success' => false, 'message' => 'Password authentication rejected: ' . trim($passResp)];
    }

    // MAIL FROM
    $mailFromResp = $sendCommand('MAIL FROM:<' . $fromEmail . '>');
    if (!str_starts_with($mailFromResp, '250')) {
        fclose($socket);
        return ['success' => false, 'message' => 'MAIL FROM rejected: ' . trim($mailFromResp)];
    }

    // RCPT TO
    $rcptResp = $sendCommand('RCPT TO:<' . $toEmail . '>');
    if (!str_starts_with($rcptResp, '250') && !str_starts_with($rcptResp, '251')) {
        fclose($socket);
        return ['success' => false, 'message' => 'RCPT TO rejected for ' . $toEmail . ': ' . trim($rcptResp)];
    }

    // DATA
    $dataResp = $sendCommand('DATA');
    if (!str_starts_with($dataResp, '354')) {
        fclose($socket);
        return ['success' => false, 'message' => 'DATA command rejected: ' . trim($dataResp)];
    }

    // Assemble MIME Message
    $messageId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@' . $clientHost . '>';
    $date = date('r');
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

    $headers = [
        "Date: {$date}",
        "From: {$encodedFromName} <{$fromEmail}>",
        "To: <{$toEmail}>",
        "Reply-To: <{$replyTo}>",
        "Subject: {$encodedSubject}",
        "Message-ID: {$messageId}",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: base64",
        "X-Mailer: School-ERP-Hostinger-Mailer/1.0",
    ];

    $rawBody = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody)) . "\r\n.";
    $sendResp = $sendCommand($rawBody);

    $sendCommand('QUIT');
    fclose($socket);

    if (str_starts_with($sendResp, '250')) {
        return ['success' => true, 'message' => 'Email successfully sent via SMTP.'];
    }

    return ['success' => false, 'message' => 'Error finalizing message: ' . trim($sendResp)];
}

/**
 * Hostinger-hardened PHP mail() dispatcher.
 * Uses envelope sender (-f parameter) and standard RFC headers.
 */
function send_email_via_hostinger_mail(
    string $to,
    string $subject,
    string $htmlBody,
    string $fromEmail,
    string $fromName,
    string $replyTo
): array {
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $replyTo,
        'Return-Path: <' . $fromEmail . '>',
        'X-Mailer: PHP/' . phpversion(),
        'X-Priority: 1 (Highest)',
    ];

    $headersStr = implode("\r\n", $headers);
    // Crucial for Hostinger: -f flag sets envelope return path to prevent silent drops
    $extraParam = '-f' . escapeshellarg($fromEmail);

    try {
        $sent = @mail($to, $encodedSubject, $htmlBody, $headersStr, $extraParam);
        if ($sent) {
            return ['success' => true, 'message' => 'Email sent successfully via Hostinger mail().'];
        }

        // Retry without extra parameter if server environment restricts it
        $retrySent = @mail($to, $encodedSubject, $htmlBody, $headersStr);
        if ($retrySent) {
            return ['success' => true, 'message' => 'Email sent via mail() (without -f param).'];
        }

        $lastErr = error_get_last();
        return [
            'success' => false, 
            'message' => 'PHP mail() function returned false. ' . ($lastErr['message'] ?? 'Check hostinger mail service configuration.'),
        ];
    } catch (Throwable $t) {
        return ['success' => false, 'message' => 'PHP mail() exception: ' . $t->getMessage()];
    }
}

/**
 * Builds the responsive HTML template for Developer Maintenance Notices.
 */
function build_maintenance_email_template(array $data): string
{
    $daysLeft = $data['days_left'] ?? 0;
    $dueDate = $data['due_date_formatted'] ?? '';
    $feeFormatted = $data['formatted_fee'] ?? '₹ 15,000.00';
    $schoolName = $data['school_name'] ?? 'School ERP System';
    $appUrl = $data['app_url'] ?? 'http://localhost/school-erp';
    $devUrl = rtrim($appUrl, '/') . '/developer/maintenance.php';
    $devLoginUrl = rtrim($appUrl, '/') . '/developer/login.php';
    $phone = $data['phone'] ?? '+91 84299 39031';
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

    require_once __DIR__ . '/../config/developer_portal.php';
    $devCreds = function_exists('developer_portal_credentials') ? developer_portal_credentials() : ['username' => 'devmaster', 'password' => 'DevPortal@123'];
    $devUser = htmlspecialchars($devCreds['username'] ?? 'devmaster');
    $devPass = htmlspecialchars($devCreds['password'] ?? 'DevPortal@123');

    $badgeColor = ($daysLeft <= 1) ? '#dc2626' : (($daysLeft <= 7) ? '#ea580c' : '#d97706');
    $dayText = ($daysLeft === 1) ? '1 DAY' : ($daysLeft . ' DAYS');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yearly ERP Maintenance Notice</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0b111e; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #f8fafc;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #0b111e; padding: 30px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #141d2e; border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.6);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 28px 24px; border-bottom: 2px solid {$badgeColor}; text-align: center;">
                            <div style="display: inline-block; background-color: {$badgeColor}; color: #ffffff; font-size: 11px; font-weight: 800; padding: 5px 12px; border-radius: 999px; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 12px;">
                                AMC ALERT: {$dayText} REMAINING
                            </div>
                            <h1 style="color: #ffffff; font-size: 22px; margin: 0; font-weight: 800; letter-spacing: -0.02em;">
                                Annual ERP Maintenance Notice
                            </h1>
                            <p style="color: #94a3b8; font-size: 13px; margin: 6px 0 0 0;">
                                Instance: <strong>{$schoolName}</strong>
                            </p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 28px 24px;">
                            <p style="font-size: 15px; line-height: 1.5; color: #e2e8f0; margin-top: 0;">
                                Hello Developer,
                            </p>
                            <p style="font-size: 14px; line-height: 1.6; color: #cbd5e1;">
                                This is an automated reminder that the Annual Maintenance Contract (AMC) for <strong>{$schoolName}</strong> is approaching its expiration date.
                            </p>

                            <!-- Metrics Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #0c1320; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 10px; margin: 20px 0; overflow: hidden;">
                                <tr>
                                    <td style="padding: 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.06);">
                                        <span style="font-size: 12px; text-transform: uppercase; color: #94a3b8; display: block; font-weight: 600;">Due Date</span>
                                        <strong style="font-size: 16px; color: #ffffff;">{$dueDate}</strong>
                                    </td>
                                    <td style="padding: 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.06);">
                                        <span style="font-size: 12px; text-transform: uppercase; color: #94a3b8; display: block; font-weight: 600;">Time Remaining</span>
                                        <strong style="font-size: 16px; color: {$badgeColor};">{$dayText}</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 16px;">
                                        <span style="font-size: 12px; text-transform: uppercase; color: #94a3b8; display: block; font-weight: 600;">Annual Fee</span>
                                        <strong style="font-size: 16px; color: #f59e0b;">{$feeFormatted}</strong>
                                    </td>
                                    <td style="padding: 16px;">
                                        <span style="font-size: 12px; text-transform: uppercase; color: #94a3b8; display: block; font-weight: 600;">Action on Expiry</span>
                                        <strong style="font-size: 15px; color: #ef4444;">Automatic Lockout</strong>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 13.5px; line-height: 1.5; color: #94a3b8;">
                                When the due date arrives, the ERP system will automatically suspend access across all portals (Super Admin, Admin, Teacher, Student, Parent) and render the lock screen with the UPI QR code.
                            </p>

                            <!-- Developer Credentials & Direct Login Card -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #0b1220; border: 1px solid rgba(194, 149, 79, 0.45); border-radius: 10px; margin: 22px 0; overflow: hidden;">
                                <tr>
                                    <td style="background: rgba(194, 149, 79, 0.12); padding: 12px 16px; border-bottom: 1px solid rgba(194, 149, 79, 0.25);">
                                        <strong style="color: #c2954f; font-size: 12.5px; letter-spacing: 0.05em; text-transform: uppercase;">
                                            🔐 Developer Portal Access &amp; Credentials
                                        </strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 16px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="6" style="font-size: 13.5px; color: #e2e8f0;">
                                            <tr>
                                                <td width="130" style="color: #94a3b8; font-weight: 600;">Direct Link:</td>
                                                <td><a href="{$devLoginUrl}" target="_blank" style="color: #38bdf8; text-decoration: underline; font-weight: 600; word-break: break-all;">{$devLoginUrl}</a></td>
                                            </tr>
                                            <tr>
                                                <td style="color: #94a3b8; font-weight: 600;">Username:</td>
                                                <td><span style="background: #1e293b; color: #f8fafc; padding: 3px 8px; border-radius: 4px; font-family: monospace; font-size: 13px; font-weight: 700; border: 1px solid rgba(255,255,255,0.1);">{$devUser}</span></td>
                                            </tr>
                                            <tr>
                                                <td style="color: #94a3b8; font-weight: 600;">Password:</td>
                                                <td><span style="background: #1e293b; color: #f43f5e; padding: 3px 8px; border-radius: 4px; font-family: monospace; font-size: 13px; font-weight: 700; border: 1px solid rgba(255,255,255,0.1);">{$devPass}</span></td>
                                            </tr>
                                        </table>
                                        <div style="margin-top: 14px;">
                                            <a href="{$devLoginUrl}" target="_blank" style="display: inline-block; background-color: #c2954f; color: #0b111e; font-weight: 800; font-size: 13px; text-decoration: none; padding: 10px 20px; border-radius: 6px;">
                                                &rarr; Click Here to Login to Developer Portal
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- Action Buttons -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top: 18px;">
                                <tr>
                                    <td align="center">
                                        <a href="{$devUrl}" target="_blank" style="display: inline-block; background-color: #c2954f; color: #0b111e; font-weight: 800; font-size: 14px; text-decoration: none; padding: 12px 24px; border-radius: 8px; box-shadow: 0 4px 12px rgba(194,149,79,0.3); margin-right: 8px; margin-bottom: 8px;">
                                            Open Developer Console & Renew
                                        </a>
                                        <a href="https://wa.me/{$cleanPhone}?text=AMC%20Maintenance%20Update%20for%20ERP" target="_blank" style="display: inline-block; background-color: #22c55e; color: #0b111e; font-weight: 800; font-size: 14px; text-decoration: none; padding: 12px 20px; border-radius: 8px; margin-bottom: 8px;">
                                            WhatsApp Contact
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 18px 24px; border-top: 1px solid rgba(255, 255, 255, 0.08); text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #64748b;">
                                Automated Maintenance System &bull; Recipient: <strong>razaainaan@gmail.com</strong>
                            </p>
                            <p style="margin: 4px 0 0 0; font-size: 11px; color: #475569;">
                                Configured for reliable Hostinger delivery &bull; Sent from {$schoolName}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

/**
 * Builds the responsive HTML template for Student Concession / Aid notices to Kalbe Mehdi.
 */
function build_concession_email_template(array $data): string
{
    $leadName = htmlspecialchars($data['lead_name'] ?? 'Student');
    $classLabel = htmlspecialchars($data['class_label'] ?? 'Unknown');
    $concessionAmount = (float)($data['concession_amount'] ?? 0);
    $aidPercentage = (float)($data['aid_percentage'] ?? 0);
    $note = htmlspecialchars($data['note'] ?? 'No additional remarks provided.');
    $grantedBy = htmlspecialchars($data['granted_by'] ?? 'Staff User');
    $leadsUrl = htmlspecialchars($data['leads_url'] ?? '#');
    $dateFormatted = htmlspecialchars($data['date_formatted'] ?? date('d M Y, h:i A'));

    $typeRows = '';
    if ($concessionAmount > 0) {
        $formattedAmount = '₹ ' . number_format($concessionAmount, 2);
        $typeRows .= <<<ROW
        <tr>
            <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #94a3b8; font-size: 13.5px; font-weight: 600;" width="160">Admission Concession:</td>
            <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #22c55e; font-size: 16px; font-weight: 800;">{$formattedAmount}</td>
        </tr>
ROW;
    }

    if ($aidPercentage > 0) {
        $formattedAid = number_format($aidPercentage, 2) . '%';
        $typeRows .= <<<ROW
        <tr>
            <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #94a3b8; font-size: 13.5px; font-weight: 600;" width="160">Tuition Fee Aid:</td>
            <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #38bdf8; font-size: 16px; font-weight: 800;">{$formattedAid}</td>
        </tr>
ROW;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Concession &amp; Aid Notification</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0b111e; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #f8fafc;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #0b111e; padding: 30px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #141d2e; border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.6);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 26px 24px; border-bottom: 2px solid #c2954f; text-align: center;">
                            <div style="display: inline-block; background-color: #c2954f; color: #0b111e; font-size: 11px; font-weight: 800; padding: 4px 12px; border-radius: 999px; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 10px;">
                                ADMISSION &amp; FEES ALERT
                            </div>
                            <h1 style="color: #ffffff; font-size: 21px; margin: 0; font-weight: 800;">
                                Concession / Financial Aid Granted
                            </h1>
                            <p style="color: #94a3b8; font-size: 13px; margin: 6px 0 0 0;">
                                Student: <strong style="color: #f8fafc;">{$leadName}</strong> ({$classLabel})
                            </p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 28px 24px;">
                            <p style="font-size: 14.5px; line-height: 1.5; color: #e2e8f0; margin-top: 0;">
                                Hello Super Admin,
                            </p>
                            <p style="font-size: 14px; line-height: 1.6; color: #cbd5e1;">
                                A new admission concession or financial aid has been recorded in the School ERP system. Full details of the student and concession are provided below:
                            </p>

                            <!-- Summary Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #0c1320; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 10px; margin: 20px 0; overflow: hidden;">
                                <tr>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #94a3b8; font-size: 13.5px; font-weight: 600;" width="160">Student Name:</td>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #ffffff; font-size: 14px; font-weight: 700;">{$leadName}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #94a3b8; font-size: 13.5px; font-weight: 600;">Course / Class:</td>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #ffffff; font-size: 14px;">{$classLabel}</td>
                                </tr>
                                {$typeRows}
                                <tr>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #94a3b8; font-size: 13.5px; font-weight: 600;">Reason / Remarks:</td>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #f59e0b; font-size: 13.5px; line-height: 1.4;">{$note}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #94a3b8; font-size: 13.5px; font-weight: 600;">Granted By:</td>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); color: #e2e8f0; font-size: 13.5px;">{$grantedBy}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; color: #94a3b8; font-size: 13.5px; font-weight: 600;">Date &amp; Time:</td>
                                    <td style="padding: 12px 16px; color: #e2e8f0; font-size: 13.5px;">{$dateFormatted}</td>
                                </tr>
                            </table>

                            <!-- Action CTA -->
                            <div style="text-align: center; margin-top: 24px;">
                                <a href="{$leadsUrl}" target="_blank" style="display: inline-block; background-color: #c2954f; color: #0b111e; font-weight: 800; font-size: 14px; text-decoration: none; padding: 12px 26px; border-radius: 8px; box-shadow: 0 4px 12px rgba(194,149,79,0.3);">
                                    View Admission Leads in ERP
                                </a>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 16px 24px; border-top: 1px solid rgba(255, 255, 255, 0.08); text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #64748b;">
                                Super Admin Concession Notification &bull; Delivered to <strong>kalbemehdi@gmail.com</strong>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

/**
 * Dispatches a concession or aid email notification to kalbemehdi@gmail.com every time.
 */
function send_concession_aid_email(
    PDO $pdo,
    array $lead,
    string $classLabel,
    float $concessionAmount,
    string $concessionNote,
    float $aidPercentage = 0.0
): array {
    $to = 'kalbemehdi@gmail.com';

    $isConcession = ($concessionAmount > 0);
    $isAid = ($aidPercentage > 0);

    if (!$isConcession && !$isAid) {
        return ['success' => false, 'message' => 'No concession or aid to report.'];
    }

    $leadName = (string)($lead['lead_name'] ?? 'Student');
    if ($isConcession && $isAid) {
        $subject = "[ERP Alert] Concession (₹" . number_format($concessionAmount, 2) . ") & Aid (" . number_format($aidPercentage, 2) . "%) - " . $leadName;
    } elseif ($isConcession) {
        $subject = "[ERP Alert] Admission Concession of ₹" . number_format($concessionAmount, 2) . " Granted - " . $leadName;
    } else {
        $subject = "[ERP Alert] Tuition Aid of " . number_format($aidPercentage, 2) . "% Granted - " . $leadName;
    }

    // SMTP Config from system_maintenance
    $smtpConfig = [];
    try {
        $stmt = $pdo->query("SELECT * FROM system_maintenance WHERE id = 1 LIMIT 1");
        $rec = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($rec) {
            $smtpUser = !empty($rec['smtp_username']) ? trim($rec['smtp_username']) : 'developer@uvedaaniversity.com';
            $smtpConfig = [
                'smtp_enabled' => !empty($rec['smtp_enabled']),
                'smtp_host' => !empty($rec['smtp_host']) ? trim($rec['smtp_host']) : 'smtp.hostinger.com',
                'smtp_port' => (int)($rec['smtp_port'] ?? 465),
                'smtp_encryption' => !empty($rec['smtp_encryption']) ? trim($rec['smtp_encryption']) : 'ssl',
                'smtp_username' => $smtpUser,
                'smtp_password' => $rec['smtp_password'] ?? '',
                'from_email' => !empty($rec['smtp_from_email']) ? trim($rec['smtp_from_email']) : $smtpUser,
                'from_name' => !empty($rec['smtp_from_name']) ? trim($rec['smtp_from_name']) : 'School ERP System',
                'reply_to' => $smtpUser,
            ];
        }
    } catch (Throwable $t) {
        // Continue with default
    }

    // Construct ERP URLs
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? (str_contains(__DIR__, 'erp.unityiti.edu.in') ? 'erp.unityiti.edu.in' : 'localhost');
    $appBase = function_exists('app_base_url') ? app_base_url() : (str_contains(__DIR__, 'itierp') ? '/itierp' : '');
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_contains($host, 'unityiti.edu.in') ? 'https://' : 'http://';
    $leadsUrl = $protocol . $host . $appBase . '/modules/admission/leads.php';

    $currentUser = function_exists('current_user') ? current_user() : null;
    $grantedBy = $currentUser ? ($currentUser['full_name'] . ' (' . ucfirst(str_replace('_', ' ', $currentUser['role'])) . ')') : 'Staff User';

    $htmlBody = build_concession_email_template([
        'lead_name' => $leadName,
        'class_label' => $classLabel,
        'concession_amount' => $concessionAmount,
        'aid_percentage' => $aidPercentage,
        'note' => $concessionNote,
        'granted_by' => $grantedBy,
        'leads_url' => $leadsUrl,
        'date_formatted' => date('d M Y, h:i A'),
    ]);

    return send_maintenance_email($to, $subject, $htmlBody, $smtpConfig);
}
