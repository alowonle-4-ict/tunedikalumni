<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT_PATH . '/includes/mailer.php';

$pageTitle = 'Manage Payments';
$activeNav = 'payments';

$db = getDB();

// ── POST: Approve or Reject ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action    = $_POST['action']     ?? '';
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $notes     = trim($_POST['notes'] ?? '');

    if ($paymentId) {
        $stmt = $db->prepare('SELECT p.*, u.id as uid FROM payments p JOIN users u ON u.id = p.user_id WHERE p.id = :id LIMIT 1');
        $stmt->execute([':id' => $paymentId]);
        $payment = $stmt->fetch();

        if ($payment) {
            $userId   = (int)$payment['uid'];
            $approver = (int)currentUserId();
            $user     = getUserById($userId);

            if ($action === 'approve') {
                $db->prepare(
                    'UPDATE payments SET status="success", approved_by=:ab, approved_at=NOW(), notes=:n, updated_at=NOW()
                     WHERE id=:id'
                )->execute([':ab' => $approver, ':n' => $notes, ':id' => $paymentId]);

                activateMembership($userId);
                $membership = getUserMembership($userId);

                $emailSent = false;
                if ($user && $membership) {
                    $emailSent = sendPaymentApprovedEmail($user, $membership);
                }

                $memberName = $user ? ($user['first_name'] . ' ' . $user['last_name']) : 'Member';
                $memId      = $membership['membership_id'] ?? 'N/A';
                $notice     = $emailSent ? ' A confirmation email has been sent.' : '';
                setFlash('success',
                    '<i class="bi bi-check-circle-fill me-2"></i>'
                    . '<strong>' . htmlspecialchars($memberName, ENT_QUOTES) . '</strong>'
                    . "'s payment has been <strong>approved</strong>. "
                    . 'Membership ID: <strong class="font-monospace">' . htmlspecialchars($memId, ENT_QUOTES) . '</strong> is now active.'
                    . $notice
                );

            } elseif ($action === 'reject') {
                $db->prepare(
                    'UPDATE payments SET status="rejected", approved_by=:ab, approved_at=NOW(), notes=:n, updated_at=NOW()
                     WHERE id=:id'
                )->execute([':ab' => $approver, ':n' => $notes, ':id' => $paymentId]);

                $emailSent = false;
                if ($user) {
                    $emailSent = sendPaymentRejectedEmail($user, $notes);
                }

                $memberName = $user ? ($user['first_name'] . ' ' . $user['last_name']) : 'Member';
                $notice     = $emailSent ? ' Member has been notified by email.' : '';
                $reasonText = $notes ? ' Reason: <em>' . htmlspecialchars($notes, ENT_QUOTES) . '</em>.' : '';
                setFlash('warning',
                    '<i class="bi bi-x-circle-fill me-2"></i>'
                    . '<strong>' . htmlspecialchars($memberName, ENT_QUOTES) . '</strong>'
                    . "'s payment has been <strong>rejected</strong>."
                    . $reasonText
                    . $notice
                );
            }
        }
    }

    redirect(BASE_URL . '/admin/payments.php');
}

// ── Filters ───────────────────────────────────────────────
$filter = $_GET['filter'] ?? '';
$method = $_GET['method'] ?? '';

$where  = ['1=1'];
$params = [];

if ($filter && in_array($filter, ['pending','success','rejected','failed'], true)) {
    $where[]         = 'p.status = :status';
    $params[':status'] = $filter;
}
if ($method && in_array($method, ['paystack','offline'], true)) {
    $where[]          = 'p.method = :method';
    $params[':method'] = $method;
}

$payments = $db->prepare(
    'SELECT p.*, u.first_name, u.last_name, u.email,
            a.first_name as approver_first, a.last_name as approver_last
     FROM payments p
     JOIN users u ON u.id = p.user_id
     LEFT JOIN users a ON a.id = p.approved_by
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY p.created_at DESC'
);
$payments->execute($params);
$payments = $payments->fetchAll();

$pendingCount = $db->query("SELECT COUNT(*) FROM payments WHERE status='pending' AND method='offline'")->fetchColumn();

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <h4 class="fw-bold mb-0">
    <i class="bi bi-cash-stack me-2"></i>Payments
    <?php if ($pendingCount): ?>
      <span class="badge bg-warning text-dark ms-2"><?= $pendingCount ?> pending</span>
    <?php endif; ?>
  </h4>
</div>

<?= renderFlash() ?>

<!-- ── Filters ─────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold">Status</label>
        <select name="filter" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="pending"  <?= $filter === 'pending'  ? 'selected' : '' ?>>Pending</option>
          <option value="success"  <?= $filter === 'success'  ? 'selected' : '' ?>>Success</option>
          <option value="rejected" <?= $filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
          <option value="failed"   <?= $filter === 'failed'   ? 'selected' : '' ?>>Failed</option>
        </select>
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold">Method</label>
        <select name="method" class="form-select form-select-sm">
          <option value="">All Methods</option>
          <option value="paystack" <?= $method === 'paystack' ? 'selected' : '' ?>>Paystack</option>
          <option value="offline"  <?= $method === 'offline'  ? 'selected' : '' ?>>Offline</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="<?= BASE_URL ?>/admin/payments.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- ── Payments Table ──────────────────────────────────── -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-custom mb-0">
        <thead>
          <tr>
            <th>Member</th>
            <th>Amount</th>
            <th>Method</th>
            <th>Reference</th>
            <th>Receipt</th>
            <th>Status</th>
            <th>Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($payments): ?>
            <?php foreach ($payments as $p): ?>
              <tr>
                <td>
                  <div class="fw-semibold small"><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name'], ENT_QUOTES) ?></div>
                  <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($p['email'], ENT_QUOTES) ?></div>
                </td>
                <td class="fw-semibold"><?= formatCurrency((float)$p['amount']) ?></td>
                <td><span class="badge bg-light text-dark text-capitalize"><?= htmlspecialchars($p['method'], ENT_QUOTES) ?></span></td>
                <td><code style="font-size:.72rem"><?= htmlspecialchars($p['reference'] ?? '', ENT_QUOTES) ?></code></td>
                <td>
                  <?php if ($p['receipt_file']): ?>
                    <?php $ext = strtolower(pathinfo($p['receipt_file'], PATHINFO_EXTENSION)); ?>
                    <?php if ($ext === 'pdf'): ?>
                      <a href="<?= BASE_URL ?>/assets/uploads/receipts/<?= urlencode($p['receipt_file']) ?>"
                         target="_blank" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-file-earmark-pdf"></i>
                      </a>
                    <?php else: ?>
                      <a href="<?= BASE_URL ?>/assets/uploads/receipts/<?= urlencode($p['receipt_file']) ?>"
                         target="_blank">
                        <img src="<?= BASE_URL ?>/assets/uploads/receipts/<?= urlencode($p['receipt_file']) ?>"
                             class="receipt-thumb" alt="Receipt">
                      </a>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>
                <td><span class="status-badge badge-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span>
                  <?php if ($p['approved_by']): ?>
                    <div class="text-muted" style="font-size:.7rem">
                      by <?= htmlspecialchars($p['approver_first'] . ' ' . $p['approver_last'], ENT_QUOTES) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="text-muted small"><?= formatDate($p['created_at'], 'd M Y') ?></td>
                <td>
                  <?php if ($p['status'] === 'pending' && $p['method'] === 'offline'): ?>
                    <!-- Approve -->
                    <form method="POST" class="d-inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action"     value="approve">
                      <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-success"
                              data-confirm="Approve this payment and activate membership?">
                        <i class="bi bi-check-lg me-1"></i>Approve
                      </button>
                    </form>
                    <!-- Reject (modal trigger) -->
                    <button type="button" class="btn btn-sm btn-danger ms-1"
                            data-bs-toggle="modal" data-bs-target="#rejectModal"
                            data-payment-id="<?= $p['id'] ?>">
                      <i class="bi bi-x-lg me-1"></i>Reject
                    </button>
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No payments found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Reject Modal ────────────────────────────────────── -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action"     value="reject">
        <input type="hidden" name="payment_id" id="rejectPaymentId" value="">
        <div class="modal-header">
          <h5 class="modal-title text-danger"><i class="bi bi-x-circle me-2"></i>Reject Payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">This will mark the payment as rejected and notify the member by email.</p>
          <label class="form-label fw-semibold">Reason for rejection (optional)</label>
          <textarea name="notes" class="form-control" rows="3"
                    placeholder="e.g. Receipt unclear, amount doesn't match..."></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Reject Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('rejectModal').addEventListener('show.bs.modal', function (e) {
  const btn = e.relatedTarget;
  document.getElementById('rejectPaymentId').value = btn.dataset.paymentId;
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
