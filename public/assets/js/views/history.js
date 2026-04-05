import { api } from '../api.js';

let container;
let tableId;
let actions = [];
let filters = { player: '', actionType: '' };

export function mount(el, params) {
    container = el;
    tableId = params.id;
    render();
    loadHistory();
}

export function unmount() { if (container) container.innerHTML = ''; }

async function loadHistory() {
    try {
        const data = await api.get(`/tables/${tableId}/state`);
        const actData = await api.get(`/tables/${tableId}/actions?limit=100`);
        actions = actData.actions || [];
        renderHistory(data, actions);
    } catch (e) { console.error(e); }
}

function render() {
    container.innerHTML = `
        <div class="history-page">
            <header class="page-header">
                <a href="#/lobby" class="btn btn-ghost">&larr; Back</a>
                <h1>Game History</h1>
            </header>
            <div class="history-filters" id="history-filters"></div>
            <div class="history-stats" id="history-stats"></div>
            <div class="history-log" id="history-log"><div class="loading">Loading...</div></div>
            <button class="btn btn-ghost" id="export-btn">Export JSON</button>
        </div>
    `;

    document.getElementById('export-btn').addEventListener('click', () => {
        const blob = new Blob([JSON.stringify(actions, null, 2)], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `game-${tableId}-actions.json`;
        a.click();
    });
}

function renderHistory(state, actions) {
    // Stats
    const statsEl = document.getElementById('history-stats');
    if (statsEl) {
        const cardActions = actions.filter(a => a.action_type.startsWith('card.'));
        const chipActions = actions.filter(a => a.action_type.startsWith('chips.'));
        const shuffles = actions.filter(a => a.action_type === 'card.shuffled');
        const duration = actions.length >= 2
            ? Math.round((new Date(actions[actions.length - 1].created_at) - new Date(actions[0].created_at)) / 60000)
            : 0;

        statsEl.innerHTML = `
            <div class="stats-grid">
                <div class="stat"><strong>${state.players?.length || 0}</strong> Players</div>
                <div class="stat"><strong>${cardActions.length}</strong> Card Actions</div>
                <div class="stat"><strong>${chipActions.length}</strong> Chip Transfers</div>
                <div class="stat"><strong>${shuffles.length}</strong> Shuffles</div>
                <div class="stat"><strong>${duration}m</strong> Duration</div>
            </div>
        `;
    }

    // Filters
    const filtersEl = document.getElementById('history-filters');
    if (filtersEl) {
        const players = state.players || [];
        const types = [...new Set(actions.map(a => a.action_type))];
        filtersEl.innerHTML = `
            <select id="filter-player"><option value="">All Players</option>${players.map(p => `<option value="${p.user_id}">${esc(p.display_name)}</option>`).join('')}</select>
            <select id="filter-type"><option value="">All Types</option>${types.map(t => `<option value="${t}">${t}</option>`).join('')}</select>
        `;
        filtersEl.querySelector('#filter-player').addEventListener('change', (e) => { filters.player = e.target.value; renderLog(); });
        filtersEl.querySelector('#filter-type').addEventListener('change', (e) => { filters.actionType = e.target.value; renderLog(); });
    }

    renderLog();
}

function renderLog() {
    const el = document.getElementById('history-log');
    if (!el) return;

    let filtered = actions;
    if (filters.player) filtered = filtered.filter(a => a.user_id === filters.player);
    if (filters.actionType) filtered = filtered.filter(a => a.action_type === filters.actionType);

    el.innerHTML = filtered.map(a => {
        const time = new Date(a.created_at).toLocaleTimeString();
        return `<div class="action-entry"><span class="action-time">[${time}]</span> <strong>${esc(a.actor_name)}</strong> ${formatAction(a)}</div>`;
    }).join('') || '<div class="empty-state">No actions match filters</div>';
}

function formatAction(a) {
    const type = a.action_type.replace('card.', '').replace('chips.', '').replace('game.', '');
    return type.replace(/_/g, ' ');
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
