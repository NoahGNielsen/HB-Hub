// Casino chips (assets/php/casino/wallet.php), on every casino page: keeps the chip count in the top bar up to date,
// and on the casino home the big chip count too, and the daily chips panel with its countdown and Collect button, its
// streak, a note when another of the person's accounts already had today's (so this one gets less, wallet.php), and
// the 30-day calendar under it (which days are ticked off, which one is next, and the progress bar over it). On every
// casino page it also pops up the 3-hour bonus that follows the daily chips (navPanel.php's box in the bottom-right
// corner) as soon as it can be collected, counting down to that without showing it, and collects it from there.
// Its x hides it on this page until the next bonus.
// The chips come along with navUpdates.js's request (every 15 seconds while the page is in view: wallet=1 on nav:poll,
// the answer's "wallet" on nav:update). /casino/chips is only asked when the daily chips' or the 3-hour bonus's
// countdown runs out and to collect them. A game that gets a new balance in its own answers passes it on straight away with
//     document.dispatchEvent(new CustomEvent('casino:wallet', { detail: { chips: 1234 } }));
// A game that's showing something whose result the chips would give away (Roulette's wheel turning) holds them back
// with casino:hold { hold: true }, and the last count that came in meanwhile shows on { hold: false }.
(() => {
    const counts = document.querySelectorAll('[data-casino-chips]');
    const daily = document.querySelector('[data-casino-daily]');
    const claimButton = daily?.querySelector('[data-casino-claim]');
    const dailyTimer = daily?.querySelector('[data-casino-daily-timer]');
    const dailyMessage = daily?.querySelector('[data-casino-daily-message]');
    const dailyStreak = daily?.querySelector('[data-casino-streak]');
    const dailyWhen = daily?.querySelector('[data-casino-daily-when]');
    const dailyDay = daily?.querySelector('[data-casino-daily-day]');
    const dailyAmount = daily?.querySelector('[data-casino-daily-amount]');
    const dailyShared = daily?.querySelector('[data-casino-daily-shared]');
    const calendarDays = document.querySelectorAll('[data-casino-calendar] [data-day]');
    const progress = document.querySelector('[data-casino-progress]');
    const progressText = document.querySelector('[data-casino-progress-text]');
    const bonus = document.querySelector('[data-casino-bonus]');
    const bonusButton = bonus?.querySelector('[data-casino-bonus-claim]');
    const bonusClose = bonus?.querySelector('[data-casino-bonus-close]');
    const bonusAmount = bonus?.querySelector('[data-casino-bonus-amount]');
    const bonusMessage = bonus?.querySelector('[data-casino-bonus-message]');
    const BONUS_COLLECTED_MS = 4000; // how long "You collected ..." stays before the pop-up goes

    let chips = counts.length > 0 ? Number(counts[0].dataset.casinoChips) : null;
    let nextDailyAt = null; // Date.now() when the daily chips can be collected again, null while they can be
    let countdownTimer = null;
    let isClaiming = false;
    let nextBonusAt = null; // Date.now() when the 3-hour bonus is ready, null while it is or there's none to come
    let bonusTimerId = null;
    let isClaimingBonus = false;
    let isBonusDismissed = false; // its x was clicked, so it stays away until a bonus that isn't ready yet comes in
    let bonusHideTimer = null; // hides it a moment after it was collected
    let isHeld = false; // casino:hold: the count stays as it is for now
    let heldChips = null; // the last count that came in while held

    const formatChips = (amount) => amount.toLocaleString('en-US');

    async function request(options) {
        try {
            const response = await fetch('/casino/chips', { headers: { Accept: 'application/json' }, ...options });
            return response.ok ? await response.json() : null;
        } catch {
            return null; // offline for a moment, or logged out in another tab
        }
    }

    // Flashes green when the chips went up and red when they went down (casino.css)
    function showChips(newChips) {
        if (newChips === chips) return;
        const change = chips === null || newChips > chips ? 'is-up' : 'is-down';
        chips = newChips;
        counts.forEach((count) => {
            count.textContent = formatChips(newChips);
            count.dataset.casinoChips = String(newChips);
            count.classList.remove('is-up', 'is-down');
            void count.offsetWidth; // so the animation starts over when it's already running
            count.classList.add(change);
        });
    }

    function formatCountdown(ms) {
        const seconds = Math.max(0, Math.ceil(ms / 1000));
        const minutes = Math.floor(seconds / 60);
        return Math.floor(minutes / 60) + ':' + String(minutes % 60).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
    }

    function tick() {
        const left = nextDailyAt - Date.now();
        if (left <= 0) {
            // A new casino day: the server says when they're really ready, in case this device's clock is off
            clearInterval(countdownTimer);
            countdownTimer = null;
            refresh();
            return;
        }
        dailyTimer.textContent = 'Next daily chips in ' + formatCountdown(left);
    }

    // The same as casinoStreakText() in casino/index.php
    const streakText = (collected) => collected === 0 ? 'No streak yet'
        : collected === 1 ? '1 day in a row' : collected + ' days in a row';

    // Ticks off the days collected so far and marks the next one, like casino/index.php does when it makes the page.
    // A day that's ticked off now but wasn't before bounces once.
    function showCalendar(state) {
        dailyStreak.textContent = streakText(state.collected);
        dailyWhen.textContent = state.ready ? 'Today' : 'Tomorrow';
        dailyDay.textContent = String(state.day);
        dailyAmount.textContent = formatChips(state.amount);
        // Another of the person's accounts already had today's, so this one only gets part of them (wallet.php)
        if (dailyShared) dailyShared.hidden = !(state.ready && state.shared);
        daily.classList.toggle('is-ready', state.ready);
        if (progress) progress.value = state.collected;
        if (progressText) progressText.textContent = String(state.collected);

        calendarDays.forEach((cell) => {
            const day = Number(cell.dataset.day);
            const isCollected = day <= state.collected;
            const isNext = !isCollected && day === state.day;
            if (isCollected && !cell.classList.contains('is-collected')) {
                cell.classList.add('is-just-collected');
                cell.addEventListener('animationend', () => cell.classList.remove('is-just-collected'), { once: true });
            }
            cell.classList.toggle('is-collected', isCollected);
            cell.classList.toggle('is-next', isNext);
            cell.classList.toggle('is-ready', isNext && state.ready);
        });
    }

    // The server sends how many seconds are left instead of a time, so the countdown is right even when this
    // device's clock isn't
    function showDaily(state) {
        if (!daily) return;
        clearInterval(countdownTimer);
        countdownTimer = null;
        claimButton.disabled = !state.ready || isClaiming;
        showCalendar(state);

        if (state.ready) {
            nextDailyAt = null;
            dailyTimer.textContent = 'Your daily chips are ready.';
        } else {
            nextDailyAt = Date.now() + state.seconds * 1000;
            tick();
            countdownTimer = setInterval(tick, 1000);
        }
    }

    function tickBonus() {
        const left = nextBonusAt - Date.now();
        if (left <= 0) {
            // Ready now, going by this device's clock: the server has the last word
            clearInterval(bonusTimerId);
            bonusTimerId = null;
            refresh();
        }
    }

    function hideBonus() {
        clearTimeout(bonusHideTimer);
        bonusHideTimer = null;
        bonus.hidden = true;
        bonus.classList.remove('is-collected');
        bonusMessage.textContent = '';
        bonusMessage.classList.remove('is-error');
    }

    // The 3-hour bonus pop-up (wallet.php's hbHubCasinoBonusState()): shown while it's ready, and otherwise counting
    // down (once a second, but only checking the time) to when it will be, to ask the server then
    function showBonus(state) {
        if (!bonus) return;
        clearInterval(bonusTimerId);
        bonusTimerId = null;
        nextBonusAt = null;
        if (!state.ready && state.available) {
            nextBonusAt = Date.now() + state.seconds * 1000;
            bonusTimerId = setInterval(tickBonus, 1000);
        }
        if (bonusHideTimer !== null) return; // just collected: the message stays up until it goes by itself

        if (state.ready) {
            bonusAmount.textContent = formatChips(state.amount);
            bonusButton.disabled = isClaimingBonus;
            if (!isBonusDismissed) bonus.hidden = false;
        } else if (!isClaimingBonus) {
            // Not ready (collected in another tab, say): out of sight, and the next one may pop up again
            isBonusDismissed = false;
            hideBonus();
        }
    }

    function show(wallet) {
        if (typeof wallet?.chips === 'number') {
            if (isHeld) {
                heldChips = wallet.chips;
            } else {
                showChips(wallet.chips);
            }
        }
        if (wallet?.daily) showDaily(wallet.daily);
        if (wallet?.bonus) showBonus(wallet.bonus);
    }

    async function refresh() {
        const wallet = await request();
        if (wallet !== null) show(wallet);
    }

    function showMessage(text, isError = false) {
        dailyMessage.textContent = text;
        dailyMessage.classList.toggle('is-error', isError);
    }

    claimButton?.addEventListener('click', async () => {
        isClaiming = true;
        claimButton.disabled = true;
        showMessage('');

        const wallet = await request({ method: 'POST', body: new URLSearchParams({ action: 'claimDaily' }) });
        isClaiming = false;
        if (wallet === null) {
            claimButton.disabled = false;
            showMessage('Could not collect your chips. Please try again.', true);
            return;
        }

        show(wallet);
        showMessage(wallet.claimed
            ? 'You collected ' + formatChips(wallet.claimedAmount) + ' chips! Come back tomorrow for day ' + wallet.daily.day + '.'
            : 'You already collected your chips today.');
    });

    bonusButton?.addEventListener('click', async () => {
        isClaimingBonus = true;
        bonusButton.disabled = true;
        bonusMessage.textContent = '';
        bonusMessage.classList.remove('is-error');

        const wallet = await request({ method: 'POST', body: new URLSearchParams({ action: 'claimBonus' }) });
        isClaimingBonus = false;
        if (wallet === null) {
            bonusButton.disabled = false;
            bonusMessage.textContent = 'Could not collect your bonus. Please try again.';
            bonusMessage.classList.add('is-error');
            return;
        }

        // The message first, then a moment before the pop-up goes (showBonus() leaves it be meanwhile). The bar
        // along its bottom runs out over that same moment (--bonus-pop-linger in navPanel.css).
        bonus.style.setProperty('--bonus-pop-linger', BONUS_COLLECTED_MS + 'ms');
        bonus.classList.add('is-collected');
        bonusMessage.textContent = !wallet.claimed ? 'This bonus was already collected.'
            : 'You collected ' + formatChips(wallet.claimedAmount) + ' chips!'
                + (wallet.bonus.available ? ' The next one is in 3 hours.' : ' That was the last one today.');
        bonusHideTimer = setTimeout(hideBonus, BONUS_COLLECTED_MS);
        show(wallet);
    });

    bonusClose?.addEventListener('click', () => {
        isBonusDismissed = bonusHideTimer === null; // after collecting, it's just closing the message
        hideBonus();
    });

    document.addEventListener('casino:wallet', (event) => show(event.detail));
    document.addEventListener('casino:hold', (event) => {
        isHeld = Boolean(event.detail?.hold);
        if (!isHeld && heldChips !== null) {
            const newChips = heldChips;
            heldChips = null;
            showChips(newChips);
        }
    });
    document.addEventListener('nav:poll', (event) => event.detail.params.set('wallet', '1'));
    document.addEventListener('nav:update', (event) => {
        if (event.detail.wallet) show(event.detail.wallet);
    });

    // The page was made with the daily chips' state, so this only starts the countdown (the calendar already matches)
    if (daily) {
        const seconds = Number(daily.dataset.seconds);
        showDaily({
            ready: seconds === 0,
            seconds,
            amount: Number(daily.dataset.amount),
            day: Number(daily.dataset.day),
            collected: Number(daily.dataset.collected),
            shared: daily.dataset.shared === '1',
        });
    }
    if (bonus) {
        showBonus({
            available: bonus.dataset.available === '1',
            ready: bonus.dataset.ready === '1',
            seconds: Number(bonus.dataset.seconds),
            amount: Number(bonus.dataset.amount),
        });
    }
})();
