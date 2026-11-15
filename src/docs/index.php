<?php
// Help docs, the front page: a card for each section (HBHUB_DOCS_SECTIONS in assets/php/docs/pages.php) with its pages
// and what's on them. Open to everyone, like the legal pages. The pages themselves are in this folder and the
// chat/, casino/ and games/ folders under it, each built by assets/php/docs/top.php and bottom.php.
// The docs are in Danish, like every public page, but the app they describe is in English, so its buttons and
// headings are named the way they're written there (in bold).
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/';
$docsIntro = 'Sådan bruger du alt på HB Hub, fra din første besked til dit første Big Rip. Vælg en side nedenfor, eller
    brug menuen ved siden af teksten på en hvilken som helst side.';
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
                        Kan du ikke finde det, du leder efter? Tryk på <strong>Feedback</strong> nederst i sidebjælken og spørg,
                        eller se <a href="/docs/feedback">Feedback og hjælp</a>. Reglerne, alle accepterer, står i
                        <a href="/legal/terms">vilkårene</a> og <a href="/legal/code-of-conduct">adfærdskodekset</a>.
                    </p>
                    <p class="legal-note">
                        Hjælpesiderne er på dansk, men selve HB Hub bag login er på engelsk, så knapper og overskrifter derfra
                        står med deres engelske navne, som du ser dem på siden.
                    </p>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
