<?php
// The bottom half of every help docs page: closes what top.php opened, with links to the page before and after it
// (in HBHUB_DOCS_SECTIONS' order) under the text, and the site footer. Needs top.php's $docsPage.
?>
                    <nav class="docs-pager" aria-label="More docs">
                        <?php if ($docsPage['prev'] !== null): ?>
                            <a class="docs-pager-link is-prev" href="<?= htmlspecialchars($docsPage['prev']['path']) ?>">
                                <span class="docs-pager-label">&larr; Previous</span>
                                <span class="docs-pager-title"><?= htmlspecialchars($docsPage['prev']['title']) ?></span>
                            </a>
                        <?php endif; ?>
                        <?php if ($docsPage['next'] !== null): ?>
                            <a class="docs-pager-link is-next" href="<?= htmlspecialchars($docsPage['next']['path']) ?>">
                                <span class="docs-pager-label">Next &rarr;</span>
                                <span class="docs-pager-title"><?= htmlspecialchars($docsPage['next']['title']) ?></span>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
