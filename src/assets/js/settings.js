// Settings page: draws the QR code for setting up two-factor login, shows how strong a new password is, asks the browser for permission
// to show notifications once they're turned on, and asks "are you sure?" before deleting the account.
// Without JS the 2FA key can still be typed in by hand, and deleting still needs the password.
(() => {
    for (const form of document.querySelectorAll('form[data-confirm]')) {
        form.addEventListener('submit', (event) => {
            if (!confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
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
