<?php
// Help docs: getting started - making an account, logging in, the side bar and top bar, and adding HB Hub to a phone's
// home screen. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/getting-started';
$docsIntro = 'HB Hub is the school\'s own place to chat, play games and try your luck in a casino with chips that aren\'t
    real money. Here\'s how to get in and find your way around.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="sign-up" aria-labelledby="docs-sign-up">
                        <h2 id="docs-sign-up">Making an account</h2>
                        <p>Press <strong>Sign up</strong> on the <a href="/">frontpage</a>. It asks you a few questions, one at a time:</p>
                        <ol class="docs-steps">
                            <li><strong>What should you be known as?</strong> The name people see in the chat and on the leaderboards. Use a name people from school will know you by. It's also what you log in with.</li>
                            <li><strong>Which year are you in?</strong> 1., 2. or 3. year at HTX, and then your field of study (X, U, T, Y or Z), or Not HTX. It's shown on your profile, and at HTX you're put in your <a href="/docs/chat/groups#class">class chat</a> straight away.</li>
                            <li><strong>A picture of yourself.</strong> Your profile picture. You can skip it and add one later.</li>
                            <li><strong>Describe yourself.</strong> A short bio for your profile.</li>
                            <li><strong>Your password.</strong> The form tells you while you type if it's too weak, or one that's used so often it's easy to guess.</li>
                        </ol>
                        <p>
                            Names and bios can use the letters of the Latin alphabet (with æ, ø and å), numbers, punctuation and
                            emoji. By making an account you agree to the <a href="/legal/terms">Terms</a> and the
                            <a href="/legal/code-of-conduct">Code of Conduct</a>, so give them a read. One account per person.
                        </p>
                    </section>

                    <section id="log-in" aria-labelledby="docs-log-in">
                        <h2 id="docs-log-in">Logging in</h2>
                        <p>
                            Press <strong>Log in</strong> on the frontpage and type your name and password. If you've turned on
                            <a href="/docs/two-factor">two-factor login</a>, it asks for the 6-digit code from your authenticator
                            app too. You stay logged in on that browser until you log out, and you can see and log out every
                            device you're logged in on from your <a href="/docs/account#devices">settings</a>.
                        </p>
                        <p>
                            A few wrong passwords in a row lock the login for a while. That's explained in
                            <a href="/docs/two-factor#lockout">Two-factor login &amp; security</a>.
                        </p>
                    </section>

                    <section id="around" aria-labelledby="docs-around">
                        <h2 id="docs-around">Finding your way around</h2>
                        <p>Once you're in, every page has the same two bars:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Where</th><th scope="col">What's there</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Side bar</td><td><strong>Chat</strong>, <strong>Gambling</strong> (the casino) and <strong>Games</strong>, with a red number on Chat for chats with unread messages. <strong>Feedback</strong> is at the bottom. On a phone it's a bar along the bottom of the screen.</td></tr>
                                    <tr><td>Top bar</td><td>The <a href="/docs/notifications">notification bell</a>, your chips on casino pages, and your profile picture, which opens your profile.</td></tr>
                                    <tr><td>Your profile</td><td>Your picture, name, class and bio, as others see them, with the cog that opens your <a href="/docs/account">settings</a>.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>The numbers on the side bar and the bell update by themselves every 15 seconds while the page is open.</p>
                    </section>

                    <section id="app" aria-labelledby="docs-app">
                        <h2 id="docs-app">Putting HB Hub on your phone</h2>
                        <p>
                            HB Hub can be added to your phone's home screen like an app, and then it opens without the browser
                            bars. Long-press the icon for shortcuts straight to Chat, the Casino and Games.
                        </p>
                        <ul>
                            <li><strong>iPhone (Safari):</strong> press the Share button, then <strong>Add to Home Screen</strong>.</li>
                            <li><strong>Android (Chrome):</strong> open the browser's menu (&vellip;), then <strong>Add to Home screen</strong> or <strong>Install app</strong>.</li>
                        </ul>
                    </section>

                    <section id="next" aria-labelledby="docs-next">
                        <h2 id="docs-next">Where to go next</h2>
                        <ul>
                            <li><a href="/docs/chat/">Chat basics</a>: start a DM or a group and say hi.</li>
                            <li><a href="/docs/casino/">How the casino works</a>: collect your free chips and pick a table.</li>
                            <li><a href="/docs/games/">How games work</a>: open a game and invite your friends.</li>
                            <li><a href="/docs/account">Profile &amp; settings</a>: add a profile picture and make your account safe.</li>
                        </ul>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
