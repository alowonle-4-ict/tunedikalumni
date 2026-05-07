<?php
require_once dirname(__DIR__) . '/config/app.php';

$pageTitle = 'Manage Users';
$activeNav = 'users';

$db = getDB();

// ── POST Actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);

    if ($uid && $uid !== (int)currentUserId()) {
        if ($action === 'set_role') {
            $role = $_POST['role'] ?? 'member';
            if (in_array($role, ['member', 'financial_secretary', 'admin', 'nec_member'], true)) {
                $db->prepare('UPDATE users SET role = :r WHERE id = :id')
                   ->execute([':r' => $role, ':id' => $uid]);
                setFlash('success', 'Role updated successfully.');
            }
        } elseif ($action === 'suspend') {
            $reason = trim($_POST['suspension_reason'] ?? '');
            if ($reason === '') {
                setFlash('error', 'A suspension reason is required.');
            } else {
                $db->prepare(
                    'UPDATE users SET is_active = 0,
                     suspension_reason = :reason,
                     suspended_at = NOW(),
                     suspended_by = :by
                     WHERE id = :id'
                )->execute([
                    ':reason' => $reason,
                    ':by'     => (int)currentUserId(),
                    ':id'     => $uid,
                ]);
                setFlash('warning', 'User suspended successfully.');
            }
        } elseif ($action === 'unsuspend') {
            $db->prepare(
                'UPDATE users SET is_active = 1,
                 suspension_reason = NULL,
                 suspended_at = NULL,
                 suspended_by = NULL
                 WHERE id = :id'
            )->execute([':id' => $uid]);
            setFlash('success', 'User unsuspended successfully.');
        } elseif ($action === 'toggle_active') {
            $db->prepare('UPDATE users SET is_active = NOT is_active, suspension_reason = NULL, suspended_at = NULL, suspended_by = NULL WHERE id = :id')
               ->execute([':id' => $uid]);
            setFlash('info', 'User status toggled.');
        } elseif ($action === 'disable_2fa') {
            $db->prepare('UPDATE users SET two_fa_enabled = 0 WHERE id = :id')
               ->execute([':id' => $uid]);
            setFlash('success', 'Two-factor authentication disabled for this member.');
        } elseif ($action === 'reset_password') {
            $newPass = trim($_POST['new_password'] ?? '');
            if (strlen($newPass) < 8) {
                setFlash('danger', 'Password must be at least 8 characters.');
            } else {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $db->prepare('UPDATE users SET password = :p WHERE id = :id')
                   ->execute([':p' => $hash, ':id' => $uid]);
                // Fetch name for confirmation message
                $uRow = $db->prepare('SELECT first_name, last_name FROM users WHERE id = :id');
                $uRow->execute([':id' => $uid]);
                $uRow = $uRow->fetch();
                $uName = htmlspecialchars($uRow['first_name'] . ' ' . $uRow['last_name'], ENT_QUOTES);
                setFlash('success', "Password reset for <strong>{$uName}</strong>. New password: <code>{$newPass}</code> — share it securely.");
            }
        }
    }

    // Bulk actions
    if ($action === 'bulk') {
        $ids     = array_map('intval', (array)($_POST['selected_ids'] ?? []));
        $bulk    = $_POST['bulk_action'] ?? '';
        $selfId  = (int)currentUserId();
        $ids     = array_filter($ids, fn($i) => $i !== $selfId);

        if ($ids && $bulk === 'activate') {
            $in = implode(',', $ids);
            $db->exec("UPDATE users SET is_active=1, suspension_reason=NULL, suspended_at=NULL, suspended_by=NULL WHERE id IN ({$in})");
            setFlash('success', count($ids) . ' user(s) activated.');
        } elseif ($ids && $bulk === 'deactivate') {
            $in = implode(',', $ids);
            $db->exec("UPDATE users SET is_active=0 WHERE id IN ({$in})");
            setFlash('warning', count($ids) . ' user(s) deactivated.');
        }
    }

    redirect(BASE_URL . '/admin/users.php');
}

// ── Filters ───────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$role   = $_GET['role']   ?? '';
$status = $_GET['status'] ?? '';

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]      = '(u.first_name LIKE :q OR u.last_name LIKE :q OR u.email LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($role && in_array($role, ['member','financial_secretary','admin','nec_member'], true)) {
    $where[]         = 'u.role = :role';
    $params[':role'] = $role;
}
if ($status === 'suspended') {
    $where[] = '(u.is_active = 0 AND u.suspension_reason IS NOT NULL)';
} elseif ($status === 'disabled') {
    $where[] = '(u.is_active = 0 AND u.suspension_reason IS NULL)';
} elseif ($status === 'active') {
    $where[] = 'u.is_active = 1';
}

$sql   = 'SELECT u.*, m.membership_id, m.status as mem_status
          FROM users u
          LEFT JOIN memberships m ON m.user_id = u.id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY u.created_at DESC';
$stmt  = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <h4 class="fw-bold mb-0"><i class="bi bi-people-fill me-2"></i>Users (<?= count($users) ?>)</h4>
  <a href="<?= BASE_URL ?>/api/export_members.php" class="btn btn-outline-success btn-sm">
    <i class="bi bi-download me-1"></i>Export CSV
  </a>
</div>

<?= renderFlash() ?>

<!-- ── Filters ─────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-6 col-md-4">
        <label class="form-label small fw-semibold">Search</label>
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Name or email..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
      </div>
      <div class="col-sm-4 col-md-2">
        <label class="form-label small fw-semibold">Role</label>
        <select name="role" class="form-select form-select-sm">
          <option value="">All Roles</option>
          <option value="member"              <?= $role === 'member'              ? 'selected' : '' ?>>Member</option>
          <option value="financial_secretary" <?= $role === 'financial_secretary' ? 'selected' : '' ?>>Financial Secretary</option>
          <option value="nec_member"          <?= $role === 'nec_member'          ? 'selected' : '' ?>>NEC Member</option>
          <option value="admin"               <?= $role === 'admin'               ? 'selected' : '' ?>>Admin</option>
        </select>
      </div>
      <div class="col-sm-4 col-md-2">
        <label class="form-label small fw-semibold">Account Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active"    <?= $status === 'active'    ? 'selected' : '' ?>>Active</option>
          <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
          <option value="disabled"  <?= $status === 'disabled'  ? 'selected' : '' ?>>Disabled</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- ── Bulk Action Bar ──────────────────────────────────── -->
<div id="bulkBar" class="alert alert-primary d-none d-flex align-items-center gap-3 mb-3">
  <span class="fw-semibold small"><span id="bulkCount">0</span> selected</span>
  <form method="post" id="bulkForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="bulk">
    <div id="bulkHiddenInputs"></div>
    <div class="d-flex gap-2">
      <select name="bulk_action" class="form-select form-select-sm" style="width:auto">
        <option value="">— Choose action —</option>
        <option value="activate">Activate</option>
        <option value="deactivate">Deactivate</option>
      </select>
      <button type="submit" class="btn btn-primary btn-sm"
              onclick="return confirm('Apply bulk action to selected users?')">Apply</button>
    </div>
  </form>
</div>

<!-- ── Users Table ─────────────────────────────────────── -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-custom mb-0">
        <thead>
          <tr>
            <th><input type="checkbox" id="selectAll" class="form-check-input"></th>
            <th>#</th>
            <th>Name</th>
            <th>Email</th>
            <th>Location</th>
            <th>Membership ID</th>
            <th>Mem. Status</th>
            <th>Account</th>
            <th>Role</th>
            <th>Joined</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($users): ?>
            <?php foreach ($users as $i => $u): ?>
              <?php $isSuspended = !$u['is_active'] && !empty($u['suspension_reason']); ?>
              <tr class="<?= $isSuspended ? 'table-danger' : (!$u['is_active'] ? 'table-secondary' : '') ?>">
                <td><input type="checkbox" class="form-check-input row-check" value="<?= (int)$u['id'] ?>"></td>
                <td class="text-muted small"><?= $i + 1 ?></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <img src="<?= htmlspecialchars(userAvatarUrl($u), ENT_QUOTES) ?>"
                         alt="" style="width:32px;height:32px;object-fit:cover;border-radius:50%;flex-shrink:0">
                    <div>
                      <div class="fw-semibold small"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES) ?></div>
                      <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($u['phone'] ?? '', ENT_QUOTES) ?></div>
                      <?php if (!empty($u['matric_number'])): ?>
                        <div class="text-muted" style="font-size:.72rem">
                          <i class="bi bi-hash"></i><?= htmlspecialchars($u['matric_number'], ENT_QUOTES) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="small"><?= htmlspecialchars($u['email'], ENT_QUOTES) ?></td>
                <td class="small"><?= htmlspecialchars($u['country'] === 'Nigeria' ? ($u['state'] ?? '') : $u['country'], ENT_QUOTES) ?></td>
                <td>
                  <?php if ($u['membership_id']): ?>
                    <code class="small"><?= htmlspecialchars($u['membership_id'], ENT_QUOTES) ?></code>
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($u['mem_status']): ?>
                    <span class="status-badge badge-<?= $u['mem_status'] ?>"><?= ucfirst($u['mem_status']) ?></span>
                  <?php else: ?>
                    <span class="text-muted small">No membership</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($isSuspended): ?>
                    <span class="badge bg-danger">
                      <i class="bi bi-slash-circle me-1"></i>Suspended
                    </span>
                    <div class="text-danger small mt-1" style="max-width:160px;font-size:.7rem"
                         title="<?= htmlspecialchars($u['suspension_reason'], ENT_QUOTES) ?>">
                      <?= htmlspecialchars(mb_strimwidth($u['suspension_reason'], 0, 60, '…'), ENT_QUOTES) ?>
                    </div>
                    <?php if ($u['suspended_at']): ?>
                      <div class="text-muted" style="font-size:.68rem"><?= formatDate($u['suspended_at'], 'd M Y') ?></div>
                    <?php endif; ?>
                  <?php elseif (!$u['is_active']): ?>
                    <span class="badge bg-secondary">Disabled</span>
                  <?php else: ?>
                    <span class="badge bg-success">Active</span>
                  <?php endif; ?>
                  <?php if (!empty($u['two_fa_enabled'])): ?>
                    <div class="mt-1">
                      <span class="badge bg-info text-dark" style="font-size:.65rem">
                        <i class="bi bi-shield-lock-fill me-1"></i>2FA On
                      </span>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                    $roleColors = ['admin' => 'danger', 'financial_secretary' => 'warning text-dark', 'nec_member' => 'info text-dark', 'member' => 'secondary'];
                    $roleColor  = $roleColors[$u['role']] ?? 'secondary';
                  ?>
                  <span class="badge bg-<?= $roleColor ?> text-capitalize"><?= str_replace('_', ' ', $u['role']) ?></span>
                </td>
                <td class="text-muted small"><?= formatDate($u['created_at'], 'd M Y') ?></td>
                <td>
                  <?php if ($u['id'] != currentUserId()): ?>
                    <div class="d-flex flex-wrap gap-1">
                      <!-- Change Role -->
                      <form method="POST" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="set_role">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <select name="role"
                                data-current-role="<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>"
                                data-user-name="<?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES) ?>"
                                onchange="confirmRoleChange(this)"
                                class="form-select form-select-sm" style="width:auto;font-size:.75rem">
                          <option value="member"              <?= $u['role'] === 'member'              ? 'selected' : '' ?>>Member</option>
                          <option value="financial_secretary" <?= $u['role'] === 'financial_secretary' ? 'selected' : '' ?>>Fin. Sec.</option>
                          <option value="nec_member"          <?= $u['role'] === 'nec_member'          ? 'selected' : '' ?>>NEC Member</option>
                          <option value="admin"               <?= $u['role'] === 'admin'               ? 'selected' : '' ?>>Admin</option>
                        </select>
                      </form>

                      <?php if ($isSuspended): ?>
                        <!-- Unsuspend -->
                        <form method="POST" class="d-inline">
                          <?= csrfField() ?>
                          <input type="hidden" name="action"  value="unsuspend">
                          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-success"
                                  data-confirm="Unsuspend this user and restore their access?">
                            <i class="bi bi-check-circle me-1"></i>Unsuspend
                          </button>
                        </form>
                      <?php elseif ($u['is_active']): ?>
                        <!-- Suspend trigger -->
                        <button type="button" class="btn btn-sm btn-danger js-suspend-btn"
                                data-uid="<?= $u['id'] ?>"
                                data-name="<?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES) ?>">
                          <i class="bi bi-slash-circle me-1"></i>Suspend
                        </button>
                      <?php else: ?>
                        <!-- Was disabled (not via suspend) — re-enable -->
                        <form method="POST" class="d-inline">
                          <?= csrfField() ?>
                          <input type="hidden" name="action"  value="toggle_active">
                          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-success"
                                  data-confirm="Enable this user?">
                            <i class="bi bi-check-circle"></i> Enable
                          </button>
                        </form>
                      <?php endif; ?>

                      <?php if (!empty($u['two_fa_enabled'])): ?>
                        <!-- Disable 2FA -->
                        <form method="POST" class="d-inline">
                          <?= csrfField() ?>
                          <input type="hidden" name="action"  value="disable_2fa">
                          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-warning"
                                  data-confirm="Disable 2FA for <?= htmlspecialchars($u['first_name'], ENT_QUOTES) ?>? They will need to re-enable it themselves.">
                            <i class="bi bi-shield-x me-1"></i>Disable 2FA
                          </button>
                        </form>
                      <?php endif; ?>

                      <!-- Reset Password -->
                      <button type="button" class="btn btn-sm btn-outline-danger js-reset-pwd-btn"
                              data-uid="<?= (int)$u['id'] ?>"
                              data-name="<?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES) ?>">
                        <i class="bi bi-key me-1"></i>Reset Password
                      </button>

                    </div>
                  <?php else: ?>
                    <span class="text-muted small">(you)</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="10" class="text-center text-muted py-4">No users found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Reset Password Modal ────────────────────────────── -->
<div class="modal fade" id="resetPwdModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" id="resetPwdForm">
        <?= csrfField() ?>
        <input type="hidden" name="action"  value="reset_password">
        <input type="hidden" name="user_id" id="resetPwdUserId">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">Reset password for <strong id="resetPwdUserName"></strong>. They will need to use this new password to log in.</p>
          <div class="mb-3">
            <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="text" name="new_password" id="resetPwdInput" class="form-control font-monospace"
                     minlength="8" required placeholder="Min. 8 characters">
              <button type="button" class="btn btn-outline-secondary" id="genPwdBtn" title="Generate password">
                <i class="bi bi-arrow-repeat"></i>
              </button>
            </div>
            <div class="form-text">Share this password securely with the member after resetting.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-key me-1"></i>Reset Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Suspend Modal ───────────────────────────────────── -->
<div class="modal fade" id="suspendModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" id="suspendForm">
        <?= csrfField() ?>
        <input type="hidden" name="action"  value="suspend">
        <input type="hidden" name="user_id" id="suspendUserId">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="bi bi-slash-circle me-2"></i>Suspend Member</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">You are about to suspend <strong id="suspendUserName"></strong>. They will not be able to log in until unsuspended.</p>
          <div class="mb-3">
            <label class="form-label fw-semibold">Suspension Reason <span class="text-danger">*</span></label>
            <textarea name="suspension_reason" class="form-control" rows="3"
                      placeholder="Enter the reason for suspension…" required maxlength="500"></textarea>
            <div class="form-text">This reason will be visible in the admin panel.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-slash-circle me-1"></i>Suspend Member</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function confirmRoleChange(sel) {
  var name    = sel.dataset.userName;
  var newRole = sel.options[sel.selectedIndex].text;
  var oldRole = sel.dataset.currentRole;
  if (!confirm('Change role for ' + name + ' to "' + newRole + '"?\n\nThis will update their access level immediately.')) {
    // Revert select to original value
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value === oldRole) { sel.selectedIndex = i; break; }
    }
    return;
  }
  sel.form.submit();
}

// Reset password modal
function generatePassword(len) {
  var chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#';
  var pwd = '';
  for (var i = 0; i < len; i++) pwd += chars[Math.floor(Math.random() * chars.length)];
  return pwd;
}
document.querySelectorAll('.js-reset-pwd-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('resetPwdUserId').value = this.dataset.uid;
    document.getElementById('resetPwdUserName').textContent = this.dataset.name;
    document.getElementById('resetPwdInput').value = generatePassword(10);
    new bootstrap.Modal(document.getElementById('resetPwdModal')).show();
  });
});
document.getElementById('genPwdBtn').addEventListener('click', function() {
  document.getElementById('resetPwdInput').value = generatePassword(10);
});

// Suspend modal
document.querySelectorAll('.js-suspend-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('suspendUserId').value = this.dataset.uid;
        document.getElementById('suspendUserName').textContent = this.dataset.name;
        document.querySelector('#suspendForm textarea[name="suspension_reason"]').value = '';
        new bootstrap.Modal(document.getElementById('suspendModal')).show();
    });
});

// Confirm dialogs for unsuspend / enable
document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (!confirm(this.dataset.confirm)) e.preventDefault();
    });
});
</script>

<script>
const checkboxes = () => document.querySelectorAll('.row-check');
const bulkBar    = document.getElementById('bulkBar');
const bulkCount  = document.getElementById('bulkCount');
const hiddenDiv  = document.getElementById('bulkHiddenInputs');

function updateBulkBar() {
  const checked = [...checkboxes()].filter(c => c.checked);
  bulkCount.textContent = checked.length;
  bulkBar.classList.toggle('d-none', checked.length === 0);
  bulkBar.classList.toggle('d-flex', checked.length > 0);
  hiddenDiv.innerHTML = checked.map(c =>
    `<input type="hidden" name="selected_ids[]" value="${c.value}">`
  ).join('');
}

document.getElementById('selectAll').addEventListener('change', function() {
  checkboxes().forEach(c => c.checked = this.checked);
  updateBulkBar();
});
document.querySelectorAll('.row-check').forEach(c =>
  c.addEventListener('change', updateBulkBar)
);
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
