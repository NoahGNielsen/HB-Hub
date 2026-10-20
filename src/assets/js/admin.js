// Admin panel (admin/user.php, admin/chat.php, admin/banned.php and admin/feedback.php): before any of its forms is sent,
// a popup asks "are you sure?" (the form's data-confirm, like "Ban Name?", or the button's own text) and lets the admin type
// a reason, which is sent along as the form's `reason` field and kept in the admin log with the action (adminLog.php).
// Generate fills in a random password for Reset password, shown so it can be passed on to the user,
// and the chat's Background card shows the gradient as it's picked. (The pictures in the lists load two at a time, from pictureQueue.js.)
// Without JS the forms are sent straight away with no reason, the password has to be typed in and there's no preview.
(() => {
    const main = document.querySelector('main.admin');
    if (!main || typeof HTMLDialogElement !== 'function') return;

    // Inside main, so it gets the settings page's fields and buttons (settings.css)
    const dialog = document.createElement('dialog');
    dialog.className = 'admin-reason';
    dialog.setAttribute('aria-labelledby', 'admin-reason-title');
    dialog.innerHTML = `
        <form class="admin-reason-form" method="dialog">
            <h2 class="admin-reason-title" id="admin-reason-title"></h2>
            <div class="settings-field">
                <label class="settings-label" for="admin-reason-text">Reason <span class="settings-optional">(optional)</span></label>
                <textarea id="admin-reason-text" rows="3" maxlength="500" aria-describedby="admin-reason-hint"></textarea>
                <p class="settings-hint" id="admin-reason-hint">Why you're doing it. It's saved in the admin log with what you did.</p>
            </div>
            <div class="settings-actions">
                <button type="button" class="settings-btn" data-reason-cancel>Cancel</button>
                <button type="submit" class="settings-btn" data-reason-ok></button>
            </div>
        </form>`;
    main.append(dialog);
    const title = dialog.querySelector('.admin-reason-title');
    const text = dialog.querySelector('textarea');
    const ok = dialog.querySelector('[data-reason-ok]');

    let pending = null; // { form, submitter } waiting for the popup

    // Capture, so it runs before anything else listening for the form being sent
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method !== 'post' || !main.contains(form) || form.dataset.reasonGiven === '1') return;
        event.preventDefault();

        const submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : null;
        const label = submitter?.textContent.trim() || 'OK';
        pending = { form, submitter };
        title.textContent = form.dataset.confirm || `${label}?`;
        ok.textContent = label;
        const isDanger = submitter?.classList.contains('settings-btn-danger') ?? false;
        ok.className = `settings-btn ${isDanger ? 'settings-btn-danger settings-btn-solid' : 'settings-btn-primary'}`;
        text.value = '';
        dialog.showModal();
        text.focus();
    }, true);

    dialog.querySelector('[data-reason-cancel]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { pending = null; });

    dialog.querySelector('form').addEventListener('submit', (event) => {
        event.preventDefault();
        if (!pending) return;
        const { form, submitter } = pending;
        dialog.close();

        let field = form.querySelector('input[name="reason"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = 'reason';
            form.append(field);
        }
        field.value = text.value.trim();

        // Sent again with the same button (like Add or Remove chips), this time straight through
        form.dataset.reasonGiven = '1';
        if (submitter) form.requestSubmit(submitter); else form.requestSubmit();
        delete form.dataset.reasonGiven;
    });
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
