<?php
require_once dirname(__DIR__) . '/config/app.php';
requireAdmin();

$db = getDB();
$rows = $db->query(
    "SELECT u.id, u.first_name, u.last_name, u.email, u.phone,
            u.state, u.country, u.department, u.graduation_year,
            u.role, u.is_active,
            m.membership_id, m.status AS membership_status,
            m.membership_start_date, m.membership_expiry_date,
            u.created_at
     FROM users u
     LEFT JOIN memberships m ON m.user_id = u.id
     ORDER BY u.first_name, u.last_name"
)->fetchAll();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="members_export_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID','First Name','Last Name','Email','Phone','State','Country','Department','Grad Year','Role','Active','Membership ID','Membership Status','Start Date','Expiry Date','Registered']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['id'], $r['first_name'], $r['last_name'], $r['email'], $r['phone'],
        $r['state'], $r['country'], $r['department'], $r['graduation_year'],
        $r['role'], $r['is_active'] ? 'Yes' : 'No',
        $r['membership_id'] ?? '', $r['membership_status'] ?? '',
        $r['membership_start_date'] ?? '', $r['membership_expiry_date'] ?? '',
        date('d M Y', strtotime($r['created_at'])),
    ]);
}
fclose($out);
exit;
