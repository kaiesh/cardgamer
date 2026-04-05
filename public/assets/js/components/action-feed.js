/**
 * Action feed component.
 */
const ACTION_TEMPLATES = {
    'card.shuffled': (a) => `shuffled the deck`,
    'card.cut': (a) => `cut the deck`,
    'card.dealt': (a) => `dealt ${pluralCards(a.payload?.card_ids)} cards`,
    'card.dealt_to_zone': (a) => `dealt cards to the table`,
    'card.taken': (a) => `took cards from the deck`,
    'card.placed': (a) => `placed cards on the table`,
    'card.given': (a) => `gave cards to another player`,
    'card.flipped': (a) => `flipped a card ${a.payload?.face_up ? 'face-up' : 'face-down'}`,
    'card.peeked': (a) => `peeked at a card`,
    'card.unpeeked': (a) => `stopped peeking`,
    'card.revealed': (a) => `revealed a card`,
    'card.marked': (a) => `marked a card`,
    'card.removed': (a) => `removed cards from play`,
    'card.returned': (a) => `returned cards to play`,
    'card.swapped': (a) => `swapped cards with another player`,
    'card.offered': (a) => `offered cards`,
    'card.offer_accepted': (a) => `accepted an offer`,
    'card.offer_declined': (a) => `declined an offer`,
    'card.reordered': (a) => `rearranged their hand`,
    'card.discarded': (a) => `discarded cards`,
    'card.forced_give': (a) => `force-gave cards`,
    'card.forced_take': (a) => `force-took cards`,
    'chips.transferred': (a) => `transferred ${a.payload?.amount || '?'} chips`,
    'chips.pot_created': (a) => `created a chip pot`,
    'chat.message': (a) => `said: "${a.payload?.phrase_text || ''}"`,
    'player.joined': (a) => `joined the table`,
    'player.left': (a) => `left the table`,
    'player.kicked': (a) => `was kicked`,
    'game.started': () => `Game started!`,
    'game.paused': () => `Game paused`,
    'game.resumed': () => `Game resumed`,
    'game.closed': () => `Game ended`,
};

let feedActions = [];

export function renderActionFeed(container, actions) {
    feedActions = actions;
    container.innerHTML = '<div class="action-feed" id="action-feed"></div>';
    const feed = container.querySelector('.action-feed');
    actions.forEach(a => appendActionEl(feed, a));
    feed.scrollTop = feed.scrollHeight;
}

export function addAction(action) {
    feedActions.push(action);
    const feed = document.getElementById('action-feed');
    if (feed) {
        appendActionEl(feed, action);
        feed.scrollTop = feed.scrollHeight;
    }
}

function appendActionEl(feed, action) {
    const el = document.createElement('div');
    el.className = 'action-entry';
    const time = action.created_at ? new Date(action.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '';
    const template = ACTION_TEMPLATES[action.action_type];
    const text = template ? template(action) : action.action_type.replace(/\./g, ' ');
    const payload = typeof action.payload === 'string' ? JSON.parse(action.payload) : action.payload;

    el.innerHTML = `<span class="action-time">[${time}]</span> <strong>${esc(action.actor_name || '')}</strong> ${text}`;
    feed.appendChild(el);
}

function pluralCards(ids) {
    if (!ids) return '?';
    return ids.length;
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
