<?php
// Help docs: profile and settings - the profile card, password, logged in devices, logging out and deleting the
// account. Follows the settings page's cards (otherPages/settings.php). Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/account';
$docsIntro = 'Everything about your account is on the <a href="/settings">settings page</a>: open your profile
    (your picture in the top bar) and press the cog.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="profile" aria-labelledby="docs-profile">
                        <h2 id="docs-profile">Your profile</h2>
                        <p>Your profile is how everyone else sees you. The <strong>Profile</strong> card in settings changes it:</p>
                        <ul>
                            <li><strong>Picture</strong>: a PNG, JPEG, GIF or WebP. It shows next to your messages, in the chat list and on the leaderboards.</li>
                            <li><strong>Name</strong>: what everyone sees you as, and what you log in with.</li>
                            <li><strong>Year and class</strong>: so people know who you are.</li>
                            <li><strong>Bio</strong>: a few words about yourself.</li>
                        </ul>
                        <p>
                            Anyone logged in can open your profile, from the chat, a leaderboard or a game. Pictures have to follow
                            the <a href="/legal/code-of-conduct">Code of Conduct</a> like everything else: a profile picture
                            that gets an account banned is banned too, on every account.
                        </p>
                    </section>

                    <section id="password" aria-labelledby="docs-password">
                        <h2 id="docs-password">Changing your password</h2>
                        <p>
                            Type your current password, then the new one twice, in the <strong>Password</strong> card. Changing
                            it logs you out on every other device, so it's also the thing to do if you think someone else knows it.
                            The rules for a new password are in <a href="/docs/two-factor#passwords">Two-factor login &amp; security</a>.
                        </p>
                    </section>

                    <section id="devices" aria-labelledby="docs-devices">
                        <h2 id="docs-devices">Logged in devices</h2>
                        <p>
                            <strong>Logged in devices</strong> lists every browser you're logged in on, with its browser and
                            system, when you logged in and when it was last used. Log out any of them with its button. If there's
                            one you don't recognise, log it out and change your password.
                        </p>
                        <p><strong>Log out everywhere</strong> logs you out on every device, the one you're on too.</p>
                    </section>

                    <section id="log-out" aria-labelledby="docs-log-out">
                        <h2 id="docs-log-out">Logging out</h2>
                        <p>
                            <strong>Log out</strong>, near the bottom of the settings page, logs you out on the device you're on.
                            You stay logged in everywhere else. On a shared computer, always log out when you're done.
                        </p>
                    </section>

                    <section id="delete" aria-labelledby="docs-delete">
                        <h2 id="docs-delete">Deleting your account</h2>
                        <p>
                            <strong>Delete account</strong>, at the very bottom, asks for your password and then deletes your
                            account straight away. This can't be undone.
                        </p>
                        <ul>
                            <li>Your name, picture, bio, year, class, settings, logins and the files you attached in chats are deleted, and you're logged out everywhere.</li>
                            <li>Messages you've sent stay in their chats, from "Deleted User".</li>
                            <li>Your Ore Mining Inc. save is deleted too, and nobody can log in to the account or find it again.</li>
                        </ul>
                        <p class="legal-note">
                            Want everything gone, messages too, or a copy of your data first? The
                            <a href="/legal/privacy">Privacy Policy</a> explains how to ask for that.
                        </p>
                    </section>

                    <section id="more" aria-labelledby="docs-more">
                        <h2 id="docs-more">The other settings</h2>
                        <ul>
                            <li><a href="/docs/notifications">Notifications</a>: a browser notification or a sound for new messages.</li>
                            <li><a href="/docs/self-blocking">Block Gambling and Games</a>: keep yourself away from them during school hours, or all the time.</li>
                            <li><a href="/docs/two-factor">Two-factor login</a>: a code from your phone on top of your password.</li>
                        </ul>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
