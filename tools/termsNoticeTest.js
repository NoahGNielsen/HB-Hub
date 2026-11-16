// Terms notice tester: paste all of this into the browser console on any HB Hub page to see the "the Terms of Service
// and the Privacy Policy have been updated" box again. It's not a cookie: cornerBoxes.js keeps it in localStorage. This
// keeps "clicked the cookie notice away" (hbHubCookieNotice) and forgets which terms and privacy policy versions you
// agreed to (hbHubTermsAgreed, hbHubPrivacyAgreed), then reloads, so the page treats you as someone who agreed to older
// versions of both. Clicking "Got it" saves the versions again, as usual.
// To see the box about only one of them, comment out the removeItem line for the other one.
// To get the very first cookie notice back instead, also run localStorage.removeItem('hbHubCookieNotice') before
// reloading. Not part of the site: tools/ isn't uploaded.
(() => {
    localStorage.setItem('hbHubCookieNotice', 'seen');
    localStorage.removeItem('hbHubTermsAgreed');
    localStorage.removeItem('hbHubPrivacyAgreed');
    localStorage.removeItem('hbHubTermsSeen'); // the old name of the terms key, in case it's still lying around
    location.reload();
})();
