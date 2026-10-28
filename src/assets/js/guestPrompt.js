// The popup on the guest games (/play/..., assets/php/guestGames/bottom.php) that asks the visitor to sign up or log in
// after a while: first after FIRST_S seconds of playing, then again AGAIN_S seconds after each "Keep playing". Only time
// with the page in view counts, over all the guest games together: it's kept in localStorage (hbHubGuestPrompt, as
// {played: seconds, next: when to show it, shownAt: when it last was}), so it carries on from page to page.
// A game can also ask for it at a good moment (a Wordle that's just been finished) with
//     document.dispatchEvent(new CustomEvent('guest:prompt'));
// which shows it unless it was shown less than SOON_S seconds ago. It never opens over another popup: then it waits.
(() => {
    const dialog = document.querySelector('[data-guest-prompt]');
    if (!dialog) return;

    const STORE_KEY = 'hbHubGuestPrompt';
    const FIRST_S = 180;
    const AGAIN_S = 600;
    const SOON_S = 60;
    const TICK_S = 5;

    let state = { played: 0, next: FIRST_S, shownAt: null };
    try {
        const saved = JSON.parse(localStorage.getItem(STORE_KEY));
        if (Number.isFinite(saved?.played) && Number.isFinite(saved?.next)) {
            state = { played: saved.played, next: saved.next, shownAt: Number.isFinite(saved.shownAt) ? saved.shownAt : null };
        }
    } catch {
        // no storage, or something broken in it: counted for this page only
    }

    function store() {
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify(state));
        } catch {
            // no storage
        }
    }

    // Returns whether it opened: not over another popup (a game's own, like Ore Mining Inc.'s Warp Jump)
    function show() {
        if (dialog.open || document.querySelector('dialog[open]')) return false;
        dialog.showModal();
        state.shownAt = state.played;
        state.next = state.played + AGAIN_S;
        store();
        return true;
    }

    setInterval(() => {
        if (document.visibilityState !== 'visible') return;
        state.played += TICK_S;
        if (state.played >= state.next && !show()) state.next = state.played + TICK_S; // try again shortly
        store();
    }, TICK_S * 1000);

    document.addEventListener('guest:prompt', () => {
        if (state.shownAt !== null && state.played - state.shownAt < SOON_S) return;
        setTimeout(show, 1500); // after the game has shown what just happened
    });

    dialog.querySelector('[data-guest-prompt-later]').addEventListener('click', () => dialog.close());
    // A click on the dark backdrop around it closes it too (the click lands on the dialog itself, not its contents)
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
})();
