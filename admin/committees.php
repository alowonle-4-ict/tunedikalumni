<?php
require_once dirname(__DIR__) . '/config/app.php';
requireAdmin();
require_once ROOT_PATH . '/includes/committee_functions.php';

$pageTitle = 'Committees';
$activeNav = 'committees';

$db = getDB();

// ── POST: create / delete ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name      = trim($_POST['name']    ?? '');
        $purpose   = trim($_POST['purpose'] ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date']   ?? '') ?: null;

        if ($name && $startDate) {
            $db->prepare(
                'INSERT INTO committees (name, purpose, start_date, end_date, status, created_by)
                 VALUES (:n, :p, :s, :e, "active", :cb)'
            )->execute([
                ':n'  => $name,
                ':p'  => $purpose ?: null,
                ':s'  => $startDate,
                ':e'  => $endDate,
                ':cb' => $_SESSION['user_id'],
            ]);
            $newCommitteeId = (int)$db->lastInsertId();

            // Auto-enrol all active admin users into the new committee
            $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
            foreach ($admins as $admin) {
                $db->prepare(
                    'INSERT IGNORE INTO committee_members (committee_id, user_id, role, joined_at)
                     VALUES (:cid, :uid, "member", CURDATE())'
                )->execute([':cid' => $newCommitteeId, ':uid' => $admin['id']]);
            }

            setFlash('success', "Committee \"" . htmlspecialchars($name, ENT_QUOTES) . "\" created.");
        } else {
            setFlash('danger', 'Name and start date are required.');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['committee_id'] ?? 0);
        if ($id > 0) {
            // Only allow delete if no members
            $count = $db->prepare('SELECT COUNT(*) FROM committee_members WHERE committee_id = :id');
            $count->execute([':id' => $id]);
            if ((int)$count->fetchColumn() === 0) {
                $db->prepare('DELETE FROM committees WHERE id = :id')->execute([':id' => $id]);
                setFlash('success', 'Committee deleted.');
            } else {
                setFlash('danger', 'Cannot delete a committee that has members. Remove all members first.');
            }
        }
    }

    if ($action === 'sync_admins') {
        // Enrol all active admins into every active committee they're not already in
        $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
        $committees = $db->query("SELECT id FROM committees WHERE status = 'active'")->fetchAll();
        $enrolled = 0;
        $stmt = $db->prepare(
            'INSERT IGNORE INTO committee_members (committee_id, user_id, role, joined_at)
             VALUES (:cid, :uid, "member", CURDATE())'
        );
        foreach ($committees as $c) {
            foreach ($admins as $a) {
                $stmt->execute([':cid' => $c['id'], ':uid' => $a['id']]);
                $enrolled += $stmt->rowCount();
            }
        }
        setFlash('success', "Sync complete — {$enrolled} enrolment(s) added.");
    }

    if ($action === 'change_status') {
        $id     = (int)($_POST['committee_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id > 0 && in_array($status, ['active', 'completed', 'dissolved'], true)) {
            $db->prepare('UPDATE committees SET status = :s WHERE id = :id')
               ->execute([':s' => $status, ':id' => $id]);
            setFlash('success', 'Committee status updated.');
        }
    }

    header('Location: committees.php');
    exit;
}

// ── Filter ────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$committees   = getAllCommittees($filterStatus);

// Stats
$stats = $db->query('
    SELECT
        SUM(status = "active")    AS active_count,
        SUM(status = "completed") AS completed_count,
        SUM(status = "dissolved") AS dissolved_count,
        COUNT(*) AS total
    FROM committees
')->fetch();

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <h4 class="fw-bold mb-0"><i class="bi bi-people-fill me-2"></i>Committees</h4>
  <div class="d-flex gap-2">
    <!-- Sync all admins/NEC into every active committee -->
    <form method="POST" class="d-inline">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="sync_admins">
      <button type="submit" class="btn btn-outline-secondary btn-sm"
              onclick="return confirm('Enrol all Admin users into every active committee they are not already in?')">
        <i class="bi bi-arrow-repeat me-1"></i>Sync Admins to All
      </button>
    </form>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal">
      <i class="bi bi-plus-lg me-1"></i>New Committee
    </button>
  </div>
</div>

<?= renderFlash() ?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card success">
      <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
      <div>
        <div class="stat-label">Active</div>
        <div class="stat-value"><?= (int)$stats['active_count'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-flag-fill"></i></div>
      <div>
        <div class="stat-label">Completed</div>
        <div class="stat-value"><?= (int)$stats['completed_count'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card danger">
      <div class="stat-icon red"><i class="bi bi-x-circle-fill"></i></div>
      <div>
        <div class="stat-label">Dissolved</div>
        <div class="stat-value"><?= (int)$stats['dissolved_count'] ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Filter tabs -->
<div class="card">
  <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h6 class="fw-bold mb-0"><i class="bi bi-list-ul me-2"></i>All Committees</h6>
    <div class="d-flex gap-1 flex-wrap">
      <?php foreach ([''=>'All','active'=>'Active','completed'=>'Completed','dissolved'=>'Dissolved'] as $val=>$lbl): ?>
        <a href="committees.php<?= $val ? '?status='.$val : '' ?>"
           class="btn btn-sm <?= $filterStatus === $val ? 'btn-primary' : 'btn-outline-secondary' ?>">
          <?= $lbl ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-custom mb-0 align-middle">
        <thead>
          <tr>
            <th>Committee</th>
            <th>Chair</th>
            <th>Members</th>
            <th>Status</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Duration</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($committees)): ?>
            <tr>
              <td colspan="8" class="text-center text-muted py-5">
                <i class="bi bi-people fs-1"></i>
                <p class="small mt-2 mb-0">No committees found.</p>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($committees as $c):
              $meta = committeeStatusMeta($c['status']);
              $end  = $c['end_date'] && $c['status'] !== 'active' ? $c['end_date'] : null;
            ?>
            <tr>
              <td>
                <a href="committee_view.php?id=<?= $c['id'] ?>" class="fw-semibold small text-decoration-none">
                  <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>
                </a>
                <?php if ($c['purpose']): ?>
                  <div class="text-muted" style="font-size:.72rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= htmlspecialchars($c['purpose'], ENT_QUOTES) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="small text-muted"><?= $c['chair_name'] ? htmlspecialchars($c['chair_name'], ENT_QUOTES) : '<em>—</em>' ?></td>
              <td class="text-center fw-semibold"><?= (int)$c['member_count'] ?></td>
              <td>
                <span class="badge bg-<?= $meta['badge'] ?>">
                  <i class="bi <?= $meta['icon'] ?> me-1"></i><?= $meta['label'] ?>
                </span>
              </td>
              <td class="small text-muted"><?= date('d M Y', strtotime($c['start_date'])) ?></td>
              <td class="small text-muted"><?= $c['end_date'] ? date('d M Y', strtotime($c['end_date'])) : '<em>Ongoing</em>' ?></td>
              <td class="small text-muted"><?= committeeDuration($c['start_date'], $end) ?></td>
              <td class="text-center">
                <a href="committee_view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-eye me-1"></i>Manage
                </a>
                <div class="btn-group ms-1">
                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"></button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <?php foreach (['active'=>'Set Active','completed'=>'Mark Completed','dissolved'=>'Dissolve'] as $sv=>$sl):
                      if ($sv === $c['status']) continue; ?>
                      <li>
                        <form method="post" class="d-inline">
                          <?= csrfField() ?>
                          <input type="hidden" name="action"       value="change_status">
                          <input type="hidden" name="committee_id" value="<?= $c['id'] ?>">
                          <input type="hidden" name="status"       value="<?= $sv ?>">
                          <button type="submit" class="dropdown-item small"><?= $sl ?></button>
                        </form>
                      </li>
                    <?php endforeach; ?>
                    <?php if ((int)$c['member_count'] === 0): ?>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <form method="post" onsubmit="return confirm('Delete this committee?')">
                          <?= csrfField() ?>
                          <input type="hidden" name="action"       value="delete">
                          <input type="hidden" name="committee_id" value="<?= $c['id'] ?>">
                          <button type="submit" class="dropdown-item text-danger small">
                            <i class="bi bi-trash me-1"></i>Delete
                          </button>
                        </form>
                      </li>
                    <?php endif; ?>
                  </ul>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Create Modal ───────────────────────────────────────────── -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>New Committee</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Committee Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required
                   placeholder="e.g. Finance & Audit Committee">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Purpose / Mandate</label>
            <textarea name="purpose" class="form-control" rows="3"
                      placeholder="Describe the committee's purpose and responsibilities..."></textarea>
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
              <input type="date" name="start_date" class="form-control" required
                     value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">End Date</label>
              <input type="date" name="end_date" class="form-control">
              <div class="form-text">Leave blank for ongoing committees.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i>Create Committee
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Add loading state to modal form
(function () {
  const form = document.querySelector('#createModal form');
  if (form) {
    form.addEventListener('submit', function (e) {
      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
        const originalHTML = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Creating...';
        
        setTimeout(() => {
          if (submitBtn && document.activeElement !== document.body) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHTML;
          }
        }, 5000);
      }
    });
  }
})();

// Add loading state to sync and delete forms
(function () {
  const forms = document.querySelectorAll('form[method="POST"]');
  forms.forEach(form => {
    if (form !== document.querySelector('#createModal form')) {
      form.addEventListener('submit', function (e) {
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn && !submitBtn.disabled) {
          submitBtn.disabled = true;
          const originalHTML = submitBtn.innerHTML;
          submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';
          
          setTimeout(() => {
            if (submitBtn && document.activeElement !== document.body) {
              submitBtn.disabled = false;
              submitBtn.innerHTML = originalHTML;
            }
          }, 5000);
        }
      });
    }
  });
})();
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
