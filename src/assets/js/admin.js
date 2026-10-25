// Admin panel (admin/user.php, admin/person.php, admin/chat.php, admin/banned.php and admin/feedback.php): before any of its forms is sent,
// a popup asks "are you sure?" (the button's or the form's data-confirm, like "Ban Name?", or the button's own text) and lets the admin type
// a reason, which is sent along as the form's `reason` field and kept in the admin log with the action (adminLog.php).
// A form with data-reply-to (Reply, and mark completed on admin/feedback.php) asks for a message to that user instead,
// which has to be filled in and is sent as `reply`. Those forms are hidden until this shows them, as they need the popup.
// Generate fills in a random password for Reset password, shown so it can be passed on to the user,
// on admin/person.php the buttons that wouldn't change any ticked account are greyed out,
// and the chat's Background card shows the gradient as it's picked. On admin/crashes.php, Copy everything copies a crash report. (The pictures in the lists load two at a time, from pictureQueue.js.)
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
                <label class="settings-label" for="admin-reason-text"><span data-reason-label></span> <span class="settings-optional">(optional)</span></label>
                <textarea id="admin-reason-text" rows="3" maxlength="500" aria-describedby="admin-reason-hint"></textarea>
                <p class="settings-hint" id="admin-reason-hint"></p>
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
    const fieldLabel = dialog.querySelector('[data-reason-label]');
    const optional = dialog.querySelector('.settings-optional');
    const hint = dialog.querySelector('.settings-hint');

    let pending = null; // { form, submitter, name } waiting for the popup, name is the field the text is sent as

    main.querySelectorAll('form[data-reply-to]').forEach((form) => { form.hidden = false; });

    // Capture, so it runs before anything else listening for the form being sent
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method !== 'post' || !main.contains(form) || form.dataset.reasonGiven === '1') return;
        event.preventDefault();

        const submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : null;
        const label = submitter?.textContent.trim() || 'OK';
        const replyTo = form.dataset.replyTo;
        const isReply = replyTo !== undefined;
        pending = { form, submitter, name: isReply ? 'reply' : 'reason' };
        if (isReply) {
            title.textContent = `Reply to ${replyTo}`;
            fieldLabel.textContent = 'Message';
            hint.textContent = `${replyTo} gets it as a notification: "An admin has marked your feedback as completed with the message: …", and the feedback is marked as done. It's saved in the admin log as the reason.`;
            ok.textContent = 'Send, and mark completed';
        } else {
            // data-confirm-all instead while the "Also ... their other accounts" switch is on (admin/user.php)
            const confirmAll = form.elements.allAccounts?.checked ? form.dataset.confirmAll : undefined;
            title.textContent = submitter?.dataset.confirm || confirmAll || form.dataset.confirm || `${label}?`;
            fieldLabel.textContent = 'Reason';
            hint.textContent = 'Why you\'re doing it. It\'s saved in the admin log with what you did.';
            ok.textContent = label;
        }
        optional.hidden = isReply;
        text.required = isReply;
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
        const { form, submitter, name } = pending;
        // required lets only spaces through, which the server would turn down
        if (text.required && text.value.trim() === '') {
            text.value = '';
            text.reportValidity();
            return;
        }
        dialog.close();

        let field = form.querySelector(`input[name="${name}"]`);
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = name;
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

// Person page (admin/person.php): a button is greyed out while none of its form's ticked accounts would be changed by
// it. data-person-needs on the button says what a ticked account has to be (from its tick box's data-person-state):
// "banned" for Unban, "!banned" (not banned) for Ban, and empty for any ticked account at all. The server checks
// the same thing again, so without JS the buttons just stay on.
(() => {
    const buttons = document.querySelectorAll('button[data-person-needs]');

    function update(form) {
        const ticked = [...form.querySelectorAll('input[name="accounts[]"]:checked')]
            .map((box) => box.dataset.personState.split(' '));
        for (const button of form.querySelectorAll('button[data-person-needs]')) {
            const needs = button.dataset.personNeeds;
            const wanted = needs.replace(/^!/, '');
            button.disabled = !ticked.some((state) => needs === '' || state.includes(wanted) !== needs.startsWith('!'));
        }
    }

    const forms = new Set([...buttons].map((button) => button.form));
    for (const form of forms) {
        update(form);
        form.addEventListener('change', () => update(form));
    }
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

// Crash reports (admin/crashes.php): Copy everything copies a report as text (its hidden data-crash-copy-text, made by
// adminCrashCopyText()), to paste somewhere else. The button only shows with this script, as it needs it.
(() => {
    const buttons = document.querySelectorAll('[data-crash-copy]');
    for (const button of buttons) {
        button.hidden = false;
        button.addEventListener('click', async () => {
            const text = button.parentElement.querySelector('[data-crash-copy-text]').textContent;
            try {
                await navigator.clipboard.writeText(text);
                button.textContent = 'Copied!';
            } catch {
                button.textContent = 'Couldn\'t copy'; // no clipboard access: it's all under Everything to copy by hand
            }
            setTimeout(() => (button.textContent = 'Copy everything'), 2000);
        });
    }
})();
