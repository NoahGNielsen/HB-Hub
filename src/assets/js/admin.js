// Admin panel (admin/index.php, admin/user.php, admin/chat.php, admin/banned.php and admin/feedback.php): asks "are you sure?" before the forms with
// data-confirm (ban, delete, kick, removing a banned picture, deleting feedback and so on), Generate fills in a random password for Reset password, shown so it can be passed on to the user,
// the chat's Background card shows the gradient as it's picked, and the user/chat lists and Banned pictures load their pictures two at a time.
// Without JS the forms are sent straight away, the password has to be typed in, there's no preview and the pictures all load at once.
(() => {
    for (const form of document.querySelectorAll('form[data-confirm]')) {
        form.addEventListener('submit', (event) => {
            if (!confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    }
})();

(() => {
    const generate = document.querySelector('[data-password-generate]');
    if (!generate) return;
    const input = document.querySelector('#admin-new-password');

    // No characters that look alike (0 O 1 l I), so it can be read out or typed in from a screen.
    // One of each kind and 16 long, so it meets the strictest password rules.
    const charSets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!?#%+-='];

    function randomIndex(length) {
        const range = 0x100000000;
        const limit = range - (range % length);
        let value;
        do {
            value = crypto.getRandomValues(new Uint32Array(1))[0];
        } while (value >= limit);
        return value % length;
    }

    function randomPassword() {
        const all = charSets.join('');
        const chars = charSets.map((set) => set[randomIndex(set.length)]);
        while (chars.length < 16) chars.push(all[randomIndex(all.length)]);
        // Shuffled, so the one of each kind aren't always first
        for (let i = chars.length - 1; i > 0; i--) {
            const j = randomIndex(i + 1);
            [chars[i], chars[j]] = [chars[j], chars[i]];
        }
        return chars.join('');
    }

    generate.hidden = false;
    generate.addEventListener('click', () => {
        input.value = randomPassword();
        input.focus();
        input.select();
    });
})();

// Background (admin/chat.php): the preview shows the gradient from the two colors, going the way the picked arrow points.
// Set from here, as the site's content security policy doesn't allow style attributes.
(() => {
    const preview = document.querySelector('[data-background-preview]');
    if (!preview) return;
    const form = preview.closest('form');

    function showPreview() {
        const { color1, color2, angle } = form.elements;
        preview.style.backgroundImage = `linear-gradient(${angle.value}deg, ${color1.value}, ${color2.value})`;
    }

    showPreview();
    form.addEventListener('input', showPreview);
})();

// Pictures in the long lists (the users and chats on admin/index.php, admin/banned.php): each picture comes from a PHP
// script (otherPages/avatar.php, chat/icon.php, admin/bannedPicture.php), and the hosting only runs 10 PHP requests at
// the same time for the whole site - the rest get "508 Resource Limit Is Reached" instead, and so does anyone else on
// the site at that moment. So the pictures (data-queued-picture holds the address) load PICTURES_AT_ONCE at a time,
// the ones on screen first, and one that fails anyway is tried again a bit later, up to PICTURE_TRIES times.
(() => {
    const pictures = [...document.querySelectorAll('img[data-queued-picture]')];
    if (pictures.length === 0) return;

    const PICTURES_AT_ONCE = 2;
    const PICTURE_TRIES = 5;
    const PICTURE_RETRY_MS = 1500; // doubled after each failed try

    const waiting = []; // on screen (or close to it), not loaded yet
    let loading = 0;

    // Resolves once the picture has loaded, or after its last try failed
    function loadPicture(img) {
        return new Promise((resolve) => {
            let tries = 0;
            const tryLoad = () => {
                tries++;
                img.removeAttribute('src'); // so setting the same address again really sends a new request
                img.src = img.dataset.queuedPicture;
            };
            img.addEventListener('load', resolve);
            img.addEventListener('error', () => {
                if (tries >= PICTURE_TRIES) {
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
            loading++;
            loadPicture(waiting.shift()).then(() => {
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
            waiting.push(entry.target);
        }
        next();
    }, { rootMargin: '200px' });
    for (const img of pictures) {
        observer.observe(img);
    }
})();
