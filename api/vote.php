<?php
/**
 * TUNEDIK — AJAX: Submit a vote for an election candidate.
 * POST: position_id, application_id, csrf_token
 * Returns JSON.
 */
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT_PATH . '/includes/election_functions.php';

header('Content-Type: application/json');

// Must be logged in as an active member
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'You must be logged in to vote.']);
    exit;
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (!verifyCsrfSilent($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Security token invalid. Please refresh and try again.']);
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$posId     = (int)($_POST['position_id']    ?? 0);
$appId     = (int)($_POST['application_id'] ?? 0);

if ($posId <= 0 || $appId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request parameters.']);
    exit;
}

// Check membership
if (!isActiveMember($userId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Only active members can vote.']);
    exit;
}

$db  = getDB();
$pos = getPositionById($posId);

if (!$pos) {
    http_response_code(404);
    echo json_encode(['error' => 'Position not found.']);
    exit;
}

// Must be in voting_open state
if (electionState($pos) !== 'voting_open') {
    http_response_code(403);
    echo json_encode(['error' => 'Voting is not currently open for this position.']);
    exit;
}

// Already voted?
if (hasVoted($userId, $posId)) {
    http_response_code(409);
    echo json_encode(['error' => 'You have already voted for this position.']);
    exit;
}

// Confirm application_id belongs to this position and is approved
$stmt = $db->prepare(
    'SELECT id FROM election_applications
     WHERE id = :aid AND position_id = :pid AND status = "approved" LIMIT 1'
);
$stmt->execute([':aid' => $appId, ':pid' => $posId]);
if (!$stmt->fetch()) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid candidate selection.']);
    exit;
}

// ── Record vote (transaction + SELECT FOR UPDATE to prevent race conditions) ──
try {
    $db->beginTransaction();

    // Lock voter row to prevent duplicate submissions
    $db->prepare(
        'SELECT id FROM users WHERE id = :uid FOR UPDATE'
    )->execute([':uid' => $userId]);

    // Double-check after lock
    if (hasVoted($userId, $posId)) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'You have already voted for this position.']);
        exit;
    }

    $db->prepare(
        'INSERT INTO election_votes (voter_id, position_id, application_id)
         VALUES (:vid, :pid, :aid)'
    )->execute([':vid' => $userId, ':pid' => $posId, ':aid' => $appId]);

    $db->commit();
} catch (PDOException $e) {
    $db->rollBack();
    // Duplicate key = already voted (race condition caught at DB level)
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['error' => 'You have already voted for this position.']);
    } else {
        error_log('Vote error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'An error occurred. Please try again.']);
    }
    exit;
}

// Return updated totals
$results    = getPositionResults($posId);
$totalVotes = array_sum(array_column($results, 'vote_count'));

echo json_encode([
    'success'     => true,
    'message'     => 'Your vote has been recorded. Thank you!',
    'total_votes' => (int)$totalVotes,
    'candidates'  => array_map(fn($r) => [
        'application_id' => (int)$r['application_id'],
        'name'           => $r['first_name'] . ' ' . $r['last_name'],
        'vote_count'     => (int)$r['vote_count'],
    ], $results),
]);
