<?php
// Admin panel's Ore Mining Inc. Cosmic Engine: /admin/engine, in the admin panel's side menu (adminListsMenuHtml()).
// Everyone but site admins gets a 404, so it doesn't show that the page is here.
//
// How far the Engine the whole site is building is (the newest one, as each next one starts when the one before is
// done; assets/php/games/spaceOreMiner/engine.php): the stage being built, its parts and lock, how many have given
// parts and what came from where, and every stage with its cost, which can be changed here (they were a first guess,
// to be tuned once there's real data). A form (action=costs, cost[stage] = parts) saves them all at once, in
// engineStageCosts; a stage set back to its cost in HBHUB_MINER_ENGINE_STAGES uses that one again. Lowering the cost
// of the stage being built below its parts finishes it straight away (if its lock is met), with the notifications to
// everyone. It goes in the admin log (assets/php/adminLog.php).
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/admin.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/miner.php';

adminOnly($hbHubUser);

$db = hbHubDatabase();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'costs') {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/toast.php';
    $posted = is_array($_POST['cost'] ?? null) ? $_POST['cost'] : [];
    $costs = [];
    foreach (hbHubMinerEngineStagesOf(hbHubMinerEngineRow($db, false)) as $index => $stage) {
        $value = $posted[$index + 1] ?? null;
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 9 || (int) $value < 1 || (int) $value > HBHUB_MINER_ENGINE_COST_MAX) {
            hbHubSetToast('adminEngineCostsBad');
            header('Location: /admin/engine#costs', true, 303);
            exit;
        }
        $costs[$index + 1] = (int) $value;
    }

    $before = hbHubMinerEngineCosts(hbHubMinerEngineRow($db, false));
    $finished = hbHubMinerEngineSetCosts($db, $costs);
    $changed = [];
    foreach ($costs as $stage => $cost) {
        if ($before[$stage - 1] !== $cost) {
            $changed[$stage] = [$before[$stage - 1], $cost];
        }
    }
    if ($changed !== []) {
        adminLog($db, (int) $hbHubUser['userId'], 'engine.costs', null, null, ['changed' => $changed, 'finished' => $finished]);
    }

    // Back to the page with a toast, so reloading doesn't send the form again
    hbHubSetToast('adminEngineCosts');
    header('Location: /admin/engine#costs', true, 303);
    exit;
}

$adminEngine = hbHubMinerEngineRow($db, false);
$adminCosts = hbHubMinerEngineCosts($adminEngine);
$adminDone = (int) $adminEngine['engineStage'];
$adminFinished = $adminEngine['engineStageFinished'] !== null ? (json_decode($adminEngine['engineStageFinished'], true) ?: []) : [];
$adminStages = hbHubMinerEngineStagesOf($adminEngine);
$adminEngineName = HBHUB_MINER_ENGINES[(int) $adminEngine['engineNumber']]['name'] ?? 'Cosmic Engine';
$adminLock = $adminDone < count($adminStages) ? hbHubMinerEngineLock($db, $adminEngine, $adminDone) : null;

// What's been given to this Engine, by where it came from, and by how many players
$statement = $db->prepare('SELECT contributionSource, SUM(contributionParts) AS parts FROM `HBHub-MinerContributions`
    WHERE contributionEngineId = ? GROUP BY contributionSource');
$statement->execute([(int) $adminEngine['engineId']]);
$adminSources = array_map('intval', array_column($statement->fetchAll(), 'parts', 'contributionSource'));
$statement = $db->prepare('SELECT COUNT(DISTINCT contributionUserId) FROM `HBHub-MinerContributions` WHERE contributionEngineId = ?');
$statement->execute([(int) $adminEngine['engineId']]);
$adminContributors = (int) $statement->fetchColumn();
// The last 7 days, to see how fast it's going (the costs were planned for about 28,000 parts a week)
$statement = $db->prepare('SELECT COALESCE(SUM(contributionParts), 0) FROM `HBHub-MinerContributions`
    WHERE contributionEngineId = ? AND contributionTimestamp >= UTC_TIMESTAMP() - INTERVAL 7 DAY');
$statement->execute([(int) $adminEngine['engineId']]);
$adminWeek = (int) $statement->fetchColumn();

const ADMIN_ENGINE_SOURCES = [ // contributionSource => what it's called here
    HBHUB_MINER_ENGINE_SOURCE_STRANGE => 'Strange Matter',
    HBHUB_MINER_ENGINE_SOURCE_ORE => 'Ore',
    HBHUB_MINER_ENGINE_SOURCE_SHIFT => 'Engine Shifts',
    HBHUB_MINER_ENGINE_SOURCE_ANOMALY => 'Anomalies',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/settings.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.css') ?>">
    <script src="/assets/js/admin.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.js') ?>" defer></script>
    <title>Cosmic Engine - Admin panel - HB Hub</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="settings settings-page admin">
        <div class="settings-layout">
            <?= adminListsMenuHtml($db, 'engine') ?>

            <div class="settings-content">
                <h1>Admin panel</h1>

                <section class="settings-card" id="engine" aria-labelledby="admin-engine-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-engine-heading">Ore Mining Inc. <?= htmlspecialchars($adminEngineName) ?> (Engine #<?= (int) $adminEngine['engineNumber'] ?>)</h2>
                            <p class="settings-intro">
                                Started <?= adminTime($adminEngine['engineStartedTimestamp']) ?> (UTC).
                                <?= $adminDone ?> of <?= HBHUB_MINER_ENGINE_STAGE_TOTAL ?> stages done.<?= $adminEngine['engineIgnitedTimestamp'] !== null ? ' Ignited ' . adminTime($adminEngine['engineIgnitedTimestamp']) . ' (UTC).' : '' ?>
                            </p>
                        </div>
                    </div>
                    <dl class="admin-engine-numbers">
                        <?php if ($adminDone < count($adminStages)): ?>
                            <dt>Building</dt>
                            <dd>
                                Stage <?= $adminDone + 1 ?>, <?= htmlspecialchars($adminStages[$adminDone]['name']) ?>:
                                <?= number_format((int) $adminEngine['engineStageParts']) ?> of <?= number_format($adminCosts[$adminDone]) ?> parts
                                <?php if ($adminLock !== null): ?>
                                    <br>Lock: <?= htmlspecialchars($adminStages[$adminDone]['lock']['text']) ?> (<?= $adminLock['have'] ?> / <?= $adminLock['need'] ?>)
                                <?php endif; ?>
                                <?php if ($adminEngine['engineCountdownTimestamp'] !== null): ?>
                                    <br>Has to be full by <?= adminTime($adminEngine['engineCountdownTimestamp']) ?> (UTC), or <?= (1 - HBHUB_MINER_ENGINE_DRAIN) * 100 ?>% of its parts are lost
                                    and it gets another <?= intdiv(HBHUB_MINER_ENGINE_COUNTDOWN_MS, HBHUB_MINER_DAY_MS) ?> days.
                                <?php endif; ?>
                            </dd>
                        <?php endif; ?>
                        <dt>Parts given</dt>
                        <dd><?= number_format((int) $adminEngine['engineParts']) ?> by <?= number_format($adminContributors) ?> <?= $adminContributors === 1 ? 'player' : 'players' ?>, <?= number_format($adminWeek) ?> in the last 7 days</dd>
                        <?php foreach (ADMIN_ENGINE_SOURCES as $source => $label): ?>
                            <dt>From <?= $label ?></dt>
                            <dd><?= number_format($adminSources[$source] ?? 0) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </section>

                <section class="settings-card" id="costs" aria-labelledby="admin-costs-heading">
                    <div class="settings-card-head">
                        <div>
                            <h2 id="admin-costs-heading">Stage costs</h2>
                            <p class="settings-intro">
                                In Engine Parts. Lowering the stage being built to its parts or less finishes it straight away, if its lock is met,
                                and tells everyone who plays. A done stage's cost changes nothing any more.
                            </p>
                        </div>
                    </div>
                    <form action="/admin/engine#costs" method="post">
                        <input type="hidden" name="action" value="costs">
                        <div class="admin-table-wrap">
                            <table class="admin-table admin-list">
                                <thead><tr><th scope="col">Stage</th><th scope="col">Perk and lock</th><th scope="col">Done</th><th scope="col">Cost</th></tr></thead>
                                <tbody>
                                    <?php foreach ($adminStages as $index => $stage): ?>
                                        <tr>
                                            <td><?= $index + 1 ?>. <?= htmlspecialchars($stage['name']) ?></td>
                                            <td class="admin-message-text">
                                                <?= htmlspecialchars($stage['perk']['text']) ?>
                                                <?php if ($stage['lock'] !== null): ?>
                                                    <br><span class="admin-muted">Lock: <?= htmlspecialchars($stage['lock']['text']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="admin-nowrap">
                                                <?php if ($index < $adminDone): ?>
                                                    <?= isset($adminFinished[$index + 1]) ? adminTime($adminFinished[$index + 1]) : 'Yes' ?>
                                                <?php elseif ($index === $adminDone): ?>
                                                    Building
                                                <?php else: ?>
                                                    <span class="admin-muted">&ndash;</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="admin-nowrap">
                                                <label class="settings-visually-hidden" for="admin-cost-<?= $index + 1 ?>">Cost of stage <?= $index + 1 ?></label>
                                                <input class="admin-engine-cost" type="number" id="admin-cost-<?= $index + 1 ?>" name="cost[<?= $index + 1 ?>]"
                                                       min="1" max="<?= HBHUB_MINER_ENGINE_COST_MAX ?>" step="1" required value="<?= $adminCosts[$index] ?>">
                                                <?php if ($adminCosts[$index] !== $stage['cost']): ?>
                                                    <span class="admin-muted">(was <?= number_format($stage['cost']) ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="settings-btn settings-btn-primary">Save costs</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
