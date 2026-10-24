// Bottom sheets on phones (.scrim > .sheet, ≤600px) close with a downward swipe, like native ones: the sheet
// follows the finger and closes past ~110px (or a quick flick). Only from the top of the sheet's content, so
// scrolling inside a long sheet still works; never from inputs, sliders or the photo cropper ([data-noswipe]).
// Closing = a click on the scrim, which every sheet already handles (@click.self="close").
export function installSheetSwipe() {
    const phone = window.matchMedia('(max-width: 600px)');
    let sheet: HTMLElement | null = null;
    let x0 = 0;
    let y0 = 0;
    let dy = 0;
    let t0 = 0;
    let dragging = false;

    const reset = (el: HTMLElement) => {
        el.style.transition = 'transform .2s ease-out';
        el.style.transform = '';
        setTimeout(() => (el.style.transition = ''), 220);
    };

    document.addEventListener(
        'touchstart',
        (e) => {
            const target = e.target as HTMLElement;
            const s = target.closest('.sheet') as HTMLElement | null;
            sheet = null;
            if (!phone.matches || !s || !s.parentElement?.classList.contains('scrim') || e.touches.length !== 1) return;
            if (s.scrollTop > 0 || target.closest('input, textarea, select, [contenteditable], [data-noswipe]')) return;
            sheet = s;
            x0 = e.touches[0].clientX;
            y0 = e.touches[0].clientY;
            dy = 0;
            t0 = Date.now();
            dragging = false;
        },
        { passive: true },
    );

    document.addEventListener(
        'touchmove',
        (e) => {
            if (!sheet) return;
            dy = e.touches[0].clientY - y0;
            if (!dragging) {
                // a scroll up, a sideways swipe (date or emoji rows), or content scrolled meanwhile: not a close
                const dx = Math.abs(e.touches[0].clientX - x0);
                if (dy < 0 || dx > dy || sheet.scrollTop > 0) return void (sheet = null);
                if (dy < 8) return;
                dragging = true;
            }
            e.preventDefault();   // the page and the sheet must not scroll while it follows the finger
            sheet.style.transition = 'none';
            sheet.style.transform = `translateY(${Math.max(0, dy)}px)`;
        },
        { passive: false },
    );

    document.addEventListener('touchend', () => {
        const s = sheet;
        sheet = null;
        if (!s || !dragging) return;
        const flick = dy > 40 && dy / Math.max(1, Date.now() - t0) > 0.6;
        if (dy < 110 && !flick) return reset(s);
        s.style.transition = 'transform .18s ease-in';
        s.style.transform = 'translateY(110%)';
        setTimeout(() => {
            s.parentElement?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            s.style.transition = '';
            s.style.transform = '';
        }, 170);
    });
}
