<?php
// The rules of Roulette as played here, shown on the Roulette page. roulette.php plays by them, so change both together.
?>
<ul class="casino-rule-list">
    <li>One wheel with the numbers 1-36 (half red, half black) and a single green 0. Every number is as likely as the next.</li>
    <li>Click a number to bet on it. Click the line between two numbers for a split, the corner between four for a corner, and the outer edge of a row for the row (street) or two rows (line). Right-click a bet to take a chip off it.</li>
    <li>
        Payouts: a number 35:1, a split 17:1, a street or 0-1-2 / 0-2-3 11:1, a corner or 0-1-2-3 8:1, a line 5:1,
        a dozen or column 2:1, and red, black, odd, even, 1-18 and 19-36 1:1.
    </li>
    <li>When the ball lands on 0, every bet outside the numbers (dozens, columns, red, black and so on) loses.</li>
    <li>Bets go in steps of <?= HBHUB_ROULETTE_BET_STEP ?>. Your bets stay on the table after a spin, so you can spin again with the same bets.</li>
</ul>
