<?php
if (!isset($pageTitle)) {
    $pageTitle = 'School ERP';
}
if (!function_exists('nav_active')) {
    function nav_active($path) {
        return strpos($_SERVER['REQUEST_URI'] ?? '', $path) !== false ? ' active' : '';
    }
}

$unreadCountBadge = '';
if (is_logged_in() && isset($pdo)) {
    $headerUser = current_user();
    $headerRole = current_role();
    $headerClassIds = [];
    if ($headerRole === ROLE_STUDENT) {
        $hcStmt = $pdo->prepare('SELECT DISTINCT sce.class_id FROM students s JOIN student_class_enrollments sce ON sce.student_id = s.id AND sce.is_active = 1 WHERE s.user_id = :uid');
        $hcStmt->execute(['uid' => $headerUser['id']]);
        $headerClassIds = array_map('intval', $hcStmt->fetchAll(PDO::FETCH_COLUMN));
    } elseif ($headerRole === ROLE_PARENT) {
        $hcStmt = $pdo->prepare('SELECT DISTINCT sce.class_id FROM parents p JOIN parent_student ps ON ps.parent_id = p.id JOIN student_class_enrollments sce ON sce.student_id = ps.student_id AND sce.is_active = 1 WHERE p.user_id = :uid');
        $hcStmt->execute(['uid' => $headerUser['id']]);
        $headerClassIds = array_map('intval', $hcStmt->fetchAll(PDO::FETCH_COLUMN));
    } elseif ($headerRole === ROLE_TEACHER) {
        $htStmt = $pdo->prepare('SELECT id FROM teachers WHERE user_id = :uid LIMIT 1');
        $htStmt->execute(['uid' => $headerUser['id']]);
        if ($tid = $htStmt->fetchColumn()) {
            $hcStmt = $pdo->prepare('SELECT DISTINCT id FROM classes WHERE class_teacher_id = :tid');
            $hcStmt->execute(['tid' => $tid]);
            $headerClassIds = array_map('intval', $hcStmt->fetchAll(PDO::FETCH_COLUMN));
        }
    }
    
    $hSql = 'SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = :uid WHERE nr.read_at IS NULL AND (n.target_scope = "all" OR (n.target_scope = "role" AND n.target_value = :role) OR (n.target_scope = "user" AND n.target_value = :uid_txt)';
    $hParams = ['uid' => $headerUser['id'], 'role' => $headerRole, 'uid_txt' => (string)$headerUser['id']];
    if (!empty($headerClassIds)) {
        $hSql .= ' OR (n.target_scope = "class" AND n.target_value IN (' . implode(',', $headerClassIds) . '))';
    }
    $hSql .= ')';
    $hStmt = $pdo->prepare($hSql);
    $hStmt->execute($hParams);
    $hCount = (int)$hStmt->fetchColumn();
    if ($hCount > 0) {
        $unreadCountBadge = ' <span class="badge" style="background:var(--error); color:#fff; padding:2px 6px; border-radius:10px; font-size:0.75rem; margin-left:auto;">' . $hCount . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/itierp/assets/css/style.css?v=<?= time() ?>">
    <script>
    function closeAllModalForms() {
        const overlay = document.getElementById('modal-overlay');
        document.querySelectorAll('.collapsible-form.active').forEach(function(form) {
            form.classList.remove('active');
        });
        if (overlay) {
            overlay.classList.remove('active');
        }
        document.body.classList.remove('modal-open');
    }

    function setMobileNavState(isOpen) {
        const sidebar = document.getElementById('app-sidebar');
        const toggle = document.getElementById('mobile-nav-toggle');
        const backdrop = document.getElementById('app-sidebar-backdrop');
        if (!sidebar || !toggle || !backdrop) {
            return;
        }

        document.body.classList.toggle('nav-open', isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        toggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
    }

    function toggleForm(id, btn) {
        const form = document.getElementById(id);
        const overlay = document.getElementById('modal-overlay');
        if (!form || !overlay) return;

        const shouldOpen = !form.classList.contains('active');
        closeAllModalForms();

        if (shouldOpen) {
            // Add close button if not exists
            if (!form.querySelector('.modal-close-btn')) {
                const closeBtn = document.createElement('span');
                closeBtn.className = 'modal-close-btn';
                closeBtn.innerHTML = '&times;';
                closeBtn.onclick = function() { toggleForm(id, btn); };
                form.prepend(closeBtn);
            }

            window.requestAnimationFrame(function() {
                form.classList.add('active');
                overlay.classList.add('active');
                document.body.classList.add('modal-open');
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        let overlay = document.getElementById('modal-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'modal-overlay';
            overlay.className = 'modal-overlay';
            document.body.appendChild(overlay);
        }

        overlay.onclick = function() {
            closeAllModalForms();
        };

        const sidebar = document.getElementById('app-sidebar');
        const sidebarToggle = document.getElementById('mobile-nav-toggle');
        const sidebarBackdrop = document.getElementById('app-sidebar-backdrop');

        if (sidebar && sidebarToggle && sidebarBackdrop) {
            sidebarToggle.onclick = function() {
                const shouldOpen = !document.body.classList.contains('nav-open');
                setMobileNavState(shouldOpen);
            };

            sidebarBackdrop.onclick = function() {
                setMobileNavState(false);
            };

            sidebar.querySelectorAll('.nav-item').forEach(function(link) {
                link.addEventListener('click', function() {
                    setMobileNavState(false);
                });
            });

            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    setMobileNavState(false);
                }
            });
        }

        // Close on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllModalForms();
                setMobileNavState(false);
            }
        });
    });
    </script>
</head>
<body class="<?= is_logged_in() ? 'is-authenticated' : 'is-guest' ?>">
<?php if (is_logged_in()): ?>
    <div class="app-layout">
        <aside class="app-sidebar" id="app-sidebar">
            <div class="sidebar-brand">
                <div class="sidebar-brand-mark" aria-hidden="true">
                    <img src="/itierp/assets/images/unityiti_logo.jpeg" alt="" class="sidebar-brand-logo">
                </div>
                <h2>Unity ITI</h2>
                <p>ERP SUITE</p>
            </div>
            <nav class="sidebar-nav">
                <?php $dashPath = '/itierp/' . (current_role() === 'super_admin' ? 'superadmin' : current_role()) . '/dashboard.php'; ?>
                <a href="<?= $dashPath ?>" class="nav-item<?= nav_active('dashboard.php') ?>">Dashboard</a>
                
                <?php if (in_array(current_role(), ['admin', 'super_admin'])): ?>
                    <p class="nav-group">Directory</p>
                    <a href="/itierp/modules/admission/apply.php" class="nav-item<?= nav_active('/modules/admission/apply.php') ?>">Admission Form</a>
                    <a href="/itierp/modules/admission/leads.php" class="nav-item<?= nav_active('/modules/admission/leads.php') ?>">Leads</a>
                    <a href="/itierp/modules/admission/students.php" class="nav-item<?= nav_active('/modules/admission/students.php') ?>">Students</a>
                    <a href="/itierp/modules/admission/documents.php" class="nav-item<?= nav_active('/modules/admission/documents.php') ?>">Documents</a>
                    <a href="/itierp/modules/admission/parent_linking.php" class="nav-item<?= nav_active('/modules/admission/parent_linking.php') ?>">Parents</a>
                    <?php if (current_role() === 'super_admin'): ?>
                        <a href="/itierp/modules/admission/admin_management.php" class="nav-item<?= nav_active('/modules/admission/admin_management.php') ?>">Staff</a>
                    <?php endif; ?>
                    
                    <p class="nav-group">Modules</p>
                    <a href="/itierp/modules/attendance/manage.php" class="nav-item<?= nav_active('/modules/attendance/manage.php') ?>">Attendance</a>
                    <a href="/itierp/modules/fees/fee_structure.php" class="nav-item<?= nav_active('/modules/fees/fee_structure.php') ?>">Fees Structure</a>
                    <a href="/itierp/modules/fees/student_fees.php" class="nav-item<?= nav_active('/modules/fees/student_fees.php') ?>">Student Fees</a>
                    <?php if (current_role() === 'super_admin'): ?>
                        <a href="/itierp/modules/fees/superadmin_finance.php" class="nav-item<?= nav_active('/modules/fees/superadmin_finance.php') ?>">Finances</a>
                        <a href="/itierp/modules/inventory/items.php" class="nav-item<?= strpos($_SERVER['REQUEST_URI'] ?? '', '/modules/inventory/') !== false ? ' active' : '' ?>">Inventory</a>
                    <?php endif; ?>

                    <p class="nav-group">Notifications</p>
                    <a href="/itierp/modules/notification/send.php" class="nav-item<?= nav_active('/modules/notification/send.php') ?>">Broadcasts</a>
                    <a href="/itierp/modules/notification/inbox.php" class="nav-item<?= nav_active('/modules/notification/inbox.php') ?>">Inbox<?= $unreadCountBadge ?></a>
                    
                    <p class="nav-group">System</p>
                    <?php if (current_role() === 'super_admin'): ?>
                        <a href="/itierp/modules/academics/settings.php" class="nav-item<?= nav_active('/modules/academics/settings.php') ?>">Settings</a>
                    <?php else: ?>
                        <a href="/itierp/modules/academics/manage.php" class="nav-item<?= nav_active('/modules/academics/manage.php') ?>">Course Setup</a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (current_role() === 'teacher'): ?>
                    <p class="nav-group">Coursework</p>
                    <a href="/itierp/modules/attendance/mark.php" class="nav-item<?= nav_active('/modules/attendance/mark.php') ?>">Mark Attendance</a>
                    <a href="/itierp/modules/academics/homework.php" class="nav-item<?= nav_active('/modules/academics/homework.php') ?>">Assignments</a>
                    <a href="/itierp/modules/admission/students.php" class="nav-item<?= nav_active('/modules/admission/students.php') ?>">Student Profiles</a>
                    
                    <p class="nav-group">Notifications</p>
                    <a href="/itierp/modules/notification/send.php" class="nav-item<?= nav_active('/modules/notification/send.php') ?>">Send Alerts</a>
                    <a href="/itierp/modules/notification/inbox.php" class="nav-item<?= nav_active('/modules/notification/inbox.php') ?>">Inbox<?= $unreadCountBadge ?></a>
                <?php endif; ?>

                <?php if (current_role() === 'student'): ?>
                    <p class="nav-group">My Academics</p>
                    <a href="/itierp/modules/academics/homework.php" class="nav-item<?= nav_active('/modules/academics/homework.php') ?>">Homework</a>
                    <a href="/itierp/modules/attendance/view.php" class="nav-item<?= nav_active('/modules/attendance/view.php') ?>">Attendance Log</a>
                    <a href="/itierp/modules/admission/documents.php" class="nav-item<?= nav_active('/modules/admission/documents.php') ?>">Documents</a>
                    
                    <p class="nav-group">Portal</p>
                    <a href="/itierp/modules/fees/student_fees.php" class="nav-item<?= nav_active('/modules/fees/student_fees.php') ?>">Fee Status</a>

                    <p class="nav-group">Notifications</p>
                    <a href="/itierp/modules/notification/inbox.php" class="nav-item<?= nav_active('/modules/notification/inbox.php') ?>">Inbox<?= $unreadCountBadge ?></a>
                <?php endif; ?>

                <?php if (current_role() === 'parent'): ?>
                    <p class="nav-group">Family</p>
                    <a href="/itierp/parent/children.php" class="nav-item<?= nav_active('/parent/children.php') ?>">My Children</a>
                    <a href="/itierp/parent/attendance.php" class="nav-item<?= nav_active('/parent/attendance.php') ?>">Child Attendance</a>
                    <a href="/itierp/parent/homework.php" class="nav-item<?= nav_active('/parent/homework.php') ?>">Homework</a>
                    
                    <p class="nav-group">Records</p>
                    <a href="/itierp/parent/fees.php" class="nav-item<?= nav_active('/parent/fees.php') ?>">Fee Payments</a>

                    <p class="nav-group">Notifications</p>
                    <a href="/itierp/modules/notification/inbox.php" class="nav-item<?= nav_active('/modules/notification/inbox.php') ?>">Inbox<?= $unreadCountBadge ?></a>
                    <a href="/itierp/parent/notifications.php" class="nav-item<?= nav_active('/parent/notifications.php') ?>">Alerts</a>
                <?php endif; ?>
                
            </nav>
        </aside>
        <div class="app-sidebar-backdrop" id="app-sidebar-backdrop" aria-hidden="true"></div>
        
        <div class="app-main-content">
            <header class="app-topbar">
                <div class="topbar-left">
                    <button type="button" class="mobile-nav-toggle" id="mobile-nav-toggle" aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation menu">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                    <div class="topbar-welcome">
                        <p>Good to see you, <?= htmlspecialchars(current_user()['name']) ?></p>
                    </div>
                </div>
                <div class="header-user">
                    <a href="/itierp/logout.php" class="topbar-logout" title="Logout">Logout</a>
                    <div class="avatar" title="<?= htmlspecialchars(current_user()['name']) ?> (<?= htmlspecialchars(current_role()) ?>)">
                        <?= strtoupper(substr(current_user()['name'], 0, 1)) ?>
                    </div>
                </div>
            </header>
            <main class="container">
<?php else: ?>
    <!-- Guest Layout (Login page) -->
    <main class="container guest-container">
<?php endif; ?>
