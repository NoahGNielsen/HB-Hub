<?php
// Help docs: blocking the Casino and Games for yourself (assets/php/block.php) - the two blocks, why turning one down
// waits, and a block set by an admin. The hours come from block.php's constants. Open to everyone. Built by
// assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/block.php';

/**
 * An hour of the day the way people say it, like "8 AM" or "3 PM".
 */
function docsHour(int $hour): string
{
    return ($hour % 12 === 0 ? 12 : $hour % 12) . ($hour < 12 ? ' AM' : ' PM');
}

$docsPath = '/docs/self-blocking';
$docsIntro = 'Playing too much when you should be in class? Block the Casino and Games for yourself, and HB Hub helps
    you stick to it.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="how" aria-labelledby="docs-how">
                        <h2 id="docs-how">Turning on a block</h2>
                        <p>
                            Open the <strong>Block Gambling and Games</strong> card in your
                            <a href="/otherPages/settings#block">settings</a> and pick a block. It covers both the
                            <a href="/docs/casino/">Casino</a> and the <a href="/docs/games/">Games</a>.
                        </p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Block</th><th scope="col">What it does</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>No block</td><td>Everything is open.</td></tr>
                                    <tr><td>During school hours</td><td>The Casino and Games are closed from <?= docsHour(HBHUB_BLOCK_SCHOOL_START) ?> to <?= docsHour(HBHUB_BLOCK_SCHOOL_END) ?>, Monday to Friday (Danish time).</td></tr>
                                    <tr><td>All the time</td><td>The Casino and Games are always closed.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>While a block is on, opening a casino or games page shows a page saying Gambling and Games are blocked instead. The chat always works.</p>
                    </section>

                    <section id="wait" aria-labelledby="docs-wait">
                        <h2 id="docs-wait">Why turning it off waits</h2>
                        <p>
                            A stricter block starts straight away. Turning one down doesn't, so it can't be switched off in a weak
                            moment:
                        </p>
                        <ul>
                            <li>From <strong>During school hours</strong>, during school hours: it waits until <?= docsHour(HBHUB_BLOCK_SCHOOL_END) ?>.</li>
                            <li>From <strong>All the time</strong>: it waits <?= HBHUB_BLOCK_ALWAYS_DELAY / 3600 ?> hours.</li>
                        </ul>
                        <p>The settings page shows when the change kicks in. Until then the stricter block still counts.</p>
                    </section>

                    <section id="admin" aria-labelledby="docs-admin">
                        <h2 id="docs-admin">A block from an admin</h2>
                        <p>
                            Site admins can also block the Casino and Games for someone. It works like "All the time", on top of your
                            own block. The settings card then says <strong>Blocked by an admin</strong>, and only an admin can lift it.
                        </p>
                        <p>
                            Separate from all of this, the whole Casino or Games section can be closed for everyone by the people
                            running HB Hub. Then it's closed whatever your block says.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
