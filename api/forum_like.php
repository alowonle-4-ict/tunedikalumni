<?php
/**
 * TUNEDIK — AJAX: Toggle forum post like.
 * POST: post_id, csrf_token
 * Returns JSON: {success, liked, count}
 */
require_once dirname(__DIR__) . '/config/app.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (!verifyCsrfSilent($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$postId = (int)($_POST['post_id'] ?? 0);

if ($postId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid post.']);
    exit;
}

$db = getDB();

// Confirm post exists and is not deleted
$check = $db->prepare('SELECT id FROM forum_posts WHERE id=:id AND is_deleted=0 LIMIT 1');
$check->execute([':id' => $postId]);
if (!$check->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Post not found.']);
    exit;
}

// Toggle
$existing = $db->prepare('SELECT id FROM forum_likes WHERE post_id=:pid AND user_id=:uid LIMIT 1');
$existing->execute([':pid' => $postId, ':uid' => $userId]);

if ($existing->fetch()) {
    $db->prepare('DELETE FROM forum_likes WHERE post_id=:pid AND user_id=:uid')
       ->execute([':pid' => $postId, ':uid' => $userId]);
    $liked = false;
} else {
    $db->prepare('INSERT INTO forum_likes (post_id, user_id) VALUES (:pid, :uid)')
       ->execute([':pid' => $postId, ':uid' => $userId]);
    $liked = true;
}

$count = (int)$db->prepare('SELECT COUNT(*) FROM forum_likes WHERE post_id=:pid')
                 ->execute([':pid' => $postId]) ? $db->query('SELECT FOUND_ROWS()')->fetchColumn() : 0;

// Get fresh count
$cnt = $db->prepare('SELECT COUNT(*) FROM forum_likes WHERE post_id=:pid');
$cnt->execute([':pid' => $postId]);
$count = (int)$cnt->fetchColumn();

echo json_encode(['success' => true, 'liked' => $liked, 'count' => $count]);
