/**
 * Unified drag & drop engine (mouse + touch).
 */
export function initDrag(container, options = {}) {
    // The container acts as a global drop zone for the table
    container.addEventListener('dragover', (e) => {
        e.preventDefault();
    });

    container.addEventListener('drop', (e) => {
        e.preventDefault();
        try {
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            if (options.onDrop) {
                // Determine which zone was dropped on
                const zoneEl = e.target.closest('.zone-el');
                const playerSeat = e.target.closest('.player-seat-el');

                if (zoneEl) {
                    const zoneId = parseInt(zoneEl.dataset.zoneId);
                    const zone = options.state?.zones?.find(z => z.id === zoneId);
                    options.onDrop(data, zone);
                } else if (playerSeat) {
                    // Give to player - find player id
                    const userId = playerSeat.dataset.user;
                    const player = options.state?.players?.find(p => p.user_id === userId);
                    if (player && data.cardId) {
                        import('../api.js').then(({ api }) => {
                            api.post(`/tables/${options.tableId}/cards/action`, {
                                action: 'give_to_player',
                                card_ids: [data.cardId],
                                target_player_id: player.id,
                            }).catch(console.error);
                        });
                    }
                }
            }
        } catch (err) {}
    });
}

/**
 * Touch-based drag for mobile.
 */
export function initTouchDrag(element, callbacks = {}) {
    let dragEl = null;
    let ghost = null;
    let startX, startY;

    element.addEventListener('touchstart', (e) => {
        const card = e.target.closest('.card');
        if (!card) return;

        dragEl = card;
        const touch = e.touches[0];
        startX = touch.clientX;
        startY = touch.clientY;

        // Create ghost
        ghost = card.cloneNode(true);
        ghost.className = 'card card-ghost';
        ghost.style.position = 'fixed';
        ghost.style.pointerEvents = 'none';
        ghost.style.zIndex = '10000';
        ghost.style.opacity = '0.8';
        document.body.appendChild(ghost);
    }, { passive: true });

    element.addEventListener('touchmove', (e) => {
        if (!ghost) return;
        const touch = e.touches[0];
        ghost.style.left = (touch.clientX - 35) + 'px';
        ghost.style.top = (touch.clientY - 50) + 'px';
    }, { passive: true });

    element.addEventListener('touchend', (e) => {
        if (ghost) {
            ghost.remove();
            ghost = null;
        }
        if (!dragEl) return;

        const touch = e.changedTouches[0];
        const dropTarget = document.elementFromPoint(touch.clientX, touch.clientY);
        const zone = dropTarget?.closest('.zone-el');

        if (zone && callbacks.onDrop) {
            callbacks.onDrop({
                cardId: parseInt(dragEl.dataset.cardId),
            }, {
                id: parseInt(zone.dataset.zoneId),
            });
        }

        dragEl = null;
    });
}
