<?php
require_once dirname(__DIR__) . '/config/app.php';

$pageTitle = 'Admin Dashboard';
$activeNav = 'dashboard';

require_once __DIR__ . '/includes/admin_header.php';

$db = getDB();

// ── Stats ─────────────────────────────────────────────────
$totalUsers      = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeMembers   = $db->query('SELECT COUNT(*) FROM memberships WHERE status = "active"')->fetchColumn();
$expiredMembers  = $db->query('SELECT COUNT(*) FROM memberships WHERE status = "expired"')->fetchColumn();
$pendingPayments = $db->query('SELECT COUNT(*) FROM payments WHERE status = "pending" AND method = "offline"')->fetchColumn();
$totalRevenue    = $db->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = "success"')->fetchColumn();
$totalCommittees = $db->query('SELECT COUNT(*) FROM committees WHERE status = "active"')->fetchColumn();

// ── Recent Payments ───────────────────────────────────────
$recentPayments = $db->query(
    'SELECT p.*, u.first_name, u.last_name, u.email
     FROM payments p
     JOIN users u ON u.id = p.user_id
     ORDER BY p.created_at DESC
     LIMIT 10'
)->fetchAll();

// ── Members by State ─────────────────────────────────────
$byState = $db->query(
    'SELECT location_code, COUNT(*) as total
     FROM memberships
     GROUP BY location_code
     ORDER BY total DESC
     LIMIT 10'
)->fetchAll();

// ── Revenue last 6 months ─────────────────────────────────
$revMonths = $db->query(
    'SELECT DATE_FORMAT(created_at, "%b %Y") AS month,
            MONTH(created_at) AS mn, YEAR(created_at) AS yr,
            SUM(amount) AS total
     FROM payments
     WHERE status = "success" AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY yr, mn, month
     ORDER BY yr ASC, mn ASC'
)->fetchAll();
$revLabels = array_column($revMonths, 'month');
$revData   = array_map(fn($r) => round((float)$r['total'], 2), $revMonths);

// ── New members per month ─────────────────────────────────
$newMemMonths = $db->query(
    'SELECT DATE_FORMAT(created_at, "%b %Y") AS month,
            MONTH(created_at) AS mn, YEAR(created_at) AS yr,
            COUNT(*) AS total
     FROM users
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY yr, mn, month
     ORDER BY yr ASC, mn ASC'
)->fetchAll();
$newMemLabels = array_column($newMemMonths, 'month');
$newMemData   = array_column($newMemMonths, 'total');
?>

<!-- ── Stat Cards ─────────────────────────────────────── -->
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
      <div><div class="stat-label">Total Users</div><div class="stat-value"><?= number_format((int)$totalUsers) ?></div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card success">
      <div class="stat-icon green"><i class="bi bi-award-fill"></i></div>
      <div><div class="stat-label">Active Members</div><div class="stat-value"><?= number_format((int)$activeMembers) ?></div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card accent">
      <div class="stat-icon orange"><i class="bi bi-hourglass-split"></i></div>
      <div><div class="stat-label">Pending Approvals</div><div class="stat-value"><?= number_format((int)$pendingPayments) ?></div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card danger">
      <div class="stat-icon red"><i class="bi bi-currency-exchange"></i></div>
      <div><div class="stat-label">Total Revenue</div><div class="stat-value" style="font-size:1.3rem"><?= formatCurrency((float)$totalRevenue) ?></div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-people"></i></div>
      <div><div class="stat-label">Active Committees</div><div class="stat-value"><?= number_format((int)$totalCommittees) ?></div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card danger">
      <div class="stat-icon red"><i class="bi bi-x-circle"></i></div>
      <div><div class="stat-label">Expired Members</div><div class="stat-value"><?= number_format((int)$expiredMembers) ?></div></div>
    </div>
  </div>
</div>

<!-- Quick Actions -->
<div class="d-flex gap-2 mb-4 flex-wrap">
  <a href="<?= BASE_URL ?>/api/export_members.php"  class="btn btn-outline-primary btn-sm"><i class="bi bi-download me-1"></i>Export Members</a>
  <a href="<?= BASE_URL ?>/api/export_payments.php" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Export Payments</a>
  <a href="<?= BASE_URL ?>/admin/announcements.php" class="btn btn-outline-warning btn-sm"><i class="bi bi-megaphone me-1"></i>Announcement</a>
  <a href="<?= BASE_URL ?>/admin/events.php"        class="btn btn-outline-info btn-sm"><i class="bi bi-calendar-plus me-1"></i>New Event</a>
  <a href="<?= BASE_URL ?>/admin/audit_log.php"     class="btn btn-outline-secondary btn-sm"><i class="bi bi-journal-text me-1"></i>Audit Log</a>
</div>

<?php if ($pendingPayments > 0): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <div>
      <strong><?= $pendingPayments ?> offline payment(s)</strong> are awaiting your approval.
      <a href="<?= BASE_URL ?>/admin/payments.php?filter=pending" class="alert-link ms-1">Review now →</a>
    </div>
  </div>
<?php endif; ?>

<div class="row g-4">

  <!-- ── Recent Payments ─────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header bg-white d-flex align-items-center justify-content-between py-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-cash-stack me-2"></i>Recent Payments</h6>
        <a href="<?= BASE_URL ?>/admin/payments.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if ($recentPayments): ?>
          <div class="table-responsive">
            <table class="table table-custom mb-0">
              <thead>
                <tr><th>Member</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recentPayments as $p): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold small"><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name'], ENT_QUOTES) ?></div>
                      <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($p['email'], ENT_QUOTES) ?></div>
                    </td>
                    <td class="fw-semibold"><?= formatCurrency((float)$p['amount']) ?></td>
                    <td><span class="badge bg-light text-dark text-capitalize"><?= htmlspecialchars($p['method'], ENT_QUOTES) ?></span></td>
                    <td><span class="status-badge badge-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span></td>
                    <td class="text-muted small"><?= formatDate($p['created_at'], 'd M Y') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-center py-4 text-muted"><i class="bi bi-inbox"></i> No payments yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Members by State ────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header bg-white py-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-geo-alt-fill me-2"></i>Members by Location</h6>
      </div>
      <div class="card-body p-0">
        <?php if ($byState): ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($byState as $row): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center px-4">
                <span class="fw-semibold small font-monospace"><?= htmlspecialchars($row['location_code'], ENT_QUOTES) ?></span>
                <span class="badge rounded-pill" style="background:var(--primary)"><?= $row['total'] ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="text-center py-4 text-muted small">No membership data yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /row -->

<!-- ── Analytics Charts ───────────────────────────────── -->
<div class="row g-4 mt-2">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header bg-white py-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-bar-chart-fill me-2 text-success"></i>Revenue — Last 6 Months</h6>
      </div>
      <div class="card-body">
        <canvas id="revenueChart" height="220"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header bg-white py-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-person-plus-fill me-2 text-primary"></i>New Registrations — Last 6 Months</h6>
      </div>
      <div class="card-body">
        <canvas id="newMembersChart" height="220"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header bg-white py-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-pie-chart-fill me-2 text-info"></i>Membership Status</h6>
      </div>
      <div class="card-body d-flex justify-content-center">
        <canvas id="statusChart" height="220" style="max-width:280px"></canvas>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const chartDefaults = { plugins: { legend: { labels: { font: { family: 'inherit' } } } } };

// Revenue
new Chart(document.getElementById('revenueChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($revLabels) ?>,
    datasets: [{ label: 'Revenue (₦)', data: <?= json_encode($revData) ?>,
      backgroundColor: 'rgba(25,135,84,.7)', borderColor: '#198754', borderWidth: 1, borderRadius: 4 }]
  },
  options: { ...chartDefaults, scales: { y: { beginAtZero: true, ticks: { callback: v => '₦' + v.toLocaleString() } } } }
});

// New Members
new Chart(document.getElementById('newMembersChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($newMemLabels) ?>,
    datasets: [{ label: 'New Registrations', data: <?= json_encode($newMemData) ?>,
      fill: true, tension: 0.4,
      backgroundColor: 'rgba(13,110,253,.1)', borderColor: '#0d6efd', pointBackgroundColor: '#0d6efd' }]
  },
  options: { ...chartDefaults, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
});

// Membership Status Donut
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: ['Active', 'Expired', 'Other'],
    datasets: [{ data: [<?= (int)$activeMembers ?>, <?= (int)$expiredMembers ?>, <?= max(0, (int)$totalUsers - (int)$activeMembers - (int)$expiredMembers) ?>],
      backgroundColor: ['#198754','#dc3545','#adb5bd'], borderWidth: 2 }]
  },
  options: { ...chartDefaults, cutout: '65%' }
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
