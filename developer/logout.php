<?php
require_once __DIR__ . '/_auth.php';

developer_logout();
header('Location: /school-erp/developer/login.php');
exit;
