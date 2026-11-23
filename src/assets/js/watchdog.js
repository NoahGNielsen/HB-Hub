// The Watchdog, on every page (header.php, before every other script): notes every error that happens in the browser
// and tells the server, so site admins can see when, where and how often things go wrong, under Browser errors in the
// admin panel (admin/watchdog.php). assets/php/watchdog.php has the rest. It started out counting only the
// "508 Resource Limit Is Reached" answers (the hosting only runs about 10 PHP requests at once, and every one over that
// gets a 508), and those are still counted on their own on the admin panel.
//
// What counts, as an error of one of these kinds (an entry's `kind`):
// - 'http': an answer of 400 or more from the site, with its status. From every fetch() (window.fetch is wrapped), every
//   XMLHttpRequest (open/send are wrapped), and in browsers that give the status of everything else they load
//   (Chromium, PerformanceResourceTiming.responseStatus) also pictures, styles and scripts. Not an answer the endpoint
//   marked with the header "X-HBHub-Expected: 1" (a normal part of a game, like Wordle's "not a word").
// - 'network': a fetch() or XMLHttpRequest that got no answer at all (the connection broke, the server didn't answer).
//   Not when it was stopped on purpose (AbortError), while the browser says it's offline, once the page is being left,
//   or when the tab was hidden while it was on its way (phones freeze tabs in the background and break their requests).
// - 'load': a picture, script, stylesheet, video or audio that didn't load, when the browser doesn't say it was an
//   'http' one (waits LOAD_WAIT_MS for that first, so one isn't counted twice).
// - 'script': an uncaught JavaScript error (window 'error'), with its message and the file, line and column.
// - 'promise': a promise that was rejected with nobody catching it (unhandledrejection), with the message and, where
//   the stack says, the file, line and column.
// - 'csp': something the Content-Security-Policy blocked (securitypolicyviolation), with the directive.
// Left out: browser extensions' own errors (chrome-extension:// and the like), fonts from other sites that the CSP
// blocked (ours are all self-hosted, so those come from extensions too), cross-origin scripts' "Script error."
// that says nothing, the harmless "ResizeObserver loop" warning, and the report itself. Addresses are kept as a path
// on this site (/chat/), or with the origin for other sites (https://media.giphy.com/...), never with the query, and
// URLs in messages lose their query too. A page that's an error as a whole (the hosting's 508 page) doesn't have this
// script, so that one isn't counted.
//
// WAIT_MS (3 seconds) after the first one, what there was is sent to /watchdog (otherPages/watchdog.php) as a form:
// action=report, count, page (the path of this page), errors (JSON list of {kind, path, status, message, line, count},
// at most MAX_ERRORS different ones, the same error again only adds to its count), firstAgo and lastAgo (milliseconds
// ago, so the computer's clock doesn't matter). The report is a PHP request too, so while the server is busy it often
// gets a 508 itself: then it's tried again every WAIT_MS until it goes through, with any new errors from the meantime
// added to it. Only a 400 (the report itself is wrong) makes it give up.
//
// What hasn't gone through yet is kept in sessionStorage (STORE_KEY, {pending, sending}), so reloading or going to
// another page in the same tab carries on with it instead of losing it. A report that was on its way when the page
// went is sent again, since there's no knowing whether it arrived. Without sessionStorage it's only kept in the page.
(() => {
    const REPORT_URL = '/watchdog';
    const WAIT_MS = 3000;
    const LOAD_WAIT_MS = 1000; // a 'load' error waits this long for the browser to say it was an 'http' one
    const MAX_ERRORS = 20; // different errors in one report, the same as HBHUB_WATCHDOG_MAX_ERRORS
    const MAX_MESSAGE = 300; // characters of a message, the same as HBHUB_WATCHDOG_MAX_MESSAGE
    const STORE_KEY = 'hbHubWatchdog';

    const nativeFetch = window.fetch;
    if (typeof nativeFetch !== 'function') return;

    // A batch is {count, errors: {key: {kind, path, status, message, line, count}}, first, last} (first/last as Date.now())
    let pending = null; // not sent yet
    let sending = null; // on its way
    let timer = 0;
    let isLeaving = false; // the page is being left: requests it had going break off, which isn't an error
    let hiddenAt = 0; // the last time the tab was hidden or frozen (Date.now()), see noteNetwork()
    const recentHttp = new Map(); // address => Date.now(), the 'http' ones, so a 'load' for the same one isn't counted too

    // Adds batch b into batch a (or returns b when there's no a)
    const merge = (a, b) => {
        if (!a) return b;
        if (!b) return a;
        a.count += b.count;
        a.first = Math.min(a.first, b.first);
        a.last = Math.max(a.last, b.last);
        for (const [key, error] of Object.entries(b.errors)) {
            if (key in a.errors) {
                a.errors[key].count += error.count;
            } else if (Object.keys(a.errors).length < MAX_ERRORS) {
                a.errors[key] = error;
            }
        }
        return a;
    };

    const isBatch = (batch) => batch && Number.isInteger(batch.count) && batch.count > 0 && batch.errors
        && typeof batch.errors === 'object' && Number.isFinite(batch.first) && Number.isFinite(batch.last);

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

    // What an earlier page in this tab didn't get sent. One that was on its way is sent again (see the top). One saved
    // by the older Watchdog (only 508s, {addresses} instead of {errors}) isn't a batch any more and is dropped.
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
            errors: JSON.stringify(Object.values(sending.errors)),
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

    // An address as it's kept: the path on this site, or origin + path elsewhere, never the query. Null for what isn't
    // a web address (data:, blob:, an extension's) and for the report itself.
    const addressOf = (address) => {
        if (!address) return null;
        try {
            const url = new URL(String(address), location.href);
            if (url.protocol !== 'https:' && url.protocol !== 'http:') return null;
            if (url.origin === location.origin) return url.pathname === REPORT_URL ? null : url.pathname;
            return url.origin + url.pathname;
        } catch {
            return null;
        }
    };

    // A message as it's kept: one line, URLs without their query, at most MAX_MESSAGE characters
    const messageOf = (text) => {
        const message = String(text ?? '').replace(/\s+/g, ' ').trim()
            .replace(/(https?:\/\/[^\s?#'"]*)[?#][^\s'"]*/g, '$1');
        return message === '' ? null : message.slice(0, MAX_MESSAGE);
    };

    // Whether a file the error came from belongs to a browser extension rather than the site
    const isExtension = (file) => /^(chrome|moz|safari|safari-web|ms-browser)-extension:/i.test(String(file ?? ''));

    // Counts one error: {kind, path, status, message, line} (path from addressOf(), line "120:5" or null)
    const note = (kind, { path = null, status = null, message = null, line = null } = {}) => {
        const error = { kind, path, status, message, line, count: 1 };
        const key = [kind, path, status, message, line].join('|');
        const now = Date.now();
        pending = merge(pending, { count: 1, errors: { [key]: error }, first: now, last: now });
        save();
        schedule();
    };

    // An answer of 400 or more from address (anything not ours to report is skipped)
    const noteHttp = (address, status) => {
        const path = addressOf(address);
        if (path === null) return;
        recentHttp.set(path, Date.now());
        note('http', { path, status });
    };

    // A request to address that got no answer, unless that's because of the page or the browser (see the top).
    // startedAt is when it was sent (Date.now()): a tab that was hidden since then may have been frozen or lost its
    // connection (phones do that to tabs in the background, and the polls they had going come back as "Failed to
    // fetch"), so that doesn't count either.
    const noteNetwork = (address, error, startedAt) => {
        if (isLeaving || navigator.onLine === false || error?.name === 'AbortError') return;
        if (document.visibilityState !== 'visible' || hiddenAt >= startedAt) return;
        const path = addressOf(address);
        if (path !== null) note('network', { path, message: messageOf(error?.message) });
    };

    // The file, line and column an error's stack starts in, as [path, "120:5"], from the first frame that's a web address
    const whereOf = (stack) => {
        const match = /(https?:\/\/[^\s()]+?):(\d+):(\d+)/.exec(String(stack ?? ''));
        return match ? [addressOf(match[1]), match[2] + ':' + match[3]] : [null, null];
    };

    const requestUrl = (input) => (input instanceof Request ? input.url : String(input));

    // An answer the endpoint sent as part of how things work (EXPECTED_HEADER: "not a word" in Wordle, "the host
    // stopped the game" in Matador) isn't an error, whatever its status. get(name) reads a header of the answer.
    const EXPECTED_HEADER = 'X-HBHub-Expected';
    const isExpected = (get) => {
        try {
            return get(EXPECTED_HEADER) === '1';
        } catch {
            return false;
        }
    };

    // Every fetch() on the page goes through here first. The answer (or the failure) is passed on untouched.
    window.fetch = function (input, init) {
        const startedAt = Date.now();
        return nativeFetch.call(this, input, init).then((response) => {
            if (response.status >= 400 && !isExpected((name) => response.headers.get(name))) {
                noteHttp(response.url || requestUrl(input), response.status);
            }
            return response;
        }, (error) => {
            noteNetwork(requestUrl(input), error, startedAt);
            throw error;
        });
    };

    // Every XMLHttpRequest too (chat.js's uploads): open() remembers where to, send() listens for how it went
    if (typeof XMLHttpRequest === 'function') {
        const nativeOpen = XMLHttpRequest.prototype.open;
        const nativeSend = XMLHttpRequest.prototype.send;
        const urls = new WeakMap();
        XMLHttpRequest.prototype.open = function (method, url, ...rest) {
            urls.set(this, url);
            return nativeOpen.call(this, method, url, ...rest);
        };
        XMLHttpRequest.prototype.send = function (...args) {
            const url = urls.get(this);
            const startedAt = Date.now();
            this.addEventListener('load', () => {
                if (this.status >= 400 && !isExpected((name) => this.getResponseHeader(name))) {
                    noteHttp(this.responseURL || url, this.status);
                }
            });
            this.addEventListener('error', () => noteNetwork(url, null, startedAt));
            return nativeSend.apply(this, args);
        };
    }

    // Everything else the page loads, where the browser tells the status (fetch() and XMLHttpRequest are counted above,
    // not twice)
    if (typeof PerformanceObserver === 'function' && PerformanceObserver.supportedEntryTypes?.includes('resource')) {
        try {
            new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (entry.responseStatus >= 400 && entry.initiatorType !== 'fetch' && entry.initiatorType !== 'xmlhttprequest') {
                        noteHttp(entry.name, entry.responseStatus);
                    }
                }
            }).observe({ type: 'resource', buffered: true });
        } catch {
            // An older browser that can't watch these: only fetch() and XMLHttpRequest give a status
        }
    }

    // A picture, script, stylesheet, video or audio that didn't load. Their 'error' doesn't bubble, so it's caught on
    // the way down. Counted LOAD_WAIT_MS later, unless the browser said it was an 'http' error in the meantime.
    const LOADS = new Set(['IMG', 'SCRIPT', 'LINK', 'VIDEO', 'AUDIO', 'SOURCE', 'TRACK', 'IFRAME', 'EMBED', 'OBJECT']);
    window.addEventListener('error', (event) => {
        const target = event.target;
        if (!(target instanceof Element) || !LOADS.has(target.tagName)) return;
        const path = addressOf(target.currentSrc || target.src || target.href || target.data);
        if (path === null) return;
        const at = Date.now();
        setTimeout(() => {
            const httpAt = recentHttp.get(path);
            if (httpAt !== undefined && httpAt >= at - WAIT_MS) return;
            if (!isLeaving) note('load', { path, message: target.tagName.toLowerCase() });
        }, LOAD_WAIT_MS);
    }, true);

    // An uncaught JavaScript error
    window.addEventListener('error', (event) => {
        if (event.target instanceof Element) return; // a file that didn't load, above
        if (isExtension(event.filename) || isExtension(event.error?.stack)) return;
        const message = messageOf(String(event.message || event.error?.message || '').replace(/^Uncaught\s+/, '')); // Chromium starts with "Uncaught "
        if (message === null || /^Script error\.?$/i.test(message) || /^(Error: )?ResizeObserver loop/i.test(message)) return;
        let path = addressOf(event.filename);
        let line = event.lineno ? event.lineno + ':' + (event.colno || 0) : null;
        if (path === null) [path, line] = whereOf(event.error?.stack);
        note('script', { path, message: (event.error?.name && !message.includes(event.error.name) ? event.error.name + ': ' : '') + message, line });
    });

    // What a promise was rejected with that isn't an Error or a string, as text. JSON alone says "{}" for most of them
    // (an Event, a DOMException in some browsers, a class's object), which tells nothing, so it starts with what it is:
    // "Event (error on img)", "Object {}", "XMLHttpRequest {}". Anything JSON can't do is String() instead.
    const describe = (reason) => {
        if (reason === null || typeof reason !== 'object') return String(reason);
        if (typeof Event === 'function' && reason instanceof Event) {
            const target = reason.target instanceof Element ? ' on ' + reason.target.tagName.toLowerCase() : '';
            return (reason.constructor?.name || 'Event') + ' (' + reason.type + target + ')';
        }
        let json;
        try { json = JSON.stringify(reason); } catch { json = String(reason); }
        return (reason.constructor?.name || 'Object') + ' ' + json;
    };

    // A rejected promise nobody caught
    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;
        if (reason?.name === 'AbortError' || isExtension(reason?.stack)) return;
        const message = messageOf(reason instanceof Error ? reason.name + ': ' + reason.message
            : typeof reason === 'string' ? reason : describe(reason));
        const [path, line] = whereOf(reason?.stack);
        note('promise', { path, message, line });
    });

    // Something the Content-Security-Policy blocked. blockedURI is an address, or a word for what isn't one: 'inline'
    // (a style="" or inline script), 'eval', or a scheme like 'data' / 'blob'. Those words aren't resolved as addresses
    // (they'd turn into /games/matador/inline): then the file it happened in is the address, and the word goes in the message.
    document.addEventListener('securitypolicyviolation', (event) => {
        if (isExtension(event.sourceFile) || isExtension(event.blockedURI)) return;
        const isAddress = /^https?:/i.test(event.blockedURI ?? '');
        const blocked = isAddress ? addressOf(event.blockedURI) : null;
        const directive = event.effectiveDirective || event.violatedDirective;
        // Every font we use is our own copy (assets/fonts/), so a font from another site was asked for by something the
        // browser put on the page, like an extension's CSS (Mulish from fonts.gstatic.com, say), and font loads don't name
        // a sourceFile for isExtension() to catch. If our own code ever linked an outside font, its stylesheet would still
        // show up here as style-src.
        if (directive === 'font-src' && isAddress && /^https?:\/\//i.test(blocked ?? '')) return;
        // The site has no inline styles or scripts, so a blocked one was put there by something else. The CSP has
        // 'report-sample', so the browser gives its first 40 characters (event.sample), which usually says what did it
        // (".darkreader ...", a translator's class names)
        const sample = typeof event.sample === 'string' && event.sample.trim() !== '' ? ': ' + event.sample : '';
        note('csp', {
            path: blocked ?? addressOf(event.sourceFile),
            message: messageOf(directive + (!isAddress && event.blockedURI ? ' (' + event.blockedURI + ')' : '') + sample),
            line: event.lineNumber ? event.lineNumber + ':' + (event.columnNumber || 0) : null,
        });
    });

    // Leaving the page breaks off what it was loading: none of that is an error. Back from the back/forward cache, it is again.
    window.addEventListener('pagehide', () => { isLeaving = true; });
    window.addEventListener('beforeunload', () => { isLeaving = true; });
    window.addEventListener('pageshow', () => { isLeaving = false; });
    // A hidden (or frozen) tab's requests can break off too, see noteNetwork()
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') hiddenAt = Date.now();
    });
    document.addEventListener('freeze', () => { hiddenAt = Date.now(); });
    document.addEventListener('resume', () => { hiddenAt = Date.now(); });

    load();
    schedule();
})();
