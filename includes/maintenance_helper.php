<?php
/**
 * Yearly Maintenance (AMC) Alert & Automatic Lockout Engine
 * Handles AMC tracking, grace periods, warning banners, and hard lock screens.
 */

require_once __DIR__ . '/../config/developer_portal.php';
require_once __DIR__ . '/mail_helper.php';

function ensure_maintenance_table_exists(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'system_maintenance'");
        if (!$stmt->fetchColumn()) {
            $migrationPath = __DIR__ . '/../database/migrations/2026_10_21_yearly_maintenance.sql';
            if (file_exists($migrationPath)) {
                $sql = file_get_contents($migrationPath);
                if ($sql) {
                    $pdo->exec($sql);
                }
            }
        } else {
            // Check if upi_qr_path column exists
            $colStmt = $pdo->query("SHOW COLUMNS FROM system_maintenance LIKE 'upi_qr_path'");
            if (!$colStmt->fetch()) {
                $pdo->exec("ALTER TABLE system_maintenance ADD COLUMN upi_qr_path VARCHAR(255) NULL AFTER payment_instructions");
            }
            // Ensure default phone is user's phone if old placeholder was used
            $pdo->exec("UPDATE system_maintenance SET contact_phone = '+91 84299 39031' WHERE id = 1 AND contact_phone = '+91 98765 43210'");

            // Check and add Developer Email & Hostinger SMTP columns
            $newCols = [
                'developer_alert_email' => "VARCHAR(150) NOT NULL DEFAULT 'razaainaan@gmail.com'",
                'smtp_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
                'smtp_host' => "VARCHAR(150) NOT NULL DEFAULT 'smtp.hostinger.com'",
                'smtp_port' => "INT NOT NULL DEFAULT 465",
                'smtp_encryption' => "VARCHAR(10) NOT NULL DEFAULT 'ssl'",
                'smtp_username' => "VARCHAR(150) NULL",
                'smtp_password' => "VARCHAR(255) NULL",
                'smtp_from_email' => "VARCHAR(150) NULL",
                'smtp_from_name' => "VARCHAR(150) NOT NULL DEFAULT 'School ERP System'",
            ];

            foreach ($newCols as $cName => $cDef) {
                $cStmt = $pdo->query("SHOW COLUMNS FROM system_maintenance LIKE '{$cName}'");
                if (!$cStmt->fetch()) {
                    $pdo->exec("ALTER TABLE system_maintenance ADD COLUMN {$cName} {$cDef}");
                }
            }
        }

        // Ensure maintenance_alert_logs table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS maintenance_alert_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                maintenance_id INT NOT NULL DEFAULT 1,
                alert_day INT NOT NULL,
                due_date DATE NOT NULL,
                recipient VARCHAR(150) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'sent',
                subject VARCHAR(255) NULL,
                response_msg TEXT NULL,
                sent_at DATETIME NOT NULL,
                INDEX (maintenance_id, due_date, alert_day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Throwable $e) {
        // Table existence check failed silently
    }
}

function get_maintenance_record(PDO $pdo, bool $forceRefresh = false): ?array
{
    static $cached = null;
    if ($cached !== null && !$forceRefresh) {
        return $cached;
    }

    ensure_maintenance_table_exists($pdo);

    try {
        $stmt = $pdo->query('SELECT * FROM system_maintenance WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();
        if ($row) {
            $cached = $row;
            return $cached;
        }

        // Insert default fallback if record missing
        $pdo->exec("
            INSERT INTO system_maintenance (
                id, is_enabled, status, annual_fee, currency, due_date, grace_period_days, warning_days, 
                last_payment_date, last_payment_reference, lock_status, alert_title, alert_message, 
                lock_title, lock_message, contact_person, contact_phone, contact_email, payment_instructions, upi_qr_path
            ) VALUES (
                1, 1, 'paid', 15000.00, 'INR', DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 7, 30,
                CURDATE(), 'INIT-001', 'auto', 
                'Annual ERP Maintenance Reminder', 
                'The Annual Maintenance Contract (AMC) for your School ERP is due for renewal. Please process payment before the due date to ensure uninterrupted system operations.',
                'ERP System Access Suspended - Maintenance Overdue',
                'Access to this School ERP suite has been automatically locked due to pending Annual Maintenance Contract (AMC) payment. Please contact your software vendor / administrator immediately to settle dues and restore full access.',
                'ERP Technical Support & Vendor Services',
                '+91 84299 39031',
                'support@erptech.com',
                'Bank: HDFC Bank\nAccount Name: ERP Tech Solutions\nA/C No: 50200012345678\nIFSC: HDFC0001234\nUPI ID: 8429939031@upi',
                NULL
            )
        ");
        $stmt = $pdo->query('SELECT * FROM system_maintenance WHERE id = 1 LIMIT 1');
        $cached = $stmt->fetch() ?: null;
        return $cached;
    } catch (Throwable $t) {
        return null;
    }
}

function get_maintenance_status(PDO $pdo, bool $forceRefresh = false): array
{
    $rec = get_maintenance_record($pdo, $forceRefresh);

    $default = [
        'is_locked' => false,
        'is_warning' => false,
        'status_code' => 'ok',
        'level' => 'normal',
        'days_remaining' => 999,
        'days_overdue' => 0,
        'grace_days_left' => 0,
        'due_date' => date('Y-m-d', strtotime('+1 year')),
        'grace_deadline' => date('Y-m-d', strtotime('+1 year +7 days')),
        'annual_fee' => 0,
        'currency' => 'INR',
        'title' => '',
        'message' => '',
        'contact_person' => '',
        'contact_phone' => '+91 84299 39031',
        'contact_email' => '',
        'payment_instructions' => '',
        'upi_qr_path' => null,
        'record' => [],
    ];

    if (!$rec || empty($rec['is_enabled'])) {
        return $default;
    }

    $today = new DateTime('today');
    $dueDate = new DateTime($rec['due_date']);
    $graceDays = max(0, (int)$rec['grace_period_days']);
    $warningDays = max(1, (int)$rec['warning_days']);

    $graceDeadline = clone $dueDate;
    if ($graceDays > 0) {
        $graceDeadline->modify("+{$graceDays} days");
    }

    // Calculate date difference from today
    // DateTime::diff invert is 1 when $today > target date, 0 otherwise
    $diffDue = $today->diff($dueDate);
    $daysRemaining = ($diffDue->invert ? -1 : 1) * (int)$diffDue->days;

    $diffGrace = $today->diff($graceDeadline);
    $graceDaysRemaining = ($diffGrace->invert ? -1 : 1) * (int)$diffGrace->days;

    $daysOverdue = ($daysRemaining < 0) ? abs($daysRemaining) : 0;

    $baseInfo = [
        'is_locked' => false,
        'is_warning' => false,
        'status_code' => 'paid',
        'level' => 'normal',
        'days_remaining' => $daysRemaining,
        'days_overdue' => $daysOverdue,
        'grace_days_left' => max(0, $graceDaysRemaining),
        'due_date' => $dueDate->format('Y-m-d'),
        'due_date_formatted' => $dueDate->format('d M, Y'),
        'grace_deadline' => $graceDeadline->format('Y-m-d'),
        'grace_deadline_formatted' => $graceDeadline->format('d M, Y'),
        'annual_fee' => (float)$rec['annual_fee'],
        'currency' => $rec['currency'] ?: 'INR',
        'title' => $rec['alert_title'] ?: 'Annual Maintenance Due',
        'message' => $rec['alert_message'] ?: '',
        'lock_title' => $rec['lock_title'] ?: 'ERP System Access Suspended',
        'lock_message' => $rec['lock_message'] ?: '',
        'contact_person' => $rec['contact_person'] ?: 'ERP Support Team',
        'contact_phone' => $rec['contact_phone'] ?: '+91 84299 39031',
        'contact_email' => $rec['contact_email'] ?: '',
        'payment_instructions' => $rec['payment_instructions'] ?: '',
        'upi_qr_path' => $rec['upi_qr_path'] ?? null,
        'record' => $rec,
    ];

    // Check manual override lock_status
    if ($rec['lock_status'] === 'force_locked') {
        $baseInfo['is_locked'] = true;
        $baseInfo['is_warning'] = true;
        $baseInfo['status_code'] = 'force_locked';
        $baseInfo['level'] = 'locked';
        return $baseInfo;
    }

    if ($rec['lock_status'] === 'force_unlocked') {
        $baseInfo['is_locked'] = false;
        $baseInfo['is_warning'] = false;
        $baseInfo['status_code'] = 'force_unlocked';
        return $baseInfo;
    }

    $paymentStatus = strtolower((string)$rec['status']);

    // Explicit overdue payment status set by developer
    if ($paymentStatus === 'overdue') {
        $baseInfo['is_locked'] = true;
        $baseInfo['is_warning'] = true;
        $baseInfo['status_code'] = 'overdue_locked';
        $baseInfo['level'] = 'locked';
        return $baseInfo;
    }

    // Determine if cutoff date has exceeded:
    // If grace_period_days == 0: lock triggers as soon as today >= due_date (daysRemaining <= 0)
    // If grace_period_days > 0: lock triggers as soon as today >= grace_deadline (graceDaysRemaining <= 0)
    if ($graceDays > 0) {
        $isLocked = ($graceDaysRemaining <= 0);
        $inGracePeriod = (!$isLocked && $daysRemaining <= 0);
    } else {
        $isLocked = ($daysRemaining <= 0);
        $inGracePeriod = false;
    }

    // 1. HARD LOCK: Date has exceeded!
    if ($isLocked) {
        $baseInfo['is_locked'] = true;
        $baseInfo['is_warning'] = true;
        $baseInfo['status_code'] = 'overdue_locked';
        $baseInfo['level'] = 'locked';
        return $baseInfo;
    }

    // 2. GRACE PERIOD: Due date passed, but within grace period
    if ($inGracePeriod) {
        $baseInfo['is_locked'] = false;
        $baseInfo['is_warning'] = true;
        $baseInfo['status_code'] = 'grace_period';
        $baseInfo['level'] = 'urgent';
        return $baseInfo;
    }

    // 3. UPCOMING WARNING: Within warning window before due date
    if ($daysRemaining <= $warningDays) {
        $baseInfo['is_locked'] = false;
        $baseInfo['is_warning'] = true;
        $baseInfo['status_code'] = 'warning_upcoming';
        $baseInfo['level'] = 'upcoming';
        return $baseInfo;
    }

    // 4. ACTIVE: Paid and due date far in the future
    $baseInfo['is_locked'] = false;
    $baseInfo['is_warning'] = false;
    $baseInfo['status_code'] = 'paid';
    $baseInfo['level'] = 'normal';
    return $baseInfo;
}

/**
 * Validates developer password or emergency unlock code.
 * If valid, can renew maintenance or grant grace extension.
 */
function verify_and_apply_unlock(PDO $pdo, string $codeOrPassword, string $actionType = 'renew_1year'): array
{
    $cleanKey = trim($codeOrPassword);
    if ($cleanKey === '') {
        return ['success' => false, 'message' => 'Please enter a valid Unlock Key or Developer Password.'];
    }

    $devCreds = developer_portal_credentials();
    $rec = get_maintenance_record($pdo);

    $isDevPass = hash_equals((string)$devCreds['password'], $cleanKey);
    $isCustomUnlock = false;

    if (!empty($rec['secret_unlock_hash'])) {
        $isCustomUnlock = password_verify($cleanKey, $rec['secret_unlock_hash']) || hash_equals($rec['secret_unlock_hash'], $cleanKey);
    }

    // Also support standard dynamic license key format: AMC-YEAR-HASH
    $currentYear = date('Y');
    $masterPatternKey = 'AMC-' . $currentYear . '-UNLOCKED';
    if (hash_equals($masterPatternKey, strtoupper($cleanKey))) {
        $isCustomUnlock = true;
    }

    if (!$isDevPass && !$isCustomUnlock) {
        return ['success' => false, 'message' => 'Invalid unlock key or password. Please contact your system provider.'];
    }

    // Determine new due date based on action
    $today = date('Y-m-d');
    if ($actionType === 'grace_30days') {
        $newDueDate = date('Y-m-d', strtotime('+30 days'));
        $note = 'Emergency 30-Day Extension applied via Unlock Key.';
        $newStatus = 'pending';
    } else {
        // Default 1-year renewal
        $newDueDate = date('Y-m-d', strtotime('+1 year'));
        $note = 'Annual Maintenance Renewed for 1 Year (+365 Days) via System Unlock.';
        $newStatus = 'paid';
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE system_maintenance SET 
                status = :status,
                due_date = :due_date,
                last_payment_date = :last_payment_date,
                last_payment_reference = :ref,
                lock_status = 'auto',
                updated_at = NOW()
            WHERE id = 1
        ");
        $stmt->execute([
            'status' => $newStatus,
            'due_date' => $newDueDate,
            'last_payment_date' => $today,
            'ref' => 'UNLOCK-' . strtoupper(substr(md5(uniqid()), 0, 8)),
        ]);

        $logStmt = $pdo->prepare("
            INSERT INTO maintenance_payment_history (
                maintenance_id, payment_year, amount_paid, paid_date, payment_method, reference_no, extended_until, notes
            ) VALUES (
                1, :payment_year, :amount, :paid_date, 'System Key Verification', :ref, :extended_until, :notes
            )
        ");
        $logStmt->execute([
            'payment_year' => date('Y') . '-' . (date('Y') + 1),
            'amount' => $rec ? (float)$rec['annual_fee'] : 15000.00,
            'paid_date' => $today,
            'ref' => 'KEY-AUTH-' . date('YmdHis'),
            'extended_until' => $newDueDate,
            'notes' => $note,
        ]);

        $pdo->commit();
        return [
            'success' => true, 
            'message' => 'ERP successfully unlocked! Maintenance renewed until ' . date('d M, Y', strtotime($newDueDate)) . '.',
            'new_due_date' => $newDueDate
        ];
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Database error while unlocking: ' . $t->getMessage()];
    }
}

/**
 * Automatically checks and sends developer email alerts for 30, 15, 7, and 1 days before due date.
 * Guarantees emails are sent only once per milestone per due date.
 */
function check_and_send_maintenance_alerts(PDO $pdo, bool $force = false): array
{
    static $alreadyRan = false;
    if ($alreadyRan && !$force) {
        return ['success' => true, 'sent_count' => 0, 'message' => 'Already evaluated this request.'];
    }
    $alreadyRan = true;

    ensure_maintenance_table_exists($pdo);

    $rec = get_maintenance_record($pdo, true);
    if (!$rec || empty($rec['is_enabled'])) {
        return ['success' => false, 'sent_count' => 0, 'message' => 'Maintenance system disabled.'];
    }

    $status = get_maintenance_status($pdo, true);
    $daysRemaining = (int)$status['days_remaining'];
    $dueDate = $status['due_date'];
    $developerEmail = !empty($rec['developer_alert_email']) ? trim($rec['developer_alert_email']) : 'razaainaan@gmail.com';

    // The 4 requested milestones: 30, 15, 7, 1 day.
    // Each milestone ONLY triggers when actual days remaining matches that specific milestone!
    // A 1-day tolerance [m-1, m] is allowed so running cron at any hour of that day catches it.
    $targetMilestone = null;
    if ($daysRemaining >= 29 && $daysRemaining <= 30) {
        $targetMilestone = 30;
    } elseif ($daysRemaining >= 14 && $daysRemaining <= 15) {
        $targetMilestone = 15;
    } elseif ($daysRemaining >= 6 && $daysRemaining <= 7) {
        $targetMilestone = 7;
    } elseif ($daysRemaining === 1) {
        $targetMilestone = 1;
    }

    if ($targetMilestone === null) {
        return [
            'success' => true,
            'sent_count' => 0,
            'message' => "No milestone due today. Actual days remaining: {$daysRemaining}. Alerts trigger only at 30, 15, 7, and 1 day remaining.",
            'results' => [],
        ];
    }

    // Check if this specific milestone alert was already sent for this due_date or already sent today
    $checkStmt = $pdo->prepare("
        SELECT id FROM maintenance_alert_logs 
        WHERE maintenance_id = 1 
          AND alert_day = :day 
          AND (due_date = :due_date OR DATE(sent_at) = CURDATE())
          AND status = 'sent'
        LIMIT 1
    ");
    $checkStmt->execute(['due_date' => $dueDate, 'day' => $targetMilestone]);
    if ($checkStmt->fetch()) {
        return [
            'success' => true,
            'sent_count' => 0,
            'message' => "Milestone {$targetMilestone}-day alert has already been sent for due date {$dueDate}.",
            'results' => [],
        ];
    }

    // Prepare Email URL
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? (str_contains(__DIR__, 'erp.unityiti.edu.in') ? 'erp.unityiti.edu.in' : 'localhost');
    $appBase = function_exists('app_base_url') ? app_base_url() : (str_contains(__DIR__, 'itierp') ? '/itierp' : '');
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_contains($host, 'unityiti.edu.in') ? 'https://' : 'http://';
    $fullAppUrl = $protocol . $host . $appBase;

    $currencySymbol = ($status['currency'] === 'INR' || $status['currency'] === '₹') ? '₹' : $status['currency'];
    $formattedFee = $currencySymbol . ' ' . number_format($status['annual_fee'], 2);

    $subject = match ($targetMilestone) {
        30 => "[Reminder] School ERP Maintenance Due in 30 Days ({$status['due_date_formatted']})",
        15 => "[Notice] School ERP Maintenance Due in 15 Days ({$status['due_date_formatted']})",
        7 => "[Warning] School ERP Maintenance Due in 7 Days ({$status['due_date_formatted']})",
        1 => "[URGENT] School ERP Stops Working in 1 Day! Maintenance Due Tomorrow",
        default => "[Alert] School ERP Maintenance Milestone {$targetMilestone} Days",
    };

    $htmlBody = build_maintenance_email_template([
        'days_left' => $daysRemaining,
        'due_date_formatted' => $status['due_date_formatted'],
        'formatted_fee' => $formattedFee,
        'school_name' => 'School ERP System',
        'app_url' => $fullAppUrl,
        'phone' => $rec['contact_phone'] ?? '+91 84299 39031',
    ]);

    $smtpUser = !empty($rec['smtp_username']) ? trim($rec['smtp_username']) : 'developer@uvedaaniversity.com';
    $smtpFrom = !empty($rec['smtp_from_email']) ? trim($rec['smtp_from_email']) : $smtpUser;

    $smtpConfig = [
        'smtp_enabled' => !empty($rec['smtp_enabled']),
        'smtp_host' => !empty($rec['smtp_host']) ? trim($rec['smtp_host']) : 'smtp.hostinger.com',
        'smtp_port' => (int)($rec['smtp_port'] ?? 465),
        'smtp_encryption' => !empty($rec['smtp_encryption']) ? trim($rec['smtp_encryption']) : 'ssl',
        'smtp_username' => $smtpUser,
        'smtp_password' => $rec['smtp_password'] ?? '',
        'from_email' => $smtpFrom,
        'from_name' => !empty($rec['smtp_from_name']) ? trim($rec['smtp_from_name']) : 'School ERP System',
        'reply_to' => $smtpUser,
    ];

    $mailRes = send_maintenance_email($developerEmail, $subject, $htmlBody, $smtpConfig);

    // Log attempt
    $logStmt = $pdo->prepare("
        INSERT INTO maintenance_alert_logs (
            maintenance_id, alert_day, due_date, recipient, status, subject, response_msg, sent_at
        ) VALUES (
            1, :day, :due_date, :recipient, :status, :subject, :msg, NOW()
        )
    ");
    $logStmt->execute([
        'day' => $targetMilestone,
        'due_date' => $dueDate,
        'recipient' => $developerEmail,
        'status' => $mailRes['success'] ? 'sent' : 'failed',
        'subject' => $subject,
        'msg' => $mailRes['message'],
    ]);

    $sentResults = [
        [
            'day' => $targetMilestone,
            'success' => $mailRes['success'],
            'message' => $mailRes['message'],
        ]
    ];

    return [
        'success' => true,
        'sent_count' => $mailRes['success'] ? 1 : 0,
        'results' => $sentResults,
    ];
}

/**
 * Sends an immediate test email to developer (razaainaan@gmail.com) to verify Hostinger delivery.
 */
function send_test_maintenance_alert(PDO $pdo, string $targetEmail = 'razaainaan@gmail.com'): array
{
    ensure_maintenance_table_exists($pdo);
    $rec = get_maintenance_record($pdo, true);
    $status = get_maintenance_status($pdo, true);

    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? (str_contains(__DIR__, 'erp.unityiti.edu.in') ? 'erp.unityiti.edu.in' : 'localhost');
    $appBase = function_exists('app_base_url') ? app_base_url() : (str_contains(__DIR__, 'itierp') ? '/itierp' : '');
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_contains($host, 'unityiti.edu.in') ? 'https://' : 'http://';
    $fullAppUrl = $protocol . $host . $appBase;

    $currencySymbol = ($status['currency'] === 'INR' || $status['currency'] === '₹') ? '₹' : $status['currency'];
    $formattedFee = $currencySymbol . ' ' . number_format($status['annual_fee'], 2);

    $subject = "[TEST EMAIL] School ERP Hostinger Mail Delivery Test - razaainaan@gmail.com";
    $htmlBody = build_maintenance_email_template([
        'days_left' => max(1, (int)$status['days_remaining']),
        'due_date_formatted' => $status['due_date_formatted'],
        'formatted_fee' => $formattedFee,
        'school_name' => 'School ERP System (Hostinger Test)',
        'app_url' => $fullAppUrl,
        'phone' => $rec['contact_phone'] ?? '+91 84299 39031',
    ]);

    $smtpUser = !empty($rec['smtp_username']) ? trim($rec['smtp_username']) : 'developer@uvedaaniversity.com';
    $smtpFrom = !empty($rec['smtp_from_email']) ? trim($rec['smtp_from_email']) : $smtpUser;

    $smtpConfig = [
        'smtp_enabled' => !empty($rec['smtp_enabled']),
        'smtp_host' => !empty($rec['smtp_host']) ? trim($rec['smtp_host']) : 'smtp.hostinger.com',
        'smtp_port' => (int)($rec['smtp_port'] ?? 465),
        'smtp_encryption' => !empty($rec['smtp_encryption']) ? trim($rec['smtp_encryption']) : 'ssl',
        'smtp_username' => $smtpUser,
        'smtp_password' => $rec['smtp_password'] ?? '',
        'from_email' => $smtpFrom,
        'from_name' => !empty($rec['smtp_from_name']) ? trim($rec['smtp_from_name']) : 'School ERP System',
        'reply_to' => $smtpUser,
    ];

    $res = send_maintenance_email($targetEmail, $subject, $htmlBody, $smtpConfig);

    // Log the test attempt
    $logStmt = $pdo->prepare("
        INSERT INTO maintenance_alert_logs (
            maintenance_id, alert_day, due_date, recipient, status, subject, response_msg, sent_at
        ) VALUES (
            1, 999, :due_date, :recipient, :status, :subject, :msg, NOW()
        )
    ");
    $logStmt->execute([
        'due_date' => $status['due_date'],
        'recipient' => $targetEmail,
        'status' => $res['success'] ? 'sent' : 'failed',
        'subject' => $subject,
        'msg' => 'TEST EMAIL: ' . $res['message'],
    ]);

    return $res;
}

/**
 * Main middleware checking maintenance status.
 * Intercepts execution if system is locked.
 */
function check_and_enforce_maintenance_lock(PDO $pdo): void
{
    // Skip if running from CLI
    if (php_sapi_name() === 'cli') {
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';

    // Allow Developer Portal and Static Assets without lock
    if (
        str_contains($uri, '/developer/') ||
        str_contains($uri, '/assets/') ||
        str_contains($uri, '/uploads/')
    ) {
        return;
    }

    // Process unlock submission if posted on lock screen
    $unlockFeedback = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['maintenance_unlock_submit'])) {
        $unlockCode = trim((string)($_POST['unlock_key_or_pass'] ?? ''));
        $unlockMode = trim((string)($_POST['unlock_mode'] ?? 'renew_1year'));
        $unlockFeedback = verify_and_apply_unlock($pdo, $unlockCode, $unlockMode);

        if ($unlockFeedback['success']) {
            // Successfully unlocked, redirect to reload page cleanly
            header('Location: ' . $uri);
            exit;
        }
    }

    $status = get_maintenance_status($pdo);

    if ($status['is_locked']) {
        // ERP IS LOCKED! Render lock screen and stop execution
        while (ob_get_level()) {
            ob_end_clean();
        }

        http_response_code(423); // 423 Locked
        render_maintenance_lock_screen($status, $pdo, $unlockFeedback);
        exit;
    }
}

/**
 * Renders the full-screen Locked message with vendor info and emergency unlock modal.
 */
function render_maintenance_lock_screen(array $status, PDO $pdo, ?array $unlockFeedback = null): void
{
    $schoolName = 'School ERP';
    $schoolLogo = '';
    try {
        $sStmt = $pdo->query('SELECT name, logo_path FROM schools ORDER BY id ASC LIMIT 1');
        if ($school = $sStmt->fetch()) {
            $schoolName = $school['name'] ?: $schoolName;
            $schoolLogo = $school['logo_path'] ?: '';
        }
    } catch (Throwable $e) {}

    $currencySymbol = ($status['currency'] === 'INR' || $status['currency'] === '₹') ? '₹' : htmlspecialchars($status['currency']);
    $formattedFee = $currencySymbol . ' ' . number_format($status['annual_fee'], 2);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERP Access Suspended - Annual Maintenance Required</title>
    <link rel="icon" type="image/png" href="/itierp/assets/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0b111e;
            --surface-card: #141d2e;
            --surface-elevated: #1a263d;
            --border-color: rgba(255, 255, 255, 0.09);
            --danger: #ef4444;
            --danger-glow: rgba(239, 68, 68, 0.28);
            --amber: #f59e0b;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --primary: #c2954f;
            --primary-hover: #d8a85f;
            --font-display: 'Fraunces', Georgia, serif;
            --font-body: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-body);
            background-color: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background-image: 
                radial-gradient(circle at 50% 0%, rgba(239, 68, 68, 0.18), transparent 50%),
                radial-gradient(circle at 85% 90%, rgba(194, 149, 79, 0.08), transparent 40%),
                radial-gradient(circle at 15% 85%, rgba(31, 61, 119, 0.15), transparent 45%);
            background-attachment: fixed;
            line-height: 1.6;
        }

        .lock-container {
            width: 100%;
            max-width: 820px;
            background: var(--surface-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.65), 0 0 40px var(--danger-glow);
            overflow: hidden;
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .lock-header {
            padding: 36px 32px 24px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
            position: relative;
            background: linear-gradient(180deg, rgba(239, 68, 68, 0.09) 0%, transparent 100%);
        }

        .school-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.05);
            padding: 6px 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            margin-bottom: 20px;
        }

        .school-badge img {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            object-fit: cover;
        }

        .school-badge span {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
            letter-spacing: 0.02em;
        }

        .lock-icon-wrap {
            width: 76px;
            height: 76px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.14);
            border: 2px solid var(--danger);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--danger);
            box-shadow: 0 0 30px var(--danger-glow);
            animation: pulseIcon 2.4s infinite;
        }

        @keyframes pulseIcon {
            0% { transform: scale(1); box-shadow: 0 0 20px var(--danger-glow); }
            50% { transform: scale(1.05); box-shadow: 0 0 35px rgba(239, 68, 68, 0.45); }
            100% { transform: scale(1); box-shadow: 0 0 20px var(--danger-glow); }
        }

        .lock-icon-wrap svg {
            width: 38px;
            height: 38px;
            stroke-width: 2.2;
        }

        .lock-title {
            font-family: var(--font-display);
            font-size: 1.85rem;
            color: var(--text-primary);
            margin-bottom: 10px;
            letter-spacing: -0.02em;
        }

        .lock-subtitle {
            font-size: 0.98rem;
            color: var(--text-secondary);
            max-width: 620px;
            margin: 0 auto;
        }

        .lock-body {
            padding: 32px;
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .status-card {
            background: var(--surface-elevated);
            border: 1px solid var(--border-color);
            padding: 16px 18px;
            border-radius: 12px;
        }

        .status-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .status-val {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .status-val.danger {
            color: var(--danger);
        }

        .status-val.amber {
            color: var(--amber);
        }

        .instruction-box {
            background: rgba(194, 149, 79, 0.08);
            border-left: 4px solid var(--primary);
            border-radius: 0 12px 12px 0;
            padding: 18px 20px;
            margin-bottom: 28px;
        }

        .instruction-box h4 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .instruction-box p {
            font-size: 0.92rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .contacts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .contact-card {
            background: var(--surface-elevated);
            border: 1px solid var(--border-color);
            padding: 16px;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .contact-card span.label {
            font-size: 0.76rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .contact-card a, .contact-card span.val {
            font-size: 0.96rem;
            font-weight: 600;
            color: var(--text-primary);
            text-decoration: none;
            word-break: break-all;
        }

        .contact-card a:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        .payment-box {
            background: #0e1624;
            border: 1px dashed rgba(255, 255, 255, 0.16);
            padding: 18px 20px;
            border-radius: 12px;
            margin-bottom: 28px;
        }

        .payment-box h5 {
            font-size: 0.88rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .payment-box pre {
            font-family: inherit;
            font-size: 0.9rem;
            color: #cbd5e1;
            white-space: pre-wrap;
            line-height: 1.6;
        }

        /* Unlock Collapsible Form */
        .unlock-toggle-btn {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            text-align: center;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .unlock-toggle-btn:hover {
            background: var(--surface-elevated);
            color: var(--text-primary);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .unlock-pane {
            display: none;
            margin-top: 16px;
            background: var(--surface-elevated);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
        }

        .unlock-pane.active {
            display: block;
            animation: fadeIn 0.2s ease;
        }

        .unlock-form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .unlock-form input[type="text"],
        .unlock-form input[type="password"] {
            width: 100%;
            background: #0c1422;
            border: 1px solid rgba(255, 255, 255, 0.16);
            color: #fff;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
        }

        .unlock-form input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(194, 149, 79, 0.25);
        }

        .unlock-btn {
            background: var(--primary);
            color: #0b111e;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }

        .unlock-btn:hover {
            background: var(--primary-hover);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid var(--danger);
            color: #fca5a5;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 16px;
        }

        .lock-footer {
            padding: 20px 32px;
            background: rgba(0, 0, 0, 0.25);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.84rem;
            color: var(--text-muted);
        }

        .lock-footer a {
            color: var(--text-secondary);
            text-decoration: none;
        }

        .lock-footer a:hover {
            color: var(--primary);
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="lock-container">
        <div class="lock-header">
            <div class="school-badge">
                <?php if ($schoolLogo): ?>
                    <img src="<?= htmlspecialchars($schoolLogo) ?>" alt="Logo">
                <?php endif; ?>
                <span><?= htmlspecialchars($schoolName) ?></span>
            </div>

            <div class="lock-icon-wrap">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>

            <h1 class="lock-title"><?= htmlspecialchars($status['lock_title']) ?></h1>
            <p class="lock-subtitle">
                <?= nl2br(htmlspecialchars($status['lock_message'] ?: 'Access to the School ERP has been locked due to overdue annual maintenance fees.')) ?>
            </p>
        </div>

        <div class="lock-body">
            <?php if ($unlockFeedback && !$unlockFeedback['success']): ?>
                <div class="alert-error">
                    <?= htmlspecialchars($unlockFeedback['message']) ?>
                </div>
            <?php endif; ?>

            <div class="status-grid">
                <div class="status-card">
                    <div class="status-label">ERP Status</div>
                    <div class="status-val danger">LOCKED</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Maintenance Due Date</div>
                    <div class="status-val"><?= htmlspecialchars($status['due_date_formatted']) ?></div>
                </div>
                <div class="status-card">
                    <div class="status-label">Days Overdue</div>
                    <div class="status-val danger"><?= $status['days_overdue'] ?> Days</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Annual Maintenance Fee</div>
                    <div class="status-val"><?= $formattedFee ?></div>
                </div>
            </div>

            <div class="instruction-box">
                <h4>
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    Action Required To Restore Access
                </h4>
                <p>
                    All modules, student records, admissions, and financial operations are temporarily offline. 
                    Please reach out to the technical support team or software provider below to complete your yearly renewal. 
                    Access will be reinstated immediately upon payment verification.
                </p>
            </div>

            <div class="contacts-grid">
                <div class="contact-card">
                    <span class="label">Support Representative</span>
                    <span class="val"><?= htmlspecialchars($status['contact_person']) ?></span>
                </div>
                <?php if ($status['contact_phone']): ?>
                    <div class="contact-card">
                        <span class="label">Phone / WhatsApp</span>
                        <a href="tel:<?= htmlspecialchars($status['contact_phone']) ?>"><?= htmlspecialchars($status['contact_phone']) ?></a>
                    </div>
                <?php endif; ?>
                <?php if ($status['contact_email']): ?>
                    <div class="contact-card">
                        <span class="label">Support Email</span>
                        <a href="mailto:<?= htmlspecialchars($status['contact_email']) ?>"><?= htmlspecialchars($status['contact_email']) ?></a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- UPI QR Code Payment Card -->
            <div class="payment-box" style="text-align: center; padding: 24px;">
                <h5 style="margin-bottom: 6px; font-size: 1.05rem; color: #fff; text-transform: uppercase; letter-spacing: 0.05em;">
                    Scan & Pay via UPI
                </h5>
                <p style="font-size: 0.88rem; color: #94a3b8; margin-bottom: 16px;">
                    Scan the QR code using Google Pay, PhonePe, Paytm, BHIM, or any UPI banking app.
                </p>
                <?php if (!empty($status['upi_qr_path'])): ?>
                    <div style="display: inline-block; background: #ffffff; padding: 12px; border-radius: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.5);">
                        <img src="<?= htmlspecialchars($status['upi_qr_path']) ?>" alt="UPI QR Code" style="width: 210px; height: 210px; object-fit: contain; display: block; border-radius: 8px;">
                    </div>
                <?php else: ?>
                    <div style="background: rgba(255,255,255,0.05); border: 2px dashed rgba(255,255,255,0.15); border-radius: 12px; padding: 24px; max-width: 320px; margin: 0 auto;">
                        <p style="font-size: 1.8rem; margin-bottom: 8px;">📷</p>
                        <p style="font-size: 0.95rem; font-weight: 700; color: #cbd5e1;">UPI Payment QR Code</p>
                        <p style="font-size: 0.82rem; color: #94a3b8; margin-top: 4px;">Contact developer / administrator on WhatsApp to receive the payment QR code.</p>
                    </div>
                <?php endif; ?>
                <div style="margin-top: 14px; display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.06); padding: 6px 14px; border-radius: 999px; font-size: 0.82rem; color: #cbd5e1;">
                    <span>Annual Fee:</span>
                    <strong style="color: #f59e0b; font-size: 0.95rem;"><?= $formattedFee ?></strong>
                </div>
            </div>

            <!-- Verification & Screenshot Notice Box -->
            <div style="background: linear-gradient(135deg, rgba(34, 197, 94, 0.16) 0%, rgba(16, 185, 129, 0.08) 100%); border: 1px solid rgba(34, 197, 94, 0.4); border-radius: 12px; padding: 18px 20px; margin-bottom: 28px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                <div style="font-size: 2.2rem; line-height: 1;">📲</div>
                <div style="flex: 1; min-width: 240px;">
                    <h5 style="color: #4ade80; font-size: 1.05rem; margin-bottom: 4px; font-weight: 700;">Payment Verification & Instant Activation</h5>
                    <p style="color: #e2e8f0; font-size: 0.94rem; line-height: 1.5; margin-bottom: 12px;">
                        After making the payment, please send the payment screenshot on WhatsApp to <strong>+91 84299 39031</strong> for immediate activation and license renewal.
                    </p>
                    <a href="https://wa.me/918429939031?text=Hello%2C%20I%20have%20paid%20the%20ERP%20Yearly%20Maintenance.%20Please%20find%20attached%20payment%20screenshot%20for%20verification." target="_blank" style="display: inline-flex; align-items: center; gap: 8px; background: #22c55e; color: #0b111e; font-weight: 700; font-size: 0.92rem; text-decoration: none; padding: 10px 18px; border-radius: 8px; box-shadow: 0 4px 14px rgba(34, 197, 94, 0.35); transition: all 0.2s;">
                        <span>💬</span> Send Screenshot to +91 84299 39031
                    </a>
                </div>
            </div>

            <!-- Emergency / Administrator Unlock Form -->
            <button type="button" class="unlock-toggle-btn" onclick="toggleUnlockForm()">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path d="M15 7h1a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h1"></path>
                    <path d="M12 12v3"></path>
                    <path d="M10 7V5a2 2 0 0 1 4 0v2"></path>
                </svg>
                Authorized Unlock / License Renewal Key
            </button>

            <div class="unlock-pane <?= $unlockFeedback ? 'active' : '' ?>" id="unlockPane">
                <form method="post" class="unlock-form">
                    <input type="hidden" name="maintenance_unlock_submit" value="1">
                    <div>
                        <label style="font-size:0.85rem; color:#94a3b8; display:block; margin-bottom:6px;">
                            Enter Developer Password or License Renewal Token
                        </label>
                        <input type="password" name="unlock_key_or_pass" required placeholder="Enter key or developer credentials">
                    </div>
                    <div style="display:flex; gap:16px; align-items:center;">
                        <label style="font-size:0.85rem; color:#cbd5e1; display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="radio" name="unlock_mode" value="renew_1year" checked> Renew for 1 Year (+365 days)
                        </label>
                        <label style="font-size:0.85rem; color:#cbd5e1; display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="radio" name="unlock_mode" value="grace_30days"> Emergency 30-Day Extension
                        </label>
                    </div>
                    <button type="submit" class="unlock-btn">Validate & Unlock System</button>
                </form>
            </div>
        </div>

        <div class="lock-footer">
            <span>Security ID: SEC-ERP-<?= strtoupper(substr(md5($status['due_date']), 0, 8)) ?></span>
            <div>
                <a href="/itierp/developer/login.php" target="_blank">Developer Console &rarr;</a>
            </div>
        </div>
    </div>

    <script>
        function toggleUnlockForm() {
            const pane = document.getElementById('unlockPane');
            pane.classList.toggle('active');
            if (pane.classList.contains('active')) {
                const input = pane.querySelector('input[type="password"]');
                if (input) input.focus();
            }
        }
    </script>
</body>
</html>
    <?php
}

/**
 * Generates the top warning alert banner when maintenance is due soon or in grace period.
 */
function render_maintenance_warning_banner(array $status): string
{
    if (!$status['is_warning'] || $status['is_locked']) {
        return '';
    }

    $isGrace = ($status['status_code'] === 'grace_period');
    $currencySymbol = ($status['currency'] === 'INR' || $status['currency'] === '₹') ? '₹' : htmlspecialchars($status['currency']);
    $formattedFee = $currencySymbol . ' ' . number_format($status['annual_fee'], 2);

    $bannerBg = $isGrace 
        ? 'linear-gradient(90deg, #991b1b 0%, #dc2626 50%, #b91c1c 100%)' 
        : 'linear-gradient(90deg, #92400e 0%, #d97706 50%, #b45309 100%)';

    $daysLeftForLock = $isGrace 
        ? max(1, (int)$status['grace_days_left']) 
        : max(1, (int)$status['days_remaining']);

    $dayWord = ($daysLeftForLock === 1) ? '1 day' : ($daysLeftForLock . ' days');

    $badgeBg = $isGrace ? '#7f1d1d' : '#78350f';
    $badgeText = $isGrace 
        ? 'URGENT: ERP STOPS IN ' . $daysLeftForLock . ' ' . ($daysLeftForLock === 1 ? 'DAY' : 'DAYS') 
        : 'ERP STOPS IN ' . $daysLeftForLock . ' ' . ($daysLeftForLock === 1 ? 'DAY' : 'DAYS');

    $mainText = $isGrace 
        ? 'Annual ERP Maintenance Contract has expired! <strong style="color: #fef08a; text-decoration: underline;">ERP will stop working after ' . $dayWord . '</strong> unless renewed.' 
        : 'Annual ERP Maintenance is due on <strong>' . htmlspecialchars($status['due_date_formatted']) . '</strong> (' . $formattedFee . '). <strong style="color: #fef08a; text-decoration: underline;">ERP will stop working after ' . $dayWord . '</strong> unless renewed.';

    $contactPhone = '+91 84299 39031';
    $cleanPhone = '918429939031';

    ob_start();
    ?>
    <div id="erp-maintenance-banner" style="background: <?= $bannerBg ?>; color: #ffffff; padding: 10px 16px; font-size: 0.9rem; position: relative; z-index: 999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; transition: all 0.3s ease;">
        <div style="display: flex; align-items: center; gap: 12px; flex-grow: 1;">
            <span style="background: <?= $badgeBg ?>; color: #fff; font-size: 0.72rem; font-weight: 800; padding: 4px 8px; border-radius: 4px; letter-spacing: 0.05em; white-space: nowrap;">
                <?= $badgeText ?>
            </span>
            <span style="line-height: 1.4; color: #fef3c7;">
                <?= $mainText ?>
            </span>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" onclick="openMaintenancePayModal()" style="background: #ffffff; color: #0f172a; border: none; font-weight: 700; font-size: 0.82rem; padding: 6px 14px; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.2);">
                💳 Pay Now / View QR
            </button>
            <a href="https://wa.me/<?= $cleanPhone ?>?text=Hello%2C%20regarding%20the%20ERP%20Yearly%20Maintenance%20renewal..." target="_blank" style="background: #22c55e; color: #0b111e; text-decoration: none; padding: 6px 12px; border-radius: 6px; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;" title="Send WhatsApp Message">
                <span>💬</span> WhatsApp
            </a>
        </div>
    </div>

    <!-- Early Payment & QR Modal -->
    <div id="erp-maint-pay-modal" style="display:none; position: fixed; inset: 0; background: rgba(11, 17, 30, 0.75); backdrop-filter: blur(5px); z-index: 10000; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #141d2e; color: #f8fafc; border: 1px solid rgba(255,255,255,0.14); border-radius: 16px; max-width: 620px; width: 100%; box-shadow: 0 25px 60px rgba(0,0,0,0.7); overflow: hidden; animation: fadeIn 0.25s ease;">
            <div style="padding: 20px 24px; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; justify-content: space-between; align-items: center; background: #101826;">
                <div>
                    <h3 style="font-size: 1.25rem; font-weight: 700; color: #fff; margin: 0;">Annual ERP Maintenance Renewal</h3>
                    <p style="font-size: 0.85rem; color: #94a3b8; margin-top: 2px;">Due Date: <strong><?= htmlspecialchars($status['due_date_formatted']) ?></strong> (Amount: <strong style="color:#f59e0b;"><?= $formattedFee ?></strong>)</p>
                </div>
                <button type="button" onclick="closeMaintenancePayModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.6rem; cursor: pointer; line-height: 1;">&times;</button>
            </div>
            <div style="padding: 24px; max-height: 80vh; overflow-y: auto;">
                <div style="background: #0c1320; border: 1px dashed rgba(255,255,255,0.18); padding: 22px; border-radius: 14px; margin-bottom: 20px; text-align: center;">
                    <h5 style="font-size: 0.92rem; text-transform: uppercase; color: #fff; margin-bottom: 6px; letter-spacing: 0.05em; font-weight: 700;">Scan & Pay via UPI</h5>
                    <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 14px;">Scan using Google Pay, PhonePe, Paytm, BHIM, or any UPI banking app.</p>
                    <?php if (!empty($status['upi_qr_path'])): ?>
                        <div style="display: inline-block; background: #ffffff; padding: 10px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.5);">
                            <img src="<?= htmlspecialchars($status['upi_qr_path']) ?>" alt="UPI QR Code" style="width: 180px; height: 180px; object-fit: contain; display: block; border-radius: 6px;">
                        </div>
                    <?php else: ?>
                        <div style="background: rgba(255,255,255,0.05); border: 2px dashed rgba(255,255,255,0.15); border-radius: 12px; padding: 20px; max-width: 300px; margin: 0 auto;">
                            <p style="font-size: 1.8rem; margin-bottom: 6px;">📷</p>
                            <p style="font-size: 0.92rem; font-weight: 700; color: #cbd5e1;">UPI Payment QR Code</p>
                            <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 4px;">Contact developer on WhatsApp to receive the payment QR code.</p>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top: 12px; display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.06); padding: 6px 14px; border-radius: 999px; font-size: 0.82rem; color: #cbd5e1;">
                        <span>Amount Due:</span>
                        <strong style="color: #f59e0b; font-size: 0.95rem;"><?= $formattedFee ?></strong>
                    </div>
                </div>

                <!-- WhatsApp Screenshot Prompt -->
                <div style="background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.35); border-radius: 10px; padding: 16px; margin-bottom: 20px;">
                    <p style="font-weight: 700; color: #4ade80; font-size: 0.94rem; margin-bottom: 4px;">📲 Verification: Send Screenshot on WhatsApp</p>
                    <p style="font-size: 0.88rem; color: #e2e8f0; line-height: 1.4; margin-bottom: 12px;">
                        After making the payment, please send the payment screenshot on WhatsApp to <strong>+91 84299 39031</strong> for immediate activation and license renewal.
                    </p>
                    <a href="https://wa.me/<?= $cleanPhone ?>?text=Hello%2C%20I%20have%20paid%20the%20ERP%20Yearly%20Maintenance.%20Please%20find%20attached%20payment%20screenshot%20for%20verification." target="_blank" style="display: inline-flex; align-items: center; gap: 8px; background: #22c55e; color: #0b111e; font-weight: 700; font-size: 0.88rem; text-decoration: none; padding: 9px 16px; border-radius: 6px; box-shadow: 0 4px 12px rgba(34,197,94,0.3);">
                        <span>💬</span> Send Screenshot to +91 84299 39031
                    </a>
                </div>

                <!-- Activation Key Input -->
                <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 16px;">
                    <p style="font-weight: 600; font-size: 0.9rem; margin-bottom: 4px; color: #e2e8f0;">Already received an activation code from vendor?</p>
                    <p style="font-size: 0.82rem; color: #94a3b8; margin-bottom: 10px;">Enter your renewal key to immediately extend your ERP license for 1 year:</p>
                    <form method="post" style="display: flex; gap: 8px;">
                        <input type="hidden" name="maintenance_unlock_submit" value="1">
                        <input type="hidden" name="unlock_mode" value="renew_1year">
                        <input type="text" name="unlock_key_or_pass" required placeholder="Enter key (e.g. AMC-2026-...)" style="flex: 1; background: #0b111e; border: 1px solid rgba(255,255,255,0.18); color: #fff; padding: 10px 12px; border-radius: 6px; font-family: monospace;">
                        <button type="submit" style="background: #c2954f; color: #0b111e; font-weight: 700; border: none; border-radius: 6px; padding: 10px 16px; cursor: pointer;">Validate & Renew</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openMaintenancePayModal() {
            const m = document.getElementById('erp-maint-pay-modal');
            if (m) m.style.display = 'flex';
        }
        function closeMaintenancePayModal() {
            const m = document.getElementById('erp-maint-pay-modal');
            if (m) m.style.display = 'none';
        }
    </script>
    <?php
    return ob_get_clean();
}
