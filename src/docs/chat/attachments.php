<?php
// Help docs: files and pictures in the chat - sending them, the picture viewer, and attachment tickets. The numbers are
// chatAttachments.php's own constants, so they stay right when they change. Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/chatAttachments.php';

$docsTicketMb = HBHUB_CHAT_ATTACHMENT_TICKET_BYTES / 1024 / 1024;
$docsMaxMb = HBHUB_CHAT_ATTACHMENT_MAX_BYTES / 1024 / 1024;

$docsPath = '/docs/chat/attachments';
$docsIntro = 'Send photos, documents and anything else in any chat, up to ' . HBHUB_CHAT_ATTACHMENT_FILES_MAX . ' files
    at a time.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Files per message</dt><dd>Up to <?= HBHUB_CHAT_ATTACHMENT_FILES_MAX ?></dd></div>
                        <div><dt>Size</dt><dd><?= $docsMaxMb ?> MB together</dd></div>
                        <div><dt>Tickets</dt><dd><?= HBHUB_CHAT_ATTACHMENT_TICKETS ?> &times; <?= $docsTicketMb ?> MB</dd></div>
                        <div><dt>Kept for</dt><dd><?= HBHUB_CHAT_ATTACHMENT_DAYS ?> days</dd></div>
                    </dl>

                    <section id="send" aria-labelledby="docs-send">
                        <h2 id="docs-send">Sending files and pictures</h2>
                        <p>There are three ways to attach something:</p>
                        <ul>
                            <li>Press the attach button next to the message box and pick the files.</li>
                            <li>Paste a picture or file with <kbd>Ctrl</kbd> + <kbd>V</kbd>.</li>
                            <li>Drag files onto the chat and drop them.</li>
                        </ul>
                        <p>
                            They wait as small preview tiles above the message box, so you can take one out again or write a message
                            to go with them. Then send as normal. Several pictures in one message show as a gallery.
                        </p>
                    </section>

                    <section id="smaller" aria-labelledby="docs-smaller">
                        <h2 id="docs-smaller">Pictures are made smaller</h2>
                        <p>
                            Before a picture is sent, your browser turns it into JPEG XL, a newer kind of picture file that takes
                            much less space. It doesn't lose any detail, except JPEG photos, which get one that looks the same. Tick
                            <strong>Keep original pictures</strong> to send them exactly as they are instead. Other files are zipped
                            on the server and unzipped again when someone downloads them, so you get back the same file.
                        </p>
                    </section>

                    <section id="viewer" aria-labelledby="docs-viewer">
                        <h2 id="docs-viewer">The picture viewer</h2>
                        <p>Click a picture to open it big. In the viewer:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Key</th><th scope="col">Does</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><kbd>&larr;</kbd> <kbd>&rarr;</kbd></td><td>The previous or next picture in the message</td></tr>
                                    <tr><td><kbd>+</kbd> <kbd>-</kbd></td><td>Zoom in and out (or <kbd>Ctrl</kbd> + the mouse wheel)</td></tr>
                                    <tr><td><kbd>0</kbd></td><td>Back to the normal size</td></tr>
                                    <tr><td><kbd>Esc</kbd></td><td>Close it</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>The Download button saves the file to your device.</p>
                    </section>

                    <section id="tickets" aria-labelledby="docs-tickets">
                        <h2 id="docs-tickets">How tickets work</h2>
                        <p>
                            The server only has so much space, so attachments are paid for with tickets. Everyone has
                            <strong><?= HBHUB_CHAT_ATTACHMENT_TICKETS ?> tickets</strong>.
                        </p>
                        <ul>
                            <li>Every started <?= $docsTicketMb ?> MB of a file takes one ticket. A 1 MB photo takes 1, a 12 MB video takes 3. Each file pays for itself, so three small files take three tickets.</li>
                            <li>A file keeps its tickets for <?= HBHUB_CHAT_ATTACHMENT_DAYS ?> days. Then it's deleted and the tickets are yours again. A big file, taking more than <?= HBHUB_CHAT_ATTACHMENT_BIG_TICKETS ?> tickets, only stays for <?= HBHUB_CHAT_ATTACHMENT_BIG_DAYS ?> days.</li>
                            <li>Deleting the message, or just the attachment, gives the tickets back straight away.</li>
                            <li>Site admins can give someone more tickets, or take some away.</li>
                        </ul>
                        <p>
                            <strong>Your attachments</strong>, in the attach button's menu, shows what's taking your tickets and when
                            each one runs out, and deletes any you don't need any more. If you try to send more than you have free
                            tickets for, it says how many are missing, and <strong>Send now</strong> sends them once enough are free.
                        </p>
                        <p class="legal-note">
                            HB Hub isn't a backup: attachments always disappear in the end, so keep your own copy of anything that
                            matters. And only send pictures you're allowed to share. Don't post photos of other people without asking.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
