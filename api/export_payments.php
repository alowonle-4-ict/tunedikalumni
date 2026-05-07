<?php
require_once dirname(__DIR__) . '/config/app.php';
requireFinancialOrAdmin();

$db     = getDB();
$status = $_GET['status'] ?? '';
$from   = $_GET['from']   ?? '';
$to     = $_GET['to']     ?? '';

$where  = [];
$params = [];

if ($status && in_array($status, ['pending','success','rejected','failed'], true)) {
    $where[]          = 'p.status = :status';
    $params[':status'] = $status;
}
if ($from) {
    $where[]        = 'p.created_at >= :from';
    $params[':from'] = $from . ' 00:00:00';
}
if ($to) {
    $where[]      = 'p.created_at <= :to';
    $params[':to'] = $to . ' 23:59:59';
}

$wSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare(
    "SELECT p.id, u.first_name, u.last_name, u.email, u.phone,
            p.amount, p.method, p.status, p.reference, p.created_at,
            m.membership_id
     FROM payments p
     JOIN users u ON u.id = p.user_id
     LEFT JOIN memberships m ON m.user_id = p.user_id
     {$wSql}
     ORDER BY p.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'payments_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Amount (₦)', 'Method', 'Status', 'Reference', 'Membership ID', 'Date']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['id'],
        $r['first_name'],
        $r['last_name'],
        $r['email'],
        $r['phone'],
        number_format((float)$r['amount'], 2),
        $r['method'],
        $r['status'],
        $r['reference'],
        $r['membership_id'] ?? '',
        date('d M Y H:i', strtotime($r['created_at'])),
    ]);
}
fclose($out);
exit;
