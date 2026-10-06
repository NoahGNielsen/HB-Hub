// Onboarding: shows one question at a time with Back/Next.
// Without JS the form still works as one long page.
(() => {
    const form = document.querySelector('.onboarding');
    if (!form) return;

    const steps = [...form.querySelectorAll('.onboarding-step')];
    const progress = form.querySelector('.onboarding-progress');
    const counter = form.querySelector('.onboarding-counter');
    const progressBar = form.querySelector('.onboarding-progress-bar');
    const backBtn = form.querySelector('[data-action="back"]');
    const nextBtn = form.querySelector('[data-action="next"]');
    const submitBtn = form.querySelector('[data-action="submit"]');

    const yearSelect = form.querySelector('#onboarding-year');
    const classField = form.querySelector('[data-class-field]');
    const classSelect = form.querySelector('#onboarding-class');

    const avatarInput = form.querySelector('#onboarding-avatar');
    const avatarPreview = form.querySelector('.onboarding-avatar-preview');
    const avatarDefaultSrc = avatarPreview.getAttribute('src');
    const avatarRemoveBtn = form.querySelector('[data-action="remove-avatar"]');
    const avatarError = form.querySelector('[data-avatar-error]');
    const avatarTypes = avatarInput.accept.split(',');
    const avatarMaxBytes = Number(avatarInput.dataset.maxBytes);

    const description = form.querySelector('#onboarding-description');

    const password = form.querySelector('#onboarding-password');
    const passwordConfirm = form.querySelector('#onboarding-password-confirm');

    let current = 0;

    // Returns the first field in a step that isn't valid, or null
    function firstInvalidField(step) {
        for (const field of step.querySelectorAll('input, select, textarea')) {
            // Whitespace-only counts as empty
            if (field.matches('input[type="text"], textarea') && field.value.trim() === '') field.value = '';
            if (!field.checkValidity()) return field;
        }
        return null;
    }

    function showStep(index, focus = true) {
        current = index;
        steps.forEach((step, i) => { step.hidden = i !== index; });

        const isLast = index === steps.length - 1;
        backBtn.hidden = index === 0;
        nextBtn.hidden = isLast;
        submitBtn.hidden = !isLast;
        updateNextLabel();

        counter.textContent = `Step ${index + 1} of ${steps.length}`;
        progressBar.style.width = `${((index + 1) / steps.length) * 100}%`;
        if (steps[index].contains(password)) loadCommonPasswords();

        if (focus) steps[index].querySelector('input, select, textarea')?.focus();
    }

    function goNext() {
        const invalid = firstInvalidField(steps[current]);
        if (invalid) {
            invalid.reportValidity();
            return;
        }
        if (current < steps.length - 1) showStep(current + 1);
    }

    // The picture and description steps are optional, so "Next" reads "Skip" until they're filled in
    function updateNextLabel() {
        const step = steps[current];
        const skippable = (step.contains(avatarInput) && avatarInput.files.length === 0)
            || (step.contains(description) && description.value.trim() === '');
        nextBtn.textContent = skippable ? 'Skip' : 'Next';
    }

    backBtn.addEventListener('click', () => {
        if (current > 0) showStep(current - 1);
    });
    nextBtn.addEventListener('click', goNext);

    // Enter moves to the next question instead of submitting the whole form
    form.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.target.matches('textarea, button, input[type="file"]')) return;
        if (current < steps.length - 1) {
            event.preventDefault();
            goNext();
        }
    });

    form.addEventListener('submit', (event) => {
        const invalidIndex = steps.findIndex((step) => firstInvalidField(step));
        if (invalidIndex === -1) return;
        event.preventDefault();
        showStep(invalidIndex, false);
        firstInvalidField(steps[invalidIndex]).reportValidity();
    });

    // Class picker only appears once a year is picked
    function updateClassField() {
        classField.hidden = yearSelect.value === '';
    }
    yearSelect.addEventListener('change', () => {
        updateClassField();
        if (classSelect.value === '') classSelect.focus();
    });

    // Picture preview (data: URL, since the CSP doesn't allow blob: images)
    function resetAvatar() {
        avatarInput.value = '';
        avatarPreview.src = avatarDefaultSrc;
        avatarPreview.classList.add('is-placeholder');
        avatarRemoveBtn.hidden = true;
        updateNextLabel();
    }

    function showAvatarError(message) {
        avatarError.textContent = message;
        avatarError.hidden = message === '';
    }

    avatarInput.addEventListener('change', () => {
        const file = avatarInput.files[0];
        showAvatarError('');
        if (!file) {
            resetAvatar();
            return;
        }
        if (!avatarTypes.includes(file.type)) {
            resetAvatar();
            showAvatarError('Please upload a PNG, JPEG, GIF or WebP image.');
            return;
        }
        if (file.size > avatarMaxBytes) {
            resetAvatar();
            showAvatarError('That picture is too big. Please choose one under 5 MB.');
            return;
        }

        const reader = new FileReader();
        reader.addEventListener('load', () => {
            avatarPreview.src = reader.result;
            avatarPreview.classList.remove('is-placeholder');
        });
        reader.readAsDataURL(file);
        avatarRemoveBtn.hidden = false;
        updateNextLabel();
    });

    avatarRemoveBtn.addEventListener('click', () => {
        resetAvatar();
        showAvatarError('');
        avatarInput.focus();
    });

    // Live character counters
    for (const field of form.querySelectorAll('[data-counter]')) {
        const output = document.getElementById(field.dataset.counter);
        field.addEventListener('input', () => { output.textContent = field.value.length; });
    }

    // Common passwords list - only downloaded once the password step is reached (the server checks it too)
    let commonPasswords = null;
    let commonPasswordsRequested = false;
    function loadCommonPasswords() {
        if (commonPasswordsRequested) return;
        commonPasswordsRequested = true;
        fetch(form.dataset.commonPasswords)
            .then((response) => (response.ok ? response.text() : Promise.reject(response.status)))
            .then((text) => {
                commonPasswords = new Set(text.split(/\r?\n/).map((line) => line.toLowerCase()));
                updatePassword();
            })
            .catch(() => { commonPasswordsRequested = false; });
    }

    // Password strength: at least 8 characters, 3 of the 4 character types and not a common password
    const strength = form.querySelector('.onboarding-strength');
    const strengthLabel = form.querySelector('.onboarding-strength-label');
    const strengthBar = form.querySelector('.onboarding-strength-bar');
    const confirmField = form.querySelector('[data-password-confirm-field]');
    const passwordChecks = {
        length: (value) => value.length >= 8,
        lower: (value) => /\p{Ll}/u.test(value),
        upper: (value) => /\p{Lu}/u.test(value),
        number: (value) => /\p{N}/u.test(value),
        special: (value) => /[^\p{Ll}\p{Lu}\p{N}]/u.test(value),
    };

    function updatePassword() {
        const value = password.value;
        const met = {};
        for (const [name, check] of Object.entries(passwordChecks)) {
            met[name] = check(value);
            form.querySelector(`[data-check="${name}"]`).classList.toggle('is-met', met[name]);
        }
        const charTypes = met.lower + met.upper + met.number + met.special;
        const isCommon = commonPasswords !== null && commonPasswords.has(value.toLowerCase());

        let message = '';
        if (!met.length) message = 'Your password needs to be at least 8 characters.';
        else if (charTypes < 3) message = 'Your password needs at least 3 of: lowercase letters, uppercase letters, numbers and special characters.';
        else if (isCommon) message = 'That password is too common. Please choose another one.';
        password.setCustomValidity(message);

        // Meter: one point per character type, plus one each for 8+ and 12+ characters
        const points = charTypes + met.length + (value.length >= 12);
        let level = 'strong';
        if (value === '') level = 'none';
        else if (isCommon) level = 'common';
        else if (message) level = 'weak';
        else if (points < 6) level = 'good';

        strength.dataset.level = level;
        strengthBar.style.width = level === 'none' ? '0%' : `${((isCommon ? 1 : Math.max(points, 1)) / 6) * 100}%`;
        strengthLabel.textContent = {
            none: '',
            common: 'Too common - this password is on a list of leaked passwords',
            weak: 'Too weak',
            good: 'Good',
            strong: 'Strong',
        }[level];

        // Only ask for the password again once the first one is good enough
        confirmField.hidden = message !== '';
        checkPasswordsMatch();
    }

    function checkPasswordsMatch() {
        passwordConfirm.setCustomValidity(passwordConfirm.value === password.value ? '' : 'The passwords don\'t match.');
    }
    password.addEventListener('input', updatePassword);
    passwordConfirm.addEventListener('input', checkPasswordsMatch);
    description.addEventListener('input', updateNextLabel);

    // Clear a server-side error once that answer is edited
    form.addEventListener('input', (event) => {
        if (event.target === avatarInput) return;
        event.target.closest('.onboarding-step')?.querySelector('.onboarding-error')?.remove();
    });

    form.noValidate = true; // validation is done per step above
    progress.hidden = false;
    updateClassField();
    updatePassword();
    showStep(Math.min(Math.max(Number(form.dataset.startStep) || 1, 1), steps.length) - 1);
})();
