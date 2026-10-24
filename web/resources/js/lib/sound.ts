// A short, soft notification sound (two notes), made in the browser: no audio file to download.
// Phones only allow sound after the first tap on the page, so the audio is unlocked on the first touch / click.
let ctx: AudioContext | null = null;

function audio(): AudioContext | null {
    if (ctx) return ctx;
    const AC = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!AC) return null;
    ctx = new AC();
    return ctx;
}

/** Call once at start: the first tap anywhere unlocks sound on iPhone / Android. */
export function unlockSound() {
    const unlock = () => {
        const a = audio();
        if (a && a.state === 'suspended') a.resume().catch(() => {});
        window.removeEventListener('pointerdown', unlock);
    };
    window.addEventListener('pointerdown', unlock);
}

/** "Ding-ding": a new notice or chat message. Silent when the page is in the background or sound was never unlocked. */
export function ping() {
    const a = audio();
    if (!a || a.state !== 'running' || document.visibilityState !== 'visible') return;
    const now = a.currentTime;
    [
        [880, 0],
        [1318.5, 0.12],
    ].forEach(([freq, at]) => {
        const osc = a.createOscillator();
        const gain = a.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0, now + at);
        gain.gain.linearRampToValueAtTime(0.18, now + at + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + at + 0.35);
        osc.connect(gain).connect(a.destination);
        osc.start(now + at);
        osc.stop(now + at + 0.4);
    });
    // a light tap on the phone too, inside Telegram
    (window as unknown as { Telegram?: { WebApp?: { HapticFeedback?: { notificationOccurred(t: string): void } } } }).Telegram?.WebApp?.HapticFeedback?.notificationOccurred('success');
}
