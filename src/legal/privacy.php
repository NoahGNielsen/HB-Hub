<?php
// Privacy policy: what HB Hub stores about you, why, who can see it, the cookies it sets and how to get it deleted.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too.
// Open to everyone, like the frontpage. Keep it in step with the code: when a new table, cookie, localStorage key or
// outside service is added, it belongs here, and HBHUB_PRIVACY_UPDATED gets the date of the change.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

const HBHUB_PRIVACY_UPDATED = '19 October 2026';

// The cookies the site sets: name => [what it's for, how long it's kept]. All of them are needed for the site to work
// (logging in, the login lock, bans), so the cookie notice (footer.php) only tells you about them, it doesn't ask.
const HBHUB_PRIVACY_COOKIES = [
    'hbHubSession' => ['Keeps you logged in. Holds a random id that points at your login.', '30 days, or until you log out'],
    'hbHubPendingLogin' => ['Remembers that you typed the right password while you enter your two-factor code.', '5 minutes'],
    'hbHubDevice' => ['Recognises a browser you have logged in from before, so someone guessing your password can\'t lock you out of it.', '180 days'],
    'hbHubBanned' => ['Set when a banned account is used in the browser, so it can\'t just make a new account.', '400 days, or until the account is unbanned and logs in again'],
    'hbHubToast' => ['A short message for the next page, like "Your password was changed".', '60 seconds'],
];

// What the site keeps in your browser's local storage. It never leaves your browser.
const HBHUB_PRIVACY_LOCAL_STORAGE = [
    'hbhub-chat-emoji-recent' => 'The emoji you used most recently in the chat, in messages and reactions, so they come first in the emoji picker and when you react.',
    'hbHubNotifiedMessageId' => 'The last message you got a browser notification for, so you don\'t get it twice.',
    'hbHubChessSound, hbHubMatadorSound' => 'Whether you turned the game sounds on or off.',
    'hbHubCookieNotice' => 'That you clicked away the cookie notice, so it isn\'t shown again.',
];
?>
<!DOCTYPE html>
<html lang="en" class="home">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Privacy Policy - HB Hub</title>
    <meta name="description" content="What HB Hub stores about you, why, who can see it, the cookies it uses and how to get your data deleted.">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <article class="legal-page" aria-labelledby="privacy-title">
            <header class="legal-hero">
                <div class="legal-hero-inner">
                    <p class="home-kicker legal-kicker">Legal</p>
                    <h1 id="privacy-title">Privacy Policy</h1>
                    <p class="legal-intro">
                        HB Hub is a student project, not a company. It only stores what it needs to run the chat, the casino
                        and the games. There are no ads, nothing is sold, and nothing is used to track you around the web.
                        This page explains exactly what is stored, in plain words.
                    </p>
                    <p class="legal-updated">Last updated <?= HBHUB_PRIVACY_UPDATED ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="On this page">
                    <p class="legal-toc-title" aria-hidden="true">On this page</p>
                    <ol>
                        <li><a href="#who">Who runs HB Hub</a></li>
                        <li><a href="#what">What is stored</a></li>
                        <li><a href="#why">Why it's stored</a></li>
                        <li><a href="#see">Who can see what</a></li>
                        <li><a href="#services">Outside services</a></li>
                        <li><a href="#cookies">Cookies and local storage</a></li>
                        <li><a href="#keep">How long it's kept</a></li>
                        <li><a href="#rights">Your rights</a></li>
                        <li><a href="#changes">Changes to this policy</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <section id="who" aria-labelledby="privacy-who">
                        <h2 id="privacy-who">1. Who runs HB Hub</h2>
                        <p>
                            HB Hub (hbhub.noahgajnielsen.dk) is made and run by Noah Gaj Nedergaard Nielsen in Denmark, who is
                            responsible for the data stored on it (the "data controller" under the GDPR).
                        </p>
                        <p>
                            Questions about your data, or a request to download or delete it: email
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>

                    <section id="what" aria-labelledby="privacy-what">
                        <h2 id="privacy-what">2. What is stored</h2>
                        <h3>Your account</h3>
                        <ul>
                            <li>Your name, and if you add them: your profile picture, bio, year and class.</li>
                            <li>Your password, but only as a one-way hash. Nobody, not even an admin, can read your password.</li>
                            <li>The secret behind your two-factor codes, if you turn two-factor login on.</li>
                            <li>Your settings, like which chats you've muted, hidden or blocked, and whether you've blocked the Casino and Games.</li>
                            <li>When you made the account and when you were last on the site.</li>
                            <li>Failed logins, whether the account is locked or banned, and whether a ban was by an admin or automatic.</li>
                        </ul>
                        <h3>Banned pictures</h3>
                        <p>
                            When an account is banned, a copy of its profile picture is usually put on a list of banned pictures,
                            together with which account it came from and which admin banned it. Admins can also add pictures to
                            the list themselves. When you upload a profile picture, or sign up with one, it's compared with the
                            list in two ways: a fingerprint of the file (its SHA-256 hash), and a fingerprint of what the picture
                            looks like, made from a tiny greyscale copy of it, so a resized or slightly cropped copy is found too.
                            If it's the same file, or looks almost the same as a banned picture, the account is banned straight
                            away and keeps the picture, so admins can see why. The fingerprints of banned pictures are stored with
                            them; the fingerprints of other pictures are only used for the comparison and never stored.
                        </p>
                        <h3>IP addresses</h3>
                        <p>
                            The IP address you signed up from, and the one you used most recently. Only the latest one is kept,
                            not a history. IP addresses are also counted briefly when accounts are made, so one place can't make
                            hundreds of accounts.
                        </p>
                        <h3>Logins</h3>
                        <p>
                            Each login is stored with when it started, when it was last used (updated at most once a minute)
                            and when it runs out, plus a short name for the browser and system it's on, like "Chrome on
                            Windows". That name is worked out from what your browser sends (its user agent), and only the
                            short name is stored, never the rest. It's there so you can see where you're logged in under
                            Logged in devices on the <a href="/otherPages/settings#devices">settings page</a>, and log out
                            any of them, or all of them at once. Browsers you've logged in from are also remembered by a
                            random token (only a hash of it is stored) and how many wrong passwords they've tried.
                        </p>
                        <h3>Messages and chats</h3>
                        <ul>
                            <li>Every message you send, with the time, which chat it's in, what it replies to and any GIF.</li>
                            <li>
                                Files and pictures you attach to a message, with their name, size and type, which chat they were
                                sent in, and when they were sent and run out. They're kept as files on HB Hub's server, not in the
                                database. Pictures are turned into JPEG XL in your own browser before they're sent (unless you
                                tick the box to keep the original), so they never go anywhere else to be converted. A converted
                                picture doesn't keep the details a camera or phone saves in it, like where a photo was taken.
                                Other files are zipped on the server to take less space.
                            </li>
                            <li>
                                Reactions you put on messages: which emoji, on which message, and when. Taking a reaction back
                                deletes it.
                            </li>
                            <li>The chats you're in, group names, icons and backgrounds, and nicknames you give people.</li>
                            <li>
                                Deleting a message hides it for everyone in the chat, but the message itself is kept, so site
                                admins can still look into reports of abuse. Its reactions are hidden with it, and kept the same
                                way. An attachment in it isn't kept: its file and name are deleted for good.
                            </li>
                            <li>Notifications under the bell: what happened, who did it and whether you've seen it.</li>
                            <li>
                                When someone @mentions you, in a chat or a Chess or Matador game's chat, you get one of those
                                notifications, saying who it was and which chat, message or game it was in. Nothing else is
                                stored for a mention: it's worked out from the message each time it's shown. You don't get one
                                from a chat you muted or from someone you blocked, and in a game only while you aren't looking
                                at it. Deleting the message (by you, a group admin or a site admin) deletes the notifications
                                about it too.
                            </li>
                        </ul>
                        <h3>Casino and games</h3>
                        <ul>
                            <li>Your chip balance and every change to it (bets, payouts, the daily chips, and chips a site admin added or took away, with which admin it was).</li>
                            <li>The rounds you played in the casino games, for example but not limited to Blackjack, Baccarat and Roulette, used for the leaderboards.</li>
                            <li>Your Chess and Matador games: seats, moves, saves, results and the in-game chat.</li>
                        </ul>
                        <p>
                            Chips are virtual. HB Hub never asks for, or stores, any payment details. If you buy extra chips
                            from the owner, the payment and the talk about it happen outside the site's casino (in a chat
                            message or somewhere else), and a site admin adds the chips you get, which shows up as a change to your balance like any other.
                        </p>
                        <h3>Feedback</h3>
                        <p>
                            When you send feedback with the Feedback button in the side bar, what you wrote is stored with your
                            account, the time, and the address of the page you were on when you pressed the button (only the
                            page, like <code>/chat/</code>, not which chat or what you searched for). Site admins can also mark it
                            as done, which stores which admin did it and when.
                        </p>
                        <h3>Admin log</h3>
                        <p>
                            Everything a site admin does in the admin panel is written to an admin log: which admin it was, when,
                            what they did, and which account or chat it was about. That includes just opening your account or a
                            chat you're in, since that shows your messages and personal info, and downloading your data. It also
                            keeps a few details, like how many chips were added or which message was deleted, but never a
                            password, a name or what a message said.
                        </p>
                        <h3>What isn't stored</h3>
                        <p>
                            No email address, phone number, real address or date of birth. No analytics profile, no advertising
                            ids, and no record of which pages you open, apart from the one page you send feedback from (and, for
                            site admins, the admin log above).
                        </p>
                    </section>

                    <section id="why" aria-labelledby="privacy-why">
                        <h2 id="privacy-why">3. Why it's stored</h2>
                        <ul>
                            <li><strong>To run the site</strong>: your account, messages and games are the site. Without them there's nothing to show you.</li>
                            <li><strong>To keep it safe</strong>: IP addresses, failed logins and the device cookie stop people from breaking into accounts, spamming sign-ups and dodging bans. The banned pictures list stops a banned person from coming back with a copy of their old profile.</li>
                            <li><strong>To keep it friendly</strong>: messages are kept so admins can act on bullying or abuse.</li>
                            <li><strong>To make it better</strong>: feedback, and the page it came from, helps admins find bugs and see what people want.</li>
                        </ul>
                        <p>
                            Under the GDPR, this is "necessary to provide the service you signed up for" and HB Hub's
                            "legitimate interest" in keeping it secure. Browser notifications are only shown if you allow them
                            in your browser and turn them on in your settings.
                        </p>
                    </section>

                    <section id="see" aria-labelledby="privacy-see">
                        <h2 id="privacy-see">4. Who can see what</h2>
                        <ul>
                            <li><strong>Everyone with an account</strong> can see your name, profile picture, bio, year, class, when you were last online, and your spot on the leaderboards.</li>
                            <li><strong>People in a chat with you</strong> can see the messages in that chat and who reacted to them with what, and open and download its attachments. Group owners and admins can delete messages (and with them their attachments) and remove members in their group.</li>
                            <li><strong>People in a game with you</strong> can see your moves and the game's chat.</li>
                            <li>
                                <strong>Site admins</strong> can see everything about an account in the admin panel, including
                                messages in every chat (also deleted ones), IP addresses and logins, the list of banned
                                pictures, and all feedback. The admin panel only shows that a message has an attachment, but the
                                site's owner can reach attachment files on the server. They use it to deal with abuse, bans and
                                broken accounts, and to improve the site. Feedback is only ever seen by site admins, never by
                                other users. They can never see your password or your two-factor secret. Everything they do
                                there, also just looking at your account or a chat you're in, goes in the admin log, which only
                                site admins can read and nobody can change or delete.
                            </li>
                        </ul>
                        <p class="legal-note">
                            Messages are not end-to-end encrypted. They travel encrypted (HTTPS), but they're stored so the site
                            can show them, so don't send anything you wouldn't want an admin to read.
                        </p>
                    </section>

                    <section id="services" aria-labelledby="privacy-services">
                        <h2 id="privacy-services">5. Outside services</h2>
                        <p>Your data is never sold or shared for marketing. These services are involved in running the site:</p>
                        <ul>
                            <li><strong>Simply.com</strong> (Denmark) hosts the website, the database and chat attachments. Everything above is stored there, in the EU.</li>
                            <li>
                                <strong>Giphy</strong> provides the GIFs. Your GIF searches go through HB Hub's server, so Giphy
                                doesn't learn who searched. To ask Giphy less often, the results of each search are kept on HB
                                Hub's server for 15 minutes, without who searched for it, and shown to anyone who searches the
                                same thing in that time. GIFs in the chat load straight from Giphy, which means Giphy sees your
                                IP address when your browser shows one.
                            </li>
                            <li><strong>Google Fonts</strong> serves the site's font, so Google sees your IP address when your browser loads it.</li>
                            <li><strong>GitHub</strong> hosts the site's code. It only gets the total number of members and messages, for the counters in the README, never anything about one person.</li>
                        </ul>
                    </section>

                    <section id="cookies" aria-labelledby="privacy-cookies">
                        <h2 id="privacy-cookies">6. Cookies and local storage</h2>
                        <p>
                            HB Hub only uses cookies it needs to work. There are no tracking or advertising cookies, so the
                            small cookie notice only tells you about them: by using the site, you agree to them. All of them
                            are only sent to HB Hub, over HTTPS, and scripts can't read them.
                        </p>
                        <dl class="legal-cookies">
                            <?php foreach (HBHUB_PRIVACY_COOKIES as $privacyCookie => [$privacyCookieUse, $privacyCookieKept]): ?>
                                <div>
                                    <dt><code><?= htmlspecialchars($privacyCookie) ?></code></dt>
                                    <dd><?= htmlspecialchars($privacyCookieUse) ?></dd>
                                    <dd class="legal-cookie-kept">Kept for <?= htmlspecialchars($privacyCookieKept) ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                        <p>A few small things are also saved in your browser's local storage. They never leave your browser:</p>
                        <dl class="legal-cookies">
                            <?php foreach (HBHUB_PRIVACY_LOCAL_STORAGE as $privacyKey => $privacyKeyUse): ?>
                                <div>
                                    <dt><code><?= htmlspecialchars($privacyKey) ?></code></dt>
                                    <dd><?= htmlspecialchars($privacyKeyUse) ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    </section>

                    <section id="keep" aria-labelledby="privacy-keep">
                        <h2 id="privacy-keep">7. How long it's kept</h2>
                        <p>
                            Your data is kept for as long as you have an account. Logins run out after 30 days, and a browser
                            stops counting as a known device after 180 days without a login. Logging out a device, or
                            logging out everywhere, deletes those logins right away.
                        </p>
                        <p>
                            <strong>Attachments</strong> are kept for 90 days, or for 3 days when one is bigger than 25 MB, and
                            then deleted by themselves, at the latest the night after. They're deleted sooner when you delete them (in Your attachments in the
                            chat, or by deleting their message), when an admin deletes their message, when their chat is deleted,
                            or when your account is deleted. After that only a note that the message had an attachment stays
                            with it (how big it was, its type and the dates), never the file or its name.
                        </p>
                        <p>
                            You can <strong>delete your account</strong> yourself on the <a href="/otherPages/settings">settings page</a>.
                            That right away removes your name, profile picture, bio, year, class, settings, password, two-factor
                            secret, IP addresses, logins, known devices and the files you attached in chats. Your name is replaced
                            with "Deleted User".
                        </p>
                        <p>
                            What stays is what other people need for their chats and games to make sense: the messages you sent
                            and your reactions to messages (shown as from "Deleted User"), your game results and chip history, and any feedback you sent. None of it is connected to your
                            name any more.
                        </p>
                        <p>
                            A picture on the list of banned pictures stays there until the account it came from is unbanned or an
                            admin takes it off, also if that account is deleted.
                        </p>
                        <p>
                            Feedback is kept until a site admin deletes it. If you delete your account, your feedback stays,
                            shown as from "Deleted User", like your messages.
                        </p>
                        <p>
                            The admin log is kept for a year, and then deleted by itself (at the latest the night after). If you
                            delete your account, what's in it about you stays until then, shown as about "Deleted User".
                        </p>
                        <p class="legal-note">
                            To <strong>completely delete</strong> all your data, messages included, or to <strong>download</strong>
                            a copy of all of it, email <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>

                    <section id="rights" aria-labelledby="privacy-rights">
                        <h2 id="privacy-rights">8. Your rights</h2>
                        <p>Under the GDPR you can ask to:</p>
                        <ul>
                            <li>see the data HB Hub has about you, and get a copy of it,</li>
                            <li>have wrong data corrected (most of it you can change yourself in your settings),</li>
                            <li>have your data deleted,</li>
                            <li>object to how your data is used, or have it used less.</li>
                        </ul>
                        <p>
                            Email <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>, and you'll get an answer
                            within a month. If you think your data is being handled wrongly, you can complain to the Danish Data
                            Protection Agency, <a href="https://www.datatilsynet.dk" target="_blank" rel="noopener noreferrer">Datatilsynet</a>.
                        </p>
                    </section>

                    <section id="changes" aria-labelledby="privacy-changes">
                        <h2 id="privacy-changes">9. Changes to this policy</h2>
                        <p>
                            HB Hub changes as new features are added, and this page tries to changes with it. However, this is a small side project, and i will try to make sure this stays up to date. If you notice it aint, please contract me at admin@noahgajnielsen.dk The date at the top shows when it was last updated.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
