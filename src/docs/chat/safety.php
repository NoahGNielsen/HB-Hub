<?php
// Help docs: staying safe in the chat - blocking (Block on a message request), the link warning and the URL blacklist
// (assets/php/urlBlacklist.php), the spam limit (HBHUB_CHAT_SEND_MAX in chat/index.php) and reporting someone, the
// same way the Code of Conduct explains it. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/chat/safety';
$docsIntro = 'HB Hub should feel like a friendly corner of school. Here\'s what keeps it that way, and what you can do
    when it isn\'t.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="block" aria-labelledby="docs-block">
                        <h2 id="docs-block">Blocking someone</h2>
                        <p>
                            Press <strong>Block</strong> on a <a href="/docs/chat/#requests">message request</a> to decline it and
                            stop that person from sending you new requests: they can't start a chat with you or add you to groups,
                            and their @mentions don't notify you. They aren't told that you blocked them.
                        </p>
                        <p>
                            Starting a chat with them yourself unblocks them again. For someone you already have a chat with, you
                            can mute, hide or leave it from the <a href="/docs/chat/#chat-menu">chat menu</a>.
                        </p>
                    </section>

                    <section id="links" aria-labelledby="docs-links">
                        <h2 id="docs-links">Link safety</h2>
                        <ul>
                            <li><strong>Every link asks first.</strong> Clicking a link in a message shows where it goes before you leave HB Hub, so you can decide if you trust it.</li>
                            <li>
                                <strong>Some links can't be sent at all</strong>, in chats, game chats or bios: IP loggers (which find
                                out where you are the moment you click), URL shorteners (which can hide where a link really goes), and
                                known crypto scam and phishing sites. Trying shows <strong>Link blocked</strong>.
                            </li>
                            <li><strong>Masked links can't lie</strong>: one whose text looks like an address shows the real address instead. See <a href="/docs/chat/formatting#links">Links</a>.</li>
                        </ul>
                        <p>Changing a link to sneak a blocked one through is against the <a href="/legal/code-of-conduct">Code of Conduct</a>, just like sending it.</p>
                    </section>

                    <section id="spam" aria-labelledby="docs-spam">
                        <h2 id="docs-spam">The spam limit</h2>
                        <p>
                            Everyone can send up to <strong>10 messages every 5 seconds</strong>, in all their chats together (files
                            and GIFs count too). Send one more, and you get <strong>Woah! Slow down!</strong>: the message isn't
                            sent, but it stays in the message box, so you can send it a moment later.
                        </p>
                    </section>

                    <section id="report" aria-labelledby="docs-report">
                        <h2 id="docs-report">Reporting someone</h2>
                        <p>If someone is mean, makes you feel unsafe or breaks the rules:</p>
                        <ol class="docs-steps">
                            <li>Press <strong>Feedback</strong> at the bottom of the side bar (only site admins can read it), tell a site admin, or email <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.</li>
                            <li>Say who it was, which chat or game it happened in, and roughly when.</li>
                            <li>Mute, hide or leave the chat in the meantime if you like. You don't have to keep reading it.</li>
                        </ol>
                        <p>Reports are kept private, and the person you report isn't told who reported them. You won't get in trouble for reporting.</p>
                        <p class="legal-note">
                            If something is serious, like threats or someone being in danger, also talk to a teacher, a parent or
                            another adult you trust. If someone is in immediate danger, call 112.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
