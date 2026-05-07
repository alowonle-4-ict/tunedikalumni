<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT_PATH . '/includes/mailer.php';

$pageTitle = 'Manual Payment Clearance';
$activeNav = 'clear_payment';

$db  = getDB();
$fee = (float)getSetting('membership_fee', '5000');

// ── POST: Process Manual Clearance ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $userId    = (int)($_POST['user_id']   ?? 0);
    $amount    = (float)($_POST['amount']  ?? $fee);
    $method    = $_POST['pay_method']      ?? 'manual';
    $reference = trim($_POST['reference']  ?? '');
    $notes     = trim($_POST['notes']      ?? '');
    $approver  = (int)currentUserId();

    $allowedMethods = ['bank_transfer', 'cash', 'cheque', 'manual'];
    if (!in_array($method, $allowedMethods, true)) {
        $method = 'manual';
    }

    if ($userId && $amount > 0) {
        $user = getUserById($userId);

        if ($user) {
            // Auto-generate a reference if none provided
            if ($reference === '') {
                $reference = 'MANUAL-' . strtoupper(bin2hex(random_bytes(4))) . '-' . time();
            }

            // Insert payment record as already-approved
            $db->prepare(
                'INSERT INTO payments
                 (user_id, amount, method, status, reference, notes, approved_by, approved_at, created_at, updated_at)
                 VALUES (:uid, :amt, :mth, "success", :ref, :notes, :ab, NOW(), NOW(), NOW())'
            )->execute([
                ':uid'   => $userId,
                ':amt'   => $amount,
                ':mth'   => $method,
                ':ref'   => $reference,
                ':notes' => $notes,
                ':ab'    => $approver,
            ]);

            activateMembership($userId);
            $membership = getUserMembership($userId);

            $emailSent = false;
            if ($membership) {
                $emailSent = sendPaymentApprovedEmail($user, $membership);
            }

            $memberName = $user['first_name'] . ' ' . $user['last_name'];
            $memId      = $membership['membership_id'] ?? 'N/A';
            $notice     = $emailSent ? ' A confirmation email has been sent to the member.' : '';
            setFlash('success',
                '<i class="bi bi-check-circle-fill me-2"></i>'
                . '<strong>' . htmlspecialchars($memberName, ENT_QUOTES) . '</strong>'
                . ' has been <strong>manually cleared</strong>. '
                . 'Membership ID: <strong class="font-monospace">' . htmlspecialchars($memId, ENT_QUOTES) . '</strong> is now active.'
                . $notice
            );

            redirect(BASE_URL . '/admin/clear_payment.php');
        }
    }

    setFlash('error', 'Invalid submission. Please try again.');
    redirect(BASE_URL . '/admin/clear_payment.php');
}

// ── Search ────────────────────────────────────────────────────
$search  = trim($_GET['search'] ?? '');
$members = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $db->prepare(
        'SELECT u.id, u.first_name, u.last_name, u.email, u.phone,
                m.status AS mem_status, m.membership_id, m.membership_expiry_date,
                (SELECT COUNT(*) FROM payments WHERE user_id = u.id AND status = "success") AS paid_count,
                (SELECT status FROM payments WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) AS last_payment_status
         FROM users u
         LEFT JOIN memberships m ON m.user_id = u.id
         WHERE u.role = "member"
           AND (u.first_name LIKE :q OR u.last_name LIKE :q OR u.email LIKE :q
                OR CONCAT(u.first_name," ",u.last_name) LIKE :q
                OR m.membership_id LIKE :q)
         ORDER BY u.first_name, u.last_name
         LIMIT 30'
    );
    $stmt->execute([':q' => $like]);
    $members = $stmt->fetchAll();
}

// ── Selected member for the clearance form ────────────────────
$selectedUser = null;
$selectedMem  = null;
$recentPayments = [];

if (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0) {
    $uid = (int)$_GET['user_id'];
    $selectedUser = getUserById($uid);

    if ($selectedUser && $selectedUser['role'] === 'member') {
        $selectedMem = getUserMembership($uid);

        $stmt = $db->prepare(
            'SELECT * FROM payments WHERE user_id = :uid ORDER BY created_at DESC LIMIT 10'
        );
        $stmt->execute([':uid' => $uid]);
        $recentPayments = $stmt->fetchAll();
    }
}

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <h4 class="fw-bold mb-0">
    <i class="bi bi-shield-check me-2 text-success"></i>Manual Payment Clearance
  </h4>
</div>

<?= renderFlash() ?>

<div class="alert alert-info d-flex gap-2 align-items-start mb-4">
  <i class="bi bi-info-circle-fill fs-5 mt-1 flex-shrink-0"></i>
  <div class="small">
    Use this tool when a member's payment has been <strong>confirmed in the alumni bank account</strong>
    but the system has not automatically updated their membership — for example, a failed Paystack webhook,
    a bank teller deposit, or a manually confirmed offline transfer.
    This will immediately activate or renew the member's membership.
  </div>
</div>

<!-- ── Step 1: Search ───────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header bg-white py-3">
    <h6 class="fw-bold mb-0"><i class="bi bi-search me-2"></i>Step 1 — Find Member</h6>
  </div>
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-8 col-md-6">
        <label class="form-label small fw-semibold">Search by Name, Email, or Membership ID</label>
        <input type="text" name="search" class="form-control"
               placeholder="e.g. John Doe, john@mail.com, 08/TUN/LAG/0001"
               value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" autofocus>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search me-1"></i>Search
        </button>
        <?php if ($search): ?>
          <a href="<?= BASE_URL ?>/admin/clear_payment.php" class="btn btn-outline-secondary ms-1">Clear</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- ── Search Results ─────────────────────────────────────────  -->
<?php if ($search && $members): ?>
<div class="card mb-4">
  <div class="card-header bg-white py-3">
    <h6 class="fw-bold mb-0">Search Results (<?= count($members) ?>)</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-custom mb-0">
        <thead>
          <tr>
            <th>Member</th>
            <th>Membership ID</th>
            <th>Status</th>
            <th>Last Payment</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $m): ?>
            <tr <?= (isset($_GET['user_id']) && (int)$_GET['user_id'] === (int)$m['id']) ? 'class="table-warning"' : '' ?>>
              <td>
                <div class="fw-semibold small"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name'], ENT_QUOTES) ?></div>
                <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($m['email'], ENT_QUOTES) ?></div>
              </td>
              <td>
                <?php if ($m['membership_id']): ?>
                  <code class="small"><?= htmlspecialchars($m['membership_id'], ENT_QUOTES) ?></code>
                <?php else: ?>
                  <span class="text-muted small">Not assigned</span>
                <?php endif; ?>
              </td>
              <td>
                <?php
                  $s = $m['mem_status'] ?? 'none';
                  $badgeMap = ['active' => 'success', 'expired' => 'danger', 'none' => 'secondary'];
                  $badge = $badgeMap[$s] ?? 'secondary';
                ?>
                <span class="badge bg-<?= $badge ?>"><?= ucfirst($s === 'none' ? 'No Membership' : $s) ?></span>
              </td>
              <td>
                <?php if ($m['last_payment_status']): ?>
                  <span class="status-badge badge-<?= $m['last_payment_status'] ?>">
                    <?= ucfirst($m['last_payment_status']) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted small">None</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="?search=<?= urlencode($search) ?>&user_id=<?= $m['id'] ?>"
                   class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-pencil-square me-1"></i>Select
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php elseif ($search && !$members): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle me-2"></i>No members found matching "<strong><?= htmlspecialchars($search, ENT_QUOTES) ?></strong>".
</div>
<?php endif; ?>

<!-- ── Step 2: Clearance Form ────────────────────────────────── -->
<?php if ($selectedUser): ?>
  <?php
    $memStatus = $selectedMem['status'] ?? 'none';
    $isActive  = $memStatus === 'active';
  ?>
  <div class="card border-primary">
    <div class="card-header bg-primary text-white py-3">
      <h6 class="fw-bold mb-0">
        <i class="bi bi-person-check-fill me-2"></i>Step 2 — Clear Payment for
        <?= htmlspecialchars($selectedUser['first_name'] . ' ' . $selectedUser['last_name'], ENT_QUOTES) ?>
      </h6>
    </div>
    <div class="card-body">

      <!-- Member Info Summary -->
      <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
          <div class="p-3 bg-light rounded">
            <div class="small text-muted fw-semibold">EMAIL</div>
            <div class="fw-bold small"><?= htmlspecialchars($selectedUser['email'], ENT_QUOTES) ?></div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="p-3 bg-light rounded">
            <div class="small text-muted fw-semibold">MEMBERSHIP ID</div>
            <div class="fw-bold small font-monospace">
              <?= $selectedMem ? htmlspecialchars($selectedMem['membership_id'], ENT_QUOTES) : 'Not yet assigned' ?>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="p-3 bg-light rounded">
            <div class="small text-muted fw-semibold">CURRENT STATUS</div>
            <?php
              $badgeMap = ['active' => 'success', 'expired' => 'danger', 'none' => 'secondary'];
              $badge = $badgeMap[$memStatus] ?? 'secondary';
            ?>
            <span class="badge bg-<?= $badge ?> mt-1"><?= ucfirst($memStatus === 'none' ? 'No Membership' : $memStatus) ?></span>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="p-3 bg-light rounded">
            <div class="small text-muted fw-semibold">EXPIRY DATE</div>
            <div class="fw-bold small">
              <?= $selectedMem ? formatDate($selectedMem['membership_expiry_date']) : '—' ?>
            </div>
          </div>
        </div>
      </div>

      <?php if ($isActive): ?>
        <div class="alert alert-warning d-flex gap-2 mb-4">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
          <div class="small">
            This member's membership is <strong>currently active</strong> (expires <?= formatDate($selectedMem['membership_expiry_date']) ?>).
            Clearing a payment now will <strong>reset and extend</strong> their membership by one year from today.
          </div>
        </div>
      <?php endif; ?>

      <!-- Recent Payments -->
      <?php if ($recentPayments): ?>
        <h6 class="fw-semibold mb-2 mt-3">Recent Payment History</h6>
        <div class="table-responsive mb-4">
          <table class="table table-sm table-bordered mb-0" style="font-size:.82rem">
            <thead class="table-light">
              <tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentPayments as $rp): ?>
                <tr>
                  <td><?= formatDate($rp['created_at'], 'd M Y H:i') ?></td>
                  <td><?= formatCurrency((float)$rp['amount']) ?></td>
                  <td><span class="badge bg-light text-dark text-capitalize"><?= htmlspecialchars($rp['method'], ENT_QUOTES) ?></span></td>
                  <td><code style="font-size:.7rem"><?= htmlspecialchars($rp['reference'] ?? '—', ENT_QUOTES) ?></code></td>
                  <td><span class="status-badge badge-<?= $rp['status'] ?>"><?= ucfirst($rp['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <!-- Clearance Form -->
      <hr>
      <h6 class="fw-semibold mb-3">
        <i class="bi bi-pen-fill me-2 text-primary"></i>Enter Payment Details to Clear
      </h6>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="user_id" value="<?= (int)$selectedUser['id'] ?>">

        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Amount Paid <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text">₦</span>
              <input type="number" name="amount" class="form-control" step="0.01" min="1"
                     value="<?= $fee ?>" required>
            </div>
            <div class="form-text">Default is the current membership fee.</div>
          </div>

          <div class="col-sm-6">
            <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
            <select name="pay_method" class="form-select" required>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="cash">Cash Payment</option>
              <option value="cheque">Cheque</option>
              <option value="manual">Manual / Other</option>
            </select>
          </div>

          <div class="col-sm-6">
            <label class="form-label fw-semibold">Transaction / Teller Reference</label>
            <input type="text" name="reference" class="form-control"
                   placeholder="e.g. Bank teller number, transfer ref...">
            <div class="form-text">Leave blank to auto-generate a reference.</div>
          </div>

          <div class="col-sm-6">
            <label class="form-label fw-semibold">Internal Notes</label>
            <input type="text" name="notes" class="form-control"
                   placeholder="e.g. Confirmed by branch manager on 10 Apr 2026">
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button type="submit" class="btn btn-success px-4"
                  data-confirm="<?= $isActive
                    ? 'This will RESET and extend this member\'s active membership by 1 year. Continue?'
                    : 'This will immediately activate membership for this member. Continue?' ?>">
            <i class="bi bi-check2-circle me-2"></i>
            <?= $isActive ? 'Extend & Clear Payment' : 'Clear Payment & Activate Membership' ?>
          </button>
          <a href="<?= BASE_URL ?>/admin/clear_payment.php<?= $search ? '?search=' . urlencode($search) : '' ?>"
             class="btn btn-outline-secondary">Cancel</a>
        </div>
      </form>

    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
