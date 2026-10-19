// Side menu on the settings page (otherPages/settings.php) and the admin panel's user and chat pages (admin/user.php,
// admin/chat.php): [data-settings-menu] holds links to the cards on the page (#profile, #block, ...). While scrolling,
// the link to the card at the top of the screen is marked with is-active. On a small screen the menu is a row of links
// that scrolls sideways (settings.css), so the marked one is scrolled into view there too.
// Without JS the links still jump to their cards, there's just nothing marked.
(() => {
    const menu = document.querySelector('[data-settings-menu]');
    if (!menu) return;
    const row = menu.querySelector('.settings-menu-links');

    // Each link with the card it goes to, in the order they're on the page
    const entries = [...menu.querySelectorAll('a.settings-menu-link[href^="#"]')]
        .map((link) => ({ link, card: document.getElementById(decodeURIComponent(link.hash.slice(1))) }))
        .filter((entry) => entry.card !== null);
    if (entries.length === 0) return;

    let active = null;

    function mark(entry) {
        if (entry === active) return;
        if (active) {
            active.link.classList.remove('is-active');
            active.link.removeAttribute('aria-current');
        }
        active = entry;
        entry.link.classList.add('is-active');
        entry.link.setAttribute('aria-current', 'location');

        // Only sideways, and only the row - the page itself isn't moved
        if (row && row.scrollWidth > row.clientWidth) {
            const left = entry.link.offsetLeft - row.offsetLeft - (row.clientWidth - entry.link.offsetWidth) / 2;
            row.scrollTo({ left, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        }
    }

    // The last card whose top has gone past a line a bit below the top bar (and the menu row on a small screen),
    // or the last card when the page is scrolled to the bottom, as a short last card can never reach the line
    function update() {
        const line = (parseFloat(getComputedStyle(entries[0].card).scrollMarginTop) || 0) + 40;
        const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;
        let current = entries[0];
        for (const entry of entries) {
            if (entry.card.getBoundingClientRect().top <= line) {
                current = entry;
            }
        }
        mark(atBottom ? entries[entries.length - 1] : current);
    }

    let scheduled = false;
    function scheduleUpdate() {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => {
            scheduled = false;
            update();
        });
    }

    // Clicking marks the link straight away, instead of every card scrolled past on the way there
    let clickedUntil = 0;
    for (const entry of entries) {
        entry.link.addEventListener('click', () => {
            mark(entry);
            clickedUntil = Date.now() + 800;
        });
    }

    window.addEventListener('scroll', () => {
        if (Date.now() < clickedUntil) return;
        scheduleUpdate();
    }, { passive: true });
    window.addEventListener('resize', scheduleUpdate);
    update();
})();
