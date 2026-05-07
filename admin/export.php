<?php
require_once dirname(__DIR__) . '/config/app.php';
requireAdmin();
$db = getDB();
require_once ROOT_PATH . '/includes/export_handler.php';
