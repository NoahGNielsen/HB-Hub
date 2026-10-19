<?php
// One banned picture: /admin/bannedPicture?picture=id sends the copy kept in HBHub-BannedAvatars, for the Banned
// pictures page (admin/banned.php). Only for site admins, everyone else gets a 404 like the rest of the admin panel.
// A row's file never changes, so the browser can keep it for good.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';

adminOnly($hbHubUser);

$pictureId = $_GET['picture'] ?? null;
if (!is_string($pictureId) || !ctype_digit($pictureId) || strlen($pictureId) > 10) {
    adminNotFound();
}

// Private, as it's only for site admins
header('Cache-Control: private, max-age=31536000, immutable');
$etag = '"banned-avatar-' . (int) $pictureId . '"';
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag) {
    http_response_code(304);
    exit;
}

$statement = hbHubDatabase()->prepare('SELECT bannedAvatarType, bannedAvatarFile FROM `HBHub-BannedAvatars` WHERE bannedAvatarId = ?');
$statement->execute([(int) $pictureId]);
$picture = $statement->fetch();
if ($picture === false) {
    header_remove('Cache-Control');
    header_remove('ETag');
    adminNotFound();
}

header('Content-Type: ' . $picture['bannedAvatarType']);
header('Content-Length: ' . strlen($picture['bannedAvatarFile']));
echo $picture['bannedAvatarFile'];
