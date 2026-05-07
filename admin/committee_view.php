<?php
require_once dirname(__DIR__) . '/config/app.php';
requireAdmin();
require_once ROOT_PATH . '/includes/committee_functions.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$committee = getCommitteeById($id);
if (!$committee) {
    setFlash('danger', 'Committee not found.');
    header('Location: committees.php');
    exit;
}

$pageTitle = htmlspecialchars($committee['name'], ENT_QUOTES) . ' — Committee';
$activeNav = 'committees';

// ── POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Edit committee details
    if ($action === 'edit') {
        $name      = trim($_POST['name']       ?? '');
        $purpose   = trim($_POST['purpose']    ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date']   ?? '') ?: null;

        if ($name && $startDate) {
            $db->prepare(
                'UPDATE committees SET name=:n, purpose=:p, start_date=:s, end_date=:e WHERE id=:id'
            )->execute([
                ':n' => $name, ':p' => $purpose ?: null,
                ':s' => $startDate, ':e' => $endDate, ':id' => $id,
            ]);
            setFlash('success', 'Committee details updated.');
        } else {
            setFlash('danger', 'Name and start date are required.');
        }
    }

    // Change status
    if ($action === 'change_status') {
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['active', 'completed', 'dissolved'], true)) {
            $db->prepare('UPDATE committees SET status=:s WHERE id=:id')
               ->execute([':s' => $status, ':id' => $id]);
            setFlash('success', 'Status updated to ' . ucfirst($status) . '.');
        }
    }

    // Add member
    if ($action === 'add_member') {
        $userId   = (int)($_POST['user_id']  ?? 0);
        $role     = $_POST['member_role'] === 'chair' ? 'chair' : 'member';
        $joinedAt = trim($_POST['joined_at'] ?? '') ?: date('Y-m-d');

        if ($userId > 0) {
            // Only one chair allowed — demote existing chair if assigning new one
            if ($role === 'chair') {
                $db->prepare(
                    'UPDATE committee_members SET role="member" WHERE committee_id=:cid AND role="chair"'
                )->execute([':cid' => $id]);
            }
            try {
                $db->prepare(
                    'INSERT INTO committee_members (committee_id, user_id, role, joined_at)
                     VALUES (:cid, :uid, :r, :ja)
                     ON DUPLICATE KEY UPDATE role=:r2, joined_at=:ja2'
                )->execute([
                    ':cid' => $id, ':uid' => $userId,
                    ':r'   => $role, ':ja'  => $joinedAt,
                    ':r2'  => $role, ':ja2' => $joinedAt,
                ]);
                setFlash('success', 'Member assigned to committee.');
            } catch (PDOException $e) {
                setFlash('danger', 'Could not add member: ' . $e->getMessage());
            }
        }
    }

    // Set chair (promote existing member)
    if ($action === 'set_chair') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId > 0) {
            // Demote current chair
            $db->prepare(
                'UPDATE committee_members SET role="member" WHERE committee_id=:cid AND role="chair"'
            )->execute([':cid' => $id]);
            // Promote selected member
            $db->prepare(
                'UPDATE committee_members SET role="chair" WHERE id=:mid AND committee_id=:cid'
            )->execute([':mid' => $memberId, ':cid' => $id]);
            setFlash('success', 'Chair updated.');
        }
    }

    // Remove member
    if ($action === 'remove_member') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId > 0) {
            $db->prepare('DELETE FROM committee_members WHERE id=:id AND committee_id=:cid')
               ->execute([':id' => $memberId, ':cid' => $id]);
            setFlash('success', 'Member removed from committee.');
        }
    }

    // Delete report (admin only)
    if ($action === 'delete_report') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        if ($reportId > 0) {
            $filename = deleteCommitteeReport($reportId, $id);
            if ($filename) {
                @unlink(ROOT_PATH . '/assets/uploads/committee_reports/' . $filename);
                setFlash('success', 'Report deleted.');
            }
        }
    }

    header('Location: committee_view.php?id=' . $id);
    exit;
}

require_once __DIR__ . '/includes/admin_header.php';

$members       = getCommitteeMembers($id);
$activeMembers = getActiveMembers();
$meta          = committeeStatusMeta($committee['status']);
$reports       = getCommitteeReports($id);

// Members already in this committee (to exclude from add dropdown)
$existingUserIds = array_column($members, 'user_id');

// Duration
$durEnd  = ($committee['status'] !== 'active' && $committee['end_date']) ? $committee['end_date'] : null;
$duration = committeeDuration($committee['start_date'], $durEnd);
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <div>
    <a href="committees.php" class="text-decoration-none text-muted small">
      <i class="bi bi-arrow-left me-1"></i>All Committees
    </a>
    <h4 class="fw-bold mb-0 mt-1">
      <?= htmlspecialchars($committee['name'], ENT_QUOTES) ?>
      <span class="badge bg-<?= $meta['badge'] ?> ms-2 fs-6">
        <i class="bi <?= $meta['icon'] ?> me-1"></i><?= $meta['label'] ?>
      </span>
    </h4>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal">
      <i class="bi bi-pencil me-1"></i>Edit
    </button>
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
        Change Status
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <?php foreach (['active'=>'Set Active','completed'=>'Mark Completed','dissolved'=>'Dissolve'] as $sv=>$sl):
          if ($sv === $committee['status']) continue; ?>
          <li>
            <form method="post">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="change_status">
              <input type="hidden" name="status" value="<?= $sv ?>">
              <button type="submit" class="dropdown-item small"><?= $sl ?></button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<?= renderFlash() ?>

<div class="row g-4">

  <!-- ── Left: Details + Members ────────────────────────────── -->
  <div class="col-lg-8">

    <!-- Details card -->
    <div class="card mb-4">
      <div class="card-header bg-white py-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-info-circle me-2"></i>Details</h6>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-sm-6">
            <div class="text-muted small text-uppercase fw-semibold mb-1">Purpose</div>
            <div class="small"><?= $committee['purpose'] ? htmlspecialchars($committee['purpose'], ENT_QUOTES) : '<em class="text-muted">Not specified</em>' ?></div>
          </div>
          <div class="col-sm-2">
            <div class="text-muted small text-uppercase fw-semibold mb-1">Start</div>
            <div class="small fw-semibold"><?= date('d M Y', strtotime($committee['start_date'])) ?></div>
          </div>
          <div class="col-sm-2">
            <div class="text-muted small text-uppercase fw-semibold mb-1">End</div>
            <div class="small fw-semibold"><?= $committee['end_date'] ? date('d M Y', strtotime($committee['end_date'])) : '<em class="text-muted">Ongoing</em>' ?></div>
          </div>
          <div class="col-sm-2">
            <div class="text-muted small text-uppercase fw-semibold mb-1">Duration</div>
            <div class="small fw-semibold"><?= $duration ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Members table -->
    <div class="card">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">
          <i class="bi bi-people-fill me-2"></i>Members
          <span class="badge bg-primary ms-1"><?= count($members) ?></span>
        </h6>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMemberModal">
          <i class="bi bi-person-plus me-1"></i>Add Member
        </button>
      </div>

      <?php if (empty($members)): ?>
        <div class="card-body text-center text-muted py-4">
          <i class="bi bi-person-x fs-2"></i>
          <p class="small mt-2 mb-0">No members assigned yet.</p>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-custom mb-0 align-middle">
          <thead>
            <tr>
              <th>Member</th>
              <th>Role</th>
              <th>Department</th>
              <th>Joined</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($members as $m): ?>
            <tr>
              <td>
                <div class="fw-semibold small">
                  <?php if ($m['committee_role'] === 'chair'): ?>
                    <i class="bi bi-person-badge-fill text-warning me-1"></i>
                  <?php endif; ?>
                  <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name'], ENT_QUOTES) ?>
                </div>
                <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($m['email'], ENT_QUOTES) ?></div>
              </td>
              <td>
                <span class="badge bg-<?= $m['committee_role'] === 'chair' ? 'warning text-dark' : 'secondary' ?>">
                  <?= $m['committee_role'] === 'chair' ? 'Chair' : 'Member' ?>
                </span>
              </td>
              <td class="small text-muted"><?= $m['department'] ? htmlspecialchars($m['department'], ENT_QUOTES) : '—' ?></td>
              <td class="small text-muted"><?= date('d M Y', strtotime($m['joined_at'])) ?></td>
              <td class="text-center">
                <?php if ($m['committee_role'] !== 'chair'): ?>
                  <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"    value="set_chair">
                    <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-warning"
                            title="Promote to Chair">
                      <i class="bi bi-person-badge-fill"></i>
                    </button>
                  </form>
                <?php endif; ?>
                <form method="post" class="d-inline ms-1"
                      onsubmit="return confirm('Remove this member from the committee?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"    value="remove_member">
                  <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                    <i class="bi bi-person-dash"></i>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Reports card -->
    <div class="card mt-4">
      <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h6 class="fw-bold mb-0">
          <i class="bi bi-file-earmark-pdf-fill text-danger me-2"></i>Committee Reports
          <span class="badge bg-danger ms-1"><?= count($reports) ?></span>
        </h6>
      </div>

      <?php if (empty($reports)): ?>
        <div class="card-body text-center text-muted py-4">
          <i class="bi bi-file-earmark-x fs-2"></i>
          <p class="small mt-2 mb-0">No reports uploaded yet.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-custom mb-0 align-middle">
            <thead>
              <tr>
                <th>Title</th>
                <th>Uploaded By</th>
                <th>Date</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $r): ?>
              <tr>
                <td>
                  <div class="fw-semibold small">
                    <i class="bi bi-file-earmark-pdf text-danger me-1"></i>
                    <?= htmlspecialchars($r['title'], ENT_QUOTES) ?>
                  </div>
                  <div class="text-muted" style="font-size:.72rem">
                    <?= htmlspecialchars($r['original_name'], ENT_QUOTES) ?>
                  </div>
                </td>
                <td class="small"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name'], ENT_QUOTES) ?></td>
                <td class="small text-muted"><?= date('d M Y', strtotime($r['uploaded_at'])) ?></td>
                <td class="text-center">
                  <a href="<?= BASE_URL ?>/assets/uploads/committee_reports/<?= urlencode($r['filename']) ?>"
                     target="_blank"
                     class="btn btn-sm btn-outline-danger me-1"
                     download="<?= htmlspecialchars($r['title'], ENT_QUOTES) ?>.pdf"
                     title="Download">
                    <i class="bi bi-download"></i>
                  </a>
                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete this report? This cannot be undone.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"    value="delete_report">
                    <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div><!-- /col-lg-8 -->

  <!-- ── Right: Quick stats + creator info ──────────────────── -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-body">
        <div class="mb-3 pb-3 border-bottom">
          <div class="text-muted small text-uppercase fw-semibold mb-1">Created by</div>
          <div class="small fw-semibold"><?= htmlspecialchars($committee['creator_first'] . ' ' . $committee['creator_last'], ENT_QUOTES) ?></div>
          <div class="text-muted" style="font-size:.72rem"><?= date('d M Y', strtotime($committee['created_at'])) ?></div>
        </div>
        <div class="mb-3 pb-3 border-bottom">
          <div class="text-muted small text-uppercase fw-semibold mb-1">Total Members</div>
          <div class="fs-4 fw-bold text-primary"><?= count($members) ?></div>
        </div>
        <div class="mb-3 pb-3 border-bottom">
          <div class="text-muted small text-uppercase fw-semibold mb-1">Duration Served</div>
          <div class="fw-semibold"><?= $duration ?></div>
          <div class="text-muted" style="font-size:.72rem">
            <?= $committee['status'] === 'active' ? 'and counting...' : 'total' ?>
          </div>
        </div>
        <?php
          $chairRow = null;
          foreach ($members as $m) { if ($m['committee_role'] === 'chair') { $chairRow = $m; break; } }
        ?>
        <div>
          <div class="text-muted small text-uppercase fw-semibold mb-1">Chair</div>
          <?php if ($chairRow): ?>
            <div class="fw-semibold small">
              <i class="bi bi-person-badge-fill text-warning me-1"></i>
              <?= htmlspecialchars($chairRow['first_name'] . ' ' . $chairRow['last_name'], ENT_QUOTES) ?>
            </div>
          <?php else: ?>
            <em class="text-muted small">No chair assigned</em>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-white py-2">
        <h6 class="fw-bold mb-0 small">Member Breakdown</h6>
      </div>
      <div class="card-body py-2">
        <?php
          $chairCount  = count(array_filter($members, fn($m) => $m['committee_role'] === 'chair'));
          $memberCount = count($members) - $chairCount;
        ?>
        <div class="d-flex justify-content-between small py-1 border-bottom">
          <span class="text-muted">Chair</span>
          <span class="fw-semibold"><?= $chairCount ?></span>
        </div>
        <div class="d-flex justify-content-between small py-1">
          <span class="text-muted">Members</span>
          <span class="fw-semibold"><?= $memberCount ?></span>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ── Edit Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="edit">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Committee</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required
                   value="<?= htmlspecialchars($committee['name'], ENT_QUOTES) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Purpose</label>
            <textarea name="purpose" class="form-control" rows="3"><?= htmlspecialchars($committee['purpose'] ?? '', ENT_QUOTES) ?></textarea>
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
              <input type="date" name="start_date" class="form-control" required
                     value="<?= $committee['start_date'] ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">End Date</label>
              <input type="date" name="end_date" class="form-control"
                     value="<?= $committee['end_date'] ?? '' ?>">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Add Member Modal ───────────────────────────────────────── -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_member">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2"></i>Add Member</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Select Member <span class="text-danger">*</span></label>
            <select name="user_id" class="form-select" required>
              <option value="">— Choose an active member —</option>
              <?php foreach ($activeMembers as $am):
                if (in_array($am['id'], $existingUserIds, true)) continue; ?>
                <option value="<?= $am['id'] ?>">
                  <?= htmlspecialchars($am['first_name'] . ' ' . $am['last_name'], ENT_QUOTES) ?>
                  <?= $am['membership_id'] ? '(' . htmlspecialchars($am['membership_id'], ENT_QUOTES) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Only active members are listed.</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Role</label>
            <select name="member_role" class="form-select">
              <option value="member">Member</option>
              <option value="chair">Chair (will replace current chair)</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Date Joined Committee</label>
            <input type="date" name="joined_at" class="form-control"
                   value="<?= $committee['start_date'] ?>">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i>Add to Committee
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
