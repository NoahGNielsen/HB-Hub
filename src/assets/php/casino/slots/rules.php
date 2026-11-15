<?php
// The rules of the slot machine as played here, with its pay table, shown on the Slots page and in the help docs. In
// Danish, like the docs (a public page), also on the game, so the symbols get Danish names here (slots.php's own names
// are the game's, in English). slots.php plays by them, and every number here comes from it, so the strips and the
// pays only change there. The pay table has docs-table too, so it looks like the docs' other tables there (slots.css
// styles it on the game).
require_once __DIR__ . '/slots.php';

// HBHUB_SLOTS_SYMBOLS' keys => the symbol's name in Danish, for the pay table
$slotsDanishNames = [
    'seven' => '7\'ere', 'diamond' => 'diamanter', 'star' => 'stjerner', 'bell' => 'klokker', 'grape' => 'vindruer',
    'lemon' => 'citroner', 'cherry' => 'kirsebær',
];
?>
<ul class="casino-rule-list" lang="da">
    <li>Tre hjul, tre rækker og <?= count(HBHUB_SLOTS_LINES) ?> gevinstlinjer: de tre rækker og de to diagonaler. Hvert spin spiller dem alle.</li>
    <li>
        Din indsats bliver delt ligeligt ud på linjerne, og en linje vinder det, den betaler, gange sin andel. En linje
        betaler kun sin bedste gevinst, og hver vindende linje bliver betalt. Så en indsats på 50 er 10 per linje, og tre
        klokker på en af dem betaler 250.
    </li>
    <li>
        Indsatser går fra <?= HBHUB_SLOTS_MIN_BET ?> i trin af <?= HBHUB_SLOTS_BET_STEP ?>. Med færre end
        <?= HBHUB_CASINO_ALL_IN_BELOW ?> chips kan du i stedet gå <strong>all in</strong>: alle dine chips på ét spin (det,
        det vinder, bliver rundet ned til en hel chip).
    </li>
</ul>
<div class="casino-pay-table-wrap docs-table-wrap" lang="da">
<table class="casino-pay-table docs-table">
    <thead>
        <tr><th scope="col">På en linje</th><th scope="col">Betaler</th></tr>
    </thead>
    <tbody>
        <?php foreach (HBHUB_SLOTS_PAYS as $slotsSymbol => $slotsPays):
            [$slotsName, $slotsShown] = HBHUB_SLOTS_SYMBOLS[$slotsSymbol]; ?>
            <tr>
                <td><span class="casino-pay-symbols" aria-hidden="true"><?= str_repeat('<span class="casino-pay-symbol casino-pay-' . $slotsSymbol . '">' . $slotsShown . '</span>', 3) ?></span> 3 &times; <?= htmlspecialchars($slotsDanishNames[$slotsSymbol] ?? $slotsName) ?></td>
                <td><?= $slotsPays ?>&times;</td>
            </tr>
        <?php endforeach; ?>
        <tr><td>3 blandede frugter (🍒 🍋 🍇)</td><td><?= HBHUB_SLOTS_PAY_MIXED_FRUIT ?>&times;</td></tr>
        <tr><td>🍒 🍒 på de første to hjul</td><td><?= HBHUB_SLOTS_PAY_TWO_CHERRIES ?>&times;</td></tr>
        <tr><td>🍒 på det første hjul</td><td><?= HBHUB_SLOTS_PAY_CHERRY ?>&times; (linjens andel tilbage)</td></tr>
    </tbody>
</table>
</div>
