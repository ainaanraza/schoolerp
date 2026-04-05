<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$admissionSummary = $pdo->query(
    'SELECT
        SUM(CASE WHEN status = "new" THEN 1 ELSE 0 END) AS new_leads,
        SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) AS approved_leads
     FROM leads'
)->fetch();

$studentCount = $pdo->query('SELECT COUNT(*) FROM students WHERE status = "enrolled"')->fetchColumn();

$financeSummary = $pdo->query(
    'SELECT
        COALESCE(SUM(paid_amount), 0) AS paid,
        COALESCE(SUM(payable_amount - paid_amount), 0) AS pending
     FROM student_fees'
)->fetch();

$attendanceToday = $pdo->query(
    'SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()'
)->fetchColumn();

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="dashboard-content">
    <div class="metrics-grid">
        <a class="metric-card" href="/school-erp/modules/admission/leads.php#section-table">
            <p class="metric-label">New Leads</p>
            <p class="metric-value"><?= (int)$admissionSummary['new_leads'] ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/admission/leads.php#section-table">
            <p class="metric-label">Approved Leads</p>
            <p class="metric-value"><?= (int)$admissionSummary['approved_leads'] ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/admission/students.php#section-table">
            <p class="metric-label">Enrolled Students</p>
            <p class="metric-value"><?= (int)$studentCount ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/attendance/manage.php#section-table">
            <p class="metric-label">Attendance Today</p>
            <p class="metric-value"><?= (int)$attendanceToday ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/fees/student_fees.php#section-table">
            <p class="metric-label">Fees Paid</p>
            <p class="metric-value">₹<?= number_format((float)$financeSummary['paid'], 2) ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/fees/student_fees.php#section-table">
            <p class="metric-label">Fees Pending</p>
            <p class="metric-value">₹<?= number_format((float)$financeSummary['pending'], 2) ?></p>
        </a>
    </div>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
