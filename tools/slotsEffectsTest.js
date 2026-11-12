// Slots effects tester: paste all of this into the browser console on /gambling/slots, then run
//
//   slotsTest('jackpot')   three 7s on the middle line (60x), after the last reel teases it
//   slotsTest('mega')      a 32x win with diamonds
//   slotsTest('big')       three diamonds on the middle line (16x)
//   slotsTest('multi')     three winning lines (3x), shown one at a time with what each paid. Only the middle line's
//                          symbols really match: the other two are made up
//   slotsTest('win')       three cherries (1.2x, a little more than the bet)
//   slotsTest('small')     three cherries paying the bet back (no profit)
//   slotsTest('tease')     7, 7 and a lemon: the last reel teases the jackpot and misses ("So close!")
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

    // Where the reels stop for each one (the middle row on assets/php/casino/slots/slots.php's strips), what it pays
    // as times the bet, and the lines it says won (the middle one if not given), which share the payout
    const SPINS = {
        jackpot: { stops: [0, 8, 11], times: 60 }, // 7 7 7
        mega: { stops: [7, 4, 6], times: 32 }, // diamonds, paid as if on two lines
        big: { stops: [7, 4, 6], times: 16 }, // diamond diamond diamond
        multi: { stops: [1, 0, 2], times: 3, lines: [0, 1, 3] }, // cherries on the middle line, and two made up
        win: { stops: [1, 0, 2], times: 1.2 }, // cherry cherry cherry
        small: { stops: [1, 0, 2], times: 1 }, // cherries, the bet back
        tease: { stops: [0, 8, 0], times: 0, lines: [] }, // 7 7 lemon
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
        const lines = spin.lines ?? [0];
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
                    lines: lines.map((line) => ({
                        line, pays: (spin.times * LINES.length) / lines.length, payout: Math.round(payout / lines.length),
                    })),
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

    console.log('slotsTest ready: slotsTest(\'' + Object.keys(SPINS).join('\' | \'') + '\'), or slotsTest.all()');
})();
