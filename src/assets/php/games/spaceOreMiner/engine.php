<?php
// Ore Mining Inc.'s Cosmic Engines, in the database: the machines the whole site builds together, one after the other
// (the stages, perks and what players can give are rules, so they're in miner.php with the rest; this is where the
// parts go).
//
// - HBHub-MinerEngines: one row per Engine (#1 is made by hbHubMinerEngineRow() the first time it's needed, and each
//   next one by hbHubMinerEngineFinish() as the one before is done, if HBHUB_MINER_ENGINES has it). The newest row is
//   the one being built. Each has how many of its stages are done (engineStage, counted in that Engine only: the save's
//   engineStage counts every Engine's, see hbHubMinerEngineAdopt()), the parts in the stage being built
//   (engineStageParts), all parts ever given (engineParts), the admin's own stage costs (engineStageCosts, JSON
//   {stage: cost}, see hbHubMinerEngineCosts()), when each stage was done (engineStageFinished, JSON
//   {stage: 'Y-m-d H:i:s' UTC}), when the last stage has to be full by (engineCountdownTimestamp) and when it was
//   (engineIgnitedTimestamp).
// - HBHub-MinerContributions: who gave how many parts, from what (HBHUB_MINER_ENGINE_SOURCE_*), into which Engine and
//   stage and when, and for an Anomaly how long it took (contributionMs, for the time locks): one row per source per
//   save. Shown to everyone in the game's Engine view and on the leaderboard.
// - HBHub-MinerAnomalyTimes: each player's best time for each Anomaly (or stack) they've finished, and for each week's
//   weekly Anomaly they finished that week (hbHubMinerAnomalyTimesSave()), for the leaderboard's Anomaly tabs, the
//   weekly lock and the weekly medals. The save has the player's own best times too ('anomalies'); this is so they can
//   be listed together.
//
// hbHubMinerSync() (miner.php) puts in what a save gave with hbHubMinerEngineGive(), with the Engine's row locked, and
// a stage is done as soon as it has its parts and its lock is met (hbHubMinerEngineFinish()), which notifies everyone
// who plays (miner.engineStage, notificationTypes/miner.php). The last stage's countdown has no timer: the first save
// after it runs out drains it (hbHubMinerEngineDrain()). The admin panel changes the costs (hbHubMinerEngineSetCosts(),
// admin/engine.php).
require_once __DIR__ . '/../../database.php';

const HBHUB_MINER_ENGINE_TOP = 10; // contributors in the Project Starbound view
const HBHUB_MINER_ENGINE_FEED = 15; // the latest parts given, in the Project Starbound view
const HBHUB_MINER_ENGINE_HALL_TOP = 3; // who gave the most to each finished build, in the Starbound Hall
const HBHUB_MINER_ENGINE_COST_MAX = 10000000; // the most the admin panel lets a stage cost
const HBHUB_MINER_ENGINE_STATUS_BANNED = 4; // userStatus in HBHub-Users
const HBHUB_MINER_MEDALS = 3; // places with a weekly Anomaly medal: gold, silver, bronze

/**
 * The Engine being built (the newest row), made if there isn't one yet. $lock: FOR UPDATE, to change it. It also has
 * lastIgnitedNumber and lastIgnitedTimestamp: the last Engine that was ignited (it, or one before it), or null.
 */
function hbHubMinerEngineRow(PDO $db, bool $lock): array
{
    $sql = 'SELECT engineId, engineNumber, engineStage, engineStageParts, engineParts, engineStageCosts, engineStageFinished,
            engineStartedTimestamp, engineCountdownTimestamp, engineIgnitedTimestamp,
            (SELECT MAX(i.engineNumber) FROM `HBHub-MinerEngines` i WHERE i.engineIgnitedTimestamp IS NOT NULL) AS lastIgnitedNumber,
            (SELECT MAX(i.engineIgnitedTimestamp) FROM `HBHub-MinerEngines` i) AS lastIgnitedTimestamp
        FROM `HBHub-MinerEngines` ORDER BY engineId DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $engine = $db->query($sql)->fetch();
    if ($engine === false) {
        // Only ever once. Looked for first, as an INSERT IGNORE on every save would lock the row for each of them.
        $db->exec('INSERT IGNORE INTO `HBHub-MinerEngines` (engineId, engineNumber, engineStartedTimestamp) VALUES (1, 1, UTC_TIMESTAMP())');
        $engine = $db->query($sql)->fetch();
    }
    if ($engine['engineIgnitedTimestamp'] !== null && isset(HBHUB_MINER_ENGINES[(int) $engine['engineNumber'] + 1])) {
        // Done before its next Engine was added to the game (hbHubMinerEngineFinish() starts it otherwise): it starts
        // now, and the parts that waited for it stay in the one before (counted there, not lost)
        $db->prepare('INSERT IGNORE INTO `HBHub-MinerEngines` (engineNumber, engineStartedTimestamp) VALUES (?, UTC_TIMESTAMP())')
            ->execute([(int) $engine['engineNumber'] + 1]);
        $engine = $db->query($sql)->fetch();
    }
    return $engine;
}

/**
 * The stages of the Engine in $engine (a row), stage 1 first (hbHubMinerEngineStages()).
 */
function hbHubMinerEngineStagesOf(array $engine): array
{
    return hbHubMinerEngineStages((int) $engine['engineNumber']);
}

/**
 * How many stages are done of every Engine so far, the one in $engine (a row) and the ones before it: what a save's
 * engineStage is (hbHubMinerEngineAdopt()).
 */
function hbHubMinerEngineDoneAll(array $engine): int
{
    return ((int) $engine['engineNumber'] - 1) * HBHUB_MINER_ENGINE_STAGE_TOTAL + (int) $engine['engineStage'];
}

/**
 * Whether a stage with a perk of $type is done, for everyone (hbHubMinerEnginePerk() for the Engine being built).
 */
function hbHubMinerEnginePerkDone(PDO $db, string $type): bool
{
    return hbHubMinerEnginePerk(['engineStage' => hbHubMinerEngineDoneAll(hbHubMinerEngineRow($db, false))], $type);
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
    foreach (hbHubMinerEngineStagesOf($engine) as $index => $stage) {
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
    $stages = hbHubMinerEngineStagesOf($engine);
    $done = (int) $engine['engineStage'];
    if ($done >= count($stages)) {
        return false;
    }
    $lock = $stages[$done]['lock'];
    return $lock === null || in_array($lock['type'], ['contributors', 'section', 'source'], true)
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
 * Gives the save what the Engines are now: their stages done (the perks, counted over every Engine so far), whether
 * the one being built takes parts, when the last one was ignited, and (looked up once for each Engine done, after it's
 * done) whether $userId gave parts to its last stage. $canGive is false for guests, who can't give anything, and have
 * no $userId.
 */
function hbHubMinerEngineAdopt(array &$state, array $engine, bool $canGive, ?PDO $db = null, ?int $userId = null): void
{
    $state['engineStage'] = min(hbHubMinerEngineDoneAll($engine), count(HBHUB_MINER_ENGINE_STAGES));
    $state['engineOpen'] = $canGive && hbHubMinerEngineOpen($engine);
    $state['engineIgnited'] = hbHubMinerEngineMsOrZero($engine['lastIgnitedTimestamp']);
    $lastIgnited = (int) ($engine['lastIgnitedNumber'] ?? 0);
    if ($lastIgnited > $state['crewsChecked'] && $db !== null && $userId !== null) {
        foreach (hbHubMinerEngineCrews($db, $userId, $state['crewsChecked']) as $number) {
            $state['crews'][] = $number;
        }
        $state['crews'] = array_values(array_unique($state['crews']));
        $state['crewsChecked'] = $lastIgnited;
    }
    if ($state['anomaly'] !== null) { // the perks work in there too, but nothing's given from it (see hbHubMinerAnomalyStart())
        $state['anomaly']['state']['engineStage'] = $state['engineStage'];
        $state['anomaly']['state']['engineIgnited'] = $state['engineIgnited'];
    }
}

/**
 * The Engines (their numbers) after Engine $after that $userId was the crew of: they gave parts to its last stage
 * before it was done. Parts given after that (from Anomalies, while there's no next Engine yet) don't count.
 *
 * @return list<int>
 */
function hbHubMinerEngineCrews(PDO $db, int $userId, int $after = 0): array
{
    $statement = $db->prepare('SELECT DISTINCT e.engineNumber FROM `HBHub-MinerContributions` c
        JOIN `HBHub-MinerEngines` e ON e.engineId = c.contributionEngineId
        WHERE c.contributionUserId = ? AND c.contributionStage = ? AND e.engineIgnitedTimestamp IS NOT NULL
            AND c.contributionTimestamp <= e.engineIgnitedTimestamp AND e.engineNumber > ?
        ORDER BY e.engineNumber');
    $statement->execute([$userId, HBHUB_MINER_ENGINE_STAGE_TOTAL, $after]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
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
 * How far stage $index (0 for stage 1) of this Engine is with its lock: ['have' => n, 'need' => n], or null when it has
 * none (see HBHUB_MINER_ENGINE_STAGES for what each kind counts). For an each lock, have is the fewest players any of
 * the tier's Anomalies has; for a weekly one, the most players who finished the weekly Anomaly in one week since the
 * Engine began.
 */
function hbHubMinerEngineLock(PDO $db, array $engine, int $index): ?array
{
    $stages = hbHubMinerEngineStagesOf($engine);
    $lock = $stages[$index]['lock'] ?? null;
    if ($lock === null) {
        return null;
    }
    $engineId = (int) $engine['engineId'];
    $tierIds = isset($lock['tier'])
        ? array_keys(array_filter(HBHUB_MINER_ANOMALIES, static fn (array $anomaly): bool => $anomaly['tier'] === $lock['tier'])) : [];
    // The stages of this stage's section (1 for the first), by the stage that was being built when the parts went in
    $section = array_keys(array_filter($stages, static fn (array $stage): bool => $stage['section'] === $stages[$index]['section']));
    $sectionRange = [min($section) + 1, max($section) + 1];
    switch ($lock['type']) {
        case 'contributors':
        case 'section':
            $inSection = $lock['type'] === 'section';
            $rising = ($lock['rising'] ?? false) === true; // only players who haven't learned every skill yet (saveSkills)
            $statement = $db->prepare('SELECT COUNT(DISTINCT c.contributionUserId) FROM `HBHub-MinerContributions` c'
                . ($rising ? ' JOIN `HBHub-MinerSaves` s ON s.userId = c.contributionUserId AND s.saveSkills < ?' : '')
                . ' WHERE c.contributionEngineId = ?' . ($inSection ? ' AND c.contributionStage BETWEEN ? AND ?' : ''));
            $statement->execute([...($rising ? [count(HBHUB_MINER_SKILLS)] : []), $engineId, ...($inSection ? $sectionRange : [])]);
            $have = (int) $statement->fetchColumn();
            break;
        case 'source':
            $statement = $db->prepare('SELECT COUNT(*) FROM (SELECT contributionUserId FROM `HBHub-MinerContributions`
                WHERE contributionEngineId = ? AND contributionSource = ? AND contributionStage BETWEEN ? AND ?
                GROUP BY contributionUserId HAVING SUM(contributionParts) >= ?) players');
            $statement->execute([$engineId, $lock['source'], ...$sectionRange, $lock['parts']]);
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
        case 'time': // on its own, not in a stack
            $statement = $db->prepare('SELECT COUNT(DISTINCT contributionUserId) FROM `HBHub-MinerContributions`
                WHERE contributionEngineId = ? AND contributionSource = ? AND contributionAnomaly = ? AND contributionMs < ?');
            $statement->execute([$engineId, HBHUB_MINER_ENGINE_SOURCE_ANOMALY, $lock['anomaly'], $lock['ms']]);
            $have = (int) $statement->fetchColumn();
            break;
        default:
            $have = 0;
    }
    return ['have' => $have, 'need' => $lock['count']];
}

/**
 * Whether the last stage's time has run out (the stage before it done, it not yet full, the countdown passed).
 */
function hbHubMinerEngineDrainDue(array $engine): bool
{
    return (int) $engine['engineStage'] === count(hbHubMinerEngineStagesOf($engine)) - 1 && $engine['engineCountdownTimestamp'] !== null
        && hbHubMinerEngineMs($engine['engineCountdownTimestamp']) <= hbHubMinerNow();
}

/**
 * The last stage's time ran out: HBHUB_MINER_ENGINE_DRAIN of its parts are kept and it gets another
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
 * Puts what a save gave (hbHubMinerReplay()'s 'given', [source, parts] each, with the Anomaly's key and the ms it took
 * after that for Anomalies) into the Engine, one row per source (and Anomaly, with its fastest time in the save), and
 * finishes the stages that are done now. Parts from Anomalies go in even while the Engine is waiting for an Anomaly
 * lock (it's how the lock is met), and after the last stage there is so far, where they wait for the next Engine.
 * $engine must have been read with its row locked, inside a transaction (it's the next Engine's after, if this one
 * was just done).
 */
function hbHubMinerEngineGive(PDO $db, array &$engine, int $userId, array $given): void
{
    $rows = [];
    foreach ($given as $gift) {
        $key = $gift[0] . '|' . ($gift[2] ?? '');
        $rows[$key] ??= [$gift[0], 0, $gift[2] ?? null, null];
        $rows[$key][1] += $gift[1];
        if (isset($gift[3])) {
            $rows[$key][3] = min($rows[$key][3] ?? PHP_INT_MAX, (int) $gift[3], 4294967295); // what the column holds
        }
    }
    $stage = min((int) $engine['engineStage'] + 1, count(hbHubMinerEngineStagesOf($engine)));
    $insert = $db->prepare('INSERT INTO `HBHub-MinerContributions` (contributionEngineId, contributionUserId, contributionStage, contributionParts,
            contributionSource, contributionAnomaly, contributionMs, contributionTimestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
    foreach ($rows as [$source, $parts, $anomaly, $ms]) {
        $insert->execute([(int) $engine['engineId'], $userId, $stage, $parts, $source, $anomaly, $ms]);
        $engine['engineStageParts'] = (int) $engine['engineStageParts'] + $parts;
        $engine['engineParts'] = (int) $engine['engineParts'] + $parts;
    }
    hbHubMinerEngineFinish($db, $engine);
}

/**
 * Finishes every stage in a row that has its parts and its lock met (what's over goes on to the next one), notifies
 * everyone who plays about each, and writes the Engine back. The stage before the last starts its countdown, and the
 * last one ignites the Engine: then the next one in HBHUB_MINER_ENGINES starts, with what was over (and $engine is it
 * from then on). Returns the stages finished of the Engine $engine was. $engine must have been read with its row
 * locked, inside a transaction.
 *
 * @return list<int>
 */
function hbHubMinerEngineFinish(PDO $db, array &$engine): array
{
    $stages = hbHubMinerEngineStagesOf($engine);
    $costs = hbHubMinerEngineCosts($engine);
    $times = $engine['engineStageFinished'] !== null ? (json_decode($engine['engineStageFinished'], true) ?: []) : [];
    $finished = [];
    while (($done = (int) $engine['engineStage']) < count($stages) && (int) $engine['engineStageParts'] >= $costs[$done]) {
        $lock = hbHubMinerEngineLock($db, $engine, $done);
        if ($lock !== null && $lock['have'] < $lock['need']) {
            break;
        }
        $engine['engineStageParts'] = (int) $engine['engineStageParts'] - $costs[$done];
        $engine['engineStage'] = $done + 1;
        $times[$done + 1] = gmdate('Y-m-d H:i:s');
        $finished[] = $done + 1;
        if ($done + 1 === count($stages) - 1) {
            $engine['engineCountdownTimestamp'] = gmdate('Y-m-d H:i:s', intdiv(hbHubMinerNow() + HBHUB_MINER_ENGINE_COUNTDOWN_MS, 1000));
        } elseif ($done + 1 === count($stages)) {
            $engine['engineCountdownTimestamp'] = null;
            $engine['engineIgnitedTimestamp'] = gmdate('Y-m-d H:i:s');
        }
        hbHubMinerEngineAnnounce($db, $engine, $done + 1);
    }
    $engine['engineStageFinished'] = $times !== [] ? json_encode($times, JSON_FORCE_OBJECT) : null;

    // Ignited just now, with a next Engine to build: what was over goes into its first stage instead
    $next = (int) $engine['engineNumber'] + 1;
    $carry = in_array(count($stages), $finished, true) && isset(HBHUB_MINER_ENGINES[$next]) ? (int) $engine['engineStageParts'] : null;
    if ($carry !== null) {
        $engine['engineStageParts'] = 0;
    }

    $db->prepare('UPDATE `HBHub-MinerEngines` SET engineStage = ?, engineStageParts = ?, engineParts = ?, engineStageFinished = ?,
            engineCountdownTimestamp = ?, engineIgnitedTimestamp = ?
        WHERE engineId = ?')
        ->execute([(int) $engine['engineStage'], (int) $engine['engineStageParts'], (int) $engine['engineParts'],
            $engine['engineStageFinished'], $engine['engineCountdownTimestamp'], $engine['engineIgnitedTimestamp'], (int) $engine['engineId']]);

    if ($carry !== null) {
        // The parts carried over were given to the Engine before, so they're in its contributions, not the new one's
        $db->prepare('INSERT IGNORE INTO `HBHub-MinerEngines` (engineNumber, engineStageParts, engineParts, engineStartedTimestamp)
            VALUES (?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$next, $carry, $carry]);
        $engine = hbHubMinerEngineRow($db, true);
        hbHubMinerEngineFinish($db, $engine);
    }
    return $finished;
}

/**
 * Tells everyone who plays (has made a Warp Jump, so can give parts), apart from banned and deleted accounts, that
 * stage $stage of the Engine is done: miner.engineStage, in one INSERT however many there are. The data has the
 * Engine's number and name and the stage's name and perk, so the notification still says the same if they're changed
 * later.
 */
function hbHubMinerEngineAnnounce(PDO $db, array $engine, int $stage): void
{
    $number = (int) $engine['engineNumber'];
    $data = hbHubMinerEngineStagesOf($engine)[$stage - 1];
    $db->prepare('INSERT INTO `HBHub-Notifications` (userId, notificationType, notificationByUserId, notificationData)
        SELECT s.userId, ?, NULL, ?
        FROM `HBHub-MinerSaves` s
        JOIN `HBHub-Users` u ON u.userId = s.userId
        WHERE s.saveWarps > 0 AND u.userStatus IS NOT NULL AND u.userStatus <> ?')
        ->execute(['miner.engineStage', json_encode(['engine' => $number, 'engineName' => HBHUB_MINER_ENGINES[$number]['name'] ?? null,
            'stage' => $stage, 'name' => $data['name'], 'perk' => $data['perk']['text']]), HBHUB_MINER_ENGINE_STATUS_BANNED]);
}

/**
 * Sets the stages' costs of the Engine being built from the admin panel ($costs: stage => parts; a stage left out, or
 * set to what HBHUB_MINER_ENGINE_STAGES has, uses that one) and finishes any stage that has its parts now. Returns
 * the stages finished.
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
        foreach (hbHubMinerEngineStagesOf($engine) as $index => $stage) {
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
 * The Engine for the page (the save's answer, spaceOreMiner.js's Engine view): number, done (its stages), parts (in
 * the stage being built), total (parts ever), costs (each stage's, stage 1 first), finished ({stage: Unix ms}), open
 * (whether it takes parts), lock (hbHubMinerEngineLock() for the stage being built), countdown (when the last stage
 * has to be full by, Unix ms, or 0) and ignited (when it was, or 0). With $details also top (the
 * HBHUB_MINER_ENGINE_TOP who gave the most: [{name, parts, you}]), feed (the latest HBHUB_MINER_ENGINE_FEED: [{name,
 * parts, source, anomaly (its key, or null), at}]), mine (the parts $userId gave, 0 for a guest) and, once the hall
 * perk is done, hall (the Engines done before: [{number, ignited, parts, players, top: [names]}]). Banned players'
 * parts count, but they're not shown.
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
        'lock' => $done < count(hbHubMinerEngineStagesOf($engine)) ? hbHubMinerEngineLock($db, $engine, $done) : null,
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

    if (hbHubMinerEnginePerk(['engineStage' => hbHubMinerEngineDoneAll($engine)], 'hall')) {
        $summary['hall'] = hbHubMinerEngineHall($db);
    }
    return $summary;
}

/**
 * The Hall of Engines (the hall perk): every Engine done so far, first first: number, ignited (Unix ms), parts (ever
 * given to it), players (who gave them) and top (the names of the HBHUB_MINER_ENGINE_HALL_TOP who gave the most,
 * banned players left out).
 *
 * @return list<array{number: int, ignited: int, parts: int, players: int, top: list<string>}>
 */
function hbHubMinerEngineHall(PDO $db): array
{
    $engines = $db->query('SELECT e.engineId, e.engineNumber, e.engineIgnitedTimestamp, e.engineParts,
            (SELECT COUNT(DISTINCT c.contributionUserId) FROM `HBHub-MinerContributions` c WHERE c.contributionEngineId = e.engineId) AS players
        FROM `HBHub-MinerEngines` e WHERE e.engineIgnitedTimestamp IS NOT NULL ORDER BY e.engineNumber')->fetchAll();
    $top = $db->prepare('SELECT u.userName FROM `HBHub-MinerContributions` c
        JOIN `HBHub-Users` u ON u.userId = c.contributionUserId
        WHERE c.contributionEngineId = ? AND (u.userStatus IS NULL OR u.userStatus <> ?)
        GROUP BY c.contributionUserId, u.userName
        ORDER BY SUM(c.contributionParts) DESC, MIN(c.contributionId)
        LIMIT ' . HBHUB_MINER_ENGINE_HALL_TOP);
    $hall = [];
    foreach ($engines as $row) {
        $top->execute([(int) $row['engineId'], HBHUB_MINER_ENGINE_STATUS_BANNED]);
        $hall[] = [
            'number' => (int) $row['engineNumber'],
            'ignited' => hbHubMinerEngineMs($row['engineIgnitedTimestamp']),
            'parts' => (int) $row['engineParts'],
            'players' => (int) $row['players'],
            'top' => $top->fetchAll(PDO::FETCH_COLUMN),
        ];
    }
    return $hall;
}

/**
 * The Engine badges $userId has on their profile, oldest Engine first: for each Engine they gave parts to that has its
 * badge stage done (Engine Crew, Forge Crew, ...), and for each one whose last stage they gave parts to before it was
 * done (its 'crew' in HBHUB_MINER_ENGINES: Ignition, First Light, ...). Each is [icon, name, what it's for].
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function hbHubMinerEngineBadges(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT e.engineNumber, e.engineStage,
            MAX(c.contributionStage = ? AND e.engineIgnitedTimestamp IS NOT NULL AND c.contributionTimestamp <= e.engineIgnitedTimestamp) AS crew
        FROM `HBHub-MinerContributions` c
        JOIN `HBHub-MinerEngines` e ON e.engineId = c.contributionEngineId
        WHERE c.contributionUserId = ?
        GROUP BY e.engineId, e.engineNumber, e.engineStage
        ORDER BY e.engineNumber');
    $statement->execute([HBHUB_MINER_ENGINE_STAGE_TOTAL, $userId]);
    $badges = [];
    foreach ($statement->fetchAll() as $row) {
        $number = (int) $row['engineNumber'];
        $name = HBHUB_MINER_ENGINES[$number]['name'] ?? 'Cosmic Engine';
        foreach (hbHubMinerEngineStages($number) as $index => $stage) {
            if ($stage['perk']['type'] === 'badge' && (int) $row['engineStage'] > $index) {
                $badges[] = [$stage['perk']['icon'], $stage['perk']['badge'], 'Helped build Ore Mining Inc.\'s ' . $name];
            }
        }
        if ((int) $row['crew'] === 1 && isset(HBHUB_MINER_ENGINES[$number]['crew'])) {
            $crew = HBHUB_MINER_ENGINES[$number]['crew'];
            $badges[] = [$crew['icon'], $crew['name'], 'Helped fill the last stage of Ore Mining Inc.\'s ' . $name];
        }
    }
    return $badges;
}

/**
 * $userId's weekly Anomaly medals (the medals perk): how many weeks they were 1st, 2nd and 3rd fastest at that week's
 * weekly Anomaly, [1 => n, 2 => n, 3 => n], over the weeks that are over. Banned and deleted players aren't ranked.
 * Empty until the perk is done.
 *
 * @return array<int, int>
 */
function hbHubMinerWeeklyMedals(PDO $db, int $userId): array
{
    if (!hbHubMinerEnginePerkDone($db, 'medals')) {
        return [];
    }
    $statement = $db->prepare('SELECT place, COUNT(*) AS times FROM (
            SELECT t.timeUserId, RANK() OVER (PARTITION BY t.timeWeek ORDER BY t.timeBest) AS place
            FROM `HBHub-MinerAnomalyTimes` t
            JOIN `HBHub-Users` u ON u.userId = t.timeUserId
            WHERE t.timeWeek > 0 AND t.timeWeek < ? AND u.userStatus IS NOT NULL AND u.userStatus <> ?
        ) ranked
        WHERE timeUserId = ? AND place <= ' . HBHUB_MINER_MEDALS . '
        GROUP BY place');
    $statement->execute([hbHubMinerWeek(hbHubMinerNow()), HBHUB_MINER_ENGINE_STATUS_BANNED, $userId]);
    $medals = [];
    foreach ($statement->fetchAll() as $row) {
        $medals[(int) $row['place']] = (int) $row['times'];
    }
    return $medals;
}

/**
 * The leaderboard titles of $userIds (HBHUB_MINER_TITLES): user id => the name of the first one each has, for the
 * ones who have one. An Anomaly's counts once it's finished alone or in a stack (HBHub-MinerAnomalyTimes); an Engine's
 * once they've given parts to that Engine and its title stage is done.
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
    foreach (HBHUB_MINER_TITLES as $title) {
        if (isset($title['anomaly'])) {
            $statement = $db->prepare('SELECT DISTINCT timeUserId FROM `HBHub-MinerAnomalyTimes`
                WHERE timeWeek = 0 AND FIND_IN_SET(?, REPLACE(timeAnomaly, \'+\', \',\')) > 0 AND timeUserId IN (' . $in . ')');
            $statement->execute([$title['anomaly'], ...$userIds]);
        } else {
            $titleStage = null;
            foreach (hbHubMinerEngineStages($title['engine']) as $index => $stage) {
                if ($stage['perk']['type'] === 'title') {
                    $titleStage = $index + 1;
                }
            }
            if ($titleStage === null) {
                continue;
            }
            $statement = $db->prepare('SELECT DISTINCT c.contributionUserId FROM `HBHub-MinerContributions` c
                JOIN `HBHub-MinerEngines` e ON e.engineId = c.contributionEngineId
                WHERE e.engineNumber = ? AND e.engineStage >= ? AND c.contributionUserId IN (' . $in . ')');
            $statement->execute([$title['engine'], $titleStage, ...$userIds]);
        }
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $has[(int) $userId] ??= $title['name'];
        }
    }
    return $has;
}
