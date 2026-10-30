<?php
// Ore Mining Inc.'s Cosmic Engine, in the database: the machine the whole site builds together (the stages, perks and
// what players can give are rules, so they're in miner.php with the rest; this is where the parts go).
//
// - HBHub-MinerEngines: one row per Engine (only #1 for now, made by hbHubMinerEngineRow() the first time it's needed):
//   how many stages are done (engineStage), the parts in the stage being built (engineStageParts), all parts ever given
//   (engineParts), the admin's own stage costs (engineStageCosts, JSON {stage: cost}, see hbHubMinerEngineCosts()),
//   when each stage was done (engineStageFinished, JSON {stage: 'Y-m-d H:i:s' UTC}), when the last stage (Ignition)
//   has to be full by (engineCountdownTimestamp) and when it was (engineIgnitedTimestamp).
// - HBHub-MinerContributions: who gave how many parts, from what (HBHUB_MINER_ENGINE_SOURCE_*), into which stage and
//   when: one row per source per save. Shown to everyone in the game's Engine view and on the leaderboard.
// - HBHub-MinerAnomalyTimes: each player's best time for each Anomaly (or stack) they've finished, and for each week's
//   weekly Anomaly they finished that week (hbHubMinerAnomalyTimesSave()), for the leaderboard's Anomaly tabs and the
//   weekly lock. The save has the player's own best times too ('anomalies'); this is so they can be listed together.
//
// hbHubMinerSync() (miner.php) puts in what a save gave with hbHubMinerEngineGive(), with the Engine's row locked, and
// a stage is done as soon as it has its parts and its lock is met (hbHubMinerEngineFinish()), which notifies everyone
// who plays (miner.engineStage, notificationTypes/miner.php). Ignition's countdown has no timer: the first save after
// it runs out drains it (hbHubMinerEngineDrain()). The admin panel changes the costs (hbHubMinerEngineSetCosts(),
// admin/engine.php).
require_once __DIR__ . '/../../database.php';

const HBHUB_MINER_ENGINE_TOP = 10; // contributors in the Engine view
const HBHUB_MINER_ENGINE_FEED = 15; // the latest parts given, in the Engine view
const HBHUB_MINER_ENGINE_COST_MAX = 10000000; // the most the admin panel lets a stage cost
const HBHUB_MINER_ENGINE_STATUS_BANNED = 4; // userStatus in HBHub-Users

/**
 * The Engine being built (the newest row), made if there isn't one yet. $lock: FOR UPDATE, to change it.
 */
function hbHubMinerEngineRow(PDO $db, bool $lock): array
{
    $sql = 'SELECT engineId, engineNumber, engineStage, engineStageParts, engineParts, engineStageCosts, engineStageFinished,
            engineStartedTimestamp, engineCountdownTimestamp, engineIgnitedTimestamp
        FROM `HBHub-MinerEngines` ORDER BY engineId DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $engine = $db->query($sql)->fetch();
    if ($engine === false) {
        // Only ever once. Looked for first, as an INSERT IGNORE on every save would lock the row for each of them.
        $db->exec('INSERT IGNORE INTO `HBHub-MinerEngines` (engineId, engineNumber, engineStartedTimestamp) VALUES (1, 1, UTC_TIMESTAMP())');
        $engine = $db->query($sql)->fetch();
    }
    return $engine;
}

/**
 * Each stage's cost in parts, stage 1 first: the admin panel's where it set one, otherwise the one in
 * HBHUB_MINER_ENGINE_STAGES.
 *
 * @return list<int>
 */
function hbHubMinerEngineCosts(array $engine): array
{
    $own = $engine['engineStageCosts'] !== null ? json_decode($engine['engineStageCosts'], true) : null;
    $costs = [];
    foreach (HBHUB_MINER_ENGINE_STAGES as $index => $stage) {
        $cost = is_array($own) ? ($own[$index + 1] ?? null) : null;
        $costs[] = is_int($cost) && $cost > 0 ? $cost : $stage['cost'];
    }
    return $costs;
}

/**
 * Whether the Engine takes parts: not when every stage is done, nor while a full stage waits for a lock that isn't a
 * number of contributors (see HBHUB_MINER_ENGINE_STAGES). A full stage whose lock is met is never left waiting, as
 * hbHubMinerEngineFinish() finishes it straight away.
 */
function hbHubMinerEngineOpen(array $engine): bool
{
    $done = (int) $engine['engineStage'];
    if ($done >= count(HBHUB_MINER_ENGINE_STAGES)) {
        return false;
    }
    $lock = HBHUB_MINER_ENGINE_STAGES[$done]['lock'];
    return $lock === null || in_array($lock['type'], ['contributors', 'section'], true)
        || (int) $engine['engineStageParts'] < hbHubMinerEngineCosts($engine)[$done];
}

/**
 * A UTC database time as Unix ms, or 0 for none.
 */
function hbHubMinerEngineMsOrZero(?string $timestamp): int
{
    return $timestamp !== null ? hbHubMinerEngineMs($timestamp) : 0;
}

/**
 * Gives the save what the Engine is now: its stages done (the perks), whether it takes parts, when it was ignited and
 * whether $userId gave parts to its Ignition (looked up once, after it's ignited, until it's true). $canGive is false
 * for guests, who can't give anything, and have no $userId.
 */
function hbHubMinerEngineAdopt(array &$state, array $engine, bool $canGive, ?PDO $db = null, ?int $userId = null): void
{
    $state['engineStage'] = min((int) $engine['engineStage'], count(HBHUB_MINER_ENGINE_STAGES));
    $state['engineOpen'] = $canGive && hbHubMinerEngineOpen($engine);
    $state['engineIgnited'] = hbHubMinerEngineMsOrZero($engine['engineIgnitedTimestamp']);
    if ($state['engineIgnited'] > 0 && !$state['ignitionCrew'] && $db !== null && $userId !== null) {
        // Before it was ignited: Anomaly parts given after it go in as stage 25 too, waiting for Engine #2
        $statement = $db->prepare('SELECT 1 FROM `HBHub-MinerContributions`
            WHERE contributionEngineId = ? AND contributionUserId = ? AND contributionStage = ? AND contributionTimestamp <= ? LIMIT 1');
        $statement->execute([(int) $engine['engineId'], $userId, count(HBHUB_MINER_ENGINE_STAGES), $engine['engineIgnitedTimestamp']]);
        $state['ignitionCrew'] = $statement->fetchColumn() !== false;
    }
    if ($state['anomaly'] !== null) { // the perks work in there too, but nothing's given from it (see hbHubMinerAnomalyStart())
        $state['anomaly']['state']['engineStage'] = $state['engineStage'];
        $state['anomaly']['state']['engineIgnited'] = $state['engineIgnited'];
    }
}

/**
 * The players who have finished $anomalyId for this Engine, alone or in a stack: their user ids.
 *
 * @return list<int>
 */
function hbHubMinerEngineFinishers(PDO $db, array $engine, string $anomalyId): array
{
    $statement = $db->prepare('SELECT DISTINCT contributionUserId FROM `HBHub-MinerContributions`
        WHERE contributionEngineId = ? AND contributionSource = ? AND FIND_IN_SET(?, REPLACE(contributionAnomaly, \'+\', \',\')) > 0');
    $statement->execute([(int) $engine['engineId'], HBHUB_MINER_ENGINE_SOURCE_ANOMALY, $anomalyId]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * How far stage $index (0 for stage 1) is with its lock: ['have' => n, 'need' => n], or null when it has none (see
 * HBHUB_MINER_ENGINE_STAGES for what each kind counts). For an each lock, have is the fewest players any of the tier's
 * Anomalies has; for a weekly one, the most players who finished the weekly Anomaly in one week since the Engine began.
 */
function hbHubMinerEngineLock(PDO $db, array $engine, int $index): ?array
{
    $lock = HBHUB_MINER_ENGINE_STAGES[$index]['lock'] ?? null;
    if ($lock === null) {
        return null;
    }
    $engineId = (int) $engine['engineId'];
    $tierIds = isset($lock['tier'])
        ? array_keys(array_filter(HBHUB_MINER_ANOMALIES, static fn (array $anomaly): bool => $anomaly['tier'] === $lock['tier'])) : [];
    switch ($lock['type']) {
        case 'contributors':
        case 'section':
            // section: the stages of this stage's section, by the stage that was being built when the parts went in
            $stages = $lock['type'] === 'section' ? array_keys(array_filter(HBHUB_MINER_ENGINE_STAGES,
                static fn (array $stage): bool => $stage['section'] === HBHUB_MINER_ENGINE_STAGES[$index]['section'])) : [];
            $statement = $db->prepare('SELECT COUNT(DISTINCT contributionUserId) FROM `HBHub-MinerContributions` WHERE contributionEngineId = ?'
                . ($stages !== [] ? ' AND contributionStage BETWEEN ? AND ?' : ''));
            $statement->execute($stages !== [] ? [$engineId, min($stages) + 1, max($stages) + 1] : [$engineId]);
            $have = (int) $statement->fetchColumn();
            break;
        case 'anomalies':
            $have = count(array_filter($tierIds, static fn (string $id): bool => hbHubMinerEngineFinishers($db, $engine, $id) !== []));
            break;
        case 'players': // who has finished every one of them
            $players = null;
            foreach ($lock['anomalies'] as $id) {
                $finishers = hbHubMinerEngineFinishers($db, $engine, $id);
                $players = $players === null ? $finishers : array_values(array_intersect($players, $finishers));
            }
            $have = count($players ?? []);
            break;
        case 'each':
            $have = min(array_map(static fn (string $id): int => count(hbHubMinerEngineFinishers($db, $engine, $id)), $tierIds));
            break;
        case 'stack':
            $statement = $db->prepare('SELECT COALESCE(MAX(LENGTH(contributionAnomaly) - LENGTH(REPLACE(contributionAnomaly, \'+\', \'\')) + 1), 0)
                FROM `HBHub-MinerContributions` WHERE contributionEngineId = ? AND contributionSource = ?');
            $statement->execute([$engineId, HBHUB_MINER_ENGINE_SOURCE_ANOMALY]);
            $have = (int) $statement->fetchColumn();
            break;
        case 'weekly':
            $statement = $db->prepare('SELECT COALESCE(MAX(players), 0) FROM (SELECT COUNT(*) AS players FROM `HBHub-MinerAnomalyTimes`
                WHERE timeWeek >= ? GROUP BY timeWeek) weeks');
            $statement->execute([max(1, hbHubMinerWeek(hbHubMinerEngineMs($engine['engineStartedTimestamp'])))]);
            $have = (int) $statement->fetchColumn();
            break;
        default:
            $have = 0;
    }
    return ['have' => $have, 'need' => $lock['count']];
}

/**
 * Whether Ignition's time has run out (the stage before it done, it not yet full, the countdown passed).
 */
function hbHubMinerEngineDrainDue(array $engine): bool
{
    return (int) $engine['engineStage'] === count(HBHUB_MINER_ENGINE_STAGES) - 1 && $engine['engineCountdownTimestamp'] !== null
        && hbHubMinerEngineMs($engine['engineCountdownTimestamp']) <= hbHubMinerNow();
}

/**
 * Ignition's time ran out: HBHUB_MINER_ENGINE_DRAIN of its parts are kept and it gets another
 * HBHUB_MINER_ENGINE_COUNTDOWN_MS, once for each time that's passed since. Done on the first save after (there's no
 * timer on shared hosting). $engine must have been read with its row locked, inside a transaction.
 */
function hbHubMinerEngineDrain(PDO $db, array &$engine): void
{
    $deadline = hbHubMinerEngineMs($engine['engineCountdownTimestamp']);
    $parts = (int) $engine['engineStageParts'];
    while ($deadline <= hbHubMinerNow()) {
        $parts = (int) floor($parts * HBHUB_MINER_ENGINE_DRAIN);
        $deadline += HBHUB_MINER_ENGINE_COUNTDOWN_MS;
    }
    $engine['engineStageParts'] = $parts;
    $engine['engineCountdownTimestamp'] = gmdate('Y-m-d H:i:s', intdiv($deadline, 1000));
    $db->prepare('UPDATE `HBHub-MinerEngines` SET engineStageParts = ?, engineCountdownTimestamp = ? WHERE engineId = ?')
        ->execute([$parts, $engine['engineCountdownTimestamp'], (int) $engine['engineId']]);
}

/**
 * Puts the Anomalies a save finished (hbHubMinerReplay()'s 'given' rows from Anomalies, which have the key, the ms it
 * took and the week when it was the weekly one) in HBHub-MinerAnomalyTimes: the best time and how many times for each
 * (timeWeek 0), and again for the week when it was that week's weekly Anomaly.
 */
function hbHubMinerAnomalyTimesSave(PDO $db, int $userId, array $given): void
{
    $insert = $db->prepare('INSERT INTO `HBHub-MinerAnomalyTimes` (timeUserId, timeAnomaly, timeWeek, timeBest, timeDone, timeUpdatedTimestamp)
        VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE timeBest = LEAST(timeBest, VALUES(timeBest)), timeDone = timeDone + 1, timeUpdatedTimestamp = UTC_TIMESTAMP()');
    foreach ($given as $gift) {
        if ($gift[0] !== HBHUB_MINER_ENGINE_SOURCE_ANOMALY || !isset($gift[3])) {
            continue;
        }
        foreach (array_unique([0, (int) ($gift[4] ?? 0)]) as $week) {
            $insert->execute([$userId, $gift[2], $week, (int) $gift[3]]);
        }
    }
}

/**
 * Puts what a save gave (hbHubMinerReplay()'s 'given', [source, parts] each, with the Anomaly's key third for
 * Anomalies) into the Engine, one row per source (and Anomaly), and finishes the stages that are done now. Parts from
 * Anomalies go in even while the Engine is waiting for an Anomaly lock (it's how the lock is met), and after the last
 * stage there is so far, where they wait for the next. $engine must have been read with its row locked, inside a
 * transaction.
 */
function hbHubMinerEngineGive(PDO $db, array &$engine, int $userId, array $given): void
{
    $rows = [];
    foreach ($given as $gift) {
        $key = $gift[0] . '|' . ($gift[2] ?? '');
        $rows[$key] ??= [$gift[0], 0, $gift[2] ?? null];
        $rows[$key][1] += $gift[1];
    }
    $stage = min((int) $engine['engineStage'] + 1, count(HBHUB_MINER_ENGINE_STAGES));
    $insert = $db->prepare('INSERT INTO `HBHub-MinerContributions` (contributionEngineId, contributionUserId, contributionStage, contributionParts,
            contributionSource, contributionAnomaly, contributionTimestamp)
        VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
    foreach ($rows as [$source, $parts, $anomaly]) {
        $insert->execute([(int) $engine['engineId'], $userId, $stage, $parts, $source, $anomaly]);
        $engine['engineStageParts'] = (int) $engine['engineStageParts'] + $parts;
        $engine['engineParts'] = (int) $engine['engineParts'] + $parts;
    }
    hbHubMinerEngineFinish($db, $engine);
}

/**
 * Finishes every stage in a row that has its parts and its lock met (what's over goes on to the next one), notifies
 * everyone who plays about each, and writes the Engine back. The stage before Ignition starts its countdown, and
 * Ignition itself ignites the Engine. Returns the stages finished. $engine must have been read with its row locked,
 * inside a transaction.
 *
 * @return list<int>
 */
function hbHubMinerEngineFinish(PDO $db, array &$engine): array
{
    $costs = hbHubMinerEngineCosts($engine);
    $times = $engine['engineStageFinished'] !== null ? (json_decode($engine['engineStageFinished'], true) ?: []) : [];
    $finished = [];
    while (($done = (int) $engine['engineStage']) < count(HBHUB_MINER_ENGINE_STAGES) && (int) $engine['engineStageParts'] >= $costs[$done]) {
        $lock = hbHubMinerEngineLock($db, $engine, $done);
        if ($lock !== null && $lock['have'] < $lock['need']) {
            break;
        }
        $engine['engineStageParts'] = (int) $engine['engineStageParts'] - $costs[$done];
        $engine['engineStage'] = $done + 1;
        $times[$done + 1] = gmdate('Y-m-d H:i:s');
        $finished[] = $done + 1;
        if ($done + 1 === count(HBHUB_MINER_ENGINE_STAGES) - 1) {
            $engine['engineCountdownTimestamp'] = gmdate('Y-m-d H:i:s', intdiv(hbHubMinerNow() + HBHUB_MINER_ENGINE_COUNTDOWN_MS, 1000));
        } elseif ($done + 1 === count(HBHUB_MINER_ENGINE_STAGES)) {
            $engine['engineCountdownTimestamp'] = null;
            $engine['engineIgnitedTimestamp'] = gmdate('Y-m-d H:i:s');
        }
        hbHubMinerEngineAnnounce($db, $engine, $done + 1);
    }
    $engine['engineStageFinished'] = $times !== [] ? json_encode($times, JSON_FORCE_OBJECT) : null;

    $db->prepare('UPDATE `HBHub-MinerEngines` SET engineStage = ?, engineStageParts = ?, engineParts = ?, engineStageFinished = ?,
            engineCountdownTimestamp = ?, engineIgnitedTimestamp = ?
        WHERE engineId = ?')
        ->execute([(int) $engine['engineStage'], (int) $engine['engineStageParts'], (int) $engine['engineParts'],
            $engine['engineStageFinished'], $engine['engineCountdownTimestamp'], $engine['engineIgnitedTimestamp'], (int) $engine['engineId']]);
    return $finished;
}

/**
 * Tells everyone who plays (has made a Warp Jump, so can give parts), apart from banned and deleted accounts, that
 * stage $stage is done: miner.engineStage, in one INSERT however many there are. The data has the stage's name and
 * perk, so the notification still says the same if they're changed later.
 */
function hbHubMinerEngineAnnounce(PDO $db, array $engine, int $stage): void
{
    $data = HBHUB_MINER_ENGINE_STAGES[$stage - 1];
    $db->prepare('INSERT INTO `HBHub-Notifications` (userId, notificationType, notificationByUserId, notificationData)
        SELECT s.userId, ?, NULL, ?
        FROM `HBHub-MinerSaves` s
        JOIN `HBHub-Users` u ON u.userId = s.userId
        WHERE s.saveWarps > 0 AND u.userStatus IS NOT NULL AND u.userStatus <> ?')
        ->execute(['miner.engineStage', json_encode(['engine' => (int) $engine['engineNumber'], 'stage' => $stage, 'name' => $data['name'],
            'perk' => $data['perk']['text']]), HBHUB_MINER_ENGINE_STATUS_BANNED]);
}

/**
 * Sets the stages' costs from the admin panel ($costs: stage => parts; a stage left out, or set to what
 * HBHUB_MINER_ENGINE_STAGES has, uses that one) and finishes any stage that has its parts now. Returns the stages
 * finished.
 *
 * @param array<int, int> $costs
 * @return list<int>
 */
function hbHubMinerEngineSetCosts(PDO $db, array $costs): array
{
    $db->beginTransaction();
    try {
        $engine = hbHubMinerEngineRow($db, true);
        $own = [];
        foreach (HBHUB_MINER_ENGINE_STAGES as $index => $stage) {
            $cost = $costs[$index + 1] ?? null;
            if (is_int($cost) && $cost > 0 && $cost !== $stage['cost']) {
                $own[$index + 1] = min($cost, HBHUB_MINER_ENGINE_COST_MAX);
            }
        }
        $engine['engineStageCosts'] = $own !== [] ? json_encode($own, JSON_FORCE_OBJECT) : null;
        $db->prepare('UPDATE `HBHub-MinerEngines` SET engineStageCosts = ? WHERE engineId = ?')
            ->execute([$engine['engineStageCosts'], (int) $engine['engineId']]);
        $finished = hbHubMinerEngineFinish($db, $engine);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return $finished;
}

/**
 * A UTC database time as Unix ms, for the script.
 */
function hbHubMinerEngineMs(string $timestamp): int
{
    return strtotime($timestamp . ' UTC') * 1000;
}

/**
 * The Engine for the page (the save's answer, spaceOreMiner.js's Engine view): number, done (stages), parts (in the
 * stage being built), total (parts ever), costs (each stage's, stage 1 first), finished ({stage: Unix ms}), open
 * (whether it takes parts), lock (hbHubMinerEngineLock() for the stage being built), countdown (when Ignition has to
 * be full by, Unix ms, or 0) and ignited (when it was, or 0). With $details also top (the
 * HBHUB_MINER_ENGINE_TOP who gave the most: [{name, parts, you}]), feed (the latest HBHUB_MINER_ENGINE_FEED: [{name,
 * parts, source, anomaly (its key, or null), at}]) and mine (the parts $userId gave, 0 for a guest). Banned players' parts count, but they're
 * not shown.
 */
function hbHubMinerEngineSummary(PDO $db, array $engine, ?int $userId, bool $details): array
{
    $done = (int) $engine['engineStage'];
    $finished = [];
    foreach (($engine['engineStageFinished'] !== null ? json_decode($engine['engineStageFinished'], true) : null) ?: [] as $stage => $time) {
        $finished[(string) $stage] = hbHubMinerEngineMs($time);
    }
    $summary = [
        'number' => (int) $engine['engineNumber'],
        'done' => $done,
        'parts' => (int) $engine['engineStageParts'],
        'total' => (int) $engine['engineParts'],
        'costs' => hbHubMinerEngineCosts($engine),
        'finished' => (object) $finished,
        'open' => hbHubMinerEngineOpen($engine),
        'lock' => $done < count(HBHUB_MINER_ENGINE_STAGES) ? hbHubMinerEngineLock($db, $engine, $done) : null,
        'countdown' => hbHubMinerEngineMsOrZero($engine['engineCountdownTimestamp']),
        'ignited' => hbHubMinerEngineMsOrZero($engine['engineIgnitedTimestamp']),
    ];
    if (!$details) {
        return $summary;
    }

    $engineId = (int) $engine['engineId'];
    $statement = $db->prepare('SELECT c.contributionUserId, u.userName, SUM(c.contributionParts) AS parts
        FROM `HBHub-MinerContributions` c
        JOIN `HBHub-Users` u ON u.userId = c.contributionUserId
        WHERE c.contributionEngineId = ? AND (u.userStatus IS NULL OR u.userStatus <> ?)
        GROUP BY c.contributionUserId, u.userName
        ORDER BY parts DESC, MIN(c.contributionId)
        LIMIT ' . HBHUB_MINER_ENGINE_TOP);
    $statement->execute([$engineId, HBHUB_MINER_ENGINE_STATUS_BANNED]);
    $summary['top'] = array_map(static fn (array $row): array => [
        'name' => $row['userName'],
        'parts' => (int) $row['parts'],
        'you' => (int) $row['contributionUserId'] === $userId,
    ], $statement->fetchAll());

    $statement = $db->prepare('SELECT u.userName, c.contributionParts, c.contributionSource, c.contributionAnomaly, c.contributionTimestamp
        FROM `HBHub-MinerContributions` c
        JOIN `HBHub-Users` u ON u.userId = c.contributionUserId
        WHERE c.contributionEngineId = ? AND (u.userStatus IS NULL OR u.userStatus <> ?)
        ORDER BY c.contributionId DESC
        LIMIT ' . HBHUB_MINER_ENGINE_FEED);
    $statement->execute([$engineId, HBHUB_MINER_ENGINE_STATUS_BANNED]);
    $summary['feed'] = array_map(static fn (array $row): array => [
        'name' => $row['userName'],
        'parts' => (int) $row['contributionParts'],
        'source' => (int) $row['contributionSource'],
        'anomaly' => $row['contributionAnomaly'],
        'at' => hbHubMinerEngineMs($row['contributionTimestamp']),
    ], $statement->fetchAll());

    $mine = 0;
    if ($userId !== null) {
        $statement = $db->prepare('SELECT COALESCE(SUM(contributionParts), 0) FROM `HBHub-MinerContributions`
            WHERE contributionEngineId = ? AND contributionUserId = ?');
        $statement->execute([$engineId, $userId]);
        $mine = (int) $statement->fetchColumn();
    }
    $summary['mine'] = $mine;
    return $summary;
}

/**
 * Whether $userId has the Engine Crew badge (the Crew Deck perk): they've given parts to an Engine that has its
 * badge stage done.
 */
function hbHubMinerEngineCrew(PDO $db, int $userId): bool
{
    $badgeStage = 0;
    foreach (HBHUB_MINER_ENGINE_STAGES as $index => $stage) {
        if ($stage['perk']['type'] === 'badge') {
            $badgeStage = $index + 1;
            break;
        }
    }
    $statement = $db->prepare('SELECT 1 FROM `HBHub-MinerContributions` c
        JOIN `HBHub-MinerEngines` e ON e.engineId = c.contributionEngineId
        WHERE c.contributionUserId = ? AND e.engineStage >= ? LIMIT 1');
    $statement->execute([$userId, $badgeStage]);
    return $statement->fetchColumn() !== false;
}

/**
 * Whether $userId has the Ignition badge: they gave parts to the Ignition stage of an Engine before it was ignited.
 */
function hbHubMinerEngineIgnitionCrew(PDO $db, int $userId): bool
{
    $statement = $db->prepare('SELECT 1 FROM `HBHub-MinerContributions` c
        JOIN `HBHub-MinerEngines` e ON e.engineId = c.contributionEngineId
        WHERE c.contributionUserId = ? AND c.contributionStage = ? AND e.engineIgnitedTimestamp IS NOT NULL
            AND c.contributionTimestamp <= e.engineIgnitedTimestamp
        LIMIT 1');
    $statement->execute([$userId, count(HBHUB_MINER_ENGINE_STAGES)]);
    return $statement->fetchColumn() !== false;
}

/**
 * The leaderboard titles of $userIds (HBHUB_MINER_TITLES): user id => the name of the first one each has, for the
 * ones who have one. An Anomaly's counts once it's finished alone or in a stack (HBHub-MinerAnomalyTimes); Engineer
 * once they've given parts to an Engine with its title stage done.
 *
 * @param list<int> $userIds
 * @return array<int, string>
 */
function hbHubMinerTitles(PDO $db, array $userIds): array
{
    if ($userIds === []) {
        return [];
    }
    $in = implode(', ', array_fill(0, count($userIds), '?'));
    $has = [];
    foreach (HBHUB_MINER_TITLES as $titleId => $title) {
        if (isset($title['anomaly'])) {
            $statement = $db->prepare('SELECT DISTINCT timeUserId FROM `HBHub-MinerAnomalyTimes`
                WHERE timeWeek = 0 AND FIND_IN_SET(?, REPLACE(timeAnomaly, \'+\', \',\')) > 0 AND timeUserId IN (' . $in . ')');
            $statement->execute([$title['anomaly'], ...$userIds]);
        } else {
            $titleStage = 0;
            foreach (HBHUB_MINER_ENGINE_STAGES as $index => $stage) {
                if ($stage['perk']['type'] === 'title') {
                    $titleStage = $index + 1;
                }
            }
            $statement = $db->prepare('SELECT DISTINCT c.contributionUserId FROM `HBHub-MinerContributions` c
                JOIN `HBHub-MinerEngines` e ON e.engineId = c.contributionEngineId
                WHERE e.engineStage >= ? AND c.contributionUserId IN (' . $in . ')');
            $statement->execute([$titleStage, ...$userIds]);
        }
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $has[(int) $userId] ??= $title['name'];
        }
    }
    return $has;
}
