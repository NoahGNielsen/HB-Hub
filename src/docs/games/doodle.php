<?php
// Help docs: Doodle Chain - how a game goes, the game's own rules (assets/php/games/doodle/rules.php, included as the
// game shows them) and the host's setting (HBHUB_DOODLE_DRAW_CHOICES in game.php). Open to everyone.
// Built by assets/php/docs/top.php and bottom.php. Buttons keep the English names the game shows them by.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/doodle/room.php';

$docsPath = '/docs/games/doodle';
$docsIntro = 'Skriv en sætning, tegn det, du får, gæt, hvad tegningen forestiller, og se til sidst, hvor langt det kom fra
    starten. Spil det på <a href="/games/doodle/">Doodle Chain</a>-siden.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Spillere</dt><dd><?= HBHUB_DOODLE_MIN_PLAYERS ?>-<?= HBHUB_DOODLE_MAX_PLAYERS ?></dd></div>
                        <div><dt>Runder</dt><dd><?= 2 * hbHubDoodleDrawings(HBHUB_DOODLE_MIN_PLAYERS) + 1 ?>-<?= 2 * hbHubDoodleDrawings(HBHUB_DOODLE_MAX_PLAYERS) + 1 ?>, efter antal spillere</dd></div>
                        <div><dt>At skrive</dt><dd><?= HBHUB_DOODLE_WRITE_SECONDS ?> sekunder</dd></div>
                        <div><dt>At tegne</dt><dd><?= implode('-', [HBHUB_DOODLE_DRAW_CHOICES[0], HBHUB_DOODLE_DRAW_CHOICES[count(HBHUB_DOODLE_DRAW_CHOICES) - 1]]) ?> sekunder</dd></div>
                    </dl>

                    <section id="game" aria-labelledby="docs-game">
                        <h2 id="docs-game">Et spil</h2>
                        <ol class="docs-steps">
                            <li>Alle skriver en sætning, som en anden skal tegne, fx "en kat på skateboard", og trykker på <strong>Done</strong>.</li>
                            <li>Du får en andens sætning og tegner den. Under tegningen vælger du værktøj (pen, streg, cirkel, firkant, trekant, stjerne og fyld), farve (også din egen med farvevælgeren til sidst) og størrelse. Former trækkes ud med musen eller fingeren, og holder du Shift nede, bliver de lige (runde cirkler, kvadrater og streger i hver 45 grader), og en trekant, du trækker opad, vender på hovedet. Du kan også tegne med tastaturet: piletasterne flytter en markør rundt på tegningen, og holder du mellemrumstasten nede, tegner den, ligesom når du holder museknappen nede. <strong>Undo</strong> fjerner det sidste, du lavede, og <strong>Clear</strong> starter forfra.</li>
                            <li>Du får en andens tegning og skriver, hvad du tror, den forestiller.</li>
                            <li>Sådan fortsætter det, til hver kæde er færdig. Jo flere I er, jo flere tegninger er der i hver kæde, og den sidste runde er altid et gæt.</li>
                            <li>Værten viser kæderne én del ad gangen med <strong>Show next</strong>. Bagefter kan alle kæder vises igen, og værten kan starte et nyt spil med <strong>New game</strong>.</li>
                        </ol>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/doodle/rules.php'; ?>
                    </section>

                    <section id="settings" aria-labelledby="docs-settings">
                        <h2 id="docs-settings">Værtens indstilling</h2>
                        <p>
                            Værten vælger tiden til at tegne under <strong>Game settings</strong> (knappen ved siden af
                            <strong>Start game</strong>, eller ikonet øverst): <?= implode(', ', array_slice(HBHUB_DOODLE_DRAW_CHOICES, 0, -1)) ?>
                            eller <?= HBHUB_DOODLE_DRAW_CHOICES[count(HBHUB_DOODLE_DRAW_CHOICES) - 1] ?> sekunder
                            (<?= HBHUB_DOODLE_DEFAULT_SETTINGS['drawSeconds'] ?> som standard). Alle andre kan se den samme sted. Den
                            kan ændres før et spil og mellem spil, og nye spillere kan også komme med mellem spil.
                        </p>

                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
