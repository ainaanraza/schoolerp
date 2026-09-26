<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN]);

$errors = [];
$success = [];

function inventory_table_available(PDO $pdo): bool
{
    return (bool)$pdo->query("SHOW TABLES LIKE 'inventory_items'")->fetchColumn();
}

function inventory_status_label(string $status): string
{
    return $status === 'active' ? 'Active' : 'Inactive';
}

function generate_inventory_sku(PDO $pdo): string
{
    $checkStmt = $pdo->prepare('SELECT id FROM inventory_items WHERE sku = :sku LIMIT 1');

    do {
        $candidate = 'INV-' . date('YmdHis') . '-' . str_pad((string)mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
        $checkStmt->execute(['sku' => $candidate]);
    } while ($checkStmt->fetch());

    return $candidate;
}

$inventoryTableExists = inventory_table_available($pdo);
if (!$inventoryTableExists) {
    $errors[] = 'Inventory tables are not installed yet. Please run the latest database migration.';
}

$formValues = [
    'item_name' => '',
    'category' => '',
    'quantity' => '0',
    'unit_cost' => '0.00',
    'status' => 'active',
];

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $success[] = 'Inventory item updated successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inventoryTableExists) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_item') {
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

        $allowedStatuses = ['active', 'inactive'];
        if (!in_array($status, $allowedStatuses, true)) {
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
            $sku = generate_inventory_sku($pdo);
            $insertStmt = $pdo->prepare(
                'INSERT INTO inventory_items (sku, item_name, category, quantity, unit_cost, status, created_by)
                 VALUES (:sku, :item_name, :category, :quantity, :unit_cost, :status, :created_by)'
            );
            $insertStmt->execute([
                'sku' => $sku,
                'item_name' => $itemName,
                'category' => $category !== '' ? $category : null,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'status' => $status,
                'created_by' => (int)current_user()['id'],
            ]);

            $success[] = 'Inventory item created successfully.';
            $formValues = [
                'item_name' => '',
                'category' => '',
                'quantity' => '0',
                'unit_cost' => '0.00',
                'status' => 'active',
            ];
        }
    }

    if ($action === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId <= 0) {
            $errors[] = 'Invalid item selected for delete.';
        } else {
            $deleteStmt = $pdo->prepare('DELETE FROM inventory_items WHERE id = :id LIMIT 1');
            $deleteStmt->execute(['id' => $itemId]);

            if ($deleteStmt->rowCount() > 0) {
                $success[] = 'Inventory item deleted successfully.';
            } else {
                $errors[] = 'Item not found or already deleted.';
            }
        }
    }

    if ($action === 'edit_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $itemName = trim((string)($_POST['item_name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $quantityRaw = trim((string)($_POST['quantity'] ?? '0'));
        $unitCostRaw = trim((string)($_POST['unit_cost'] ?? '0'));
        $status = (string)($_POST['status'] ?? 'active');

        if ($itemId <= 0) {
            $errors[] = 'Invalid item selected for update.';
        }

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

            if ($updateStmt->rowCount() > 0) {
                $success[] = 'Inventory item updated successfully.';
            } else {
                $success[] = 'No changes detected for inventory item.';
            }
        }
    }
}

$searchQuery = trim((string)($_GET['q'] ?? ''));
$items = [];
$inventorySummary = [
    'total_items' => 0,
    'total_units' => 0,
    'stock_value' => 0,
];

if ($inventoryTableExists) {
    $summaryRow = $pdo->query(
        'SELECT COUNT(*) AS total_items,
                COALESCE(SUM(quantity), 0) AS total_units,
                COALESCE(SUM(quantity * unit_cost), 0) AS stock_value
         FROM inventory_items'
    )->fetch();

    if ($summaryRow) {
        $inventorySummary = [
            'total_items' => (int)($summaryRow['total_items'] ?? 0),
            'total_units' => (int)($summaryRow['total_units'] ?? 0),
            'stock_value' => (float)($summaryRow['stock_value'] ?? 0),
        ];
    }

    $sql =
        'SELECT i.id, i.sku, i.item_name, i.category, i.quantity, i.unit_cost, i.status, i.updated_at,
                u.full_name AS created_by_name
         FROM inventory_items i
         LEFT JOIN users u ON u.id = i.created_by
         WHERE 1 = 1';
    $params = [];

    if ($searchQuery !== '') {
        $sql .= ' AND (i.item_name LIKE :query OR i.category LIKE :query)';
        $params['query'] = '%' . $searchQuery . '%';
    }

    $sql .= ' ORDER BY i.id DESC';

    $itemsStmt = $pdo->prepare($sql);
    $itemsStmt->execute($params);
    $items = $itemsStmt->fetchAll();
}

$pageTitle = 'Inventory Management';
require __DIR__ . '/../../includes/header.php';
?>
<h2>Inventory Management</h2>
<p>Create, update, and maintain stock baseline records for school operations.</p>

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

    <?php if ($inventoryTableExists): ?>
        <div class="grid-3">
            <div class="card">
                <h3>Total Items</h3>
                <p><strong><?= (int)$inventorySummary['total_items'] ?></strong></p>
            </div>
            <div class="card">
                <h3>Total Units</h3>
                <p><strong><?= (int)$inventorySummary['total_units'] ?></strong></p>
            </div>
            <div class="card">
                <h3>Total Stock Value</h3>
                <p><strong>₹<?= number_format((float)$inventorySummary['stock_value'], 2) ?></strong></p>
            </div>
        </div>

        <section class="card" id="section-table">
            <div class="form-header-actions">
                <h3>Inventory Items</h3>
                <button type="button" class="btn-toggle-form" onclick="toggleForm('form-create-item', this)">+ Add Item</button>
            </div>

            <div class="toolbar-row">
                <div class="toolbar-actions-tight">
                    <a class="nav-item" href="/itierp/modules/inventory/export_items_excel.php">Download Excel</a>
                </div>
            </div>

            <div class="table-wrap">
                <table class="compact-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Item</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Unit Cost</th>
                            <th>Stock Value</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= (int)$item['id'] ?></td>
                                <td><?= htmlspecialchars((string)$item['item_name']) ?></td>
                                <td><?= htmlspecialchars((string)($item['category'] ?? '')) ?></td>
                                <td><?= (int)$item['quantity'] ?></td>
                                <td>₹<?= number_format((float)$item['unit_cost'], 2) ?></td>
                                <td>₹<?= number_format((float)$item['quantity'] * (float)$item['unit_cost'], 2) ?></td>
                                <td><span class="pill"><?= htmlspecialchars(inventory_status_label((string)$item['status'])) ?></span></td>
                                <td><?= htmlspecialchars((string)$item['updated_at']) ?></td>
                                <td>
                                    <button
                                        type="button"
                                        class="nav-item inventory-action-btn"
                                        data-item-id="<?= (int)$item['id'] ?>"
                                        data-item-name="<?= htmlspecialchars((string)$item['item_name'], ENT_QUOTES) ?>"
                                        data-item-category="<?= htmlspecialchars((string)($item['category'] ?? ''), ENT_QUOTES) ?>"
                                        data-item-quantity="<?= (int)$item['quantity'] ?>"
                                        data-item-unit-cost="<?= number_format((float)$item['unit_cost'], 2, '.', '') ?>"
                                        data-item-status="<?= htmlspecialchars((string)$item['status'], ENT_QUOTES) ?>"
                                        onclick="openEditItemModal(this)">
                                        Edit
                                    </button>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this inventory item?');">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="btn-compact">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="9">No inventory items found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div id="form-create-item" class="collapsible-form">
            <section class="card">
                <h3>Add Inventory Item</h3>
                <form method="post" class="form-grid form-grid-wide">
                    <input type="hidden" name="action" value="create_item">

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

                    <button type="submit">Save Item</button>
                </form>
            </section>
        </div>

        <div id="form-edit-item" class="collapsible-form">
            <section class="card">
                <h3>Edit Inventory Item</h3>
                <form method="post" class="form-grid form-grid-wide">
                    <input type="hidden" name="action" value="edit_item">
                    <input type="hidden" name="item_id" id="editItemId" value="">

                    <label>Item Name</label>
                    <input type="text" name="item_name" id="editItemName" required>

                    <label>Category</label>
                    <input type="text" name="category" id="editItemCategory">

                    <label>Quantity</label>
                    <input type="number" name="quantity" id="editItemQuantity" min="0" step="1" required>

                    <label>Unit Cost</label>
                    <input type="number" name="unit_cost" id="editItemUnitCost" min="0" step="0.01" required>

                    <label>Status</label>
                    <select name="status" id="editItemStatus">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>

                    <button type="submit">Save Changes</button>
                </form>
            </section>
        </div>

        <script>
        function openEditItemModal(button) {
            var itemIdInput = document.getElementById('editItemId');
            var itemNameInput = document.getElementById('editItemName');
            var itemCategoryInput = document.getElementById('editItemCategory');
            var itemQuantityInput = document.getElementById('editItemQuantity');
            var itemUnitCostInput = document.getElementById('editItemUnitCost');
            var itemStatusInput = document.getElementById('editItemStatus');

            if (!button || !itemIdInput || !itemNameInput || !itemCategoryInput || !itemQuantityInput || !itemUnitCostInput || !itemStatusInput) {
                return;
            }

            itemIdInput.value = button.dataset.itemId || '';
            itemNameInput.value = button.dataset.itemName || '';
            itemCategoryInput.value = button.dataset.itemCategory || '';
            itemQuantityInput.value = button.dataset.itemQuantity || '0';
            itemUnitCostInput.value = button.dataset.itemUnitCost || '0.00';
            itemStatusInput.value = button.dataset.itemStatus || 'active';

            toggleForm('form-edit-item', button);
        }
        </script>
    <?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
