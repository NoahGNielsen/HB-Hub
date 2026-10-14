<?php
// User search for the "New chat" popup: /chat/users?q=name returns matching users as JSON.
// Leaves out the user asking and banned users. An empty q lists everyone (up to the limit), A-Z.
// avatar is the address of the user's profile picture, or null when they don't have one.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';

const HBHUB_USER_SEARCH_LIMIT = 50;

$query = is_string($_GET['q'] ?? null) ? mb_strtolower(trim($_GET['q'])) : '';
$query = mb_substr($query, 0, 64); // the userName column can't be longer

// Treat % and _ as plain characters, not LIKE wildcards
$pattern = '%' . addcslashes($query, '\\%_') . '%';

$statement = hbHubDatabase()->prepare('SELECT userId, userName, userAvatarAttachmentId FROM `HBHub-Users`
    WHERE userId <> ? AND userStatus <> ? AND userNameLower LIKE ?
    ORDER BY userNameLower LIMIT ' . HBHUB_USER_SEARCH_LIMIT);
$statement->execute([$hbHubUser['userId'], HBHUB_SESSION_STATUS_BANNED, $pattern]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['users' => array_map(fn (array $user): array => [
    'userId' => (int) $user['userId'],
    'userName' => $user['userName'],
    'avatar' => hbHubAvatarUrl((int) $user['userId'], $user['userAvatarAttachmentId'] !== null ? (int) $user['userAvatarAttachmentId'] : null),
], $statement->fetchAll())]);
