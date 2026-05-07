<?php
/**
 * TUNEDIK — Authenticated File Serving
 * Usage: /api/serve_file.php?type=committee_report&file=abc123.pdf
 *
 * Replaces direct links to sensitive uploads so only logged-in users can access them.
 * Supported types: committee_report, constitution, receipt
 */
require_once dirname(__DIR__) . '/config/app.php';
requireLogin();

$type = $_GET['type'] ?? '';
$file = basename($_GET['file'] ?? ''); // basename() prevents directory traversal

$allowedTypes = [
    'committee_report' => ROOT_PATH . '/assets/uploads/committee_reports/',
    'constitution'     => ROOT_PATH . '/assets/uploads/constitution/',
    'receipt'          => ROOT_PATH . '/assets/uploads/receipts/',
];

if (!isset($allowedTypes[$type]) || !$file) {
    http_response_code(400);
    exit('Invalid request.');
}

$dir      = $allowedTypes[$type];
$fullPath = $dir . $file;

if (!file_exists($fullPath) || !is_file($fullPath)) {
    http_response_code(404);
    exit('File not found.');
}

// For receipts: only admin/financial_secretary OR the file owner can access
if ($type === 'receipt') {
    $role = currentRole();
    if (!in_array($role, ['admin', 'financial_secretary'], true)) {
        $stmt = getDB()->prepare('SELECT id FROM payments WHERE receipt_file = :f AND user_id = :uid LIMIT 1');
        $stmt->execute([':f' => $file, ':uid' => $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            exit('Access denied.');
        }
    }
}

// For committee reports: user must be a committee member or admin
if ($type === 'committee_report') {
    $role = currentRole();
    if (!in_array($role, ['admin', 'financial_secretary'], true)) {
        $stmt = getDB()->prepare(
            'SELECT cr.committee_id FROM committee_reports cr
             JOIN committee_members cm ON cm.committee_id = cr.committee_id
             WHERE cr.filename = :f AND cm.user_id = :uid LIMIT 1'
        );
        $stmt->execute([':f' => $file, ':uid' => $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            exit('Access denied.');
        }
    }
}

// Serve the file
$ext      = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimeMap  = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
$mime     = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . addslashes($file) . '"');
header('Cache-Control: private, max-age=3600');
readfile($fullPath);
exit;
