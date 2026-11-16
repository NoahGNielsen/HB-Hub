// 3-hour bonus pop-up tester: paste all of this into the browser console on any casino page (/casino, a game, ...),
// then run
//
//   bonusTest()            shows the pop-up with a made-up 1,250 chips (bonusTest(5000) for another amount)
//   bonusTest.last()       the same, but collecting it says "That was the last one today."
//   bonusTest.taken()      collecting it says "This bonus was already collected." (as if collected in another tab)
//   bonusTest.error()      collecting it fails, to see the error message under the button
//   bonusTest.stop()       puts everything back as it was (or just reload the page)
//
// It doesn't touch the server: until bonusTest.stop() it answers casino.js's requests to /casino/chips itself (so
// Collect gives no chips and the top bar stays where it was), and swaps its made-up bonus into the "wallet" of the nav
// bar's polling, which would otherwise hide the pop-up again within 15 seconds when your real bonus isn't ready.
// Running it again shows the pop-up again, even after its x was clicked. Not part of the site: tools/ isn't uploaded.
(() => {
    if (!document.querySelector('[data-casino-bonus]')) {
        console.warn('bonusTest: open a casino page first (the pop-up is only on those).');
        return;
    }
    window.bonusTest?.stop(); // pasted twice: undo the first one before setting up again

    const THREE_HOURS = 3 * 60 * 60;
    const realFetch = window.fetch;
    let fake = null; // what the made-up bonus is now: { amount, ready, outcome }, null when stopped

    const chipsNow = () => Number(document.querySelector('[data-casino-chips]')?.dataset.casinoChips ?? 0);
    const bonusState = () => ({
        available: fake.ready || fake.outcome !== 'last',
        ready: fake.ready,
        seconds: fake.ready ? 0 : THREE_HOURS,
        amount: fake.amount,
    });
    const jsonAnswer = (data, status = 200) => new Response(JSON.stringify(data), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });

    // casino.js's /casino/chips: a look (GET) gets the made-up bonus, Collect (POST action=claimBonus) "collects" it.
    // Everything else, the daily chips included, still goes to the server.
    window.fetch = async (url, options = {}) => {
        if (fake === null || !String(url).startsWith('/casino/chips')) return realFetch(url, options);

        const action = options.body instanceof URLSearchParams ? options.body.get('action') : null;
        if (action === 'claimBonus') {
            await new Promise((resolve) => setTimeout(resolve, 400)); // long enough to see the button go grey
            if (fake.outcome === 'error') return jsonAnswer({ error: 'bonusTest' }, 500);
            const claimed = fake.outcome !== 'taken';
            fake.ready = false;
            return jsonAnswer({ claimed, claimedAmount: claimed ? fake.amount : 0, chips: chipsNow(), bonus: bonusState() });
        }
        if (action === null) {
            const wallet = await (await realFetch(url, options)).json();
            return jsonAnswer({ ...wallet, bonus: bonusState() });
        }
        return realFetch(url, options);
    };

    // The nav bar's polling (and the games' own polls that take it along) hand casino.js the real bonus on nav:update.
    // This runs first (window, capture) and swaps the made-up one in.
    const swapNavBonus = (event) => {
        if (fake !== null && event.detail?.wallet) event.detail.wallet.bonus = bonusState();
    };
    window.addEventListener('nav:update', swapNavBonus, true);

    // A not-ready bonus first, which makes casino.js forget an earlier click on the x, then the ready one
    const show = (amount, outcome) => {
        fake = { amount: Math.max(1, Math.round(Number(amount) || 1250)), ready: false, outcome };
        document.dispatchEvent(new CustomEvent('casino:wallet', { detail: { bonus: bonusState() } }));
        fake.ready = true;
        document.dispatchEvent(new CustomEvent('casino:wallet', { detail: { bonus: bonusState() } }));
        console.log('bonusTest: showing +' + fake.amount.toLocaleString('en-US') + ' chips (' + outcome + ').');
    };

    window.bonusTest = (amount) => show(amount, 'next');
    window.bonusTest.last = (amount) => show(amount, 'last');
    window.bonusTest.taken = (amount) => show(amount, 'taken');
    window.bonusTest.error = (amount) => show(amount, 'error');
    window.bonusTest.stop = () => {
        fake = null;
        window.fetch = realFetch;
        window.removeEventListener('nav:update', swapNavBonus, true);
        // Back to the real bonus (this hides the pop-up again if it isn't ready)
        realFetch('/casino/chips', { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((wallet) => wallet && document.dispatchEvent(new CustomEvent('casino:wallet', { detail: wallet })));
        console.log('bonusTest: stopped.');
    };

    console.log('bonusTest ready: bonusTest(), bonusTest.last(), bonusTest.taken(), bonusTest.error(), bonusTest.stop()');
})();
