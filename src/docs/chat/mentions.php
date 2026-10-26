<?php
// Help docs: @mentions - typing them, how a name is found (assets/php/mentions.php), who gets a notification, and the
// game chats they work in. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/chat/mentions';
$docsIntro = 'Want someone to see your message? Mention them with an @, and they get a notification.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="how" aria-labelledby="docs-how">
                        <h2 id="docs-how">Mentioning someone</h2>
                        <ol class="docs-steps">
                            <li>Type <code>@</code> in the message box. A list of the people in the chat pops up.</li>
                            <li>Keep typing to narrow it down, and pick someone with the mouse, or with <kbd>&uarr;</kbd> <kbd>&darr;</kbd> and <kbd>Enter</kbd> or <kbd>Tab</kbd>. <kbd>Esc</kbd> closes the list.</li>
                            <li>Send the message. The name lights up, like <span class="docs-mention">@Name</span>, and links to their profile.</li>
                        </ol>
                        <p>
                            You can also just type the name after the <code>@</code>. It works with spaces in names too, and upper or
                            lower case doesn't matter. A nickname works as well: in a DM the one you gave
                            someone, in a group the one they have there. An @ in the middle of a
                            word (like in an email address) isn't a mention.
                        </p>
                    </section>

                    <section id="told" aria-labelledby="docs-told">
                        <h2 id="docs-told">Who gets told</h2>
                        <p>Whoever you mention gets a notification under the <a href="/docs/notifications">bell</a>, and messages that mention you light up in the chat. They're not told when:</p>
                        <ul>
                            <li>they're looking at the chat already,</li>
                            <li>they've <a href="/docs/notifications#mute">muted</a> the chat,</li>
                            <li>or they've <a href="/docs/chat/safety#block">blocked</a> you.</li>
                        </ul>
                        <p>Only people in the chat can be mentioned. Someone who has left it since, or changed their name, just shows as text.</p>
                    </section>

                    <section id="games" aria-labelledby="docs-games">
                        <h2 id="docs-games">In game chats</h2>
                        <p>
                            Mentions work the same way in the chats of <a href="/docs/games/matador">Matador</a>,
                            <a href="/docs/games/chess">Chess</a> and <a href="/docs/games/uno">UNO</a> games, with the players
                            in that game. The notification takes them straight to the game.
                        </p>
                    </section>

                    <section id="nice" aria-labelledby="docs-nice">
                        <h2 id="docs-nice">Be nice with it</h2>
                        <p>
                            Mentioning someone over and over to annoy them is spam, and against the
                            <a href="/legal/code-of-conduct">Code of Conduct</a>.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
