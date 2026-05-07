<?php
require_once dirname(__DIR__) . '/config/app.php';

$pageTitle = 'Donation Campaigns';
$activeNav = 'donations';

$db = getDB();

// ── Helpers ──────────────────────────────────────────────────
function generateSlug(): string
{
    return strtolower(bin2hex(random_bytes(6))); // 12-char hex
}

function campaignStats(PDO $db, int $campaignId): array
{
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(amount),0) AS raised, COUNT(*) AS donors
         FROM donation_payments
         WHERE campaign_id = :cid AND status = "success"'
    );
    $stmt->execute([':cid' => $campaignId]);
    return $stmt->fetch();
}

// ── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Create campaign ─────────────────────────────────────
    if ($action === 'create') {
        $title          = trim($_POST['title'] ?? '');
        $description    = trim($_POST['description'] ?? '');
        $target         = max(0, (float)($_POST['target_amount'] ?? 0));
        $benefType      = $_POST['beneficiary_type'] ?? 'project';
        $benefUserId    = ($benefType === 'member') ? (int)($_POST['beneficiary_user_id'] ?? 0) : null;
        $benefName      = ($benefType === 'project') ? trim($_POST['beneficiary_name'] ?? '') : null;
        $deadline       = trim($_POST['deadline'] ?? '') ?: null;
        $showDonors     = isset($_POST['show_donors']) ? 1 : 0;
        $status         = in_array($_POST['status'] ?? '', ['active','draft'], true) ? $_POST['status'] : 'active';
        $createdBy      = (int)currentUserId();

        if ($title === '') {
            setFlash('error', 'Campaign title is required.');
        } else {
            // Ensure unique slug
            do {
                $slug = generateSlug();
                $exists = $db->prepare('SELECT id FROM donation_campaigns WHERE slug = :s');
                $exists->execute([':s' => $slug]);
            } while ($exists->fetch());

            $db->prepare(
                'INSERT INTO donation_campaigns
                 (title, description, target_amount, beneficiary_user_id, beneficiary_name,
                  slug, deadline, show_donors, status, created_by)
                 VALUES (:t, :d, :amt, :buid, :bn, :slug, :dl, :sd, :st, :cb)'
            )->execute([
                ':t'    => $title,
                ':d'    => $description ?: null,
                ':amt'  => $target,
                ':buid' => $benefUserId,
                ':bn'   => $benefName,
                ':slug' => $slug,
                ':dl'   => $deadline,
                ':sd'   => $showDonors,
                ':st'   => $status,
                ':cb'   => $createdBy,
            ]);

            setFlash('success',
                '<i class="bi bi-check-circle-fill me-2"></i>'
                . 'Campaign "<strong>' . htmlspecialchars($title, ENT_QUOTES) . '</strong>" created successfully.'
            );
        }
        redirect(BASE_URL . '/admin/donations.php');
    }

    // ── Toggle status ────────────────────────────────────────
    if ($action === 'toggle_status') {
        $id = (int)($_POST['campaign_id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare('SELECT status FROM donation_campaigns WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if ($row) {
                $newStatus = $row['status'] === 'active' ? 'closed' : 'active';
                $db->prepare('UPDATE donation_campaigns SET status = :s WHERE id = :id')
                   ->execute([':s' => $newStatus, ':id' => $id]);
                setFlash('success', 'Campaign status updated to <strong>' . ucfirst($newStatus) . '</strong>.');
            }
        }
        redirect(BASE_URL . '/admin/donations.php');
    }

    // ── Delete campaign ──────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['campaign_id'] ?? 0);
        if ($id) {
            $stats = campaignStats($db, $id);
            if ((int)$stats['donors'] > 0) {
                setFlash('error',
                    'Cannot delete a campaign that has received donations. Close it instead.'
                );
            } else {
                $stmt = $db->prepare('SELECT title FROM donation_campaigns WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                $db->prepare('DELETE FROM donation_campaigns WHERE id = :id')->execute([':id' => $id]);
                setFlash('success', 'Campaign "<strong>' . htmlspecialchars($row['title'] ?? '', ENT_QUOTES) . '</strong>" deleted.');
            }
        }
        redirect(BASE_URL . '/admin/donations.php');
    }
}

// ── Fetch all campaigns ───────────────────────────────────────
$campaigns = $db->query(
    'SELECT dc.*,
            u.first_name AS creator_first, u.last_name AS creator_last,
            bu.first_name AS benef_first, bu.last_name AS benef_last,
            COALESCE(ds.raised, 0) AS raised,
            COALESCE(ds.donors, 0) AS donors,
            COALESCE(dw.withdrawn, 0) AS withdrawn
     FROM donation_campaigns dc
     JOIN users u ON u.id = dc.created_by
     LEFT JOIN users bu ON bu.id = dc.beneficiary_user_id
     LEFT JOIN (
         SELECT campaign_id, SUM(amount) AS raised, COUNT(*) AS donors
         FROM donation_payments WHERE status = "success"
         GROUP BY campaign_id
     ) ds ON ds.campaign_id = dc.id
     LEFT JOIN (
         SELECT campaign_id, SUM(amount) AS withdrawn
         FROM donation_withdrawals GROUP BY campaign_id
     ) dw ON dw.campaign_id = dc.id
     ORDER BY dc.created_at DESC'
)->fetchAll();

// Members list for the create form beneficiary dropdown
$members = $db->query(
    'SELECT u.id, u.first_name, u.last_name, u.email, m.membership_id
     FROM users u
     LEFT JOIN memberships m ON m.user_id = u.id
     WHERE u.role = "member" AND u.is_active = 1
     ORDER BY u.first_name, u.last_name'
)->fetchAll();

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <h4 class="fw-bold mb-0">
    <i class="bi bi-heart-fill me-2 text-danger"></i>Donation Campaigns
  </h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-plus-lg me-1"></i>New Campaign
  </button>
</div>

<?= renderFlash() ?>

<?php if ($campaigns): ?>
  <div class="row g-4">
    <?php foreach ($campaigns as $c):
      $target    = (float)$c['target_amount'];
      $raised    = (float)$c['raised'];
      $withdrawn = (float)$c['withdrawn'];
      $available = max(0, $raised - $withdrawn);
      $pct       = ($target > 0) ? min(100, round($available / $target * 100)) : null;
      $barCls    = $pct === null ? 'bg-primary' : ($pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-info' : ($pct >= 25 ? 'bg-warning' : 'bg-danger')));
      $isActive = $c['status'] === 'active';
      $isDraft  = $c['status'] === 'draft';

      // Beneficiary display
      if ($c['beneficiary_user_id'] && $c['benef_first']) {
          $benefLabel = htmlspecialchars($c['benef_first'] . ' ' . $c['benef_last'], ENT_QUOTES);
          $benefBadge = 'bg-primary';
          $benefIcon  = 'bi-person-fill';
      } elseif ($c['beneficiary_name']) {
          $benefLabel = htmlspecialchars($c['beneficiary_name'], ENT_QUOTES);
          $benefBadge = 'bg-secondary';
          $benefIcon  = 'bi-folder-fill';
      } else {
          $benefLabel = 'General';
          $benefBadge = 'bg-secondary';
          $benefIcon  = 'bi-collection-fill';
      }

      $donateUrl = BASE_URL . '/pages/donate.php?ref=' . $c['slug'];
    ?>
    <div class="col-md-6 col-xl-4">
      <div class="card h-100 <?= $isDraft ? 'border-secondary' : ($isActive ? '' : 'border-danger opacity-75') ?>">
        <div class="card-body d-flex flex-column">

          <!-- Status + beneficiary -->
          <div class="d-flex justify-content-between align-items-start mb-2">
            <span class="badge <?= $benefBadge ?>">
              <i class="bi <?= $benefIcon ?> me-1"></i><?= $benefLabel ?>
            </span>
            <span class="badge <?= $isActive ? 'bg-success' : ($isDraft ? 'bg-secondary' : 'bg-danger') ?>">
              <?= ucfirst($c['status']) ?>
            </span>
          </div>

          <h6 class="fw-bold mb-1"><?= htmlspecialchars($c['title'], ENT_QUOTES) ?></h6>

          <?php if ($c['description']): ?>
            <p class="text-muted small mb-2" style="display:-webkit-box;-webkit-line-clamp:2;line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
              <?= htmlspecialchars($c['description'], ENT_QUOTES) ?>
            </p>
          <?php endif; ?>

          <!-- Progress -->
          <div class="mt-auto">
            <?php if ($target > 0): ?>
              <div class="d-flex justify-content-between small mb-1">
                <span class="fw-semibold"><?= formatCurrency($available) ?> available</span>
                <span class="text-muted">of <?= formatCurrency($target) ?></span>
              </div>
              <div class="progress mb-1" style="height:10px">
                <div class="progress-bar <?= $barCls ?>" style="width:<?= $pct ?>%"></div>
              </div>
              <div class="d-flex justify-content-between" style="font-size:.75rem">
                <span class="text-muted"><?= $pct ?>% funded &middot; <?= number_format((int)$c['donors']) ?> donor<?= (int)$c['donors'] !== 1 ? 's' : '' ?></span>
              </div>
              <?php if ($withdrawn > 0): ?>
                <div class="mt-1 text-muted" style="font-size:.72rem">
                  <i class="bi bi-arrow-up-circle me-1 text-danger"></i><?= formatCurrency($withdrawn) ?> disbursed from <?= formatCurrency($raised) ?> raised
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="d-flex justify-content-between small mb-1">
                <span class="fw-semibold"><?= formatCurrency($available) ?> <?= $withdrawn > 0 ? 'available' : 'raised' ?></span>
                <span class="text-muted"><?= number_format((int)$c['donors']) ?> donor<?= (int)$c['donors'] !== 1 ? 's' : '' ?></span>
              </div>
              <div class="progress mb-1" style="height:10px">
                <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated" style="width:100%"></div>
              </div>
              <div style="font-size:.75rem" class="text-muted">No target set — open fundraising</div>
              <?php if ($withdrawn > 0): ?>
                <div class="mt-1 text-muted" style="font-size:.72rem">
                  <i class="bi bi-arrow-up-circle me-1 text-danger"></i><?= formatCurrency($withdrawn) ?> disbursed from <?= formatCurrency($raised) ?> raised
                </div>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($c['deadline']): ?>
              <?php
                $daysLeft = (new DateTime('today'))->diff(new DateTime($c['deadline']));
                $expired  = $daysLeft->invert;
                $daysNum  = (int)$daysLeft->days;
              ?>
              <div class="mt-1" style="font-size:.75rem">
                <?php if ($expired): ?>
                  <span class="text-danger"><i class="bi bi-clock me-1"></i>Deadline passed <?= $daysNum ?> day<?= $daysNum !== 1 ? 's' : '' ?> ago</span>
                <?php else: ?>
                  <span class="text-muted"><i class="bi bi-clock me-1"></i><?= $daysNum ?> day<?= $daysNum !== 1 ? 's' : '' ?> remaining</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

        </div>

        <!-- Card footer: actions -->
        <div class="card-footer bg-transparent d-flex gap-1 flex-wrap">
          <a href="<?= BASE_URL ?>/admin/donation_view.php?id=<?= $c['id'] ?>"
             class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye me-1"></i>View
          </a>
          <button class="btn btn-sm btn-outline-secondary copy-link-btn"
                  data-url="<?= htmlspecialchars($donateUrl, ENT_QUOTES) ?>">
            <i class="bi bi-link-45deg me-1"></i>Copy Link
          </button>
          <form method="POST" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action"      value="toggle_status">
            <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                    data-confirm="<?= $isActive ? 'Close this campaign? Donations will stop.' : 'Reopen this campaign?' ?>">
              <i class="bi <?= $isActive ? 'bi-pause-circle' : 'bi-play-circle' ?> me-1"></i>
              <?= $isActive ? 'Close' : 'Reopen' ?>
            </button>
          </form>
          <form method="POST" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action"      value="delete">
            <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"
                    data-confirm="Delete this campaign permanently? This cannot be undone.">
              <i class="bi bi-trash"></i>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

<?php else: ?>
  <div class="card">
    <div class="card-body text-center py-5">
      <i class="bi bi-heart fs-1 text-muted"></i>
      <h5 class="fw-bold mt-3">No campaigns yet</h5>
      <p class="text-muted">Create your first donation campaign to start raising funds.</p>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-plus-lg me-1"></i>Create Campaign
      </button>
    </div>
  </div>
<?php endif; ?>


<!-- ── Create Campaign Modal ────────────────────────────────── -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">

        <div class="modal-header">
          <h5 class="modal-title fw-bold">
            <i class="bi bi-heart-fill text-danger me-2"></i>New Donation Campaign
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">

            <div class="col-12">
              <label class="form-label fw-semibold">Campaign Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control"
                     placeholder="e.g. Support for John's Medical Bills, Annual Development Fund" required>
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" class="form-control" rows="3"
                        placeholder="Explain the purpose of this campaign and how funds will be used..."></textarea>
            </div>

            <!-- Beneficiary -->
            <div class="col-12">
              <label class="form-label fw-semibold">Fundraising For</label>
              <div class="d-flex gap-3 mb-2">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="beneficiary_type"
                         id="bt-member" value="member" checked>
                  <label class="form-check-label" for="bt-member">
                    <i class="bi bi-person-fill me-1"></i>A Member
                  </label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="beneficiary_type"
                         id="bt-project" value="project">
                  <label class="form-check-label" for="bt-project">
                    <i class="bi bi-folder-fill me-1"></i>A Project / Cause
                  </label>
                </div>
              </div>
              <div id="benef-member-wrap">
                <select name="beneficiary_user_id" class="form-select">
                  <option value="">— Select a member —</option>
                  <?php foreach ($members as $m): ?>
                    <option value="<?= $m['id'] ?>">
                      <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name'], ENT_QUOTES) ?>
                      <?= $m['membership_id'] ? '(' . $m['membership_id'] . ')' : '— ' . $m['email'] ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div id="benef-project-wrap" style="display:none">
                <input type="text" name="beneficiary_name" class="form-control"
                       placeholder="e.g. Alumni Hall Renovation, Scholarship Fund">
              </div>
            </div>

            <div class="col-sm-6">
              <label class="form-label fw-semibold">Target Amount (₦)</label>
              <div class="input-group">
                <span class="input-group-text">₦</span>
                <input type="number" name="target_amount" class="form-control"
                       min="0" step="0.01" placeholder="0 = no target / unlimited" value="">
              </div>
              <div class="form-text">Leave blank or 0 for open-ended fundraising.</div>
            </div>

            <div class="col-sm-6">
              <label class="form-label fw-semibold">Deadline (optional)</label>
              <input type="date" name="deadline" class="form-control"
                     min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
              <div class="form-text">Leave blank for no deadline.</div>
            </div>

            <div class="col-sm-6">
              <label class="form-label fw-semibold">Launch Status</label>
              <select name="status" class="form-select">
                <option value="active">Active — publish immediately</option>
                <option value="draft">Draft — save but don't publish</option>
              </select>
            </div>

            <div class="col-sm-6 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="show_donors" id="show-donors" checked>
                <label class="form-check-label" for="show-donors">
                  Show donor names publicly on the donation page
                </label>
              </div>
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i>Create Campaign
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Beneficiary type toggle
document.querySelectorAll('input[name="beneficiary_type"]').forEach(function(r) {
  r.addEventListener('change', function() {
    document.getElementById('benef-member-wrap').style.display  = this.value === 'member'  ? '' : 'none';
    document.getElementById('benef-project-wrap').style.display = this.value === 'project' ? '' : 'none';
  });
});

// Copy link button
document.querySelectorAll('.copy-link-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    navigator.clipboard.writeText(this.dataset.url).then(function() {
      btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied!';
      setTimeout(function() {
        btn.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Copy Link';
      }, 2000);
    });
  }.bind(btn));
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
