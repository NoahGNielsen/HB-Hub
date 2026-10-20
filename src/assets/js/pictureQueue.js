// Pictures that load a couple at a time: every <img data-queued-picture="address"> on the page (the profile pictures and
// chat icons in the chat, and the user/chat lists and Banned pictures in the admin panel). Each of those pictures comes
// from a PHP script (otherPages/avatar.php, chat/icon.php, admin/bannedPicture.php), and the hosting only runs 10 PHP
// requests at the same time for the whole site - the rest get "508 Resource Limit Is Reached" instead, and so does
// anyone else on the site at that moment. So they load PICTURES_AT_ONCE at a time, the ones on screen first, and one
// that fails anyway is tried again a bit later, up to PICTURE_TRIES times.
// Pictures put in later (the chat's polling swaps in a new chat list, new messages, older ones...) join the queue too.
// An address that has loaded once is cached by the browser for good (the scripts send "immutable"), so the same picture
// showing up again gets it straight away, without waiting its turn - otherwise the chat list would blink on every swap.
// Without JS the pages have a <noscript> copy of each picture, which loads the normal way.
(() => {
    const PICTURES_AT_ONCE = 2;
    const PICTURE_TRIES = 5;
    const PICTURE_RETRY_MS = 1500; // doubled after each failed try

    const waiting = []; // on screen (or close to it), not loaded yet
    const loaded = new Set(); // addresses that have loaded
    let loading = 0;

    // Resolves once the picture has loaded, or after its last try failed
    function loadPicture(img) {
        const address = img.dataset.queuedPicture;
        return new Promise((resolve) => {
            let tries = 0;
            const tryLoad = () => {
                tries++;
                img.removeAttribute('src'); // so setting the same address again really sends a new request
                img.src = address;
            };
            img.addEventListener('load', () => {
                loaded.add(address);
                resolve();
            });
            img.addEventListener('error', () => {
                // Given up on, or taken off the page (a newer chat list) - no point trying that one again
                if (tries >= PICTURE_TRIES || !img.isConnected) {
                    resolve();
                    return;
                }
                setTimeout(tryLoad, PICTURE_RETRY_MS * 2 ** (tries - 1));
            });
            tryLoad();
        });
    }

    function next() {
        while (loading < PICTURES_AT_ONCE && waiting.length > 0) {
            const img = waiting.shift();
            if (!img.isConnected) continue; // swapped out before its turn came
            if (loaded.has(img.dataset.queuedPicture)) {
                img.src = img.dataset.queuedPicture; // the same picture came in again while this one waited
                continue;
            }
            loading++;
            loadPicture(img).then(() => {
                loading--;
                next();
            });
        }
    }

    // Like loading="lazy": a picture joins the queue once it's close to being on screen
    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            observer.unobserve(entry.target);
            if (!waiting.includes(entry.target)) waiting.push(entry.target);
        }
        next();
    }, { rootMargin: '200px' });

    function add(img) {
        if (loaded.has(img.dataset.queuedPicture)) {
            img.src = img.dataset.queuedPicture;
        } else if (!img.hasAttribute('src')) { // one with an address is already loading (or a copy of one that has)
            observer.observe(img);
        }
    }

    function pictures(node) {
        if (node.nodeType !== Node.ELEMENT_NODE) return [];
        const found = [...node.querySelectorAll('img[data-queued-picture]')];
        if (node.matches('img[data-queued-picture]')) found.push(node);
        return found;
    }

    document.querySelectorAll('img[data-queued-picture]').forEach(add);
    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.removedNodes) pictures(node).forEach((img) => observer.unobserve(img));
            for (const node of mutation.addedNodes) pictures(node).forEach(add);
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
