import { api } from '../api.js';
import store from '../store.js';
import { pusherManager } from '../pusher-client.js';

let container;
let pollInterval;
let handlers = {};

export function mount(el) {
    container = el;
    render();
    loadTables();

    pusherManager.subscribeToLobby();

    handlers.lobbyUpdate = () => loadTables();
    store.on('lobbyUpdate', handlers.lobbyUpdate);

    handlers.onlineUsers = () => renderOnlineUsers();
    store.on('onlineUsers', handlers.onlineUsers);

    pollInterval = setInterval(loadTables, 10000);
}

export function unmount() {
    if (pollInterval) clearInterval(pollInterval);
    store.off('lobbyUpdate', handlers.lobbyUpdate);
    store.off('onlineUsers', handlers.onlineUsers);
    pusherManager.unsubscribeFromLobby();
    if (container) container.innerHTML = '';
}

function render() {
    container.innerHTML = `
        <div class="lobby">
            <header class="lobby-header">
                <h1>&#x1F0CF; Card Table</h1>
                <div class="lobby-header-right">
                    <div class="user-badge">
                        <span class="avatar" style="background:${store.user?.avatar_color || '#3B82F6'}">${(store.user?.display_name || '?')[0].toUpperCase()}</span>
                        <span>${store.user?.display_name || 'Player'}</span>
                    </div>
                    <button class="btn btn-primary" id="create-table-btn">+ Create Table</button>
                    ${store.user?.is_admin ? '<a href="#/admin" class="btn btn-ghost">Admin</a>' : ''}
                    <button class="btn btn-ghost" id="logout-btn">Logout</button>
                </div>
            </header>
            <div class="lobby-body">
                <div class="lobby-tables">
                    <h2>Open Tables</h2>
                    <div id="tables-list" class="tables-list">
                        <div class="loading">Loading tables...</div>
                    </div>
                </div>
                <div class="lobby-sidebar">
                    <h2>Online Now</h2>
                    <div id="online-users" class="online-users-list"></div>
                </div>
            </div>
        </div>
    `;

    document.getElementById('create-table-btn').addEventListener('click', () => {
        window.location.hash = '#/create';
    });

    document.getElementById('logout-btn').addEventListener('click', async () => {
        await api.post('/auth/logout');
        store.set('user', null);
        pusherManager.disconnect();
        window.location.hash = '#/login';
    });
}

async function loadTables() {
    try {
        const data = await api.get('/lobby/tables');
        store.tables = data.tables;
        renderTables(data.tables);
    } catch (e) {
        console.error('Failed to load tables:', e);
    }
}

function renderTables(tables) {
    const el = document.getElementById('tables-list');
    if (!el) return;

    if (!tables.length) {
        el.innerHTML = '<div class="empty-state"><p>No open tables yet.</p><p>Create one to get started!</p></div>';
        return;
    }

    el.innerHTML = tables.map(t => `
        <div class="table-card" data-id="${t.id}">
            <div class="table-card-header">
                <h3>${esc(t.name)}</h3>
                <span class="badge">${t.player_count} player${t.player_count !== 1 ? 's' : ''}</span>
            </div>
            <div class="table-card-meta">
                <span>Created by ${esc(t.creator_name || 'Unknown')}</span>
                <span>${t.num_decks} deck${t.num_decks > 1 ? 's' : ''}${t.include_jokers ? ' + jokers' : ''}</span>
                ${t.chip_initial > 0 ? `<span>${t.chip_initial} chips</span>` : ''}
            </div>
            <button class="btn btn-primary btn-sm join-btn">Join Table</button>
        </div>
    `).join('');

    el.querySelectorAll('.join-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const card = e.target.closest('.table-card');
            const tableId = card.dataset.id;
            try {
                await api.post(`/tables/${tableId}/join`);
                window.location.hash = `#/table/${tableId}`;
            } catch (err) {
                if (err.code === 'ALREADY_JOINED') {
                    window.location.hash = `#/table/${tableId}`;
                } else {
                    alert(err.message);
                }
            }
        });
    });
}

function renderOnlineUsers() {
    const el = document.getElementById('online-users');
    if (!el) return;
    const users = store.onlineUsers || [];
    el.innerHTML = users.map(u => `
        <div class="online-user">
            <span class="avatar avatar-sm" style="background:${u.color || '#3B82F6'}">${(u.name || '?')[0].toUpperCase()}</span>
            <span>${esc(u.name || 'Anonymous')}</span>
        </div>
    `).join('') || '<div class="empty-state">No one else online</div>';
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
