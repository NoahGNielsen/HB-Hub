// Admin panel (admin/user.php): asks "are you sure?" before the forms with data-confirm (ban, delete and so on),
// and Generate fills in a random password for Reset password, shown so it can be passed on to the user.
// Without JS the forms are sent straight away, and the password has to be typed in.
(() => {
    for (const form of document.querySelectorAll('form[data-confirm]')) {
        form.addEventListener('submit', (event) => {
            if (!confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    }

    const generate = document.querySelector('[data-password-generate]');
    if (!generate) return;
    const input = document.querySelector('#admin-new-password');

    // No characters that look alike (0 O 1 l I), so it can be read out or typed in from a screen.
    // One of each kind and 16 long, so it meets the strictest password rules.
    const charSets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!?#%+-='];

    function randomIndex(length) {
        return crypto.getRandomValues(new Uint32Array(1))[0] % length;
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
