<?php
// Help docs: chat basics - starting a DM, message requests, sending, replies, reactions, editing and deleting, unread
// messages, the chat list's right-click menu and nicknames. Follows chat/index.php and chat.js. Open to everyone.
// Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/chat/';
$docsIntro = 'The chat is the heart of HB Hub: DMs with one person, group chats with lots, and everything you can do
    with a message.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="start" aria-labelledby="docs-start">
                        <h2 id="docs-start">Starting a chat</h2>
                        <ol class="docs-steps">
                            <li>Open <a href="/chat/">Chat</a> in the side bar and press <strong>New chat</strong> above your list of chats.</li>
                            <li>Search for the people you want to talk to and pick them.</li>
                            <li>
                                Pick one person and press <strong>Start DM</strong> for a direct message. Pick more and it becomes a
                                <a href="/docs/chat/groups">group chat</a>, which you can give a name.
                            </li>
                        </ol>
                        <p>If you already have a DM with someone, it opens that one instead of making a second.</p>
                    </section>

                    <section id="requests" aria-labelledby="docs-requests">
                        <h2 id="docs-requests">Message requests</h2>
                        <p>
                            When someone starts a chat with you, or adds you to a group, it shows up under
                            <strong>Message requests</strong> at the top of your chat list, and in the
                            <a href="/docs/notifications">bell</a>. You can read it before you answer:
                        </p>
                        <ul>
                            <li><strong>Accept</strong> moves it to your chats, and you can write back.</li>
                            <li><strong>Decline</strong> says no thanks. They can ask again later.</li>
                            <li><strong>Block</strong> declines it and stops that person from sending you more requests. See <a href="/docs/chat/safety#block">Blocking</a>.</li>
                        </ul>
                    </section>

                    <section id="send" aria-labelledby="docs-send">
                        <h2 id="docs-send">Writing messages</h2>
                        <ul>
                            <li><kbd>Enter</kbd> sends, <kbd>Shift</kbd> + <kbd>Enter</kbd> makes a new line.</li>
                            <li>A message can be up to 2,500 characters long, and <a href="/docs/chat/formatting">styled</a> with bold, italic, code, lists and more.</li>
                            <li>The buttons next to the message box send <a href="/docs/chat/attachments">files and pictures</a>, <a href="/docs/chat/gifs">GIFs and smileys</a>.</li>
                            <li>Type <code>@</code> to <a href="/docs/chat/mentions">mention</a> someone in the chat.</li>
                            <li>Messages sent in a row by the same person (less than 10 minutes apart) are shown together. Point at one to see when it was sent.</li>
                        </ul>
                    </section>

                    <section id="message-menu" aria-labelledby="docs-message-menu">
                        <h2 id="docs-message-menu">Replies, reactions, edits and deleting</h2>
                        <p>Right-click a message (or long-press it on a phone) for its menu:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Option</th><th scope="col">What it does</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Reactions</td><td>The emoji along the top are the ones you've used most lately. Press one to react with it, or <strong>+</strong> for all the others. Press a reaction under a message to add yours or take it away, and point at it to see who reacted.</td></tr>
                                    <tr><td>Reply</td><td>Quotes the message above yours. Click a quote to jump back to the message it answers. <kbd>Esc</kbd> cancels the reply.</td></tr>
                                    <tr><td>Edit message</td><td>Your own messages only. The message shows that it was edited.</td></tr>
                                    <tr><td>Mark unread</td><td>Marks the chat as unread from that message, so you remember to come back to it.</td></tr>
                                    <tr><td>Delete message</td><td>Your own messages, or anyone's if you're an admin of the group. It shows as "Message deleted" for everyone.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="unread" aria-labelledby="docs-unread">
                        <h2 id="docs-unread">Unread messages</h2>
                        <p>
                            Chats with new messages are marked in your list, and Chat in the side bar shows how many have something
                            new. Opening a chat you've been away from puts a <strong>New messages</strong> line above the first one
                            you haven't seen. Scroll up to load older messages.
                        </p>
                    </section>

                    <section id="chat-menu" aria-labelledby="docs-chat-menu">
                        <h2 id="docs-chat-menu">The chat menu</h2>
                        <p>Right-click a chat in your list (or long-press it) for more:</p>
                        <ul>
                            <li><strong>Show profile</strong> and <strong>Give nickname</strong> (DMs): open their profile, or give them a name only you see.</li>
                            <li><strong>Mute chat</strong>: no notifications or sounds from it for a while, or for good. See <a href="/docs/notifications#mute">Muting a chat</a>.</li>
                            <li><strong>Hide chat</strong>: takes it out of your list until a new message comes in, or you search for it and open it.</li>
                            <li><strong>Change background</strong>: give the chat a colour or gradient, for everyone in it. Either of you can in a DM, only admins in a group.</li>
                            <li><strong>Leave chat</strong>: leave it for good. In a group, someone has to add you again to get back in.</li>
                        </ul>
                        <p>Groups have a few more for their admins, explained in <a href="/docs/chat/groups">Group chats</a>.</p>
                    </section>

                    <section id="search" aria-labelledby="docs-search">
                        <h2 id="docs-search">Finding a chat</h2>
                        <p>
                            Type in <strong>Search chats</strong> above your list to find a chat by its name (a DM by the other
                            person's name, or the nickname you gave them). Search finds hidden chats too, and opening one brings it
                            back into your list.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
