<?php
$pageTitle = 'Loan Management';
$activeNav = 'loans';

$db = getDB();

// ── Handle approve/reject/disburse/verify_repayment ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';
    $loanId  = (int)($_POST['loan_id'] ?? 0);
    $notes   = trim($_POST['admin_notes'] ?? '');
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    $loan = getLoan($loanId);
    if ($loan) {
        if ($action === 'approve' && $loan['status'] === 'pending_admin') {
            $db->prepare(
                "UPDATE loans SET status = 'approved', approved_by = :ab, approved_at = NOW(),
                                  admin_notes = :notes WHERE id = :id"
            )->execute([':ab' => $adminId, ':notes' => $notes, ':id' => $loanId]);

            pushNotification(
                (int)$loan['user_id'],
                'Loan Approved',
                'Your loan application of ' . formatCurrency((float)$loan['amount']) .
                    ' has been approved by the admin.',
                BASE_URL . '/pages/my_loans.php',
                'success'
            );
            logAudit($adminId, 'loan_approve', "Approved loan #{$loanId} for user #{$loan['user_id']}");
            setFlash('success', 'Loan approved. Member has been notified.');

        } elseif ($action === 'disburse' && $loan['status'] === 'approved') {
            $db->prepare(
                "UPDATE loans SET status = 'active', disbursed_at = NOW(),
                                  admin_notes = :notes WHERE id = :id"
            )->execute([':notes' => $notes, ':id' => $loanId]);

            pushNotification(
                (int)$loan['user_id'],
                'Loan Disbursed',
                'Your loan of ' . formatCurrency((float)$loan['amount']) .
                    ' has been disbursed. Please begin repayment as per your schedule.',
                BASE_URL . '/pages/my_loans.php',
                'info'
            );
            logAudit($adminId, 'loan_disburse', "Marked loan #{$loanId} as disbursed");
            setFlash('success', 'Loan marked as disbursed. Member can now record repayments.');

        } elseif ($action === 'reject') {
            $db->prepare(
                "UPDATE loans SET status = 'rejected', approved_by = :ab, approved_at = NOW(),
                                  admin_notes = :notes WHERE id = :id"
            )->execute([':ab' => $adminId, ':notes' => $notes, ':id' => $loanId]);

            pushNotification(
                (int)$loan['user_id'],
                'Loan Application Rejected',
                'Your loan application of ' . formatCurrency((float)$loan['amount']) .
                    ' was not approved.' . ($notes ? ' Reason: ' . $notes : ''),
                BASE_URL . '/pages/my_loans.php',
                'danger'
            );
            logAudit($adminId, 'loan_reject', "Rejected loan #{$loanId}");
            setFlash('warning', 'Loan application rejected. Member has been notified.');

        } elseif ($action === 'add_repayment') {
            // Admin can record a repayment on behalf of member
            $principal   = (float)str_replace(',', '', $_POST['principal_amount'] ?? 0);
            $payDate     = $_POST['payment_date'] ?? date('Y-m-d');
            $repNotes    = trim($_POST['repay_notes'] ?? '');

            if ($principal > 0 && $loan['status'] === 'active') {
                $fee       = 150.00;
                $totalPaid = $principal + $fee;
                $newTotal  = min((float)$loan['amount'], (float)$loan['total_repaid'] + $principal);

                $db->prepare(
                    'INSERT INTO loan_repayments (loan_id, principal_amount, transaction_fee, total_paid, payment_date, notes, recorded_by)
                     VALUES (:lid, :p, :f, :t, :d, :n, :rb)'
                )->execute([
                    ':lid' => $loanId, ':p' => $principal, ':f' => $fee,
                    ':t'   => $totalPaid, ':d' => $payDate,
                    ':n'   => $repNotes ?: null, ':rb' => $adminId,
                ]);

                $newStatus = $newTotal >= (float)$loan['amount'] ? 'completed' : 'active';
                $db->prepare(
                    'UPDATE loans SET total_repaid = :tr, status = :s, last_repayment_date = :d,
                                     consecutive_missed = 0 WHERE id = :id'
                )->execute([':tr' => $newTotal, ':s' => $newStatus, ':d' => $payDate, ':id' => $loanId]);

                if ($newStatus === 'completed') {
                    $guarantors = getLoanGuarantors($loanId);
                    foreach ($guarantors as $g) {
                        pushNotification(
                            (int)$g['guarantor_user_id'],
                            'Loan Fully Repaid',
                            htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name'], ENT_QUOTES) .
                                '\'s loan has been fully repaid.',
                            BASE_URL . '/pages/my_loans.php', 'success'
                        );
                    }
                }
                logAudit($adminId, 'loan_repayment_admin', "Recorded repayment of " . formatCurrency($principal) . " for loan #{$loanId}");
                setFlash('success', 'Repayment recorded successfully.');
            }
        }
    }

    // ── Verify offline repayment receipt ─────────────────────
    if ($action === 'verify_repayment') {
        $repayId = (int)($_POST['repay_id'] ?? 0);
        $rStmt   = $db->prepare(
            "SELECT * FROM loan_repayments WHERE id = :id AND status = 'pending' AND method = 'offline' LIMIT 1"
        );
        $rStmt->execute([':id' => $repayId]);
        $repay = $rStmt->fetch();

        if ($repay) {
            // Confirm the offline repayment
            $db->prepare(
                "UPDATE loan_repayments SET status = 'confirmed', verified_by = :vb, verified_at = NOW() WHERE id = :id"
            )->execute([':vb' => $adminId, ':id' => $repayId]);

            // Update loan balance
            $lStmt2 = $db->prepare('SELECT * FROM loans WHERE id = :id LIMIT 1');
            $lStmt2->execute([':id' => $repay['loan_id']]);
            $rLoan = $lStmt2->fetch();
            if ($rLoan) {
                $newTotal  = min((float)$rLoan['amount'], (float)$rLoan['total_repaid'] + (float)$repay['principal_amount']);
                $newStatus = $newTotal >= (float)$rLoan['amount'] ? 'completed' : 'active';
                $db->prepare(
                    'UPDATE loans SET total_repaid = :tr, status = :s, last_repayment_date = :d,
                                     consecutive_missed = 0 WHERE id = :id'
                )->execute([':tr' => $newTotal, ':s' => $newStatus, ':d' => date('Y-m-d'), ':id' => $rLoan['id']]);

                if ($newStatus === 'completed') {
                    foreach (getLoanGuarantors((int)$rLoan['id']) as $g) {
                        pushNotification(
                            (int)$g['guarantor_user_id'],
                            'Loan Fully Repaid',
                            htmlspecialchars($rLoan['first_name'] ?? '', ENT_QUOTES) . ' ' .
                                htmlspecialchars($rLoan['last_name'] ?? '', ENT_QUOTES) .
                                '\'s loan has been fully repaid.',
                            BASE_URL . '/pages/my_loans.php', 'success'
                        );
                    }
                }
                // Notify borrower
                pushNotification(
                    (int)$rLoan['user_id'],
                    'Repayment Confirmed',
                    'Your offline loan repayment of ' . formatCurrency((float)$repay['principal_amount']) .
                        ' has been verified and confirmed by admin.',
                    BASE_URL . '/pages/my_loans.php',
                    'success'
                );
            }
            logAudit($adminId, 'loan_repayment_verify', "Verified offline repayment #{$repayId}");
            setFlash('success', 'Offline repayment verified and confirmed.');
        }

        redirect(BASE_URL . '/admin/loans.php');
    }

    // ── Reject offline repayment receipt ─────────────────────
    if ($action === 'reject_repayment') {
        $repayId    = (int)($_POST['repay_id'] ?? 0);
        $rejectNote = trim($_POST['reject_note'] ?? '');
        $rStmt      = $db->prepare(
            "SELECT lr.*, l.user_id FROM loan_repayments lr
             JOIN loans l ON l.id = lr.loan_id
             WHERE lr.id = :id AND lr.status = 'pending' LIMIT 1"
        );
        $rStmt->execute([':id' => $repayId]);
        $repay = $rStmt->fetch();
        if ($repay) {
            $db->prepare("DELETE FROM loan_repayments WHERE id = :id")->execute([':id' => $repayId]);
            pushNotification(
                (int)$repay['user_id'],
                'Repayment Receipt Rejected',
                'Your offline loan repayment receipt was rejected.' . ($rejectNote ? ' Reason: ' . $rejectNote : '') .
                    ' Please resubmit with a valid receipt.',
                BASE_URL . '/pages/my_loans.php',
                'danger'
            );
            logAudit($adminId, 'loan_repayment_reject', "Rejected offline repayment #{$repayId}");
            setFlash('warning', 'Offline repayment receipt rejected. Member has been notified.');
        }
        redirect(BASE_URL . '/admin/loans.php');
    }

    redirect(BASE_URL . '/admin/loans.php');
}

// ── Filter ────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$allowedStatuses = ['pending_admin', 'approved', 'active', 'completed', 'rejected', ''];
if (!in_array($filterStatus, $allowedStatuses)) $filterStatus = '';

$where  = $filterStatus ? "WHERE l.status = " . $db->quote($filterStatus) : "WHERE l.status != 'pending_guarantors'";
$loans  = $db->query(
    "SELECT l.*, u.first_name, u.last_name, u.email,
            m.membership_id,
            (SELECT COUNT(*) FROM loan_repayments WHERE loan_id = l.id) AS repay_count
     FROM loans l
     JOIN users u ON u.id = l.user_id
     LEFT JOIN memberships m ON m.user_id = l.user_id
     {$where}
     ORDER BY FIELD(l.status,'pending_admin','approved','active','completed','rejected'), l.created_at DESC"
)->fetchAll();

// Pending offline repayments awaiting admin verification
$pendingRepayments = $db->query(
    "SELECT lr.*, l.amount AS loan_amount, l.total_repaid,
            u.first_name, u.last_name, u.email, m.membership_id
     FROM loan_repayments lr
     JOIN loans l ON l.id = lr.loan_id
     JOIN users u ON u.id = l.user_id
     LEFT JOIN memberships m ON m.user_id = l.user_id
     WHERE lr.status = 'pending' AND lr.method = 'offline'
     ORDER BY lr.created_at ASC"
)->fetchAll();

// Stats
$stats = $db->query(
    "SELECT
       SUM(status = 'pending_admin') AS pending,
       SUM(status = 'approved')      AS approved,
       SUM(status = 'active')        AS active,
       SUM(status = 'completed')     AS completed,
       SUM(status = 'rejected')      AS rejected,
       COALESCE(SUM(CASE WHEN status = 'active' THEN amount - total_repaid END), 0) AS outstanding
     FROM loans WHERE status != 'pending_guarantors'"
)->fetch();

require_once __DIR__ . '/includes/admin_header.php';
?>
<?= renderFlash() ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
  <div>
    <h4 class="fw-bold mb-0"><i class="bi bi-bank me-2 text-primary"></i>Loan Management</h4>
    <p class="text-muted small mb-0">Review applications, approve loans, track repayments.</p>
  </div>
</div>

<!-- ── Pending Offline Repayments ────────────────────────── -->
<?php if (!empty($pendingRepayments)): ?>
<div class="card border-warning border-2 shadow-sm mb-4">
  <div class="card-header bg-warning text-dark fw-bold">
    <i class="bi bi-receipt-cutoff me-2"></i>Offline Repayments Awaiting Verification
    <span class="badge bg-dark ms-2"><?= count($pendingRepayments) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Member</th>
          <th>Amount</th>
          <th>Fee</th>
          <th>Total</th>
          <th>Submitted</th>
          <th>Receipt</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingRepayments as $pr): ?>
        <tr>
          <td>
            <div class="fw-semibold small"><?= htmlspecialchars($pr['first_name'] . ' ' . $pr['last_name'], ENT_QUOTES) ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($pr['membership_id'] ?? $pr['email'], ENT_QUOTES) ?></div>
          </td>
          <td><?= formatCurrency((float)$pr['principal_amount']) ?></td>
          <td>₦<?= number_format((float)$pr['transaction_fee'], 0) ?></td>
          <td class="fw-semibold"><?= formatCurrency((float)$pr['total_paid']) ?></td>
          <td class="small text-muted"><?= formatDate($pr['created_at']) ?></td>
          <td>
            <?php if ($pr['receipt_file']): ?>
              <a href="<?= BASE_URL ?>/assets/uploads/receipts/<?= urlencode($pr['receipt_file']) ?>"
                 target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-file-earmark-image me-1"></i>View
              </a>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
            <?php if ($pr['notes']): ?>
              <div class="small text-muted mt-1"><?= htmlspecialchars($pr['notes'], ENT_QUOTES) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="verify_repayment">
                <input type="hidden" name="repay_id" value="<?= $pr['id'] ?>">
                <button type="submit" class="btn btn-success btn-sm fw-semibold"
                        onclick="return confirm('Confirm this repayment receipt as genuine?')">
                  <i class="bi bi-check2"></i> Confirm
                </button>
              </form>
              <button type="button" class="btn btn-outline-danger btn-sm"
                      data-bs-toggle="modal" data-bs-target="#rejectRepay<?= $pr['id'] ?>">
                <i class="bi bi-x"></i> Reject
              </button>
            </div>
          </td>
        </tr>
        <!-- Reject modal -->
        <div class="modal fade" id="rejectRepay<?= $pr['id'] ?>" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
              <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title fw-bold">Reject Receipt</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject_repayment">
                <input type="hidden" name="repay_id" value="<?= $pr['id'] ?>">
                <div class="modal-body">
                  <label class="form-label fw-semibold small">Reason (optional)</label>
                  <textarea name="reject_note" class="form-control form-control-sm" rows="2"
                            placeholder="e.g. Receipt is unclear or amount doesn't match"></textarea>
                </div>
                <div class="modal-footer py-2">
                  <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" class="btn btn-sm btn-danger fw-semibold">Reject</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="row g-3 mb-4">
  <?php
  $statCards = [
    ['label' => 'Pending Review', 'val' => $stats['pending'],   'bg' => 'bg-warning text-dark', 'status' => 'pending_admin'],
    ['label' => 'Approved',       'val' => $stats['approved'],   'bg' => 'bg-primary',           'status' => 'approved'],
    ['label' => 'Active Loans',   'val' => $stats['active'],     'bg' => 'bg-success',           'status' => 'active'],
    ['label' => 'Completed',      'val' => $stats['completed'],  'bg' => 'bg-secondary',         'status' => 'completed'],
    ['label' => 'Rejected',       'val' => $stats['rejected'],   'bg' => 'bg-danger',            'status' => 'rejected'],
  ];
  foreach ($statCards as $sc): ?>
  <div class="col-6 col-sm-4 col-md-2">
    <a href="?status=<?= $sc['status'] ?>" class="text-decoration-none">
      <div class="card border-0 shadow-sm text-center h-100">
        <div class="card-body py-3 px-2">
          <div class="badge <?= $sc['bg'] ?> mb-1 fs-5 w-100 py-2"><?= $sc['val'] ?></div>
          <div class="small fw-semibold text-muted"><?= $sc['label'] ?></div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
  <div class="col-6 col-sm-4 col-md-2">
    <div class="card border-0 shadow-sm text-center h-100">
      <div class="card-body py-3 px-2">
        <div class="fw-bold text-danger fs-6"><?= formatCurrency((float)$stats['outstanding']) ?></div>
        <div class="small fw-semibold text-muted">Outstanding Balance</div>
      </div>
    </div>
  </div>
</div>

<!-- Filter bar -->
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <span class="small text-muted me-1">Filter:</span>
  <?php
  $filters = ['' => 'All Visible', 'pending_admin' => 'Pending Review', 'approved' => 'Approved',
              'active' => 'Active', 'completed' => 'Completed', 'rejected' => 'Rejected'];
  foreach ($filters as $val => $lbl): ?>
    <a href="?status=<?= $val ?>"
       class="btn btn-sm <?= $filterStatus === $val ? 'btn-primary' : 'btn-outline-secondary' ?>">
      <?= $lbl ?>
    </a>
  <?php endforeach; ?>
</div>

<!-- Loans accordion -->
<div class="accordion shadow-sm" id="loansAccordion">

  <?php if (empty($loans)): ?>
    <div class="card border-0">
      <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>No loans found.
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($loans as $idx => $l):
    $balance    = loanBalance($l);
    $guarantors = getLoanGuarantors((int)$l['id']);
    $repayments = getLoanRepayments((int)$l['id']);
    $lPct       = $l['amount'] > 0 ? min(100, round((float)$l['total_repaid'] / (float)$l['amount'] * 100)) : 0;
    $collapseId = 'loanCollapse' . $l['id'];
  ?>
  <div class="accordion-item border-0 border-bottom">

    <!-- ── Row header (clickable) ── -->
    <h2 class="accordion-header" id="loanHead<?= $l['id'] ?>">
      <button class="accordion-button collapsed py-3 px-4 bg-white"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target="#<?= $collapseId ?>"
              aria-expanded="false"
              aria-controls="<?= $collapseId ?>">
        <div class="d-flex flex-wrap align-items-center gap-3 w-100 me-3">
          <span class="text-muted small" style="min-width:28px">#<?= $l['id'] ?></span>
          <div style="min-width:180px">
            <div class="fw-semibold lh-sm"><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name'], ENT_QUOTES) ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($l['membership_id'] ?? $l['email'], ENT_QUOTES) ?></div>
          </div>
          <div style="min-width:110px">
            <div class="fw-bold text-primary"><?= formatCurrency((float)$l['amount']) ?></div>
            <div class="small <?= $l['status'] === 'completed' ? 'text-success fw-semibold' : 'text-muted' ?>">
              <?= $l['status'] === 'completed' ? 'Cleared' : formatCurrency($balance) . ' left' ?>
            </div>
          </div>
          <div class="d-none d-sm-block" style="min-width:80px">
            <div class="small text-muted"><?= (int)$l['repayment_period'] ?> mo.</div>
            <div class="small text-muted"><?= formatDate($l['created_at']) ?></div>
          </div>
          <?php if (in_array($l['status'], ['active', 'completed'])): ?>
          <div class="d-none d-md-block" style="min-width:100px">
            <div class="progress" style="height:6px;border-radius:4px;width:100px">
              <div class="progress-bar <?= $l['status'] === 'completed' ? 'bg-success' : 'bg-primary' ?>"
                   style="width:<?= $lPct ?>%;border-radius:4px"></div>
            </div>
            <div class="text-muted" style="font-size:.7rem;margin-top:2px"><?= $lPct ?>% repaid</div>
          </div>
          <?php endif; ?>
          <span class="badge <?= loanStatusBadgeClass($l['status']) ?> ms-auto"><?= loanStatusLabel($l['status']) ?></span>
        </div>
      </button>
    </h2>

    <!-- ── Collapsible detail body ── -->
    <div id="<?= $collapseId ?>" class="accordion-collapse collapse"
         data-bs-parent="#loansAccordion">
      <div class="accordion-body pt-0 px-4 pb-4 bg-light">

        <div class="row g-4 mt-1">

          <!-- Left: loan info -->
          <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-header fw-semibold small bg-white border-bottom py-2">
                <i class="bi bi-info-circle me-1 text-primary"></i>Loan Details
              </div>
              <div class="card-body p-0">
                <table class="table table-sm mb-0">
                  <tr><th class="ps-3 text-muted fw-normal" style="width:45%">Amount</th>
                      <td class="fw-bold text-primary"><?= formatCurrency((float)$l['amount']) ?></td></tr>
                  <tr><th class="ps-3 text-muted fw-normal">Purpose</th>
                      <td class="small"><?= nl2br(htmlspecialchars($l['purpose'], ENT_QUOTES)) ?></td></tr>
                  <tr><th class="ps-3 text-muted fw-normal">Bank Account</th>
                      <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($l['account_name'], ENT_QUOTES) ?></div>
                        <div class="text-muted" style="font-size:.75rem">
                          <?= htmlspecialchars($l['bank_name'], ENT_QUOTES) ?> ·
                          <span class="font-monospace"><?= htmlspecialchars($l['account_number'], ENT_QUOTES) ?></span>
                        </div>
                      </td></tr>
                  <tr><th class="ps-3 text-muted fw-normal">Period</th>
                      <td><?= (int)$l['repayment_period'] ?> month(s)</td></tr>
                  <tr><th class="ps-3 text-muted fw-normal">Monthly</th>
                      <td>₦<?= number_format((float)$l['monthly_amount'] + 150, 0) ?> <span class="text-muted small">(incl. ₦150)</span></td></tr>
                  <tr><th class="ps-3 text-muted fw-normal">Repaid</th>
                      <td><?= formatCurrency((float)$l['total_repaid']) ?></td></tr>
                  <tr><th class="ps-3 text-muted fw-normal">Balance</th>
                      <td class="fw-bold <?= $l['status'] === 'completed' ? 'text-success' : 'text-danger' ?>">
                        <?= $l['status'] === 'completed' ? 'Fully Repaid' : formatCurrency($balance) ?>
                      </td></tr>
                  <?php if ($l['approved_at']): ?>
                  <tr><th class="ps-3 text-muted fw-normal">Approved</th>
                      <td class="small"><?= formatDate($l['approved_at']) ?></td></tr>
                  <?php endif; ?>
                  <?php if ($l['disbursed_at']): ?>
                  <tr><th class="ps-3 text-muted fw-normal">Disbursed</th>
                      <td class="small"><?= formatDate($l['disbursed_at']) ?></td></tr>
                  <?php endif; ?>
                </table>
              </div>
            </div>
          </div>

          <!-- Right: guarantors + repayments + actions -->
          <div class="col-lg-7 d-flex flex-column gap-3">

            <!-- Guarantors -->
            <div class="card border-0 shadow-sm">
              <div class="card-header fw-semibold small bg-white border-bottom py-2">
                <i class="bi bi-shield-check me-1 text-success"></i>Guarantors
              </div>
              <div class="card-body py-2 d-flex flex-wrap gap-2">
                <?php foreach ($guarantors as $g): ?>
                  <span class="badge <?= $g['status'] === 'accepted' ? 'bg-success' : ($g['status'] === 'rejected' ? 'bg-danger' : 'bg-warning text-dark') ?> fw-normal p-2">
                    <i class="bi bi-person me-1"></i>
                    <?= htmlspecialchars($g['first_name'] . ' ' . $g['last_name'], ENT_QUOTES) ?> — <?= ucfirst($g['status']) ?>
                  </span>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Repayment history -->
            <?php if (!empty($repayments)): ?>
            <div class="card border-0 shadow-sm">
              <div class="card-header fw-semibold small bg-white border-bottom py-2">
                <i class="bi bi-clock-history me-1 text-secondary"></i>Repayment History
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead class="table-light">
                    <tr><th>Date</th><th>Principal</th><th>Fee</th><th>Total</th><th>Method</th><th>Notes</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($repayments as $r): ?>
                    <tr class="<?= ($r['status'] ?? '') === 'pending' ? 'table-warning' : '' ?>">
                      <td class="small"><?= htmlspecialchars($r['payment_date'], ENT_QUOTES) ?></td>
                      <td><?= formatCurrency((float)$r['principal_amount']) ?></td>
                      <td>₦<?= number_format((float)$r['transaction_fee'], 0) ?></td>
                      <td class="fw-semibold"><?= formatCurrency((float)$r['total_paid']) ?></td>
                      <td>
                        <?php
                          echo match($r['method'] ?? 'admin') {
                            'paystack' => '<span class="badge bg-primary">Online</span>',
                            'offline'  => '<span class="badge bg-warning text-dark">Offline</span>',
                            default    => '<span class="badge bg-secondary">Admin</span>',
                          };
                          if (($r['status'] ?? '') === 'pending') echo ' <span class="badge bg-danger">Pending</span>';
                        ?>
                      </td>
                      <td class="text-muted small"><?= htmlspecialchars($r['notes'] ?? '—', ENT_QUOTES) ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <?php endif; ?>

            <!-- Admin actions -->
            <?php if ($l['status'] === 'pending_admin'): ?>
            <div class="card border-warning border-2 shadow-sm">
              <div class="card-header fw-semibold small bg-warning text-dark py-2">
                <i class="bi bi-gavel me-1"></i>Admin Decision
              </div>
              <div class="card-body">
                <form method="POST">
                  <?= csrfField() ?>
                  <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                  <div class="mb-3">
                    <label class="form-label fw-semibold small">Notes for member (optional)</label>
                    <input type="text" name="admin_notes" class="form-control form-control-sm"
                           placeholder="Any notes for the member...">
                  </div>
                  <div class="d-flex gap-2">
                    <button type="submit" name="action" value="approve"
                            class="btn btn-success fw-semibold"
                            onclick="return confirm('Approve this loan application?')">
                      <i class="bi bi-check-circle me-1"></i>Approve
                    </button>
                    <button type="submit" name="action" value="reject"
                            class="btn btn-danger fw-semibold"
                            onclick="return confirm('Reject this loan application?')">
                      <i class="bi bi-x-circle me-1"></i>Reject
                    </button>
                  </div>
                </form>
              </div>
            </div>

            <?php elseif ($l['status'] === 'approved'): ?>
            <div class="card border-info border-2 shadow-sm">
              <div class="card-header fw-semibold small bg-info text-white py-2">
                <i class="bi bi-send me-1"></i>Disburse Loan
              </div>
              <div class="card-body">
                <form method="POST">
                  <?= csrfField() ?>
                  <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                  <input type="hidden" name="action" value="disburse">
                  <div class="mb-3">
                    <label class="form-label fw-semibold small">Disbursement Notes (optional)</label>
                    <input type="text" name="admin_notes" class="form-control form-control-sm"
                           placeholder="e.g. transferred to GTBank account ending 1234">
                  </div>
                  <button type="submit" class="btn btn-info fw-semibold"
                          onclick="return confirm('Mark this loan as disbursed?')">
                    <i class="bi bi-send me-1"></i>Mark as Disbursed
                  </button>
                </form>
              </div>
            </div>

            <?php elseif ($l['status'] === 'active'): ?>
            <div class="card border-success border-2 shadow-sm">
              <div class="card-header fw-semibold small bg-success text-white py-2">
                <i class="bi bi-cash-stack me-1"></i>Record Repayment on Behalf of Member
              </div>
              <div class="card-body">
                <form method="POST">
                  <?= csrfField() ?>
                  <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                  <input type="hidden" name="action" value="add_repayment">
                  <div class="row g-2 mb-3">
                    <div class="col-sm-4">
                      <label class="form-label fw-semibold small">Principal (₦)</label>
                      <input type="number" name="principal_amount" class="form-control form-control-sm"
                             min="1" max="<?= $balance ?>" step="0.01" required>
                    </div>
                    <div class="col-sm-4">
                      <label class="form-label fw-semibold small">Payment Date</label>
                      <input type="date" name="payment_date" class="form-control form-control-sm"
                             value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-sm-4">
                      <label class="form-label fw-semibold small">Notes</label>
                      <input type="text" name="repay_notes" class="form-control form-control-sm"
                             placeholder="optional">
                    </div>
                  </div>
                  <button type="submit" class="btn btn-success btn-sm fw-semibold">
                    <i class="bi bi-cash-stack me-1"></i>Record Repayment
                  </button>
                </form>
              </div>
            </div>
            <?php endif; ?>

          </div><!-- /col-lg-7 -->
        </div><!-- /row -->

      </div><!-- /accordion-body -->
    </div><!-- /collapse -->
  </div><!-- /accordion-item -->
  <?php endforeach; ?>

</div><!-- /accordion -->

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
