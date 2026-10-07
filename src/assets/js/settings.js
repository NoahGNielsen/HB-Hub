// Settings page: draws the QR code for setting up two-factor login, and asks the browser for permission
// to show notifications once they're turned on. Without JS the 2FA key can still be typed in by hand.
(() => {
    // qrcode.js (assets/js/vendor) is only loaded while setting up two-factor login
    const qrImage = document.querySelector('.settings-qr[data-qr]');
    if (qrImage && typeof qrcode === 'function') {
        const qr = qrcode(0, 'M'); // smallest size that fits, medium error correction
        qr.addData(qrImage.dataset.qr);
        qr.make();
        qrImage.src = qr.createDataURL(6, 24); // 6px per module, with the 4 module quiet zone scanners need
        qrImage.hidden = false;
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
