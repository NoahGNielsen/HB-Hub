// Terms notice tester: paste all of this into the browser console on any HB Hub page to see the "the terms have been
// updated" box again. It's not a cookie: cookieNotice.js keeps it in localStorage. This keeps "clicked the cookie
// notice away" (hbHubCookieNotice) and forgets which terms version you agreed to (hbHubTermsAgreed), then reloads, so
// the page treats you as someone who agreed to older terms. Clicking "Got it" saves the version again, as usual.
// To get the very first cookie notice back instead, also run localStorage.removeItem('hbHubCookieNotice') before
// reloading. Not part of the site: tools/ isn't uploaded.
(() => {
    localStorage.setItem('hbHubCookieNotice', 'seen');
    localStorage.removeItem('hbHubTermsAgreed');
    localStorage.removeItem('hbHubTermsSeen'); // the old name of the key, in case it's still lying around
    location.reload();
})();
