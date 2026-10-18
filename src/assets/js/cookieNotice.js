// Cookie notice (footer.php): the small box in the corner saying the site uses cookies and that using it means you
// agree to the terms. footer.php renders it hidden on every page; this shows it until "Got it" is clicked, and then
// remembers that in localStorage so it stays away.
(() => {
    const NOTICE_KEY = 'hbHubCookieNotice';
    const notice = document.querySelector('[data-cookie-notice]');
    if (!notice) return;

    try {
        if (localStorage.getItem(NOTICE_KEY) === 'seen') return;
    } catch {
        // no storage (a private window): show it, it just comes back on the next page
    }
    notice.hidden = false;

    notice.querySelector('[data-cookie-notice-ok]').addEventListener('click', () => {
        notice.hidden = true;
        try {
            localStorage.setItem(NOTICE_KEY, 'seen');
        } catch {
            // nowhere to remember it, so it's only gone from this page
        }
    });
})();
