<?php
/**
 * Cron Job: Daily Yearly Maintenance Alert Dispatcher
 * 
 * Scheduled command for Hostinger Cron Jobs (run once daily at e.g. 09:00 AM):
 * /usr/bin/php /home/USER/domains/DOMAIN/public_html/cron/maintenance_alerts.php
 */

if (php_sapi_name() !== 'cli' && empty($_GET['cron_key'])) {
    // If accessed via web, require developer key or reject
    http_response_code(403);
    die("Access denied. Please run from CLI cron or provide valid authorization.");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/maintenance_helper.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting School ERP Yearly Maintenance Alert Check...\n";

try {
    ensure_maintenance_table_exists($pdo);
    $status = get_maintenance_status($pdo, true);

    echo "Status: " . $status['status_code'] . " | Due Date: " . $status['due_date'] . " | Days Remaining: " . $status['days_remaining'] . "\n";

    $result = check_and_send_maintenance_alerts($pdo, true);

    echo "Alert check completed. Emails dispatched: " . $result['sent_count'] . "\n";
    if (!empty($result['results'])) {
        foreach ($result['results'] as $r) {
            echo "  - Milestone " . $r['day'] . " Days: " . ($r['success'] ? 'SUCCESS' : 'FAILED (' . $r['message'] . ')') . "\n";
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Finished successfully.\n";
    exit(0);
} catch (Throwable $t) {
    echo "ERROR during maintenance cron check: " . $t->getMessage() . "\n";
    exit(1);
}
