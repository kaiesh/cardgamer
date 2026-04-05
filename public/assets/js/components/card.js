/**
 * Card rendering component.
 */
export const CARD_SUITS = {
    hearts: { symbol: '♥', color: '#DC2626' },
    diamonds: { symbol: '♦', color: '#DC2626' },
    clubs: { symbol: '♣', color: '#1F2937' },
    spades: { symbol: '♠', color: '#1F2937' },
    joker: { symbol: '🃏', color: '#7C3AED' },
};

export function renderCard(card, options = {}) {
    const el = document.createElement('div');
    el.className = 'card';
    el.dataset.cardId = card.id;

    if (!card.in_play) {
        el.classList.add('card-removed');
    }

    if (card.face_up && card.suit) {
        el.classList.add('card-face-up');
        const suit = CARD_SUITS[card.suit] || CARD_SUITS.spades;
        const rank = card.rank === 'joker' ? 'JKR' : card.rank;
        el.innerHTML = `
            <div class="card-face" style="color:${suit.color}">
                <div class="card-corner card-corner-top">
                    <span class="card-rank">${rank}</span>
                    <span class="card-suit">${suit.symbol}</span>
                </div>
                <div class="card-center">${suit.symbol}</div>
                <div class="card-corner card-corner-bottom">
                    <span class="card-rank">${rank}</span>
                    <span class="card-suit">${suit.symbol}</span>
                </div>
            </div>
        `;
    } else if (card.peeked_by_you && card.suit) {
        // Peeking - semi-visible
        el.classList.add('card-peeking');
        const suit = CARD_SUITS[card.suit] || CARD_SUITS.spades;
        el.innerHTML = `
            <div class="card-face card-peek-face" style="color:${suit.color}">
                <div class="card-corner card-corner-top">
                    <span class="card-rank">${card.rank}</span>
                    <span class="card-suit">${suit.symbol}</span>
                </div>
                <div class="card-center">${suit.symbol}</div>
                <span class="peek-icon">👁</span>
            </div>
        `;
    } else {
        // Face down
        el.classList.add('card-face-down');
        el.innerHTML = `<div class="card-back"></div>`;
        if (card.is_peeked) {
            el.innerHTML += '<span class="peek-indicator">?</span>';
        }
    }

    if (card.marked_by_you) {
        el.innerHTML += '<span class="mark-dot"></span>';
    }

    // Event handlers
    if (options.onClick) {
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            options.onClick(card);
        });
    }
    if (options.onDblClick) {
        el.addEventListener('dblclick', (e) => {
            e.stopPropagation();
            options.onDblClick(card);
        });
    }

    // Make draggable
    el.draggable = true;
    el.addEventListener('dragstart', (e) => {
        e.dataTransfer.setData('text/plain', JSON.stringify({ cardId: card.id, fromZone: card.zone_id, fromHand: card.holder_player_id }));
        el.classList.add('dragging');
    });
    el.addEventListener('dragend', () => {
        el.classList.remove('dragging');
    });

    return el;
}
