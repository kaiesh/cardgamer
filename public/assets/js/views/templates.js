import { api } from '../api.js';

let container;

export function mount(el) {
    container = el;
    render();
    loadTemplates();
}

export function unmount() { if (container) container.innerHTML = ''; }

async function loadTemplates() {
    try {
        const data = await api.get('/templates');
        renderList(data.templates);
    } catch (e) { console.error(e); }
}

function render() {
    container.innerHTML = `
        <div class="templates-page">
            <header class="page-header">
                <a href="#/lobby" class="btn btn-ghost">&larr; Back</a>
                <h1>Templates</h1>
            </header>
            <div id="templates-list" class="templates-list"><div class="loading">Loading...</div></div>
        </div>
    `;
}

function renderList(templates) {
    const el = document.getElementById('templates-list');
    if (!el) return;
    if (!templates.length) {
        el.innerHTML = '<div class="empty-state">No templates yet. Save one from the Zone Builder!</div>';
        return;
    }
    el.innerHTML = templates.map(t => `
        <div class="template-card">
            <h3>${esc(t.name)}</h3>
            <span>${t.num_decks} deck${t.num_decks > 1 ? 's' : ''}${t.include_jokers ? ' + jokers' : ''}</span>
            <span>${t.is_public ? 'Public' : 'Private'}</span>
            <span>by ${esc(t.creator_name || 'you')}</span>
            <button class="btn btn-danger btn-sm delete-tpl" data-id="${t.id}">Delete</button>
        </div>
    `).join('');

    el.querySelectorAll('.delete-tpl').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Delete this template?')) return;
            try { await api.delete(`/templates/${btn.dataset.id}`); loadTemplates(); } catch (e) { alert(e.message); }
        });
    });
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
