<?php
/**
 * Cron Job: Daily Yearly Maintenance Alert Dispatcher
 * 
 * Scheduled command for Hostinger Cron Jobs (e.g. at 00:00 or 09:00 AM daily):
 * /usr/bin/php /home/u971377821/domains/erp.unityiti.edu.in/public_html/itierp/cron/maintenance_alerts.php
 */

$isCli = (php_sapi_name() === 'cli');
$cronSecret = 'ItierpSecretBackupToken2026';

if (!$isCli) {
    $providedKey = $_GET['cron_key'] ?? $_GET['token'] ?? '';
    if (!hash_equals($cronSecret, (string)$providedKey)) {
        http_response_code(403);
        die("Access denied. Please run from CLI cron or visit with ?cron_key=" . htmlspecialchars($cronSecret));
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/maintenance_helper.php';

function maint_cron_log(string $msg): void
{
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
    echo $line;
    @file_put_contents(__DIR__ . '/maintenance_cron.log', $line, FILE_APPEND);
}

maint_cron_log("Starting School ERP Yearly Maintenance Alert Check...");

try {
    ensure_maintenance_table_exists($pdo);
    $status = get_maintenance_status($pdo, true);
    $rec = get_maintenance_record($pdo, true);
    $developerEmail = !empty($rec['developer_alert_email']) ? trim($rec['developer_alert_email']) : 'razaainaan@gmail.com';

    maint_cron_log("Current Status: " . strtoupper($status['status_code']) . " | Due Date: " . $status['due_date_formatted'] . " | Days Remaining: " . $status['days_remaining']);
    maint_cron_log("Target Developer Recipient: " . $developerEmail);

    // If test requested via ?test=1
    if (isset($_GET['test']) && $_GET['test'] == '1') {
        maint_cron_log("Test mode requested: Dispatching immediate test alert to " . $developerEmail . "...");
        $testResult = send_test_maintenance_alert($pdo, $developerEmail);
        maint_cron_log("Test Alert Result: " . ($testResult['success'] ? 'SUCCESS' : 'FAILED') . " - " . $testResult['message']);
        echo "\n>>> STATUS: TEST COMPLETED\n";
        exit(0);
    }

    $result = check_and_send_maintenance_alerts($pdo, true);

    maint_cron_log("Alert check evaluation completed. Emails dispatched: " . $result['sent_count']);
    if (!empty($result['results'])) {
        foreach ($result['results'] as $r) {
            maint_cron_log("  -> Milestone " . $r['day'] . " Days: " . ($r['success'] ? 'SUCCESS' : 'FAILED (' . $r['message'] . ')'));
        }
    } else {
        if ((int)$status['days_remaining'] > 30) {
            maint_cron_log("  -> Note: No emails sent today because due date is " . $status['days_remaining'] . " days away (Milestone window starts at 30 days).");
        } else {
            maint_cron_log("  -> Note: Alerts for active milestone have already been dispatched or system is unlocked.");
        }
    }

    maint_cron_log("Cron job finished successfully.");
    echo "\n>>> STATUS: SUCCESS\n";
    exit(0);
} catch (Throwable $t) {
    maint_cron_log("ERROR during maintenance cron check: " . $t->getMessage());
    echo "\n>>> STATUS: FAILED\n";
    exit(1);
}
