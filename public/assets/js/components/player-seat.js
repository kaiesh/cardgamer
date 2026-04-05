/**
 * Player seat rendering.
 */
export function renderPlayerSeat(player, handCards, position) {
    const el = document.createElement('div');
    el.className = 'player-seat-el';
    el.dataset.user = player.user_id;
    el.style.position = 'absolute';
    el.style.left = position.x + '%';
    el.style.top = position.y + '%';
    el.style.transform = 'translate(-50%, -50%)';

    const cardCount = handCards.length;
    const miniCards = handCards.slice(0, 10).map((_, i) => {
        const angle = cardCount > 1 ? -15 + (30 * i / (Math.min(cardCount, 10) - 1 || 1)) : 0;
        return `<div class="mini-card" style="transform:rotate(${angle}deg)"></div>`;
    }).join('');

    el.innerHTML = `
        <div class="player-seat">
            <div class="player-avatar" style="background:${player.avatar_color || '#3B82F6'}">
                ${(player.display_name || '?')[0].toUpperCase()}
            </div>
            <div class="player-name">${esc(player.display_name || 'Player')}</div>
            ${player.chips > 0 ? `<div class="player-chips">🪙 ${player.chips}</div>` : ''}
            <div class="player-hand-backs">${miniCards}</div>
            ${!player.is_connected ? '<div class="disconnected-badge">offline</div>' : ''}
        </div>
    `;

    // Drop target for giving cards
    el.addEventListener('dragover', (e) => {
        e.preventDefault();
        el.classList.add('seat-drop-target');
    });
    el.addEventListener('dragleave', () => el.classList.remove('seat-drop-target'));
    el.addEventListener('drop', (e) => {
        e.preventDefault();
        el.classList.remove('seat-drop-target');
        // Card drop on player handled by parent
    });

    return el;
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
