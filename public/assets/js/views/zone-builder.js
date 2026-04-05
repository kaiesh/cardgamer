import { api } from '../api.js';
import store from '../store.js';

let container;
let tableId;
let zones = [];
let drawing = false;
let drawStart = null;
let selectedZone = null;
let dragState = null;
let resizeState = null;

export function mount(el, params) {
    container = el;
    tableId = params.id;
    render();
    loadZones();
}

export function unmount() {
    if (container) container.innerHTML = '';
}

async function loadZones() {
    try {
        const data = await api.get(`/tables/${tableId}/zones`);
        zones = data.zones || [];
        renderZones();
    } catch (e) {
        console.error('Failed to load zones', e);
    }
}

function render() {
    container.innerHTML = `
        <div class="zone-builder">
            <div class="zb-toolbar">
                <a href="#/lobby" class="btn btn-ghost">&larr; Back</a>
                <h2>Zone Builder</h2>
                <button class="btn btn-sm" id="add-shared-btn">+ Shared Zone</button>
                <button class="btn btn-sm" id="add-player-btn">+ Per-Player Zone</button>
                <button class="btn btn-primary" id="save-start-btn">Open Table</button>
                <button class="btn btn-ghost" id="save-template-btn">Save as Template</button>
            </div>
            <div class="zb-canvas-wrapper">
                <div class="zb-canvas" id="zb-canvas">
                    <div class="zb-felt"></div>
                </div>
            </div>
            <div id="zone-dialog" class="zone-dialog" style="display:none">
                <div class="zone-dialog-content">
                    <h3 id="zone-dialog-title">Zone Settings</h3>
                    <form id="zone-form">
                        <div class="form-group">
                            <label>Label</label>
                            <input type="text" id="zd-label" maxlength="50" required>
                        </div>
                        <div class="form-group">
                            <label>Type</label>
                            <select id="zd-type">
                                <option value="shared">Shared</option>
                                <option value="per_player">Per Player</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Layout</label>
                            <select id="zd-layout">
                                <option value="stacked">Stacked</option>
                                <option value="spread">Spread</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Visibility</label>
                            <select id="zd-visibility">
                                <option value="private">Private (peek only)</option>
                                <option value="public">Public (flip reveals)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Color</label>
                            <input type="color" id="zd-color" value="#1E3A5F">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Save</button>
                            <button type="button" class="btn btn-ghost" id="zd-cancel">Cancel</button>
                            <button type="button" class="btn btn-danger" id="zd-delete" style="display:none">Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    `;

    const canvas = document.getElementById('zb-canvas');

    // Draw new zone by dragging
    canvas.addEventListener('pointerdown', onCanvasDown);
    canvas.addEventListener('pointermove', onCanvasMove);
    canvas.addEventListener('pointerup', onCanvasUp);

    document.getElementById('add-shared-btn').addEventListener('click', () => addQuickZone('shared'));
    document.getElementById('add-player-btn').addEventListener('click', () => addQuickZone('per_player'));

    document.getElementById('save-start-btn').addEventListener('click', openTable);
    document.getElementById('save-template-btn').addEventListener('click', saveAsTemplate);

    document.getElementById('zone-form').addEventListener('submit', onZoneFormSubmit);
    document.getElementById('zd-cancel').addEventListener('click', () => {
        document.getElementById('zone-dialog').style.display = 'none';
    });
    document.getElementById('zd-delete').addEventListener('click', onZoneDelete);
}

function renderZones() {
    const canvas = document.getElementById('zb-canvas');
    if (!canvas) return;

    // Remove existing zone elements
    canvas.querySelectorAll('.zb-zone').forEach(el => el.remove());

    zones.forEach(z => {
        const el = document.createElement('div');
        el.className = `zb-zone ${z.zone_type === 'per_player' ? 'zb-zone-player' : ''}`;
        el.dataset.id = z.id;
        el.style.left = z.pos_x + '%';
        el.style.top = z.pos_y + '%';
        el.style.width = z.width + '%';
        el.style.height = z.height + '%';
        el.style.backgroundColor = z.color + '4D'; // 30% opacity
        el.style.borderColor = z.color;

        el.innerHTML = `
            <div class="zb-zone-label">${esc(z.label)}</div>
            ${z.zone_type === 'per_player' ? '<span class="zb-badge">Per Player</span>' : ''}
            <div class="zb-zone-resize-handle"></div>
        `;

        // Drag zone
        el.addEventListener('pointerdown', (e) => {
            if (e.target.classList.contains('zb-zone-resize-handle')) {
                startResize(e, z);
            } else {
                startDrag(e, z);
            }
            e.stopPropagation();
        });

        // Double-click to edit
        el.addEventListener('dblclick', (e) => {
            e.stopPropagation();
            openZoneDialog(z);
        });

        canvas.appendChild(el);
    });
}

function startDrag(e, zone) {
    const canvas = document.getElementById('zb-canvas');
    const rect = canvas.getBoundingClientRect();
    dragState = {
        zone,
        startX: e.clientX,
        startY: e.clientY,
        origX: zone.pos_x,
        origY: zone.pos_y,
        canvasRect: rect,
    };
    document.addEventListener('pointermove', onDragMove);
    document.addEventListener('pointerup', onDragEnd);
}

function onDragMove(e) {
    if (!dragState) return;
    const dx = (e.clientX - dragState.startX) / dragState.canvasRect.width * 100;
    const dy = (e.clientY - dragState.startY) / dragState.canvasRect.height * 100;
    dragState.zone.pos_x = Math.max(0, Math.min(100 - dragState.zone.width, dragState.origX + dx));
    dragState.zone.pos_y = Math.max(0, Math.min(100 - dragState.zone.height, dragState.origY + dy));
    renderZones();
}

async function onDragEnd() {
    document.removeEventListener('pointermove', onDragMove);
    document.removeEventListener('pointerup', onDragEnd);
    if (dragState) {
        const z = dragState.zone;
        try {
            await api.put(`/tables/${tableId}/zones/${z.id}`, { pos_x: z.pos_x, pos_y: z.pos_y });
        } catch (e) { console.error(e); }
        dragState = null;
    }
}

function startResize(e, zone) {
    const canvas = document.getElementById('zb-canvas');
    const rect = canvas.getBoundingClientRect();
    resizeState = {
        zone,
        startX: e.clientX,
        startY: e.clientY,
        origW: zone.width,
        origH: zone.height,
        canvasRect: rect,
    };
    document.addEventListener('pointermove', onResizeMove);
    document.addEventListener('pointerup', onResizeEnd);
}

function onResizeMove(e) {
    if (!resizeState) return;
    const dx = (e.clientX - resizeState.startX) / resizeState.canvasRect.width * 100;
    const dy = (e.clientY - resizeState.startY) / resizeState.canvasRect.height * 100;
    resizeState.zone.width = Math.max(5, resizeState.origW + dx);
    resizeState.zone.height = Math.max(5, resizeState.origH + dy);
    renderZones();
}

async function onResizeEnd() {
    document.removeEventListener('pointermove', onResizeMove);
    document.removeEventListener('pointerup', onResizeEnd);
    if (resizeState) {
        const z = resizeState.zone;
        try {
            await api.put(`/tables/${tableId}/zones/${z.id}`, { width: z.width, height: z.height });
        } catch (e) { console.error(e); }
        resizeState = null;
    }
}

// Canvas draw handlers
function onCanvasDown(e) {
    if (e.target.closest('.zb-zone')) return;
    const canvas = document.getElementById('zb-canvas');
    const rect = canvas.getBoundingClientRect();
    drawing = true;
    drawStart = {
        x: (e.clientX - rect.left) / rect.width * 100,
        y: (e.clientY - rect.top) / rect.height * 100,
        rect,
    };
}

function onCanvasMove(e) {
    if (!drawing || !drawStart) return;
    // Show preview rectangle
    let preview = document.getElementById('zb-draw-preview');
    if (!preview) {
        preview = document.createElement('div');
        preview.id = 'zb-draw-preview';
        preview.className = 'zb-draw-preview';
        document.getElementById('zb-canvas').appendChild(preview);
    }
    const curX = (e.clientX - drawStart.rect.left) / drawStart.rect.width * 100;
    const curY = (e.clientY - drawStart.rect.top) / drawStart.rect.height * 100;
    preview.style.left = Math.min(drawStart.x, curX) + '%';
    preview.style.top = Math.min(drawStart.y, curY) + '%';
    preview.style.width = Math.abs(curX - drawStart.x) + '%';
    preview.style.height = Math.abs(curY - drawStart.y) + '%';
}

function onCanvasUp(e) {
    if (!drawing || !drawStart) return;
    drawing = false;
    const preview = document.getElementById('zb-draw-preview');
    if (preview) preview.remove();

    const curX = (e.clientX - drawStart.rect.left) / drawStart.rect.width * 100;
    const curY = (e.clientY - drawStart.rect.top) / drawStart.rect.height * 100;
    const w = Math.abs(curX - drawStart.x);
    const h = Math.abs(curY - drawStart.y);

    if (w < 3 || h < 3) { drawStart = null; return; } // Too small

    const newZone = {
        pos_x: Math.min(drawStart.x, curX),
        pos_y: Math.min(drawStart.y, curY),
        width: w,
        height: h,
        label: 'New Zone',
        zone_type: 'shared',
        layout_mode: 'stacked',
        flip_visibility: 'private',
        color: '#1E3A5F',
    };
    drawStart = null;
    openZoneDialog(newZone, true);
}

async function addQuickZone(type) {
    const label = type === 'per_player' ? 'Player Area' : 'Zone';
    const existingCount = zones.length;
    try {
        const data = await api.post(`/tables/${tableId}/zones`, {
            label: `${label} ${existingCount + 1}`,
            zone_type: type,
            pos_x: 10 + (existingCount * 5) % 60,
            pos_y: 10 + (existingCount * 5) % 60,
            width: 20,
            height: 15,
        });
        zones.push(data.zone);
        renderZones();
    } catch (e) {
        alert(e.message);
    }
}

function openZoneDialog(zone, isNew = false) {
    selectedZone = { ...zone, _isNew: isNew };
    document.getElementById('zone-dialog-title').textContent = isNew ? 'New Zone' : 'Edit Zone';
    document.getElementById('zd-label').value = zone.label || '';
    document.getElementById('zd-type').value = zone.zone_type || 'shared';
    document.getElementById('zd-layout').value = zone.layout_mode || 'stacked';
    document.getElementById('zd-visibility').value = zone.flip_visibility || 'private';
    document.getElementById('zd-color').value = zone.color || '#1E3A5F';
    document.getElementById('zd-delete').style.display = isNew ? 'none' : 'inline-block';
    document.getElementById('zone-dialog').style.display = 'flex';
}

async function onZoneFormSubmit(e) {
    e.preventDefault();
    const data = {
        label: document.getElementById('zd-label').value.trim(),
        zone_type: document.getElementById('zd-type').value,
        layout_mode: document.getElementById('zd-layout').value,
        flip_visibility: document.getElementById('zd-visibility').value,
        color: document.getElementById('zd-color').value,
    };

    try {
        if (selectedZone._isNew) {
            const result = await api.post(`/tables/${tableId}/zones`, {
                ...data,
                pos_x: selectedZone.pos_x,
                pos_y: selectedZone.pos_y,
                width: selectedZone.width,
                height: selectedZone.height,
            });
            zones.push(result.zone);
        } else {
            await api.put(`/tables/${tableId}/zones/${selectedZone.id}`, data);
            const idx = zones.findIndex(z => z.id === selectedZone.id);
            if (idx >= 0) Object.assign(zones[idx], data);
        }
        renderZones();
        document.getElementById('zone-dialog').style.display = 'none';
    } catch (err) {
        alert(err.message);
    }
}

async function onZoneDelete() {
    if (!selectedZone || selectedZone._isNew) return;
    try {
        await api.delete(`/tables/${tableId}/zones/${selectedZone.id}`);
        zones = zones.filter(z => z.id !== selectedZone.id);
        renderZones();
        document.getElementById('zone-dialog').style.display = 'none';
    } catch (err) {
        alert(err.message);
    }
}

async function openTable() {
    try {
        // Table is already created, just go to the lobby view
        window.location.hash = `#/table/${tableId}`;
    } catch (e) {
        alert(e.message);
    }
}

async function saveAsTemplate() {
    const name = prompt('Template name:');
    if (!name) return;
    try {
        await api.post('/templates', {
            name,
            from_table_id: tableId,
            is_public: false,
        });
        alert('Template saved!');
    } catch (e) {
        alert(e.message);
    }
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
