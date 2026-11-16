<?php
// Help docs: styling messages - every kind of formatting the chat understands, the formatting menu and its shortcuts.
// Each example is turned into a message by the chat's own chatFormatHtml() (assets/php/chatFormat.php), so what's
// shown here is exactly what the chat shows, and a change there shows up here by itself. Open to everyone.
// Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chatFormat.php';

// One group of examples per section: [what's typed, whether it's shown as your own (orange) message]
const DOCS_FORMAT_TEXT = [
    ['**fed**', false],
    ['*kursiv* eller _kursiv_', false],
    ['__understreget__', false],
    ['~~gennemstreget~~', false],
    ['***fed og kursiv*** og **fed med __understregning__ i**', true],
];
const DOCS_FORMAT_CODE = [
    ['Skriv `npm install` for at starte', false],
    ["```js\nconst answer = 42;\nconsole.log(answer);\n```", true],
];
const DOCS_FORMAT_BLOCKS = [
    ["# Stor overskrift\n## Mindre overskrift\n### Mindste overskrift", false],
    ["Indkøbsliste:\n- mælk\n- brød\n  - det grove\n- ost", true],
    ["1. Åbn chatten\n2. Tryk New chat\n3. Sig hej", false],
];
const DOCS_FORMAT_LINKS = [
    ['https://hbhub.noahgajnielsen.dk', false],
    ['Læs [adfærdskodekset](https://hbhub.noahgajnielsen.dk/legal/code-of-conduct)', true],
];
const DOCS_FORMAT_ESCAPE = [
    ['\*ikke kursiv\* og 2 \* 3 = 6', false],
];

/**
 * Examples as rows: what to type next to the message it makes.
 *
 * @param array<array{string, bool}> $examples
 */
function docsFormatExamples(array $examples): void
{
    ?>
    <div class="docs-format">
        <div class="docs-format-row" aria-hidden="true">
            <span class="docs-format-head">Skriv det her</span>
            <span class="docs-format-head">Du får</span>
        </div>
        <?php foreach ($examples as [$typed, $isOwn]): ?>
            <div class="docs-format-row">
                <pre class="docs-format-typed"><?= htmlspecialchars($typed) ?></pre>
                <div class="docs-bubble<?= $isOwn ? ' is-own' : '' ?>"><?= chatFormatHtml($typed) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

$docsPath = '/docs/chat/formatting';
$docsIntro = 'Få dine beskeder til at skille sig ud med fed, kursiv, kode, overskrifter, lister og links. Det virker som
    på Discord: sæt et par tegn rundt om din tekst, så klarer chatten resten.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="menu" aria-labelledby="docs-menu">
                        <h2 id="docs-menu">Den hurtige måde</h2>
                        <p>
                            Markér noget tekst i beskedfeltet, og højreklik på den: formateringsmenuen sætter de rigtige tegn rundt
                            om den for dig. Eller brug genvejene:
                        </p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Genvej</th><th scope="col">Gør</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><kbd>Ctrl</kbd> + <kbd>B</kbd></td><td><strong>Fed</strong></td></tr>
                                    <tr><td><kbd>Ctrl</kbd> + <kbd>I</kbd></td><td><em>Kursiv</em></td></tr>
                                    <tr><td><kbd>Ctrl</kbd> + <kbd>U</kbd></td><td><u>Understreget</u></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            På en Mac bruger du <kbd>&#8984;</kbd> i stedet for <kbd>Ctrl</kbd>. Menuen har også gennemstregning,
                            kode, en kodeblok, en overskrift, lister og et link, plus klip, kopiér og indsæt. <kbd>Shift</kbd> +
                            højreklik åbner i stedet din browsers egen menu. Det virker også, når du retter en besked.
                        </p>
                    </section>

                    <section id="text" aria-labelledby="docs-text">
                        <h2 id="docs-text">Fed, kursiv, understreget og gennemstreget</h2>
                        <?php docsFormatExamples(DOCS_FORMAT_TEXT); ?>
                        <p>
                            De kan stå inden i hinanden. <code>_kursiv_</code> med understregningstegn skal have et mellemrum eller
                            tegnsætning på begge sider, så ord som <code>snake_case_navne</code> bliver, som de er.
                        </p>
                    </section>

                    <section id="code" aria-labelledby="docs-code">
                        <h2 id="docs-code">Kode</h2>
                        <p>
                            Én backtick (<code>`</code>) på hver side giver et stykke kode i en sætning, tre giver en kodeblok over
                            flere linjer. Intet inden i kode bliver formateret, så det er også en god måde at vise stjerner og
                            understregningstegn, som de er. Et ord lige efter de første tre backticks (som <code>js</code>) siger,
                            hvilket sprog det er, og bliver ikke vist.
                        </p>
                        <?php docsFormatExamples(DOCS_FORMAT_CODE); ?>
                    </section>

                    <section id="blocks" aria-labelledby="docs-blocks">
                        <h2 id="docs-blocks">Overskrifter og lister</h2>
                        <p>
                            De skal stå i starten af en linje. En overskrift er én til tre <code>#</code> og et mellemrum. En liste er
                            <code>-</code> eller <code>*</code> og et mellemrum, en nummereret liste et tal og et punktum. Ryk en linje
                            ind med to mellemrum for at lægge den i en liste inden i den ovenover (op til tre niveauer).
                        </p>
                        <?php docsFormatExamples(DOCS_FORMAT_BLOCKS); ?>
                    </section>

                    <section id="links" aria-labelledby="docs-links">
                        <h2 id="docs-links">Links</h2>
                        <p>
                            Adresser, der starter med <code>http://</code> eller <code>https://</code>, bliver selv til links. For at
                            vise nogle ord i stedet for adressen sætter du ordene i firkantede parenteser og adressen i parenteser
                            lige efter: et maskeret link.
                        </p>
                        <?php docsFormatExamples(DOCS_FORMAT_LINKS); ?>
                        <p>
                            Peger du på et link, kan du se, hvor det i virkeligheden fører hen, og hvert link spørger, før det
                            åbner. Et maskeret link, hvis tekst ligner en adresse, viser i stedet den rigtige adresse, så ingen kan få
                            et link til at se ud, som om det fører et andet sted hen. Nogle links kan slet ikke sendes: se
                            <a href="/docs/chat/safety#links">Sikre links</a>.
                        </p>
                    </section>

                    <section id="mentions" aria-labelledby="docs-mentions">
                        <h2 id="docs-mentions">Omtaler</h2>
                        <p>
                            Skriv <code>@</code>, og vælg nogen fra listen for at omtale dem. Deres navn lyser op som
                            <span class="docs-mention">@Navn</span>, og de får en notifikation. Mere i
                            <a href="/docs/chat/mentions">@omtaler</a>.
                        </p>
                    </section>

                    <section id="escape" aria-labelledby="docs-escape">
                        <h2 id="docs-escape">At vise selve tegnene</h2>
                        <p>
                            Vil du have en stjerne eller et understregningstegn, uden at det bliver til formatering? Sæt en
                            backslash (<code>\</code>) foran.
                        </p>
                        <?php docsFormatExamples(DOCS_FORMAT_ESCAPE); ?>
                        <p>
                            Formateringen er kun, hvordan en besked bliver vist. Retter du den, får du præcis det tilbage, du skrev. I
                            chatlisten, svar og notifikationer bliver en besked vist som almindelig tekst uden stjernerne.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
