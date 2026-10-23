<?php
// Help docs: feedback and getting help - the Feedback button (otherPages/feedback.php) and what happens to what's sent,
// who the site admins are, and where to report people and security problems. Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/feedback';
$docsIntro = 'Found a bug, got an idea, or need a hand? Here\'s who to ask, and how.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="feedback" aria-labelledby="docs-feedback">
                        <h2 id="docs-feedback">Sending feedback</h2>
                        <ol class="docs-steps">
                            <li>Press <strong>Feedback</strong> at the bottom of the side bar (at the end of the bottom bar on a phone), on the page you want to tell about.</li>
                            <li>Write what's wrong, what you'd like, or what you think. For a bug, say what you did and what happened.</li>
                            <li>Send it. You're taken back to the page you were on.</li>
                        </ol>
                        <p>
                            Only site admins can read feedback, and they see which page you sent it from, so you don't have to
                            explain where you were. When an admin marks yours as done, you get a
                            <a href="/docs/notifications">notification</a> with their answer.
                        </p>
                    </section>

                    <section id="admins" aria-labelledby="docs-admins">
                        <h2 id="docs-admins">Who are the admins?</h2>
                        <p>
                            Site admins run HB Hub. They have a <strong>Site Admin</strong> badge in group member lists. They help
                            people who are stuck, look into reports, and can ban accounts or delete messages that break the
                            <a href="/legal/code-of-conduct">Code of Conduct</a>. Everything they do in the admin panel is logged.
                        </p>
                        <p>
                            Group Owners and Group Admins are different: they only run their own <a href="/docs/chat/groups">group
                            chat</a>, and can't do anything outside it.
                        </p>
                    </section>

                    <section id="help" aria-labelledby="docs-help">
                        <h2 id="docs-help">Where to go for what</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">You want to</th><th scope="col">Do this</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Report a bug or send an idea</td><td>The Feedback button.</td></tr>
                                    <tr><td>Report someone</td><td>The Feedback button, a site admin, or <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>. See <a href="/docs/chat/safety#report">Reporting someone</a>.</td></tr>
                                    <tr><td>Get back into your account</td><td>Email <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a> from outside HB Hub, or ask a site admin at school.</td></tr>
                                    <tr><td>Ask about your data</td><td>See the <a href="/legal/privacy">Privacy Policy</a>.</td></tr>
                                    <tr><td>Report a security problem</td><td>Privately, with <a href="https://github.com/NoahGNielsen/HB-Hub/security/advisories/new" target="_blank" rel="noopener noreferrer">Report Security Concern</a>. Please don't tell anyone else before it's fixed.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            HB Hub is open source: the code is on <a href="https://github.com/NoahGNielsen/HB-Hub" target="_blank" rel="noopener noreferrer">GitHub</a>,
                            if you're curious how something works.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
