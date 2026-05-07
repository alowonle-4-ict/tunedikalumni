<?php
require_once dirname(__DIR__) . '/config/app.php';
$pageTitle = 'Audit Log';
$activeNav = 'audit_log';
require_once __DIR__ . '/includes/admin_header.php';

$db     = getDB();
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 30;
$offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');

$where  = [];
$params = [];
if ($search) {
    $where[]      = '(a.action LIKE :q OR a.description LIKE :q OR u.first_name LIKE :q OR u.last_name LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
$wSql  = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id {$wSql}")
                 ->execute($params) ? $db->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id {$wSql}")->execute($params) || true : 0;
$cStmt = $db->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id {$wSql}");
$cStmt->execute($params);
$total = (int)$cStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $limit));

$stmt = $db->prepare(
    "SELECT a.*, u.first_name, u.last_name
     FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
     {$wSql}
     ORDER BY a.created_at DESC
     LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim',  $limit,  \PDO::PARAM_INT);
$stmt->bindValue(':off',  $offset, \PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$actionColors = [
    'login'                => 'success',
    'login_2fa'            => 'success',
    'logout'               => 'secondary',
    'password_reset'       => 'warning',
    'announcement_created' => 'primary',
    'announcement_deleted' => 'danger',
    'event_created'        => 'primary',
    'event_deleted'        => 'danger',
];
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <h4 class="fw-bold mb-0"><i class="bi bi-journal-text me-2"></i>Audit Log</h4>
  <form method="get" class="d-flex gap-2">
    <input type="text" name="q" class="form-control form-control-sm" placeholder="Search..."
           value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" style="width:220px">
    <button class="btn btn-sm btn-outline-primary">Search</button>
    <?php if ($search): ?>
      <a href="audit_log.php" class="btn btn-sm btn-outline-secondary">Clear</a>
    <?php endif; ?>
  </form>
</div>

<?= renderFlash() ?>

<div class="card">
  <?php if (empty($logs)): ?>
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-journal-x fs-1"></i>
      <p class="mt-2">No audit records found.</p>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-custom mb-0 align-middle small">
        <thead>
          <tr><th>Time</th><th>User</th><th>Action</th><th>Description</th><th>IP Address</th></tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
          <tr>
            <td class="text-muted text-nowrap"><?= date('d M Y H:i', strtotime($log['created_at'])) ?></td>
            <td>
              <?php if ($log['first_name']): ?>
                <?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name'], ENT_QUOTES) ?>
              <?php else: ?>
                <em class="text-muted">System</em>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-<?= $actionColors[$log['action']] ?? 'light text-dark' ?>">
                <?= htmlspecialchars($log['action'], ENT_QUOTES) ?>
              </span>
            </td>
            <td class="text-muted"><?= htmlspecialchars($log['description'] ?? '', ENT_QUOTES) ?></td>
            <td class="text-muted font-monospace"><?= htmlspecialchars($log['ip_address'] ?? '—', ENT_QUOTES) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="card-footer bg-white">
        <nav>
          <ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap">
            <?php for ($i = 1; $i <= min($pages, 20); $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?q=<?= urlencode($search) ?>&page=<?= $i ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
