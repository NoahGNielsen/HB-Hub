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

    // Lights up the winning lines, their numbers and the symbols on them, or puts them all out (lines = [])
    function showLines(lines) {
        const winning = new Set(lines.map((line) => line.line));
        lineDrawings.forEach((path) => path.classList.toggle('is-win', winning.has(Number(path.dataset.slLine))));
        lineNumbers.forEach((item) => item.classList.toggle('is-win', winning.has(Number(item.dataset.slLineNumber))));
        strips.forEach((strip) => strip.querySelectorAll('.sl-symbol').forEach((cell) => cell.classList.remove('is-win')));
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

    // Rolls one reel from where it stands to $to, and resolves when it's stopped
    function rollReel(reel, to, duration) {
        const strip = strips[reel];
        const from = stops[reel];
        const length = REELS[reel].length;
        // From the symbol above where it stops, down the strip to the one below where it stands now: in view at the
        // end of it now, and the top three in view when it's done
        const between = ((from - to) % length + length) % length;
        const count = between + 3 + (duration ? REEL_TURNS[reel] * length : 0);
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
        const item = document.createElement('li');
        item.className = 'sl-history-item' + (spin.payout > 0 ? ' is-win' : '');
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
        await Promise.all(result.stops.map((to, reel) => rollReel(reel, to, IS_REDUCED_MOTION ? 0 : REEL_MS[reel])));
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
            resultText.textContent = 'No win this time.';
        }
        addHistory(result);

        isSpinning = false;
        draw();
    }

    // ----- Win effects. How big a win is decides how much goes off (winTier()):
    //   small    the bet back or less: the winning symbols and lines light up and throw off sparkles
    //   win      more than the bet back: coins burst out of the winning symbols, the win counts up, "+chips" floats up
    //   big      BIG_WIN times the bet: a "Big win!" banner over the machine, the machine shakes, coins rain down the page
    //   mega     MEGA_WIN times the bet: "Mega win!", more coin rain, confetti, fireworks and a flash
    //   jackpot  three 7s on a line: all of that, longer, with rainbow lights
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
    let particles = []; // { kind: coin|confetti|spark|ember, x, y, vx, vy (px, px a second), rotation, spin, size, age, life (s), ... }
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

    // Coins or confetti falling from the top of the page for $ms, about $perSecond of them a second
    function rain(kind, ms, perSecond) {
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
        if (tier === 'big') return;
        playClass(flash, 'is-on', 1000);
        rain('confetti', tier === 'jackpot' ? 6000 : 4500, 60);
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
        machine.classList.remove('is-shaking');
        flash.classList.remove('is-on');
        windowBox.querySelectorAll('.sl-float').forEach((float) => float.remove());
    }

    window.addEventListener('resize', () => {
        if (frame) sizeCanvas();
    });

    chipButtons.forEach((button) => button.addEventListener('click', () => pickBet(Number(button.dataset.slChip))));
    allInButton.addEventListener('click', () => {
        isAllIn = true;
        showMessage('');
        draw();
    });
    lessButton.addEventListener('click', () => pickBet(nextBet(-1)));
    moreButton.addEventListener('click', () => pickBet(nextBet(1)));
    spinButton.addEventListener('click', spin);

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
