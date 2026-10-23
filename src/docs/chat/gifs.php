<?php
// Help docs: GIFs and smileys - the GIF picker (Giphy, through chat/gifs.php so the key stays on the server) and the
// smiley menu, and reacting with emoji. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/chat/gifs';
$docsIntro = 'Sometimes a GIF or a smiley says it better than words.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="gifs" aria-labelledby="docs-gifs">
                        <h2 id="docs-gifs">Sending a GIF</h2>
                        <ol class="docs-steps">
                            <li>Press the <strong>GIF</strong> button next to the message box. It opens with what's trending.</li>
                            <li>Type in <strong>Search GIFs</strong> to find something else.</li>
                            <li>Click a GIF to send it straight away.</li>
                        </ol>
                        <p>
                            The GIFs come from <a href="https://giphy.com" target="_blank" rel="noopener noreferrer">Giphy</a>.
                            Your search goes through HB Hub's server, but the GIFs themselves are loaded from Giphy, which means
                            Giphy can see your IP address. More on that in the <a href="/legal/privacy">Privacy Policy</a>.
                            GIFs count towards the <a href="/docs/chat/safety#spam">spam limit</a> like any other message, and
                            have to follow the <a href="/legal/code-of-conduct">Code of Conduct</a> too.
                        </p>
                    </section>

                    <section id="smileys" aria-labelledby="docs-smileys">
                        <h2 id="docs-smileys">Smileys</h2>
                        <p>
                            The smiley button opens every emoji, sorted into tabs (faces, people, animals, food and so on). Click one
                            to put it in your message where the cursor is. You can of course also type emoji straight from your
                            phone's keyboard, or with <kbd>Win</kbd> + <kbd>.</kbd> on Windows and <kbd>Ctrl</kbd> + <kbd>&#8984;</kbd> + <kbd>Space</kbd> on a Mac.
                        </p>
                    </section>

                    <section id="reactions" aria-labelledby="docs-reactions">
                        <h2 id="docs-reactions">Reacting with emoji</h2>
                        <p>
                            Right-click a message to react to it. The menu has the emoji you've used most lately along the top, and
                            <strong>+</strong> for the rest. A message can have up to 20 different reactions. Point at one to see who
                            reacted, and click it to join in or take yours back. More in <a href="/docs/chat/#message-menu">Chat basics</a>.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
