<?php
// One person's accounts: every account that's been used in the same browser as a given one, by the hbHubDevice cookie
// (HBHub-LoginDevices, loginLimit.php) or by the browser fingerprint (HBHub-DeviceFingerprints, fingerprint.php), and
// the ones those have been used with, and so on. The same rules as the admin panel's people (adminPersonGroups() in
// admin.php, which has the long explanation), so a fingerprint seen with more than HBHUB_FINGERPRINT_SHARED_MAX
// accounts (a room of identical school computers) links no one. That one works out every person on the site at once
// for the admin pages; this one only walks out from one account, so it's cheap enough for the casino's daily chips
// (casino/wallet.php), which ask on every nav poll. Both give the same accounts for the same person.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/fingerprint.php'; // HBHUB_FINGERPRINT_SHARED_MAX

const HBHUB_PERSON_MAX_STEPS = 20; // how many rounds outwards at most, so a strange chain of links can't keep it busy

/**
 * The ids of every account of the person $userId is one of, lowest first, $userId included. Just [$userId] when it's
 * the only one.
 *
 * @return list<int>
 */
function hbHubPersonUserIds(PDO $db, int $userId): array
{
    $found = [$userId => true];
    $frontier = [$userId];

    // Each round finds the accounts that share a known device or a linking fingerprint with the ones found last round
    for ($step = 0; $step < HBHUB_PERSON_MAX_STEPS && $frontier !== []; $step++) {
        $in = implode(',', array_fill(0, count($frontier), '?'));
        $statement = $db->prepare('SELECT b.userId FROM `HBHub-LoginDevices` a
                JOIN `HBHub-LoginDevices` b ON b.deviceTokenHash = a.deviceTokenHash
                WHERE a.userId IN (' . $in . ')
            UNION
            SELECT b.userId FROM `HBHub-DeviceFingerprints` a
                JOIN `HBHub-DeviceFingerprints` b ON b.fingerprintHash = a.fingerprintHash
                WHERE a.userId IN (' . $in . ')
                    AND (SELECT COUNT(*) FROM `HBHub-DeviceFingerprints` c WHERE c.fingerprintHash = a.fingerprintHash) <= '
                    . HBHUB_FINGERPRINT_SHARED_MAX);
        $statement->execute([...$frontier, ...$frontier]);

        $frontier = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $otherId) {
            $otherId = (int) $otherId;
            if (!isset($found[$otherId])) {
                $found[$otherId] = true;
                $frontier[] = $otherId;
            }
        }
    }

    $userIds = array_keys($found);
    sort($userIds);
    return $userIds;
}
