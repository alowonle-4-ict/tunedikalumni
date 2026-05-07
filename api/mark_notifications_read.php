<?php
require_once dirname(__DIR__) . '/config/app.php';
requireLogin();
markNotificationsRead((int)$_SESSION['user_id']);
// Redirect back or return JSON for AJAX
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
} else {
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/pages/dashboard.php'));
}
exit;
