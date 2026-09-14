<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN]);

$userSummary = $pdo->query(
    'SELECT
        SUM(CASE WHEN role = "admin" THEN 1 ELSE 0 END) AS admins,
        SUM(CASE WHEN role = "teacher" THEN 1 ELSE 0 END) AS teachers,
        SUM(CASE WHEN role = "student" THEN 1 ELSE 0 END) AS students,
        SUM(CASE WHEN role = "parent" THEN 1 ELSE 0 END) AS parents
     FROM users
     WHERE is_active = 1'
)->fetch();

$leadSummary = $pdo->query(
    'SELECT
        SUM(CASE WHEN status IN ("new", "contacted") THEN 1 ELSE 0 END) AS open_leads,
        SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = "enrolled" THEN 1 ELSE 0 END) AS enrolled
     FROM leads'
)->fetch();

$financeSummary = $pdo->query(
    'SELECT
        COALESCE(SUM(payable_amount), 0) AS payable,
        COALESCE(SUM(paid_amount), 0) AS paid,
        COALESCE(SUM(payable_amount - paid_amount), 0) AS pending
     FROM student_fees'
)->fetch();

$todayAttendance = $pdo->query(
    'SELECT COUNT(*)
     FROM attendance
     WHERE attendance_date = CURDATE()'
)->fetchColumn();

$inventorySummary = [
    'total_items' => 0,
    'total_units' => 0,
    'stock_value' => 0,
];

$inventoryTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'inventory_items'")->fetchColumn();
if ($inventoryTableExists) {
    $inventoryRow = $pdo->query(
        'SELECT COUNT(*) AS total_items,
                COALESCE(SUM(quantity), 0) AS total_units,
                COALESCE(SUM(quantity * unit_cost), 0) AS stock_value
         FROM inventory_items'
    )->fetch();

    if ($inventoryRow) {
        $inventorySummary = [
            'total_items' => (int)($inventoryRow['total_items'] ?? 0),
            'total_units' => (int)($inventoryRow['total_units'] ?? 0),
            'stock_value' => (float)($inventoryRow['stock_value'] ?? 0),
        ];
    }
}

$pageTitle = 'Super Admin Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="dashboard-content">
    <section class="card dashboard-hero">
        <h3>Dashboard Overview</h3>
        <p>Review key KPIs, admissions, attendance, and finance status.</p>
    </section>

    <div class="metrics-grid">
        <a class="metric-card" href="/school-erp/modules/admission/admin_management.php#section-table">
            <p class="metric-label">Active Admins</p>
            <p class="metric-value"><?= (int)$userSummary['admins'] ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/admission/admin_management.php#section-table">
            <p class="metric-label">Active Teachers</p>
            <p class="metric-value"><?= (int)$userSummary['teachers'] ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/admission/students.php#section-table">
            <p class="metric-label">Active Students</p>
            <p class="metric-value"><?= (int)$userSummary['students'] ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/admission/parent_linking.php#section-table">
            <p class="metric-label">Active Parents</p>
            <p class="metric-value"><?= (int)$userSummary['parents'] ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/admission/leads.php#section-table">
            <p class="metric-label">Open Leads</p>
            <p class="metric-value"><?= (int)$leadSummary['open_leads'] ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/admission/leads.php#section-table">
            <p class="metric-label">Lead Enrolled</p>
            <p class="metric-value"><?= (int)$leadSummary['enrolled'] ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/fees/superadmin_finance.php#section-table">
            <p class="metric-label">Fee Pending</p>
            <p class="metric-value">₹<?= number_format((float)$financeSummary['pending'], 2) ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/attendance/manage.php#section-table">
            <p class="metric-label">Attendance Today</p>
            <p class="metric-value"><?= (int)$todayAttendance ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/inventory/items.php#section-table">
            <p class="metric-label">Inventory Items</p>
            <p class="metric-value"><?= (int)$inventorySummary['total_items'] ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/inventory/items.php#section-table">
            <p class="metric-label">Inventory Units</p>
            <p class="metric-value"><?= (int)$inventorySummary['total_units'] ?></p>
        </a>
        <a class="metric-card" href="/school-erp/modules/inventory/items.php#section-table">
            <p class="metric-label">Inventory Value</p>
            <p class="metric-value">₹<?= number_format((float)$inventorySummary['stock_value'], 2) ?></p>
        </a>
    </div>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
