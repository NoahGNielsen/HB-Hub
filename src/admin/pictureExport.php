<?php
// Admin panel's picture export: /admin/pictureExport, a page that's only here for the changeover from pictures in the
// database to pictures on disk (assets/php/pictureFiles.php). It writes out the pictures that are still BLOBs:
// HBHub-Attachments.attachmentFile (profile pictures and group icons) to pictures/, and
// HBHub-BannedAvatars.bannedAvatarFile (the banned pictures' copies) to bannedPictures/, and gives each row its key.
// Everyone but site admins gets a 404, like the rest of the admin panel. It isn't in the side menu, as it goes again
// once every row is done and the BLOB columns are dropped.
//
// The hosting has no command line and stops requests that take long, so it goes a batch at a time: the button posts
// action=export, which does up to ADMIN_PICTURE_EXPORT_ROWS rows or ADMIN_PICTURE_EXPORT_BYTES (whichever comes
// first, and never for longer than ADMIN_PICTURE_EXPORT_SECONDS), and adminPictureExport.js posts again until
// nothing is left, showing how far it is.
//
// One row: read the BLOB, write the file (temp name, then renamed into place), check the file's size and SHA-256
// against the BLOB, then UPDATE ... SET key WHERE the key IS still NULL. Only one request can win that UPDATE, so a
// double click or a second admin never exports a row twice: the one that lost deletes its own file again. The BLOB
// stays where it is, so nothing can be lost here; the pages just read the file from then on. The ids stay the same,
// so the pictures' addresses (&v=<id>) and ETags do too and nobody's browser cache is thrown away.
// A row that fails keeps its NULL key (the site goes on sending its BLOB), is listed on the page with why, and is
// passed over for the rest of the run: each batch starts after the last id of the one before (afterPictures and
// afterBanned in the POST). Pressing the button again starts from the beginning and tries them again.
//
// Every row is also written to pictureExport.log in the attachments folder as it happens, one line each, so a run
// that's cut off still shows how far it got.
//
// POST action=export, afterPictures=id, afterBanned=id, answered as JSON:
//   { rows: [{table: 'pictures'|'bannedPictures', id, bytes}], failed: [{table, id, error}], bytes,
//     afterPictures, afterBanned, isFinished, totals: { pictures: {done, left}, bannedPictures: {done, left} } }
// isFinished says that this batch reached the end of both tables (what's left then are the rows that failed).
// Errors: 400 {"error": "unknownAction"}, 405 for anything but GET and POST.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/pictureFiles.php';

const ADMIN_PICTURE_EXPORT_ROWS = 25; // rows in one batch at most
const ADMIN_PICTURE_EXPORT_BYTES = 20 * 1024 * 1024; // ... or this much, whichever comes first
const ADMIN_PICTURE_EXPORT_SECONDS = 15; // ... and no new row is started after this long
const ADMIN_PICTURE_EXPORT_LOG = 'pictureExport.log'; // in the attachments folder
// Folder (HBHUB_PICTURE_FILES_*) => the table's id, BLOB and key columns. The table itself is in HBHUB_PICTURE_FILES_TABLES.
const ADMIN_PICTURE_EXPORT_COLUMNS = [
    HBHUB_PICTURE_FILES_PICTURES => ['attachmentId', 'attachmentFile', 'attachmentKey'],
    HBHUB_PICTURE_FILES_BANNED => ['bannedAvatarId', 'bannedAvatarFile', 'bannedAvatarKey'],
];

adminOnly($hbHubUser);

$db = hbHubDatabase();

/**
 * How many rows of each table are on disk (done) and how many still only in the database (left).
 *
 * @return array<string, array{done: int, left: int}>
 */
function adminPictureExportTotals(PDO $db): array
{
    $totals = [];
    foreach (ADMIN_PICTURE_EXPORT_COLUMNS as $folder => [, , $keyColumn]) {
        $row = $db->query('SELECT COUNT(`' . $keyColumn . '`) AS done, COUNT(*) - COUNT(`' . $keyColumn . '`) AS `left`
            FROM `' . HBHUB_PICTURE_FILES_TABLES[$folder][0] . '`')->fetch();
        $totals[$folder] = ['done' => (int) $row['done'], 'left' => (int) $row['left']];
    }
    return $totals;
}

/**
 * Adds a line to the log in the attachments folder, straight away, so it's there even if the request is stopped.
 */
function adminPictureExportLog(string $line): void
{
    $dir = hbHubChatAttachmentsDir();
    if ($dir !== null) {
        @file_put_contents($dir . '/' . ADMIN_PICTURE_EXPORT_LOG, date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

/**
 * Exports one row (see the top of this file). Returns the bytes written, 0 when there was nothing to do (someone
 * else exported it first, or it was deleted in the meantime), or why it failed as a string.
 */
function adminPictureExportRow(PDO $db, string $folder, int $id): int|string
{
    [$idColumn, $fileColumn, $keyColumn] = ADMIN_PICTURE_EXPORT_COLUMNS[$folder];
    $table = HBHUB_PICTURE_FILES_TABLES[$folder][0];

    $statement = $db->prepare('SELECT `' . $fileColumn . '` FROM `' . $table . '` WHERE `' . $idColumn . '` = ? AND `' . $keyColumn . '` IS NULL');
    $statement->execute([$id]);
    $file = $statement->fetchColumn();
    if ($file === false) {
        return 0; // exported or deleted since the batch was picked
    }
    if (!is_string($file) || $file === '') {
        return 'there is no picture in the database for it';
    }

    try {
        $key = hbHubPictureFileSave($folder, $file);
    } catch (RuntimeException $e) {
        return 'the file could not be written';
    }

    // What's on disk has to be exactly what's in the database before the row may point at it
    clearstatcache();
    $path = hbHubPictureFilePath($folder, $key);
    if ($path === null || filesize($path) !== strlen($file) || !hash_equals(hash('sha256', $file), (string) hash_file('sha256', $path))) {
        hbHubPictureFileDelete($folder, $key);
        return 'the file on disk did not match the picture in the database';
    }

    try {
        $statement = $db->prepare('UPDATE `' . $table . '` SET `' . $keyColumn . '` = ? WHERE `' . $idColumn . '` = ? AND `' . $keyColumn . '` IS NULL');
        $statement->execute([$key, $id]);
    } catch (PDOException $e) {
        hbHubPictureFileDelete($folder, $key);
        error_log('Picture export: could not save the key of ' . $folder . ' ' . $id . ': ' . $e->getMessage());
        return 'the key could not be saved';
    }
    if ($statement->rowCount() === 0) {
        hbHubPictureFileDelete($folder, $key); // another request got there first, or the row is gone: this copy isn't needed
        return 0;
    }
    return strlen($file);
}

/**
 * Exports one batch, starting after the ids in $after (folder => id). Returns the JSON answer (see the top).
 *
 * @param array<string, int> $after
 */
function adminPictureExportBatch(PDO $db, array $after): array
{
    $started = microtime(true);
    $rows = [];
    $failed = [];
    $bytes = 0;
    $handled = 0;
    $isFinished = true;

    foreach (ADMIN_PICTURE_EXPORT_COLUMNS as $folder => [$idColumn, $fileColumn, $keyColumn]) {
        // Only the ids and sizes: each BLOB is read on its own, when it's its turn
        $statement = $db->prepare('SELECT `' . $idColumn . '` AS id, COALESCE(LENGTH(`' . $fileColumn . '`), 0) AS bytes
            FROM `' . HBHUB_PICTURE_FILES_TABLES[$folder][0] . '`
            WHERE `' . $keyColumn . '` IS NULL AND `' . $idColumn . '` > ?
            ORDER BY `' . $idColumn . '` LIMIT ' . (ADMIN_PICTURE_EXPORT_ROWS + 1));
        $statement->execute([$after[$folder]]);
        foreach ($statement->fetchAll() as $candidate) {
            // The batch is full: the next request goes on from here. The first row always goes, however big it is.
            if ($handled >= ADMIN_PICTURE_EXPORT_ROWS
                || ($handled > 0 && $bytes + (int) $candidate['bytes'] > ADMIN_PICTURE_EXPORT_BYTES)
                || microtime(true) - $started > ADMIN_PICTURE_EXPORT_SECONDS) {
                $isFinished = false;
                break 2;
            }

            $id = (int) $candidate['id'];
            try {
                $result = adminPictureExportRow($db, $folder, $id);
            } catch (Throwable $e) {
                error_log('Picture export: ' . $folder . ' ' . $id . ' failed: ' . $e->getMessage());
                $result = 'something went wrong (see the server\'s error log)';
            }
            $handled++;
            $after[$folder] = $id;
            if (is_string($result)) {
                $failed[] = ['table' => $folder, 'id' => $id, 'error' => $result];
                adminPictureExportLog($folder . ' ' . $id . ' FAILED: ' . $result);
            } elseif ($result > 0) {
                $rows[] = ['table' => $folder, 'id' => $id, 'bytes' => $result];
                $bytes += $result;
                adminPictureExportLog($folder . ' ' . $id . ' ok, ' . $result . ' bytes');
            } else {
                adminPictureExportLog($folder . ' ' . $id . ' skipped, already exported or deleted');
            }
        }
    }

    $totals = adminPictureExportTotals($db);
    adminPictureExportLog('batch done: ' . count($rows) . ' exported, ' . count($failed) . ' failed, ' . $bytes . ' bytes, '
        . ($totals[HBHUB_PICTURE_FILES_PICTURES]['left'] + $totals[HBHUB_PICTURE_FILES_BANNED]['left']) . ' left'
        . ($isFinished ? ', reached the end' : ''));

    return [
        'rows' => $rows,
        'failed' => $failed,
        'bytes' => $bytes,
        'afterPictures' => $after[HBHUB_PICTURE_FILES_PICTURES],
        'afterBanned' => $after[HBHUB_PICTURE_FILES_BANNED],
        'isFinished' => $isFinished,
        'totals' => $totals,
    ];
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (($_POST['action'] ?? null) !== 'export') {
        http_response_code(400);
        echo json_encode(['error' => 'unknownAction']);
        exit;
    }
    $afterId = fn (string $name): int => is_string($_POST[$name] ?? null) && ctype_digit($_POST[$name]) && strlen($_POST[$name]) <= 10
        ? (int) $_POST[$name] : 0;
    echo json_encode(adminPictureExportBatch($db, [
        HBHUB_PICTURE_FILES_PICTURES => $afterId('afterPictures'),
        HBHUB_PICTURE_FILES_BANNED => $afterId('afterBanned'),
    ]));
    exit;
}

$adminTotals = adminPictureExportTotals($db);
$adminLeft = $adminTotals[HBHUB_PICTURE_FILES_PICTURES]['left'] + $adminTotals[HBHUB_PICTURE_FILES_BANNED]['left'];
$adminCanWrite = hbHubPictureFilesDir(HBHUB_PICTURE_FILES_PICTURES) !== null && hbHubPictureFilesDir(HBHUB_PICTURE_FILES_BANNED) !== null;
$adminTables = [
    HBHUB_PICTURE_FILES_PICTURES => 'Profile pictures and group icons',
    HBHUB_PICTURE_FILES_BANNED => 'Banned pictures',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="/assets/js/adminPictureExport.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/adminPictureExport.js') ?>" defer></script>
    <title>Picture export - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <!-- The lists' menu, with none of them marked: this page isn't one of them -->
            <?= adminListsMenuHtml($db, '') ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <section class="settings-card" id="export" aria-labelledby="admin-export-heading" data-picture-export>
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-export-heading">Move pictures from the database to disk</h2>
                            <p class="settings-intro">
                                Profile pictures, group icons and banned pictures are files on disk now. The ones from before are
                                still in the database, and this writes them out, a batch at a time, until none are left. Each file
                                is checked against the picture in the database before its row points at it, and the database's own
                                copy stays until the columns are dropped, so nothing is lost if it stops halfway: just start it
                                again. The site works as usual while it runs.
                            </p>
                        </div>
                    </div>

                    <?php if (!$adminCanWrite): ?>
                        <p class="settings-error" role="alert">The folders for the pictures can't be made in the attachments folder (attachments_path in the config). Nothing can be exported until they can.</p>
                    <?php endif; ?>

                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr><th scope="col">Pictures</th><th scope="col">On disk</th><th scope="col">Left in the database</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adminTables as $folder => $label): ?>
                                    <tr>
                                        <th scope="row"><?= $label ?></th>
                                        <td data-export-done="<?= $folder ?>"><?= number_format($adminTotals[$folder]['done']) ?></td>
                                        <td data-export-left="<?= $folder ?>"><?= number_format($adminTotals[$folder]['left']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="settings-hint" role="status" data-export-status>
                        <?= $adminLeft === 0 ? 'Every picture is on disk. 0 left.' : number_format($adminLeft) . ' left to export.' ?>
                    </p>
                    <p class="settings-hint" data-export-run hidden>
                        This run: <span data-export-rows>0</span> exported, <span data-export-failed-count>0</span> failed,
                        <span data-export-bytes>0 KB</span> written.
                    </p>

                    <div class="settings-actions">
                        <button type="button" class="settings-btn settings-btn-primary" data-export-start<?= $adminLeft === 0 || !$adminCanWrite ? ' disabled' : '' ?>>Export the pictures</button>
                    </div>
                    <noscript><p class="settings-hint">This page needs JavaScript to run the export.</p></noscript>

                    <!-- The rows that failed, filled in by adminPictureExport.js -->
                    <div data-export-failures hidden>
                        <h3>Failed</h3>
                        <p class="settings-hint">These are still served from the database. Press the button again to try them again.</p>
                        <ul class="admin-small" data-export-failed-list></ul>
                    </div>

                    <p class="settings-hint">Every row is also logged in <?= htmlspecialchars(ADMIN_PICTURE_EXPORT_LOG) ?> in the attachments folder.</p>
                </section>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
