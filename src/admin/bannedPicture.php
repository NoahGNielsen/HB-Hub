<?php
// One banned picture: /admin/bannedPicture?picture=id sends the copy kept for HBHub-BannedAvatars, for the Banned
// pictures page (admin/banned.php). Only for site admins, everyone else gets a 404 like the rest of the admin panel.
// A row's file never changes, so the browser can keep it for good.
// The copy is a file on disk (assets/php/pictureFiles.php, which also has the cache headers).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/pictureFiles.php';

adminOnly($hbHubUser);

$pictureId = $_GET['picture'] ?? null;
if (!is_string($pictureId) || !ctype_digit($pictureId) || strlen($pictureId) > 10) {
    adminNotFound();
}
$pictureId = (int) $pictureId;

// Nothing about caching is sent before the picture's file is known to be there
$statement = hbHubDatabase()->prepare('SELECT bannedAvatarType, bannedAvatarKey FROM `HBHub-BannedAvatars` WHERE bannedAvatarId = ?');
$statement->execute([$pictureId]);
$picture = $statement->fetch();
$path = $picture !== false ? hbHubPictureFilePath(HBHUB_PICTURE_FILES_BANNED, $picture['bannedAvatarKey']) : null;
if ($path === null) {
    adminNotFound();
}

hbHubPictureFileSend('"banned-avatar-' . $pictureId . '"', $picture['bannedAvatarType'], $path);
