<?php
// Terms of service: the rules for using HB Hub - accounts, how to behave in the chat, what the chips are (and aren't),
// fair play in the games, what admins can do about it, and that the site comes as-is.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too, just like privacy.php. Open to everyone, like the frontpage.
// Sign-up (onboarding.php) links here.
// When a rule changes, HBHUB_TERMS_UPDATED gets the date of the change.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php'; // only for the start and daily chip amounts

const HBHUB_TERMS_UPDATED = '20 October 2026';
?>
<!DOCTYPE html>
<html lang="en" class="home">
<head>
    <?php $headerHomeNav = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Terms of Service - HB Hub</title>
    <meta name="description" content="The rules for using HB Hub: your account, how to behave in the chat, what the casino chips are, fair play and what happens if the rules are broken.">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <article class="legal-page" aria-labelledby="terms-title">
            <header class="legal-hero">
                <div class="legal-hero-inner">
                    <p class="home-kicker legal-kicker">Legal</p>
                    <h1 id="terms-title">Terms of Service</h1>
                    <p class="legal-intro">
                        HB Hub is a student project, made for fun and for chatting with people from school. These terms are the
                        house rules. They're short and in plain words, so please read them. By making an account or using the
                        site, you agree to them.
                    </p>
                    <p class="legal-updated">Last updated <?= HBHUB_TERMS_UPDATED ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="On this page">
                    <p class="legal-toc-title" aria-hidden="true">On this page</p>
                    <ol>
                        <li><a href="#about">About these terms</a></li>
                        <li><a href="#account">Your account</a></li>
                        <li><a href="#behave">How to behave</a></li>
                        <li><a href="#content">What you post</a></li>
                        <li><a href="#chips">The casino and chips</a></li>
                        <li><a href="#games">Games and fair play</a></li>
                        <li><a href="#moderation">When the rules are broken</a></li>
                        <li><a href="#leaving">Leaving HB Hub</a></li>
                        <li><a href="#asis">The site comes as-is</a></li>
                        <li><a href="#changes">Changes to these terms</a></li>
                        <li><a href="#law">Law and contact</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <section id="about" aria-labelledby="terms-about">
                        <h2 id="terms-about">1. About these terms</h2>
                        <p>
                            HB Hub (hbhub.noahgajnielsen.dk) is made and run by Noah Gaj Nedergaard Nielsen in Denmark. It's not
                            a company, and it's not run by the school. These terms are an agreement between you and the person
                            running HB Hub.
                        </p>
                        <p>
                            How your data is handled is explained separately in the <a href="/legal/privacy">Privacy Policy</a>.
                        </p>
                    </section>

                    <section id="account" aria-labelledby="terms-account">
                        <h2 id="terms-account">2. Your account</h2>
                        <ul>
                            <li><strong>One account per person.</strong> Don't make extra accounts, for example to get more daily chips or to get around a ban.</li>
                            <li><strong>Be yourself.</strong> Use a name people from school will recognise you by, and don't pretend to be someone else.</li>
                            <li>Names and bios can only use letters from the Latin alphabet (like a-z, æ, ø and å), numbers, punctuation and emoji. Other alphabets, like Arabic, Chinese or Cyrillic, can't be used in them. A bio can't have a link to one of the blocked sites in it either (see section 3).</li>
                            <li><strong>Keep it to yourself.</strong> Don't share your password or let others use your account. You're responsible for what happens on it.</li>
                            <li>Turning on two-factor login in your <a href="/otherPages/settings">settings</a> is a good idea. If you think someone else has got into your account, change your password and tell an admin.</li>
                        </ul>
                    </section>

                    <section id="behave" aria-labelledby="terms-behave">
                        <h2 id="terms-behave">3. How to behave</h2>
                        <p>Treat people on HB Hub the way you would at school. That means no:</p>
                        <ul>
                            <li>bullying, harassment, threats or hate against anyone, for example because of their race, religion, gender, sexuality or disability,</li>
                            <li>sexual or violent content, or anything else you wouldn't show in a classroom (GIFs and pictures count too),</li>
                            <li>sharing other people's private information, photos or messages without their permission,</li>
                            <li>spam, scams, or links to harmful sites (links to IP loggers, URL shorteners that can hide where a link goes, crypto scam and phishing sites, and other sites that are dangerou),</li>
                            <li>anything illegal, or encouraging others to do something illegal or dangerous,</li>
                            <li>trying to break into other accounts, overload the site, or get around its security.</li>
                        </ul>
                        <p>
                            If someone breaks these rules, or makes you feel unsafe, tell an admin or email
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>

                    <section id="content" aria-labelledby="terms-content">
                        <h2 id="terms-content">4. What you post</h2>
                        <p>
                            What you write and upload (messages, reactions, attachments, profile pictures, group icons and backgrounds) is
                            still yours. By posting it, you let HB Hub store it and show it to the people it's meant for, which is
                            needed for the site to work. That includes turning pictures you attach into JPEG XL (without losing
                            any detail, except JPEG photos, which are made smaller in a way that looks the same), and zipping
                            other files, so they take less space.
                        </p>
                        <ul>
                            <li>Only upload pictures and files you're allowed to share. Don't post photos of other people without asking them.</li>
                            <li>
                                Attachments don't stay forever. Everyone has 10 tickets, and every started 5 MB of an attachment
                                takes one. Site admins can give an account more tickets, or take some away (for example if
                                attachments are misused). It's deleted after 90 days (3 days when it takes more than 5 tickets), and then the
                                tickets can be used again. HB Hub isn't a backup, so keep your own copy of anything that matters.
                                When the server's space for attachments is full, nobody can send new ones until some are deleted.
                            </li>
                            <li>You're responsible for what you post. Admins can remove anything that breaks these terms.</li>
                            <li>
                                Messages aren't end-to-end encrypted, and admins can read them when they look into a report
                                (see the <a href="/legal/privacy#see">Privacy Policy</a>). Don't send anything you wouldn't want an admin to see.
                            </li>
                            <li>
                                Feedback you send with the Feedback button goes to the site admins only. They can use your ideas
                                to change the site, without owing you anything for them. Don't use it to spam, harass or insult anyone,
                                the same rules apply as in the chat.
                            </li>
                        </ul>
                    </section>

                    <section id="chips" aria-labelledby="terms-chips">
                        <h2 id="terms-chips">5. The casino and chips</h2>
                        <p>
                            The casino on HB Hub is a game. You get <?= number_format(HBHUB_CASINO_START_CHIPS) ?> chips when you
                            start, and <?= number_format(HBHUB_CASINO_DAILY_CHIPS) ?> more every day for free.
                        </p>
                        <ul>
                            <li><strong>Chips are not money.</strong> They can't be swapped back into real money, gift cards or anything else, and nothing you win in the casino can be paid out.</li>
                            <li>Don't sell, buy or bet chips, accounts or casino results for real money or real things between each other, on HB Hub or anywhere else.</li>
                            <li>Site admins can add chips to an account or take them away. That's how bought chips are added, and how balances are corrected or reset, for example after a bug, or if chips were got by cheating.</li>
                        </ul>
                        <h3>Buying extra chips</h3>
                        <p>
                            Chips are normally free. If you want more, you can buy them from the owner of HB Hub at
                            <?= number_format(HBHUB_CASINO_CHIPS_PER_KRONE) ?> chips for 1 kr, as described on the
                            <a href="/gambling/buy-chips">Buy chips</a> page. This is the only way to buy chips.
                        </p>
                        <ul>
                            <li>Bought chips are the same as any other chips. They can be lost in the games, and everything above applies to them too.</li>
                            <li>
                                <strong>No refunds, for any reason.</strong> If you're banned, delete your account or block
                                the casino in your settings, you will not get your money back. The same goes for bought chips
                                that are lost, corrected or reset.
                            </li>
                        </ul>
                        <p class="legal-note">
                            If you'd rather not see the casino or games, especially during school hours, you can block them for
                            yourself in your <a href="/otherPages/settings">settings</a>. If gambling of any kind, also with real
                            money, is starting to feel like a problem, you can get free and anonymous help from
                            <a href="https://stopspillet.dk" target="_blank" rel="noopener noreferrer">StopSpillet</a>.
                        </p>
                    </section>

                    <section id="games" aria-labelledby="terms-games">
                        <h2 id="terms-games">6. Games and fair play</h2>
                        <ul>
                            <li>Play fair. No cheating, no using scripts or bots, and no teaming up to ruin a game for others.</li>
                            <li>
                                Found a bug, for example one that gives you free chips? Please report it with the Feedback
                                button in the side bar, or to <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>, instead of using it. Using
                                it on purpose counts as cheating.
                            </li>
                            <li>Games have timers. If you leave or don't make your move in time, the game carries on without you, and that can cost you the game.</li>
                            <li>
                                Wordle has one English and one Danish word a day, and you get one go at each. A word you start
                                but don't guess by midnight (Danish time) counts as missed, and ends your streak. Don't spoil
                                the day's word for others, for example in a group chat, before the day is over.
                            </li>
                        </ul>
                    </section>

                    <section id="moderation" aria-labelledby="terms-moderation">
                        <h2 id="terms-moderation">7. When the rules are broken</h2>
                        <p>Depending on how serious it is, admins can:</p>
                        <ul>
                            <li>delete messages, attachments, pictures or groups, and ban a profile picture they delete, so it can't be used again,</li>
                            <li>reset or take away chips, or remove game results from the leaderboards,</li>
                            <li>take away attachment tickets, so fewer (or no) new attachments can be sent,</li>
                            <li>
                                ghost ban the account, for a while or for good, without telling the person. The chat keeps
                                working like normal for them, but what they do in it (messages, attachments, edits, reactions,
                                and the chats they start or add people to) only shows to themselves and site admins. Nobody
                                else sees it or gets a notification or message request from it,
                            </li>
                            <li>ban the account, for a while or for good. A ban also covers the browser it was used in, and usually the account's profile picture.</li>
                        </ul>
                        <p>
                            Using a banned profile picture is against the rules too: an account that uploads one, or signs up with
                            one, is banned straight away, without an admin having to do anything. That includes edited copies that
                            look almost the same, like a resized or cropped one, and the account the picture was removed from.
                            Banned pictures are the pictures of banned accounts, profile pictures admins have removed and banned,
                            and other pictures admins have added.
                        </p>
                        <p>
                            Admins decide what breaks the rules, also for things these terms don't list. If you think a decision
                            was wrong, email <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a> and explain why.
                        </p>
                    </section>

                    <section id="leaving" aria-labelledby="terms-leaving">
                        <h2 id="terms-leaving">8. Leaving HB Hub</h2>
                        <p>
                            You can stop using HB Hub whenever you want, and delete your account on the
                            <a href="/otherPages/settings">settings page</a>. The <a href="/legal/privacy#keep">Privacy Policy</a>
                            explains what's removed then, and how to get everything deleted.
                        </p>
                        <p>
                            HB Hub can also close an account that breaks these terms, or shut down the whole site. If the site
                            shuts down, there'll be a notice on it beforehand when possible.
                        </p>
                    </section>

                    <section id="asis" aria-labelledby="terms-asis">
                        <h2 id="terms-asis">9. The site comes as-is</h2>
                        <p>
                            HB Hub is a free side project, run by one student in their spare time. It's still being worked on,
                            so things can change, break, go down for maintenance, or in the worst case be lost, without warning.
                            Keep your own copy of anything that's important to you.
                        </p>
                        <p>
                            The site is offered as it is, with no promise that it always works or is free of mistakes. As far as
                            Danish law allows, HB Hub isn't responsible for losses caused by using it, by it being down, or by
                            what other users post.
                        </p>
                    </section>

                    <section id="changes" aria-labelledby="terms-changes">
                        <h2 id="terms-changes">10. Changes to these terms</h2>
                        <p>
                            As HB Hub gets new features, these terms may change. The date at the top shows when they last did.
                            If something big changes, it'll be announced on the site. If you keep using HB Hub after a change, you
                            agree to the new terms.
                        </p>
                    </section>

                    <section id="law" aria-labelledby="terms-law">
                        <h2 id="terms-law">11. Law and contact</h2>
                        <p>
                            These terms follow Danish law. Nothing in them takes away rights you have under the law anyway.
                        </p>
                        <p>
                            Questions about the terms, a report, or something else? Email
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
