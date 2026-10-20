// Casino chips (assets/php/casino/wallet.php), on every casino page: keeps the chip count in the top bar up to date,
// and on the casino home the big chip count too, and the daily chips card with its countdown and Collect button.
// The chips come along with navUpdates.js's request (every 15 seconds while the page is in view: wallet=1 on nav:poll,
// the answer's "wallet" on nav:update). /gambling/chips is only asked when the daily chips' countdown runs out and to
// collect them. A game that gets a new balance in its own answers passes it on straight away with
//     document.dispatchEvent(new CustomEvent('casino:wallet', { detail: { chips: 1234 } }));
(() => {
    const counts = document.querySelectorAll('[data-casino-chips]');
    const daily = document.querySelector('[data-casino-daily]');
    const claimButton = daily?.querySelector('[data-casino-claim]');
    const dailyTimer = daily?.querySelector('[data-casino-daily-timer]');
    const dailyMessage = daily?.querySelector('[data-casino-daily-message]');

    let chips = counts.length > 0 ? Number(counts[0].dataset.casinoChips) : null;
    let nextDailyAt = null; // Date.now() when the daily chips can be collected again, null while they can be
    let countdownTimer = null;
    let isClaiming = false;

    const formatChips = (amount) => amount.toLocaleString('en-US');

    async function request(options) {
        try {
            const response = await fetch('/gambling/chips', { headers: { Accept: 'application/json' }, ...options });
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

    // The server sends how many seconds are left instead of a time, so the countdown is right even when this
    // device's clock isn't
    function showDaily(state) {
        if (!daily) return;
        clearInterval(countdownTimer);
        countdownTimer = null;
        claimButton.disabled = !state.ready || isClaiming;

        if (state.ready) {
            nextDailyAt = null;
            dailyTimer.textContent = 'Your daily chips are ready.';
        } else {
            nextDailyAt = Date.now() + state.seconds * 1000;
            tick();
            countdownTimer = setInterval(tick, 1000);
        }
    }

    function show(wallet) {
        if (typeof wallet?.chips === 'number') showChips(wallet.chips);
        if (wallet?.daily) showDaily(wallet.daily);
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
            ? 'You collected ' + formatChips(wallet.daily.amount) + ' chips!'
            : 'You already collected your chips today.');
    });

    document.addEventListener('casino:wallet', (event) => show(event.detail));
    document.addEventListener('nav:poll', (event) => event.detail.params.set('wallet', '1'));
    document.addEventListener('nav:update', (event) => {
        if (event.detail.wallet) show(event.detail.wallet);
    });

    // The page was made with the daily chips' state, so only the countdown needs starting
    if (daily) {
        const seconds = Number(daily.dataset.seconds);
        showDaily({ ready: seconds === 0, seconds, amount: Number(daily.dataset.amount) });
    }
})();
