<?php
require_once __DIR__ . '/_auth.php';
developer_require_login();
require_once __DIR__ . '/../config/db.php';

function dev_is_valid_identifier(string $identifier): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_]+$/', $identifier);
}

function dev_quote_identifier(string $identifier): string
{
    if (!dev_is_valid_identifier($identifier)) {
        throw new InvalidArgumentException('Invalid identifier: ' . $identifier);
    }

    return '`' . $identifier . '`';
}

function dev_table_names(PDO $pdo): array
{
    $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $tables = [];

    foreach ($rows as $row) {
        if (isset($row[0]) && is_string($row[0]) && dev_is_valid_identifier($row[0])) {
            $tables[] = $row[0];
        }
    }

    sort($tables);
    return $tables;
}

function dev_table_columns(PDO $pdo, string $tableName): array
{
    $statement = $pdo->query('SHOW FULL COLUMNS FROM ' . dev_quote_identifier($tableName));
    return $statement->fetchAll();
}

function dev_primary_columns(array $columns): array
{
    $primaryColumns = [];
    foreach ($columns as $column) {
        if (($column['Key'] ?? '') === 'PRI') {
            $primaryColumns[] = (string)$column['Field'];
        }
    }

    return $primaryColumns;
}

function dev_is_auto_increment(array $column): bool
{
    return stripos((string)($column['Extra'] ?? ''), 'auto_increment') !== false;
}

function dev_is_nullable(array $column): bool
{
    return strtoupper((string)($column['Null'] ?? '')) === 'YES';
}

function dev_is_numeric_type(string $columnType): bool
{
    $normalized = strtolower($columnType);
    return str_contains($normalized, 'int')
        || str_contains($normalized, 'decimal')
        || str_contains($normalized, 'float')
        || str_contains($normalized, 'double')
        || str_contains($normalized, 'bit');
}

function dev_enum_options(string $columnType): array
{
    $normalized = trim($columnType);
    if (!str_starts_with(strtolower($normalized), 'enum(')) {
        return [];
    }

    $inner = substr($normalized, 5, -1);
    if ($inner === false) {
        return [];
    }

    $parts = str_getcsv($inner, ',', "'", '\\');
    $options = [];
    foreach ($parts as $part) {
        $options[] = stripcslashes($part);
    }

    return $options;
}

function dev_value_from_post(string $field, array $column): mixed
{
    $columnType = strtolower((string)($column['Type'] ?? ''));
    $raw = isset($_POST['col'][$field]) ? trim((string)$_POST['col'][$field]) : '';

    if ($raw === '') {
        if (dev_is_nullable($column)) {
            return null;
        }

        $default = $column['Default'] ?? null;
        if ($default !== null) {
            return '__USE_DEFAULT__';
        }

        return '';
    }

    if (str_starts_with($columnType, 'datetime')) {
        $normalized = str_replace('T', ' ', $raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
            $normalized .= ':00';
        }
        return $normalized;
    }

    if (str_starts_with($columnType, 'date') || str_starts_with($columnType, 'time')) {
        return $raw;
    }

    if ($columnType === 'tinyint(1)') {
        return in_array($raw, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    if (dev_is_numeric_type($columnType)) {
        return is_numeric($raw) ? $raw + 0 : $raw;
    }

    return $raw;
}

function dev_fetch_row_by_pk(PDO $pdo, string $tableName, array $pkColumns, array $source, string $prefix): ?array
{
    if (empty($pkColumns)) {
        return null;
    }

    $whereParts = [];
    $params = [];

    foreach ($pkColumns as $pkColumn) {
        $key = $prefix . $pkColumn;
        if (!array_key_exists($key, $source)) {
            return null;
        }

        $whereParts[] = dev_quote_identifier($pkColumn) . ' = :' . $pkColumn;
        $params[$pkColumn] = (string)$source[$key];
    }

    $sql = 'SELECT * FROM ' . dev_quote_identifier($tableName)
        . ' WHERE ' . implode(' AND ', $whereParts)
        . ' LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $row = $statement->fetch();
    return $row ?: null;
}

$errors = [];
$success = [];

$tableNames = dev_table_names($pdo);
$selectedTable = trim((string)($_GET['table'] ?? $_POST['table'] ?? ''));

if ($selectedTable === '' && !empty($tableNames)) {
    $selectedTable = $tableNames[0];
}

if ($selectedTable !== '' && !in_array($selectedTable, $tableNames, true)) {
    $errors[] = 'Invalid table selected.';
    $selectedTable = !empty($tableNames) ? $tableNames[0] : '';
}

$columns = [];
$primaryColumns = [];
if ($selectedTable !== '') {
    try {
        $columns = dev_table_columns($pdo, $selectedTable);
        $primaryColumns = dev_primary_columns($columns);
    } catch (Throwable $throwable) {
        $errors[] = 'Unable to inspect table: ' . $throwable->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($selectedTable !== '' && in_array($action, ['insert_row', 'update_row', 'delete_row'], true)) {
        try {
            if ($action === 'insert_row') {
                if (empty($columns)) {
                    throw new RuntimeException('No table columns available for insert.');
                }

                $insertColumns = [];
                $placeholders = [];
                $params = [];

                foreach ($columns as $column) {
                    $field = (string)$column['Field'];
                    if (dev_is_auto_increment($column)) {
                        continue;
                    }

                    $value = dev_value_from_post($field, $column);
                    if ($value === '__USE_DEFAULT__') {
                        continue;
                    }

                    $insertColumns[] = dev_quote_identifier($field);
                    $placeholders[] = ':' . $field;
                    $params[$field] = $value;
                }

                if (empty($insertColumns)) {
                    throw new RuntimeException('No insertable fields available.');
                }

                $sql = 'INSERT INTO ' . dev_quote_identifier($selectedTable)
                    . ' (' . implode(', ', $insertColumns) . ')'
                    . ' VALUES (' . implode(', ', $placeholders) . ')';

                $statement = $pdo->prepare($sql);
                $statement->execute($params);
                $success[] = 'Row inserted successfully.';
            }

            if ($action === 'update_row') {
                if (empty($primaryColumns)) {
                    throw new RuntimeException('Table has no primary key. Update is disabled.');
                }

                $setParts = [];
                $params = [];

                foreach ($columns as $column) {
                    $field = (string)$column['Field'];
                    if (in_array($field, $primaryColumns, true) || dev_is_auto_increment($column)) {
                        continue;
                    }

                    $value = dev_value_from_post($field, $column);
                    if ($value === '__USE_DEFAULT__') {
                        continue;
                    }

                    $setParts[] = dev_quote_identifier($field) . ' = :' . $field;
                    $params[$field] = $value;
                }

                if (empty($setParts)) {
                    throw new RuntimeException('No editable fields found for update.');
                }

                $whereParts = [];
                foreach ($primaryColumns as $pkColumn) {
                    $pkKey = 'pk_' . $pkColumn;
                    if (!isset($_POST[$pkKey])) {
                        throw new RuntimeException('Primary key value missing for update.');
                    }
                    $whereParts[] = dev_quote_identifier($pkColumn) . ' = :__pk_' . $pkColumn;
                    $params['__pk_' . $pkColumn] = (string)$_POST[$pkKey];
                }

                $sql = 'UPDATE ' . dev_quote_identifier($selectedTable)
                    . ' SET ' . implode(', ', $setParts)
                    . ' WHERE ' . implode(' AND ', $whereParts)
                    . ' LIMIT 1';

                $statement = $pdo->prepare($sql);
                $statement->execute($params);
                $success[] = 'Row updated successfully.';
            }

            if ($action === 'delete_row') {
                if (empty($primaryColumns)) {
                    throw new RuntimeException('Table has no primary key. Delete is disabled.');
                }

                $whereParts = [];
                $params = [];
                foreach ($primaryColumns as $pkColumn) {
                    $pkKey = 'pk_' . $pkColumn;
                    if (!isset($_POST[$pkKey])) {
                        throw new RuntimeException('Primary key value missing for delete.');
                    }
                    $whereParts[] = dev_quote_identifier($pkColumn) . ' = :' . $pkColumn;
                    $params[$pkColumn] = (string)$_POST[$pkKey];
                }

                $sql = 'DELETE FROM ' . dev_quote_identifier($selectedTable)
                    . ' WHERE ' . implode(' AND ', $whereParts)
                    . ' LIMIT 1';

                $statement = $pdo->prepare($sql);
                $statement->execute($params);
                $success[] = 'Row deleted successfully.';
            }

            if ($selectedTable !== '') {
                $columns = dev_table_columns($pdo, $selectedTable);
                $primaryColumns = dev_primary_columns($columns);
            }
        } catch (Throwable $throwable) {
            $errors[] = 'Database action failed: ' . $throwable->getMessage();
        }
    }
}

$searchQuery = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalRows = 0;
$dataRows = [];
$editRow = null;

if ($selectedTable !== '' && !empty($columns)) {
    $columnNames = array_map(static fn(array $column): string => (string)$column['Field'], $columns);
    $whereSql = '';
    $params = [];

    if ($searchQuery !== '') {
        $searchParts = [];
        foreach ($columnNames as $columnName) {
            $searchParts[] = 'CAST(' . dev_quote_identifier($columnName) . ' AS CHAR) LIKE :search_query';
        }

        if (!empty($searchParts)) {
            $whereSql = ' WHERE (' . implode(' OR ', $searchParts) . ')';
            $params['search_query'] = '%' . $searchQuery . '%';
        }
    }

    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM ' . dev_quote_identifier($selectedTable) . $whereSql);
    $countStatement->execute($params);
    $totalRows = (int)$countStatement->fetchColumn();

    $orderByColumn = !empty($primaryColumns) ? $primaryColumns[0] : $columnNames[0];
    $dataSql = 'SELECT * FROM ' . dev_quote_identifier($selectedTable)
        . $whereSql
        . ' ORDER BY ' . dev_quote_identifier($orderByColumn) . ' DESC'
        . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;

    $dataStatement = $pdo->prepare($dataSql);
    $dataStatement->execute($params);
    $dataRows = $dataStatement->fetchAll();

    if (!empty($primaryColumns)) {
        $editRow = dev_fetch_row_by_pk($pdo, $selectedTable, $primaryColumns, $_GET, 'edit_pk_');
    }
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/school-erp/assets/css/style.css?v=<?= time() ?>">
    <script>
    function closeAllDevForms() {
        var overlay = document.getElementById('modal-overlay');
        document.querySelectorAll('.collapsible-form.active').forEach(function(form) {
            form.classList.remove('active');
        });
        if (overlay) {
            overlay.classList.remove('active');
        }
        document.body.classList.remove('modal-open');
    }

    function toggleDevForm(id) {
        var form = document.getElementById(id);
        var overlay = document.getElementById('modal-overlay');
        if (!form || !overlay) {
            return;
        }

        var shouldOpen = !form.classList.contains('active');
        closeAllDevForms();

        if (shouldOpen) {
            if (!form.querySelector('.modal-close-btn')) {
                var closeBtn = document.createElement('span');
                closeBtn.className = 'modal-close-btn';
                closeBtn.innerHTML = '&times;';
                closeBtn.onclick = function() {
                    closeAllDevForms();
                };
                form.prepend(closeBtn);
            }

            requestAnimationFrame(function() {
                form.classList.add('active');
                overlay.classList.add('active');
                document.body.classList.add('modal-open');
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var overlay = document.getElementById('modal-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'modal-overlay';
            overlay.className = 'modal-overlay';
            document.body.appendChild(overlay);
        }

        overlay.onclick = function () {
            closeAllDevForms();
        };

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAllDevForms();
            }
        });
    });
    </script>
</head>
<body class="is-authenticated">
    <main class="container" style="padding-top: 24px;">
        <section class="card">
            <div class="form-header-actions">
                <div>
                    <h2>Developer Portal (Standalone)</h2>
                    <p>Separate authentication and separate session from all school portals.</p>
                </div>
                <div class="toolbar-actions-tight">
                    <a class="nav-item" href="/school-erp/developer/logout.php">Logout</a>
                </div>
            </div>

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

            <div class="grid-2">
                <div class="card">
                    <h3>Portal Quick Links</h3>
                    <p style="margin-bottom: 10px;">Open portal entry points from one place.</p>
                    <div class="toolbar-row">
                        <a class="nav-item" href="/school-erp/index.php" target="_blank" rel="noopener">Main Login</a>
                        <a class="nav-item" href="/school-erp/superadmin/dashboard.php" target="_blank" rel="noopener">Super Admin</a>
                        <a class="nav-item" href="/school-erp/admin/dashboard.php" target="_blank" rel="noopener">Admin</a>
                        <a class="nav-item" href="/school-erp/teacher/dashboard.php" target="_blank" rel="noopener">Teacher</a>
                        <a class="nav-item" href="/school-erp/student/dashboard.php" target="_blank" rel="noopener">Student</a>
                        <a class="nav-item" href="/school-erp/parent/dashboard.php" target="_blank" rel="noopener">Parent</a>
                    </div>
                </div>

                <div class="card">
                    <h3>Session Info</h3>
                    <p><strong>Developer User:</strong> <?= htmlspecialchars((string)developer_current_user()) ?></p>
                    <p><strong>Session Cookie:</strong> DEVPORTALSESSID</p>
                    <p><strong>Scope:</strong> Independent from school role session cookie.</p>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="form-header-actions">
                <h3>Table Manager (No SQL Required)</h3>
                <?php if ($selectedTable !== ''): ?>
                    <button type="button" class="btn-toggle-form" onclick="toggleDevForm('form-add-row')">+ Add Row</button>
                <?php endif; ?>
            </div>

            <form method="get" class="filter-bar filter-bar-inline">
                <label for="table">Table</label>
                <select id="table" name="table" onchange="this.form.submit()">
                    <?php foreach ($tableNames as $tableName): ?>
                        <option value="<?= htmlspecialchars($tableName) ?>" <?= $tableName === $selectedTable ? 'selected' : '' ?>>
                            <?= htmlspecialchars($tableName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="q" placeholder="Search table rows" value="<?= htmlspecialchars($searchQuery) ?>">
                <button type="submit">Search</button>
                <a href="?table=<?= urlencode($selectedTable) ?>" class="toolbar-link-clear">Clear</a>
            </form>

            <?php if ($selectedTable !== ''): ?>
                <p>
                    <strong>Table:</strong> <?= htmlspecialchars($selectedTable) ?> |
                    <strong>Columns:</strong> <?= count($columns) ?> |
                    <strong>Total Rows:</strong> <?= $totalRows ?>
                </p>

                <?php if (empty($primaryColumns)): ?>
                    <div class="error">
                        <p>This table has no primary key. Edit/Delete are disabled for safety.</p>
                    </div>
                <?php endif; ?>

                <div class="table-wrap" id="section-table">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <th><?= htmlspecialchars((string)$column['Field']) ?></th>
                                <?php endforeach; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dataRows as $row): ?>
                                <tr>
                                    <?php foreach ($columns as $column): ?>
                                        <?php $field = (string)$column['Field']; ?>
                                        <td><?= htmlspecialchars((string)($row[$field] ?? '')) ?></td>
                                    <?php endforeach; ?>
                                    <td>
                                        <?php if (!empty($primaryColumns)): ?>
                                            <?php
                                                $editQuery = [
                                                    'table' => $selectedTable,
                                                    'q' => $searchQuery,
                                                    'page' => $page,
                                                ];
                                                foreach ($primaryColumns as $pkColumn) {
                                                    $editQuery['edit_pk_' . $pkColumn] = (string)($row[$pkColumn] ?? '');
                                                }
                                            ?>
                                            <a class="nav-item" href="?<?= htmlspecialchars(http_build_query($editQuery)) ?>">Edit</a>

                                            <form method="post" class="inline-form" onsubmit="return confirm('Delete this row from <?= htmlspecialchars($selectedTable, ENT_QUOTES) ?>?');">
                                                <input type="hidden" name="action" value="delete_row">
                                                <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                                                <?php foreach ($primaryColumns as $pkColumn): ?>
                                                    <input type="hidden" name="pk_<?= htmlspecialchars($pkColumn) ?>" value="<?= htmlspecialchars((string)($row[$pkColumn] ?? '')) ?>">
                                                <?php endforeach; ?>
                                                <button type="submit" class="btn-compact">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <span>Read only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($dataRows)): ?>
                                <tr>
                                    <td colspan="<?= count($columns) + 1 ?>">No rows found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="toolbar-row">
                        <?php if ($page > 1): ?>
                            <a class="nav-item" href="?<?= htmlspecialchars(http_build_query(['table' => $selectedTable, 'q' => $searchQuery, 'page' => $page - 1])) ?>">Previous</a>
                        <?php endif; ?>
                        <span>Page <?= $page ?> of <?= $totalPages ?></span>
                        <?php if ($page < $totalPages): ?>
                            <a class="nav-item" href="?<?= htmlspecialchars(http_build_query(['table' => $selectedTable, 'q' => $searchQuery, 'page' => $page + 1])) ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($selectedTable !== '' && !empty($columns)): ?>
            <div id="form-add-row" class="collapsible-form">
                <section class="card">
                    <h3>Add Row - <?= htmlspecialchars($selectedTable) ?></h3>
                    <form method="post" class="form-grid form-grid-wide">
                        <input type="hidden" name="action" value="insert_row">
                        <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">

                        <?php foreach ($columns as $column): ?>
                            <?php
                                $field = (string)$column['Field'];
                                $type = strtolower((string)$column['Type']);
                                $enumOptions = dev_enum_options((string)$column['Type']);
                                if (dev_is_auto_increment($column)) {
                                    continue;
                                }
                            ?>
                            <label><?= htmlspecialchars($field) ?></label>
                            <?php if (!empty($enumOptions)): ?>
                                <select name="col[<?= htmlspecialchars($field) ?>]" <?= dev_is_nullable($column) ? '' : 'required' ?>>
                                    <?php if (dev_is_nullable($column)): ?>
                                        <option value="">(null)</option>
                                    <?php endif; ?>
                                    <?php foreach ($enumOptions as $option): ?>
                                        <option value="<?= htmlspecialchars($option) ?>"><?= htmlspecialchars($option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif (str_contains($type, 'text')): ?>
                                <textarea name="col[<?= htmlspecialchars($field) ?>]" rows="3" placeholder="<?= htmlspecialchars($type) ?>"></textarea>
                            <?php elseif (str_starts_with($type, 'datetime')): ?>
                                <input type="datetime-local" name="col[<?= htmlspecialchars($field) ?>]">
                            <?php elseif (str_starts_with($type, 'date')): ?>
                                <input type="date" name="col[<?= htmlspecialchars($field) ?>]">
                            <?php elseif (str_starts_with($type, 'time')): ?>
                                <input type="time" name="col[<?= htmlspecialchars($field) ?>]">
                            <?php elseif (dev_is_numeric_type($type)): ?>
                                <input type="number" step="any" name="col[<?= htmlspecialchars($field) ?>]" placeholder="<?= htmlspecialchars($type) ?>">
                            <?php else: ?>
                                <input type="text" name="col[<?= htmlspecialchars($field) ?>]" placeholder="<?= htmlspecialchars($type) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <button type="submit">Insert Row</button>
                    </form>
                </section>
            </div>

            <?php if ($editRow !== null && !empty($primaryColumns)): ?>
                <section class="card">
                    <h3>Edit Row - <?= htmlspecialchars($selectedTable) ?></h3>
                    <form method="post" class="form-grid form-grid-wide">
                        <input type="hidden" name="action" value="update_row">
                        <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">

                        <?php foreach ($primaryColumns as $pkColumn): ?>
                            <input type="hidden" name="pk_<?= htmlspecialchars($pkColumn) ?>" value="<?= htmlspecialchars((string)($editRow[$pkColumn] ?? '')) ?>">
                        <?php endforeach; ?>

                        <?php foreach ($columns as $column): ?>
                            <?php
                                $field = (string)$column['Field'];
                                $type = strtolower((string)$column['Type']);
                                $enumOptions = dev_enum_options((string)$column['Type']);
                                $isPrimary = in_array($field, $primaryColumns, true);
                                $isAuto = dev_is_auto_increment($column);
                                $value = (string)($editRow[$field] ?? '');

                                if ($isPrimary || $isAuto) {
                                    continue;
                                }
                            ?>
                            <label><?= htmlspecialchars($field) ?></label>
                            <?php if (!empty($enumOptions)): ?>
                                <select name="col[<?= htmlspecialchars($field) ?>]" <?= dev_is_nullable($column) ? '' : 'required' ?>>
                                    <?php if (dev_is_nullable($column)): ?>
                                        <option value="">(null)</option>
                                    <?php endif; ?>
                                    <?php foreach ($enumOptions as $option): ?>
                                        <option value="<?= htmlspecialchars($option) ?>" <?= $option === $value ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif (str_contains($type, 'text')): ?>
                                <textarea name="col[<?= htmlspecialchars($field) ?>]" rows="3"><?= htmlspecialchars($value) ?></textarea>
                            <?php elseif (str_starts_with($type, 'datetime')): ?>
                                <?php
                                    $datetimeValue = '';
                                    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $value)) {
                                        $datetimeValue = str_replace(' ', 'T', substr($value, 0, 16));
                                    }
                                ?>
                                <input type="datetime-local" name="col[<?= htmlspecialchars($field) ?>]" value="<?= htmlspecialchars($datetimeValue) ?>">
                            <?php elseif (str_starts_with($type, 'date')): ?>
                                <input type="date" name="col[<?= htmlspecialchars($field) ?>]" value="<?= htmlspecialchars($value) ?>">
                            <?php elseif (str_starts_with($type, 'time')): ?>
                                <input type="time" name="col[<?= htmlspecialchars($field) ?>]" value="<?= htmlspecialchars($value) ?>">
                            <?php elseif (dev_is_numeric_type($type)): ?>
                                <input type="number" step="any" name="col[<?= htmlspecialchars($field) ?>]" value="<?= htmlspecialchars($value) ?>">
                            <?php else: ?>
                                <input type="text" name="col[<?= htmlspecialchars($field) ?>]" value="<?= htmlspecialchars($value) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <button type="submit">Save Changes</button>
                        <a class="nav-item" href="?<?= htmlspecialchars(http_build_query(['table' => $selectedTable, 'q' => $searchQuery, 'page' => $page])) ?>">Cancel</a>
                    </form>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
