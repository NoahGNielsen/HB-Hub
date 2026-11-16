// Settings page (otherPages/settings.php): saves the settings by themselves as they're changed (autosave, below),
// hides the field of study for Not HTX, shows the profile picture and banner being picked and gets a banner picture
// ready (JPEG XL), draws the QR code for setting up two-factor login, shows how strong a new password is, marks the
// list's row for the panel showing and keeps what the rows say up to date, asks the browser for permission to
// show notifications once they're turned on, plays the message sound when it's turned on, and asks "are you sure?"
// before unlinking Discord, logging out everywhere and deleting the account.
// The status's emoji row is statusForm.js, as the popup on your own profile has it too.
// Without JS the autosave forms have a Save button each (in <noscript>), the 2FA key can still be typed in by hand,
// and deleting still needs the password.
(() => {
    for (const form of document.querySelectorAll('form[data-confirm]')) {
        form.addEventListener('submit', (event) => {
            if (!confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    }

    // ---------- The list and its panels ----------
    // Which panel shows is up to the CSS (:target, see the top of settings.php). Here: the row for the panel showing is
    // marked (on a wide screen, where the list stays next to it), and "‹ Settings" goes back in the history when it was
    // the list that opened the panel, so going back and forth doesn't pile up history.
    const app = document.querySelector('[data-settings-app]');
    if (app) {
        const rows = [...app.querySelectorAll('a[data-settings-row]')];
        let openedFromList = false;

        const markRow = () => {
            for (const row of rows) {
                const panel = document.getElementById(row.hash.slice(1));
                const isShowing = panel !== null && panel.getClientRects().length > 0; // display: none has none
                row.classList.toggle('is-active', isShowing);
                if (isShowing) row.setAttribute('aria-current', 'true');
                else row.removeAttribute('aria-current');
            }
        };

        for (const row of rows) {
            // Only from the list itself (no panel picked), so going back never lands on another panel
            row.addEventListener('click', () => { openedFromList = location.hash.length <= 1; });
        }
        for (const back of app.querySelectorAll('[data-settings-back]')) {
            back.addEventListener('click', (event) => {
                if (!openedFromList) return; // came straight to the panel from a link: on to the list (href="#")
                event.preventDefault();
                openedFromList = false;
                history.back();
            });
        }
        window.addEventListener('hashchange', markRow);
        window.addEventListener('resize', markRow);
        markRow();
    }

    // Profile: the field of study is only asked for when a year at HTX is picked, not for Not HTX.
    // Turned off otherwise, so it isn't checked or sent.
    const classYear = document.querySelector('[data-class-year]');
    if (classYear) {
        const classField = document.querySelector('[data-class-field]');
        const classSelect = classField.querySelector('select');
        const updateClassField = () => {
            const isHtx = classYear.value !== '' && classYear.value !== 'none';
            classField.hidden = !isHtx;
            classSelect.disabled = !isHtx;
            classSelect.required = isHtx;
        };
        classYear.addEventListener('change', updateClassField);
        updateClassField();
    }

    // ---------- Autosave ----------
    // Every form[data-autosave] is sent by itself (form-encoded, Accept: application/json) once it's changed: straight
    // away for a switch, a picker or a file, and SAVE_DELAY after the last key press for text (or once the field is
    // left, for [data-save-on-change] ones like the name). One send at a time per form; a change made meanwhile is
    // sent right after. The answer (see the top of settings.php):
    // - {"ok": true, ...}: saved. The panel's [data-save-state] says so (every panel the form is in, for the profile).
    // - 422 {"errors": {field: message}}: shown in the form's [data-error-for] boxes, and nothing on the form was saved.
    // - {"redirect": url}: off to there (a banned picture).
    // Each file is only sent once, worked or not, so a wrong one can't keep the rest of the form from being saved.
    // Events on the form, for the parts below:
    // - autosave:data (detail: the FormData), just before sending, to change what's sent
    // - autosave:done (detail: { ok, answer }), once the answer is in
    // - autosave:now, sent to it to save straight away (the banner, once its picture is ready)
    // While the form has data-busy (a banner picture being got ready) it waits, and saves once autosave:now comes.
    const SAVE_DELAY = 800;
    const isTyped = (field) => field.matches('textarea, input[type="text"], input[type="search"], input[type="color"]');

    function autosave(form) {
        // Where the form goes. Not form.action, as every form here has an <input name="action">, which that gives
        // instead (and fetch() would then post to "/[object HTMLInputElement]"). The #card part is dropped by fetch.
        const url = form.getAttribute('action');
        // The profile form holds its three panels, the others sit in theirs
        const states = [
            ...form.querySelectorAll('[data-save-state]'),
            ...(form.closest('[data-settings-panel]')?.querySelectorAll('[data-save-state]') ?? []),
        ];
        let timer = 0;
        let sending = false;
        let again = false; // changed while sending or busy, so it's sent again after
        let lastSent = ''; // what was last saved (without the files), so the same thing isn't sent twice

        const setState = (name, text) => {
            for (const state of states) {
                state.dataset.state = name;
                state.textContent = text;
            }
        };

        function formData() {
            const data = new FormData(form);
            form.dispatchEvent(new CustomEvent('autosave:data', { detail: data }));
            return data;
        }

        const files = (data) => [...data.values()].filter((value) => value instanceof File && value.size > 0);
        const withoutFiles = (data) => new URLSearchParams([...data.entries()].filter(([, value]) => !(value instanceof File))).toString();

        function schedule(delay) {
            clearTimeout(timer);
            timer = setTimeout(send, delay);
        }

        async function send() {
            clearTimeout(timer);
            timer = 0;
            if (sending || 'busy' in form.dataset) {
                again = true;
                return;
            }
            const data = formData();
            const sentFiles = files(data);
            const text = withoutFiles(data);
            if (sentFiles.length === 0 && text === lastSent) {
                return; // nothing new
            }

            sending = true;
            again = false;
            setState('saving', 'Saving…');
            let answer = null;
            let ok = false;
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: data,
                    headers: { Accept: 'application/json' },
                    keepalive: sentFiles.length === 0, // so it still gets there when the page is left right after
                });
                answer = await response.json();
                ok = response.ok && answer.ok === true;
            } catch {
                answer = null; // offline, or not JSON
            }
            sending = false;

            if (answer?.redirect) {
                location.href = answer.redirect;
                return;
            }
            if (answer) {
                for (const input of form.querySelectorAll('input[type="file"]')) {
                    if (input.files[0] && sentFiles.includes(input.files[0])) input.value = '';
                }
                for (const slot of form.querySelectorAll('[data-error-for]')) {
                    const message = answer.errors?.[slot.dataset.errorFor] ?? '';
                    slot.textContent = message;
                    slot.hidden = message === '';
                }
                if (ok) lastSent = text;
                form.dispatchEvent(new CustomEvent('autosave:done', { detail: { ok, answer } }));
            }
            if (ok) setState('saved', 'Saved');
            else if (answer) setState('failed', 'Not saved, see below');
            else setState('failed', 'Couldn\'t save. Check your connection.');

            if (again) send();
        }

        form.addEventListener('input', (event) => {
            if (isTyped(event.target) && !event.target.matches('[data-save-on-change]')) schedule(SAVE_DELAY);
        });
        form.addEventListener('change', (event) => {
            if (event.target.matches('[data-banner-input], [data-banner-check]')) return; // the banner says when it's ready
            schedule(0);
        });
        form.addEventListener('autosave:now', () => schedule(0));
        // Enter in a text field
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            send();
        });
        // Typed but not sent yet when the page is left: sent anyway (keepalive takes no files, and they're sent straight away)
        window.addEventListener('pagehide', () => {
            if (timer === 0 && !again) return;
            const data = formData();
            for (const [key, value] of [...data.entries()]) {
                if (value instanceof File) data.delete(key);
            }
            fetch(url, { method: 'POST', body: data, headers: { Accept: 'application/json' }, keepalive: true }).catch(() => {});
        });

        lastSent = withoutFiles(formData()); // as the page came, so a change that's undone again isn't sent
    }

    for (const form of document.querySelectorAll('form[data-autosave]')) {
        autosave(form);
    }

    // ---------- What the list's rows say ----------
    // Once a form is saved, its rows in the list ([data-summary]) say what it's set to now, worked out the same way
    // as settings.php does for the page.
    const setSummary = (key, text, emptyText = null) => {
        for (const summary of document.querySelectorAll(`[data-summary="${key}"]`)) {
            summary.textContent = text || emptyText || '';
            summary.hidden = !summary.textContent;
        }
    };
    const checkedLabel = (form, name) => form.querySelector(`input[name="${name}"]:checked`)?.closest('label')?.textContent.trim() ?? '';

    const summarize = (form, answer) => {
        const action = form.elements.action?.value;
        if (action === 'profile') {
            const { year, statusEmoji, statusText } = form.elements;
            setSummary('look', answer.bannerUrl ? 'Picture banner' : 'Color banner');
            let classText = { '': 'No class yet', none: 'Not HTX', graduate: 'Graduate' }[year.value] ?? `${year.value}. year`;
            if (year.value !== 'none' && form.elements.class.value) classText += ` · ${form.elements.class.value}`;
            setSummary('profile', classText);
            const status = `${statusEmoji.value} ${statusText.value.replace(/\n/g, ' ')}`.trim();
            setSummary('status', status);
            setSummary('statusRow', status, 'None');
        } else if (action === 'notifications') {
            const on = form.elements.notifications.checked;
            const sound = form.elements.notificationSound.checked;
            setSummary('notifications', on && sound ? 'Notifications and sound' : on ? 'Notifications' : sound ? 'Sound' : 'Off');
        } else if (action === 'block') {
            // An admin's block stays what the row says, whatever is picked
            const row = document.querySelector('[data-summary="block"]');
            if (row?.textContent !== 'Blocked by an admin') setSummary('block', checkedLabel(form, 'block'));
        } else if (action === 'improveSite') {
            setSummary('improve', form.elements.improveSite.checked ? 'On' : 'Off');
        }
    };
    for (const form of document.querySelectorAll('form[data-autosave]')) {
        form.addEventListener('autosave:done', ({ detail: { ok, answer } }) => {
            if (ok) summarize(form, answer);
        });
    }

    // Gambling and Games: a looser block that waits says until when
    const blockPending = document.querySelector('[data-block-pending]');
    blockPending?.closest('form').addEventListener('autosave:done', ({ detail: { ok, answer } }) => {
        if (!ok) return;
        blockPending.textContent = answer.notice ?? '';
        blockPending.hidden = !answer.notice;
    });

    // ---------- Profile ----------
    // The picture is shown straight away when one is picked, and the saved one again if it wasn't taken. Once saved,
    // the picture, the name at the top of the list, the free tickets and the status's "Clear after" picker are what the
    // server says they are now.
    const profileForm = document.querySelector('[data-profile-form]');
    if (profileForm) {
        const avatarInput = profileForm.querySelector('[data-avatar-input]');
        const avatarRemove = profileForm.querySelector('[data-avatar-remove]');
        const savedAvatar = () => document.querySelector('[data-avatar-picture]:not([hidden])')?.getAttribute('src') || null;
        let avatarUrl = savedAvatar();
        let avatarPreview = null; // object URL of the picked one

        // At the top of the list too unless onlyHere, which is the preview in Picture and banner
        const showAvatar = (url, onlyHere = false) => {
            const scope = onlyHere ? profileForm : document;
            for (const picture of scope.querySelectorAll('[data-avatar-picture]')) {
                if (url) picture.src = url;
                picture.hidden = !url;
            }
            for (const initial of scope.querySelectorAll('[data-avatar-initial]')) {
                initial.hidden = Boolean(url);
            }
        };

        avatarInput?.addEventListener('change', () => {
            if (avatarPreview) URL.revokeObjectURL(avatarPreview);
            avatarPreview = avatarInput.files[0] ? URL.createObjectURL(avatarInput.files[0]) : null;
            showAvatar(avatarPreview ?? avatarUrl, true);
        });

        profileForm.addEventListener('autosave:done', ({ detail: { ok, answer } }) => {
            if (ok) {
                avatarUrl = answer.avatarUrl;
                if (avatarRemove) avatarRemove.hidden = !avatarUrl;
                const name = document.querySelector('[data-settings-name]');
                if (name) name.textContent = answer.name;
                const tickets = profileForm.querySelector('[data-tickets-free]');
                if (tickets) tickets.textContent = answer.ticketsFree;
                // The <option>s from hbHubProfileStatusClearOptions() (assets/php/profile.php), which escapes them
                const clear = profileForm.querySelector('[data-status-clear]');
                if (clear) clear.innerHTML = answer.statusClearOptions;
            }
            if (avatarPreview && !avatarInput.files.length) {
                URL.revokeObjectURL(avatarPreview);
                avatarPreview = null;
            }
            showAvatar(avatarPreview ?? avatarUrl, !ok);
        });
    }

    // qrcode.js (assets/js/vendor) is only loaded while setting up two-factor login
    const qrImage = document.querySelector('.settings-qr[data-qr]');
    if (qrImage && typeof qrcode === 'function') {
        const qr = qrcode(0, 'M'); // smallest size that fits, medium error correction
        qr.addData(qrImage.dataset.qr);
        qr.make();
        qrImage.src = qr.createDataURL(6, 24); // 6px per module, with the 4 module quiet zone scanners need
        qrImage.hidden = false;
    }

    // New password: the strength meter and rules from onboarding.js (the server checks them too)
    const newPassword = document.querySelector('#settings-new-password');
    if (newPassword) {
        const newPasswordConfirm = document.querySelector('#settings-new-password-confirm');
        const strength = document.querySelector('.settings-strength');
        const strengthLabel = strength.querySelector('.settings-strength-label');
        const strengthBar = strength.querySelector('.settings-strength-bar');
        const passwordMinLength = Number(newPassword.dataset.minLength) || 1;
        const passwordCharTypes = Number(newPassword.dataset.charTypes) || 0;

        // Common passwords list - only downloaded once the field is used
        let commonPasswords = null;
        let commonPasswordsRequested = false;
        function loadCommonPasswords() {
            if (commonPasswordsRequested) return;
            commonPasswordsRequested = true;
            fetch(newPassword.dataset.commonPasswords)
                .then((response) => (response.ok ? response.text() : Promise.reject(response.status)))
                .then((text) => {
                    commonPasswords = new Set(text.split(/\r?\n/).map((line) => line.toLowerCase()));
                    updatePassword();
                })
                .catch(() => { commonPasswordsRequested = false; });
        }

        function updatePassword() {
            const value = newPassword.value;
            const hasLength = value.length >= passwordMinLength;
            const charTypes = /\p{Ll}/u.test(value) + /\p{Lu}/u.test(value) + /\p{N}/u.test(value) + /[^\p{Ll}\p{Lu}\p{N}]/u.test(value);
            const isCommon = commonPasswords !== null && commonPasswords.has(value.toLowerCase());

            let message = '';
            if (value === '') message = 'Please choose a password.';
            else if (!hasLength) message = `Your new password needs to be at least ${passwordMinLength} characters.`;
            else if (charTypes < passwordCharTypes) {
                message = passwordCharTypes === 4
                    ? 'Your new password needs lowercase letters, uppercase letters, numbers and special characters.'
                    : `Your new password needs at least ${passwordCharTypes} of: lowercase letters, uppercase letters, numbers and special characters.`;
            } else if (isCommon) message = 'That password is too common. Please choose another one.';
            newPassword.setCustomValidity(message);

            // Meter is the same whatever the rules are: one point per character type, plus one each for 8+ and 12+ characters
            const points = charTypes + (value.length >= 8) + (value.length >= 12);
            let level = 'strong';
            if (value === '') level = 'none';
            else if (isCommon) level = 'common';
            else if (message) level = 'weak';
            else if (points < 4) level = 'fair';
            else if (points < 6) level = 'good';

            strength.dataset.level = level;
            strength.hidden = level === 'none';
            strengthBar.style.width = level === 'none' ? '0%' : `${((isCommon ? 1 : Math.max(points, 1)) / 6) * 100}%`;
            strengthLabel.textContent = {
                none: '',
                common: 'Too common - this password is on a list of leaked passwords',
                weak: 'Too weak',
                fair: 'Weak',
                good: 'Good',
                strong: 'Strong',
            }[level];

            checkPasswordsMatch();
        }

        function checkPasswordsMatch() {
            newPasswordConfirm.setCustomValidity(newPasswordConfirm.value === newPassword.value ? '' : 'The passwords don\'t match.');
        }

        newPassword.addEventListener('focus', loadCommonPasswords);
        newPassword.addEventListener('input', updatePassword);
        newPasswordConfirm.addEventListener('input', checkPasswordsMatch);
        updatePassword();
    }

    // Profile banner: Colors shows the gradient pickers, Picture the file input, and the preview above shows whichever
    // is picked (set from here, as the content security policy doesn't allow style attributes). A new picture is
    // made smaller and turned into JPEG XL by the JPEG XL worker (chatJxlWorker.js) straight away, and put back in the
    // file input in place of the original, as the server only takes JPEG XL. The form waits (data-busy) until it's
    // ready, and is then saved.
    // A small PNG of it goes in the hidden data-banner-check input too: the server can't read JPEG XL, so that's what it
    // compares with the banned pictures list (assets/php/bannedAvatars.php).
    const banner = document.querySelector('[data-banner]');
    if (banner) {
        const form = banner.closest('form');
        const preview = banner.querySelector('[data-banner-preview]');
        const colors = banner.querySelector('[data-banner-colors]');
        const upload = banner.querySelector('[data-banner-upload]');
        const input = banner.querySelector('[data-banner-input]'); // not there while banner pictures are off
        const checkInput = banner.querySelector('[data-banner-check]');
        const status = banner.querySelector('[data-banner-status]');
        const maxBytes = Number(banner.dataset.bannerMaxBytes);
        const ticketBytes = Number(banner.dataset.ticketBytes);
        let picked = null; // { url } of the new picture's original, for the preview
        let converting = 0; // which conversion is going on, so an older one that finishes late is ignored

        const sizeLabel = (bytes) => (bytes < 1024 * 1024
            ? `${Math.max(1, Math.round(bytes / 1024))} KB`
            : `${(bytes / 1024 / 1024).toFixed(1).replace(/\.0$/, '')} MB`);

        function show() {
            const type = form.elements.bannerType.value;
            colors.hidden = type !== 'colors';
            upload.hidden = type !== 'picture';
            // The saved picture (an <img>, or the canvas jxlImages.js drew it into) or the new one
            const current = preview.querySelector('[data-banner-current]');
            let newPicture = preview.querySelector('[data-banner-new]');
            if (type === 'picture' && picked) {
                if (!newPicture) {
                    newPicture = document.createElement('img');
                    newPicture.className = 'settings-banner-picture';
                    newPicture.alt = '';
                    newPicture.dataset.bannerNew = '';
                    preview.append(newPicture);
                }
                newPicture.src = picked.url;
            }
            if (newPicture) newPicture.hidden = type !== 'picture' || !picked;
            if (current) current.hidden = type !== 'picture' || picked !== null;
            const { bannerColor1, bannerColor2, bannerAngle } = form.elements;
            preview.style.backgroundImage = type === 'colors'
                ? `linear-gradient(${bannerAngle.value}deg, ${bannerColor1.value}, ${bannerColor2.value})` : '';
        }

        function setStatus(text, isError = false) {
            status.textContent = text;
            status.classList.toggle('settings-error', isError);
        }

        const ready = () => {
            delete form.dataset.busy;
            form.dispatchEvent(new CustomEvent('autosave:now'));
        };

        input?.addEventListener('change', async () => {
            const file = input.files[0];
            const id = ++converting;
            if (picked) URL.revokeObjectURL(picked.url);
            picked = null;
            if (checkInput) checkInput.value = '';
            if (!file) {
                setStatus('');
                show();
                ready();
                return;
            }
            picked = { url: URL.createObjectURL(file) };
            show();
            form.dataset.busy = '';
            setStatus('Getting your picture ready…');
            try {
                const buffer = await bannerJxlJob(banner.dataset.jxlWorker, { type: 'encode', file, maxSide: Number(banner.dataset.bannerMaxSide) });
                if (id !== converting) return;
                if (buffer.byteLength > maxBytes) throw new Error('tooBig');
                const jxl = new File([buffer], 'banner.jxl', { type: 'image/jxl' });
                // Made from the JPEG XL rather than the original, so it's the picture the server gets, whatever kind it was
                const check = await bannerCheckCopy(banner.dataset.jxlWorker, buffer.slice(0), Number(checkInput.dataset.bannerCheck));
                if (id !== converting) return;
                const files = new DataTransfer();
                files.items.add(jxl);
                input.files = files.files; // doesn't fire change again
                const checkFiles = new DataTransfer();
                checkFiles.items.add(check);
                checkInput.files = checkFiles.files;
                const tickets = Math.max(1, Math.ceil(jxl.size / ticketBytes));
                setStatus(`Saving your banner (${sizeLabel(jxl.size)}, ${tickets} ${tickets === 1 ? 'ticket' : 'tickets'})…`);
            } catch (error) {
                if (id !== converting) return;
                input.value = '';
                checkInput.value = '';
                URL.revokeObjectURL(picked.url);
                picked = null;
                show();
                setStatus(error.message === 'tooBig'
                    ? 'That picture is too big, even made smaller. Please choose another one.'
                    : "That picture couldn't be got ready. Please try another one.", true);
            }
            ready();
        });

        // Picture picked, but there's no picture yet: the colors are saved meanwhile (the server would turn it down)
        form.addEventListener('autosave:data', ({ detail: data }) => {
            if (data.get('bannerType') === 'picture' && !preview.querySelector('[data-banner-current]') && !input?.files.length) {
                data.set('bannerType', 'colors');
            }
        });

        // Once it's saved, the new picture is the saved one, and back to colors removes the saved one.
        // A new picture that wasn't taken goes again (why is in the banner's error box).
        form.addEventListener('autosave:done', ({ detail: { ok, answer } }) => {
            const wasSent = picked !== null && !input?.files.length;
            const current = preview.querySelector('[data-banner-current]');
            const newPicture = preview.querySelector('[data-banner-new]');
            if (ok && answer.bannerUrl === null) {
                current?.remove();
            } else if (ok && wasSent && newPicture) {
                current?.remove();
                delete newPicture.dataset.bannerNew;
                newPicture.dataset.bannerCurrent = '';
                picked = null; // its object URL stays, as the picture shows it
            }
            if (wasSent && picked) {
                newPicture?.remove();
                URL.revokeObjectURL(picked.url);
                picked = null;
            }
            if (wasSent || (ok && !status.classList.contains('settings-error'))) setStatus('');
            show();
        });

        form.addEventListener('input', show);
        form.addEventListener('change', show);
        show();
    }

    // One JPEG XL job (chatJxlWorker.js) in a worker of its own, which is stopped again once it's done. Gives back the
    // .jxl file's buffer for an encode, and { pixels, width, height } for a decode.
    function bannerJxlJob(url, message, transfer = []) {
        return new Promise((resolve, reject) => {
            const worker = new Worker(url, { type: 'module' });
            worker.addEventListener('message', ({ data }) => {
                worker.terminate();
                if (data.error) reject(new Error(data.error));
                else resolve(message.type === 'encode' ? data.buffer : data);
            });
            worker.addEventListener('error', () => {
                worker.terminate();
                reject(new Error('workerFailed'));
            });
            worker.postMessage({ id: 1, ...message }, transfer);
        });
    }

    // The banner's check copy for the banned pictures list: the JPEG XL in buffer as a PNG, at most maxSide on its
    // longest side. admin.js makes the same one when an admin bans a banner.
    async function bannerCheckCopy(url, buffer, maxSide) {
        const { pixels, width, height } = await bannerJxlJob(url, { type: 'decode', buffer }, [buffer]);
        const full = document.createElement('canvas');
        full.width = width;
        full.height = height;
        full.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(pixels), width, height), 0, 0);
        const scale = Math.min(1, maxSide / Math.max(width, height));
        const small = document.createElement('canvas');
        small.width = Math.max(1, Math.round(width * scale));
        small.height = Math.max(1, Math.round(height * scale));
        const context = small.getContext('2d');
        context.imageSmoothingQuality = 'high';
        context.drawImage(full, 0, 0, small.width, small.height);
        const blob = await new Promise((resolve) => small.toBlob(resolve, 'image/png'));
        if (!blob) throw new Error('checkFailed');
        return new File([blob], 'check.png', { type: 'image/png' });
    }

    // The message sound plays when its switch is turned on, so the user hears what it sounds like (assets/js/messageSound.js)
    const soundToggle = document.querySelector('[data-notification-sound-toggle]');
    soundToggle?.addEventListener('change', () => {
        if (soundToggle.checked) document.dispatchEvent(new CustomEvent('message:sound'));
    });

    const toggle = document.querySelector('[data-notifications-toggle]');
    if (!toggle) return;
    const status = document.querySelector('[data-notifications-status]');
    const allowBtn = document.querySelector('[data-notifications-allow]');
    const isSupported = 'Notification' in window;

    function showStatus() {
        let message = '';
        if (!isSupported) {
            message = 'This browser can\'t show notifications.';
        } else if (toggle.checked && Notification.permission === 'denied') {
            message = 'Your browser is blocking notifications from HB Hub. Allow them in your browser\'s site settings.';
        }
        status.textContent = message;
        status.hidden = message === '';
        // Turned on before, but this browser hasn't been asked yet
        allowBtn.hidden = !(toggle.checked && isSupported && Notification.permission === 'default');
    }

    async function askPermission() {
        if (isSupported && Notification.permission === 'default') {
            await Notification.requestPermission();
        }
        showStatus();
    }

    // Asked straight away while turning the switch on, as browsers only allow asking right after a click
    toggle.addEventListener('change', () => {
        if (toggle.checked) {
            askPermission();
        } else {
            showStatus();
        }
    });
    allowBtn.addEventListener('click', askPermission);

    showStatus();
})();
