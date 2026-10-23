<?php
// The top half of every help docs page (src/docs/): the <head>, the frontpage's top bar (homeNav.php), a dark hero
// with the page's title, and the side menu with every docs page (pages.php). It leaves .docs-body open for the page's
// own text, which bottom.php closes again. Built like the legal pages (legal.css), with docs.css on top.
//
// A page sets these first, after session.php (with $hbHubSessionOptional = true, as the docs are open to everyone):
// - $docsPath: its path in HBHUB_DOCS_SECTIONS, like '/docs/casino/blackjack'
// - $docsIntro: the text under the title, as HTML (written by hand in the page, so it isn't escaped)
require_once __DIR__ . '/pages.php';

$docsPage = hbHubDocsPage($docsPath);
$docsIsFront = $docsPath === '/docs/';
?>
<!DOCTYPE html>
<html lang="en" class="home">
<head>
    <?php $headerHomeNav = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <link rel="stylesheet" href="https://hbhub.noahgajnielsen.dk/assets/css/docs.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/docs.css') ?>">
    <title><?= htmlspecialchars($docsIsFront ? 'Help & docs - HB Hub' : $docsPage['title'] . ' - HB Hub docs') ?></title>
    <meta name="description" content="<?= htmlspecialchars($docsPage['summary']) ?>">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <article class="legal-page docs-page" aria-labelledby="docs-title">
            <header class="legal-hero">
                <div class="legal-hero-inner">
                    <?php if ($docsIsFront): ?>
                        <p class="home-kicker legal-kicker">Docs</p>
                    <?php else: ?>
                        <!-- Where the page is: the docs, then its section (a link when the section has a page of its own) -->
                        <nav class="docs-crumbs" aria-label="Breadcrumbs">
                            <a href="/docs/">Docs</a>
                            <span aria-hidden="true">/</span>
                            <?php $docsSectionHome = array_key_first($docsPage['section']['pages']); ?>
                            <?php if ($docsSectionHome !== '/docs/' && $docsSectionHome !== $docsPath): ?>
                                <a href="<?= htmlspecialchars($docsSectionHome) ?>"><?= htmlspecialchars($docsPage['section']['title']) ?></a>
                            <?php else: ?>
                                <span><?= htmlspecialchars($docsPage['section']['title']) ?></span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                    <h1 id="docs-title"><?= htmlspecialchars($docsPage['title']) ?></h1>
                    <p class="legal-intro"><?= $docsIntro ?></p>
                </div>
            </header>

            <div class="legal-layout docs-layout">
                <!-- Every docs page, by section. After the text on small screens (docs.css), so the page starts with what was opened. -->
                <nav class="docs-nav" aria-label="Docs">
                    <?php foreach (HBHUB_DOCS_SECTIONS as $docsSectionKey => $docsSection): ?>
                        <div class="docs-nav-group">
                            <p class="docs-nav-title" id="docs-nav-<?= htmlspecialchars($docsSectionKey) ?>">
                                <span aria-hidden="true"><?= $docsSection['icon'] ?></span> <?= htmlspecialchars($docsSection['title']) ?>
                            </p>
                            <ul aria-labelledby="docs-nav-<?= htmlspecialchars($docsSectionKey) ?>">
                                <?php foreach ($docsSection['pages'] as $docsLinkPath => [$docsLinkTitle]): ?>
                                    <li><a href="<?= htmlspecialchars($docsLinkPath) ?>"<?= $docsLinkPath === $docsPath ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($docsLinkTitle) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </nav>

                <div class="legal-body docs-body">
