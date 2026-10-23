<?php
// Help docs, the front page: a card for each section (HBHUB_DOCS_SECTIONS in assets/php/docs/pages.php) with its pages
// and what's on them. Open to everyone, like the legal pages. The pages themselves are in this folder and the
// chat/, casino/ and games/ folders under it, each built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/';
$docsIntro = 'How to use everything on HB Hub, from your first message to your first Big Rip. Pick a page below, or
    use the menu next to the text on any page.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <div class="docs-sections">
                        <?php foreach (HBHUB_DOCS_SECTIONS as $docsSectionKey => $docsSection): ?>
                            <section class="docs-section-card" aria-labelledby="docs-section-<?= htmlspecialchars($docsSectionKey) ?>">
                                <h2 id="docs-section-<?= htmlspecialchars($docsSectionKey) ?>"><span aria-hidden="true"><?= $docsSection['icon'] ?></span> <?= htmlspecialchars($docsSection['title']) ?></h2>
                                <ul>
                                    <?php foreach ($docsSection['pages'] as $docsCardPath => [$docsCardTitle, $docsCardSummary]): ?>
                                        <?php if ($docsCardPath === '/docs/') continue; // this page ?>
                                        <li>
                                            <a href="<?= htmlspecialchars($docsCardPath) ?>"><?= htmlspecialchars($docsCardTitle) ?></a>
                                            <span><?= htmlspecialchars($docsCardSummary) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </section>
                        <?php endforeach; ?>
                    </div>

                    <p class="legal-note">
                        Can't find what you're looking for? Press <strong>Feedback</strong> at the bottom of the side bar and
                        ask, or see <a href="/docs/feedback">Feedback &amp; getting help</a>. The rules everyone agrees to
                        are in the <a href="/legal/terms">Terms</a> and the <a href="/legal/code-of-conduct">Code of Conduct</a>.
                    </p>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
