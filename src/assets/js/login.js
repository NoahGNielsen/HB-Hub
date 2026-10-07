// Login: asks for the name first and only shows the password once a name is entered.
// Without JS both fields are shown at once.
(() => {
    const form = document.querySelector('.login');
    if (!form) return;

    const name = form.querySelector('#login-name');
    const passwordStep = form.querySelector('[data-password-step]');
    const password = form.querySelector('#login-password');
    const nextBtn = form.querySelector('[data-action="next"]');
    const submitBtn = form.querySelector('[data-action="submit"]');

    function showPassword(revealed, focus = true) {
        passwordStep.hidden = !revealed;
        nextBtn.hidden = revealed;
        submitBtn.hidden = !revealed;
        if (revealed && focus) password.focus();
    }

    function goNext() {
        // Whitespace-only counts as empty
        if (name.value.trim() === '') name.value = '';
        if (!name.checkValidity()) {
            name.reportValidity();
            return;
        }
        showPassword(true);
    }

    nextBtn.addEventListener('click', goNext);

    // Enter on the name moves on to the password instead of submitting
    name.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || !passwordStep.hidden) return;
        event.preventDefault();
        goNext();
    });

    // Log in button gets a bigger hover once a password is entered
    function updateSubmitReady() {
        submitBtn.classList.toggle('is-ready', password.value !== '');
    }
    password.addEventListener('input', updateSubmitReady);

    // Clear a server-side error once something is edited
    form.addEventListener('input', () => {
        form.querySelector('.onboarding-error')?.remove();
    });

    // Coming back with an error keeps the name, so go straight to the password
    showPassword(name.value.trim() !== '', false);
    updateSubmitReady();
})();
