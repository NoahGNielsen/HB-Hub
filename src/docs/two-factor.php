<?php
// Help docs: two-factor login and security - setting up TOTP, the password rules, the login lock after wrong guesses
// (per known device, assets/php/loginLimit.php) and what to do when an account might be someone else's hands.
// The numbers come from the site config, which header.php (through top.php) has loaded. Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/two-factor';
$docsIntro = 'A good password and two-factor login make sure you\'re the only one who can get into your account.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';

$docsLoginAttempts = (int) ($siteConfig['login_attempts_max'] ?? 5);
$docsLockoutMinutes = (int) ($siteConfig['login_attempts_lockout_time'] ?? 15);
?>
                    <section id="two-factor" aria-labelledby="docs-two-factor">
                        <h2 id="docs-two-factor">Turning on two-factor login</h2>
                        <p>
                            With two-factor login, logging in takes your password <em>and</em> a 6-digit code from an
                            authenticator app on your phone. The code changes every 30 seconds, so someone who finds out your
                            password still can't get in.
                        </p>
                        <ol class="docs-steps">
                            <li>Install an authenticator app on your phone, like <a href="https://proton.me/authenticator" target="_blank" rel="noopener">Proton Authenticator</a>, <a href="https://support.google.com/accounts/answer/1066447" target="_blank" rel="noopener">Google Authenticator</a>, <a href="https://2fas.com/" target="_blank" rel="noopener">2FAS</a> or the passwords app built into your phone.</li>
                            <li>Open the <strong>Two-factor login</strong> card in your <a href="/settings#totp">settings</a>.</li>
                            <li>Scan the QR code with the app. On the phone itself, use <strong>Open it in your app</strong> instead, or type in the key under the code.</li>
                            <li>Type the 6-digit code the app shows to turn it on. From now on, the login asks for a code after your password.</li>
                        </ol>
                        <p>
                            To turn it off again, type a code from the app in the same card. Keep the app: if you lose your phone,
                            you'll need an admin to help you back in.
                        </p>
                    </section>

                    <section id="passwords" aria-labelledby="docs-passwords">
                        <h2 id="docs-passwords">The password rules</h2>
                        <p>
                            A new password has to be long enough and mix enough kinds of characters (lowercase, uppercase,
                            numbers and symbols). The form tells you what's missing as you type. Passwords that are on the list of
                            the most used ones, like <code>123456</code> or <code>password</code>, can't be used at all.
                        </p>
                        <ul>
                            <li>Don't use the same password as somewhere else.</li>
                            <li>Never tell anyone your password, not even an admin. Admins never need it.</li>
                            <li>A password manager (most browsers and phones have one) can make and remember a strong one for you.</li>
                        </ul>
                    </section>

                    <section id="lockout" aria-labelledby="docs-lockout">
                        <h2 id="docs-lockout">Wrong passwords and the login lock</h2>
                        <p>
                            After <?= $docsLoginAttempts ?> wrong passwords (or two-factor codes) in a row, logging in is locked
                            for for some time. The same goes for the password and codes the settings page
                            asks for before a big change.
                        </p>
                    </section>

                    <section id="hacked" aria-labelledby="docs-hacked">
                        <h2 id="docs-hacked">If you think someone got into your account</h2>
                        <ol class="docs-steps">
                            <li>Log in and <a href="/docs/account#password">change your password</a>. That logs out every other device.</li>
                            <li>Check <a href="/docs/account#devices">Logged in devices</a> for anything you don't recognise.</li>
                            <li>Turn on two-factor login, if it isn't already.</li>
                            <li>Tell an admin, send a message to <a href="https://hbhub.noahgajnielsen.dk/profile?user=1">Noha Gayy</a> or by email to <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>, especially if you can't log in any more.</li>
                        </ol>
                        <p>
                            Found a security problem in HB Hub itself? Please report it privately with
                            <a href="https://github.com/NoahGNielsen/HB-Hub/security/advisories/new" target="_blank" rel="noopener noreferrer">Report Security Concern</a>
                            instead of telling everyone.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
