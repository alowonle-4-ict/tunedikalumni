<?php
require_once dirname(__DIR__) . '/config/app.php';

$pageTitle = 'Reports & Exports';
$activeNav = 'reports';

require_once __DIR__ . '/includes/admin_header.php';

$db = getDB();

// ── Manual cleanup trigger ────────────────────────────────────
$cleanupMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_cleanup') {
    verifyCsrf();
    // Force run by resetting the last-run timestamp
    updateSetting('_cleanup_last_run', '0');
    $deleted = cleanupStalePendingUsers(7);
    $cleanupMsg = $deleted > 0
        ? ['type' => 'warning', 'text' => '<i class="bi bi-trash-fill me-2"></i>Cleanup complete — <strong>' . $deleted . '</strong> stale pending account(s) deleted.']
        : ['type' => 'success', 'text' => '<i class="bi bi-check-circle-fill me-2"></i>Cleanup ran — no stale pending accounts found.'];
}

$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate   = $_GET['to']   ?? date('Y-m-d');

// ── Quick stats for the period ────────────────────────────────
$s = $db->prepare(
    'SELECT
       COUNT(*) AS total_txns,
       COALESCE(SUM(CASE WHEN status="success" THEN amount ELSE 0 END),0) AS revenue,
       COALESCE(SUM(CASE WHEN status="success" AND method="paystack" THEN amount ELSE 0 END),0) AS online,
       COALESCE(SUM(CASE WHEN status="success" AND method NOT IN ("paystack") THEN amount ELSE 0 END),0) AS offline,
       COUNT(CASE WHEN status="pending" THEN 1 END) AS pending,
       COUNT(CASE WHEN status="rejected" THEN 1 END) AS rejected
     FROM payments
     WHERE DATE(created_at) BETWEEN :f AND :t'
);
$s->execute([':f' => $fromDate, ':t' => $toDate]);
$sum = $s->fetch();

$totalMembers  = $db->query('SELECT COUNT(*) FROM users WHERE role="member"')->fetchColumn();
$activeMembers = $db->query('SELECT COUNT(*) FROM memberships WHERE status="active"')->fetchColumn();
$expiring30    = $db->query(
    'SELECT COUNT(*) FROM memberships
     WHERE status="active"
       AND membership_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'
)->fetchColumn();

$totalDonations = $db->query(
    'SELECT COALESCE(SUM(amount),0) FROM donation_payments WHERE status="success"'
)->fetchColumn();

// Stale pending accounts (would be deleted on next cleanup)
$stalePending = $db->query(
    'SELECT COUNT(*) FROM users u
     WHERE u.role = "member"
       AND u.created_at < NOW() - INTERVAL 7 DAY
       AND NOT EXISTS (
           SELECT 1 FROM payments p WHERE p.user_id = u.id AND p.status = "success"
       )'
)->fetchColumn();

$lastCleanup = (int)getSetting('_cleanup_last_run', '0');
$lastCleanupText = $lastCleanup ? date('d M Y H:i', $lastCleanup) : 'Never';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Reports & Exports</h4>
</div>

<?php if ($cleanupMsg): ?>
  <div class="alert alert-<?= $cleanupMsg['type'] ?> alert-dismissible fade show">
    <?= $cleanupMsg['text'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- ── Date filter ────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold">From Date</label>
        <input type="date" name="from" class="form-control form-control-sm"
               value="<?= htmlspecialchars($fromDate, ENT_QUOTES) ?>">
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold">To Date</label>
        <input type="date" name="to" class="form-control form-control-sm"
               value="<?= htmlspecialchars($toDate, ENT_QUOTES) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        <a href="reports.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- ── Period summary ─────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card success">
      <div class="stat-icon green"><i class="bi bi-currency-exchange"></i></div>
      <div>
        <div class="stat-label">Revenue (period)</div>
        <div class="fw-bold"><?= formatCurrency((float)$sum['revenue']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
      <div>
        <div class="stat-label">Total Members</div>
        <div class="fw-bold"><?= number_format((int)$totalMembers) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card accent">
      <div class="stat-icon orange"><i class="bi bi-clock-history"></i></div>
      <div>
        <div class="stat-label">Expiring ≤ 30 days</div>
        <div class="fw-bold"><?= number_format((int)$expiring30) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card danger">
      <div class="stat-icon red"><i class="bi bi-heart-fill"></i></div>
      <div>
        <div class="stat-label">Total Donations</div>
        <div class="fw-bold"><?= formatCurrency((float)$totalDonations) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ── Export cards ───────────────────────────────────────────── -->
<div class="row g-4">

  <!-- Payments -->
  <div class="col-md-6 col-xl-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body text-center py-4">
        <div class="mb-3">
          <span class="rounded-circle d-inline-flex align-items-center justify-content-center"
                style="width:56px;height:56px;background:#d1fae5">
            <i class="bi bi-cash-stack fs-4 text-success"></i>
          </span>
        </div>
        <h6 class="fw-bold">Payments Report</h6>
        <p class="text-muted small mb-3">All transactions with member details, method, status and approver for the selected period.</p>
        <div class="d-flex flex-column gap-2">
          <a href="export.php?type=payments&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>"
             class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>All Statuses
          </a>
          <a href="export.php?type=payments&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>&status=success"
             class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Successful Only
          </a>
          <a href="export.php?type=payments&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>&status=pending"
             class="btn btn-outline-warning btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Pending Only
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Members -->
  <div class="col-md-6 col-xl-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body text-center py-4">
        <div class="mb-3">
          <span class="rounded-circle d-inline-flex align-items-center justify-content-center"
                style="width:56px;height:56px;background:#dbeafe">
            <i class="bi bi-people-fill fs-4 text-primary"></i>
          </span>
        </div>
        <h6 class="fw-bold">Members Report</h6>
        <p class="text-muted small mb-3">Full member directory with membership IDs, status, graduation year, location, and payment history.</p>
        <div class="d-flex flex-column gap-2">
          <a href="export.php?type=members"
             class="btn btn-primary btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>All Members
          </a>
          <a href="export.php?type=members&mem_status=active"
             class="btn btn-outline-primary btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Active Only
          </a>
          <a href="export.php?type=members&mem_status=expired"
             class="btn btn-outline-danger btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Expired Only
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Donations -->
  <div class="col-md-6 col-xl-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body text-center py-4">
        <div class="mb-3">
          <span class="rounded-circle d-inline-flex align-items-center justify-content-center"
                style="width:56px;height:56px;background:#fce7f3">
            <i class="bi bi-heart-fill fs-4 text-danger"></i>
          </span>
        </div>
        <h6 class="fw-bold">Donations Report</h6>
        <p class="text-muted small mb-3">Campaign summaries and individual donor records including amounts, messages and method.</p>
        <div class="d-flex flex-column gap-2">
          <a href="export.php?type=donations&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>"
             class="btn btn-danger btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Export Donations
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Expiring memberships -->
  <div class="col-md-6 col-xl-3">
    <div class="card h-100 border-0 shadow-sm">
      <div class="card-body text-center py-4">
        <div class="mb-3">
          <span class="rounded-circle d-inline-flex align-items-center justify-content-center"
                style="width:56px;height:56px;background:#fef3c7">
            <i class="bi bi-clock-history fs-4 text-warning"></i>
          </span>
        </div>
        <h6 class="fw-bold">Expiring Memberships</h6>
        <p class="text-muted small mb-3">List of members whose membership expires soon — useful for sending renewal reminders.</p>
        <div class="d-flex flex-column gap-2">
          <a href="export.php?type=expiring&days=30"
             class="btn btn-warning btn-sm text-dark">
            <i class="bi bi-file-earmark-excel me-1"></i>Expiring in 30 days
          </a>
          <a href="export.php?type=expiring&days=60"
             class="btn btn-outline-warning btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Expiring in 60 days
          </a>
          <a href="export.php?type=expiring&days=90"
             class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Expiring in 90 days
          </a>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ── Elections Export ───────────────────────────────────────── -->
<div class="card mt-4 border-0 shadow-sm">
  <div class="card-header bg-white py-3">
    <h6 class="fw-bold mb-0"><i class="bi bi-ballot-fill me-2 text-primary"></i>Election Reports</h6>
  </div>
  <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
    <p class="text-muted small mb-0">
      Export full election results including candidate vote counts and winner determination for all positions.
    </p>
    <a href="export.php?type=elections" class="btn btn-primary btn-sm">
      <i class="bi bi-file-earmark-excel me-1"></i>Export Election Results
    </a>
  </div>
</div>

<!-- ── Stale Account Cleanup ─────────────────────────────────── -->
<div class="card mt-4 <?= (int)$stalePending > 0 ? 'border-warning' : 'border-0 shadow-sm' ?>">
  <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
    <h6 class="fw-bold mb-0">
      <i class="bi bi-trash3-fill me-2 text-warning"></i>Stale Pending Account Cleanup
    </h6>
    <?php if ((int)$stalePending > 0): ?>
      <span class="badge bg-warning text-dark"><?= (int)$stalePending ?> account<?= (int)$stalePending !== 1 ? 's' : '' ?> queued</span>
    <?php else: ?>
      <span class="badge bg-success">All clear</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="row align-items-center g-3">
      <div class="col-md-7">
        <p class="mb-1 small">
          Member accounts that registered more than <strong>7 days ago</strong> and never completed payment are automatically deleted to keep the system clean.
          Cleanup runs automatically in the background <strong>once per hour</strong>.
        </p>
        <p class="mb-0 text-muted small">
          <i class="bi bi-clock me-1"></i>Last automatic cleanup: <strong><?= $lastCleanupText ?></strong>
          &nbsp;·&nbsp;
          <?php if ((int)$stalePending > 0): ?>
            <span class="text-warning fw-semibold"><?= (int)$stalePending ?> stale account<?= (int)$stalePending !== 1 ? 's' : '' ?> will be deleted on next run.</span>
          <?php else: ?>
            <span class="text-success">No stale accounts found.</span>
          <?php endif; ?>
        </p>
      </div>
      <div class="col-md-5 text-md-end">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="run_cleanup">
          <button type="submit"
                  class="btn <?= (int)$stalePending > 0 ? 'btn-warning' : 'btn-outline-secondary' ?> btn-sm"
                  <?= (int)$stalePending > 0 ? 'data-confirm="This will permanently delete ' . (int)$stalePending . ' stale pending account(s). Continue?"' : '' ?>>
            <i class="bi bi-arrow-repeat me-1"></i>Run Cleanup Now
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
