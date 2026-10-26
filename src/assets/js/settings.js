// Settings page: hides the field of study for Not HTX, shows the profile banner being picked and gets a banner picture
// ready (JPEG XL), draws the QR code for setting up two-factor login, shows how strong a new password is, asks the browser for permission
// to show notifications once they're turned on, plays the message sound when it's turned on, and asks "are you sure?"
// before deleting the account.
// The status's emoji row is statusForm.js, as the popup on your own profile has it too.
// Without JS the 2FA key can still be typed in by hand, and deleting still needs the password.
(() => {
    for (const form of document.querySelectorAll('form[data-confirm]')) {
        form.addEventListener('submit', (event) => {
            if (!confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
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
    // file input in place of the original, as the server only takes JPEG XL. Save waits until it's ready.
    const banner = document.querySelector('[data-banner]');
    if (banner) {
        const form = banner.closest('form');
        const preview = banner.querySelector('[data-banner-preview]');
        const colors = banner.querySelector('[data-banner-colors]');
        const upload = banner.querySelector('[data-banner-upload]');
        const input = banner.querySelector('[data-banner-input]'); // not there while banner pictures are off
        const status = banner.querySelector('[data-banner-status]');
        const saveButton = form.querySelector('[data-profile-save]');
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

        input?.addEventListener('change', async () => {
            const file = input.files[0];
            const id = ++converting;
            if (picked) URL.revokeObjectURL(picked.url);
            picked = null;
            if (!file) {
                setStatus('');
                saveButton.disabled = false;
                show();
                return;
            }
            picked = { url: URL.createObjectURL(file) };
            show();
            saveButton.disabled = true;
            setStatus('Getting your picture ready…');
            try {
                const buffer = await bannerJxlEncode(banner.dataset.jxlWorker, file, Number(banner.dataset.bannerMaxSide));
                if (id !== converting) return;
                if (buffer.byteLength > maxBytes) throw new Error('tooBig');
                const jxl = new File([buffer], 'banner.jxl', { type: 'image/jxl' });
                const files = new DataTransfer();
                files.items.add(jxl);
                input.files = files.files; // doesn't fire change again
                const tickets = Math.max(1, Math.ceil(jxl.size / ticketBytes));
                setStatus(`Ready: ${sizeLabel(jxl.size)}, takes ${tickets} ${tickets === 1 ? 'ticket' : 'tickets'}. Save profile to use it.`);
            } catch (error) {
                if (id !== converting) return;
                input.value = '';
                URL.revokeObjectURL(picked.url);
                picked = null;
                show();
                setStatus(error.message === 'tooBig'
                    ? 'That picture is too big, even made smaller. Please choose another one.'
                    : "That picture couldn't be got ready. Please try another one.", true);
            }
            saveButton.disabled = false;
        });

        form.addEventListener('input', show);
        form.addEventListener('change', show);
        show();
    }

    // One JPEG XL job (chatJxlWorker.js) in a worker of its own, which is stopped again once it's done
    function bannerJxlEncode(url, file, maxSide) {
        return new Promise((resolve, reject) => {
            const worker = new Worker(url, { type: 'module' });
            worker.addEventListener('message', ({ data }) => {
                worker.terminate();
                if (data.error) reject(new Error(data.error));
                else resolve(data.buffer);
            });
            worker.addEventListener('error', () => {
                worker.terminate();
                reject(new Error('workerFailed'));
            });
            worker.postMessage({ id: 1, type: 'encode', file, maxSide });
        });
    }

    // The message sound plays when its box is ticked, so the user hears what it sounds like (assets/js/messageSound.js)
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

    // Asked straight away while ticking the box, as browsers only allow asking right after a click
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
