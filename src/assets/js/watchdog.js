// The Watchdog, on every page (header.php, before every other script): counts the "508 Resource Limit Is Reached"
// answers the page gets from the site, and tells the server. The hosting only runs about 10 PHP requests at the same
// time, and every one over that gets a 508 (assets/php/watchdog.php has the rest), so site admins can see when and where
// that happens, under Watchdog in the admin panel (admin/watchdog.php).
//
// What counts: every fetch() the page's scripts make (window.fetch is wrapped, so it sees each answer's status), and in
// browsers that give the status of everything else they load (Chromium, PerformanceResourceTiming.responseStatus) also
// pictures, styles, scripts and XMLHttpRequests. Only addresses on this site, and never the report itself.
// A page that's a 508 as a whole is the hosting's own error page, without this script, so that one isn't counted.
//
// WAIT_MS (3 seconds) after the first one, how many there were is sent to /watchdog (otherPages/watchdog.php) as a
// form: action=report, count, page (the path of this page), addresses (JSON {"/path": count}, at most MAX_ADDRESSES),
// firstAgo and lastAgo (milliseconds ago, so the computer's clock doesn't matter). The report is a PHP request too, so
// while the server is busy it often gets a 508 itself: then it's tried again every WAIT_MS until it goes through, with
// any new 508s from the meantime added to it. Only a 400 (the report itself is wrong) makes it give up.
//
// What hasn't gone through yet is kept in sessionStorage (STORE_KEY, {pending, sending}), so reloading or going to
// another page in the same tab carries on with it instead of losing it. A report that was on its way when the page
// went is sent again, since there's no knowing whether it arrived. Without sessionStorage it's only kept in the page.
(() => {
    const REPORT_URL = '/watchdog';
    const WAIT_MS = 3000;
    const MAX_ADDRESSES = 20; // different addresses in one report, the same as HBHUB_WATCHDOG_MAX_ADDRESSES
    const STORE_KEY = 'hbHubWatchdog';
    const STATUS = 508;

    const nativeFetch = window.fetch;
    if (typeof nativeFetch !== 'function') return;

    // A batch is {count, addresses: {"/path": count}, first, last} (first/last as Date.now())
    let pending = null; // not sent yet
    let sending = null; // on its way
    let timer = 0;

    // Adds batch b into batch a (or returns b when there's no a)
    const merge = (a, b) => {
        if (!a) return b;
        if (!b) return a;
        a.count += b.count;
        a.first = Math.min(a.first, b.first);
        a.last = Math.max(a.last, b.last);
        for (const [path, count] of Object.entries(b.addresses)) {
            if (path in a.addresses || Object.keys(a.addresses).length < MAX_ADDRESSES) {
                a.addresses[path] = (a.addresses[path] || 0) + count;
            }
        }
        return a;
    };

    const isBatch = (batch) => batch && Number.isInteger(batch.count) && batch.count > 0 && batch.addresses
        && typeof batch.addresses === 'object' && Number.isFinite(batch.first) && Number.isFinite(batch.last);

    const save = () => {
        try {
            if (pending || sending) {
                sessionStorage.setItem(STORE_KEY, JSON.stringify({ pending, sending }));
            } else {
                sessionStorage.removeItem(STORE_KEY);
            }
        } catch {
            // No sessionStorage (blocked, private mode): it's only kept in this page
        }
    };

    // What an earlier page in this tab didn't get sent. One that was on its way is sent again (see the top).
    const load = () => {
        try {
            const saved = JSON.parse(sessionStorage.getItem(STORE_KEY));
            if (saved) {
                pending = merge(isBatch(saved.sending) ? saved.sending : null, isBatch(saved.pending) ? saved.pending : null);
            }
        } catch {
            pending = null;
        }
        save();
    };

    const schedule = () => {
        if (timer || sending || !pending) return;
        timer = setTimeout(send, WAIT_MS);
    };

    const send = async () => {
        timer = 0;
        if (sending || !pending) return;
        sending = pending;
        pending = null;
        save();

        const now = Date.now();
        const body = new URLSearchParams({
            action: 'report',
            count: String(sending.count),
            page: location.pathname,
            addresses: JSON.stringify(sending.addresses),
            firstAgo: String(Math.max(0, now - sending.first)),
            lastAgo: String(Math.max(0, now - sending.last)),
        });
        let isDone = false;
        try {
            const response = await nativeFetch.call(window, REPORT_URL, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body,
                keepalive: true, // so it still goes if the page is left right now
            });
            isDone = response.ok || response.status === 400; // 400: the report is wrong and never will go through
        } catch {
            // Offline, or the connection broke: tried again below
        }
        if (!isDone) pending = merge(sending, pending);
        sending = null;
        save();
        schedule();
    };

    // Counts one 508 from this address, if it's on this site and isn't the report itself
    const note = (address) => {
        let path;
        try {
            const url = new URL(address, location.href);
            if (url.origin !== location.origin) return;
            path = url.pathname;
        } catch {
            return;
        }
        if (path === REPORT_URL) return;
        const now = Date.now();
        pending = merge(pending, { count: 1, addresses: { [path]: 1 }, first: now, last: now });
        save();
        schedule();
    };

    // Every fetch() on the page goes through here first. The answer itself is passed on untouched.
    window.fetch = function (input, init) {
        return nativeFetch.call(this, input, init).then((response) => {
            if (response.status === STATUS) note(response.url || (input instanceof Request ? input.url : String(input)));
            return response;
        });
    };

    // Everything else the page loads, where the browser tells the status (fetch() is counted above, not twice)
    if (typeof PerformanceObserver === 'function' && PerformanceObserver.supportedEntryTypes?.includes('resource')) {
        try {
            new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (entry.responseStatus === STATUS && entry.initiatorType !== 'fetch') note(entry.name);
                }
            }).observe({ type: 'resource', buffered: true });
        } catch {
            // An older browser that can't watch these: only fetch() is counted
        }
    }

    load();
    schedule();
})();
