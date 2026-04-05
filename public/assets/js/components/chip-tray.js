/**
 * Chip display and transfer UI.
 */
import { api } from '../api.js';

export function renderChipTray(container, myPlayer, state, tableId) {
    if (!state.table.chip_initial && state.table.chip_initial !== 0) return;
    if (state.table.chip_initial === 0 && !state.pots?.length) return;

    let tray = container.querySelector('.chip-tray-container');
    if (!tray) {
        tray = document.createElement('div');
        tray.className = 'chip-tray-container';
        container.appendChild(tray);
    }

    tray.innerHTML = `
        <div class="chip-tray">
            <div class="chip-balance" id="chip-balance">
                <span class="chip-icon">🪙</span>
                <span class="chip-count">${myPlayer.chips}</span>
            </div>
            <button class="btn btn-sm btn-ghost" id="transfer-chips-btn">Transfer</button>
        </div>
    `;

    document.getElementById('transfer-chips-btn')?.addEventListener('click', () => {
        showTransferDialog(myPlayer, state, tableId);
    });

    document.getElementById('chip-balance')?.addEventListener('click', () => {
        showTransferDialog(myPlayer, state, tableId);
    });
}

function showTransferDialog(myPlayer, state, tableId) {
    // Remove existing
    document.querySelector('.chip-transfer-dialog')?.remove();

    const dialog = document.createElement('div');
    dialog.className = 'chip-transfer-dialog';

    const others = state.players.filter(p => p.id !== myPlayer.id);
    const pots = state.pots || [];

    dialog.innerHTML = `
        <div class="chip-dialog-content">
            <h3>Transfer Chips</h3>
            <p>Your balance: <strong>${myPlayer.chips}</strong></p>
            <div class="form-group">
                <label>Amount</label>
                <input type="range" id="chip-amount-range" min="1" max="${myPlayer.chips}" value="1">
                <input type="number" id="chip-amount" min="1" max="${myPlayer.chips}" value="1">
            </div>
            <div class="form-group">
                <label>Send to</label>
                <select id="chip-dest">
                    ${others.map(p => `<option value="player:${p.id}">${esc(p.display_name)}</option>`).join('')}
                    ${pots.map(p => `<option value="pot:${p.id}">${esc(p.label)} (${p.amount})</option>`).join('')}
                    <option value="new_pot">+ New Pot</option>
                </select>
            </div>
            <div id="new-pot-name-group" style="display:none" class="form-group">
                <label>Pot Name</label>
                <input type="text" id="new-pot-name" placeholder="Main Pot" maxlength="50">
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" id="chip-send-btn">Send</button>
                <button class="btn btn-ghost" id="chip-cancel-btn">Cancel</button>
            </div>
        </div>
    `;

    document.body.appendChild(dialog);

    const range = dialog.querySelector('#chip-amount-range');
    const input = dialog.querySelector('#chip-amount');
    range.addEventListener('input', () => input.value = range.value);
    input.addEventListener('input', () => range.value = input.value);

    dialog.querySelector('#chip-dest').addEventListener('change', (e) => {
        document.getElementById('new-pot-name-group').style.display = e.target.value === 'new_pot' ? 'block' : 'none';
    });

    dialog.querySelector('#chip-cancel-btn').addEventListener('click', () => dialog.remove());

    dialog.querySelector('#chip-send-btn').addEventListener('click', async () => {
        const amount = parseInt(input.value);
        const dest = dialog.querySelector('#chip-dest').value;

        try {
            if (dest === 'new_pot') {
                const potName = dialog.querySelector('#new-pot-name').value.trim() || 'Pot';
                const potData = await api.post(`/tables/${tableId}/chips/create-pot`, { label: potName });
                await api.post(`/tables/${tableId}/chips/transfer`, {
                    from_type: 'player', from_id: myPlayer.id,
                    to_type: 'pot', to_id: potData.pot.id,
                    amount,
                });
            } else {
                const [type, id] = dest.split(':');
                await api.post(`/tables/${tableId}/chips/transfer`, {
                    from_type: 'player', from_id: myPlayer.id,
                    to_type: type, to_id: parseInt(id),
                    amount,
                });
            }
            dialog.remove();
        } catch (e) {
            alert(e.message);
        }
    });
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
