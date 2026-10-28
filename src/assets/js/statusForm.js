// The status fields (assets/php/profile.php), in settings (otherPages/settings.php) and in the popup on your own profile
// (otherPages/profile.php). Each [data-status-picks] box gets a row of emoji to pick from (any other one can be typed in)
// that fills the [data-status-emoji] input in the same form, and the form's [data-status-text] stops at its last line
// (data-max-lines). Without JS the emoji is just typed in.
(() => {
    const EMOJI = ['😀', '📚', '🎮', '💻', '🎧', '😴', '🤒', '🍕', '⚽', '🎉', '🔥', '❤️'];

    /**
     * One button in the row: puts $value in the emoji input, '' for none.
     */
    const pickButton = (emojiInput, text, value, label) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = text;
        button.setAttribute('aria-label', label);
        button.addEventListener('click', () => {
            emojiInput.value = value;
            emojiInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
        return button;
    };

    for (const picks of document.querySelectorAll('[data-status-picks]')) {
        const form = picks.closest('form');
        const emojiInput = form?.querySelector('[data-status-emoji]');
        if (!emojiInput) continue;
        for (const emoji of EMOJI) {
            picks.append(pickButton(emojiInput, emoji, emoji, `Use ${emoji}`));
        }
        picks.append(pickButton(emojiInput, '×', '', 'No emoji'));
        picks.hidden = false;

        const text = form.querySelector('[data-status-text]');
        const maxLines = Number(text?.dataset.maxLines);
        text?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && text.value.split('\n').length >= maxLines) event.preventDefault();
        });
    }
})();
