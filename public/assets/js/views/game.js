import { api } from '../api.js';
import store from '../store.js';
import { pusherManager } from '../pusher-client.js';
import { renderCard, CARD_SUITS } from '../components/card.js';
import { renderHand } from '../components/hand.js';
import { renderZone } from '../components/zone.js';
import { showContextMenu } from '../components/context-menu.js';
import { renderActionFeed, addAction } from '../components/action-feed.js';
import { renderChat } from '../components/chat.js';
import { renderChipTray } from '../components/chip-tray.js';
import { renderPlayerSeat } from '../components/player-seat.js';
import { initDrag } from '../lib/drag.js';

let container;
let tableId;
let state = null;
let eventHandlers = {};
let refreshTimer;

export function mount(el, params) {
    container = el;
    tableId = params.id;
    render();
    loadState();
    pusherManager.subscribeToTable(tableId);
    bindEvents();

    refreshTimer = setInterval(() => {
        // Periodic light refresh for connection health
    }, 30000);
}

export function unmount() {
    if (refreshTimer) clearInterval(refreshTimer);
    unbindEvents();
    pusherManager.unsubscribeFromTable(tableId);
    if (container) container.innerHTML = '';
}

function bindEvents() {
    const tableEvents = [
        'card.shuffled', 'card.cut', 'card.dealt', 'card.dealt_to_zone', 'card.taken',
        'card.placed', 'card.given', 'card.flipped', 'card.peeked', 'card.unpeeked',
        'card.revealed', 'card.removed', 'card.returned', 'card.swapped',
        'card.offered', 'card.offer_accepted', 'card.offer_declined', 'card.reordered',
        'card.discarded', 'card.forced_give', 'card.forced_take',
        'chips.transferred', 'chips.pot_created',
        'chat.message',
        'player.joined', 'player.left', 'player.kicked',
        'game.started', 'game.paused', 'game.resumed', 'game.closed',
        'zone.created', 'zone.updated', 'zone.deleted',
    ];

    tableEvents.forEach(event => {
        const handler = (data) => handleTableEvent(event, data);
        eventHandlers[event] = handler;
        store.on(`event:${event}`, handler);
    });
}

function unbindEvents() {
    Object.entries(eventHandlers).forEach(([event, handler]) => {
        store.off(`event:${event}`, handler);
    });
    eventHandlers = {};
}

function handleTableEvent(event, data) {
    // Add to action feed
    if (data.actor) {
        addAction({
            action_type: event,
            actor_name: data.actor.name,
            payload: data.payload,
            created_at: data.timestamp,
        });
    }

    // Handle chat bubbles
    if (event === 'chat.message') {
        showChatBubble(data.payload);
    }

    // Refresh state for most events
    loadState();
}

async function loadState() {
    try {
        const data = await api.get(`/tables/${tableId}/state`);
        state = data;
        store.set('tableState', data);
        renderGameUI();
    } catch (e) {
        if (e.status === 404) {
            alert('Table not found');
            window.location.hash = '#/lobby';
        }
    }
}

function render() {
    container.innerHTML = `
        <div class="game-layout">
            <div class="game-topbar" id="game-topbar"></div>
            <div class="game-main">
                <div class="game-table-area" id="game-table-area">
                    <div class="table-felt" id="table-felt">
                        <div class="table-center-info" id="table-center-info"></div>
                    </div>
                </div>
                <div class="game-sidebar" id="game-sidebar">
                    <div class="sidebar-tabs">
                        <button class="sidebar-tab active" data-tab="feed">Feed</button>
                        <button class="sidebar-tab" data-tab="chat">Chat</button>
                    </div>
                    <div class="sidebar-content" id="sidebar-content"></div>
                </div>
            </div>
            <div class="game-hand-area" id="game-hand-area"></div>
        </div>
    `;

    // Sidebar tabs
    container.querySelectorAll('.sidebar-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            container.querySelectorAll('.sidebar-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            renderSidebar(tab.dataset.tab);
        });
    });
}

function renderGameUI() {
    if (!state) return;

    renderTopbar();
    renderTableArea();
    renderHandArea();
    renderSidebar(container.querySelector('.sidebar-tab.active')?.dataset.tab || 'feed');
}

function renderTopbar() {
    const topbar = document.getElementById('game-topbar');
    if (!topbar) return;

    const t = state.table;
    const isCreator = t.creator_id === store.user?.id;

    topbar.innerHTML = `
        <div class="topbar-left">
            <a href="#/lobby" class="btn btn-ghost btn-sm">&larr;</a>
            <h2>${esc(t.name)}</h2>
            <span class="badge badge-${t.status}">${t.status}</span>
        </div>
        <div class="topbar-right">
            ${t.status === 'lobby' && isCreator ? '<button class="btn btn-primary btn-sm" id="start-game-btn">Start Game</button>' : ''}
            ${t.status === 'lobby' && isCreator ? `<a href="#/zones/${tableId}" class="btn btn-ghost btn-sm">Edit Zones</a>` : ''}
            ${t.status === 'active' && isCreator ? '<button class="btn btn-ghost btn-sm" id="pause-btn">Pause</button>' : ''}
            ${t.status === 'paused' && isCreator ? '<button class="btn btn-primary btn-sm" id="resume-btn">Resume</button>' : ''}
            ${(t.status === 'active' || t.status === 'paused') && isCreator ? '<button class="btn btn-danger btn-sm" id="close-btn">Close</button>' : ''}
            <button class="btn btn-ghost btn-sm" id="leave-btn">Leave</button>
        </div>
    `;

    topbar.querySelector('#start-game-btn')?.addEventListener('click', async () => {
        try { await api.post(`/tables/${tableId}/start`); loadState(); } catch (e) { alert(e.message); }
    });
    topbar.querySelector('#pause-btn')?.addEventListener('click', async () => {
        try { await api.post(`/tables/${tableId}/pause`); loadState(); } catch (e) { alert(e.message); }
    });
    topbar.querySelector('#resume-btn')?.addEventListener('click', async () => {
        try { await api.post(`/tables/${tableId}/resume`); loadState(); } catch (e) { alert(e.message); }
    });
    topbar.querySelector('#close-btn')?.addEventListener('click', async () => {
        if (confirm('Close this game?')) {
            try { await api.post(`/tables/${tableId}/close`); loadState(); } catch (e) { alert(e.message); }
        }
    });
    topbar.querySelector('#leave-btn')?.addEventListener('click', async () => {
        try { await api.post(`/tables/${tableId}/leave`); window.location.hash = '#/lobby'; } catch (e) { alert(e.message); }
    });
}

function renderTableArea() {
    const felt = document.getElementById('table-felt');
    if (!felt || !state) return;

    // Clear zones and seats (keep center info)
    felt.querySelectorAll('.zone-el, .player-seat-el, .chip-pot-el').forEach(el => el.remove());

    // Render zones with their cards
    state.zones.forEach(zone => {
        const zoneCards = state.cards.filter(c => c.zone_id === zone.id && c.in_play);
        const el = renderZone(zone, zoneCards, {
            onCardClick: (card) => openCardMenu(card, zone),
            onCardDblClick: (card) => flipCard(card),
            onDrop: (data) => handleCardDrop(data, zone),
            tableId,
        });
        felt.appendChild(el);
    });

    // Render player seats
    const myId = store.user?.id;
    const players = state.players || [];
    const seatPositions = getSeatPositions(players.length, myId);

    players.forEach((player, i) => {
        if (player.user_id === myId) return; // Own hand is at bottom
        const pos = seatPositions[i];
        if (!pos) return;
        const handCards = state.cards.filter(c => c.holder_player_id === player.id && c.in_play);
        const el = renderPlayerSeat(player, handCards, pos);
        felt.appendChild(el);
    });

    // Render chip pots
    state.pots.forEach(pot => {
        if (pot.amount > 0) {
            const el = document.createElement('div');
            el.className = 'chip-pot-el';
            el.style.position = 'absolute';
            el.style.left = '45%';
            el.style.top = '45%';
            el.innerHTML = `<div class="chip-pot"><span class="chip-pot-label">${esc(pot.label)}</span><span class="chip-pot-amount">${pot.amount}</span></div>`;
            felt.appendChild(el);
        }
    });

    // Center info for lobby
    const centerInfo = document.getElementById('table-center-info');
    if (centerInfo) {
        if (state.table.status === 'lobby') {
            centerInfo.innerHTML = `
                <div class="lobby-waiting">
                    <h3>Waiting for players...</h3>
                    <p>${players.length} player${players.length !== 1 ? 's' : ''} joined</p>
                    <div class="players-list">
                        ${players.map(p => `<span class="avatar" style="background:${p.avatar_color}">${(p.display_name || '?')[0].toUpperCase()}</span>`).join('')}
                    </div>
                </div>
            `;
        } else if (state.table.status === 'paused') {
            centerInfo.innerHTML = '<div class="game-paused"><h3>Game Paused</h3></div>';
        } else if (state.table.status === 'closed') {
            centerInfo.innerHTML = `<div class="game-closed"><h3>Game Over</h3><a href="#/history/${tableId}" class="btn btn-primary">View History</a></div>`;
        } else {
            centerInfo.innerHTML = '';
        }
    }

    // Init drag and drop on the table area
    initDrag(felt, {
        tableId,
        onDrop: handleCardDrop,
        state,
    });
}

function renderHandArea() {
    const handArea = document.getElementById('game-hand-area');
    if (!handArea || !state) return;

    const myPlayer = state.players.find(p => p.user_id === store.user?.id);
    if (!myPlayer) {
        handArea.innerHTML = '<div class="hand-empty">You are not at this table</div>';
        return;
    }

    const myCards = state.cards
        .filter(c => c.holder_player_id === myPlayer.id && c.in_play)
        .sort((a, b) => a.position_in_zone - b.position_in_zone);

    renderHand(handArea, myCards, {
        onCardClick: (card) => openCardMenu(card, null),
        onCardDblClick: (card) => flipCard(card),
        onReorder: (orderedIds) => reorderHand(orderedIds),
        tableId,
    });

    // Chip tray
    renderChipTray(handArea, myPlayer, state, tableId);
}

function renderSidebar(tab) {
    const content = document.getElementById('sidebar-content');
    if (!content || !state) return;

    if (tab === 'chat') {
        renderChat(content, state, tableId);
    } else {
        renderActionFeed(content, state.actions || []);
    }
}

// Card actions
async function flipCard(card) {
    try {
        await api.post(`/tables/${tableId}/cards/action`, { action: 'flip', card_ids: [card.id] });
    } catch (e) { console.error(e); }
}

function openCardMenu(card, zone) {
    const myPlayer = state.players.find(p => p.user_id === store.user?.id);
    if (!myPlayer) return;

    const isInHand = card.holder_player_id === myPlayer.id;
    const isInZone = !!card.zone_id;
    const isFaceUp = card.face_up;

    const actions = [];

    if (isInHand) {
        if (!isFaceUp) actions.push({ label: 'Peek', icon: '👁', action: () => peekCard(card) });
        actions.push({ label: 'Play to Table', icon: '🃏', action: () => playToTable(card) });
        actions.push({ label: 'Discard', icon: '🗑', action: () => discardCard(card) });
        if (isFaceUp) actions.push({ label: 'Flip Down', icon: '🔄', action: () => flipCard(card) });
        else actions.push({ label: 'Flip Up', icon: '🔄', action: () => flipCard(card) });
        actions.push({ label: 'Give to...', icon: '🤝', action: () => giveCard(card) });
        if (card.marked_by_you) {
            actions.push({ label: 'Unmark', icon: '✖', action: () => unmarkCard(card) });
        } else {
            actions.push({ label: 'Mark', icon: '📌', action: () => markCard(card) });
        }
    } else if (isInZone) {
        if (!isFaceUp) actions.push({ label: 'Peek', icon: '👁', action: () => peekCard(card) });
        actions.push({ label: 'Take', icon: '✋', action: () => takeCard(card) });
        actions.push({ label: isFaceUp ? 'Flip Down' : 'Flip Up', icon: '🔄', action: () => flipCard(card) });
        actions.push({ label: 'Reveal', icon: '👀', action: () => revealCard(card) });
    }

    if (actions.length) {
        showContextMenu(actions);
    }
}

async function peekCard(card) {
    try { await api.post(`/tables/${tableId}/cards/action`, { action: 'peek', card_ids: [card.id] }); } catch (e) { console.error(e); }
}

async function takeCard(card) {
    try { await api.post(`/tables/${tableId}/cards/action`, { action: 'take_from_zone', card_ids: [card.id] }); loadState(); } catch (e) { console.error(e); }
}

async function revealCard(card) {
    try { await api.post(`/tables/${tableId}/cards/action`, { action: 'reveal', card_ids: [card.id] }); } catch (e) { console.error(e); }
}

async function markCard(card) {
    try { await api.post(`/tables/${tableId}/cards/action`, { action: 'mark', card_ids: [card.id] }); } catch (e) { console.error(e); }
}

async function unmarkCard(card) {
    try { await api.post(`/tables/${tableId}/cards/action`, { action: 'unmark', card_ids: [card.id] }); } catch (e) { console.error(e); }
}

async function playToTable(card) {
    // Find first shared zone
    const zone = state.zones.find(z => z.zone_type === 'shared');
    if (!zone) { alert('No shared zone to play to'); return; }
    try {
        await api.post(`/tables/${tableId}/cards/action`, { action: 'place_in_zone', card_ids: [card.id], target_zone_id: zone.id });
        loadState();
    } catch (e) { console.error(e); }
}

async function discardCard(card) {
    // Find a discard-like zone or last shared zone
    const zone = state.zones.find(z => z.label.toLowerCase().includes('discard')) || state.zones.find(z => z.zone_type === 'shared');
    if (!zone) { alert('No discard zone'); return; }
    try {
        await api.post(`/tables/${tableId}/cards/action`, { action: 'discard', card_ids: [card.id], target_zone_id: zone.id });
        loadState();
    } catch (e) { console.error(e); }
}

async function giveCard(card) {
    const others = state.players.filter(p => p.user_id !== store.user?.id);
    if (!others.length) { alert('No other players'); return; }
    const choice = others.length === 1 ? others[0] :
        others[parseInt(prompt(others.map((p, i) => `${i}: ${p.display_name}`).join('\n'))) || 0];
    if (!choice) return;
    try {
        await api.post(`/tables/${tableId}/cards/action`, { action: 'give_to_player', card_ids: [card.id], target_player_id: choice.id });
        loadState();
    } catch (e) { console.error(e); }
}

async function reorderHand(orderedIds) {
    try {
        await api.post(`/tables/${tableId}/cards/action`, { action: 'reorder_hand', card_ids_ordered: orderedIds });
    } catch (e) { console.error(e); }
}

async function handleCardDrop(data, targetZone) {
    if (!data || !data.cardId) return;
    try {
        if (targetZone) {
            await api.post(`/tables/${tableId}/cards/action`, {
                action: 'place_in_zone',
                card_ids: [data.cardId],
                target_zone_id: targetZone.id,
            });
        }
        loadState();
    } catch (e) { console.error(e); }
}

function showChatBubble(payload) {
    if (!payload) return;
    const player = state?.players?.find(p => p.user_id === payload.user_id);
    if (!player) return;

    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble';
    bubble.textContent = payload.phrase_text;

    const seat = document.querySelector(`.player-seat-el[data-user="${payload.user_id}"]`);
    if (seat) {
        seat.appendChild(bubble);
    } else {
        const handArea = document.getElementById('game-hand-area');
        if (handArea) handArea.appendChild(bubble);
    }

    setTimeout(() => bubble.classList.add('fade-out'), 4000);
    setTimeout(() => bubble.remove(), 5000);
}

function getSeatPositions(count, myUserId) {
    const positions = [];
    // Place other players around top and sides
    const slots = [
        { x: 50, y: 2 },   // top center
        { x: 15, y: 15 },  // top left
        { x: 85, y: 15 },  // top right
        { x: 5, y: 50 },   // left
        { x: 95, y: 50 },  // right
        { x: 20, y: 2 },   // top left-center
        { x: 80, y: 2 },   // top right-center
        { x: 50, y: 85 },  // bottom center (skip if it's us)
    ];
    let slotIdx = 0;
    for (let i = 0; i < count; i++) {
        positions.push(slots[slotIdx % slots.length]);
        slotIdx++;
    }
    return positions;
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
