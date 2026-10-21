<?php
require_once __DIR__ . '/_auth.php';
developer_require_login();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/maintenance_helper.php';

$errors = [];
$success = [];

ensure_maintenance_table_exists($pdo);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Quick Action: 1-Year Renewal
    if ($action === 'quick_renew_1year') {
        $amount = (float)($_POST['amount'] ?? 15000.00);
        $method = trim((string)($_POST['method'] ?? 'Bank Transfer'));
        $ref = trim((string)($_POST['reference'] ?? ('PAY-' . strtoupper(substr(md5(uniqid()), 0, 8)))));
        $notes = trim((string)($_POST['notes'] ?? 'Annual Maintenance Contract renewed for 1 Year by Developer.'));

        $rec = get_maintenance_record($pdo, true);
        $currentDue = $rec && $rec['due_date'] ? new DateTime($rec['due_date']) : new DateTime('today');
        $today = new DateTime('today');
        
        // If current due date is in future, add 1 year to that due date; otherwise add 1 year from today
        $baseDate = ($currentDue > $today) ? $currentDue : $today;
        $newDueDate = (clone $baseDate)->modify('+1 year')->format('Y-m-d');

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                UPDATE system_maintenance SET 
                    status = 'paid',
                    due_date = :due_date,
                    last_payment_date = :paid_date,
                    last_payment_reference = :ref,
                    lock_status = 'auto',
                    updated_at = NOW()
                WHERE id = 1
            ");
            $stmt->execute([
                'due_date' => $newDueDate,
                'paid_date' => $today->format('Y-m-d'),
                'ref' => $ref,
            ]);

            $logStmt = $pdo->prepare("
                INSERT INTO maintenance_payment_history (
                    maintenance_id, payment_year, amount_paid, paid_date, payment_method, reference_no, extended_until, notes
                ) VALUES (
                    1, :payment_year, :amount, :paid_date, :method, :ref, :extended_until, :notes
                )
            ");
            $logStmt->execute([
                'payment_year' => date('Y') . '-' . (date('Y') + 1),
                'amount' => $amount,
                'paid_date' => $today->format('Y-m-d'),
                'method' => $method,
                'ref' => $ref,
                'extended_until' => $newDueDate,
                'notes' => $notes,
            ]);

            $pdo->commit();
            $success[] = "ERP Maintenance successfully renewed for 1 Year until " . date('d M, Y', strtotime($newDueDate)) . "!";
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "Error renewing maintenance: " . $t->getMessage();
        }
    }

    // Quick Action: 30-Day Extension
    if ($action === 'quick_extend_30days') {
        $today = new DateTime('today');
        $newDueDate = (clone $today)->modify('+30 days')->format('Y-m-d');
        try {
            $stmt = $pdo->prepare("
                UPDATE system_maintenance SET 
                    due_date = :due_date,
                    lock_status = 'auto',
                    updated_at = NOW()
                WHERE id = 1
            ");
            $stmt->execute(['due_date' => $newDueDate]);

            $logStmt = $pdo->prepare("
                INSERT INTO maintenance_payment_history (
                    maintenance_id, payment_year, amount_paid, paid_date, payment_method, reference_no, extended_until, notes
                ) VALUES (
                    1, :payment_year, 0.00, :paid_date, 'Grace Extension', 'EXT-30D', :extended_until, 'Emergency 30-day grace extension granted.'
                )
            ");
            $logStmt->execute([
                'payment_year' => date('Y') . '-' . (date('Y') + 1),
                'paid_date' => $today->format('Y-m-d'),
                'extended_until' => $newDueDate,
            ]);

            $success[] = "30-Day Emergency Extension granted until " . date('d M, Y', strtotime($newDueDate)) . ".";
        } catch (Throwable $t) {
            $errors[] = "Error extending: " . $t->getMessage();
        }
    }

    // Quick Action: Lock Override Toggle
    if ($action === 'set_lock_override') {
        $mode = $_POST['lock_mode'] ?? 'auto';
        if (in_array($mode, ['auto', 'force_locked', 'force_unlocked'], true)) {
            $stmt = $pdo->prepare("UPDATE system_maintenance SET lock_status = :m, updated_at = NOW() WHERE id = 1");
            $stmt->execute(['m' => $mode]);
            $success[] = "System Lock override mode updated to: <strong>" . strtoupper($mode) . "</strong>.";
        }
    }

    // Save All Maintenance Settings
    if ($action === 'save_maintenance_settings') {
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        $status = $_POST['status'] ?? 'paid';
        $annualFee = (float)($_POST['annual_fee'] ?? 0);
        $currency = trim((string)($_POST['currency'] ?? 'INR'));
        $dueDate = $_POST['due_date'] ?? date('Y-m-d');
        $graceDays = max(0, (int)($_POST['grace_period_days'] ?? 7));
        $warningDays = max(1, (int)($_POST['warning_days'] ?? 30));
        $lockStatus = $_POST['lock_status'] ?? 'auto';
        $alertTitle = trim((string)($_POST['alert_title'] ?? 'Annual ERP Maintenance Due'));
        $alertMessage = trim((string)($_POST['alert_message'] ?? ''));
        $lockTitle = trim((string)($_POST['lock_title'] ?? 'ERP System Access Suspended'));
        $lockMessage = trim((string)($_POST['lock_message'] ?? ''));
        $contactPerson = trim((string)($_POST['contact_person'] ?? ''));
        $contactPhone = trim((string)($_POST['contact_phone'] ?? ''));
        $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
        $paymentInstructions = trim((string)($_POST['payment_instructions'] ?? ''));
        $secretKey = trim((string)($_POST['secret_unlock_key'] ?? ''));

        try {
            $updateFields = [
                'is_enabled' => $isEnabled,
                'status' => $status,
                'annual_fee' => $annualFee,
                'currency' => $currency,
                'due_date' => $dueDate,
                'grace_period_days' => $graceDays,
                'warning_days' => $warningDays,
                'lock_status' => $lockStatus,
                'alert_title' => $alertTitle,
                'alert_message' => $alertMessage,
                'lock_title' => $lockTitle,
                'lock_message' => $lockMessage,
                'contact_person' => $contactPerson,
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail,
                'payment_instructions' => $paymentInstructions,
            ];

            $sql = "UPDATE system_maintenance SET 
                is_enabled = :is_enabled,
                status = :status,
                annual_fee = :annual_fee,
                currency = :currency,
                due_date = :due_date,
                grace_period_days = :grace_period_days,
                warning_days = :warning_days,
                lock_status = :lock_status,
                alert_title = :alert_title,
                alert_message = :alert_message,
                lock_title = :lock_title,
                lock_message = :lock_message,
                contact_person = :contact_person,
                contact_phone = :contact_phone,
                contact_email = :contact_email,
                payment_instructions = :payment_instructions,
                updated_at = NOW()";

            if ($secretKey !== '') {
                $sql .= ", secret_unlock_hash = :secret_hash";
                $updateFields['secret_hash'] = password_hash($secretKey, PASSWORD_DEFAULT);
            }

            // Handle UPI QR Code Upload
            if (isset($_POST['remove_upi_qr']) && $_POST['remove_upi_qr'] == '1') {
                $sql = str_replace("updated_at = NOW()", "upi_qr_path = NULL, updated_at = NOW()", $sql);
            } elseif (isset($_FILES['upi_qr_image']) && $_FILES['upi_qr_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/maintenance/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fileInfo = pathinfo($_FILES['upi_qr_image']['name']);
                $ext = strtolower($fileInfo['extension'] ?? 'png');
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
                if (in_array($ext, $allowedExts, true)) {
                    $filename = 'upi_qr_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $targetPath = $uploadDir . $filename;
                    if (move_uploaded_file($_FILES['upi_qr_image']['tmp_name'], $targetPath)) {
                        $sql = str_replace("updated_at = NOW()", "upi_qr_path = :upi_qr_path, updated_at = NOW()", $sql);
                        $updateFields['upi_qr_path'] = '/itierp/uploads/maintenance/' . $filename;
                    }
                }
            }

            $sql .= " WHERE id = 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateFields);

            $success[] = "Yearly Maintenance settings successfully saved!";
        } catch (Throwable $t) {
            $errors[] = "Error saving settings: " . $t->getMessage();
        }
    }

    // Add Manual Payment Record
    if ($action === 'add_payment_history') {
        $year = trim((string)($_POST['payment_year'] ?? date('Y')));
        $amount = (float)($_POST['amount_paid'] ?? 0);
        $paidDate = $_POST['paid_date'] ?? date('Y-m-d');
        $method = trim((string)($_POST['payment_method'] ?? 'Bank Transfer'));
        $ref = trim((string)($_POST['reference_no'] ?? ''));
        $extUntil = $_POST['extended_until'] ?? date('Y-m-d', strtotime('+1 year'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        try {
            $stmt = $pdo->prepare("
                INSERT INTO maintenance_payment_history (
                    maintenance_id, payment_year, amount_paid, paid_date, payment_method, reference_no, extended_until, notes
                ) VALUES (
                    1, :py, :amt, :pd, :pm, :rn, :eu, :nt
                )
            ");
            $stmt->execute([
                'py' => $year,
                'amt' => $amount,
                'pd' => $paidDate,
                'pm' => $method,
                'rn' => $ref,
                'eu' => $extUntil,
                'nt' => $notes,
            ]);
            $success[] = "Payment history record added successfully.";
        } catch (Throwable $t) {
            $errors[] = "Error logging payment: " . $t->getMessage();
        }
    }

    // Delete Payment Record
    if ($action === 'delete_payment_history') {
        $historyId = (int)($_POST['history_id'] ?? 0);
        if ($historyId > 0) {
            $stmt = $pdo->prepare("DELETE FROM maintenance_payment_history WHERE id = :id");
            $stmt->execute(['id' => $historyId]);
            $success[] = "History entry deleted.";
        }
    }

    // Save Developer Email & Hostinger SMTP Settings
    if ($action === 'save_email_smtp_settings') {
        $devEmail = trim((string)($_POST['developer_alert_email'] ?? 'razaainaan@gmail.com'));
        $smtpEnabled = isset($_POST['smtp_enabled']) ? 1 : 0;
        $smtpHost = trim((string)($_POST['smtp_host'] ?? 'smtp.hostinger.com'));
        $smtpPort = (int)($_POST['smtp_port'] ?? 465);
        $smtpEnc = trim((string)($_POST['smtp_encryption'] ?? 'ssl'));
        $smtpUser = trim((string)($_POST['smtp_username'] ?? ''));
        $smtpPass = (string)($_POST['smtp_password'] ?? '');
        $smtpFromEmail = trim((string)($_POST['smtp_from_email'] ?? ''));
        $smtpFromName = trim((string)($_POST['smtp_from_name'] ?? 'School ERP System'));

        try {
            // Keep existing password if left blank on edit
            if ($smtpPass === '') {
                $curStmt = $pdo->query("SELECT smtp_password FROM system_maintenance WHERE id = 1");
                $smtpPass = (string)($curStmt->fetchColumn() ?: '');
            }

            $stmt = $pdo->prepare("
                UPDATE system_maintenance SET 
                    developer_alert_email = :dev_email,
                    smtp_enabled = :smtp_enabled,
                    smtp_host = :smtp_host,
                    smtp_port = :smtp_port,
                    smtp_encryption = :smtp_enc,
                    smtp_username = :smtp_user,
                    smtp_password = :smtp_pass,
                    smtp_from_email = :smtp_from_email,
                    smtp_from_name = :smtp_from_name,
                    updated_at = NOW()
                WHERE id = 1
            ");
            $stmt->execute([
                'dev_email' => $devEmail,
                'smtp_enabled' => $smtpEnabled,
                'smtp_host' => $smtpHost,
                'smtp_port' => $smtpPort,
                'smtp_enc' => $smtpEnc,
                'smtp_user' => $smtpUser,
                'smtp_pass' => $smtpPass,
                'smtp_from_email' => $smtpFromEmail,
                'smtp_from_name' => $smtpFromName,
            ]);

            $success[] = "Developer Email & Hostinger SMTP settings saved successfully!";
        } catch (Throwable $t) {
            $errors[] = "Error saving email settings: " . $t->getMessage();
        }
    }

    // Send Test Email Action
    if ($action === 'send_test_email') {
        $testTarget = trim((string)($_POST['test_email_recipient'] ?? 'razaainaan@gmail.com'));
        $testRes = send_test_maintenance_alert($pdo, $testTarget);
        if ($testRes['success']) {
            $success[] = "Test email sent successfully to <strong>" . htmlspecialchars($testTarget) . "</strong>! (" . htmlspecialchars($testRes['message']) . ")";
        } else {
            $errors[] = "Test email failed: " . htmlspecialchars($testRes['message']);
        }
    }

    // Trigger Manual Alert Dispatch Check
    if ($action === 'trigger_manual_alerts_check') {
        $alertRes = check_and_send_maintenance_alerts($pdo, true);
        if ($alertRes['sent_count'] > 0) {
            $success[] = "Maintenance alert check ran: <strong>" . $alertRes['sent_count'] . " email(s) dispatched</strong> to " . htmlspecialchars($maintRec['developer_alert_email'] ?? 'razaainaan@gmail.com') . ".";
        } else {
            $success[] = "Maintenance alert check ran: No milestone alerts pending for today (Days remaining: " . ($maintStatus['days_remaining'] ?? 0) . ").";
        }
    }
}

// Fetch live record and status
$maintStatus = get_maintenance_status($pdo, true);
$maintRec = get_maintenance_record($pdo, true);

// Fetch Payment History
$historyRows = [];
try {
    $hStmt = $pdo->query("SELECT * FROM maintenance_payment_history ORDER BY id DESC LIMIT 50");
    $historyRows = $hStmt->fetchAll();
} catch (Throwable $t) {}

// Fetch Alert Logs
$alertLogs = [];
try {
    $aStmt = $pdo->query("SELECT * FROM maintenance_alert_logs ORDER BY id DESC LIMIT 50");
    $alertLogs = $aStmt->fetchAll();
} catch (Throwable $t) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer - Yearly Maintenance & Auto-Lock Control</title>
    <link rel="icon" type="image/png" href="/itierp/assets/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/itierp/assets/css/style.css?v=<?= time() ?>">
    <style>
        .dev-maint-header {
            background: linear-gradient(135deg, #0e172a 0%, #1e293b 100%);
            color: #fff;
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: var(--shadow-md);
        }
        .dev-badge-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.92rem;
            letter-spacing: 0.03em;
        }
        .status-ok { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid rgba(74, 222, 128, 0.4); }
        .status-warn { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.4); }
        .status-urgent { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.4); animation: pulseAlert 1.5s infinite; }
        .status-locked { background: #991b1b; color: #fff; border: 1px solid #ef4444; }

        @keyframes pulseAlert {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.85; transform: scale(1.02); }
        }

        .action-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surface);
            padding: 20px;
            border-radius: 14px;
            border: 1px solid var(--outline-variant);
            box-shadow: var(--shadow-sm);
        }
        .stat-card .label {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--on-surface-variant);
            margin-bottom: 4px;
        }
        .stat-card .value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--on-surface);
        }

        .btn-quick {
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .btn-quick-primary { background: var(--secondary); color: #fff; }
        .btn-quick-primary:hover { background: #162c57; }
        .btn-quick-success { background: #15803d; color: #fff; }
        .btn-quick-success:hover { background: #166534; }
        .btn-quick-danger { background: #b91c1c; color: #fff; }
        .btn-quick-danger:hover { background: #991b1b; }
        .btn-quick-warning { background: #d97706; color: #fff; }
        .btn-quick-warning:hover { background: #b45309; }

        .tabs-header {
            display: flex;
            gap: 12px;
            border-bottom: 2px solid var(--outline-variant);
            margin-bottom: 24px;
        }
        .tab-btn {
            padding: 12px 20px;
            border: none;
            background: none;
            font-size: 1rem;
            font-weight: 700;
            color: var(--on-surface-variant);
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
        }
        .tab-btn.active {
            color: var(--secondary);
            border-bottom-color: var(--secondary);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body style="background: var(--background); padding: 24px;">
    <div style="max-width: 1200px; margin: 0 auto;">
        
        <!-- Top Navigation -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="/itierp/developer/portal.php" class="nav-item" style="display: inline-flex; align-items: center; gap: 6px;">
                    &larr; Back to Table Portal
                </a>
                <a href="/itierp/index.php" class="nav-item" target="_blank">View ERP Main &rarr;</a>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 0.88rem; color: var(--on-surface-variant);">Logged in as Developer</span>
                <a href="/itierp/developer/logout.php" class="nav-item" style="color: var(--error);">Logout</a>
            </div>
        </div>

        <!-- Banner / Header -->
        <div class="dev-maint-header">
            <div>
                <p style="text-transform: uppercase; letter-spacing: 0.1em; color: #94a3b8; font-size: 0.8rem; font-weight: 700; margin-bottom: 4px;">
                    ERP License & Infrastructure
                </p>
                <h1 style="color: #fff; font-size: 1.8rem; margin: 0;">Yearly Maintenance & Auto-Lock Control</h1>
                <p style="color: #cbd5e1; margin-top: 6px; font-size: 0.95rem;">
                    Configure Annual Maintenance Contract (AMC), set due dates, test lockouts, and generate client unlock tokens.
                </p>
            </div>
            <div>
                <?php if ($maintStatus['is_locked']): ?>
                    <span class="dev-badge-status status-locked">🔒 ERP IS LOCKED (OVERDUE)</span>
                <?php elseif ($maintStatus['status_code'] === 'grace_period'): ?>
                    <span class="dev-badge-status status-urgent">🚨 IN GRACE PERIOD (<?= $maintStatus['grace_days_left'] ?> DAYS LEFT)</span>
                <?php elseif ($maintStatus['is_warning']): ?>
                    <span class="dev-badge-status status-warn">⚠️ DUE IN <?= $maintStatus['days_remaining'] ?> DAYS</span>
                <?php else: ?>
                    <span class="dev-badge-status status-ok">✅ ACTIVE (DUE IN <?= $maintStatus['days_remaining'] ?> DAYS)</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="error" style="margin-bottom: 20px;">
                <?php foreach ($errors as $err): ?>
                    <p><?= htmlspecialchars($err) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="success" style="margin-bottom: 20px;">
                <?php foreach ($success as $msg): ?>
                    <p><?= $msg ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Key Metrics -->
        <div class="action-card-grid">
            <div class="stat-card">
                <div class="label">Current Due Date</div>
                <div class="value"><?= htmlspecialchars($maintStatus['due_date_formatted']) ?></div>
                <p style="margin-top: 4px; font-size: 0.85rem; color: var(--on-surface-variant);">
                    <?= $maintStatus['days_remaining'] >= 0 ? ($maintStatus['days_remaining'] . ' days remaining') : (abs($maintStatus['days_remaining']) . ' days overdue') ?>
                </p>
            </div>
            <div class="stat-card">
                <div class="label">Grace Deadline</div>
                <div class="value"><?= htmlspecialchars($maintStatus['grace_deadline_formatted']) ?></div>
                <p style="margin-top: 4px; font-size: 0.85rem; color: var(--on-surface-variant);">
                    Hard lockout triggers after this date
                </p>
            </div>
            <div class="stat-card">
                <div class="label">Annual Fee</div>
                <div class="value"><?= ($maintStatus['currency'] === 'INR' ? '₹' : $maintStatus['currency']) . ' ' . number_format($maintStatus['annual_fee'], 2) ?></div>
                <p style="margin-top: 4px; font-size: 0.85rem; color: var(--on-surface-variant);">
                    Status: <strong style="text-transform: uppercase;"><?= htmlspecialchars($maintRec['status'] ?? 'paid') ?></strong>
                </p>
            </div>
            <div class="stat-card">
                <div class="label">Lock Override Mode</div>
                <div class="value" style="font-size: 1.25rem; text-transform: uppercase;">
                    <?= htmlspecialchars($maintRec['lock_status'] ?? 'auto') ?>
                </div>
                <p style="margin-top: 4px; font-size: 0.85rem; color: var(--on-surface-variant);">
                    <?= ($maintRec['lock_status'] ?? '') === 'auto' ? 'Automated date check' : 'Manual override active' ?>
                </p>
            </div>
        </div>

        <!-- Quick Developer Actions Bar -->
        <section class="card" style="margin-bottom: 24px;">
            <h3>Quick Maintenance Actions</h3>
            <p style="margin-bottom: 16px;">Perform common renewal and testing operations with one click.</p>
            
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <!-- 1-Year Renewal Form -->
                <form method="post" style="display: inline;" onsubmit="return confirm('Renew Annual Maintenance for 1 Year (+365 days) from current due date?');">
                    <input type="hidden" name="action" value="quick_renew_1year">
                    <input type="hidden" name="amount" value="<?= htmlspecialchars((string)($maintRec['annual_fee'] ?? 15000.00)) ?>">
                    <button type="submit" class="btn-quick btn-quick-success">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"></path></svg>
                        Renew Maintenance for 1 Year (+365 Days)
                    </button>
                </form>

                <!-- 30-Day Extension Form -->
                <form method="post" style="display: inline;" onsubmit="return confirm('Grant 30 days emergency extension to allow client time to pay?');">
                    <input type="hidden" name="action" value="quick_extend_30days">
                    <button type="submit" class="btn-quick btn-quick-warning">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        Grant 30-Day Emergency Extension
                    </button>
                </form>

                <!-- Lock Override Form -->
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="set_lock_override">
                    <?php if (($maintRec['lock_status'] ?? '') === 'force_locked'): ?>
                        <input type="hidden" name="lock_mode" value="auto">
                        <button type="submit" class="btn-quick btn-quick-primary">
                            🔓 Remove Force Lock (Return to Auto)
                        </button>
                    <?php else: ?>
                        <input type="hidden" name="lock_mode" value="force_locked">
                        <button type="submit" class="btn-quick btn-quick-danger" onclick="return confirm('Immediately lock the ERP now (for testing or non-payment)?');">
                            🔒 Force Lock ERP Immediately (Simulate Lock)
                        </button>
                    <?php endif; ?>
                </form>

                <?php if (($maintRec['lock_status'] ?? '') !== 'force_unlocked'): ?>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="set_lock_override">
                        <input type="hidden" name="lock_mode" value="force_unlocked">
                        <button type="submit" class="btn-quick" style="background: #475569; color:#fff;" onclick="return confirm('Force unlock ERP regardless of due date?');">
                            Force Unlock (Bypass Date Checks)
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <!-- Tabs Navigation -->
        <div class="tabs-header">
            <button class="tab-btn active" onclick="switchTab('tab-settings', this)">AMC Configuration & Messages</button>
            <button class="tab-btn" onclick="switchTab('tab-history', this)">Payment & Renewal History (<?= count($historyRows) ?>)</button>
            <button class="tab-btn" onclick="switchTab('tab-email-alerts', this)">Developer Email Alerts (30, 15, 7, 1 Day) (<?= count($alertLogs) ?>)</button>
            <button class="tab-btn" onclick="switchTab('tab-token', this)">Generate Emergency Unlock Token</button>
        </div>

        <!-- Tab 1: AMC Configuration -->
        <div id="tab-settings" class="tab-content active">
            <section class="card">
                <h3>Yearly Maintenance Parameters & Notices</h3>
                <p style="margin-bottom: 20px;">Configure due date, pricing, grace periods, and customize lock & warning banners.</p>

                <form method="post" class="stacked-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_maintenance_settings">

                    <div style="background: var(--surface-container-highest); padding: 16px; border-radius: 10px; margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 700;">
                            <input type="checkbox" name="is_enabled" value="1" <?= !empty($maintRec['is_enabled']) ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                            Enable Automatic Yearly Maintenance Check & Auto-Lock System
                        </label>
                        <p style="font-size: 0.85rem; color: var(--on-surface-variant); margin-top: 4px; margin-left: 30px;">
                            When enabled, the ERP automatically checks due dates on every request. If unpaid and overdue past grace period, the ERP locks automatically.
                        </p>
                    </div>

                    <div class="grid-3">
                        <div>
                            <label>Payment Status</label>
                            <select name="status" required>
                                <option value="paid" <?= ($maintRec['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Paid (Active License)</option>
                                <option value="pending" <?= ($maintRec['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending / Due</option>
                                <option value="overdue" <?= ($maintRec['status'] ?? '') === 'overdue' ? 'selected' : '' ?>>Overdue (Non-Payment)</option>
                            </select>
                        </div>
                        <div>
                            <label>Maintenance Due Date</label>
                            <input type="date" name="due_date" value="<?= htmlspecialchars((string)($maintRec['due_date'] ?? date('Y-m-d'))) ?>" required>
                        </div>
                        <div>
                            <label>Lock Enforcement Mode</label>
                            <select name="lock_status">
                                <option value="auto" <?= ($maintRec['lock_status'] ?? '') === 'auto' ? 'selected' : '' ?>>Auto (Enforce based on Due Date)</option>
                                <option value="force_locked" <?= ($maintRec['lock_status'] ?? '') === 'force_locked' ? 'selected' : '' ?>>Force Locked (Always Locked)</option>
                                <option value="force_unlocked" <?= ($maintRec['lock_status'] ?? '') === 'force_unlocked' ? 'selected' : '' ?>>Force Unlocked (Temporary Bypass)</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid-3">
                        <div>
                            <label>Annual Maintenance Fee</label>
                            <input type="number" step="0.01" name="annual_fee" value="<?= htmlspecialchars((string)($maintRec['annual_fee'] ?? '15000.00')) ?>" required>
                        </div>
                        <div>
                            <label>Currency Symbol / Code</label>
                            <input type="text" name="currency" value="<?= htmlspecialchars((string)($maintRec['currency'] ?? 'INR')) ?>" required placeholder="e.g. INR or ₹">
                        </div>
                        <div>
                            <label>Grace Period (Days)</label>
                            <input type="number" name="grace_period_days" value="<?= (int)($maintRec['grace_period_days'] ?? 7) ?>" required>
                            <small style="color: var(--on-surface-variant);">Extra days allowed before system locks.</small>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div>
                            <label>Warning Alert Days (Before Due Date)</label>
                            <input type="number" name="warning_days" value="<?= (int)($maintRec['warning_days'] ?? 30) ?>" required>
                            <small style="color: var(--on-surface-variant);">How many days prior to due date to show the top warning banner.</small>
                        </div>
                        <div>
                            <label>Custom Secret Unlock Token (Optional)</label>
                            <input type="text" name="secret_unlock_key" placeholder="Enter key to update, or leave blank to keep current">
                            <small style="color: var(--on-surface-variant);">Allows unlocking via key on lock screen. (Master Key: DevPortal password)</small>
                        </div>
                    </div>

                    <hr style="margin: 24px 0; border: none; border-top: 1px solid var(--outline-variant);">

                    <h4 style="margin-bottom: 12px;">Alert & Lock Screen Content</h4>

                    <div class="grid-2">
                        <div>
                            <label>Warning Banner Title</label>
                            <input type="text" name="alert_title" value="<?= htmlspecialchars((string)($maintRec['alert_title'] ?? 'Annual ERP Maintenance Due')) ?>" required>
                        </div>
                        <div>
                            <label>Lockout Page Title</label>
                            <input type="text" name="lock_title" value="<?= htmlspecialchars((string)($maintRec['lock_title'] ?? 'ERP System Access Suspended - Maintenance Overdue')) ?>" required>
                        </div>
                    </div>

                    <div>
                        <label>Lockout Page Detailed Message</label>
                        <textarea name="lock_message" rows="3"><?= htmlspecialchars((string)($maintRec['lock_message'] ?? '')) ?></textarea>
                    </div>

                    <hr style="margin: 24px 0; border: none; border-top: 1px solid var(--outline-variant);">

                    <h4 style="margin-bottom: 12px;">Technical Support & UPI Payment QR Code (Shown to Client)</h4>

                    <div class="grid-3">
                        <div>
                            <label>Support Contact Person / Team</label>
                            <input type="text" name="contact_person" value="<?= htmlspecialchars((string)($maintRec['contact_person'] ?? 'ERP Technical Support & Vendor Team')) ?>" required>
                        </div>
                        <div>
                            <label>Support Phone / WhatsApp</label>
                            <input type="text" name="contact_phone" value="<?= htmlspecialchars((string)($maintRec['contact_phone'] ?? '+91 84299 39031')) ?>" required>
                        </div>
                        <div>
                            <label>Support Email Address</label>
                            <input type="email" name="contact_email" value="<?= htmlspecialchars((string)($maintRec['contact_email'] ?? 'support@erptech.com')) ?>" required>
                        </div>
                    </div>

                    <!-- UPI QR Code Upload Box -->
                    <div style="background: var(--surface-container-highest); padding: 18px; border-radius: 12px; margin-top: 16px; border: 1px dashed var(--outline-variant);">
                        <label style="font-weight: 700; display: block; margin-bottom: 6px; font-size: 0.95rem;">
                            📷 Upload UPI QR Code Image (GPay / PhonePe / Paytm / BHIM)
                        </label>
                        <input type="file" name="upi_qr_image" accept="image/*">
                        <small style="display: block; color: var(--on-surface-variant); margin-top: 6px; font-size: 0.85rem;">
                            Upload your UPI QR code (JPG, PNG, WebP). This will be displayed on the locked screen and in Super Admin settings so the school can scan and pay.
                        </small>

                        <?php if (!empty($maintRec['upi_qr_path'])): ?>
                            <div style="margin-top: 14px; display: flex; align-items: center; gap: 16px; background: #fff; padding: 12px 16px; border-radius: 8px; border: 1px solid var(--outline-variant); width: fit-content;">
                                <img src="<?= htmlspecialchars((string)$maintRec['upi_qr_path']) ?>" alt="UPI QR Code" style="height: 110px; width: 110px; object-fit: contain;">
                                <div>
                                    <p style="font-weight: 700; color: #0b111e; font-size: 0.9rem;">Active QR Code</p>
                                    <p style="font-size: 0.78rem; color: #64748b; margin-bottom: 8px;"><?= htmlspecialchars((string)$maintRec['upi_qr_path']) ?></p>
                                    <label style="color: var(--error); font-size: 0.85rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                        <input type="checkbox" name="remove_upi_qr" value="1"> Remove this QR Code
                                    </label>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- WhatsApp Screenshot Notice -->
                    <div style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.35); border-radius: 10px; padding: 16px; margin-top: 16px;">
                        <p style="font-weight: 700; color: #15803d; font-size: 0.95rem; margin-bottom: 4px;">📲 After-Payment WhatsApp Screenshot Verification</p>
                        <p style="font-size: 0.88rem; color: var(--on-surface); line-height: 1.5;">
                            The ERP lock screen and Admin settings display a direct prompt: <em>"After making the payment, please send the payment screenshot on WhatsApp to <strong>+91 84299 39031</strong> for immediate activation."</em> with a 1-click WhatsApp message button.
                        </p>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" style="padding: 12px 24px; font-weight: 700; font-size: 1rem;">
                            Save All Maintenance Settings
                        </button>
                    </div>
                </form>
            </section>
        </div>

        <!-- Tab 2: Payment History -->
        <div id="tab-history" class="tab-content">
            <section class="card" style="margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3>Annual Maintenance Payment History</h3>
                    <button type="button" class="btn-toggle-form" onclick="document.getElementById('form-add-history').classList.toggle('active')">
                        + Add Manual Payment Log
                    </button>
                </div>

                <!-- Add Payment Form -->
                <div class="collapsible-form" id="form-add-history" style="background: var(--surface-container-highest); padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                    <form method="post" class="stacked-form">
                        <input type="hidden" name="action" value="add_payment_history">
                        <div class="grid-3">
                            <div>
                                <label>Academic / Financial Year</label>
                                <input type="text" name="payment_year" value="<?= date('Y') . '-' . (date('Y') + 1) ?>" required>
                            </div>
                            <div>
                                <label>Amount Paid</label>
                                <input type="number" step="0.01" name="amount_paid" value="15000.00" required>
                            </div>
                            <div>
                                <label>Payment Date</label>
                                <input type="date" name="paid_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>

                        <div class="grid-3">
                            <div>
                                <label>Payment Method</label>
                                <input type="text" name="payment_method" value="Bank Transfer" required placeholder="e.g. UPI, Cheque, NEFT">
                            </div>
                            <div>
                                <label>Transaction / Reference No</label>
                                <input type="text" name="reference_no" placeholder="e.g. TXN987654321">
                            </div>
                            <div>
                                <label>License Extended Until</label>
                                <input type="date" name="extended_until" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required>
                            </div>
                        </div>

                        <div>
                            <label>Notes / Remarks</label>
                            <input type="text" name="notes" placeholder="e.g. Full annual AMC payment cleared by school.">
                        </div>

                        <button type="submit" style="margin-top: 10px;">Save Payment Record</button>
                    </form>
                </div>

                <div class="table-wrap">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th>Amount Paid</th>
                                <th>Payment Date</th>
                                <th>Method</th>
                                <th>Reference No</th>
                                <th>Extended Until</th>
                                <th>Notes</th>
                                <th>Logged At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historyRows as $hist): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($hist['payment_year']) ?></strong></td>
                                    <td><?= htmlspecialchars($maintRec['currency'] ?? 'INR') ?> <?= number_format((float)$hist['amount_paid'], 2) ?></td>
                                    <td><?= htmlspecialchars($hist['paid_date']) ?></td>
                                    <td><?= htmlspecialchars($hist['payment_method']) ?></td>
                                    <td><code><?= htmlspecialchars($hist['reference_no'] ?: '-') ?></code></td>
                                    <td><?= htmlspecialchars($hist['extended_until']) ?></td>
                                    <td><?= htmlspecialchars($hist['notes'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($hist['created_at']))) ?></td>
                                    <td>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this log entry?');">
                                            <input type="hidden" name="action" value="delete_payment_history">
                                            <input type="hidden" name="history_id" value="<?= $hist['id'] ?>">
                                            <button type="submit" class="btn-compact" style="color: var(--error);">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($historyRows)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; color: var(--on-surface-variant); padding: 24px;">
                                        No maintenance renewal records logged yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Tab 3: Developer Email Alerts & Hostinger Settings -->
        <div id="tab-email-alerts" class="tab-content">
            <!-- Email Status & Test Section -->
            <section class="card" style="margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                    <div>
                        <h3 style="margin: 0;">Developer Maintenance Email Alerts</h3>
                        <p style="margin-top: 4px; color: var(--on-surface-variant); font-size: 0.92rem;">
                            Automated reminders sent to developer at <strong>30, 15, 7, and 1 day</strong> before due date.
                        </p>
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="action" value="trigger_manual_alerts_check">
                            <button type="submit" class="btn-quick btn-quick-warning" title="Manually scan if any 30/15/7/1 day alert needs sending today">
                                🔄 Run Alert Check Now
                            </button>
                        </form>
                    </div>
                </div>

                <div class="grid-3" style="margin-bottom: 20px;">
                    <div style="background: var(--surface-container-highest); padding: 16px; border-radius: 10px;">
                        <span style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--on-surface-variant); display: block;">Recipient Email</span>
                        <strong style="color: #60a5fa; font-size: 1.05rem; word-break: break-all;">
                            <?= htmlspecialchars((string)($maintRec['developer_alert_email'] ?? 'razaainaan@gmail.com')) ?>
                        </strong>
                        <p style="font-size: 0.8rem; color: var(--on-surface-variant); margin-top: 4px;">Primary developer alert destination</p>
                    </div>
                    <div style="background: var(--surface-container-highest); padding: 16px; border-radius: 10px;">
                        <span style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--on-surface-variant); display: block;">Scheduled Milestones</span>
                        <strong style="color: #f59e0b; font-size: 1.05rem;">30, 15, 7, and 1 Day</strong>
                        <p style="font-size: 0.8rem; color: var(--on-surface-variant); margin-top: 4px;">Sent only once per milestone cycle</p>
                    </div>
                    <div style="background: var(--surface-container-highest); padding: 16px; border-radius: 10px;">
                        <span style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--on-surface-variant); display: block;">Hostinger Anti-Block Status</span>
                        <strong style="color: #22c55e; font-size: 1.05rem;">
                            <?= !empty($maintRec['smtp_enabled']) ? '🛡️ Authenticated SMTP (Hostinger)' : '🛡️ Envelope -f Hardened Mail' ?>
                        </strong>
                        <p style="font-size: 0.8rem; color: var(--on-surface-variant); margin-top: 4px;">Bypasses Hostinger spam & relay filters</p>
                    </div>
                </div>

                <!-- Instant Test Email Box -->
                <div style="background: #0f172a; border: 1px solid rgba(255,255,255,0.12); padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 6px 0; color: #fff; font-size: 1rem;">📨 Send Immediate Test Email to Developer</h4>
                    <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 14px;">
                        Dispatch a live HTML test email immediately to verify that Hostinger is delivering messages to <strong>razaainaan@gmail.com</strong> without blocking.
                    </p>
                    <form method="post" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <input type="hidden" name="action" value="send_test_email">
                        <input type="email" name="test_email_recipient" value="<?= htmlspecialchars((string)($maintRec['developer_alert_email'] ?? 'razaainaan@gmail.com')) ?>" required style="flex: 1; min-width: 260px; background: #1e293b; color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; padding: 10px 14px;">
                        <button type="submit" class="btn-quick btn-quick-primary" style="padding: 10px 20px;">
                            🚀 Send Test Email Now
                        </button>
                    </form>
                </div>

                <!-- Hostinger Guide Banner -->
                <div style="background: rgba(59, 130, 246, 0.08); border-left: 4px solid #3b82f6; padding: 16px; border-radius: 0 8px 8px 0; margin-bottom: 24px;">
                    <h5 style="margin: 0 0 6px 0; color: #60a5fa; font-size: 0.95rem;">💡 How Hostinger Email Delivery Works:</h5>
                    <ul style="margin: 0; padding-left: 20px; font-size: 0.85rem; color: #cbd5e1; line-height: 1.6;">
                        <li><strong>Opportunistic Check</strong>: Every ERP request checks if a 30, 15, 7, or 1-day milestone has arrived. Emails are sent once and logged.</li>
                        <li><strong>Hostinger PHP Mail() Protection</strong>: Our mail engine automatically attaches the RFC envelope sender (<code>-f</code> flag) so Hostinger will not silently drop messages.</li>
                        <li><strong>Optional Hostinger SMTP</strong>: For 100% guaranteed inbox delivery, configure your Hostinger Webmail SMTP below (<code>smtp.hostinger.com</code>, Port 465 SSL).</li>
                        <li><strong>Automated Cron Job (Hostinger hPanel)</strong>: In your Hostinger control panel, go to <em>Advanced &rarr; Cron Jobs</em> and set to run once daily at 9:00 AM:
                            <div style="background: #020617; padding: 8px 12px; border-radius: 6px; font-family: monospace; color: #38bdf8; margin-top: 6px; word-break: break-all;">
                                /usr/bin/php <?= htmlspecialchars(realpath(__DIR__ . '/../cron/maintenance_alerts.php') ?: '/home/public_html/cron/maintenance_alerts.php') ?>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Hostinger SMTP Settings Form -->
                <div style="background: var(--surface-container-highest); padding: 22px; border-radius: 12px; margin-bottom: 24px;">
                    <h4 style="margin: 0 0 14px 0;">Hostinger SMTP & Sender Settings</h4>
                    <form method="post" class="stacked-form">
                        <input type="hidden" name="action" value="save_email_smtp_settings">

                        <div class="grid-2">
                            <div>
                                <label>Developer Alert Email Recipient</label>
                                <input type="email" name="developer_alert_email" value="<?= htmlspecialchars((string)($maintRec['developer_alert_email'] ?? 'razaainaan@gmail.com')) ?>" required>
                                <small style="color: var(--on-surface-variant); display: block; margin-top: 4px;">Milestones 30, 15, 7, and 1 day will be emailed to this address.</small>
                            </div>
                            <div style="display: flex; align-items: center;">
                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 700; margin-top: 14px;">
                                    <input type="checkbox" name="smtp_enabled" value="1" <?= !empty($maintRec['smtp_enabled']) ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                                    Enable Authenticated SMTP (Recommended for Hostinger)
                                </label>
                            </div>
                        </div>

                        <div class="grid-3" style="margin-top: 10px;">
                            <div>
                                <label>SMTP Host</label>
                                <input type="text" name="smtp_host" value="<?= htmlspecialchars((string)($maintRec['smtp_host'] ?? 'smtp.hostinger.com')) ?>" placeholder="smtp.hostinger.com">
                            </div>
                            <div>
                                <label>SMTP Port</label>
                                <input type="number" name="smtp_port" value="<?= htmlspecialchars((string)($maintRec['smtp_port'] ?? '465')) ?>" placeholder="465 or 587">
                            </div>
                            <div>
                                <label>Encryption</label>
                                <select name="smtp_encryption">
                                    <option value="ssl" <?= ($maintRec['smtp_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                                    <option value="tls" <?= ($maintRec['smtp_encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS / STARTTLS (Port 587)</option>
                                    <option value="none" <?= ($maintRec['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid-2" style="margin-top: 10px;">
                            <div>
                                <label>Hostinger Email / SMTP Username</label>
                                <input type="text" name="smtp_username" value="<?= htmlspecialchars((string)($maintRec['smtp_username'] ?? '')) ?>" placeholder="e.g. admin@yourdomain.com">
                            </div>
                            <div>
                                <label>Hostinger Email Password</label>
                                <input type="password" name="smtp_password" placeholder="<?= !empty($maintRec['smtp_password']) ? '•••••••••••• (Leave blank to keep unchanged)' : 'Enter email password' ?>">
                            </div>
                        </div>

                        <div class="grid-2" style="margin-top: 10px;">
                            <div>
                                <label>Sender Email (From)</label>
                                <input type="email" name="smtp_from_email" value="<?= htmlspecialchars((string)($maintRec['smtp_from_email'] ?? '')) ?>" placeholder="e.g. noreply@yourdomain.com">
                            </div>
                            <div>
                                <label>Sender Name (Display Name)</label>
                                <input type="text" name="smtp_from_name" value="<?= htmlspecialchars((string)($maintRec['smtp_from_name'] ?? 'School ERP System')) ?>" placeholder="School ERP System">
                            </div>
                        </div>

                        <button type="submit" class="btn-quick btn-quick-success" style="margin-top: 16px;">
                            Save Email & SMTP Settings
                        </button>
                    </form>
                </div>

                <!-- Alert Dispatch Logs Table -->
                <div>
                    <h4 style="margin: 0 0 10px 0;">Alert Delivery History (<?= count($alertLogs) ?>)</h4>
                    <div class="table-wrap">
                        <table class="compact-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Milestone</th>
                                    <th>Due Date</th>
                                    <th>Recipient</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                    <th>Sent Time</th>
                                    <th>Server Response</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alertLogs as $log): ?>
                                    <tr>
                                        <td>#<?= $log['id'] ?></td>
                                        <td>
                                            <?php if ($log['alert_day'] == 999): ?>
                                                <span style="background: #3b82f6; color:#fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700;">TEST</span>
                                            <?php elseif ($log['alert_day'] == 1): ?>
                                                <span style="background: #dc2626; color:#fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700;">1 DAY</span>
                                            <?php elseif ($log['alert_day'] == 7): ?>
                                                <span style="background: #ea580c; color:#fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700;">7 DAYS</span>
                                            <?php elseif ($log['alert_day'] == 15): ?>
                                                <span style="background: #d97706; color:#fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700;">15 DAYS</span>
                                            <?php elseif ($log['alert_day'] == 30): ?>
                                                <span style="background: #2563eb; color:#fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700;">30 DAYS</span>
                                            <?php else: ?>
                                                <span style="background: #475569; color:#fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700;"><?= $log['alert_day'] ?> DAYS</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($log['due_date']) ?></td>
                                        <td><code style="color: #60a5fa;"><?= htmlspecialchars($log['recipient']) ?></code></td>
                                        <td><?= htmlspecialchars($log['subject'] ?? '') ?></td>
                                        <td>
                                            <?php if ($log['status'] === 'sent'): ?>
                                                <span style="color: #4ade80; font-weight: 700;">✅ SENT</span>
                                            <?php else: ?>
                                                <span style="color: #f87171; font-weight: 700;">❌ FAILED</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('d M Y, h:i A', strtotime($log['sent_at'])) ?></td>
                                        <td style="font-size: 0.8rem; color: var(--on-surface-variant); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars((string)$log['response_msg']) ?>">
                                            <?= htmlspecialchars((string)($log['response_msg'] ?: 'OK')) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($alertLogs)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: var(--on-surface-variant); padding: 24px;">
                                            No automated maintenance emails logged yet. Click "Send Test Email Now" above to verify delivery.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <!-- Tab 4: Emergency Unlock Token Generator -->
        <div id="tab-token" class="tab-content">
            <section class="card">
                <h3>Generate Emergency Unlock Token</h3>
                <p style="margin-bottom: 20px;">
                    If the client's ERP is locked and they have processed payment via phone or emergency agreement, 
                    generate a token below or use the predefined master key to unlock their system instantly.
                </p>

                <?php
                $currentYear = date('Y');
                $masterKey = 'AMC-' . $currentYear . '-UNLOCKED';
                $devCredentials = developer_portal_credentials();
                $generatedToken = 'AMC-' . strtoupper(substr(md5(uniqid()), 0, 4) . '-' . substr(md5(time()), 0, 4));
                ?>

                <div style="background: var(--surface-container-highest); border: 1px solid var(--outline-variant); border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                    <h4 style="margin-bottom: 10px;">Option 1: Built-in Master Emergency Key</h4>
                    <p style="font-size: 0.9rem; color: var(--on-surface-variant); margin-bottom: 12px;">
                        This key is always accepted by the system for the current year (<?= $currentYear ?>):
                    </p>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <input type="text" readonly value="<?= $masterKey ?>" style="max-width: 300px; font-weight: 700; font-family: monospace; font-size: 1.1rem; text-align: center;" id="masterKeyInput">
                        <button type="button" class="btn-quick btn-quick-primary" onclick="copyToClipboard('masterKeyInput')">Copy Key</button>
                    </div>
                </div>

                <div style="background: var(--surface-container-highest); border: 1px solid var(--outline-variant); border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                    <h4 style="margin-bottom: 10px;">Option 2: Developer Master Password</h4>
                    <p style="font-size: 0.9rem; color: var(--on-surface-variant); margin-bottom: 12px;">
                        You can also enter your Developer Password directly on the locked screen:
                    </p>
                    <code style="background: #000; color: #4ade80; padding: 6px 14px; border-radius: 6px; font-size: 1rem;"><?= htmlspecialchars($devCredentials['password']) ?></code>
                </div>

                <div style="background: var(--surface-container-highest); border: 1px solid var(--outline-variant); border-radius: 12px; padding: 20px;">
                    <h4 style="margin-bottom: 10px;">Option 3: Set One-Time Custom Client Key</h4>
                    <p style="font-size: 0.9rem; color: var(--on-surface-variant); margin-bottom: 14px;">
                        Enter a unique code below and click "Save as Active Unlock Token". Then provide this code to the client.
                    </p>
                    <form method="post" style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <input type="hidden" name="action" value="save_maintenance_settings">
                        <!-- Keep existing settings -->
                        <input type="hidden" name="is_enabled" value="<?= $maintRec['is_enabled'] ?? 1 ?>">
                        <input type="hidden" name="status" value="<?= $maintRec['status'] ?? 'paid' ?>">
                        <input type="hidden" name="due_date" value="<?= $maintRec['due_date'] ?? date('Y-m-d') ?>">
                        <input type="hidden" name="annual_fee" value="<?= $maintRec['annual_fee'] ?? 15000.00 ?>">
                        <input type="hidden" name="currency" value="<?= $maintRec['currency'] ?? 'INR' ?>">
                        <input type="hidden" name="grace_period_days" value="<?= $maintRec['grace_period_days'] ?? 7 ?>">
                        <input type="hidden" name="warning_days" value="<?= $maintRec['warning_days'] ?? 30 ?>">
                        <input type="hidden" name="lock_status" value="<?= $maintRec['lock_status'] ?? 'auto' ?>">
                        <input type="hidden" name="alert_title" value="<?= htmlspecialchars((string)($maintRec['alert_title'] ?? '')) ?>">
                        <input type="hidden" name="alert_message" value="<?= htmlspecialchars((string)($maintRec['alert_message'] ?? '')) ?>">
                        <input type="hidden" name="lock_title" value="<?= htmlspecialchars((string)($maintRec['lock_title'] ?? '')) ?>">
                        <input type="hidden" name="lock_message" value="<?= htmlspecialchars((string)($maintRec['lock_message'] ?? '')) ?>">
                        <input type="hidden" name="contact_person" value="<?= htmlspecialchars((string)($maintRec['contact_person'] ?? '')) ?>">
                        <input type="hidden" name="contact_phone" value="<?= htmlspecialchars((string)($maintRec['contact_phone'] ?? '')) ?>">
                        <input type="hidden" name="contact_email" value="<?= htmlspecialchars((string)($maintRec['contact_email'] ?? '')) ?>">
                        <input type="hidden" name="payment_instructions" value="<?= htmlspecialchars((string)($maintRec['payment_instructions'] ?? '')) ?>">

                        <input type="text" name="secret_unlock_key" value="<?= $generatedToken ?>" style="max-width: 320px; font-weight: 700; font-family: monospace; font-size: 1.05rem;" id="customKeyInput">
                        <button type="submit" class="btn-quick btn-quick-success">Set as Active Unlock Token</button>
                        <button type="button" class="btn-quick btn-quick-primary" onclick="copyToClipboard('customKeyInput')">Copy</button>
                    </form>
                </div>
            </section>
        </div>

    </div>

    <script>
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(function(el) {
                el.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(function(el) {
                el.classList.remove('active');
            });
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
        }

        function copyToClipboard(inputId) {
            const el = document.getElementById(inputId);
            el.select();
            document.execCommand('copy');
            alert('Copied to clipboard: ' + el.value);
        }
    </script>
</body>
</html>
