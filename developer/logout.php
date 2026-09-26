<?php
require_once __DIR__ . '/_auth.php';

developer_logout();
header('Location: /itierp/developer/login.php');
exit;
