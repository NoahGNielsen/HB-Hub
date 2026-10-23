<?php
// Help docs: group chats - making one, adding people, the owner/admin/member roles (HBHUB_CHAT_ROLE_* in
// assets/php/chat.php), changing the name, icon and background, kicking, leaving and deleting, and the notices
// a group shows. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/chat/groups';
$docsIntro = 'A group chat is a chat with three or more people: your class, your team, or just your friends.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="make" aria-labelledby="docs-make">
                        <h2 id="docs-make">Making a group</h2>
                        <ol class="docs-steps">
                            <li>Press <strong>New chat</strong> in the <a href="/chat/">chat</a>.</li>
                            <li>Pick two or more people.</li>
                            <li>Give the group a name if you like. Without one, it's named after the people in it.</li>
                        </ol>
                        <p>
                            Everyone you picked gets a <a href="/docs/chat/#requests">message request</a> and joins once they
                            accept it. Until then they're marked <strong>Invited</strong> in the member list. A group can have
                            up to 200 people.
                        </p>
                    </section>

                    <section id="add" aria-labelledby="docs-add">
                        <h2 id="docs-add">Adding people</h2>
                        <p>
                            Anyone in a group can add more people with the <strong>Add to chat</strong> button (the person with a
                            plus) at the top of the chat. They get a request saying who added them. People who
                            <a href="/docs/chat/safety#block">blocked</a> you can't be added by you.
                        </p>
                    </section>

                    <section id="roles" aria-labelledby="docs-roles">
                        <h2 id="docs-roles">Owner, admins and members</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Role</th><th scope="col">Can</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Group Owner</td><td>Everything an admin can, and also kick admins or remove them as admin. Whoever made the group is its owner, and there's only one.</td></tr>
                                    <tr><td>Group Admin</td><td>Rename the group, change or remove its icon, change its background, make members admin, kick members, delete anyone's messages and delete the whole group.</td></tr>
                                    <tr><td>Member</td><td>Write, react, add people and leave.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            The <strong>Members</strong> button (the person) at the top of the chat lists everyone, with their
                            badges. Admins make someone admin, remove them as admin or kick them with the buttons next to their name. When the owner leaves, the admin who has been in
                            the group the longest takes over, or the longest-standing member when there are no admins.
                        </p>
                        <p>
                            You might also see <strong>Site Admin</strong> on someone. They run HB Hub itself, not the group, see
                            <a href="/docs/feedback#admins">Who are the admins?</a>
                        </p>
                    </section>

                    <section id="look" aria-labelledby="docs-look">
                        <h2 id="docs-look">Name, icon and background</h2>
                        <p>Group admins find these in the <a href="/docs/chat/#chat-menu">chat menu</a> (right-click the group in your list):</p>
                        <ul>
                            <li><strong>Change name</strong>: up to 64 characters. Leave it empty to name the group after its members again.</li>
                            <li><strong>Change group icon</strong>: a PNG, JPEG, GIF or WebP picture, up to 2 MB. It can be removed again too.</li>
                            <li><strong>Change background</strong>: one colour, or a gradient between two colours in the direction you pick. Reset puts the normal one back.</li>
                        </ul>
                        <p>In a DM, both of you can change the background.</p>
                    </section>

                    <section id="notices" aria-labelledby="docs-notices">
                        <h2 id="docs-notices">Notices</h2>
                        <p>
                            Changes to a group show up in the chat as small notices, so everyone knows what happened: a new name,
                            icon or background, someone joining, leaving, being added or kicked, and someone becoming admin or owner.
                        </p>
                    </section>

                    <section id="leave" aria-labelledby="docs-leave">
                        <h2 id="docs-leave">Kicking, leaving and deleting</h2>
                        <ul>
                            <li><strong>Kick</strong> takes someone out of the group. They get a notification, and can only come back if someone adds them again.</li>
                            <li><strong>Leave chat</strong> in the chat menu takes you out. Your messages stay.</li>
                            <li><strong>Delete group</strong> (admins) closes the group for everyone, and deletes the files sent in it. A group everyone has left is deleted too.</li>
                        </ul>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
