<?php
// Privacy policy: what HB Hub stores about you, why, who can see it, the cookies it sets and how to get it deleted.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too.
// Open to everyone, like the frontpage. Keep it in step with the code: when a new table, cookie, localStorage key or
// outside service is added, it belongs here, and HBHUB_PRIVACY_UPDATED gets the date of the change.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

const HBHUB_PRIVACY_UPDATED = '23 October 2026';

// The cookies the site sets: name => [what it's for, how long it's kept]. All of them are needed for the site to work
// (logging in, the login lock, bans), so the cookie notice (footer.php) only tells you about them, it doesn't ask.
const HBHUB_PRIVACY_COOKIES = [
    'hbHubSession' => ['Keeps you logged in. Holds a random id that points at your login.', '30 days, or until you log out'],
    'hbHubPendingLogin' => ['Remembers that you typed the right password while you enter your two-factor code.', '5 minutes'],
    'hbHubDevice' => ['Recognises a browser you have logged in from before, so someone guessing your password can\'t lock you out of it. It\'s also how a ghost ban is passed on to a new account used in the same browser.', '180 days'],
    'hbHubBanned' => ['Set when a banned account is used in the browser, so it can\'t just make a new account.', '400 days, or until the account is unbanned and logs in again'],
    'hbHubToast' => ['A short message for the next page, like "Your password was changed".', '60 seconds'],
];

// What the site keeps in your browser's local storage. It never leaves your browser.
const HBHUB_PRIVACY_LOCAL_STORAGE = [
    'hbhub-chat-emoji-recent' => 'The emoji you used most recently in the chat, in messages and reactions, so they come first in the emoji picker and when you react.',
    'hbHubChatListScroll' => 'How far down the chat list you had scrolled, so it\'s still there when you refresh or come back to the chat. Kept in session storage, so it\'s gone when you close the tab.',
    'hbHubNotifiedMessageId' => 'The last message you got a browser notification or sound for, so you don\'t get it twice.',
    'hbHubChessSound, hbHubMatadorSound, hbHubUnoSound, hbHubMinerSound' => 'Whether you turned the game sounds on or off.',
    'hbHubMinerLateKeys' => 'Whether you switched Space Ore Miner\'s 1-9 and 0 hot keys to your last ten buildings (with the Long-Range Hot Keys upgrade).',
    'hbHubMinerAutoBuy' => 'Whether you switched Space Ore Miner\'s Auto Buy on or off (with the Auto Buy upgrade).',
    'hbHubMinerAutoWarp, hbHubMinerAutoCrunch' => 'Whether you switched Space Ore Miner\'s Autopilot and Auto Crunch on or off (with the skills of the same name).',
    'hbHubCookieNotice' => 'That you clicked away the cookie notice, so it isn\'t shown again.',
];
?>
<!DOCTYPE html>
<html lang="en" class="home">
<head>
    <?php $headerHomeNav = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
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
                            <li>Your settings, like which chats you've muted, hidden or blocked, whether new messages give you a browser notification or a sound, and whether you've blocked the Casino and Games, or a site admin has blocked them for you.</li>
                            <li>When you made the account and when you were last on the site (updated at most once a minute).</li>
                            <li>Failed logins, whether the account is locked or banned, and whether a ban was by an admin or automatic.</li>
                            <li>Whether, and since when, the account is ghost banned (see Messages and chats below).</li>
                        </ul>
                        <h3>Banned pictures</h3>
                        <p>
                            When an account is banned, a copy of its profile picture is usually put on a list of banned pictures,
                            together with which account it came from and which admin banned it. When an admin removes a profile
                            picture that breaks the rules, they can put a copy of it on the list the same way without banning the
                            account. Admins can also add pictures to the list themselves. When you upload a profile picture, or sign up with one, it's compared with the
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
                                Files and pictures you attach to a message (up to 10 in one), with their name, size and type,
                                which message and chat they were sent in, and when they were sent and run out. They're kept as files on HB Hub's server, not in the
                                database. Pictures are turned into JPEG XL in your own browser before they're sent (unless you
                                tick the box to keep the original), so they never go anywhere else to be converted. A converted
                                picture doesn't keep the details a camera or phone saves in it, like where a photo was taken.
                                Other files are zipped on the server to take less space. If a site admin has given you more
                                attachment tickets or taken some away, how many is stored with your account.
                            </li>
                            <li>
                                Reactions you put on messages: which emoji, on which message, and when. Taking a reaction back
                                deletes it.
                            </li>
                            <li>The chats you're in, group names, icons and backgrounds, and nicknames you give people.</li>
                            <li>
                                Deleting a message hides it for everyone in the chat, but the message itself is kept, so site
                                admins can still look into reports of abuse. Its reactions are hidden with it, and kept the same
                                way. Attachments in it aren't kept: their files and names are deleted for good.
                            </li>
                            <li>Notifications under the bell: what happened, who did it and whether you've seen it. If you're a site admin and a group admin removes you from a group, that notification also keeps how you were in the group (your role, nickname, mute and how far you had read), so you can undo it. Undoing it deletes the notification.</li>
                            <li>
                                When someone @mentions you, in a chat or a Chess, Matador or UNO game's chat, you get one of those
                                notifications, saying who it was and which chat, message or game it was in. Nothing else is
                                stored for a mention: it's worked out from the message each time it's shown. You don't get one
                                from a chat you muted or from someone you blocked, and only while you aren't looking at that
                                chat or game. To know that, the last time you had each chat open in front of you is kept with
                                your membership of it, and goes when you leave the chat. Deleting the message (by you, a group admin or a site admin) deletes the notifications
                                about it too.
                            </li>
                            <li>
                                Ghost bans: a site admin can ghost ban an account that breaks the rules, without telling it.
                                What it does in chats from then on (messages, attachments, edits, reactions, and the chats it
                                starts or adds people to) is stored like anything else, but marked so that only that account
                                and site admins can see it. People it starts a chat with or adds to a group are stored as
                                invited, marked the same way, so they never see the chat or get a request or notification about
                                it, until someone else invites them or they start a chat with that account themselves. The
                                marks stay when the ghost ban is lifted, so what was hidden stays hidden. They go with the
                                messages and invites they're on. To stop a ghost ban being dodged with a new account, the
                                known devices (the hbHubDevice cookie, see <a href="#privacy-cookies">Cookies and local storage</a>) are checked when an account is made
                                or logged in to: an account at most 3 days old that's used in the same browser as a
                                ghost-banned account is ghost banned too, by itself, and site admins can see which account
                                it came from in the admin log. Older accounts aren't affected.
                            </li>
                        </ul>
                        <h3>Casino and games</h3>
                        <ul>
                            <li>Your chip balance and every change to it (bets, payouts, the daily chips, and chips a site admin added or took away, with which admin it was).</li>
                            <li>The rounds you played in the casino games, for example but not limited to Blackjack, Baccarat and Roulette, used for the leaderboards.</li>
                            <li>Your Chess and Matador games: seats, moves, saves, results and the in-game chat.</li>
                            <li>
                                Your UNO games: your seat, the cards in your hand and what you played, invites, and the
                                in-game chat. Only you see your cards while the game is played; when it's over, everyone in it
                                sees the cards that were left. All of it goes when everyone has left the game. What's kept is
                                one line per game for the UNO leaderboard: whether you won, lost or left, the points you
                                scored, how many cards you had left, how many played and when. Other people only see the
                                totals there (games, wins, losses, win %, points and best win).
                            </li>
                            <li>
                                Your Wordle games: for each day and language you play, the words you guessed, whether you got
                                it, and when you started and finished. They make up your stats and streaks, and your spot on
                                the Wordle leaderboards. Other people only see the totals there (games, wins, win %, average
                                guesses and streaks), never your guesses.
                            </li>
                            <li>
                                Your Space Ore Miner save: your ore, buildings, upgrades, Dark Matter, Antimatter, Strange Matter, the skills you've learned, achievements, how many
                                times you've clicked and caught comets, when you started and last saved, how many saves came
                                in the last few seconds (so nobody can flood the server with them), and a random id for
                                the browser tab you're playing in (so the game only runs in one tab at a time). Your game sends
                                what you did since the last save every 30 seconds, with the time of each purchase and how many
                                times you clicked each second, so the server can check it. Only the result is kept, not the list
                                of clicks. To spot auto-clickers, the game page looks at how long you hold each click, the gaps
                                between clicks and where the pointer is, but that stays in your browser: all it sends is whether
                                a click looked like an auto-clicker's, and it isn't kept. Other people only see
                                the totals on the Space Ore Miner leaderboard (ore mined, ore per second, Dark Matter, Antimatter,
                                Strange Matter, Warp Jumps, clicks and achievements).
                            </li>
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
                            as done, which stores which admin did it and when. When they mark it as done with a reply, you get
                            the reply as a notification under the bell (it says "An admin", not which one). The reply is kept in
                            that notification, like your other notifications, and in the admin log below.
                        </p>
                        <h3>Admin log</h3>
                        <p>
                            Everything a site admin does in the admin panel is written to an admin log: which admin it was, when,
                            what they did, and which account or chat it was about. That includes just opening your account or a
                            chat you're in, since that shows your messages and personal info, and downloading your data. It also
                            keeps a few details, like how many chips were added or which message was deleted, but never a
                            password, a name or what a message said. Before every change, the admin can type why they're doing
                            it, and that reason is kept with it in the log. When an admin replies to feedback, the reply is kept
                            there as the reason. When an account is banned automatically for uploading a
                            banned profile picture, that goes in the admin log too, with which picture it was and whether it
                            happened when signing up.
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
                            <li><strong>People in a chat with you</strong> can see the messages in that chat and who reacted to them with what, and open and download its attachments, except what a ghost-banned account did there, which only it and site admins see. Group owners and admins can delete messages (and with them their attachments) and remove members in their group. When they remove a site admin, the site admin can undo it from their notification: they're put back in the group as they were, and the notice about it in the chat is deleted.</li>
                            <li><strong>People in a game with you</strong> can see your moves and the game's chat.</li>
                            <li>
                                <strong>Site admins</strong> can see everything about an account in the admin panel, including
                                messages in every chat (also deleted ones), IP addresses and logins, the list of banned
                                pictures, and all feedback. The admin panel lists the attachments you've sent that are still
                                kept (their name, size, which chat and how many tickets they take) but doesn't open them,
                                though the site's owner can reach attachment files on the server. Site admins can delete your
                                attachments and give you more tickets or take some away. They use it to deal with abuse, bans and
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
                        <p>A few small things are also saved in your browser's local storage (or session storage). They never leave your browser:</p>
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
                            or when your account is deleted. After that only a note that the message had attachments stays
                            with it (how many, how big each was, its type and the dates), never the files or their names.
                        </p>
                        <p>
                            You can <strong>delete your account</strong> yourself on the <a href="/otherPages/settings">settings page</a>.
                            That right away removes your name, profile picture, bio, year, class, settings, password, two-factor
                            secret, IP addresses, logins, known devices, your Space Ore Miner save and the files you attached in chats. Your name is replaced
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
