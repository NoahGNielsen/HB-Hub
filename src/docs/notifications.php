<?php
// Help docs: notifications - the bell (assets/php/notificationTypes/ lists what lands there), browser notifications
// and the message sound from the settings page, and muting chats. Open to everyone. Built by assets/php/docs/top.php
// and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/notifications';
$docsIntro = 'HB Hub tells you when something happens in three ways: the bell in the top bar, a notification from your
    browser, and a sound. Only the bell is on to begin with.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="bell" aria-labelledby="docs-bell">
                        <h2 id="docs-bell">The bell</h2>
                        <p>
                            The bell in the top bar shows a red number when there's something new. Open it to see your newest
                            notifications, and press one to go to what it's about.
                        </p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">From</th><th scope="col">What you're told about</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Chat</td><td>Message requests, <a href="/docs/chat/mentions">@mentions</a>, being kicked from a group, being made admin or no longer admin, and someone leaving your DM.</td></tr>
                                    <tr><td>Casino</td><td>Invites to a Blackjack or Baccarat table.</td></tr>
                                    <tr><td>Games</td><td>Invites to Matador, Chess and UNO, @mentions in their game chats, a seat given to you in a Matador game, and a saved Matador game being continued.</td></tr>
                                    <tr><td>Feedback</td><td>An admin marking your <a href="/docs/feedback">feedback</a> as done, with their answer.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>New messages themselves don't go under the bell: they show as a red number on Chat in the side bar instead.</p>
                    </section>

                    <section id="browser" aria-labelledby="docs-browser">
                        <h2 id="docs-browser">Browser notifications and the message sound</h2>
                        <p>Both are in the <strong>Notifications</strong> card in your <a href="/otherPages/settings#notifications">settings</a>:</p>
                        <ul>
                            <li>
                                <strong>Notify me when I get a new message</strong> shows a pop-up from your browser. The first time,
                                your browser asks if HB Hub may show notifications: press Allow. If you pressed Block by mistake, allow
                                it again in your browser's site settings (the icon left of the address).
                            </li>
                            <li>
                                <strong>Play a sound when I get a new message</strong> plays a short sound, but not for the chat
                                you're looking at. Browsers only let a page play sound after you've clicked or typed on it.
                            </li>
                        </ul>
                        <p class="legal-note">
                            Both only work while HB Hub is open in a tab somewhere, a background tab is fine. Close every HB Hub tab
                            and they stop until you open it again.
                        </p>
                    </section>

                    <section id="mute" aria-labelledby="docs-mute">
                        <h2 id="docs-mute">Muting a chat</h2>
                        <p>
                            Too much going on in a group? Right-click it in the chat list (or long-press it on a phone) and pick
                            <strong>Mute chat</strong>, for 15 minutes, 1, 3, 8 or 24 hours, or for good. A muted chat sends no
                            browser notifications, sounds or @mention notifications, but its messages still come in. Unmute it
                            the same way.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
