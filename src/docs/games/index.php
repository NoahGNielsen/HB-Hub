<?php
// Help docs: how games work - the multiplayer games' rooms (public, code and invite-only, like
// games/matador/room.php), what the host can do, game chats, leaving, and the leaderboards; and the two you play on
// your own. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';

$docsPath = '/docs/games/';
$docsIntro = 'Five games, no chips needed: three to play with friends and two to play on your own every day. All of
    them are under <a href="/games/">Games</a> in the side bar.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <section id="list" aria-labelledby="docs-list">
                        <h2 id="docs-list">The games</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Game</th><th scope="col">What it is</th><th scope="col">Players</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><a href="/docs/games/matador">Matador</a></td><td>The Danish Monopoly: buy streets, build hotels and bankrupt your friends.</td><td>2-4</td></tr>
                                    <tr><td><a href="/docs/games/chess">Chess</a></td><td>Chess, with or without a clock.</td><td>2</td></tr>
                                    <tr><td><a href="/docs/games/uno">UNO</a></td><td>The card game. Get rid of your cards first, and don't forget to say UNO!</td><td>2-6</td></tr>
                                    <tr><td><a href="/docs/games/wordle">Wordle</a></td><td>Guess the day's five-letter word, in English and in Danish.</td><td>Just you</td></tr>
                                    <tr><td><a href="/docs/games/ore-mining-inc">Ore Mining Inc.</a></td><td>A clicker game about mining asteroids, and then the whole multiverse.</td><td>Just you</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="rooms" aria-labelledby="docs-rooms">
                        <h2 id="docs-rooms">Opening a game</h2>
                        <p>Matador, Chess and UNO are played in rooms. On the game's page, open one or join one:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Kind of game</th><th scope="col">Who can join</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Public</td><td>Anyone. It's listed on the game's page.</td></tr>
                                    <tr><td>Code</td><td>Anyone with its 6-character code, typed in on the game's page.</td></tr>
                                    <tr><td>Invite-only</td><td>Only the people the host invites. They get a <a href="/docs/notifications">notification</a>, and the invite waits on the game's page.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>Every game has a code, whatever kind it is, so you can always send it to a friend. Codes leave out letters that look alike, like 0 and O.</p>
                    </section>

                    <section id="host" aria-labelledby="docs-host">
                        <h2 id="docs-host">The host</h2>
                        <p>Whoever opens a game is its host. The host:</p>
                        <ul>
                            <li>picks the game's settings (like the clock in Chess, or the turn timer and stacking in UNO),</li>
                            <li>invites people, and starts the game once enough have joined,</li>
                            <li>can pause the game, which stops the timers until they go on,</li>
                            <li>and in Matador can give an empty seat to someone else, or save the game to finish another day.</li>
                        </ul>
                    </section>

                    <section id="playing" aria-labelledby="docs-playing">
                        <h2 id="docs-playing">While you play</h2>
                        <ul>
                            <li><strong>Game chat:</strong> every game has its own chat, with <a href="/docs/chat/mentions">@mentions</a>. It follows the same rules as the normal chat.</li>
                            <li><strong>Your turn:</strong> in Matador and UNO a sound plays when it's your turn, so you can look at something else while you wait.</li>
                            <li><strong>Timers:</strong> turns have a time limit, so nobody can keep the game waiting. What happens when it runs out is different in each game.</li>
                            <li><strong>Leaving:</strong> in Chess and UNO, leaving a game that's being played counts as a loss. In Matador your seat is kept for you. Keeping the game closed for a while counts as leaving it.</li>
                        </ul>
                    </section>

                    <section id="leaderboards" aria-labelledby="docs-leaderboards">
                        <h2 id="docs-leaderboards">Leaderboards</h2>
                        <p>
                            Every game has a leaderboard, linked from its page. Click a column's heading to sort by it. Win % (or the
                            score, in Chess) only counts once you've played 5 games, so one lucky game can't top it.
                        </p>
                    </section>

                    <section id="fair" aria-labelledby="docs-fair">
                        <h2 id="docs-fair">Playing fair</h2>
                        <p>
                            No cheating, no bots or auto-clickers, no extra accounts to win against yourself, and no bugs used for an
                            advantage. Found a bug? <a href="/docs/feedback">Tell the admins</a> instead. And win and lose nicely.
                            The details are in the <a href="/legal/code-of-conduct">Code of Conduct</a>.
                        </p>
                        <p>
                            The games can be <a href="/docs/self-blocking">blocked for yourself</a> during school hours or all the time.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
