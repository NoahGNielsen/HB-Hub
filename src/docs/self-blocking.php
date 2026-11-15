<?php
// Help docs: blocking the Casino and Games for yourself (assets/php/block.php) - the two blocks, why turning one down
// waits, and a block set by an admin. The hours come from block.php's constants. Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/block.php';

/**
 * An hour of the day the Danish way, like "kl. 8" or "kl. 15".
 */
function docsHour(int $hour): string
{
    return 'kl. ' . $hour;
}

$docsPath = '/docs/self-blocking';
$docsIntro = 'Spiller du for meget, når du burde være i timen? Bloker Casino og Spil for dig selv, så hjælper HB Hub
    dig med at holde fast i det.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="how" aria-labelledby="docs-how">
                        <h2 id="docs-how">At slå en blokering til</h2>
                        <p>
                            Åbn <strong>Gambling and Games</strong> i gruppen <strong>Preferences</strong> under dine
                            <a href="/settings#block">indstillinger</a>, og vælg en blokering. Den bliver gemt med det samme. Den dækker både
                            <a href="/docs/casino/">casinoet</a> og <a href="/docs/games/">spillene</a>.
                        </p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Blokering</th><th scope="col">Hvad den gør</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>No block</td><td>Alt er åbent.</td></tr>
                                    <tr><td>During school hours</td><td>Casino og Spil er lukket fra <?= docsHour(HBHUB_BLOCK_SCHOOL_START) ?> til <?= docsHour(HBHUB_BLOCK_SCHOOL_END) ?>, mandag til fredag (dansk tid).</td></tr>
                                    <tr><td>All the time</td><td>Casino og Spil er altid lukket.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>Mens en blokering er slået til, viser en casino- eller spilside i stedet en side, der siger, at Gambling and Games er blokeret. Chatten virker altid.</p>
                    </section>

                    <section id="wait" aria-labelledby="docs-wait">
                        <h2 id="docs-wait">Hvorfor det venter at slå den fra</h2>
                        <p>
                            En strengere blokering starter med det samme. At skrue ned for en gør ikke, så den ikke kan slås fra i
                            et svagt øjeblik:
                        </p>
                        <ul>
                            <li>Fra <strong>During school hours</strong>, i skoletiden: den venter til <?= docsHour(HBHUB_BLOCK_SCHOOL_END) ?>.</li>
                            <li>Fra <strong>All the time</strong>: den venter <?= HBHUB_BLOCK_ALWAYS_DELAY / 3600 ?> timer.</li>
                        </ul>
                        <p>Indstillingssiden viser, hvornår ændringen træder i kraft. Indtil da gælder den strengere blokering stadig.</p>
                    </section>

                    <section id="admin" aria-labelledby="docs-admin">
                        <h2 id="docs-admin">En blokering fra en admin</h2>
                        <p>
                            Side-admins kan også blokere Casino og Spil for nogen. Det virker som "All the time", oven i din egen
                            blokering. Under indstillinger står der så <strong>Blocked by an admin</strong>, og kun en admin kan
                            ophæve den.
                        </p>
                        <p>
                            Uafhængigt af alt det kan hele Casino- eller Spil-sektionen lukkes for alle af dem, der driver HB Hub.
                            Så er den lukket, uanset hvad din blokering siger.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
