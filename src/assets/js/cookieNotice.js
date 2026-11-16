// Cookie notice (footer.php): the small box in the corner saying the site uses cookies and that using it means you
// agree to the terms and the privacy policy. footer.php renders it hidden on every page; this shows it until "Got it" is
// clicked, and then remembers that in localStorage so it stays away.
//
// Clicking it away also remembers which versions of the Terms of Service and the Privacy Policy those were (the dates
// in the second box's data-terms-updated / data-privacy-updated, from assets/php/legalUpdated.php). When either later
// gets a new date, that second box - "the Terms of Service and/or the Privacy Policy have been updated" - shows
// instead, with only the part about the one(s) that changed, until its own "Got it" is clicked (which saves both dates).
// Someone with no version saved at all clicked the cookie notice away before versions were remembered, so agreed to
// some older version, and gets the box too. (The terms key used to be 'hbHubTermsSeen', which quietly saved the current
// date for those people instead; it was renamed so everyone who agreed before the 14 November 2026 terms gets told about
// them. The privacy key was added on 14 November 2026, so everyone who'd already clicked the cookie notice away then
// was told about that day's privacy policy.)
(() => {
    const NOTICE_KEY = 'hbHubCookieNotice';
    const TERMS_KEY = 'hbHubTermsAgreed';
    const PRIVACY_KEY = 'hbHubPrivacyAgreed';
    const notice = document.querySelector('[data-cookie-notice]');
    const legalNotice = document.querySelector('[data-corner-terms]');
    if (!notice) return;
    const termsVersion = legalNotice?.dataset.termsUpdated ?? '';
    const privacyVersion = legalNotice?.dataset.privacyUpdated ?? '';

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

    /** Remembers the versions on this page as the ones agreed to. */
    const saveVersions = () => {
        if (termsVersion) write(TERMS_KEY, termsVersion);
        if (privacyVersion) write(PRIVACY_KEY, privacyVersion);
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
        // Never clicked away (or no storage: then it comes back on the next page, and the second box never shows)
        showUntilClicked(notice, '[data-cookie-notice-ok]', () => {
            write(NOTICE_KEY, 'seen');
            saveVersions();
        });
        return;
    }

    if (!legalNotice) return;
    // null = agreed before versions were kept, so to an older version than this one
    const termsChanged = termsVersion !== '' && read(TERMS_KEY) !== termsVersion;
    const privacyChanged = privacyVersion !== '' && read(PRIVACY_KEY) !== privacyVersion;
    if (!termsChanged && !privacyChanged) return;

    // Only the part about what changed: "the Terms of Service (...) have", "the Privacy Policy (...) has", or both
    legalNotice.querySelector('[data-corner-legal="terms"]').hidden = !termsChanged;
    legalNotice.querySelector('[data-corner-legal="privacy"]').hidden = !privacyChanged;
    legalNotice.querySelector('[data-corner-legal-and]').hidden = !(termsChanged && privacyChanged);
    legalNotice.querySelector('[data-corner-legal-verb]').textContent = termsChanged ? 'have' : 'has';
    legalNotice.querySelector('[data-corner-legal-them]').textContent = termsChanged && privacyChanged
        ? 'the new versions'
        : termsChanged ? 'the new terms' : 'the new privacy policy';
    legalNotice.setAttribute('aria-label', termsChanged && privacyChanged
        ? 'Terms and privacy policy updated'
        : termsChanged ? 'Terms updated' : 'Privacy policy updated');

    showUntilClicked(legalNotice, '[data-corner-terms-ok]', saveVersions);
})();
