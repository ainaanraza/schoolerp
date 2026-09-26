<?php
require_once __DIR__ . '/includes/bootstrap.php';
logout_user();
header('Location: /itierp/index.php');
exit;
