// Slots (gambling/slots/): the slot machine. Spin sends the bet to /gambling/slots/spin (data-api, see
// assets/php/casino/slots/slots.php), which picks where each reel stops and pays out; this only shows it. Each reel rolls
// round its real strip (data-reels) and stops where the server said, the left one first, then the winning lines light up.
// A reel is rolled by filling its strip with the symbols between where it stands and where it stops (plus a few whole
// turns), standing it at the bottom of that, and sliding it to the top: the symbols come down from above like on a real
// machine. When it's stopped, the strip is cut back to the three symbols in view.
// The bet is picked with the quick bets or - and + (in steps of 10); with fewer than 10 chips the All in chip bets all
// of them. Space spins too. Every spin has an id of its own (request), so one sent twice only counts once.
// The chips in the top bar (casino.js) only change when the reels have stopped, so they don't give the result away.
// A win sets things off, more the bigger it is (see "Win effects" further down): sparkles, coins, a banner over the
// machine, coin and confetti rain, fireworks. Coins and the like are drawn on a canvas over the page (data-sl-fx).
// The rest of the spin has a show too ("Spin effects"): the lever pulls itself, each reel lands with a thud, a flash and
// sparks, and when the first two reels have lined up two of a good symbol (tease()), the last one rolls on longer, glowing,
// with the line it could win on flickering. Winning lines draw themselves in with a spot of light running along them, and
// with more than one, they're shown one at a time with what each paid.
// All of it has sound effects too ("Sounds"), made with Web Audio rather than loaded, which the Sound button in the
// header mutes (remembered in localStorage as hbHubSlotsSound).
(() => {
    const root = document.querySelector('[data-slots]');
    if (!root) return;

    const API = root.dataset.api;
    const IS_REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const REELS = JSON.parse(root.dataset.reels); // [[symbol, ...], x3], the strips from the top
    const SYMBOLS = JSON.parse(root.dataset.symbols); // symbol => [name, what's shown]
    const LINES = JSON.parse(root.dataset.lines); // [[row on each reel], ...]
    const BET_STEP = Number(root.dataset.betStep);
    const MIN_BET = Number(root.dataset.minBet);
    const REEL_MS = [1100, 1500, 1900]; // how long each reel rolls, so they stop one after another from the left
    const REEL_TURNS = [2, 3, 4]; // whole turns of its strip each one rolls first
    const TEASE_MS = 1600; // how much longer the last reel rolls when the first two could make a good win (tease())
    const TEASE_TURNS = 3; // and how many more turns it rolls in that time, so it doesn't just go slower
    const TEASE_SYMBOLS = ['seven', 'diamond', 'star']; // the symbols worth teasing
    const BIG_WIN = 10; // a win of this many times the bet or more is a big one
    const MEGA_WIN = 30; // and this many times a mega one (three 7s on a line is always a jackpot, 60 times)
    const HISTORY_MAX = 10;
    const ERRORS = {
        blocked: 'Gambling is blocked in your settings right now.',
        closed: 'The casino isn\'t open right now.',
        badBet: 'That bet can\'t be played. Bets go in steps of ' + BET_STEP + '.',
        notEnoughChips: 'You don\'t have enough chips for that bet.',
    };

    const find = (selector) => root.querySelector(selector);
    const strips = [...root.querySelectorAll('[data-sl-reel]')];
    const lineDrawings = [...root.querySelectorAll('[data-sl-line]')];
    const lineComets = [...root.querySelectorAll('[data-sl-line-comet]')];
    const lineNumbers = [...root.querySelectorAll('[data-sl-line-number]')];
    const windowText = find('[data-sl-window-text]');
    const resultText = find('[data-sl-result]');
    const winText = find('[data-sl-win]');
    const message = find('[data-sl-message]');
    const betText = find('[data-sl-bet]');
    const lineBetText = find('[data-sl-line-bet]');
    const lessButton = find('[data-sl-less]');
    const moreButton = find('[data-sl-more]');
    const chipButtons = [...root.querySelectorAll('[data-sl-chip]')];
    const allInButton = find('[data-sl-all-in]');
    const ALL_IN_BELOW = Number(allInButton.dataset.slAllIn);
    const spinButton = find('[data-sl-spin]');
    const brokeHint = find('[data-sl-broke]');
    const historyList = find('[data-sl-history]');
    const historyEmpty = find('[data-sl-history-empty]');
    const navChips = document.querySelector('[data-casino-chips]'); // kept up to date by casino.js

    let chips = Number(root.dataset.chips);
    let bet = MIN_BET;
    let isAllIn = false;
    let isSpinning = false;
    let stops = root.dataset.stops.split(' ').map(Number); // where the reels stand now

    const formatChips = (amount) => amount.toLocaleString('en-US');
    const symbolAt = (reel, position) => {
        const strip = REELS[reel];
        return strip[((position % strip.length) + strip.length) % strip.length];
    };
    const windowAt = (at) => [0, 1, 2].map((row) => [0, 1, 2].map((reel) => symbolAt(reel, at[reel] + row - 1)));

    // The user's chips: the top bar's when it's there, as casino.js keeps that up to date (daily chips collected in
    // another tab and the like)
    function walletChips() {
        const shown = navChips ? Number(navChips.dataset.casinoChips) : NaN;
        return Number.isFinite(shown) ? shown : chips;
    }

    function setChips(newChips) {
        chips = newChips;
        document.dispatchEvent(new CustomEvent('casino:wallet', { detail: { chips: newChips } }));
    }

    function showMessage(text) {
        message.textContent = text;
    }

    // The bet being played: all the chips for an all in, else the one picked
    function playedBet() {
        return isAllIn ? walletChips() : bet;
    }

    function canGoAllIn() {
        const have = walletChips();
        return have > 0 && have < ALL_IN_BELOW;
    }

    // Shows the bet, which buttons can be pressed, and the All in chip when the user has too few chips for any bet
    function draw() {
        const have = walletChips();
        if (isAllIn && !canGoAllIn()) isAllIn = false;
        if (!isSpinning && !isAllIn && bet > have) {
            // Not enough for the bet anymore: the most that's left, in steps of 10, or All in for the last few chips
            if (have >= MIN_BET) {
                bet = Math.floor(have / BET_STEP) * BET_STEP;
            } else if (canGoAllIn()) {
                isAllIn = true;
            }
        }
        const played = playedBet();
        betText.textContent = formatChips(played);
        lineBetText.textContent = formatChips(Math.floor((played / LINES.length) * 10) / 10);

        allInButton.hidden = !canGoAllIn();
        allInButton.setAttribute('aria-pressed', String(isAllIn));
        chipButtons.forEach((button) => {
            const amount = Number(button.dataset.slChip);
            button.setAttribute('aria-pressed', String(!isAllIn && amount === bet));
            button.disabled = isSpinning || amount > have;
        });
        allInButton.disabled = isSpinning;
        lessButton.disabled = isSpinning || isAllIn || bet <= MIN_BET;
        moreButton.disabled = isSpinning || isAllIn || nextBet(1) > have;
        spinButton.disabled = isSpinning || played <= 0 || played > have;
        brokeHint.hidden = isSpinning || have > 0;
    }

    // How far - and + move a bet of $amount: 10 under 100, then half its power of ten up to 5 of it, and the whole
    // power of ten after that (50 from 100, 100 from 500, 500 from 1,000...), so a big bet is a few clicks away
    function betStep(amount) {
        if (amount < 100) return BET_STEP;
        const power = 10 ** Math.floor(Math.log10(amount));
        return amount < 5 * power ? power / 2 : power;
    }

    // The bet one step up or down. Going down it takes the step of the bet below, so - and + undo each other
    function nextBet(direction) {
        return direction > 0 ? bet + betStep(bet) : Math.max(MIN_BET, bet - betStep(bet - 1));
    }

    function pickBet(amount) {
        isAllIn = false;
        bet = Math.max(MIN_BET, amount);
        showMessage('');
        draw();
    }

    // Lights up the winning lines, their numbers and the symbols on them, or puts them all out (lines = []). The lines
    // draw themselves in one after another: --sl-order is which one each is, for slots.css's delay
    function showLines(lines) {
        const winning = new Set(lines.map((line) => line.line));
        const order = lines.map((line) => line.line);
        [...lineDrawings, ...lineComets].forEach((path) => {
            const line = Number(path.dataset.slLine ?? path.dataset.slLineComet);
            path.classList.toggle('is-win', winning.has(line));
            path.style.setProperty('--sl-order', String(Math.max(0, order.indexOf(line))));
        });
        lineNumbers.forEach((item) => item.classList.toggle('is-win', winning.has(Number(item.dataset.slLineNumber))));
        strips.forEach((strip) => strip.querySelectorAll('.sl-symbol').forEach((cell) => cell.classList.remove('is-win', 'is-focus')));
        winning.forEach((line) => {
            LINES[line].forEach((row, reel) => strips[reel].children[row]?.classList.add('is-win'));
        });
    }

    function symbolElement(symbol) {
        const cell = document.createElement('span');
        cell.className = 'sl-symbol sl-symbol-' + symbol;
        cell.dataset.slSymbol = symbol;
        cell.title = SYMBOLS[symbol][0];
        const face = document.createElement('span');
        face.className = 'sl-symbol-face';
        face.setAttribute('aria-hidden', 'true');
        face.textContent = SYMBOLS[symbol][1];
        cell.append(face);
        return cell;
    }

    // Rolls one reel from where it stands to $to, $turns whole turns of its strip first, calls $onStop when it's stopped
    // (not without a $duration, as nothing moved), and resolves then
    function rollReel(reel, to, duration, turns, onStop) {
        const strip = strips[reel];
        const from = stops[reel];
        const length = REELS[reel].length;
        // From the symbol above where it stops, down the strip to the one below where it stands now: in view at the
        // end of it now, and the top three in view when it's done
        const between = ((from - to) % length + length) % length;
        const count = between + 3 + (duration ? turns * length : 0);
        const cells = [];
        for (let i = 0; i < count; i++) cells.push(symbolElement(symbolAt(reel, to - 1 + i)));
        strip.replaceChildren(...cells);

        const cellHeight = strip.firstElementChild.getBoundingClientRect().height;
        strip.style.transition = 'none';
        strip.style.transform = 'translateY(' + -(count - 3) * cellHeight + 'px)';
        strip.getBoundingClientRect(); // so the browser stands it there before it slides

        return new Promise((resolve) => {
            const done = () => {
                strip.parentElement.classList.remove('is-rolling');
                strip.style.transition = 'none';
                strip.style.transform = '';
                strip.replaceChildren(...cells.slice(0, 3));
                if (duration) onStop?.(reel);
                resolve();
            };
            if (!duration) {
                done();
                return;
            }
            strip.parentElement.classList.add('is-rolling');
            strip.style.transition = 'transform ' + duration + 'ms cubic-bezier(0.25, 0.1, 0.2, 1.06)';
            strip.style.transform = 'translateY(0)';
            setTimeout(done, duration);
        });
    }

    function describeWindow(at) {
        windowText.textContent = windowAt(at).map((row) => row.map((symbol) => SYMBOLS[symbol][0]).join(', ')).join('. ');
    }

    function addHistory(spin) {
        // is-new slides it in (and flashes a win gold, a big one in colour by its data-sl-tier)
        const item = document.createElement('li');
        item.className = 'sl-history-item is-new' + (spin.payout > 0 ? ' is-win' : '');
        if (spin.payout > 0) item.dataset.slTier = winTier(spin);
        const middle = spin.window[1];
        const label = middle.map((symbol) => SYMBOLS[symbol][0]).join(', ') + ': bet ' + formatChips(spin.bet) + ', '
            + (spin.payout > 0 ? 'won ' + formatChips(spin.payout) : 'no win');
        item.title = label;

        const symbols = document.createElement('span');
        symbols.className = 'sl-history-symbols';
        symbols.setAttribute('aria-hidden', 'true');
        middle.forEach((symbol) => {
            const shown = document.createElement('span');
            if (symbol === 'seven') shown.className = 'sl-history-seven';
            shown.textContent = SYMBOLS[symbol][1];
            symbols.append(shown);
        });
        const spoken = document.createElement('span');
        spoken.className = 'casino-sr-only';
        spoken.textContent = label;
        const net = document.createElement('span');
        net.className = 'sl-history-net' + (spin.net > 0 ? ' casino-net-up' : spin.net < 0 ? ' casino-net-down' : '');
        net.setAttribute('aria-hidden', 'true');
        net.textContent = (spin.net > 0 ? '+' : spin.net < 0 ? '−' : '') + formatChips(Math.abs(spin.net));
        item.append(symbols, spoken, net);

        historyList.prepend(item);
        while (historyList.children.length > HISTORY_MAX) historyList.lastElementChild.remove();
        historyEmpty.hidden = true;
    }

    function requestId() {
        const bytes = new Uint8Array(12);
        crypto.getRandomValues(bytes);
        return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    // Sends the spin, and sends it again (with the same id, so it still only counts once) if the answer got lost
    async function send(body) {
        for (let attempt = 0; attempt < 3; attempt++) {
            try {
                const response = await fetch(API, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body,
                });
                return await response.json();
            } catch {
                await new Promise((resolve) => setTimeout(resolve, 600 * (attempt + 1)));
            }
        }
        return null;
    }

    async function spin() {
        const played = playedBet();
        if (isSpinning || played <= 0 || played > walletChips()) return;
        isSpinning = true;
        showMessage('');
        showLines([]);
        clearEffects();
        winText.hidden = true;
        winText.classList.remove('is-big');
        root.classList.remove('is-winning');
        root.classList.add('is-spinning');
        pullLever();
        resultText.textContent = 'Spinning…';
        draw();

        const answer = await send(new URLSearchParams({ action: 'spin', bet: String(played), request: requestId() }));
        if (!answer || !answer.spin) {
            root.classList.remove('is-spinning');
            isSpinning = false;
            resultText.textContent = 'Pick a bet and pull the lever.';
            showMessage(answer ? ERRORS[answer.error] ?? 'Something went wrong. Please try again.' : 'Couldn\'t reach HB Hub. Check your connection and try again.');
            if (answer && typeof answer.chips === 'number') setChips(answer.chips);
            draw();
            return;
        }

        const result = answer.spin;
        // The bet comes off straight away, as it would at a real machine; what it won only when the reels stop
        setChips(walletChips() - result.bet);
        // The last reel rolls on longer when the first two have lined up a good win, teasing it from when the second stops
        const teased = IS_REDUCED_MOTION ? [] : tease(result.stops);
        if (!IS_REDUCED_MOTION) sizeCanvas(); // for the sparks as the reels land
        startRollSound();
        await Promise.all(result.stops.map((to, reel) => {
            const isTeased = reel === 2 && teased.length > 0;
            return rollReel(reel, to, IS_REDUCED_MOTION ? 0 : REEL_MS[reel] + (isTeased ? TEASE_MS : 0),
                REEL_TURNS[reel] + (isTeased ? TEASE_TURNS : 0), (stopped) => {
                    landReel(stopped);
                    if (stopped === 1 && teased.length) startTease(teased);
                });
        }));
        stopRollSound();
        stopTease();
        if (IS_REDUCED_MOTION) sound('stop', 1); // nothing rolled, so no reel landed with its own thud
        stops = result.stops;
        describeWindow(stops);

        setChips(answer.chips);
        root.classList.remove('is-spinning');
        showLines(result.lines);
        if (result.payout > 0) {
            const times = result.payout / result.bet;
            const lineCount = result.lines.length;
            resultText.textContent = (lineCount === 1 ? '1 winning line' : lineCount + ' winning lines')
                + (result.net < 0 ? ', but less than the bet back.' : result.net === 0 ? ': the bet back.' : '!');
            winText.hidden = false;
            winText.classList.toggle('is-big', times >= BIG_WIN);
            root.classList.toggle('is-winning', result.net > 0);
            celebrate(result);
        } else {
            // A tease that came to nothing shakes the result; any other loss just dims the reels for a moment
            resultText.textContent = teased.length ? 'So close! No win this time.' : 'No win this time.';
            sound(teased.length ? 'nearMiss' : 'lose');
            if (!IS_REDUCED_MOTION) playClass(root, teased.length ? 'is-near-miss' : 'is-losing', 900);
        }
        addHistory(result);

        isSpinning = false;
        draw();
    }

    // ----- Win effects. How big a win is decides how much goes off (winTier()):
    //   small    the bet back or less: the winning symbols and lines light up and throw off sparkles
    //   win      more than the bet back: coins burst out of the winning symbols, the win counts up, "+chips" floats up
    //   big      BIG_WIN times the bet: a "Big win!" banner over the machine, the machine shakes, coins rain down the page
    //            and fountain out of the Spin button, and the screen's edges glow gold
    //   mega     MEGA_WIN times the bet: "Mega win!", more coin rain, confetti, diamonds falling, fireworks and a flash
    //   jackpot  three 7s on a line: all of that, longer, with 7s falling instead and rainbow lights
    // Whatever the size, what each winning line paid is shown at its end (showLinePays()).
    // The tier goes on the page as data-sl-tier, which slots.css lights the machine by. Coins, confetti, sparkles and
    // fireworks are particles drawn on one canvas over the whole page, and only while there are any to draw. With
    // reduced motion it's only the lights and the banner, with nothing moving. The next spin puts it all away.
    const machine = find('.sl-machine');
    const windowBox = find('.sl-window');
    const banner = find('[data-sl-banner]');
    const bannerTitle = find('[data-sl-banner-title]');
    const bannerAmount = find('[data-sl-banner-amount]');
    const flash = find('[data-sl-flash]');
    const canvas = find('[data-sl-fx]');
    const context = canvas.getContext('2d');
    const BANNERS = { big: ['Big win!', 3500], mega: ['Mega win!', 5000], jackpot: ['Jackpot!', 6500] }; // [text, ms shown]
    const COUNT_MS = { small: 0, win: 900, big: 2000, mega: 3000, jackpot: 3500 }; // how long the win counts up
    const CONFETTI = ['#e8b931', '#ff4f6d', '#4fc3f7', '#81e36b', '#ff7ab8', '#b388ff', '#ffffff'];
    const MAX_PARTICLES = 700; // so a phone doesn't choke on a jackpot
    let particles = []; // { kind: coin|confetti|spark|ember|glyph, x, y, vx, vy (px, px a second), rotation, spin, size, age, life (s), ... }
    let frame = 0; // the animation frame drawing them, 0 when there are none
    let lastTime = 0;
    let canvasWidth = 0;
    let canvasHeight = 0;
    let counting = 0; // the count-up's animation frame
    let fxTimers = []; // the effects' timeouts and intervals, cleared by the next spin (they share one list of ids)

    const randomBetween = (low, high) => low + Math.random() * (high - low);
    const pick = (list) => list[Math.floor(Math.random() * list.length)];

    function winTier(result) {
        const times = result.payout / result.bet;
        const isSevens = result.lines.some((line) => LINES[line.line].every((row, reel) => result.window[row][reel] === 'seven'));
        if (isSevens) return 'jackpot';
        if (times >= MEGA_WIN) return 'mega';
        if (times >= BIG_WIN) return 'big';
        return result.net > 0 ? 'win' : 'small';
    }

    function sizeCanvas() {
        const ratio = window.devicePixelRatio || 1;
        canvasWidth = canvas.clientWidth;
        canvasHeight = canvas.clientHeight;
        canvas.width = Math.round(canvasWidth * ratio);
        canvas.height = Math.round(canvasHeight * ratio);
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    function addParticle(particle) {
        if (particles.length >= MAX_PARTICLES) return;
        particles.push({ rotation: Math.random() * Math.PI * 2, spin: 0, gravity: 900, drag: 0, age: 0, ...particle });
        if (!frame) {
            lastTime = performance.now();
            frame = requestAnimationFrame(tick);
        }
    }

    // Moves every particle on by the time since the last frame, draws the ones still going, and stops when they're gone
    function tick(now) {
        const seconds = Math.min(0.05, Math.max(0, now - lastTime) / 1000); // no big jump after the tab was hidden
        lastTime = now;
        context.clearRect(0, 0, canvasWidth, canvasHeight);
        particles = particles.filter((particle) => {
            particle.age += seconds;
            if (particle.age >= particle.life || particle.y > canvasHeight + 40) return false;
            const slow = Math.max(0, 1 - particle.drag * seconds);
            particle.vx *= slow;
            particle.vy = particle.vy * slow + particle.gravity * seconds;
            particle.x += particle.vx * seconds;
            particle.y += particle.vy * seconds;
            particle.rotation += particle.spin * seconds;
            drawParticle(particle, Math.min(1, (particle.life - particle.age) * 3)); // fades out in its last third of a second
            return true;
        });
        frame = particles.length ? requestAnimationFrame(tick) : 0;
    }

    function ellipse(x, y, width, height) {
        context.beginPath();
        context.ellipse(x, y, width, height, 0, 0, Math.PI * 2);
        context.fill();
    }

    function drawParticle(particle, alpha) {
        const size = particle.size;
        context.save();
        context.globalAlpha = alpha;
        context.translate(particle.x, particle.y);
        if (particle.kind === 'coin') {
            // A gold coin flipping over: as wide as the cosine of how far round it is, and a shade darker on its back
            const turn = Math.cos(particle.rotation);
            const width = Math.max(1, size * Math.abs(turn));
            context.fillStyle = '#9a6b06';
            ellipse(0, 0, width, size);
            context.fillStyle = turn < 0 ? '#e0a91f' : '#ffd23f';
            ellipse(0, 0, width * 0.8, size * 0.8);
            context.fillStyle = 'rgba(255, 255, 255, 0.7)';
            ellipse(-width * 0.25, -size * 0.3, width * 0.2, size * 0.18);
        } else if (particle.kind === 'confetti') {
            // A strip of paper turning in the air
            context.rotate(particle.rotation);
            context.scale(1, Math.cos(particle.age * particle.flip));
            context.fillStyle = particle.color;
            context.fillRect(-size / 2, -size / 4, size, size / 2);
        } else if (particle.kind === 'spark') {
            // A four-pointed twinkle
            const waist = size * 0.25;
            context.globalCompositeOperation = 'lighter';
            context.rotate(particle.rotation);
            context.fillStyle = particle.color;
            context.beginPath();
            context.moveTo(0, -size);
            context.lineTo(waist, -waist);
            context.lineTo(size, 0);
            context.lineTo(waist, waist);
            context.lineTo(0, size);
            context.lineTo(-waist, waist);
            context.lineTo(-size, 0);
            context.lineTo(-waist, -waist);
            context.closePath();
            context.fill();
        } else if (particle.kind === 'glyph') {
            // A 7 (red with a gold edge, as on the reels) or an emoji, rocking from side to side as it falls
            const isSeven = particle.text === '7';
            context.rotate(Math.sin(particle.rotation) * 0.5);
            context.font = isSeven ? 'italic 900 ' + size + 'px Georgia, serif' : size + 'px sans-serif';
            context.textAlign = 'center';
            context.textBaseline = 'middle';
            if (isSeven) {
                context.lineWidth = size / 7;
                context.lineJoin = 'round';
                context.strokeStyle = '#8a6a10';
                context.strokeText(particle.text, 0, 0);
                context.fillStyle = '#d0142c';
            }
            context.fillText(particle.text, 0, 0);
        } else {
            // A firework's ember
            context.globalCompositeOperation = 'lighter';
            context.fillStyle = particle.color;
            ellipse(0, 0, size, size);
        }
        context.restore();
    }

    // Twinkles all over $element
    function sparkles(element, count) {
        const box = element.getBoundingClientRect();
        for (let i = 0; i < count; i++) {
            addParticle({
                kind: 'spark', x: box.left + Math.random() * box.width, y: box.top + Math.random() * box.height,
                vx: randomBetween(-60, 60), vy: randomBetween(-120, -20), gravity: 60, drag: 1,
                size: randomBetween(3, 9), spin: randomBetween(-3, 3), life: randomBetween(0.5, 1.2),
                color: Math.random() < 0.5 ? '#fff6c2' : '#e8b931',
            });
        }
    }

    // Coins thrown up and out of the middle of $element, $power times as hard as normal
    function coinBurst(element, count, power) {
        const box = element.getBoundingClientRect();
        for (let i = 0; i < count; i++) {
            const angle = randomBetween(-Math.PI * 0.85, -Math.PI * 0.15);
            const speed = randomBetween(250, 550) * power;
            addParticle({
                kind: 'coin', x: box.left + box.width / 2, y: box.top + box.height / 2,
                vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed,
                size: randomBetween(8, 14), spin: randomBetween(6, 14), life: randomBetween(1.4, 2.4),
            });
        }
    }

    // Coins, confetti or a glyph ($text: a 7 or an emoji) falling from the top of the page for $ms, about $perSecond of
    // them a second
    function rain(kind, ms, perSecond, text = '') {
        const end = performance.now() + ms;
        const timer = setInterval(() => {
            if (performance.now() > end) {
                clearInterval(timer);
                return;
            }
            for (let left = perSecond / 20; left > 0; left--) {
                if (left < 1 && Math.random() > left) break;
                const x = Math.random() * canvasWidth;
                if (kind === 'coin') {
                    addParticle({ kind, x, y: -20, vx: randomBetween(-40, 40), vy: randomBetween(50, 250), gravity: 700,
                        size: randomBetween(8, 14), spin: randomBetween(6, 14), life: 4 });
                } else if (kind === 'glyph') {
                    addParticle({ kind, text, x, y: -40, vx: randomBetween(-30, 30), vy: randomBetween(40, 160), gravity: 350,
                        size: randomBetween(22, 40), spin: randomBetween(2, 5), life: 5 });
                } else {
                    addParticle({ kind, x, y: -20, vx: randomBetween(-80, 80), vy: randomBetween(40, 160), gravity: 250,
                        drag: 1.2, size: randomBetween(8, 13), spin: randomBetween(-6, 6), flip: randomBetween(4, 10),
                        color: pick(CONFETTI), life: 6 });
                }
            }
        }, 50);
        fxTimers.push(timer);
    }

    // $count fireworks going off over the top half of the page, spread over $ms
    function fireworks(count, ms) {
        for (let i = 0; i < count; i++) {
            fxTimers.push(setTimeout(() => {
                const x = randomBetween(0.15, 0.85) * canvasWidth;
                const y = randomBetween(0.12, 0.45) * canvasHeight;
                const color = pick(CONFETTI);
                sound('boom');
                for (let ember = 0; ember < 50; ember++) {
                    const angle = Math.random() * Math.PI * 2;
                    const speed = randomBetween(120, 360);
                    addParticle({ kind: 'ember', x, y, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed,
                        gravity: 160, drag: 1.4, size: randomBetween(1.8, 3.5), life: randomBetween(0.9, 1.6),
                        color: Math.random() < 0.25 ? '#ffffff' : color });
                }
            }, (i / count) * ms + Math.random() * 300));
        }
    }

    // Counts the win shown up from 0 to $to over $ms, fast at first and slowing down at the end
    function countUp(to, ms, show) {
        cancelAnimationFrame(counting);
        counting = 0;
        if (!ms) {
            show(to);
            return;
        }
        const start = performance.now();
        const step = (now) => {
            const done = Math.min(1, Math.max(0, now - start) / ms);
            show(Math.round(to * (1 - (1 - done) ** 3)));
            counting = done < 1 ? requestAnimationFrame(step) : 0;
        };
        step(start);
    }

    // Plays $element's CSS animation for $className (taking it off first, so it plays again), and takes it off after $ms
    function playClass(element, className, ms) {
        element.classList.remove(className);
        element.getBoundingClientRect();
        element.classList.add(className);
        fxTimers.push(setTimeout(() => element.classList.remove(className), ms));
    }

    // "+chips" rising out of the reels
    function floatText(text) {
        const float = document.createElement('span');
        float.className = 'sl-float';
        float.setAttribute('aria-hidden', 'true');
        float.textContent = text;
        float.addEventListener('animationend', () => float.remove());
        windowBox.append(float);
    }

    function celebrate(result) {
        const tier = winTier(result);
        root.dataset.slTier = tier;
        countUp(result.payout, IS_REDUCED_MOTION ? 0 : COUNT_MS[tier], (amount) => {
            winText.textContent = 'Won ' + formatChips(amount);
            bannerAmount.textContent = formatChips(amount);
        });
        if (BANNERS[tier]) {
            bannerTitle.textContent = BANNERS[tier][0];
            banner.hidden = false;
            fxTimers.push(setTimeout(() => { banner.hidden = true; }, BANNERS[tier][1]));
        }
        showLinePays(result);
        sound('win', tier);
        // The counter ticking while the win counts up
        if (COUNT_MS[tier] && !IS_REDUCED_MOTION) {
            const ticker = setInterval(() => sound('count'), 70);
            fxTimers.push(ticker, setTimeout(() => clearInterval(ticker), COUNT_MS[tier] * 0.8));
        }
        if (IS_REDUCED_MOTION) return;

        sizeCanvas(); // the window may have changed size since the last win
        const cells = [...root.querySelectorAll('.sl-symbol.is-win')];
        cells.forEach((cell) => sparkles(cell, tier === 'small' ? 6 : 12));
        if (tier === 'small') return;
        cells.forEach((cell) => coinBurst(cell, tier === 'win' ? 6 : 14, tier === 'win' ? 0.8 : 1.2));
        if (tier === 'win') {
            floatText('+' + formatChips(result.net));
            return;
        }
        playClass(machine, 'is-shaking', 700);
        rain('coin', tier === 'big' ? 2500 : 4500, tier === 'big' ? 40 : 80);
        coinBurst(spinButton, 24, 1.5);
        if (tier === 'big') return;
        playClass(flash, 'is-on', 1000);
        rain('confetti', tier === 'jackpot' ? 6000 : 4500, 60);
        rain('glyph', tier === 'jackpot' ? 6000 : 4500, tier === 'jackpot' ? 10 : 6, tier === 'jackpot' ? '7' : '💎');
        fireworks(tier === 'jackpot' ? 12 : 5, tier === 'jackpot' ? 5000 : 3000);
        // A second shake and flash on a jackpot, as the fireworks get going
        if (tier === 'jackpot') {
            fxTimers.push(setTimeout(() => {
                playClass(machine, 'is-shaking', 700);
                playClass(flash, 'is-on', 1000);
            }, 1200));
        }
    }

    // Puts away the last win's effects when the next spin starts. Particles still in the air just fall off the page
    function clearEffects() {
        fxTimers.forEach((timer) => clearTimeout(timer));
        fxTimers = [];
        cancelAnimationFrame(counting);
        counting = 0;
        delete root.dataset.slTier;
        banner.hidden = true;
        lineBadge.hidden = true;
        root.classList.remove('is-line-focus', 'is-near-miss', 'is-losing');
        [...lineDrawings, ...lineComets].forEach((path) => path.classList.remove('is-focus'));
        machine.classList.remove('is-shaking', 'is-thudding');
        flash.classList.remove('is-on');
        windowBox.querySelectorAll('.sl-float').forEach((float) => float.remove());
    }

    // ----- Spin effects: the lever, the reels landing, the tease, and what each winning line paid
    const lever = find('[data-sl-lever]');
    const lineBadge = find('[data-sl-line-badge]');
    const LINE_CYCLE_MS = 1300; // how long each winning line is shown on its own, when there's more than one
    let teaseTimer = 0; // the sparkles round the teased reel

    // The lines the first two reels put two of the same good symbol on, when they stop at $at: the ones the last reel
    // could still make a big win of
    function tease(at) {
        const shown = windowAt(at);
        return LINES.map((rows, line) => ({ line, first: shown[rows[0]][0], second: shown[rows[1]][1] }))
            .filter(({ first, second }) => first === second && TEASE_SYMBOLS.includes(first))
            .map(({ line }) => line);
    }

    function pullLever() {
        sound('lever');
        if (IS_REDUCED_MOTION) return;
        playClass(lever, 'is-pulled', 800);
        playClass(spinButton, 'is-pushed', 600);
    }

    // A reel has stopped: it bumps and flashes, sparks fly off its top and bottom edges, the machine gives a little thud,
    // and a 7 that landed in view twinkles
    function landReel(reel) {
        const reelBox = strips[reel].parentElement;
        playClass(reelBox, 'is-landed', 500);
        playClass(machine, 'is-thudding', 250);
        sound('stop', reel);
        const box = reelBox.getBoundingClientRect();
        for (let i = 0; i < 16; i++) {
            const isTop = i % 2 === 0;
            addParticle({
                kind: 'spark', x: box.left + Math.random() * box.width, y: isTop ? box.top : box.bottom,
                vx: randomBetween(-90, 90), vy: isTop ? randomBetween(-160, -40) : randomBetween(40, 140), gravity: 300,
                drag: 1.5, size: randomBetween(2, 5), spin: randomBetween(-4, 4), life: randomBetween(0.3, 0.6),
                color: Math.random() < 0.5 ? '#fff6c2' : '#ffd23f',
            });
        }
        strips[reel].querySelectorAll('[data-sl-symbol="seven"]').forEach((cell) => sparkles(cell, 8));
    }

    // The first two reels have lined up $lines: the last reel glows and throws off sparkles while it rolls on, and the
    // lines and the two symbols on each flicker
    function startTease(lines) {
        const reelBox = strips[2].parentElement;
        root.classList.add('is-teasing');
        reelBox.classList.add('is-teasing');
        lines.forEach((line) => {
            lineDrawings[line].classList.add('is-tease');
            LINES[line].slice(0, 2).forEach((row, reel) => strips[reel].children[row]?.classList.add('is-tease'));
        });
        clearInterval(teaseTimer);
        teaseTimer = setInterval(() => sparkles(reelBox, 3), 120);
        startTeaseSound((REEL_MS[2] + TEASE_MS - REEL_MS[1]) / 1000); // until the last reel stops
    }

    function stopTease() {
        clearInterval(teaseTimer);
        stopTeaseSound();
        root.classList.remove('is-teasing');
        strips[2].parentElement.classList.remove('is-teasing');
        lineDrawings.forEach((path) => path.classList.remove('is-tease'));
        root.querySelectorAll('.sl-symbol.is-tease').forEach((cell) => cell.classList.remove('is-tease'));
    }

    // What each winning line paid, in a badge at its right end in the line's colour. With one line, that one; with more,
    // after a moment of them all lit, each in turn, with the others dimmed (is-line-focus), until the next spin
    function showLinePays(result) {
        const lines = result.lines;
        let next = 0;
        const focus = () => {
            const line = lines[next++ % lines.length];
            root.classList.toggle('is-line-focus', lines.length > 1);
            [...lineDrawings, ...lineComets].forEach((path) => {
                path.classList.toggle('is-focus', Number(path.dataset.slLine ?? path.dataset.slLineComet) === line.line);
            });
            root.querySelectorAll('.sl-symbol.is-focus').forEach((cell) => cell.classList.remove('is-focus'));
            LINES[line.line].forEach((row, reel) => strips[reel].children[row]?.classList.add('is-focus'));
            lineBadge.textContent = '+' + formatChips(line.payout);
            lineBadge.className = 'sl-line-badge sl-line-badge-' + line.line + ' is-row-' + LINES[line.line][2];
            lineBadge.hidden = false;
            playClass(lineBadge, 'is-popping', 500);
            sound('line', line.line);
        };
        fxTimers.push(setTimeout(() => {
            focus();
            if (lines.length > 1) fxTimers.push(setInterval(focus, LINE_CYCLE_MS));
        }, lines.length > 1 ? 1500 : 700));
    }

    // ----- Sounds, made here rather than loaded (like the games' pings): a clunk for the lever, the reels ticking round
    // and thudding as they stop, a rising whine while the last reel is teased, a sad slide for a near miss, and chimes,
    // a fanfare, coins and fireworks for a win, more the bigger it is. Browsers only let a page play sound once it's been
    // clicked or typed in, so the first click or key wakes it up. Everything goes through one volume (master), so muting
    // cuts off what's playing too. The Sound button turns it on and off, remembered in this browser (SOUND_KEY).
    const SOUND_KEY = 'hbHubSlotsSound';
    const SOUND_VOLUME = 0.5;
    const soundButton = document.querySelector('[data-sl-sound]');
    let isSoundOn = true;
    try {
        isSoundOn = localStorage.getItem(SOUND_KEY) !== 'off';
    } catch {
        // no storage (a private window): the sound is just on
    }
    let audio = null;
    let master = null;
    let noise = null; // a second of white noise, for clicks, clunks and booms
    let rollTicker = 0; // the reels' ticking while they roll
    let teaseWhine = null; // the oscillator rising while the last reel is teased

    function wakeAudio() {
        try {
            if (audio === null) {
                audio = new AudioContext();
                master = audio.createGain();
                master.gain.value = isSoundOn ? SOUND_VOLUME : 0;
                master.connect(audio.destination);
                noise = audio.createBuffer(1, audio.sampleRate, audio.sampleRate);
                const samples = noise.getChannelData(0);
                for (let i = 0; i < samples.length; i++) samples[i] = Math.random() * 2 - 1;
            }
            if (audio.state === 'suspended') audio.resume();
        } catch {
            audio = null; // no Web Audio: no sounds
        }
    }

    // The time to play a sound from, or -1 when there's no sound
    function soundNow() {
        if (!isSoundOn) return -1;
        wakeAudio();
        return audio === null ? -1 : audio.currentTime;
    }

    // A note of $frequency Hz from $at seconds, up to $volume at once and fading out over $length, gliding to $to Hz
    function tone(at, frequency, length, volume, type = 'sine', to = 0) {
        const oscillator = audio.createOscillator();
        const gain = audio.createGain();
        oscillator.type = type;
        oscillator.frequency.setValueAtTime(frequency, at);
        if (to) oscillator.frequency.exponentialRampToValueAtTime(to, at + length);
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(volume, at + 0.008);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        oscillator.connect(gain).connect(master);
        oscillator.start(at);
        oscillator.stop(at + length + 0.02);
    }

    // A burst of noise through a $filter ('lowpass', 'bandpass', 'highpass') at $frequency Hz: clicks, clunks and booms
    function hiss(at, length, volume, filter, frequency) {
        const source = audio.createBufferSource();
        const shape = audio.createBiquadFilter();
        const gain = audio.createGain();
        source.buffer = noise;
        shape.type = filter;
        shape.frequency.value = frequency;
        gain.gain.setValueAtTime(volume, at);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        source.connect(shape).connect(gain).connect(master);
        source.start(at, Math.random() * 0.5, length + 0.02);
    }

    // A coin landing: two bright notes a fifth apart, a bit different every time
    function clink(at, volume) {
        const frequency = randomBetween(2400, 3600);
        tone(at, frequency, 0.15, volume, 'triangle');
        tone(at + 0.01, frequency * 1.5, 0.1, volume * 0.5, 'sine');
    }

    // Notes one after another from $at, $gap seconds apart, each $length long
    function melody(at, notes, gap, length, volume, type = 'triangle') {
        notes.forEach((frequency, i) => tone(at + i * gap, frequency, length, volume, type));
    }

    const C5 = 523.25, E5 = 659.25, G5 = 783.99, A5 = 880, C6 = 1046.5, E6 = 1318.5, G6 = 1568, C7 = 2093;

    function sound(kind, detail = 0) {
        const now = soundNow();
        if (now < 0) return;
        if (kind === 'chip') {
            // A chip clacking on the felt
            hiss(now, 0.04, 0.35, 'bandpass', 3200);
            tone(now, 1800, 0.05, 0.08, 'triangle');
        } else if (kind === 'lever') {
            // The lever's clunk, then its ratchet clicking back up
            hiss(now, 0.12, 0.6, 'lowpass', 700);
            tone(now, 160, 0.15, 0.35, 'square', 55);
            for (let i = 0; i < 5; i++) hiss(now + 0.18 + i * 0.07, 0.025, 0.25, 'highpass', 2500);
        } else if (kind === 'tick') {
            // A symbol going past on a rolling reel, higher while only the teased one is left ($detail = 1)
            hiss(now, 0.02, 0.12, 'bandpass', detail ? 4200 : 2600);
        } else if (kind === 'stop') {
            // A reel thudding to a stop: lower for each one further right
            tone(now, 170 - detail * 25, 0.2, 0.5, 'sine', 45);
            hiss(now, 0.06, 0.4, 'lowpass', 900);
            hiss(now + 0.01, 0.03, 0.15, 'highpass', 3000);
        } else if (kind === 'lose') {
            tone(now, 220, 0.18, 0.12, 'triangle', 180);
        } else if (kind === 'nearMiss') {
            // "Wah wah": two notes sliding down
            tone(now, 392, 0.35, 0.25, 'sawtooth', 330);
            tone(now + 0.38, 330, 0.6, 0.25, 'sawtooth', 220);
        } else if (kind === 'line') {
            // A winning line's turn: a ding, higher for each line ($detail is its number)
            tone(now, C6 * 2 ** (detail / 6), 0.35, 0.18, 'sine');
        } else if (kind === 'count') {
            hiss(now, 0.015, 0.1, 'highpass', 5000);
            tone(now, 2600, 0.03, 0.04, 'square');
        } else if (kind === 'boom') {
            // A firework going off: a thump, then crackling
            hiss(now, 0.6, 0.5, 'lowpass', 300);
            tone(now, 90, 0.4, 0.3, 'sine', 40);
            for (let i = 0; i < 8; i++) hiss(now + 0.15 + Math.random() * 0.5, 0.02, 0.12, 'highpass', 4000);
        } else if (kind === 'win') {
            winSound(now, detail);
        }
    }

    // A win's fanfare, by its tier ($tier, see winTier())
    function winSound(now, tier) {
        if (tier === 'small') {
            melody(now, [E6, G6], 0.09, 0.3, 0.18, 'sine');
            return;
        }
        if (tier === 'win') {
            melody(now, [C6, E6, G6, C7], 0.08, 0.35, 0.18);
            for (let i = 0; i < 6; i++) clink(now + 0.15 + i * 0.07, 0.08);
            return;
        }
        // A fanfare: a run up, then a held chord, with coins pouring out under it
        const run = tier === 'big' ? [C5, E5, G5, C6] : [C5, E5, G5, C6, E6, G6];
        melody(now, run, 0.1, 0.3, 0.2, 'square');
        const chordAt = now + run.length * 0.1;
        const hold = tier === 'jackpot' ? 1.8 : tier === 'mega' ? 1.3 : 0.9;
        [C6, E6, G6].forEach((frequency) => tone(chordAt, frequency, hold, 0.12, 'square'));
        tone(chordAt, C5, hold, 0.15, 'triangle');
        if (tier === 'jackpot') {
            // and the run again an octave up, ringing like bells
            melody(chordAt + 0.4, [C6, E6, G6, C7, G6, C7], 0.12, 0.6, 0.14, 'sine');
        }
        const coins = tier === 'big' ? 30 : tier === 'mega' ? 55 : 80;
        const pour = tier === 'big' ? 2.5 : tier === 'mega' ? 4 : 5.5;
        for (let i = 0; i < coins; i++) clink(now + 0.2 + Math.random() * pour, randomBetween(0.03, 0.08));
    }

    // The ticking of the reels going round, until stopRollSound(): faster while more are rolling, and higher when only
    // the teased last reel is left
    function startRollSound() {
        clearInterval(rollTicker);
        const reels = strips.map((strip) => strip.parentElement);
        rollTicker = setInterval(() => {
            const rolling = reels.filter((reel) => reel.classList.contains('is-rolling'));
            if (rolling.length === 0) return;
            const isTeased = rolling.length === 1 && rolling[0].classList.contains('is-teasing');
            sound('tick', isTeased ? 1 : 0);
        }, 65);
    }

    function stopRollSound() {
        clearInterval(rollTicker);
        rollTicker = 0;
    }

    // While the last reel is teased: a whine rising for the $seconds it has left, with a tremble in it
    function startTeaseSound(seconds) {
        const now = soundNow();
        if (now < 0) return;
        stopTeaseSound();
        const oscillator = audio.createOscillator();
        const tremble = audio.createOscillator();
        const trembleDepth = audio.createGain();
        const gain = audio.createGain();
        oscillator.type = 'sawtooth';
        oscillator.frequency.setValueAtTime(220, now);
        oscillator.frequency.exponentialRampToValueAtTime(880, now + seconds);
        tremble.frequency.setValueAtTime(6, now);
        tremble.frequency.linearRampToValueAtTime(14, now + seconds);
        trembleDepth.gain.value = 12;
        tremble.connect(trembleDepth).connect(oscillator.frequency);
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(0.06, now + seconds);
        oscillator.connect(gain).connect(master);
        oscillator.start(now);
        tremble.start(now);
        oscillator.stop(now + seconds + 0.1);
        tremble.stop(now + seconds + 0.1);
        teaseWhine = { oscillator, tremble, gain };
    }

    function stopTeaseSound() {
        if (teaseWhine === null) return;
        const { oscillator, tremble, gain } = teaseWhine;
        teaseWhine = null;
        try {
            const now = audio.currentTime;
            gain.gain.cancelScheduledValues(now);
            gain.gain.setTargetAtTime(0.0001, now, 0.02);
            oscillator.stop(now + 0.1);
            tremble.stop(now + 0.1);
        } catch {
            // it had already stopped
        }
    }

    // aria-pressed also picks the icon (slots.css)
    function renderSound() {
        soundButton.setAttribute('aria-pressed', String(isSoundOn));
        soundButton.title = isSoundOn ? 'Sound on' : 'Sound off';
    }

    soundButton.addEventListener('click', () => {
        isSoundOn = !isSoundOn;
        try {
            localStorage.setItem(SOUND_KEY, isSoundOn ? 'on' : 'off');
        } catch {
            // only kept until the page is closed
        }
        renderSound();
        wakeAudio();
        if (master !== null) {
            master.gain.cancelScheduledValues(audio.currentTime);
            master.gain.setTargetAtTime(isSoundOn ? SOUND_VOLUME : 0, audio.currentTime, 0.02);
        }
        sound('chip'); // so they hear it's on
    });
    renderSound();
    document.addEventListener('pointerdown', wakeAudio, { once: true });
    document.addEventListener('keydown', wakeAudio, { once: true });

    window.addEventListener('resize', () => {
        if (frame) sizeCanvas();
    });

    // A chip flips over when it's picked, and the bet bounces when it changes
    const bumpBet = () => {
        sound('chip');
        if (!IS_REDUCED_MOTION) playClass(betText, 'is-bumped', 350);
    };
    const flipChip = (button) => {
        if (!IS_REDUCED_MOTION) playClass(button, 'is-flipping', 500);
    };
    chipButtons.forEach((button) => button.addEventListener('click', () => {
        flipChip(button);
        pickBet(Number(button.dataset.slChip));
        bumpBet();
    }));
    allInButton.addEventListener('click', () => {
        isAllIn = true;
        showMessage('');
        flipChip(allInButton);
        draw();
        bumpBet();
    });
    lessButton.addEventListener('click', () => {
        pickBet(nextBet(-1));
        bumpBet();
    });
    moreButton.addEventListener('click', () => {
        pickBet(nextBet(1));
        bumpBet();
    });
    spinButton.addEventListener('click', spin);
    lever.addEventListener('click', spin);

    // Space spins, also when a button on the page has focus (it would press that one otherwise), but not in text boxes
    document.addEventListener('keydown', (event) => {
        if (event.key !== ' ' || event.ctrlKey || event.metaKey || event.altKey) return;
        if (event.target.closest?.('input, textarea, select, [contenteditable], summary')) return;
        event.preventDefault(); // no scrolling the page
        if (!event.repeat) spin();
    });
    document.addEventListener('keyup', (event) => {
        if (event.key === ' ' && !event.target.closest?.('input, textarea, select, [contenteditable], summary')) event.preventDefault();
    });

    // The chips changed somewhere else (daily chips collected, another tab): the bet may not fit anymore, or All in may
    // come or go. After casino.js has put the new count in the top bar, which walletChips() reads
    document.addEventListener('casino:wallet', () => setTimeout(draw));
    document.addEventListener('nav:update', () => setTimeout(draw));

    draw();
})();
