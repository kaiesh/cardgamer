/**
 * Player's hand component.
 */
import { renderCard } from './card.js';

export function renderHand(container, cards, options = {}) {
    // Preserve chip tray if present
    const chipTray = container.querySelector('.chip-tray-container');

    let handEl = container.querySelector('.hand');
    if (!handEl) {
        handEl = document.createElement('div');
        handEl.className = 'hand';
        container.insertBefore(handEl, container.firstChild);
    }

    handEl.innerHTML = '';

    if (!cards.length) {
        handEl.innerHTML = '<div class="hand-empty-msg">Your hand is empty</div>';
        return;
    }

    const cardCount = cards.length;
    cards.forEach((card, i) => {
        const cardEl = renderCard(card, {
            onClick: options.onCardClick,
            onDblClick: options.onCardDblClick,
        });

        // Fan effect: rotate cards slightly
        if (cardCount > 1) {
            const maxAngle = Math.min(cardCount * 3, 30);
            const angle = -maxAngle / 2 + (maxAngle * i / (cardCount - 1));
            const lift = -Math.abs(angle) * 0.5;
            cardEl.style.transform = `rotate(${angle}deg) translateY(${lift}px)`;
        }

        cardEl.style.zIndex = i;

        // Drag reorder within hand
        cardEl.addEventListener('dragover', (e) => {
            e.preventDefault();
            cardEl.classList.add('drop-target');
        });
        cardEl.addEventListener('dragleave', () => {
            cardEl.classList.remove('drop-target');
        });
        cardEl.addEventListener('drop', (e) => {
            e.preventDefault();
            cardEl.classList.remove('drop-target');
            try {
                const data = JSON.parse(e.dataTransfer.getData('text/plain'));
                if (data.fromHand && options.onReorder) {
                    const currentOrder = cards.map(c => c.id);
                    const fromIdx = currentOrder.indexOf(data.cardId);
                    const toIdx = i;
                    if (fromIdx >= 0 && fromIdx !== toIdx) {
                        currentOrder.splice(fromIdx, 1);
                        currentOrder.splice(toIdx, 0, data.cardId);
                        options.onReorder(currentOrder);
                    }
                }
            } catch (err) {}
        });

        handEl.appendChild(cardEl);
    });

    // Re-append chip tray
    if (chipTray) container.appendChild(chipTray);
}
