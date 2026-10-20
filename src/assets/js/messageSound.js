// The sound for a new message, made here rather than loaded: two soft notes going up, rounder and quieter than the
// chess and Matador pings so they can be told apart. Plays on
//     document.dispatchEvent(new CustomEvent('message:sound'));
// from notifications.js (new messages came in and the sound is turned on in settings) and settings.js (ticking the
// box, so the user hears what it sounds like). navPanel.php adds it next to notifications.js, and on pages that set
// $navMessageSound (the settings page).
// Browsers only let a page play sound once it's been clicked or typed in, so the first click on the page wakes it up.
// Until then a new message is silent.
(() => {
    let audio = null;

    function wakeAudio() {
        try {
            audio ??= new AudioContext();
            if (audio.state === 'suspended') audio.resume();
        } catch {
            audio = null; // no Web Audio: no sound
        }
    }

    function chime() {
        wakeAudio();
        if (audio === null) return;
        const now = audio.currentTime;
        [[587.33, 0], [880, 0.09]].forEach(([frequency, after]) => {
            const tone = audio.createOscillator();
            const volume = audio.createGain();
            tone.type = 'triangle';
            tone.frequency.value = frequency;
            volume.gain.setValueAtTime(0.0001, now + after);
            volume.gain.exponentialRampToValueAtTime(0.18, now + after + 0.015);
            volume.gain.exponentialRampToValueAtTime(0.0001, now + after + 0.4);
            tone.connect(volume).connect(audio.destination);
            tone.start(now + after);
            tone.stop(now + after + 0.45);
        });
    }

    document.addEventListener('message:sound', chime);
    document.addEventListener('pointerdown', wakeAudio, { once: true });
    document.addEventListener('keydown', wakeAudio, { once: true });
})();
