<?php
// Ore Mining Inc.'s Cosmic Engine, in the database: the machine the whole site builds together (the stages, perks and
// what players can give are rules, so they're in miner.php with the rest; this is where the parts go).
//
// - HBHub-MinerEngines: one row per Engine (only #1 for now, made by hbHubMinerEngineRow() the first time it's needed):
//   how many stages are done (engineStage), the parts in the stage being built (engineStageParts), all parts ever given
//   (engineParts), the admin's own stage costs (engineStageCosts, JSON {stage: cost}, see hbHubMinerEngineCosts()) and
//   when each stage was done (engineStageFinished, JSON {stage: 'Y-m-d H:i:s' UTC}).
// - HBHub-MinerContributions: who gave how many parts, from what (HBHUB_MINER_ENGINE_SOURCE_*), into which stage and
//   when: one row per source per save. Shown to everyone in the game's Engine view and on the leaderboard.
//
// hbHubMinerSync() (miner.php) puts in what a save gave with hbHubMinerEngineGive(), with the Engine's row locked, and
// a stage is done as soon as it has its parts and its lock is met (hbHubMinerEngineFinish()), which notifies everyone
// who plays (miner.engineStage, notificationTypes/miner.php). The admin panel changes the costs
// (hbHubMinerEngineSetCosts(), admin/engine.php).
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
            engineStartedTimestamp
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
    return $lock === null || $lock['type'] === 'contributors' || (int) $engine['engineStageParts'] < hbHubMinerEngineCosts($engine)[$done];
}

/**
 * Gives the save what the Engine is now: its stages done (the perks) and whether it takes parts. $canGive is false for
 * guests, who can't.
 */
function hbHubMinerEngineAdopt(array &$state, array $engine, bool $canGive): void
{
    $state['engineStage'] = min((int) $engine['engineStage'], count(HBHUB_MINER_ENGINE_STAGES));
    $state['engineOpen'] = $canGive && hbHubMinerEngineOpen($engine);
}

/**
 * How far stage $index (0 for stage 1) is with its lock: ['have' => n, 'need' => n], or null when it has none.
 * Anomalies aren't in the game yet, so nobody has finished any.
 */
function hbHubMinerEngineLock(PDO $db, array $engine, int $index): ?array
{
    $lock = HBHUB_MINER_ENGINE_STAGES[$index]['lock'] ?? null;
    if ($lock === null) {
        return null;
    }
    if ($lock['type'] === 'contributors') {
        $statement = $db->prepare('SELECT COUNT(DISTINCT contributionUserId) FROM `HBHub-MinerContributions` WHERE contributionEngineId = ?');
        $statement->execute([(int) $engine['engineId']]);
        return ['have' => (int) $statement->fetchColumn(), 'need' => $lock['count']];
    }
    return ['have' => 0, 'need' => $lock['count']];
}

/**
 * Puts what a save gave (hbHubMinerReplay()'s 'given', [source, parts] each) into the Engine, one row per source, and
 * finishes the stages that are done now. $engine must have been read with its row locked, inside a transaction.
 */
function hbHubMinerEngineGive(PDO $db, array &$engine, int $userId, array $given): void
{
    $bySource = [];
    foreach ($given as [$source, $parts]) {
        $bySource[$source] = ($bySource[$source] ?? 0) + $parts;
    }
    $stage = min((int) $engine['engineStage'] + 1, count(HBHUB_MINER_ENGINE_STAGES));
    $insert = $db->prepare('INSERT INTO `HBHub-MinerContributions` (contributionEngineId, contributionUserId, contributionStage, contributionParts,
            contributionSource, contributionTimestamp)
        VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
    foreach ($bySource as $source => $parts) {
        $insert->execute([(int) $engine['engineId'], $userId, $stage, $parts, $source]);
        $engine['engineStageParts'] = (int) $engine['engineStageParts'] + $parts;
        $engine['engineParts'] = (int) $engine['engineParts'] + $parts;
    }
    hbHubMinerEngineFinish($db, $engine);
}

/**
 * Finishes every stage in a row that has its parts and its lock met (what's over goes on to the next one), notifies
 * everyone who plays about each, and writes the Engine back. Returns the stages finished. $engine must have been read
 * with its row locked, inside a transaction.
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
        hbHubMinerEngineAnnounce($db, $engine, $done + 1);
    }
    $engine['engineStageFinished'] = $times !== [] ? json_encode($times, JSON_FORCE_OBJECT) : null;

    $db->prepare('UPDATE `HBHub-MinerEngines` SET engineStage = ?, engineStageParts = ?, engineParts = ?, engineStageFinished = ? WHERE engineId = ?')
        ->execute([(int) $engine['engineStage'], (int) $engine['engineStageParts'], (int) $engine['engineParts'],
            $engine['engineStageFinished'], (int) $engine['engineId']]);
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
 * (whether it takes parts) and lock (hbHubMinerEngineLock() for the stage being built). With $details also top (the
 * HBHUB_MINER_ENGINE_TOP who gave the most: [{name, parts, you}]), feed (the latest HBHUB_MINER_ENGINE_FEED: [{name,
 * parts, source, at}]) and mine (the parts $userId gave, 0 for a guest). Banned players' parts count, but they're
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

    $statement = $db->prepare('SELECT u.userName, c.contributionParts, c.contributionSource, c.contributionTimestamp
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
