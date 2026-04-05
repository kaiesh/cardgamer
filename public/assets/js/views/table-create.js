import { api } from '../api.js';
import store from '../store.js';

let container;

export function mount(el) {
    container = el;
    render();
    loadExtras();
}

export function unmount() {
    if (container) container.innerHTML = '';
}

async function loadExtras() {
    try {
        const [templates, skins, logos] = await Promise.allSettled([
            api.get('/templates'),
            api.get('/admin/deck-skins').catch(() => ({ skins: [] })),
            api.get('/admin/table-logos').catch(() => ({ logos: [] })),
        ]);
        renderSelectors(
            templates.status === 'fulfilled' ? templates.value.templates : [],
            skins.status === 'fulfilled' ? skins.value.skins : [],
            logos.status === 'fulfilled' ? logos.value.logos : [],
        );
    } catch (e) { /* optional extras */ }
}

function renderSelectors(templates, skins, logos) {
    const tplSelect = document.getElementById('template-select');
    if (tplSelect && templates.length) {
        tplSelect.innerHTML = '<option value="">No template</option>' +
            templates.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
    }
}

function render() {
    container.innerHTML = `
        <div class="create-page">
            <header class="page-header">
                <a href="#/lobby" class="btn btn-ghost">&larr; Back</a>
                <h1>Create Table</h1>
            </header>
            <form id="create-form" class="create-form">
                <div class="form-group">
                    <label for="table-name">Table Name</label>
                    <input type="text" id="table-name" maxlength="100" required placeholder="Friday Night Poker">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="num-decks">Decks</label>
                        <select id="num-decks">
                            ${[1,2,3,4,5,6,7,8].map(n => `<option value="${n}">${n}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" id="include-jokers"> Include Jokers</label>
                    </div>
                    <div class="form-group">
                        <label for="deck-backs">Card Backs</label>
                        <select id="deck-backs">
                            <option value="uniform">Uniform</option>
                            <option value="random_per_deck">Random per Deck</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="chip-initial">Starting Chips (0 = disabled)</label>
                    <input type="number" id="chip-initial" value="0" min="0" max="100000">
                </div>
                <div class="form-group">
                    <label for="template-select">Load Template</label>
                    <select id="template-select">
                        <option value="">No template</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-lg">Next: Set Up Zones &rarr;</button>
                </div>
            </form>
        </div>
    `;

    document.getElementById('create-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = document.getElementById('table-name').value.trim();
        const numDecks = parseInt(document.getElementById('num-decks').value);
        const includeJokers = document.getElementById('include-jokers').checked;
        const deckBacks = document.getElementById('deck-backs').value;
        const chipInitial = parseInt(document.getElementById('chip-initial').value) || 0;
        const templateId = document.getElementById('template-select').value;

        try {
            const data = await api.post('/tables', {
                name, num_decks: numDecks, include_jokers: includeJokers,
                deck_backs: deckBacks, chip_initial: chipInitial,
            });
            const tableId = data.table_id;

            if (templateId) {
                await api.post(`/tables/${tableId}/apply-template`, { template_id: parseInt(templateId) });
            }

            window.location.hash = `#/zones/${tableId}`;
        } catch (err) {
            alert(err.message);
        }
    });
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
