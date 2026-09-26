<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_roles([ROLE_STUDENT]);

$studentStatement = $pdo->prepare('SELECT id FROM students WHERE user_id = :user_id LIMIT 1');
$studentStatement->execute(['user_id' => current_user()['id']]);
$studentId = (int)($studentStatement->fetchColumn() ?: 0);

$attendanceTotal = 0;
$attendancePresent = 0;
$pendingFees = 0.0;
$homeworkCount = 0;
$unreadNotifications = 0;

if ($studentId > 0) {
    $attendanceStmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_rows,
            SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) AS present_rows
         FROM attendance
         WHERE student_id = :student_id'
    );
    $attendanceStmt->execute(['student_id' => $studentId]);
    $attendanceSummary = $attendanceStmt->fetch();
    $attendanceTotal = (int)($attendanceSummary['total_rows'] ?? 0);
    $attendancePresent = (int)($attendanceSummary['present_rows'] ?? 0);

    $feeStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(payable_amount - paid_amount), 0)
         FROM student_fees
         WHERE student_id = :student_id'
    );
    $feeStmt->execute(['student_id' => $studentId]);
    $pendingFees = (float)$feeStmt->fetchColumn();

    $homeworkStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM homework h
         WHERE h.class_id IN (
             SELECT class_id
             FROM student_class_enrollments
             WHERE student_id = :student_id
               AND is_active = 1
         )'
    );
    $homeworkStmt->execute(['student_id' => $studentId]);
    $homeworkCount = (int)$homeworkStmt->fetchColumn();

    $classIdsStmt = $pdo->prepare(
        'SELECT class_id
         FROM student_class_enrollments
         WHERE student_id = :student_id
           AND is_active = 1'
    );
    $classIdsStmt->execute(['student_id' => $studentId]);
    $classIds = array_map(static fn(array $row): int => (int)$row['class_id'], $classIdsStmt->fetchAll());

    $notifSql =
        'SELECT COUNT(*)
         FROM notifications n
         LEFT JOIN notification_reads nr
             ON nr.notification_id = n.id AND nr.user_id = :user_id
         WHERE nr.id IS NULL
           AND (
               n.target_scope = "all"
               OR (n.target_scope = "role" AND n.target_value = :role)
               OR (n.target_scope = "user" AND n.target_value = :user_id_text)';

    $notifParams = [
        'user_id' => current_user()['id'],
        'role' => ROLE_STUDENT,
        'user_id_text' => (string)current_user()['id'],
    ];

    if (!empty($classIds)) {
        $classPlaceholders = [];
        foreach ($classIds as $index => $classId) {
            $key = 'class_' . $index;
            $classPlaceholders[] = ':' . $key;
            $notifParams[$key] = (string)$classId;
        }
        $notifSql .= ' OR (n.target_scope = "class" AND n.target_value IN (' . implode(', ', $classPlaceholders) . '))';
    }

    $notifSql .= ')';

    $notifStmt = $pdo->prepare($notifSql);
    $notifStmt->execute($notifParams);
    $unreadNotifications = (int)$notifStmt->fetchColumn();
}

$attendancePercent = $attendanceTotal > 0 ? round(($attendancePresent / $attendanceTotal) * 100, 1) : 0;

$pageTitle = 'Student Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="dashboard-content">
    <section class="card dashboard-hero">
        <h3>My Learning Snapshot</h3>
        <p>Review attendance consistency, fee standing, homework load, and notifications in one place.</p>
    </section>

    <section class="card">
        <h3>Attendance Overview</h3>
        <div class="metrics-grid metrics-grid-tight">
            <a class="metric-card" href="/itierp/modules/attendance/view.php#section-table">
                <p class="metric-label">Attendance %</p>
                <p class="metric-value"><?= number_format($attendancePercent, 1) ?>%</p>
            </a>
            <a class="metric-card" href="/itierp/modules/attendance/view.php#section-table">
                <p class="metric-label">Attendance Records</p>
                <p class="metric-value"><?= $attendanceTotal ?></p>
            </a>
        </div>
    </section>

    <div class="metrics-grid">
        <a class="metric-card" href="/itierp/modules/fees/student_fees.php#section-table">
            <p class="metric-label">Pending Fees</p>
            <p class="metric-value">₹<?= number_format($pendingFees, 2) ?></p>
        </a>
        <a class="metric-card" href="/itierp/modules/academics/homework.php#section-table">
            <p class="metric-label">Homework Items</p>
            <p class="metric-value"><?= $homeworkCount ?></p>
        </a>
        <a class="metric-card" href="/itierp/modules/notification/inbox.php#section-table">
            <p class="metric-label">Unread Notifications</p>
            <p class="metric-value"><?= $unreadNotifications ?></p>
        </a>
    </div>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
