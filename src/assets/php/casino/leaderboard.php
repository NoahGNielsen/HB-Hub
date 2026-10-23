<?php
// The whole casino's leaderboard rows, for gambling/leaderboard.php (the full table) and the casino home
// (gambling/index.php: the top players and the user's own place). One row per player who has played any casino game:
// their rounds (Blackjack and Baccarat hands and Roulette spins together), the chips won or lost in each game, all of it
// together, and their chips now. The rounds come from HBHub-BlackjackPlayerRounds, HBHub-BaccaratPlayerRounds and
// HBHub-RouletteSpins (playerNet / spinNet: chips won or lost, insurance included), the chips from HBHub-CasinoWallets.
// A game someone has never played is null.
require_once __DIR__ . '/../database.php';

const HBHUB_CASINO_LEADERBOARD_GAMES = ['blackjack', 'baccarat', 'roulette'];
const HBHUB_CASINO_LEADERBOARD_BANNED = 4; // userStatus in HBHub-Users

/**
 * Everyone on the casino leaderboard, in no order. Banned and deleted accounts (userStatus NULL) are left out.
 *
 * @return list<array{userId: int, player: string, avatarId: ?int, rounds: int, blackjack: ?int, baccarat: ?int, roulette: ?int, net: int, chips: int}>
 */
function hbHubCasinoLeaderboardRows(PDO $db): array
{
    // Each game's rounds and chips won or lost per player, one line per game, added up per player
    $statement = $db->prepare('SELECT g.userId, u.userName, u.userAvatarAttachmentId, COALESCE(w.walletChips, 0) AS chips,
            SUM(g.blackjackRounds) AS blackjackRounds, SUM(g.blackjackNet) AS blackjackNet,
            SUM(g.baccaratRounds) AS baccaratRounds, SUM(g.baccaratNet) AS baccaratNet,
            SUM(g.rouletteRounds) AS rouletteRounds, SUM(g.rouletteNet) AS rouletteNet
        FROM (
            SELECT userId, COUNT(*) AS blackjackRounds, SUM(playerNet) AS blackjackNet,
                0 AS baccaratRounds, 0 AS baccaratNet, 0 AS rouletteRounds, 0 AS rouletteNet
            FROM `HBHub-BlackjackPlayerRounds` GROUP BY userId
            UNION ALL
            SELECT userId, 0, 0, COUNT(*), SUM(playerNet), 0, 0 FROM `HBHub-BaccaratPlayerRounds` GROUP BY userId
            UNION ALL
            SELECT userId, 0, 0, 0, 0, COUNT(*), SUM(spinNet) FROM `HBHub-RouletteSpins` GROUP BY userId
        ) g
        JOIN `HBHub-Users` u ON u.userId = g.userId
        LEFT JOIN `HBHub-CasinoWallets` w ON w.userId = g.userId
        WHERE u.userStatus IS NOT NULL AND u.userStatus <> ?
        GROUP BY g.userId, u.userName, u.userAvatarAttachmentId, w.walletChips');
    $statement->execute([HBHUB_CASINO_LEADERBOARD_BANNED]);

    return array_map(static function (array $row): array {
        $games = [];
        foreach (HBHUB_CASINO_LEADERBOARD_GAMES as $game) {
            $games[$game] = (int) $row[$game . 'Rounds'] > 0 ? (int) $row[$game . 'Net'] : null;
        }
        return [
            'userId' => (int) $row['userId'],
            'player' => $row['userName'],
            'avatarId' => $row['userAvatarAttachmentId'] !== null ? (int) $row['userAvatarAttachmentId'] : null,
            'rounds' => (int) $row['blackjackRounds'] + (int) $row['baccaratRounds'] + (int) $row['rouletteRounds'],
            ...$games,
            'net' => (int) $row['blackjackNet'] + (int) $row['baccaratNet'] + (int) $row['rouletteNet'],
            'chips' => (int) $row['chips'],
        ];
    }, $statement->fetchAll());
}

/**
 * The rows sorted by a column ('player' or any number in them). When sorting by a game, those who never played it come
 * after everyone who has, whichever way. A tie goes to who played more, then by name.
 */
function hbHubCasinoLeaderboardSort(array $rows, string $sort, bool $descending): array
{
    $byName = static fn (array $a, array $b): int => strcmp(mb_strtolower($a['player']), mb_strtolower($b['player']));
    $isGame = in_array($sort, HBHUB_CASINO_LEADERBOARD_GAMES, true);

    usort($rows, static function (array $a, array $b) use ($sort, $descending, $isGame, $byName): int {
        if ($isGame && ($a[$sort] === null) !== ($b[$sort] === null)) {
            return $a[$sort] === null ? 1 : -1; // never played it: after everyone who has
        }
        $order = $sort === 'player' ? $byName($a, $b) : $a[$sort] <=> $b[$sort];
        if ($descending) {
            $order = -$order;
        }
        return $order ?: ($b['rounds'] <=> $a['rounds'] ?: $byName($a, $b));
    });
    return $rows;
}
