<?php
/**
 * TUNEDIK — AJAX: Live election results for a position.
 * Returns JSON with vote counts per candidate.
 * Public endpoint — no login required (results are public once voting starts).
 */
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT_PATH . '/includes/election_functions.php';

header('Content-Type: application/json');

$posId = (int)($_GET['position_id'] ?? 0);
if ($posId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing position_id']);
    exit;
}

$pos = getPositionById($posId);
if (!$pos) {
    http_response_code(404);
    echo json_encode(['error' => 'Position not found']);
    exit;
}

$state = electionState($pos);

// Only return data if candidates have been published
if (!in_array($state, ['candidates_announced', 'voting_open', 'ended'])) {
    echo json_encode(['error' => 'Results not yet available', 'state' => $state]);
    exit;
}

$results    = getPositionResults($posId);
$totalVotes = array_sum(array_column($results, 'vote_count'));

$candidates = [];
foreach ($results as $r) {
    $candidates[] = [
        'application_id' => (int)$r['application_id'],
        'name'           => $r['first_name'] . ' ' . $r['last_name'],
        'vote_count'     => (int)$r['vote_count'],
    ];
}

echo json_encode([
    'position_id'  => $posId,
    'position'     => $pos['title'],
    'state'        => $state,
    'total_votes'  => (int)$totalVotes,
    'candidates'   => $candidates,
]);
