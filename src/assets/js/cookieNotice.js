// Cookie notice (footer.php): the small box in the corner saying the site uses cookies and that using it means you
// agree to the terms. footer.php renders it hidden on every page; this shows it until "Got it" is clicked, and then
// remembers that in localStorage so it stays away.
//
// Clicking it away also remembers which version of the terms that was (the date in the terms notice's
// data-terms-updated, from assets/php/termsUpdated.php). When the terms later get a new date, the second box - "the
// terms have been updated" - shows instead, until its own "Got it" is clicked. Someone who clicked the cookie notice
// away before versions were remembered (so no version saved at all) agreed to some older terms, so they get the box
// too. (The key used to be 'hbHubTermsSeen', which quietly saved the current date for those people instead; it was
// renamed so everyone who agreed before the 14 November 2026 terms gets told about them.)
(() => {
    const NOTICE_KEY = 'hbHubCookieNotice';
    const TERMS_KEY = 'hbHubTermsAgreed';
    const notice = document.querySelector('[data-cookie-notice]');
    const termsNotice = document.querySelector('[data-terms-notice]');
    if (!notice) return;
    const termsVersion = termsNotice?.dataset.termsUpdated ?? '';

    /** localStorage that just gives up (null / nothing saved) when there's no storage, e.g. in a private window. */
    const read = (key) => {
        try {
            return localStorage.getItem(key);
        } catch {
            return null;
        }
    };
    const write = (key, value) => {
        try {
            localStorage.setItem(key, value);
        } catch {
            // nowhere to remember it, so it's only gone from this page
        }
    };

    /** Shows a box and hides it again (remembering why) when its "Got it" is clicked. */
    const showUntilClicked = (box, button, onClick) => {
        box.hidden = false;
        box.querySelector(button).addEventListener('click', () => {
            box.hidden = true;
            onClick();
        });
    };

    if (read(NOTICE_KEY) !== 'seen') {
        // Never clicked away (or no storage: then it comes back on the next page, and the terms box never shows)
        showUntilClicked(notice, '[data-cookie-notice-ok]', () => {
            write(NOTICE_KEY, 'seen');
            if (termsVersion) write(TERMS_KEY, termsVersion);
        });
        return;
    }

    if (!termsNotice || !termsVersion) return;
    // null = agreed before versions were kept, so to older terms than these
    if (read(TERMS_KEY) !== termsVersion) {
        showUntilClicked(termsNotice, '[data-terms-notice-ok]', () => write(TERMS_KEY, termsVersion));
    }
})();
