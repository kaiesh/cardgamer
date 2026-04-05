/**
 * Zone rendering component.
 */
import { renderCard } from './card.js';

export function renderZone(zone, cards, options = {}) {
    const el = document.createElement('div');
    el.className = `zone-el zone-${zone.layout_mode}`;
    el.dataset.zoneId = zone.id;
    el.style.position = 'absolute';
    el.style.left = zone.pos_x + '%';
    el.style.top = zone.pos_y + '%';
    el.style.width = zone.width + '%';
    el.style.height = zone.height + '%';
    el.style.backgroundColor = zone.color + '4D';
    el.style.borderColor = zone.color;

    const label = document.createElement('div');
    label.className = 'zone-label';
    label.textContent = zone.label;
    if (cards.length > 0) {
        label.textContent += ` (${cards.length})`;
    }
    el.appendChild(label);

    const cardsContainer = document.createElement('div');
    cardsContainer.className = 'zone-cards';

    if (zone.layout_mode === 'stacked') {
        // Show top few cards stacked
        const visible = cards.slice(-3);
        visible.forEach((card, i) => {
            const cardEl = renderCard(card, {
                onClick: options.onCardClick,
                onDblClick: options.onCardDblClick,
            });
            cardEl.style.position = 'absolute';
            cardEl.style.top = (i * 2) + 'px';
            cardEl.style.left = (i * 2) + 'px';
            cardEl.style.zIndex = i;
            cardsContainer.appendChild(cardEl);
        });
    } else {
        // Spread layout
        cards.forEach((card, i) => {
            const cardEl = renderCard(card, {
                onClick: options.onCardClick,
                onDblClick: options.onCardDblClick,
            });
            cardsContainer.appendChild(cardEl);
        });
    }

    el.appendChild(cardsContainer);

    // Drop target
    el.addEventListener('dragover', (e) => {
        e.preventDefault();
        el.classList.add('zone-drop-target');
    });
    el.addEventListener('dragleave', () => {
        el.classList.remove('zone-drop-target');
    });
    el.addEventListener('drop', (e) => {
        e.preventDefault();
        el.classList.remove('zone-drop-target');
        try {
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            if (options.onDrop) options.onDrop(data, zone);
        } catch (err) {}
    });

    return el;
}
