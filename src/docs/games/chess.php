<?php
// Help docs: Chess - starting a game, the clocks (HBHUB_CHESS_CLOCKS in games/chess/game.php), the game's own rules
// (assets/php/games/chess/rules.php, included as the game shows them, with HB Hub's promotion rule), moving pieces,
// and the leaderboard's score. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/room.php';

$docsPath = '/docs/games/chess';
$docsIntro = 'To spillere, alle skakkens regler og ét twist ved forvandling. Udfordr en ven på
    <a href="/games/chess/">skak</a>-siden.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Spillere</dt><dd>2</dd></div>
                        <div><dt>Ure</dt><dd><?= count(HBHUB_CHESS_CLOCKS) - 1 ?> + intet</dd></div>
                        <div><dt>Standardur</dt><dd><?= htmlspecialchars(HBHUB_CHESS_CLOCK_NAMES[HBHUB_CHESS_DEFAULT_CLOCK]) ?></dd></div>
                    </dl>

                    <section id="start" aria-labelledby="docs-start">
                        <h2 id="docs-start">At starte et spil</h2>
                        <ol class="docs-steps">
                            <li>Åbn et spil på skaksiden: offentligt, med en kode eller kun for inviterede (se <a href="/docs/games/#rooms">At åbne et spil</a>).</li>
                            <li>Vælg uret, og hvem der spiller hvid (dig, dem eller tilfældigt), i spillets indstillinger.</li>
                            <li>Når nogen er kommet med, trykker du start. Hvid trækker først.</li>
                        </ol>
                    </section>

                    <section id="clocks" aria-labelledby="docs-clocks">
                        <h2 id="docs-clocks">Urene</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Ur</th><th scope="col">Hver spiller får</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (HBHUB_CHESS_CLOCKS as $docsClock => $docsClockTime): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(HBHUB_CHESS_CLOCK_NAMES[$docsClock]) ?></td>
                                            <td>
                                                <?php if ($docsClockTime === null): ?>
                                                    Al den tid i verden
                                                <?php else: ?>
                                                    <?= $docsClockTime[0] ?> minutter<?= $docsClockTime[1] > 0 ? ', plus ' . $docsClockTime[1] . ' sekunder for hvert træk' : '' ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="moving" aria-labelledby="docs-moving">
                        <h2 id="docs-moving">At flytte brikker</h2>
                        <p>
                            Klik på en brik for at se, hvor den kan gå hen, og klik så, hvor du vil have den, eller træk den derhen.
                            Kun lovlige træk kan spilles, så du kan ikke komme til at sætte dig selv skak ved et uheld.
                        </p>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/chess/rules.php'; ?>
                    </section>

                    <section id="promotion" aria-labelledby="docs-promotion">
                        <h2 id="docs-promotion">Forvandlingens twist, forklaret</h2>
                        <p>
                            I normal skak kan en bonde, der når den sidste række, blive til en hvilken som helst brik, så du kunne have
                            tre dronninger. Her kan den kun blive til en brik, du har mistet. Lad os sige, at din modstander har taget
                            en af dine springere og intet andet: en bonde, der kommer til enden, kan kun blive til en springer. Har de
                            ikke taget nogen af dine brikker endnu, venter bonden bare dér og bliver til en, i det øjeblik de gør.
                        </p>
                    </section>

                    <section id="leaderboard" aria-labelledby="docs-leaderboard">
                        <h2 id="docs-leaderboard">Rangliste</h2>
                        <p>
                            <a href="/games/chess/leaderboard">Skak-ranglisten</a> tæller dine spil, sejre, remiser og nederlag og din
                            hurtigste skakmat. Scoren er ét point for hver sejr og et halvt point for hver remis, ud af alle dine
                            spil, og tæller først fra 5 spil. Afbrudte spil tæller ikke. At forlade et spil tæller som et nederlag,
                            medmindre værten havde sat det på pause.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
