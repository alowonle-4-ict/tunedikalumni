<?php
require_once dirname(__DIR__) . '/config/app.php';
$pageTitle = 'Events';
$activeNav = 'events';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title    = trim($_POST['title']       ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $location = trim($_POST['location']    ?? '');
        $date     = trim($_POST['event_date']  ?? '');
        $endDate  = trim($_POST['end_date']    ?? '') ?: null;
        $isRsvp   = isset($_POST['is_rsvp']) ? 1 : 0;
        $maxRsvp  = $isRsvp && !empty($_POST['max_rsvp']) ? (int)$_POST['max_rsvp'] : null;

        if ($title && $date) {
            $db->prepare(
                'INSERT INTO events (title, description, location, event_date, end_date, is_rsvp, max_rsvp, created_by)
                 VALUES (:t, :d, :loc, :ed, :end, :rsvp, :max, :by)'
            )->execute([
                ':t' => $title, ':d' => $desc ?: null, ':loc' => $location ?: null,
                ':ed' => $date, ':end' => $endDate, ':rsvp' => $isRsvp,
                ':max' => $maxRsvp, ':by' => (int)currentUserId(),
            ]);
            auditLog('event_created', "Created event: {$title}");
            setFlash('success', 'Event created.');
        } else {
            setFlash('danger', 'Title and date are required.');
        }
    }

    if ($action === 'delete') {
        $eid = (int)($_POST['event_id'] ?? 0);
        if ($eid) {
            $db->prepare('DELETE FROM events WHERE id = :id')->execute([':id' => $eid]);
            auditLog('event_deleted', "Deleted event #{$eid}");
            setFlash('success', 'Event deleted.');
        }
    }

    if ($action === 'change_status') {
        $eid    = (int)($_POST['event_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($eid && in_array($status, ['upcoming','ongoing','past','cancelled'], true)) {
            $db->prepare('UPDATE events SET status = :s WHERE id = :id')
               ->execute([':s' => $status, ':id' => $eid]);
            setFlash('success', 'Event status updated.');
        }
    }

    redirect(BASE_URL . '/admin/events.php');
}

$events = $db->query(
    'SELECT e.*, u.first_name, u.last_name,
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id) AS rsvp_count
     FROM events e JOIN users u ON u.id = e.created_by
     ORDER BY e.event_date DESC'
)->fetchAll();

$statusBadge = ['upcoming' => 'primary', 'ongoing' => 'success', 'past' => 'secondary', 'cancelled' => 'danger'];

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <h4 class="fw-bold mb-0"><i class="bi bi-calendar-event-fill me-2"></i>Events</h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-plus-lg me-1"></i>New Event
  </button>
</div>

<?= renderFlash() ?>

<div class="card">
  <?php if (empty($events)): ?>
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-calendar-x fs-1"></i>
      <p class="mt-2">No events yet.</p>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-custom mb-0 align-middle">
        <thead>
          <tr><th>Event</th><th>Date</th><th>Location</th><th>RSVP</th><th>Status</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($events as $e): ?>
          <tr>
            <td>
              <div class="fw-semibold small"><?= htmlspecialchars($e['title'], ENT_QUOTES) ?></div>
              <?php if ($e['description']): ?>
                <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars(mb_substr($e['description'], 0, 80), ENT_QUOTES) ?>...</div>
              <?php endif; ?>
            </td>
            <td class="small"><?= date('d M Y', strtotime($e['event_date'])) ?></td>
            <td class="small text-muted"><?= $e['location'] ? htmlspecialchars($e['location'], ENT_QUOTES) : '—' ?></td>
            <td class="small">
              <?php if ($e['is_rsvp']): ?>
                <span class="fw-semibold"><?= $e['rsvp_count'] ?></span>
                <?= $e['max_rsvp'] ? ' / ' . $e['max_rsvp'] : '' ?>
              <?php else: ?>
                <span class="text-muted">Off</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-<?= $statusBadge[$e['status']] ?? 'secondary' ?>">
                <?= ucfirst($e['status']) ?>
              </span>
            </td>
            <td class="text-center">
              <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Actions</button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <?php foreach (['upcoming','ongoing','past','cancelled'] as $sv):
                    if ($sv === $e['status']) continue; ?>
                    <li>
                      <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"   value="change_status">
                        <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                        <input type="hidden" name="status"   value="<?= $sv ?>">
                        <button type="submit" class="dropdown-item small">Set <?= ucfirst($sv) ?></button>
                      </form>
                    </li>
                  <?php endforeach; ?>
                  <li><hr class="dropdown-divider"></li>
                  <li>
                    <form method="post" onsubmit="return confirm('Delete this event?')">
                      <?= csrfField() ?>
                      <input type="hidden" name="action"   value="delete">
                      <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                      <button type="submit" class="dropdown-item text-danger small">Delete</button>
                    </form>
                  </li>
                </ul>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
          <h5 class="modal-title fw-bold"><i class="bi bi-calendar-plus me-2"></i>New Event</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Event Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Location / Venue</label>
              <input type="text" name="location" class="form-control" placeholder="e.g. University Auditorium, Lagos">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Start Date & Time <span class="text-danger">*</span></label>
              <input type="datetime-local" name="event_date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">End Date & Time</label>
              <input type="datetime-local" name="end_date" class="form-control">
            </div>
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_rsvp" id="rsvpSwitch"
                       onchange="document.getElementById('maxRsvpRow').classList.toggle('d-none', !this.checked)">
                <label class="form-check-label fw-semibold" for="rsvpSwitch">Enable RSVP</label>
              </div>
            </div>
            <div class="col-md-4 d-none" id="maxRsvpRow">
              <label class="form-label fw-semibold">Max Attendees</label>
              <input type="number" name="max_rsvp" class="form-control" min="1" placeholder="Leave blank for unlimited">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check me-1"></i>Create Event</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
