<?php
require_once dirname(__DIR__) . '/config/app.php';

$pageTitle = 'Campaign Details';
$activeNav = 'donations';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    redirect(BASE_URL . '/admin/donations.php');
}

$stmt = $db->prepare(
    'SELECT dc.*,
            u.first_name AS creator_first, u.last_name AS creator_last,
            bu.first_name AS benef_first,  bu.last_name AS benef_last, bu.email AS benef_email
     FROM donation_campaigns dc
     JOIN users u ON u.id = dc.created_by
     LEFT JOIN users bu ON bu.id = dc.beneficiary_user_id
     WHERE dc.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$campaign = $stmt->fetch();

if (!$campaign) {
    setFlash('error', 'Campaign not found.');
    redirect(BASE_URL . '/admin/donations.php');
}

// ── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Record manual donation
    if ($action === 'manual_donation') {
        $donorName  = trim($_POST['donor_name']  ?? '');
        $donorEmail = trim($_POST['donor_email'] ?? '');
        $amount     = (float)($_POST['amount']   ?? 0);
        $notes      = trim($_POST['notes']       ?? '');
        $isAnon     = isset($_POST['is_anonymous']) ? 1 : 0;
        $recorder   = (int)currentUserId();

        if ($donorName === '' || $donorEmail === '' || $amount <= 0) {
            setFlash('error', 'Donor name, email, and a valid amount are required.');
        } elseif (!filter_var($donorEmail, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
        } else {
            $reference = 'MANUAL-DON-' . strtoupper(bin2hex(random_bytes(5))) . '-' . time();
            $db->prepare(
                'INSERT INTO donation_payments
                 (campaign_id, donor_name, donor_email, amount, reference, method, status, message, is_anonymous, recorded_by)
                 VALUES (:cid, :dn, :de, :amt, :ref, "manual", "success", :msg, :anon, :rb)'
            )->execute([
                ':cid'  => $id,
                ':dn'   => $donorName,
                ':de'   => $donorEmail,
                ':amt'  => $amount,
                ':ref'  => $reference,
                ':msg'  => $notes ?: null,
                ':anon' => $isAnon,
                ':rb'   => $recorder,
            ]);

            setFlash('success',
                'Manual donation of <strong>' . formatCurrency($amount) . '</strong> recorded for '
                . '<strong>' . htmlspecialchars($donorName, ENT_QUOTES) . '</strong>.'
            );
        }
        redirect(BASE_URL . '/admin/donation_view.php?id=' . $id);
    }

    // Delete a single donation record
    if ($action === 'delete_donation') {
        $did = (int)($_POST['donation_id'] ?? 0);
        if ($did) {
            $db->prepare('DELETE FROM donation_payments WHERE id = :did AND campaign_id = :cid')
               ->execute([':did' => $did, ':cid' => $id]);
            setFlash('success', 'Donation record removed.');
        }
        redirect(BASE_URL . '/admin/donation_view.php?id=' . $id);
    }

    // Record a withdrawal
    if ($action === 'withdraw') {
        $withdrawAmount = (float)str_replace(',', '', $_POST['withdraw_amount'] ?? 0);
        $withdrawNotes  = trim($_POST['withdraw_notes'] ?? '');
        $adminId        = (int)currentUserId();
        if ($withdrawAmount <= 0) {
            setFlash('error', 'Please enter a valid withdrawal amount.');
        } else {
            $db->prepare(
                'INSERT INTO donation_withdrawals (campaign_id, amount, notes, withdrawn_by)
                 VALUES (:cid, :amt, :notes, :wb)'
            )->execute([
                ':cid'   => $id,
                ':amt'   => $withdrawAmount,
                ':notes' => $withdrawNotes ?: null,
                ':wb'    => $adminId,
            ]);
            setFlash('success', 'Withdrawal of <strong>' . formatCurrency($withdrawAmount) . '</strong> recorded.');
        }
        redirect(BASE_URL . '/admin/donation_view.php?id=' . $id);
    }

    // Edit campaign details
    if ($action === 'edit_campaign') {
        $title       = trim($_POST['title']        ?? '');
        $description = trim($_POST['description']  ?? '');
        $target      = max(0, (float)($_POST['target_amount'] ?? 0));
        $deadline    = trim($_POST['deadline']     ?? '') ?: null;
        $showDonors  = isset($_POST['show_donors']) ? 1 : 0;
        $status      = in_array($_POST['status'] ?? '', ['active','closed','draft'], true) ? $_POST['status'] : 'active';

        if ($title === '') {
            setFlash('error', 'Campaign title is required.');
        } else {
            $db->prepare(
                'UPDATE donation_campaigns
                 SET title=:t, description=:d, target_amount=:amt, deadline=:dl, show_donors=:sd, status=:st
                 WHERE id=:id'
            )->execute([
                ':t'   => $title,
                ':d'   => $description ?: null,
                ':amt' => $target,
                ':dl'  => $deadline,
                ':sd'  => $showDonors,
                ':st'  => $status,
                ':id'  => $id,
            ]);
            setFlash('success', 'Campaign updated.');
        }
        redirect(BASE_URL . '/admin/donation_view.php?id=' . $id);
    }
}

// ── Stats ─────────────────────────────────────────────────────
$statsStmt = $db->prepare(
    'SELECT COALESCE(SUM(amount),0) AS raised,
            COUNT(*) AS donors,
            COALESCE(MAX(amount),0) AS largest,
            COALESCE(AVG(amount),0) AS average
     FROM donation_payments
     WHERE campaign_id = :cid AND status = "success"'
);
$statsStmt->execute([':cid' => $id]);
$stats = $statsStmt->fetch();

// ── Withdrawals ───────────────────────────────────────────────
$wStmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM donation_withdrawals WHERE campaign_id = :cid');
$wStmt->execute([':cid' => $id]);
$withdrawn = (float)$wStmt->fetchColumn();

$wHistStmt = $db->prepare(
    'SELECT dw.*, u.first_name, u.last_name
     FROM donation_withdrawals dw
     JOIN users u ON u.id = dw.withdrawn_by
     WHERE dw.campaign_id = :cid
     ORDER BY dw.created_at DESC'
);
$wHistStmt->execute([':cid' => $id]);
$withdrawalHistory = $wHistStmt->fetchAll();

// ── Donations list ────────────────────────────────────────────
$donStmt = $db->prepare(
    'SELECT dp.*,
            u.first_name AS rec_first, u.last_name AS rec_last
     FROM donation_payments dp
     LEFT JOIN users u ON u.id = dp.recorded_by
     WHERE dp.campaign_id = :cid
     ORDER BY dp.created_at DESC'
);
$donStmt->execute([':cid' => $id]);
$donations = $donStmt->fetchAll();

$target    = (float)$campaign['target_amount'];
$raised    = (float)$stats['raised'];
$available = max(0, $raised - $withdrawn);
$pct       = ($target > 0) ? min(100, round($available / $target * 100)) : null;
$barCls    = $pct === null ? 'bg-primary progress-bar-striped progress-bar-animated'
           : ($pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-info' : ($pct >= 25 ? 'bg-warning' : 'bg-danger')));

$donateUrl = BASE_URL . '/pages/donate.php?ref=' . $campaign['slug'];
$isActive  = $campaign['status'] === 'active';

// Beneficiary display
if ($campaign['beneficiary_user_id'] && $campaign['benef_first']) {
    $benefLabel = $campaign['benef_first'] . ' ' . $campaign['benef_last'];
} elseif ($campaign['beneficiary_name']) {
    $benefLabel = $campaign['beneficiary_name'];
} else {
    $benefLabel = 'General';
}

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex align-items-center gap-3 flex-wrap mb-4">
  <a href="<?= BASE_URL ?>/admin/donations.php" class="text-muted text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>All Campaigns
  </a>
  <span class="text-muted">/</span>
  <h4 class="fw-bold mb-0"><?= htmlspecialchars($campaign['title'], ENT_QUOTES) ?></h4>
  <span class="badge <?= $isActive ? 'bg-success' : ($campaign['status'] === 'draft' ? 'bg-secondary' : 'bg-danger') ?>">
    <?= ucfirst($campaign['status']) ?>
  </span>
</div>

<?= renderFlash() ?>

<div class="row g-4">

  <!-- ── Left: Progress + share ─────────────────────────────── -->
  <div class="col-lg-4">

    <!-- Progress card -->
    <div class="card mb-3">
      <div class="card-body">
        <div class="text-center mb-3">
          <div class="fs-2 fw-bold text-primary"><?= formatCurrency($available) ?></div>
          <div class="text-muted small">
            <?php if ($withdrawn > 0): ?>
              available balance
            <?php elseif ($target > 0): ?>
              raised of <?= formatCurrency($target) ?> target
            <?php else: ?>
              raised (open fundraising)
            <?php endif; ?>
          </div>
          <?php if ($withdrawn > 0): ?>
            <div class="mt-2 small">
              <span class="text-success fw-semibold"><?= formatCurrency($raised) ?> raised</span>
              <span class="text-muted mx-1">&minus;</span>
              <span class="text-danger fw-semibold"><?= formatCurrency($withdrawn) ?> withdrawn</span>
            </div>
          <?php endif; ?>
        </div>
        <div class="progress mb-2" style="height:14px;border-radius:7px">
          <div class="progress-bar <?= $barCls ?>" style="width:<?= $pct ?? 100 ?>%;border-radius:7px">
            <?php if ($pct !== null && $pct >= 15): ?><?= $pct ?>%<?php endif; ?>
          </div>
        </div>
        <?php if ($pct !== null): ?>
          <div class="text-center small text-muted"><?= $pct ?>% of goal</div>
        <?php endif; ?>

        <hr>
        <div class="row text-center g-2">
          <div class="col-6">
            <div class="fw-bold fs-5"><?= number_format((int)$stats['donors']) ?></div>
            <div class="small text-muted">Donors</div>
          </div>
          <div class="col-6">
            <div class="fw-bold fs-5"><?= formatCurrency((float)$stats['average']) ?></div>
            <div class="small text-muted">Avg. Donation</div>
          </div>
          <div class="col-6">
            <div class="fw-bold"><?= formatCurrency((float)$stats['largest']) ?></div>
            <div class="small text-muted">Largest</div>
          </div>
          <div class="col-6">
            <div class="fw-bold"><?= $campaign['deadline'] ? formatDate($campaign['deadline']) : '—' ?></div>
            <div class="small text-muted">Deadline</div>
          </div>
        </div>

        <hr>
        <button class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#withdrawModal">
          <i class="bi bi-cash-stack me-1"></i>Record Withdrawal
        </button>
      </div>
    </div>

    <!-- Shareable link -->
    <div class="card mb-3">
      <div class="card-header bg-white py-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-share-fill me-2 text-primary"></i>Shareable Link</h6>
      </div>
      <div class="card-body">
        <div class="input-group mb-2">
          <input type="text" id="donate-url" class="form-control form-control-sm font-monospace"
                 value="<?= htmlspecialchars($donateUrl, ENT_QUOTES) ?>" readonly>
          <button class="btn btn-outline-primary btn-sm" id="copy-btn" onclick="copyLink()">
            <i class="bi bi-clipboard"></i>
          </button>
        </div>
        <a href="<?= htmlspecialchars($donateUrl, ENT_QUOTES) ?>" target="_blank"
           class="btn btn-outline-secondary btn-sm w-100">
          <i class="bi bi-box-arrow-up-right me-1"></i>Open Donation Page
        </a>
      </div>
    </div>

    <!-- Edit campaign -->
    <div class="card mb-3">
      <div class="card-header bg-white py-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-pencil me-2"></i>Edit Campaign</h6>
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="edit_campaign">

          <div class="mb-2">
            <label class="form-label small fw-semibold">Title</label>
            <input type="text" name="title" class="form-control form-control-sm"
                   value="<?= htmlspecialchars($campaign['title'], ENT_QUOTES) ?>" required>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Description</label>
            <textarea name="description" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($campaign['description'] ?? '', ENT_QUOTES) ?></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Target Amount (₦, 0 = unlimited)</label>
            <input type="number" name="target_amount" class="form-control form-control-sm"
                   min="0" step="0.01" value="<?= $target ?>">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Deadline</label>
            <input type="date" name="deadline" class="form-control form-control-sm"
                   value="<?= $campaign['deadline'] ?? '' ?>">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Status</label>
            <select name="status" class="form-select form-select-sm">
              <option value="active"  <?= $campaign['status'] === 'active'  ? 'selected' : '' ?>>Active</option>
              <option value="closed"  <?= $campaign['status'] === 'closed'  ? 'selected' : '' ?>>Closed</option>
              <option value="draft"   <?= $campaign['status'] === 'draft'   ? 'selected' : '' ?>>Draft</option>
            </select>
          </div>
          <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" name="show_donors" id="edit-show-donors"
                   <?= $campaign['show_donors'] ? 'checked' : '' ?>>
            <label class="form-check-label small" for="edit-show-donors">Show donors publicly</label>
          </div>
          <button type="submit" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-save me-1"></i>Save Changes
          </button>
        </form>
      </div>
    </div>

  </div>

  <!-- ── Right: Donations list + manual entry ────────────────── -->
  <div class="col-lg-8">

    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 class="fw-bold mb-0">Donation Records</h5>
      <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#manualModal">
        <i class="bi bi-plus-lg me-1"></i>Record Manual Donation
      </button>
    </div>

    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-custom mb-0">
            <thead>
              <tr>
                <th>Donor</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Message</th>
                <th>Status</th>
                <th>Date</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($donations): ?>
                <?php foreach ($donations as $d): ?>
                  <tr>
                    <td>
                      <?php if ($d['is_anonymous']): ?>
                        <span class="text-muted small fst-italic">Anonymous</span>
                      <?php else: ?>
                        <div class="fw-semibold small"><?= htmlspecialchars($d['donor_name'], ENT_QUOTES) ?></div>
                        <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($d['donor_email'], ENT_QUOTES) ?></div>
                      <?php endif; ?>
                      <?php if ($d['recorded_by']): ?>
                        <div class="text-muted" style="font-size:.68rem">
                          <i class="bi bi-person-gear"></i> by <?= htmlspecialchars($d['rec_first'] . ' ' . $d['rec_last'], ENT_QUOTES) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= formatCurrency((float)$d['amount']) ?></td>
                    <td>
                      <span class="badge bg-light text-dark text-capitalize">
                        <?= $d['method'] === 'paystack' ? '<i class="bi bi-credit-card me-1"></i>Paystack' : '<i class="bi bi-cash me-1"></i>Manual' ?>
                      </span>
                    </td>
                    <td class="text-muted small" style="max-width:140px;word-break:break-word">
                      <?= $d['message'] ? htmlspecialchars($d['message'], ENT_QUOTES) : '—' ?>
                    </td>
                    <td>
                      <span class="status-badge badge-<?= $d['status'] === 'success' ? 'success' : ($d['status'] === 'pending' ? 'pending' : 'rejected') ?>">
                        <?= ucfirst($d['status']) ?>
                      </span>
                    </td>
                    <td class="text-muted small"><?= formatDate($d['created_at'], 'd M Y') ?></td>
                    <td>
                      <form method="POST" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"      value="delete_donation">
                        <input type="hidden" name="donation_id" value="<?= $d['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                data-confirm="Remove this donation record?">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-2"></i>
                    <p class="small mt-2 mb-0">No donations yet.</p>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Withdrawal History -->
    <?php if ($withdrawalHistory): ?>
      <h5 class="fw-bold mt-4 mb-3">
        <i class="bi bi-cash-stack me-2 text-danger"></i>Withdrawal History
      </h5>
      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-custom mb-0">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Amount</th>
                  <th>Notes</th>
                  <th>Recorded by</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($withdrawalHistory as $w): ?>
                  <tr>
                    <td class="text-muted small"><?= formatDate($w['created_at'], 'd M Y H:i') ?></td>
                    <td class="fw-semibold text-danger"><?= formatCurrency((float)$w['amount']) ?></td>
                    <td class="text-muted small"><?= $w['notes'] ? htmlspecialchars($w['notes'], ENT_QUOTES) : '—' ?></td>
                    <td class="small"><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name'], ENT_QUOTES) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot class="table-light">
                <tr>
                  <td class="fw-semibold">Total withdrawn</td>
                  <td class="fw-bold text-danger"><?= formatCurrency($withdrawn) ?></td>
                  <td colspan="2"></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>


<!-- ── Manual Donation Modal ─────────────────────────────────── -->
<div class="modal fade" id="manualModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="manual_donation">

        <div class="modal-header">
          <h5 class="modal-title fw-bold">
            <i class="bi bi-cash-coin me-2 text-success"></i>Record Manual Donation
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">
            Use this for cash, cheque, or bank transfer donations confirmed outside the system.
          </p>
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Donor Name <span class="text-danger">*</span></label>
              <input type="text" name="donor_name" class="form-control" required placeholder="Full name">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Donor Email <span class="text-danger">*</span></label>
              <input type="email" name="donor_email" class="form-control" required placeholder="email@example.com">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Amount (₦) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">₦</span>
                <input type="number" name="amount" class="form-control" min="1" step="0.01" required>
              </div>
            </div>
            <div class="col-sm-6 d-flex align-items-end">
              <div class="form-check">
                <input type="checkbox" class="form-check-input" name="is_anonymous" id="manual-anon">
                <label class="form-check-label" for="manual-anon">Anonymous donor</label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes (optional)</label>
              <input type="text" name="notes" class="form-control"
                     placeholder="e.g. Bank teller ref, date confirmed, etc.">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-lg me-1"></i>Record Donation
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Withdrawal Modal ───────────────────────────────────────── -->
<div class="modal fade" id="withdrawModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="withdraw">

        <div class="modal-header">
          <h5 class="modal-title fw-bold">
            <i class="bi bi-cash-stack me-2 text-danger"></i>Record Withdrawal
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">
            Record an amount that has been disbursed or withdrawn from this campaign's funds.
            The available balance and progress bar will update to reflect the remaining funds.
          </p>
          <?php if ($withdrawn > 0): ?>
            <div class="alert alert-info small mb-3">
              <strong>Current balance:</strong> <?= formatCurrency($raised) ?> raised &minus;
              <?= formatCurrency($withdrawn) ?> withdrawn = <strong><?= formatCurrency($available) ?> available</strong>
            </div>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Withdrawal Amount (₦) <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text">₦</span>
              <input type="number" name="withdraw_amount" class="form-control"
                     min="1" step="0.01" required placeholder="0.00">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Notes (optional)</label>
            <input type="text" name="withdraw_notes" class="form-control"
                   placeholder="e.g. Paid to beneficiary on 1 May 2026, cheque no. 12345">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"
                  data-confirm="Record this withdrawal? The available balance will decrease.">
            <i class="bi bi-check-lg me-1"></i>Record Withdrawal
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function copyLink() {
  var url = document.getElementById('donate-url').value;
  navigator.clipboard.writeText(url).then(function() {
    var btn = document.getElementById('copy-btn');
    btn.innerHTML = '<i class="bi bi-check2"></i>';
    btn.classList.replace('btn-outline-primary', 'btn-success');
    setTimeout(function() {
      btn.innerHTML = '<i class="bi bi-clipboard"></i>';
      btn.classList.replace('btn-success', 'btn-outline-primary');
    }, 2000);
  });
}
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
