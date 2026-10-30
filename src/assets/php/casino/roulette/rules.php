<?php
// The rules of Roulette as played here, shown on the Roulette page and in the help docs. roulette.php and table.php
// play by them, so change them together. They're the same for the free table at /play/roulette, which is the guest's
// own, so the shared table's part says it's for members.
require_once __DIR__ . '/table.php';
?>
<ul class="casino-rule-list">
    <li>One wheel with the numbers 1-36 (half red, half black) and a single green 0. Every number is as likely as the next.</li>
    <li>Click a number to bet on it. Click the line between two numbers for a split, the corner between four for a corner, and the outer edge of a row for the row (street) or two rows (line). Right-click a bet to take a chip off it.</li>
    <li>
        Payouts: a number 35:1, a split 17:1, a street or 0-1-2 / 0-2-3 11:1, a corner or 0-1-2-3 8:1, a line 5:1,
        a dozen or column 2:1, and red, black, odd, even, 1-18 and 19-36 1:1.
    </li>
    <li>When the ball lands on 0, every bet outside the numbers (dozens, columns, red, black and so on) loses.</li>
    <li>Bets go in steps of <?= HBHUB_ROULETTE_BET_STEP ?>.</li>
    <li>
        Members all play at one table: everyone sees everyone's bets, and one ball is spun for all of them. The first bet
        on the table starts a <?= intdiv(HBHUB_ROULETTE_BET_MS, 1000) ?>-second clock, and anyone can put bets on or take
        them off until it runs out. Press Ready (or Space) when you're done: then your bets can't change, and when
        everyone with bets is ready the wheel spins straight away.
    </li>
    <li>
        Your chips are only taken when the wheel spins. If you don't have the chips for your bets by then, for example
        because you spent them at another table, your bets are taken off and nothing is taken or paid.
    </li>
    <li>After a spin, Same again (or Space) puts your last bets back on the table. On the free table your bets just stay on it, and Space spins again.</li>
</ul>
