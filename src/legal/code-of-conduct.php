<?php
// Code of conduct: how people are expected to treat each other on HB Hub - in chats, groups, games and the casino -
// and how to report someone. The Terms of Service (terms.php) hold the actual rules and what admins can do; this page
// is the friendlier, longer version of their "How to behave" section, with examples.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too, just like terms.php and privacy.php. Open to everyone, like the
// frontpage. The site footer (footer.php) links here.
// When an expectation changes, HBHUB_CONDUCT_UPDATED gets the date of the change.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

const HBHUB_CONDUCT_UPDATED = '19 October 2026';
?>
<!DOCTYPE html>
<html lang="en" class="home">
<head>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Code of Conduct - HB Hub</title>
    <meta name="description" content="How we treat each other on HB Hub: in the chat, in groups, in the games and the casino, and how to report someone who crosses the line.">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <article class="legal-page" aria-labelledby="conduct-title">
            <header class="legal-hero">
                <div class="legal-hero-inner">
                    <p class="home-kicker legal-kicker">Legal</p>
                    <h1 id="conduct-title">Code of Conduct</h1>
                    <p class="legal-intro">
                        Everyone on HB Hub goes to the same school. You'll see the people you chat with in the hallway tomorrow,
                        so treat them the way you'd want to be treated there. This page explains what that means in practice.
                        The rules themselves, and what happens when they're broken, are in the
                        <a href="/legal/terms">Terms of Service</a>.
                    </p>
                    <p class="legal-updated">Last updated <?= HBHUB_CONDUCT_UPDATED ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="On this page">
                    <p class="legal-toc-title" aria-hidden="true">On this page</p>
                    <ol>
                        <li><a href="#short">The short version</a></li>
                        <li><a href="#chat">In the chat</a></li>
                        <li><a href="#groups">Running a group</a></li>
                        <li><a href="#privacy">Other people's privacy</a></li>
                        <li><a href="#profile">Your profile</a></li>
                        <li><a href="#games">Games and the casino</a></li>
                        <li><a href="#site">The site itself</a></li>
                        <li><a href="#report">Reporting someone</a></li>
                        <li><a href="#admins">What admins do</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <section id="short" aria-labelledby="conduct-short">
                        <h2 id="conduct-short">1. The short version</h2>
                        <ul>
                            <li><strong>Be kind.</strong> Joking around is fine. Making someone the joke, when they're not laughing, isn't.</li>
                            <li><strong>Keep it school-friendly.</strong> If you wouldn't show it in a classroom, don't post it here.</li>
                            <li><strong>Respect privacy.</strong> What someone tells you in a chat isn't yours to pass on.</li>
                            <li><strong>Play fair.</strong> Win and lose without cheating, and without being a sore winner or loser.</li>
                            <li><strong>Speak up.</strong> If something is wrong, tell an admin. You won't get in trouble for reporting.</li>
                        </ul>
                    </section>

                    <section id="chat" aria-labelledby="conduct-chat">
                        <h2 id="conduct-chat">2. In the chat</h2>
                        <p>Not okay, in private chats, groups, game chats, GIFs, reactions and attachments alike:</p>
                        <ul>
                            <li>bullying, mocking or ganging up on someone, also "just for fun",</li>
                            <li>threats, or telling someone to hurt themselves,</li>
                            <li>hate or slurs against anyone, for example because of their race, religion, gender, sexuality or disability,</li>
                            <li>sexual content, gore, or anything meant to shock,</li>
                            <li>spamming messages, GIFs, reactions, attachments, @mentions or notifications, or flooding a chat so others can't talk,</li>
                            <li>scams, fake giveaways, or links to harmful or dodgy sites - links to IP loggers (which find out where someone is the moment they click), URL shorteners that can hide where a link goes, crypto scam and phishing sites, and other known bad sites are blocked, and changing the link to sneak one through is just as bad,</li>
                            <li>sending viruses or other harmful files, or files that don't belong to you to share (like pirated films, games or music).</li>
                        </ul>
                        <p>
                            If someone asks you to stop messaging them, stop. Don't keep contacting someone who has blocked you or
                            left a chat with you, for example by adding them to a new group.
                        </p>
                        <p class="legal-note">
                            Deleting a message hides it from the chat, but admins can still see it (see the
                            <a href="/legal/privacy#see">Privacy Policy</a>), so "I deleted it" doesn't undo a mean message.
                            Think before you send.
                        </p>
                    </section>

                    <section id="groups" aria-labelledby="conduct-groups">
                        <h2 id="conduct-groups">3. Running a group</h2>
                        <p>If you make a group, or are an admin in one, you help keep it a good place to be:</p>
                        <ul>
                            <li>Pick a group name, icon and background that everyone in it would be fine with.</li>
                            <li>Don't make groups to talk about, or make fun of, someone who isn't in them.</li>
                            <li>Removing people is fine, but don't use it to single out or embarrass someone.</li>
                            <li>Nicknames you give people should be ones they'd be happy to see.</li>
                        </ul>
                        <p>
                            Group admins can delete messages in their group, but if something serious happens there, still tell a
                            site admin, so it can be dealt with properly.
                        </p>
                    </section>

                    <section id="privacy" aria-labelledby="conduct-privacy">
                        <h2 id="conduct-privacy">4. Other people's privacy</h2>
                        <ul>
                            <li>Don't screenshot or forward someone's messages to embarrass them or spread drama.</li>
                            <li>Don't post photos or videos of other people without asking them first.</li>
                            <li>Don't share someone's personal details, like their address, phone number or accounts on other sites.</li>
                            <li>Don't log in to someone else's account, even if they left it open. Log them out and tell them instead.</li>
                        </ul>
                    </section>

                    <section id="profile" aria-labelledby="conduct-profile">
                        <h2 id="conduct-profile">5. Your profile</h2>
                        <p>
                            Your name, profile picture and bio are seen by everyone on HB Hub, so the same rules apply to them as to
                            messages. Use a name people from school recognise you by, and don't make your profile look like
                            someone else's. Names and bios can only be written with the Latin alphabet (like a-z, æ, ø and å),
                            numbers, punctuation and emoji, so everyone can read them. The links that are blocked in messages
                            can't be put in a bio either.
                        </p>
                        <p>
                            A banned account's profile picture can't be used again. Uploading it, or a resized or cropped copy of
                            it, also when signing up, bans the account straight away, so don't use a picture from someone who has
                            been banned.
                        </p>
                    </section>

                    <section id="games" aria-labelledby="conduct-games">
                        <h2 id="conduct-games">6. Games and the casino</h2>
                        <ul>
                            <li>Trash talk should stay friendly. Say "good game" whether you won or lost.</li>
                            <li>Don't stall a game on purpose, or leave a table just because you're losing. Others are waiting on you.</li>
                            <li>No cheating, scripts or teaming up against one player. Found a bug? Report it, don't use it.</li>
                            <li>Don't spoil the day's Ordle word for others. Share your coloured squares, not the word, until the day is over.</li>
                            <li>Don't pressure anyone into playing, betting, or giving you their chips.</li>
                            <li>Chips are just for fun. Never trade them, or game results, with each other for real money or real things. The only way to buy chips is from the owner (see the <a href="/legal/terms#chips">terms</a>).</li>
                        </ul>
                        <p class="legal-note">
                            If the casino or games are taking up more of your time than you'd like, especially in class, you can
                            block them for yourself in your <a href="/otherPages/settings">settings</a>. If gambling, also with real
                            money, is starting to feel like a problem for you or a friend,
                            <a href="https://stopspillet.dk" target="_blank" rel="noopener noreferrer">StopSpillet</a> gives free
                            and anonymous help.
                        </p>
                    </section>

                    <section id="site" aria-labelledby="conduct-site">
                        <h2 id="conduct-site">7. The site itself</h2>
                        <p>
                            HB Hub is run by one student in their spare time. Please don't try to break it, overload it, or get
                            around its security. If you find a security problem, report it privately through
                            <a href="https://github.com/NoahGNielsen/HB-Hub/security/advisories/new" target="_blank" rel="noopener noreferrer">GitHub</a>
                            or by email instead of telling everyone about it, so it can be fixed first. Thank you, that really helps.
                        </p>
                    </section>

                    <section id="report" aria-labelledby="conduct-report">
                        <h2 id="conduct-report">8. Reporting someone</h2>
                        <p>
                            If someone breaks this code of conduct, or makes you feel unsafe, tell a site admin, use the Feedback
                            button at the bottom of the side bar (only site admins can read it), or email
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>. It helps to say who it was, which
                            chat or game it happened in, and roughly when. Reports are kept private, and the person you report
                            isn't told who reported them.
                        </p>
                        <p>
                            You don't have to keep reading their messages while it's being looked into: you can mute, hide or
                            leave the chat from its menu, and Block on a message request stops them from sending you new ones.
                        </p>
                        <p class="legal-note">
                            If something is serious, like threats, someone being in danger, or anything that happens outside HB Hub
                            too, also talk to a teacher, a parent or another adult you trust. If someone is in immediate danger,
                            call 112.
                        </p>
                    </section>

                    <section id="admins" aria-labelledby="conduct-admins">
                        <h2 id="conduct-admins">9. What admins do</h2>
                        <p>
                            Admins look at every report and decide what to do based on how serious it is. Often a warning is
                            enough. Other times messages are deleted, chips are taken away, or an account is banned. The full list
                            is in the <a href="/legal/terms#moderation">Terms of Service</a>.
                        </p>
                        <p>
                            This page can't list everything, so admins also act on things that aren't mentioned here but clearly go
                            against its spirit. Admins follow this code of conduct too. If you think a decision was unfair, email
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a> and explain why.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
