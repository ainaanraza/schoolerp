<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN]);

$errors = [];

function inventory_table_available(PDO $pdo): bool
{
    return (bool)$pdo->query("SHOW TABLES LIKE 'inventory_items'")->fetchColumn();
}

$inventoryTableExists = inventory_table_available($pdo);
if (!$inventoryTableExists) {
    $errors[] = 'Inventory tables are not installed yet. Please run the latest database migration.';
}

$itemId = (int)($_GET['id'] ?? $_POST['item_id'] ?? 0);
$item = null;

if ($inventoryTableExists) {
    $itemStmt = $pdo->prepare('SELECT * FROM inventory_items WHERE id = :id LIMIT 1');
    $itemStmt->execute(['id' => $itemId]);
    $item = $itemStmt->fetch();
}

if ($inventoryTableExists && !$item) {
    http_response_code(404);
    echo 'Inventory item not found.';
    exit;
}

$formValues = [
    'item_name' => (string)($item['item_name'] ?? ''),
    'category' => (string)($item['category'] ?? ''),
    'quantity' => (string)($item['quantity'] ?? '0'),
    'unit_cost' => (string)($item['unit_cost'] ?? '0.00'),
    'status' => (string)($item['status'] ?? 'active'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inventoryTableExists && $item) {
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $quantityRaw = trim((string)($_POST['quantity'] ?? '0'));
    $unitCostRaw = trim((string)($_POST['unit_cost'] ?? '0'));
    $status = (string)($_POST['status'] ?? 'active');

    $formValues = [
        'item_name' => $itemName,
        'category' => $category,
        'quantity' => $quantityRaw,
        'unit_cost' => $unitCostRaw,
        'status' => $status,
    ];

    if ($itemName === '') {
        $errors[] = 'Item name is required.';
    }

    if (filter_var($quantityRaw, FILTER_VALIDATE_INT) === false) {
        $errors[] = 'Quantity must be a whole number.';
    }

    if (!is_numeric($unitCostRaw)) {
        $errors[] = 'Unit cost must be a valid number.';
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors[] = 'Invalid status selected.';
    }

    $quantity = (int)$quantityRaw;
    $unitCost = (float)$unitCostRaw;

    if ($quantity < 0) {
        $errors[] = 'Quantity cannot be negative.';
    }

    if ($unitCost < 0) {
        $errors[] = 'Unit cost cannot be negative.';
    }

    if (empty($errors)) {
        $updateStmt = $pdo->prepare(
            'UPDATE inventory_items
             SET item_name = :item_name,
                 category = :category,
                 quantity = :quantity,
                 unit_cost = :unit_cost,
                 status = :status
             WHERE id = :id'
        );
        $updateStmt->execute([
            'item_name' => $itemName,
            'category' => $category !== '' ? $category : null,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'status' => $status,
            'id' => $itemId,
        ]);

        header('Location: /itierp/modules/inventory/items.php?updated=1');
        exit;
    }
}

$pageTitle = 'Edit Inventory Item';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Edit Inventory Item</h2>
    <p>Update stock quantity, unit cost, category, and active state.</p>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($inventoryTableExists && $item): ?>
        <form method="post" class="form-grid form-grid-wide">
            <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">

            <label>Item Name</label>
            <input type="text" name="item_name" value="<?= htmlspecialchars($formValues['item_name']) ?>" required>

            <label>Category</label>
            <input type="text" name="category" value="<?= htmlspecialchars($formValues['category']) ?>">

            <label>Quantity</label>
            <input type="number" name="quantity" min="0" step="1" value="<?= htmlspecialchars($formValues['quantity']) ?>" required>

            <label>Unit Cost</label>
            <input type="number" name="unit_cost" min="0" step="0.01" value="<?= htmlspecialchars($formValues['unit_cost']) ?>" required>

            <label>Status</label>
            <select name="status">
                <option value="active" <?= $formValues['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $formValues['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>

            <button type="submit">Save Changes</button>
        </form>

        <div class="toolbar-row toolbar-row-end">
            <a class="nav-item" href="/itierp/modules/inventory/items.php#section-table">Back to Inventory</a>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
