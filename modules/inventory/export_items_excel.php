<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN]);

$tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'inventory_items'")->fetchColumn();
if (!$tableExists) {
    http_response_code(400);
    echo 'Inventory tables are not installed yet.';
    exit;
}

$itemsStmt = $pdo->query(
    'SELECT id, item_name, category, quantity, unit_cost, status, created_at, updated_at
     FROM inventory_items
     ORDER BY id DESC'
);
$items = $itemsStmt->fetchAll();

$filename = 'inventory_items_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

echo "<table border='1'>";
echo '<tr>';
echo '<th>ID</th>';
echo '<th>Item Name</th>';
echo '<th>Category</th>';
echo '<th>Quantity</th>';
echo '<th>Unit Cost</th>';
echo '<th>Stock Value</th>';
echo '<th>Status</th>';
echo '<th>Created At</th>';
echo '<th>Updated At</th>';
echo '</tr>';

foreach ($items as $item) {
    $quantity = (int)$item['quantity'];
    $unitCost = (float)$item['unit_cost'];
    $stockValue = $quantity * $unitCost;

    echo '<tr>';
    echo '<td>' . (int)$item['id'] . '</td>';
    echo '<td>' . htmlspecialchars((string)$item['item_name']) . '</td>';
    echo '<td>' . htmlspecialchars((string)($item['category'] ?? '')) . '</td>';
    echo '<td>' . $quantity . '</td>';
    echo '<td>' . number_format($unitCost, 2, '.', '') . '</td>';
    echo '<td>' . number_format($stockValue, 2, '.', '') . '</td>';
    echo '<td>' . htmlspecialchars((string)$item['status']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$item['created_at']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$item['updated_at']) . '</td>';
    echo '</tr>';
}

echo '</table>';
exit;
