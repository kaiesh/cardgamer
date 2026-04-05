/**
 * Gesture detection: double-tap, long-press.
 */
export function onDoubleTap(element, callback) {
    let lastTap = 0;
    element.addEventListener('touchend', (e) => {
        const now = Date.now();
        if (now - lastTap < 300) {
            e.preventDefault();
            callback(e);
        }
        lastTap = now;
    });
}

export function onLongPress(element, callback, duration = 500) {
    let timer = null;
    let startX, startY;

    const start = (e) => {
        const touch = e.touches ? e.touches[0] : e;
        startX = touch.clientX;
        startY = touch.clientY;
        timer = setTimeout(() => {
            callback(e);
            timer = null;
        }, duration);
    };

    const move = (e) => {
        if (!timer) return;
        const touch = e.touches ? e.touches[0] : e;
        if (Math.abs(touch.clientX - startX) > 10 || Math.abs(touch.clientY - startY) > 10) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const end = () => {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    };

    element.addEventListener('pointerdown', start);
    element.addEventListener('pointermove', move);
    element.addEventListener('pointerup', end);
    element.addEventListener('pointercancel', end);

    return () => {
        element.removeEventListener('pointerdown', start);
        element.removeEventListener('pointermove', move);
        element.removeEventListener('pointerup', end);
        element.removeEventListener('pointercancel', end);
    };
}
