<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/delete_helpers.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

function generate_temp_password(int $length = 10): string
{
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    $maxIndex = strlen($characters) - 1;
    $password = '';

    for ($index = 0; $index < $length; $index++) {
        $password .= $characters[random_int(0, $maxIndex)];
    }

    return $password;
}

function email_exists(PDO $pdo, string $email): bool
{
    $statement = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $statement->execute(['email' => $email]);
    return (bool)$statement->fetch();
}

function make_unique_email(PDO $pdo, string $candidate, string $prefix, int $leadId): string
{
    if ($candidate !== '' && !email_exists($pdo, $candidate)) {
        return $candidate;
    }

    $attempt = 1;
    do {
        $generated = strtolower($prefix . $leadId . ($attempt > 1 ? $attempt : '') . '@school.local');
        $attempt++;
    } while (email_exists($pdo, $generated));

    return $generated;
}

function ensure_lead_profile_columns(PDO $pdo): void
{
    $columns = [];
    $columnRows = $pdo->query('SHOW COLUMNS FROM leads')->fetchAll();
    foreach ($columnRows as $row) {
        $columns[$row['Field']] = true;
    }

    if (!isset($columns['dob'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN dob DATE NULL AFTER class_applied');
    }
    if (!isset($columns['gender'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN gender ENUM("male", "female", "other") NULL AFTER dob');
    }
    if (!isset($columns['address'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN address VARCHAR(255) NULL AFTER gender');
    }
    if (!isset($columns['photo_path'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN photo_path VARCHAR(255) NULL AFTER notes');
    }
    if (!isset($columns['guardian_phone'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN guardian_phone VARCHAR(20) NULL AFTER guardian_name');
    }
    if (!isset($columns['admission_fee_status'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN admission_fee_status ENUM("full_paid", "unpaid", "quarterly_paid", "half_paid") NULL AFTER admission_fee_paid');
    }
    if (!isset($columns['admission_fee_amount'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN admission_fee_amount DECIMAL(10,2) NULL AFTER admission_fee_status');
    }
    if (!isset($columns['admission_concession_amount'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN admission_concession_amount DECIMAL(10,2) NULL AFTER admission_fee_amount');
    }
    if (!isset($columns['admission_concession_note'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN admission_concession_note TEXT NULL AFTER admission_concession_amount');
    }
    if (!isset($columns['payment_mode'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN payment_mode ENUM("cash", "card", "upi", "bank_transfer", "online_gateway") NULL AFTER admission_fee_amount');
    }
    if (!isset($columns['class_id'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN class_id INT NULL AFTER phone');
    }
}

function ensure_student_credentials_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS student_login_credentials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            student_email VARCHAR(150) NOT NULL,
            student_password_plain VARCHAR(255) NOT NULL,
            parent_email VARCHAR(150) NULL,
            parent_password_plain VARCHAR(255) NULL,
            generated_by INT NULL,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_credential (student_id),
            FOREIGN KEY (student_id) REFERENCES students(id),
            FOREIGN KEY (generated_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function ensure_fee_structure_columns(PDO $pdo): void
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM fee_structures')->fetchAll() as $row) {
        $columns[$row['Field']] = true;
    }

    if (!isset($columns['fee_type'])) {
        $pdo->exec("ALTER TABLE fee_structures ADD COLUMN fee_type ENUM('admission_fee', 'caution_money', 'tuition_fee') NOT NULL DEFAULT 'tuition_fee' AFTER session_id");
        $pdo->exec("UPDATE fee_structures SET fee_type = 'tuition_fee' WHERE fee_type IS NULL OR fee_type = ''");
    }
}

function fee_type_priority_sql(): string
{
    return "CASE fee_type WHEN 'caution_money' THEN 0 WHEN 'admission_fee' THEN 1 WHEN 'tuition_fee' THEN 2 ELSE 3 END, CASE WHEN billing_cycle = 'one_time' THEN 0 ELSE 1 END, id";
}

function resolve_class_for_lead(PDO $pdo, array $lead): ?array
{
    $classId = (int)($lead['class_id'] ?? 0);
    if ($classId > 0) {
        $byIdStatement = $pdo->prepare(
            'SELECT c.id, c.session_id
             FROM classes c
             WHERE c.id = :class_id
             LIMIT 1'
        );
        $byIdStatement->execute(['class_id' => $classId]);
        $byId = $byIdStatement->fetch();
        if ($byId) {
            return $byId;
        }
    }

    $classApplied = trim((string)($lead['class_applied'] ?? ''));
    $classApplied = trim($classApplied);
    if ($classApplied === '') {
        return null;
    }

    $matchStatement = $pdo->prepare(
        'SELECT c.id, c.session_id
         FROM classes c
         JOIN academic_sessions s ON s.id = c.session_id
         WHERE CONCAT(c.class_name, " - ", c.section) = :class_applied
            OR c.class_name = :class_applied
         ORDER BY s.is_active DESC, s.start_date DESC, c.id DESC
         LIMIT 1'
    );
    $matchStatement->execute(['class_applied' => $classApplied]);
    $row = $matchStatement->fetch();

    return $row ?: null;
}


function fee_status_for_amount(float $payableAmount, float $paidAmount): string
{
    if ($payableAmount <= 0 || $paidAmount >= $payableAmount) {
        return 'paid';
    }

    if ($paidAmount > 0 && $paidAmount < $payableAmount) {
        return 'partial';
    }

    return 'pending';
}

function apply_admission_concession_to_student_fees(PDO $pdo, int $studentId, int $classId, int $sessionId, float $concessionAmount): void
{
    if ($concessionAmount <= 0) {
        return;
    }

    $feeRowsStatement = $pdo->prepare(
        'SELECT sf.id, sf.total_amount, sf.discount_amount, sf.payable_amount, sf.paid_amount, fs.fee_type, fs.billing_cycle
         FROM student_fees sf
         JOIN fee_structures fs ON fs.id = sf.fee_structure_id
         WHERE sf.student_id = :student_id
           AND fs.class_id = :class_id
           AND fs.session_id = :session_id
           AND fs.is_active = 1
         ORDER BY CASE fs.fee_type WHEN "admission_fee" THEN 0 WHEN "tuition_fee" THEN 1 WHEN "caution_money" THEN 2 ELSE 3 END,
                  CASE WHEN fs.billing_cycle = "one_time" THEN 0 ELSE 1 END,
                  sf.id
         FOR UPDATE'
    );
    $feeRowsStatement->execute([
        'student_id' => $studentId,
        'class_id' => $classId,
        'session_id' => $sessionId,
    ]);
    $feeRows = $feeRowsStatement->fetchAll();

    if (empty($feeRows)) {
        return;
    }

    $updateStatement = $pdo->prepare(
        'UPDATE student_fees
         SET discount_amount = :discount_amount,
             payable_amount = :payable_amount,
             status = :status
         WHERE id = :id'
    );

    $remainingConcession = $concessionAmount;
    foreach ($feeRows as $feeRow) {
        if ($remainingConcession <= 0) {
            break;
        }

        $totalAmount = (float)$feeRow['total_amount'];
        $currentDiscount = (float)$feeRow['discount_amount'];
        $currentPaid = (float)$feeRow['paid_amount'];
        $discountableAmount = max(0, $totalAmount - $currentDiscount - $currentPaid);

        if ($discountableAmount <= 0) {
            continue;
        }

        $applied = min($remainingConcession, $discountableAmount);
        $newDiscount = $currentDiscount + $applied;
        $newPayable = max($currentPaid, $totalAmount - $newDiscount);

        $updateStatement->execute([
            'discount_amount' => $newDiscount,
            'payable_amount' => $newPayable,
            'status' => fee_status_for_amount($newPayable, $currentPaid),
            'id' => (int)$feeRow['id'],
        ]);

        $remainingConcession -= $applied;
    }
}

function notify_superadmin_of_concession(PDO $pdo, array $lead, string $classLabel, float $concessionAmount, string $concessionNote): void
{
    if ($concessionAmount <= 0) {
        return;
    }

    $title = 'Admission concession recorded';
    $message = "Admission concession of ₹" . number_format($concessionAmount, 2) . "\n" .
               (string)$lead['lead_name'] . " - " . $classLabel . ".\n";
    if ($concessionNote !== '') {
        $message .= "Reason: " . $concessionNote . ".";
    }

    $insertNotification = $pdo->prepare(
        'INSERT INTO notifications (title, message, sender_id, target_scope, target_value)
         VALUES (:title, :message, :sender_id, :target_scope, :target_value)'
    );
    $insertNotification->execute([
        'title' => $title,
        'message' => $message,
        'sender_id' => current_user()['id'],
        'target_scope' => 'role',
        'target_value' => ROLE_SUPER_ADMIN,
    ]);
}

function ensure_student_fees_for_class(PDO $pdo, int $studentId, int $classId, int $sessionId): array
{
    $structureStatement = $pdo->prepare(
                'SELECT id, fee_type, amount, billing_cycle
         FROM fee_structures
         WHERE class_id = :class_id
           AND session_id = :session_id
           AND is_active = 1
                 ORDER BY ' . fee_type_priority_sql()
    );
    $structureStatement->execute([
        'class_id' => $classId,
        'session_id' => $sessionId,
    ]);
    $structures = $structureStatement->fetchAll();

    if (empty($structures)) {
        return [];
    }

    $selectStudentFee = $pdo->prepare(
        'SELECT id, payable_amount, paid_amount
         FROM student_fees
         WHERE student_id = :student_id
           AND fee_structure_id = :fee_structure_id
           AND period_label = :period_label
         LIMIT 1'
    );
    $insertStudentFee = $pdo->prepare(
        'INSERT INTO student_fees (student_id, fee_structure_id, period_label, total_amount, discount_amount, payable_amount, paid_amount, status, due_date)
         VALUES (:student_id, :fee_structure_id, :period_label, :total_amount, :discount_amount, :payable_amount, :paid_amount, :status, :due_date)'
    );

    $studentFees = [];
    foreach ($structures as $structure) {
        $amount = (float)$structure['amount'];
        $periods = get_upfront_periods_for_cycle((string)$structure['billing_cycle']);
        
        foreach ($periods as $periodLabel) {
            $selectStudentFee->execute([
                'student_id' => $studentId,
                'fee_structure_id' => (int)$structure['id'],
                'period_label' => $periodLabel,
            ]);
            $existing = $selectStudentFee->fetch();

            if ($existing) {
                $studentFees[] = [
                    'id' => (int)$existing['id'],
                    'fee_type' => (string)$structure['fee_type'],
                    'payable_amount' => (float)$existing['payable_amount'],
                    'paid_amount' => (float)$existing['paid_amount'],
                ];
                continue;
            }

            $insertStudentFee->execute([
                'student_id' => $studentId,
                'fee_structure_id' => (int)$structure['id'],
                'period_label' => $periodLabel,
                'total_amount' => $amount,
                'discount_amount' => 0,
                'payable_amount' => $amount,
                'paid_amount' => 0,
                'status' => 'pending',
                'due_date' => null,
            ]);
            $studentFees[] = [
                'id' => (int)$pdo->lastInsertId(),
                'fee_type' => (string)$structure['fee_type'],
                'payable_amount' => $amount,
                'paid_amount' => 0,
            ];
        }
    }

    return $studentFees;
}

function apply_lead_payment_credit(PDO $pdo, array $studentFees, float $creditAmount, int $receivedBy, int $leadId, string $paymentMode): void
{
    if ($creditAmount <= 0 || empty($studentFees)) {
        return;
    }

    $updateStudentFee = $pdo->prepare(
        'UPDATE student_fees
         SET paid_amount = :paid_amount,
             status = :status
         WHERE id = :id'
    );
    $insertPayment = $pdo->prepare(
        'INSERT INTO payments (student_fee_id, amount, payment_date, method, transaction_ref, status, received_by)
         VALUES (:student_fee_id, :amount, NOW(), :method, :transaction_ref, :status, :received_by)'
    );

    $remaining = $creditAmount;
    foreach ($studentFees as $feeRow) {
        if ($remaining <= 0) {
            break;
        }

        $payable = (float)$feeRow['payable_amount'];
        $paid = (float)$feeRow['paid_amount'];
        $outstanding = max(0, $payable - $paid);
        if ($outstanding <= 0) {
            continue;
        }

        $applied = min($remaining, $outstanding);
        $newPaid = $paid + $applied;

        $status = 'pending';
        if ($newPaid >= $payable && $payable > 0) {
            $status = 'paid';
        } elseif ($newPaid > 0) {
            $status = 'partial';
        }

        $updateStudentFee->execute([
            'paid_amount' => $newPaid,
            'status' => $status,
            'id' => (int)$feeRow['id'],
        ]);

        $insertPayment->execute([
            'student_fee_id' => (int)$feeRow['id'],
            'amount' => $applied,
            'method' => $paymentMode,
            'transaction_ref' => 'ADM-' . $leadId . '-' . (int)$feeRow['id'] . '-' . date('His'),
            'status' => 'success',
            'received_by' => $receivedBy,
        ]);

        $remaining -= $applied;
    }
}

$errors = [];
$success = [];
$credentialFlash = $_SESSION['generated_credentials'] ?? null;
unset($_SESSION['generated_credentials']);

if (isset($_GET['imported'])) {
    $imported = (int)($_GET['imported'] ?? 0);
    $failed = (int)($_GET['failed'] ?? 0);
    $success[] = 'Lead import completed. Imported: ' . $imported . ', Failed: ' . $failed . '.';
}
if (isset($_GET['import_error'])) {
    $errors[] = (string)$_GET['import_error'];
}

ensure_fee_structure_columns($pdo);

$classOptions = $pdo->query(
    'SELECT c.id, c.class_name, c.section, s.title AS session_title
     FROM classes c
     JOIN academic_sessions s ON s.id = c.session_id
     ORDER BY s.is_active DESC, s.start_date DESC, c.class_name, c.section'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensure_lead_profile_columns($pdo);

    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        $allowedStatuses = ['contacted', 'approved', 'rejected'];

        if ($leadId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            $errors[] = 'Invalid lead status update request.';
        } else {
            if ($newStatus === 'approved') {
                $leadForApproval = $pdo->prepare('SELECT admission_fee_amount FROM leads WHERE id = :id LIMIT 1');
                $leadForApproval->execute(['id' => $leadId]);
                $leadRow = $leadForApproval->fetch();

                $feeAmount = (float)($leadRow['admission_fee_amount'] ?? 0);
                if ($feeAmount <= 0) {
                    $errors[] = 'Approval requires an admission fee amount.';
                }
            }

            if (empty($errors)) {
                if ($newStatus === 'approved') {
                    // Approve step directly proceeds to enrollment + credential generation.
                    $action = 'enroll_lead';
                } else {
                    $updateStatus = $pdo->prepare('UPDATE leads SET status = :status WHERE id = :id');
                    $updateStatus->execute([
                        'status' => $newStatus,
                        'id' => $leadId,
                    ]);
                    $success[] = 'Lead status updated.';
                }
            }
        }
    }

    if ($action === 'update_fee') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $feeAmount = (float)($_POST['admission_fee_amount'] ?? 0);
        $concessionAmount = (float)($_POST['admission_concession_amount'] ?? 0);
        $concessionNote = trim($_POST['admission_concession_note'] ?? '');
        $paymentMode = $_POST['payment_mode'] ?? '';
        $allowedPaymentModes = ['cash', 'card', 'upi', 'bank_transfer', 'online_gateway'];

        if ($leadId <= 0 || $classId <= 0 || !in_array($paymentMode, $allowedPaymentModes, true)) {
            $errors[] = 'Invalid fee update request. Select course, payment mode, and enter an amount.';
        } else {
            if ($feeAmount <= 0) {
                $errors[] = 'Enter a valid admission fee amount.';
            }
            if ($concessionAmount < 0) {
                $errors[] = 'Concession amount cannot be negative.';
            }
            if ($concessionAmount > 0 && $concessionNote === '') {
                $errors[] = 'Add a note explaining the concession.';
            }

            $classStatement = $pdo->prepare('SELECT class_name, section FROM classes WHERE id = :id LIMIT 1');
            $classStatement->execute(['id' => $classId]);
            $selectedClass = $classStatement->fetch();

            if (!$selectedClass) {
                $errors[] = 'Selected course is invalid.';
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $leadLookup = $pdo->prepare(
                    'SELECT id, lead_name, class_applied, admission_concession_amount, admission_concession_note
                     FROM leads
                     WHERE id = :id
                     LIMIT 1 FOR UPDATE'
                );
                $leadLookup->execute(['id' => $leadId]);
                $leadRow = $leadLookup->fetch();

                if (!$leadRow) {
                    throw new RuntimeException('Lead not found.');
                }

                $classApplied = (string)$selectedClass['class_name'];
                $normalizedConcessionAmount = $concessionAmount > 0 ? $concessionAmount : 0.0;
                $normalizedConcessionNote = $normalizedConcessionAmount > 0 ? $concessionNote : null;

                $updateFee = $pdo->prepare(
                    'UPDATE leads
                     SET admission_fee_status = NULL,
                         admission_fee_amount = :admission_fee_amount,
                         admission_concession_amount = :admission_concession_amount,
                         admission_concession_note = :admission_concession_note,
                         payment_mode = :payment_mode,
                         class_id = :class_id,
                         class_applied = :class_applied,
                         admission_fee_paid = 1,
                         payment_confirmed_at = :payment_confirmed_at
                     WHERE id = :id'
                );
                $updateFee->execute([
                    'admission_fee_amount' => $feeAmount,
                    'admission_concession_amount' => $normalizedConcessionAmount,
                    'admission_concession_note' => $normalizedConcessionNote,
                    'payment_mode' => $paymentMode,
                    'class_id' => $classId,
                    'class_applied' => $classApplied,
                    'payment_confirmed_at' => date('Y-m-d H:i:s'),
                    'id' => $leadId,
                ]);

                $concessionChanged = $normalizedConcessionAmount > 0 && (
                    abs((float)($leadRow['admission_concession_amount'] ?? 0) - $normalizedConcessionAmount) > 0.0001 ||
                    trim((string)($leadRow['admission_concession_note'] ?? '')) !== (string)$normalizedConcessionNote
                );
                if ($concessionChanged) {
                    notify_superadmin_of_concession($pdo, [
                        'id' => $leadId,
                        'lead_name' => (string)$leadRow['lead_name'],
                    ], $classApplied, $normalizedConcessionAmount, (string)$normalizedConcessionNote);
                }

                $pdo->commit();
                $success[] = 'Admission fee details updated.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to update admission fee details: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'apply_concession') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $concessionAmount = (float)($_POST['admission_concession_amount'] ?? 0);
        $concessionNote = trim($_POST['admission_concession_note'] ?? '');

        if ($leadId <= 0) {
            $errors[] = 'Invalid concession request.';
        } elseif ($concessionAmount < 0) {
            $errors[] = 'Concession amount cannot be negative.';
        } elseif ($concessionAmount > 0 && $concessionNote === '') {
            $errors[] = 'Add a note explaining the concession.';
        } else {
            try {
                $pdo->beginTransaction();

                $leadLookup = $pdo->prepare(
                    'SELECT id, lead_name, class_applied, admission_concession_amount, admission_concession_note
                     FROM leads
                     WHERE id = :id
                     LIMIT 1 FOR UPDATE'
                );
                $leadLookup->execute(['id' => $leadId]);
                $leadRow = $leadLookup->fetch();

                if (!$leadRow) {
                    throw new RuntimeException('Lead not found.');
                }

                $normalizedConcessionAmount = $concessionAmount > 0 ? $concessionAmount : 0.0;
                $normalizedConcessionNote = $normalizedConcessionAmount > 0 ? $concessionNote : null;

                $updateFee = $pdo->prepare(
                    'UPDATE leads
                     SET admission_concession_amount = :admission_concession_amount,
                         admission_concession_note = :admission_concession_note
                     WHERE id = :id'
                );
                $updateFee->execute([
                    'admission_concession_amount' => $normalizedConcessionAmount,
                    'admission_concession_note' => $normalizedConcessionNote,
                    'id' => $leadId,
                ]);

                if ($normalizedConcessionAmount > 0) {
                    notify_superadmin_of_concession(
                        $pdo,
                        [
                            'id' => $leadId,
                            'lead_name' => (string)$leadRow['lead_name'],
                        ],
                        (string)($leadRow['class_applied'] ?? 'Unknown class'),
                        $normalizedConcessionAmount,
                        (string)$normalizedConcessionNote
                    );
                }

                $pdo->commit();
                $success[] = 'Concession applied successfully.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to apply concession: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'enroll_lead') {
        $leadId = (int)($_POST['lead_id'] ?? 0);

        if ($leadId <= 0) {
            $errors[] = 'Invalid enrollment request.';
        } else {
            try {
                $pdo->beginTransaction();

                $leadStatement = $pdo->prepare('SELECT * FROM leads WHERE id = :id FOR UPDATE');
                $leadStatement->execute(['id' => $leadId]);
                $lead = $leadStatement->fetch();

                if (!$lead) {
                    throw new RuntimeException('Lead not found.');
                }
                if (!in_array((string)$lead['status'], ['approved', 'contacted', 'new'], true)) {
                    throw new RuntimeException('Only active leads can be enrolled.');
                }
                if (!empty($lead['converted_student_id'])) {
                    throw new RuntimeException('This lead is already enrolled.');
                }

                $admissionFeeAmount = (float)($lead['admission_fee_amount'] ?? 0);
                if ($admissionFeeAmount <= 0) {
                    throw new RuntimeException('Enrollment requires an admission fee amount.');
                }

                $studentEmail = make_unique_email($pdo, (string)($lead['email'] ?? ''), 'student', $leadId);
                $parentCandidateEmail = (string)($lead['guardian_email'] ?? '');
                if ($parentCandidateEmail !== '' && strtolower($parentCandidateEmail) === strtolower($studentEmail)) {
                    $parentCandidateEmail = '';
                }
                $parentEmail = make_unique_email($pdo, $parentCandidateEmail, 'parent', $leadId);

                $studentPassword = generate_temp_password();
                $parentPassword = generate_temp_password();

                $studentUserStmt = $pdo->prepare(
                    'INSERT INTO users (full_name, email, password_hash, role, is_active)
                     VALUES (:full_name, :email, :password_hash, :role, :is_active)'
                );
                $studentUserStmt->execute([
                    'full_name' => $lead['lead_name'],
                    'email' => $studentEmail,
                    'password_hash' => password_hash($studentPassword, PASSWORD_DEFAULT),
                    'role' => ROLE_STUDENT,
                    'is_active' => 1,
                ]);
                $studentUserId = (int)$pdo->lastInsertId();

                $parentName = (string)($lead['guardian_name'] ?? 'Guardian of ' . $lead['lead_name']);
                $parentUserStmt = $pdo->prepare(
                    'INSERT INTO users (full_name, email, password_hash, role, is_active)
                     VALUES (:full_name, :email, :password_hash, :role, :is_active)'
                );
                $parentUserStmt->execute([
                    'full_name' => $parentName,
                    'email' => $parentEmail,
                    'password_hash' => password_hash($parentPassword, PASSWORD_DEFAULT),
                    'role' => ROLE_PARENT,
                    'is_active' => 1,
                ]);
                $parentUserId = (int)$pdo->lastInsertId();

                $admissionNumber = 'ADM' . date('Y') . sprintf('%04d', $leadId);
                $leadDob = trim((string)($lead['dob'] ?? ''));
                $leadGender = trim((string)($lead['gender'] ?? ''));
                if (!in_array($leadGender, ['male', 'female', 'other'], true)) {
                    $leadGender = '';
                }

                $studentStmt = $pdo->prepare(
                    'INSERT INTO students (user_id, admission_no, roll_number, dob, gender, photo_path, address, status)
                     VALUES (:user_id, :admission_no, :roll_number, :dob, :gender, :photo_path, :address, :status)'
                );
                $studentStmt->execute([
                    'user_id' => $studentUserId,
                    'admission_no' => $admissionNumber,
                    'roll_number' => null,
                    'dob' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $leadDob) ? $leadDob : null,
                    'gender' => $leadGender !== '' ? $leadGender : null,
                    'photo_path' => !empty($lead['photo_path']) ? (string)$lead['photo_path'] : null,
                    'address' => !empty($lead['address']) ? (string)$lead['address'] : null,
                    'status' => 'enrolled',
                ]);
                $studentId = (int)$pdo->lastInsertId();

                $parentStmt = $pdo->prepare('INSERT INTO parents (user_id, phone, address) VALUES (:user_id, :phone, :address)');
                $parentStmt->execute([
                    'user_id' => $parentUserId,
                    'phone' => !empty($lead['guardian_phone']) ? (string)$lead['guardian_phone'] : (!empty($lead['phone']) ? (string)$lead['phone'] : null),
                    'address' => !empty($lead['address']) ? (string)$lead['address'] : null,
                ]);
                $parentId = (int)$pdo->lastInsertId();

                $linkStmt = $pdo->prepare(
                    'INSERT INTO parent_student (parent_id, student_id, relation)
                     VALUES (:parent_id, :student_id, :relation)'
                );
                $linkStmt->execute([
                    'parent_id' => $parentId,
                    'student_id' => $studentId,
                    'relation' => 'guardian',
                ]);

                $resolvedClass = resolve_class_for_lead($pdo, $lead);
                if (!$resolvedClass) {
                    throw new RuntimeException('Course mapping not found for this lead. Update the course in lead edit and save fee details.');
                }

                $enrollmentStmt = $pdo->prepare(
                    'INSERT INTO student_class_enrollments (student_id, class_id, session_id, is_active)
                     VALUES (:student_id, :class_id, :session_id, 1)
                     ON DUPLICATE KEY UPDATE class_id = VALUES(class_id), is_active = 1'
                );
                $enrollmentStmt->execute([
                    'student_id' => $studentId,
                    'class_id' => (int)$resolvedClass['id'],
                    'session_id' => (int)$resolvedClass['session_id'],
                ]);

                $studentFees = ensure_student_fees_for_class(
                    $pdo,
                    $studentId,
                    (int)$resolvedClass['id'],
                    (int)$resolvedClass['session_id']
                );
                if (empty($studentFees)) {
                    throw new RuntimeException('No active fee structure found for this course/session. Create fee structure first.');
                }

                $admissionConcessionAmount = (float)($lead['admission_concession_amount'] ?? 0);
                if ($admissionConcessionAmount > 0) {
                    apply_admission_concession_to_student_fees(
                        $pdo,
                        $studentId,
                        (int)$resolvedClass['id'],
                        (int)$resolvedClass['session_id'],
                        $admissionConcessionAmount
                    );
                    $studentFees = ensure_student_fees_for_class(
                        $pdo,
                        $studentId,
                        (int)$resolvedClass['id'],
                        (int)$resolvedClass['session_id']
                    );
                }

                apply_lead_payment_credit(
                    $pdo,
                    $studentFees,
                    max(0, $admissionFeeAmount),
                    (int)current_user()['id'],
                    $leadId,
                    (string)($lead['payment_mode'] ?? 'cash')
                );

                $remainingStmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(sf.payable_amount - sf.paid_amount), 0)
                     FROM student_fees sf
                     JOIN fee_structures fs ON fs.id = sf.fee_structure_id
                     WHERE sf.student_id = :student_id
                       AND fs.class_id = :class_id
                       AND fs.session_id = :session_id
                      AND fs.is_active = 1'
                );
                $remainingStmt->execute([
                    'student_id' => $studentId,
                    'class_id' => (int)$resolvedClass['id'],
                    'session_id' => (int)$resolvedClass['session_id'],
                ]);
                $remainingOutstanding = (float)($remainingStmt->fetchColumn() ?: 0);
                $isFullyPaid = $remainingOutstanding <= 0.0001;

                $updateLead = $pdo->prepare(
                    'UPDATE leads
                     SET status = "enrolled",
                         converted_student_id = :student_id,
                         admission_fee_paid = :admission_fee_paid,
                         payment_confirmed_at = :payment_confirmed_at
                     WHERE id = :id'
                );
                $updateLead->execute([
                    'student_id' => $studentId,
                    'admission_fee_paid' => $isFullyPaid ? 1 : 0,
                    'payment_confirmed_at' => $isFullyPaid ? date('Y-m-d H:i:s') : null,
                    'id' => $leadId,
                ]);

                ensure_student_credentials_table($pdo);
                $saveCredentialStmt = $pdo->prepare(
                    'INSERT INTO student_login_credentials (
                        student_id,
                        student_email,
                        student_password_plain,
                        parent_email,
                        parent_password_plain,
                        generated_by
                    ) VALUES (
                        :student_id,
                        :student_email,
                        :student_password_plain,
                        :parent_email,
                        :parent_password_plain,
                        :generated_by
                    )
                    ON DUPLICATE KEY UPDATE
                        student_email = VALUES(student_email),
                        student_password_plain = VALUES(student_password_plain),
                        parent_email = VALUES(parent_email),
                        parent_password_plain = VALUES(parent_password_plain),
                        generated_by = VALUES(generated_by),
                        updated_at = CURRENT_TIMESTAMP'
                );
                $saveCredentialStmt->execute([
                    'student_id' => $studentId,
                    'student_email' => $studentEmail,
                    'student_password_plain' => $studentPassword,
                    'parent_email' => $parentEmail,
                    'parent_password_plain' => $parentPassword,
                    'generated_by' => (int)current_user()['id'],
                ]);

                $pdo->commit();

                $_SESSION['generated_credentials'] = [
                    'student_name' => $lead['lead_name'],
                    'admission_no' => $admissionNumber,
                    'student_email' => $studentEmail,
                    'student_password' => $studentPassword,
                    'parent_name' => $parentName,
                    'parent_email' => $parentEmail,
                    'parent_password' => $parentPassword,
                ];

                header('Location: /itierp/modules/admission/leads.php?enrolled=1');
                exit;
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $throwable->getMessage();
            }
        }
    }

    if ($action === 'delete_lead') {
        $leadId = (int)($_POST['lead_id'] ?? 0);

        if ($leadId <= 0) {
            $errors[] = 'Invalid lead delete request.';
        } else {
            try {
                $pdo->beginTransaction();

                $leadRowStmt = $pdo->prepare('SELECT converted_student_id FROM leads WHERE id = :id LIMIT 1 FOR UPDATE');
                $leadRowStmt->execute(['id' => $leadId]);
                $leadRow = $leadRowStmt->fetch();

                if (!$leadRow) {
                    throw new RuntimeException('Lead not found.');
                }

                $convertedStudentId = (int)($leadRow['converted_student_id'] ?? 0);
                if ($convertedStudentId > 0) {
                    cascade_delete_student($pdo, $convertedStudentId);
                }

                $deleteLead = $pdo->prepare('DELETE FROM leads WHERE id = :id');
                $deleteLead->execute(['id' => $leadId]);
                if ($deleteLead->rowCount() > 0) {
                    $success[] = 'Lead deleted successfully.';
                } else {
                    $errors[] = 'Lead not found.';
                }

                $pdo->commit();
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to delete lead: ' . $throwable->getMessage();
            }
        }
    }
}

$leadQuery = 'SELECT * FROM leads ORDER BY created_at DESC, id DESC';
$leadsStatement = $pdo->query($leadQuery);
$leads = $leadsStatement->fetchAll();

$pageTitle = 'Admission Leads';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Admission & Leads Management</h2>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="success">
            <?php foreach ($success as $message): ?>
                <p><?= htmlspecialchars($message) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['enrolled']) && $credentialFlash): ?>
        <div class="card">
            <h3>Credentials Generated (Share Securely)</h3>
            <p><strong>Student:</strong> <?= htmlspecialchars($credentialFlash['student_name']) ?> | Admission No: <?= htmlspecialchars($credentialFlash['admission_no']) ?></p>
            <p><strong>Student Login:</strong> <?= htmlspecialchars($credentialFlash['student_email']) ?> / <?= htmlspecialchars($credentialFlash['student_password']) ?></p>
            <p><strong>Parent Login:</strong> <?= htmlspecialchars($credentialFlash['parent_email']) ?> / <?= htmlspecialchars($credentialFlash['parent_password']) ?></p>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="screen-toolbar">
        <h3>Lead Pipeline</h3>
    </div>

    <div class="table-wrap" id="section-table">
        <table class="compact-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Photo</th>
                    <th>Student</th>
                    <th>Guardian</th>
                    <th>Course</th>
                    <th>Status</th>
                    <th>Source</th>
                    <th>Decision</th>
                    <th>Concession</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead): ?>
                    <?php
                        $isFeeReadyForApproval = (float)($lead['admission_fee_amount'] ?? 0) > 0;
                    ?>
                    <tr>
                        <td><?= (int)$lead['id'] ?></td>
                        <td>
                            <?php if (!empty($lead['photo_path'])): ?>
                                <img src="<?= htmlspecialchars((string)$lead['photo_path']) ?>" alt="Lead Photo" class="table-photo-thumb">
                            <?php else: ?>
                                <span class="table-photo-placeholder">No Photo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($lead['lead_name']) ?><br>
                            <small><?= htmlspecialchars((string)$lead['email']) ?></small>
                        </td>
                        <td>
                            <?= htmlspecialchars((string)$lead['guardian_name']) ?><br>
                            <small><?= htmlspecialchars((string)($lead['guardian_phone'] ?? '')) ?></small>
                        </td>
                        <td>
                            <?= htmlspecialchars((string)$lead['class_applied']) ?>
                        </td>
                        <td><span class="pill"><?= ucfirst(htmlspecialchars($lead['status'])) ?></span></td>
                        <td><?= htmlspecialchars($lead['source']) ?></td>
                        <td class="lead-decision-cell">
                            <div class="lead-actions">
                            <form method="post" id="lead-fee-form-<?= (int)$lead['id'] ?>" class="inline-form lead-fee-form">
                                <input type="hidden" name="action" value="update_fee">
                                <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
                                <input type="hidden" name="class_id" value="<?= (int)($lead['class_id'] ?? 0) ?>">
                                <input type="number" name="admission_fee_amount" min="0.01" step="0.01" value="<?= htmlspecialchars((string)($lead['admission_fee_amount'] ?? '')) ?>" placeholder="Fee" required>
                                <select name="payment_mode" required>
                                    <option value="">Mode</option>
                                    <option value="cash" <?= ($lead['payment_mode'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="card" <?= ($lead['payment_mode'] ?? '') === 'card' ? 'selected' : '' ?>>Card</option>
                                    <option value="upi" <?= ($lead['payment_mode'] ?? '') === 'upi' ? 'selected' : '' ?>>UPI</option>
                                    <option value="bank_transfer" <?= ($lead['payment_mode'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                    <option value="online_gateway" <?= ($lead['payment_mode'] ?? '') === 'online_gateway' ? 'selected' : '' ?>>Online Gateway</option>
                                </select>
                                <button type="submit">Save Fee</button>
                            </form>

                            <a class="nav-item nav-item-subtle lead-action-link" href="/itierp/modules/admission/edit_lead.php?id=<?= (int)$lead['id'] ?>">Edit</a>

                            <?php if (!in_array($lead['status'], ['enrolled', 'rejected'], true)): ?>
                                <?php if ($isFeeReadyForApproval): ?>
                                    <form method="post" class="inline-form lead-status-form" onsubmit="return confirm('Approve and generate enrollment credentials now?');">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
                                        <input type="hidden" name="status" value="approved">
                                        <button type="submit">Approve</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="inline-form lead-status-form" onsubmit="return confirm('Reject this lead?');">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
                                        <input type="hidden" name="status" value="rejected">
                                        <button type="submit" class="danger">Reject</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ((float)($lead['admission_concession_amount'] ?? 0) > 0): ?>
                                <small>₹<?= number_format((float)$lead['admission_concession_amount'], 2) ?></small><br>
                                <small><?= htmlspecialchars((string)($lead['admission_concession_note'] ?? '')) ?></small>
                            <?php else: ?>
                                <small>No concession</small>
                            <?php endif; ?>
                            <div class="concession-trigger-wrap">
                                <button
                                    type="button"
                                    class="btn-concession-slim"
                                    data-lead-id="<?= (int)$lead['id'] ?>"
                                    data-lead-name="<?= htmlspecialchars((string)$lead['lead_name'], ENT_QUOTES) ?>"
                                    data-class-label="<?= htmlspecialchars((string)$lead['class_applied'], ENT_QUOTES) ?>"
                                    data-current-amount="<?= htmlspecialchars((string)($lead['admission_concession_amount'] ?? ''), ENT_QUOTES) ?>"
                                    data-current-note="<?= htmlspecialchars((string)($lead['admission_concession_note'] ?? ''), ENT_QUOTES) ?>"
                                    onclick="openConcessionModal(this)">
                                    Give Concession
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($leads)): ?>
                    <tr>
                        <td colspan="9">No leads found matching your criteria.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div id="form-concession" class="collapsible-form">
    <section class="card">
        <h3>Give Concession</h3>
        <p id="concessionLeadLabel" class="concession-lead-label"></p>
        <form method="post" class="form-grid form-grid-wide">
            <input type="hidden" name="action" value="apply_concession">
            <input type="hidden" name="lead_id" value="">

            <label>Concession Amount</label>
            <input type="number" name="admission_concession_amount" min="0" step="0.01" required>

            <label>Concession Note</label>
            <textarea name="admission_concession_note" rows="4" placeholder="Reason for concession" required></textarea>

            <button type="submit">Apply</button>
        </form>
    </section>
</div>

<script>
function openConcessionModal(button) {
    var modal = document.getElementById('form-concession');
    var overlay = document.getElementById('modal-overlay');
    var label = document.getElementById('concessionLeadLabel');
    var leadIdInput = modal ? modal.querySelector('input[name="lead_id"]') : null;
    var amountInput = modal ? modal.querySelector('input[name="admission_concession_amount"]') : null;
    var noteInput = modal ? modal.querySelector('textarea[name="admission_concession_note"]') : null;

    if (!modal || !overlay || !button) {
        return;
    }

    if (leadIdInput) {
        leadIdInput.value = button.dataset.leadId || '';
    }
    if (amountInput) {
        amountInput.value = button.dataset.currentAmount || '';
    }
    if (noteInput) {
        noteInput.value = button.dataset.currentNote || '';
    }
    if (label) {
        var leadName = button.dataset.leadName || 'Selected lead';
        var classLabel = button.dataset.classLabel || 'Unknown class';
        label.textContent = leadName + ' | ' + classLabel;
    }

    document.querySelectorAll('.collapsible-form.active').forEach(function(form) {
        form.classList.remove('active');
    });

    modal.classList.add('active');
    overlay.classList.add('active');
    document.body.classList.add('modal-open');

    if (!modal.querySelector('.modal-close-btn')) {
        var closeBtn = document.createElement('span');
        closeBtn.className = 'modal-close-btn';
        closeBtn.innerHTML = '&times;';
        closeBtn.onclick = function() {
            modal.classList.remove('active');
            overlay.classList.remove('active');
            document.body.classList.remove('modal-open');
        };
        modal.prepend(closeBtn);
    }
}
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
