<?php
require_once dirname(__DIR__) . '/config/app.php';
$pageTitle = 'Announcements';
$activeNav = 'announcements';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title    = trim($_POST['title']    ?? '');
        $body     = trim($_POST['body']     ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['normal','important','urgent'], true) ? $_POST['priority'] : 'normal';
        $pubAt    = trim($_POST['published_at'] ?? '') ?: null;
        $expAt    = trim($_POST['expires_at']   ?? '') ?: null;

        if ($title && $body) {
            $db->prepare(
                'INSERT INTO announcements (title, body, priority, published_at, expires_at, created_by)
                 VALUES (:t, :b, :p, :pub, :exp, :by)'
            )->execute([
                ':t' => $title, ':b' => $body, ':p' => $priority,
                ':pub' => $pubAt, ':exp' => $expAt,
                ':by' => (int)currentUserId(),
            ]);
            auditLog('announcement_created', "Created announcement: {$title}");
            setFlash('success', 'Announcement published.');
        } else {
            setFlash('danger', 'Title and body are required.');
        }
    }

    if ($action === 'delete') {
        $aid = (int)($_POST['ann_id'] ?? 0);
        if ($aid) {
            $db->prepare('DELETE FROM announcements WHERE id = :id')->execute([':id' => $aid]);
            auditLog('announcement_deleted', "Deleted announcement #{$aid}");
            setFlash('success', 'Announcement deleted.');
        }
    }

    redirect(BASE_URL . '/admin/announcements.php');
}

$announcements = $db->query(
    'SELECT a.*, u.first_name, u.last_name
     FROM announcements a JOIN users u ON u.id = a.created_by
     ORDER BY a.created_at DESC'
)->fetchAll();

$priorityBadge = ['normal' => 'secondary', 'important' => 'warning', 'urgent' => 'danger'];

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <h4 class="fw-bold mb-0"><i class="bi bi-megaphone-fill me-2"></i>Announcements</h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-plus-lg me-1"></i>New Announcement
  </button>
</div>

<?= renderFlash() ?>

<div class="card">
  <?php if (empty($announcements)): ?>
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-megaphone fs-1"></i>
      <p class="mt-2">No announcements yet.</p>
    </div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($announcements as $a): ?>
        <div class="list-group-item py-3">
          <div class="d-flex justify-content-between align-items-start gap-3">
            <div class="flex-grow-1">
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-<?= $priorityBadge[$a['priority']] ?>">
                  <?= ucfirst($a['priority']) ?>
                </span>
                <h6 class="fw-bold mb-0"><?= htmlspecialchars($a['title'], ENT_QUOTES) ?></h6>
              </div>
              <p class="text-muted small mb-1" style="white-space:pre-wrap"><?= htmlspecialchars($a['body'], ENT_QUOTES) ?></p>
              <div class="text-muted" style="font-size:.72rem">
                By <?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name'], ENT_QUOTES) ?>
                &middot; <?= date('d M Y H:i', strtotime($a['created_at'])) ?>
                <?php if ($a['expires_at']): ?>
                  &middot; Expires <?= date('d M Y', strtotime($a['expires_at'])) ?>
                <?php endif; ?>
              </div>
            </div>
            <form method="post" onsubmit="return confirm('Delete this announcement?')" class="flex-shrink-0">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-megaphone me-2"></i>New Announcement</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control" required placeholder="Announcement title">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
            <textarea name="body" class="form-control" rows="5" required placeholder="Write your announcement here..."></textarea>
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Priority</label>
              <select name="priority" class="form-select">
                <option value="normal">Normal</option>
                <option value="important">Important</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Publish Date</label>
              <input type="datetime-local" name="published_at" class="form-control">
              <div class="form-text">Leave blank to publish immediately.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Expiry Date</label>
              <input type="datetime-local" name="expires_at" class="form-control">
              <div class="form-text">Leave blank for no expiry.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-megaphone me-1"></i>Publish</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Add loading state to modal form submission
(function () {
  const form = document.querySelector('#createModal form');
  if (form) {
    form.addEventListener('submit', function (e) {
      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
        const originalHTML = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Publishing...';
        
        // Restore button if form validation fails
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

// Add loading state to delete forms
(function () {
  const deleteForms = document.querySelectorAll('form[onsubmit*="confirm"]');
  deleteForms.forEach(form => {
    form.addEventListener('submit', function (e) {
      if (!confirm('Delete this announcement?')) {
        e.preventDefault();
        return;
      }
      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
      }
    });
  });
})();
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
