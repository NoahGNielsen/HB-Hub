// Slots win effects tester: paste all of this into the browser console on /gambling/slots, then run
//
//   slotsTest('jackpot')   three 7s on the middle line (60x)
//   slotsTest('mega')      a 32x win with diamonds
//   slotsTest('big')       three diamonds on the middle line (16x)
//   slotsTest('win')       three cherries (1.2x, a little more than the bet)
//   slotsTest('small')     three cherries paying the bet back (no profit)
//   slotsTest.all()        every one of them in turn, 9 seconds apart
//
// It doesn't touch the server: it catches the next request slots.js sends to /gambling/slots/spin, answers it with a
// made-up spin of that size, and presses Spin. So no chips are spent or won (the top bar ends where it started), and
// the fake spin only shows up in "Your last spins" until the page is reloaded. Spin needs the bet to be playable, so
// have at least the bet in chips. Not part of the site: tools/ isn't uploaded.
(() => {
    const root = document.querySelector('[data-slots]');
    if (!root) {
        console.warn('slotsTest: open /gambling/slots first.');
        return;
    }
    const REELS = JSON.parse(root.dataset.reels);
    const LINES = JSON.parse(root.dataset.lines);

    // Where the reels stop for each one (the middle row on assets/php/casino/slots/slots.php's strips), and what it pays
    // as times the bet
    const SPINS = {
        jackpot: { stops: [0, 8, 11], times: 60 }, // 7 7 7
        mega: { stops: [7, 4, 6], times: 32 }, // diamonds, paid as if on two lines
        big: { stops: [7, 4, 6], times: 16 }, // diamond diamond diamond
        win: { stops: [1, 0, 2], times: 1.2 }, // cherry cherry cherry
        small: { stops: [1, 0, 2], times: 1 }, // cherries, the bet back
    };

    const symbolAt = (reel, position) => REELS[reel][((position % REELS[reel].length) + REELS[reel].length) % REELS[reel].length];
    const chipsNow = () => {
        const shown = Number(document.querySelector('[data-casino-chips]')?.dataset.casinoChips);
        return Number.isFinite(shown) ? shown : Number(root.dataset.chips);
    };

    window.slotsTest = (tier = 'jackpot') => {
        const spin = SPINS[tier];
        if (!spin) {
            console.warn('slotsTest: pick one of ' + Object.keys(SPINS).join(', '));
            return;
        }
        const button = root.querySelector('[data-sl-spin]');
        if (button.disabled) {
            console.warn('slotsTest: Spin can\'t be pressed right now (still spinning, or not enough chips for the bet).');
            return;
        }
        const bet = Number(root.querySelector('[data-sl-bet]').textContent.replace(/[^0-9]/g, ''));
        const payout = Math.round(bet * spin.times);
        const window3x3 = [0, 1, 2].map((row) => [0, 1, 2].map((reel) => symbolAt(reel, spin.stops[reel] + row - 1)));
        const chips = chipsNow();

        // Answers the next spin request with the made-up spin, and puts fetch back straight after
        const realFetch = window.fetch;
        window.fetch = async (url, options) => {
            if (String(url) !== root.dataset.api) return realFetch(url, options);
            window.fetch = realFetch;
            const answer = {
                chips, // what the user has now, so the top bar ends where it started
                spin: {
                    id: 0, stops: spin.stops, window: window3x3, bet, payout, net: payout - bet,
                    lines: [{ line: 0, pays: spin.times * LINES.length, payout }],
                },
            };
            return new Response(JSON.stringify(answer), { headers: { 'Content-Type': 'application/json' } });
        };
        button.click();
        console.log('slotsTest: ' + tier + ', bet ' + bet + ', paying ' + payout);
    };

    window.slotsTest.all = () => {
        Object.keys(SPINS).reverse().forEach((tier, index) => setTimeout(() => window.slotsTest(tier), index * 9000));
    };

    console.log('slotsTest ready: slotsTest(\'jackpot\' | \'mega\' | \'big\' | \'win\' | \'small\'), or slotsTest.all()');
})();
