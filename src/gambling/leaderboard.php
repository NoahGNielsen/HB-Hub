<?php
// The whole casino's leaderboard: everyone who has played any casino game, with the rounds they've played (Blackjack
// and Baccarat hands and Roulette spins together), the chips they've won or lost in each game, all of it together, and
// their chips now. The games' own rounds come from HBHub-BlackjackPlayerRounds, HBHub-BaccaratPlayerRounds and
// HBHub-RouletteSpins (playerNet / spinNet: chips won or lost, insurance included), the chips from HBHub-CasinoWallets.
// Sorted by any column with ?sort=column&dir=asc|desc, which the column headings link to. A game someone has never
// played is "–", and comes after everyone who has when sorting by it (whichever way).
// Each game's own leaderboard (gambling/<game>/leaderboard.php) has the wins, losses and win %. The rows come from
// assets/php/casino/leaderboard.php, which the casino home uses for its top players too.
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/leaderboard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/userAvatar.php';
hbHubCasinoPage($hbHubUser); // blocked in settings, or not open yet

const LEADERBOARD_ROWS = 200;
const LEADERBOARD_COLUMNS = [ // sort => [heading, which way it sorts first]
    'player' => ['Player', 'asc'],
    'rounds' => ['Rounds', 'desc'],
    'blackjack' => ['Blackjack', 'desc'],
    'baccarat' => ['Baccarat', 'desc'],
    'roulette' => ['Roulette', 'desc'],
    'net' => ['Total won / lost', 'desc'],
    'chips' => ['Chips', 'desc'],
];
const LEADERBOARD_NULLABLE = HBHUB_CASINO_LEADERBOARD_GAMES; // "–" for a game never played
const LEADERBOARD_GAMES = [ // the games' own leaderboards, linked above the table
    'Blackjack' => '/gambling/blackjack/leaderboard',
    'Baccarat' => '/gambling/baccarat/leaderboard',
    'Roulette' => '/gambling/roulette/leaderboard',
];

$db = hbHubDatabase();
$userId = (int) $hbHubUser['userId'];

$sort = is_string($_GET['sort'] ?? null) && isset(LEADERBOARD_COLUMNS[$_GET['sort']]) ? $_GET['sort'] : 'net';
$dir = in_array($_GET['dir'] ?? null, ['asc', 'desc'], true) ? $_GET['dir'] : LEADERBOARD_COLUMNS[$sort][1];
// The top three get a gold, silver and bronze rank, but only while it's sorted the way the column sorts first (not by
// name, or turned round)
$podium = $sort !== 'player' && $dir === LEADERBOARD_COLUMNS[$sort][1];

// Banned and deleted accounts are left out (assets/php/casino/leaderboard.php)
$rows = hbHubCasinoLeaderboardSort(hbHubCasinoLeaderboardRows($db), $sort, $dir === 'desc');
$rows = array_slice($rows, 0, LEADERBOARD_ROWS);

$navCasinoChips = hbHubCasinoWallet($db, $userId)['chips']; // the chip count in the top bar (navPanel.php)

/**
 * The heading's link: the other way round when it's already sorted by it, otherwise the way it sorts first.
 */
function leaderboardSortUrl(string $column, string $sort, string $dir): string
{
    $newDir = $column === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : LEADERBOARD_COLUMNS[$column][1];
    return '?' . http_build_query(['sort' => $column, 'dir' => $newDir]);
}

/**
 * A won / lost cell: green with a "+" for chips won, red with a "−" for chips lost, and a faint "–" for a game never
 * played. $class is added to the cell (casino-board-total for the total).
 */
function leaderboardNetCell(?int $net, string $class = ''): string
{
    if ($net === null) {
        return '<td><span class="casino-unranked" title="Never played">–</span></td>';
    }
    $classes = trim($class . ' ' . ($net > 0 ? 'casino-net-up' : ($net < 0 ? 'casino-net-down' : '')));
    return '<td' . ($classes !== '' ? ' class="' . $classes . '"' : '') . '>'
        . ($net > 0 ? '+' : ($net < 0 ? '−' : '')) . number_format(abs($net)) . '</td>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/casino.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/casino.css') ?>">
    <title>Casino leaderboard - HB Hub</title>
    <meta name="robots" content="noindex">
    <meta name="description" content="Who has won the most chips in the HB Hub casino, in every game together">
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/navPanel.php'; ?>
    <main class="casino casino-leaderboard">
        <a class="casino-back" href="/gambling/">&larr; Casino</a>
        <h1>Casino leaderboard</h1>
        <p class="casino-intro">
            How many chips everyone has won or lost in each game, and in the whole casino together: what their rounds paid
            back, minus what they bet on them. The daily chips don't count towards it, but they're in Chips, which is
            everyone's chips right now. Wins, losses and win % are on each game's own leaderboard.
        </p>

        <nav class="casino-board-links" aria-label="Each game's leaderboard">
            <?php foreach (LEADERBOARD_GAMES as $name => $url): ?>
                <a class="casino-btn" href="<?= $url ?>"><?= $name ?></a>
            <?php endforeach; ?>
        </nav>

        <section class="casino-card casino-board" aria-label="Leaderboard">
            <?php if ($rows === []): ?>
                <p class="casino-hint">Nobody has played in the casino yet. <a href="/gambling/">Be the first!</a></p>
            <?php else: ?>
                <div class="casino-table-wrap">
                    <table class="casino-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="casino-rank">#</span></th>
                                <?php foreach (LEADERBOARD_COLUMNS as $column => [$heading]): ?>
                                    <th scope="col"<?= $column === $sort ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                        <a class="casino-sort<?= $column === $sort ? ' is-active' : '' ?>" href="<?= htmlspecialchars(leaderboardSortUrl($column, $sort, $dir)) ?>">
                                            <?= $heading ?><span class="casino-sort-arrow" aria-hidden="true"><?= $column === $sort ? ($dir === 'asc' ? '↑' : '↓') : '' ?></span>
                                        </a>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $index => $row):
                                $avatarSrc = hbHubAvatarUrl($row['userId'], $row['avatarId']);
                                $isUnranked = in_array($sort, LEADERBOARD_NULLABLE, true) && $row[$sort] === null;
                                $place = $podium && !$isUnranked && $index < 3 ? $index + 1 : null;
                            ?>
                                <tr<?= $row['userId'] === $userId ? ' class="is-you"' : '' ?>>
                                    <td class="casino-rank"<?= $place !== null ? ' data-podium="' . $place . '"' : '' ?>><?= $isUnranked ? '–' : $index + 1 ?></td>
                                    <td>
                                        <a class="casino-player" href="<?= htmlspecialchars(hbHubProfileUrl($row['userId'], $_SERVER['REQUEST_URI'])) ?>">
                                            <span class="casino-avatar" aria-hidden="true">
                                                <?php if ($avatarSrc !== null): ?>
                                                    <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="" loading="lazy">
                                                <?php else: ?>
                                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($row['player'], 0, 1))) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="casino-player-name"><?= htmlspecialchars($row['player']) ?></span>
                                        </a>
                                    </td>
                                    <td><?= number_format($row['rounds']) ?></td>
                                    <?= leaderboardNetCell($row['blackjack']) ?>
                                    <?= leaderboardNetCell($row['baccarat']) ?>
                                    <?= leaderboardNetCell($row['roulette']) ?>
                                    <?= leaderboardNetCell($row['net'], 'casino-board-total') ?>
                                    <td><?= number_format($row['chips']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
