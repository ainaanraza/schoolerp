<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_roles([ROLE_PARENT]);

$childrenStmt = $pdo->prepare(
    'SELECT ps.student_id
     FROM parents p
     JOIN parent_student ps ON ps.parent_id = p.id
     WHERE p.user_id = :user_id'
);
$childrenStmt->execute(['user_id' => current_user()['id']]);
$childIds = array_map(static fn(array $row): int => (int)$row['student_id'], $childrenStmt->fetchAll());

$childrenCount = count($childIds);
$pendingFees = 0.0;
$todayAbsent = 0;
$unreadNotifications = 0;

if (!empty($childIds)) {
    $feePlaceholders = [];
    $feeParams = [];
    foreach ($childIds as $index => $childId) {
        $key = 'child_' . $index;
        $feePlaceholders[] = ':' . $key;
        $feeParams[$key] = $childId;
    }

    $feeStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(payable_amount - paid_amount), 0)
         FROM student_fees
         WHERE student_id IN (' . implode(', ', $feePlaceholders) . ')'
    );
    $feeStmt->execute($feeParams);
    $pendingFees = (float)$feeStmt->fetchColumn();

    $absentStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM attendance
         WHERE student_id IN (' . implode(', ', $feePlaceholders) . ')
           AND attendance_date = CURDATE()
           AND status = "absent"'
    );
    $absentStmt->execute($feeParams);
    $todayAbsent = (int)$absentStmt->fetchColumn();

    $classStmt = $pdo->prepare(
        'SELECT DISTINCT class_id
         FROM student_class_enrollments
         WHERE student_id IN (' . implode(', ', $feePlaceholders) . ')
           AND is_active = 1'
    );
    $classStmt->execute($feeParams);
    $classIds = array_map(static fn(array $row): int => (int)$row['class_id'], $classStmt->fetchAll());

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
        'role' => ROLE_PARENT,
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

$pageTitle = 'Parent Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="dashboard-content">
    <div class="metrics-grid">
        <a class="metric-card" href="/school-erp/parent/children.php#section-table">
            <p class="metric-label">Linked Children</p>
            <p class="metric-value"><?= $childrenCount ?></p>
        </a>
        <a class="metric-card" href="/school-erp/parent/fees.php#section-table">
            <p class="metric-label">Pending Fees</p>
            <p class="metric-value">₹<?= number_format($pendingFees, 2) ?></p>
        </a>
        <a class="metric-card" href="/school-erp/parent/attendance.php#section-table">
            <p class="metric-label">Absent Alerts Today</p>
            <p class="metric-value"><?= $todayAbsent ?></p>
        </a>
        <a class="metric-card" href="/school-erp/parent/notifications.php#section-table">
            <p class="metric-label">Unread Notifications</p>
            <p class="metric-value"><?= $unreadNotifications ?></p>
        </a>
    </div>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
