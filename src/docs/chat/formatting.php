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
    ['**bold**', false],
    ['*italic* or _italic_', false],
    ['__underline__', false],
    ['~~strikethrough~~', false],
    ['***bold and italic*** and **bold with __underline__ in it**', true],
];
const DOCS_FORMAT_CODE = [
    ['Type `npm install` to start', false],
    ["```js\nconst answer = 42;\nconsole.log(answer);\n```", true],
];
const DOCS_FORMAT_BLOCKS = [
    ["# Big heading\n## Smaller heading\n### Smallest heading", false],
    ["Shopping list:\n- milk\n- bread\n  - the brown one\n- cheese", true],
    ["1. Open the chat\n2. Press New chat\n3. Say hi", false],
];
const DOCS_FORMAT_LINKS = [
    ['https://hbhub.noahgajnielsen.dk', false],
    ['Read the [Code of Conduct](https://hbhub.noahgajnielsen.dk/legal/code-of-conduct)', true],
];
const DOCS_FORMAT_ESCAPE = [
    ['\*not italic\* and 2 \* 3 = 6', false],
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
            <span class="docs-format-head">Type this</span>
            <span class="docs-format-head">You get</span>
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
$docsIntro = 'Make your messages stand out with bold, italic, code, headings, lists and links. It works like Discord:
    put a few characters around your text, and the chat does the rest.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="menu" aria-labelledby="docs-menu">
                        <h2 id="docs-menu">The quick way</h2>
                        <p>
                            Select some text in the message box and right-click it: the formatting menu puts the right characters
                            around it for you. Or use the shortcuts:
                        </p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Shortcut</th><th scope="col">Does</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><kbd>Ctrl</kbd> + <kbd>B</kbd></td><td><strong>Bold</strong></td></tr>
                                    <tr><td><kbd>Ctrl</kbd> + <kbd>I</kbd></td><td><em>Italic</em></td></tr>
                                    <tr><td><kbd>Ctrl</kbd> + <kbd>U</kbd></td><td><u>Underline</u></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            On a Mac, use <kbd>&#8984;</kbd> instead of <kbd>Ctrl</kbd>. The menu also has strikethrough, code, a
                            code block, a heading, lists and a link, plus cut, copy and paste. <kbd>Shift</kbd> + right-click opens
                            your browser's own menu instead. It works when you're editing a message too.
                        </p>
                    </section>

                    <section id="text" aria-labelledby="docs-text">
                        <h2 id="docs-text">Bold, italic, underline and strikethrough</h2>
                        <?php docsFormatExamples(DOCS_FORMAT_TEXT); ?>
                        <p>
                            They can go inside each other. <code>_italic_</code> with underscores needs a space or punctuation on
                            both sides, so words like <code>snake_case_names</code> stay as they are.
                        </p>
                    </section>

                    <section id="code" aria-labelledby="docs-code">
                        <h2 id="docs-code">Code</h2>
                        <p>
                            One backtick (<code>`</code>) on each side for a bit of code in a sentence, three for a code block
                            over several lines. Nothing inside code is formatted, so it's also a good way to show stars and
                            underscores as they are. A word right after the first three backticks (like <code>js</code>) says
                            which language it is, and isn't shown.
                        </p>
                        <?php docsFormatExamples(DOCS_FORMAT_CODE); ?>
                    </section>

                    <section id="blocks" aria-labelledby="docs-blocks">
                        <h2 id="docs-blocks">Headings and lists</h2>
                        <p>
                            These go at the start of a line. A heading is one to three <code>#</code> and a space. A list is
                            <code>-</code> or <code>*</code> and a space, a numbered list a number and a dot. Indent a line with
                            two spaces to put it in a list inside the one above (up to three deep).
                        </p>
                        <?php docsFormatExamples(DOCS_FORMAT_BLOCKS); ?>
                    </section>

                    <section id="links" aria-labelledby="docs-links">
                        <h2 id="docs-links">Links</h2>
                        <p>
                            Addresses starting with <code>http://</code> or <code>https://</code> become links by themselves.
                            To show some words instead of the address, put the words in square brackets and the address in
                            brackets right after: a masked link.
                        </p>
                        <?php docsFormatExamples(DOCS_FORMAT_LINKS); ?>
                        <p>
                            Pointing at a link shows where it really goes, and every link asks before it opens. A masked link whose
                            text looks like an address shows the real address instead, so nobody can make a link look like it goes
                            somewhere it doesn't. Some links can't be sent at all: see <a href="/docs/chat/safety#links">Link safety</a>.
                        </p>
                    </section>

                    <section id="mentions" aria-labelledby="docs-mentions">
                        <h2 id="docs-mentions">Mentions</h2>
                        <p>
                            Type <code>@</code> and pick someone from the list to mention them. Their name lights up like
                            <span class="docs-mention">@Name</span>, and they get a notification. More in
                            <a href="/docs/chat/mentions">@mentions</a>.
                        </p>
                    </section>

                    <section id="escape" aria-labelledby="docs-escape">
                        <h2 id="docs-escape">Showing the characters themselves</h2>
                        <p>
                            Want a star or an underscore without it turning into formatting? Put a backslash (<code>\</code>)
                            in front of it.
                        </p>
                        <?php docsFormatExamples(DOCS_FORMAT_ESCAPE); ?>
                        <p>
                            The formatting is only how a message is shown. Edit it, and you get back exactly what you typed. In
                            the chat list, replies and notifications, a message is shown as plain text without the stars.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
