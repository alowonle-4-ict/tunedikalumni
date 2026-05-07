<?php
require_once dirname(__DIR__) . '/config/app.php';

$pageTitle = 'Memberships';
$activeNav = 'memberships';

require_once __DIR__ . '/includes/admin_header.php';

$db = getDB();

$filter = $_GET['status'] ?? '';
$where  = ['1=1'];
$params = [];

if ($filter && in_array($filter, ['active','pending','expired'], true)) {
    $where[]         = 'm.status = :status';
    $params[':status'] = $filter;
}

$memberships = $db->prepare(
    'SELECT m.*, u.first_name, u.last_name, u.email, u.phone, u.state, u.country, u.department, u.graduation_year
     FROM memberships m
     JOIN users u ON u.id = m.user_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY m.created_at DESC'
);
$memberships->execute($params);
$memberships = $memberships->fetchAll();

$stats = $db->query(
    'SELECT status, COUNT(*) as cnt FROM memberships GROUP BY status'
)->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <h4 class="fw-bold mb-0"><i class="bi bi-award-fill me-2"></i>Memberships (<?= count($memberships) ?>)</h4>
</div>

<?= renderFlash() ?>

<!-- ── Summary ─────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php
    $statusCfg = [
      'active'  => ['icon'=>'bi-check-circle-fill','color'=>'success','label'=>'Active'],
      'pending' => ['icon'=>'bi-clock-fill',        'color'=>'warning','label'=>'Pending'],
      'expired' => ['icon'=>'bi-x-circle-fill',     'color'=>'danger', 'label'=>'Expired'],
    ];
    foreach ($statusCfg as $key => $cfg):
  ?>
  <div class="col-sm-4">
    <a href="?status=<?= $key ?>" class="text-decoration-none">
      <div class="stat-card <?= $key === 'active' ? 'success' : ($key === 'expired' ? 'danger' : 'accent') ?>">
        <div class="stat-icon <?= $key === 'active' ? 'green' : ($key === 'expired' ? 'red' : 'orange') ?>">
          <i class="bi <?= $cfg['icon'] ?>"></i>
        </div>
        <div>
          <div class="stat-label"><?= $cfg['label'] ?></div>
          <div class="stat-value"><?= number_format((int)($stats[$key] ?? 0)) ?></div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Filter ──────────────────────────────────────────── -->
<div class="mb-3 d-flex gap-2 flex-wrap">
  <a href="<?= BASE_URL ?>/admin/memberships.php" class="btn btn-sm <?= !$filter ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
  <a href="?status=active"  class="btn btn-sm <?= $filter === 'active'  ? 'btn-success'  : 'btn-outline-success' ?>">Active</a>
  <a href="?status=pending" class="btn btn-sm <?= $filter === 'pending' ? 'btn-warning'  : 'btn-outline-warning' ?>">Pending</a>
  <a href="?status=expired" class="btn btn-sm <?= $filter === 'expired' ? 'btn-danger'   : 'btn-outline-danger'  ?>">Expired</a>
</div>

<!-- ── Table ───────────────────────────────────────────── -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-custom mb-0">
        <thead>
          <tr>
            <th>Membership ID</th>
            <th>Member</th>
            <th>Location</th>
            <th>Department</th>
            <th>Class</th>
            <th>Status</th>
            <th>Start</th>
            <th>Expiry</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($memberships): ?>
            <?php foreach ($memberships as $m): ?>
              <tr>
                <td><code class="fw-bold small"><?= htmlspecialchars($m['membership_id'], ENT_QUOTES) ?></code></td>
                <td>
                  <div class="fw-semibold small"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name'], ENT_QUOTES) ?></div>
                  <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($m['email'], ENT_QUOTES) ?></div>
                </td>
                <td class="small"><?= htmlspecialchars($m['country'] === 'Nigeria' ? ($m['state'] ?? '') : $m['country'], ENT_QUOTES) ?></td>
                <td class="small text-muted"><?= htmlspecialchars($m['department'] ?? '—', ENT_QUOTES) ?></td>
                <td class="small"><?= $m['graduation_year'] ?? '—' ?></td>
                <td><span class="status-badge badge-<?= $m['status'] ?>"><?= ucfirst($m['status']) ?></span></td>
                <td class="text-muted small"><?= formatDate($m['membership_start_date']) ?></td>
                <td class="small">
                  <?= formatDate($m['membership_expiry_date']) ?>
                  <?php if ($m['status'] === 'active'): ?>
                    <div class="text-muted" style="font-size:.7rem"><?= daysRemaining($m['membership_expiry_date']) ?> days left</div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No memberships found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
