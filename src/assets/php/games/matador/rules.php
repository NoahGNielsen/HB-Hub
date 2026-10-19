<?php
// The rules of Matador as played here, shown on the Matador page and in the game. game.php and room.php play by them,
// so change both together.
?>
<ul class="casino-rule-list">
    <li>
        Everyone starts with <?= hbHubMatadorKr(HBHUB_MATADOR_START_CASH) ?> on START. The turn order is drawn when the game starts.
        You get <?= hbHubMatadorKr(HBHUB_MATADOR_START_PAY) ?> every time you pass or land on START.
    </li>
    <li>
        You roll one die. A 6 gives you another roll, as many times as you roll it. The host can choose 2 dice in the
        room's settings before the game starts: then doubles give you another roll instead.
    </li>
    <li>
        Land on a street, a ferry or a brewery nobody owns, and you can buy it. If you don't, it stays with the bank,
        and the next player to land there can buy it.
    </li>
    <li>
        Land on someone else's property and you pay them rent. Owning every street in a colour doubles the rent while it's unbuilt.
        Ferries take more the more of them the owner has, and breweries take 100 or 200 times your roll.
    </li>
    <li>
        Own every street in a colour to build houses on it in your turn, evenly, and a hotel after 4 houses. The bank has
        <?= HBHUB_MATADOR_BANK_HOUSES ?> houses and <?= HBHUB_MATADOR_BANK_HOTELS ?> hotels. Selling them back gives half the price.
    </li>
    <li>
        Mortgage a property in your turn for half its price (sell the houses in its colour first). No rent is paid for it while it's mortgaged,
        and paying it back costs <?= HBHUB_MATADOR_INTEREST_PERCENT ?> % on top.
    </li>
    <li>
        Indkomstskat: pay <?= hbHubMatadorKr(HBHUB_MATADOR_TAX_FIXED) ?> or <?= HBHUB_MATADOR_TAX_PERCENT ?> % of everything you're worth,
        chosen before it's worked out. Ekstraordinær statsskat is <?= hbHubMatadorKr(HBHUB_MATADOR_FIELDS[38]['amount']) ?>.
        Parkering is a free rest.
    </li>
    <li>
        De fængsles sends you to jail. To get out, pay <?= hbHubMatadorKr(HBHUB_MATADOR_BAIL) ?> before you roll, use a
        "benådet" card, or roll a 6 (doubles with 2 dice). After <?= HBHUB_MATADOR_JAIL_TRIES ?> tries you pay and move what you rolled.
        Whether you still get rent while you're in jail is a room setting: yes (the default), no, or it's paid to the bank instead.
    </li>
    <li>Prøv lykken: draw a card and do what it says. The "benådet" cards are kept until they're used, or traded.</li>
    <li>
        Trade with the others at any time, also in their turns: properties (with nothing built in their colour), money and "benådet" cards.
        Whoever gets a mortgaged property pays the <?= HBHUB_MATADOR_INTEREST_PERCENT ?> % interest on it straight away.
    </li>
    <li>
        Can't pay? Sell houses, mortgage or trade to raise the money, or go bankrupt: everything goes to the one you owe
        (or back to the bank). The last player left wins.
    </li>
    <li>
        You have <?= HBHUB_MATADOR_TURN_SECONDS ?> seconds to do something in your turn, and the time starts again with
        everything you do. Run out of time and your turn is passed on: a property you landed on stays with the bank.
        While you owe money the timer is paused, and the game waits for you to pay or go bankrupt.
    </li>
    <li>
        The game is saved after every move, in the save slot of whoever opened it. Leave, and your seat is kept for you while
        your turns are skipped. The host can give an empty seat to someone else, or save the game and stop it.
    </li>
</ul>
