<?php
// The rules of the slot machine as played here, with its pay table, shown on the Slots page and in the help docs.
// slots.php plays by them, and every number here comes from it, so the strips and the pays only change there.
// The pay table has docs-table too, so it looks like the docs' other tables there (slots.css styles it on the game).
require_once __DIR__ . '/slots.php';
?>
<ul class="casino-rule-list">
    <li>Three reels, three rows and <?= count(HBHUB_SLOTS_LINES) ?> paylines: the three rows and the two diagonals. Every spin plays all of them.</li>
    <li>
        Your bet is split evenly across the lines, and a line wins by what it pays times its share. A line only pays
        its best win, and every winning line is paid. So a bet of 50 is 10 a line, and three bells on one of them
        pays 250.
    </li>
    <li>
        Bets go from <?= HBHUB_SLOTS_MIN_BET ?> in steps of <?= HBHUB_SLOTS_BET_STEP ?>. With fewer than
        <?= HBHUB_CASINO_ALL_IN_BELOW ?> chips you can go <strong>all in</strong> instead: every chip you have on one spin
        (what it wins is rounded down to a whole chip).
    </li>
</ul>
<div class="casino-pay-table-wrap docs-table-wrap">
<table class="casino-pay-table docs-table">
    <thead>
        <tr><th scope="col">On a line</th><th scope="col">Pays</th></tr>
    </thead>
    <tbody>
        <?php foreach (HBHUB_SLOTS_PAYS as $slotsSymbol => $slotsPays):
            [$slotsName, $slotsShown] = HBHUB_SLOTS_SYMBOLS[$slotsSymbol]; ?>
            <tr>
                <td><span class="casino-pay-symbols" aria-hidden="true"><?= str_repeat('<span class="casino-pay-symbol casino-pay-' . $slotsSymbol . '">' . $slotsShown . '</span>', 3) ?></span> 3 &times; <?= $slotsName ?></td>
                <td><?= $slotsPays ?>&times;</td>
            </tr>
        <?php endforeach; ?>
        <tr><td>Any 3 fruit, mixed (🍒 🍋 🍇)</td><td><?= HBHUB_SLOTS_PAY_MIXED_FRUIT ?>&times;</td></tr>
        <tr><td>🍒 🍒 on the first two reels</td><td><?= HBHUB_SLOTS_PAY_TWO_CHERRIES ?>&times;</td></tr>
        <tr><td>🍒 on the first reel</td><td><?= HBHUB_SLOTS_PAY_CHERRY ?>&times; (the line's share back)</td></tr>
    </tbody>
</table>
</div>
