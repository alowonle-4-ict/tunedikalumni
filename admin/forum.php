<?php
require_once dirname(__DIR__) . '/config/app.php';
requireAdmin();
require_once ROOT_PATH . '/includes/forum_functions.php';

$pageTitle = 'Forum Management';
$activeNav = 'forum';

$db = getDB();

// ── POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Create category
    if ($action === 'create_category') {
        $name  = trim($_POST['name']  ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $icon  = trim($_POST['icon']  ?? 'bi-chat-dots-fill');
        $order = (int)($_POST['display_order'] ?? 0);
        $slug  = forumSlug($name);

        if ($name) {
            // Ensure unique slug
            $existing = $db->prepare('SELECT COUNT(*) FROM forum_categories WHERE slug=:s');
            $existing->execute([':s' => $slug]);
            if ((int)$existing->fetchColumn() > 0) {
                $slug .= '-' . time();
            }
            $db->prepare(
                'INSERT INTO forum_categories (name, description, slug, icon, display_order, created_by)
                 VALUES (:n, :d, :s, :i, :o, :cb)'
            )->execute([
                ':n' => $name, ':d' => $desc ?: null, ':s' => $slug,
                ':i' => $icon, ':o' => $order, ':cb' => $_SESSION['user_id'],
            ]);
            setFlash('success', 'Category created.');
        } else {
            setFlash('danger', 'Category name is required.');
        }
    }

    // Edit category
    if ($action === 'edit_category') {
        $id    = (int)($_POST['category_id']   ?? 0);
        $name  = trim($_POST['name']           ?? '');
        $desc  = trim($_POST['description']    ?? '');
        $icon  = trim($_POST['icon']           ?? 'bi-chat-dots-fill');
        $order = (int)($_POST['display_order'] ?? 0);
        if ($id && $name) {
            $db->prepare(
                'UPDATE forum_categories SET name=:n, description=:d, icon=:i, display_order=:o WHERE id=:id'
            )->execute([':n' => $name, ':d' => $desc ?: null, ':i' => $icon, ':o' => $order, ':id' => $id]);
            setFlash('success', 'Category updated.');
        }
    }

    // Delete category
    if ($action === 'delete_category') {
        $id = (int)($_POST['category_id'] ?? 0);
        if ($id) {
            $count = $db->prepare('SELECT COUNT(*) FROM forum_topics WHERE category_id=:id');
            $count->execute([':id' => $id]);
            if ((int)$count->fetchColumn() > 0) {
                setFlash('danger', 'Cannot delete a category that has topics. Delete all topics first.');
            } else {
                $db->prepare('DELETE FROM forum_categories WHERE id=:id')->execute([':id' => $id]);
                setFlash('success', 'Category deleted.');
            }
        }
    }

    // Topic moderation
    if ($action === 'delete_topic') {
        $tid = (int)($_POST['topic_id'] ?? 0);
        if ($tid) {
            $db->prepare('DELETE FROM forum_topics WHERE id=:id')->execute([':id' => $tid]);
            setFlash('success', 'Topic deleted.');
        }
    }
    if ($action === 'toggle_pin') {
        $tid = (int)($_POST['topic_id'] ?? 0);
        if ($tid) {
            $db->prepare('UPDATE forum_topics SET is_pinned = NOT is_pinned WHERE id=:id')
               ->execute([':id' => $tid]);
        }
    }
    if ($action === 'toggle_lock') {
        $tid = (int)($_POST['topic_id'] ?? 0);
        if ($tid) {
            $db->prepare('UPDATE forum_topics SET is_locked = NOT is_locked WHERE id=:id')
               ->execute([':id' => $tid]);
        }
    }

    header('Location: forum.php');
    exit;
}

$categories = getAllForumCategories();

// Recent topics (all, latest 50)
$recentTopics = $db->query('
    SELECT ft.*, fc.name AS category_name,
           u.first_name, u.last_name,
           (SELECT COUNT(*) FROM forum_posts WHERE topic_id=ft.id AND is_deleted=0)-1 AS reply_count
    FROM forum_topics ft
    JOIN forum_categories fc ON fc.id = ft.category_id
    JOIN users u ON u.id = ft.user_id
    ORDER BY ft.created_at DESC LIMIT 50
')->fetchAll();

$iconOptions = [
    'bi-chat-dots-fill', 'bi-megaphone-fill', 'bi-briefcase-fill',
    'bi-lightbulb-fill', 'bi-newspaper', 'bi-question-circle-fill',
    'bi-people-fill', 'bi-trophy-fill', 'bi-heart-fill',
    'bi-globe2', 'bi-book-fill', 'bi-camera-fill',
];

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <h4 class="fw-bold mb-0"><i class="bi bi-chat-dots-fill me-2"></i>Forum Management</h4>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/pages/forum.php" class="btn btn-sm btn-outline-secondary" target="_blank">
      <i class="bi bi-box-arrow-up-right me-1"></i>View Forum
    </a>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createCatModal">
      <i class="bi bi-plus-lg me-1"></i>New Category
    </button>
  </div>
</div>

<?= renderFlash() ?>

<!-- ── Categories ─────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header bg-white py-3">
    <h6 class="fw-bold mb-0"><i class="bi bi-grid me-2"></i>Categories</h6>
  </div>
  <div class="table-responsive">
    <table class="table table-custom mb-0 align-middle">
      <thead>
        <tr>
          <th>Order</th>
          <th>Category</th>
          <th>Topics</th>
          <th>Posts</th>
          <th>Last Activity</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($categories)): ?>
          <tr>
            <td colspan="6" class="text-center text-muted py-4">
              No categories yet. Create one to get started.
            </td>
          </tr>
        <?php else: ?>
        <?php foreach ($categories as $cat): ?>
        <tr>
          <td class="text-muted small fw-semibold"><?= (int)$cat['display_order'] ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <i class="bi <?= htmlspecialchars($cat['icon'], ENT_QUOTES) ?> text-primary fs-5"></i>
              <div>
                <div class="fw-semibold small"><?= htmlspecialchars($cat['name'], ENT_QUOTES) ?></div>
                <?php if ($cat['description']): ?>
                  <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars(mb_strimwidth($cat['description'], 0, 60, '…'), ENT_QUOTES) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="text-center fw-semibold"><?= number_format((int)$cat['topic_count']) ?></td>
          <td class="text-center"><?= number_format((int)$cat['post_count']) ?></td>
          <td class="small text-muted"><?= $cat['last_activity'] ? timeAgo($cat['last_activity']) : '—' ?></td>
          <td class="text-center">
            <button class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#editCatModal"
                    data-id="<?= $cat['id'] ?>"
                    data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>"
                    data-description="<?= htmlspecialchars($cat['description'] ?? '', ENT_QUOTES) ?>"
                    data-icon="<?= htmlspecialchars($cat['icon'], ENT_QUOTES) ?>"
                    data-order="<?= (int)$cat['display_order'] ?>">
              <i class="bi bi-pencil"></i>
            </button>
            <a href="<?= BASE_URL ?>/pages/forum_category.php?id=<?= $cat['id'] ?>"
               class="btn btn-sm btn-outline-primary ms-1" target="_blank">
              <i class="bi bi-eye"></i>
            </a>
            <?php if ((int)$cat['topic_count'] === 0): ?>
            <form method="post" class="d-inline ms-1" onsubmit="return confirm('Delete this category?')">
              <?= csrfField() ?>
              <input type="hidden" name="action"      value="delete_category">
              <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Recent Topics ──────────────────────────────────────────── -->
<div class="card">
  <div class="card-header bg-white py-3">
    <h6 class="fw-bold mb-0"><i class="bi bi-list-ul me-2"></i>All Topics
      <span class="badge bg-secondary ms-1"><?= count($recentTopics) ?></span>
    </h6>
  </div>
  <div class="table-responsive">
    <table class="table table-custom mb-0 align-middle">
      <thead>
        <tr>
          <th>Topic</th>
          <th>Category</th>
          <th>Author</th>
          <th>Replies</th>
          <th>Posted</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recentTopics)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No topics yet.</td></tr>
        <?php else: ?>
        <?php foreach ($recentTopics as $t): ?>
        <tr>
          <td>
            <a href="<?= BASE_URL ?>/pages/forum_topic.php?id=<?= $t['id'] ?>"
               class="fw-semibold small text-decoration-none" target="_blank">
              <?php if ($t['is_pinned']): ?><i class="bi bi-pin-angle-fill text-warning me-1"></i><?php endif; ?>
              <?php if ($t['is_locked']): ?><i class="bi bi-lock-fill text-secondary me-1"></i><?php endif; ?>
              <?= htmlspecialchars(mb_strimwidth($t['title'], 0, 60, '…'), ENT_QUOTES) ?>
            </a>
          </td>
          <td class="small text-muted"><?= htmlspecialchars($t['category_name'], ENT_QUOTES) ?></td>
          <td class="small text-muted"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name'], ENT_QUOTES) ?></td>
          <td class="text-center small"><?= max(0, (int)$t['reply_count']) ?></td>
          <td class="small text-muted"><?= timeAgo($t['created_at']) ?></td>
          <td class="text-center">
            <form method="post" class="d-inline">
              <?= csrfField() ?>
              <input type="hidden" name="topic_id" value="<?= $t['id'] ?>">
              <input type="hidden" name="action"   value="toggle_pin">
              <button class="btn btn-sm btn-outline-warning" title="<?= $t['is_pinned'] ? 'Unpin' : 'Pin' ?>">
                <i class="bi bi-pin-angle<?= $t['is_pinned'] ? '-fill' : '' ?>"></i>
              </button>
            </form>
            <form method="post" class="d-inline ms-1">
              <?= csrfField() ?>
              <input type="hidden" name="topic_id" value="<?= $t['id'] ?>">
              <input type="hidden" name="action"   value="toggle_lock">
              <button class="btn btn-sm btn-outline-secondary" title="<?= $t['is_locked'] ? 'Unlock' : 'Lock' ?>">
                <i class="bi bi-lock<?= $t['is_locked'] ? '-fill' : '' ?>"></i>
              </button>
            </form>
            <form method="post" class="d-inline ms-1" onsubmit="return confirm('Delete this topic and all its posts?')">
              <?= csrfField() ?>
              <input type="hidden" name="topic_id" value="<?= $t['id'] ?>">
              <input type="hidden" name="action"   value="delete_topic">
              <button class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Create Category Modal ─────────────────────────────────── -->
<div class="modal fade" id="createCatModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_category">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>New Category</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required placeholder="e.g. General Discussion">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Description</label>
            <textarea name="description" class="form-control" rows="2"
                      placeholder="What is this category for?"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Icon</label>
            <select name="icon" class="form-select">
              <?php foreach ($iconOptions as $ico): ?>
                <option value="<?= $ico ?>">
                  <?= $ico ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Bootstrap Icon class name.</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Display Order</label>
            <input type="number" name="display_order" class="form-control" value="0" min="0">
            <div class="form-text">Lower numbers appear first.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create Category</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Edit Category Modal ───────────────────────────────────── -->
<div class="modal fade" id="editCatModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action"      value="edit_category">
        <input type="hidden" name="category_id" id="edit-cat-id">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Category</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="edit-cat-name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Description</label>
            <textarea name="description" id="edit-cat-desc" class="form-control" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Icon</label>
            <select name="icon" id="edit-cat-icon" class="form-select">
              <?php foreach ($iconOptions as $ico): ?>
                <option value="<?= $ico ?>"><?= $ico ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Display Order</label>
            <input type="number" name="display_order" id="edit-cat-order" class="form-control" min="0">
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

<script>
document.getElementById('editCatModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('edit-cat-id').value    = btn.dataset.id;
  document.getElementById('edit-cat-name').value  = btn.dataset.name;
  document.getElementById('edit-cat-desc').value  = btn.dataset.description;
  document.getElementById('edit-cat-order').value = btn.dataset.order;
  // Set icon select
  var sel = document.getElementById('edit-cat-icon');
  for (var i = 0; i < sel.options.length; i++) {
    if (sel.options[i].value === btn.dataset.icon) { sel.selectedIndex = i; break; }
  }
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
