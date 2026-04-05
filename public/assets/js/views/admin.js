import { api } from '../api.js';

let container;
let currentSection = 'dashboard';

export function mount(el) {
    container = el;
    render();
    loadSection('dashboard');
}

export function unmount() { if (container) container.innerHTML = ''; }

function render() {
    container.innerHTML = `
        <div class="admin-page">
            <header class="page-header">
                <a href="#/lobby" class="btn btn-ghost">&larr; Back</a>
                <h1>Admin Panel</h1>
            </header>
            <div class="admin-layout">
                <nav class="admin-nav">
                    <button class="admin-nav-btn active" data-section="dashboard">Dashboard</button>
                    <button class="admin-nav-btn" data-section="users">Users</button>
                    <button class="admin-nav-btn" data-section="tables">Tables</button>
                    <button class="admin-nav-btn" data-section="skins">Deck Skins</button>
                    <button class="admin-nav-btn" data-section="logos">Table Logos</button>
                    <button class="admin-nav-btn" data-section="phrases">Chat Phrases</button>
                </nav>
                <div class="admin-content" id="admin-content"></div>
            </div>
        </div>
    `;

    container.querySelectorAll('.admin-nav-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            container.querySelectorAll('.admin-nav-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            loadSection(btn.dataset.section);
        });
    });
}

async function loadSection(section) {
    currentSection = section;
    const content = document.getElementById('admin-content');
    if (!content) return;
    content.innerHTML = '<div class="loading">Loading...</div>';

    try {
        switch (section) {
            case 'dashboard': {
                const data = await api.get('/admin/stats');
                const s = data.stats;
                content.innerHTML = `
                    <div class="stats-grid">
                        <div class="stat-card"><h3>${s.total_users}</h3><p>Total Users</p></div>
                        <div class="stat-card"><h3>${s.online_users}</h3><p>Online Now</p></div>
                        <div class="stat-card"><h3>${s.active_tables}</h3><p>Active Tables</p></div>
                        <div class="stat-card"><h3>${s.lobby_tables}</h3><p>In Lobby</p></div>
                        <div class="stat-card"><h3>${s.total_games}</h3><p>Total Games</p></div>
                    </div>
                `;
                break;
            }
            case 'users': {
                const data = await api.get('/admin/users');
                content.innerHTML = `
                    <table class="admin-table">
                        <thead><tr><th>Name</th><th>Email</th><th>Joined</th><th>Last Active</th></tr></thead>
                        <tbody>${data.users.map(u => `
                            <tr>
                                <td><span class="avatar avatar-sm" style="background:${u.avatar_color}">${(u.display_name || '?')[0]}</span> ${esc(u.display_name || 'No name')}</td>
                                <td>${esc(u.email)}</td>
                                <td>${new Date(u.created_at).toLocaleDateString()}</td>
                                <td>${u.last_active ? new Date(u.last_active * 1000).toLocaleString() : 'Never'}</td>
                            </tr>
                        `).join('')}</tbody>
                    </table>
                `;
                break;
            }
            case 'tables': {
                const data = await api.get('/admin/tables');
                content.innerHTML = `
                    <table class="admin-table">
                        <thead><tr><th>Name</th><th>Status</th><th>Creator</th><th>Players</th><th>Created</th></tr></thead>
                        <tbody>${data.tables.map(t => `
                            <tr>
                                <td>${esc(t.name)}</td>
                                <td><span class="badge badge-${t.status}">${t.status}</span></td>
                                <td>${esc(t.creator_name)}</td>
                                <td>${t.player_count}</td>
                                <td>${new Date(t.created_at).toLocaleDateString()}</td>
                            </tr>
                        `).join('')}</tbody>
                    </table>
                `;
                break;
            }
            case 'skins': {
                const data = await api.get('/admin/deck-skins');
                content.innerHTML = `
                    <div class="upload-form">
                        <h3>Upload Deck Skin</h3>
                        <form id="skin-upload-form">
                            <input type="text" id="skin-name" placeholder="Skin name" required>
                            <input type="file" id="skin-file" accept="image/*" required>
                            <button type="submit" class="btn btn-primary btn-sm">Upload</button>
                        </form>
                    </div>
                    <div class="skins-grid">${data.skins.map(s => `
                        <div class="skin-card">
                            <img src="${s.back_image_path}" alt="${esc(s.name)}">
                            <p>${esc(s.name)}</p>
                            <span class="badge">${s.is_active ? 'Active' : 'Inactive'}</span>
                            <button class="btn btn-danger btn-sm del-skin" data-id="${s.id}">Delete</button>
                        </div>
                    `).join('')}</div>
                `;
                document.getElementById('skin-upload-form')?.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const fd = new FormData();
                    fd.append('name', document.getElementById('skin-name').value);
                    fd.append('image', document.getElementById('skin-file').files[0]);
                    try { await api.upload('/admin/deck-skins', fd); loadSection('skins'); } catch (err) { alert(err.message); }
                });
                content.querySelectorAll('.del-skin').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        try { await api.delete(`/admin/deck-skins/${btn.dataset.id}`); loadSection('skins'); } catch (err) { alert(err.message); }
                    });
                });
                break;
            }
            case 'logos': {
                const data = await api.get('/admin/table-logos');
                content.innerHTML = `
                    <div class="upload-form">
                        <h3>Upload Table Logo</h3>
                        <form id="logo-upload-form">
                            <input type="text" id="logo-name" placeholder="Logo name" required>
                            <input type="file" id="logo-file" accept="image/*" required>
                            <button type="submit" class="btn btn-primary btn-sm">Upload</button>
                        </form>
                    </div>
                    <div class="skins-grid">${data.logos.map(l => `
                        <div class="skin-card">
                            <img src="${l.image_path}" alt="${esc(l.name)}">
                            <p>${esc(l.name)}</p>
                            <button class="btn btn-danger btn-sm del-logo" data-id="${l.id}">Delete</button>
                        </div>
                    `).join('')}</div>
                `;
                document.getElementById('logo-upload-form')?.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const fd = new FormData();
                    fd.append('name', document.getElementById('logo-name').value);
                    fd.append('image', document.getElementById('logo-file').files[0]);
                    try { await api.upload('/admin/table-logos', fd); loadSection('logos'); } catch (err) { alert(err.message); }
                });
                content.querySelectorAll('.del-logo').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        try { await api.delete(`/admin/table-logos/${btn.dataset.id}`); loadSection('logos'); } catch (err) { alert(err.message); }
                    });
                });
                break;
            }
            case 'phrases': {
                const data = await api.get('/admin/chat-phrases');
                content.innerHTML = `
                    <div class="upload-form">
                        <h3>Add Phrase</h3>
                        <form id="phrase-form" style="display:flex;gap:8px">
                            <input type="text" id="phrase-input" placeholder="New phrase..." maxlength="200" required style="flex:1">
                            <button type="submit" class="btn btn-primary btn-sm">Add</button>
                        </form>
                    </div>
                    <div class="phrases-list">${data.phrases.map(p => `
                        <div class="phrase-item">
                            <span>${esc(p.phrase)}</span>
                            <button class="btn btn-danger btn-sm del-phrase" data-id="${p.id}">x</button>
                        </div>
                    `).join('')}</div>
                `;
                document.getElementById('phrase-form')?.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const phrase = document.getElementById('phrase-input').value.trim();
                    try { await api.post('/admin/chat-phrases', { phrase }); loadSection('phrases'); } catch (err) { alert(err.message); }
                });
                content.querySelectorAll('.del-phrase').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        try { await api.delete(`/admin/chat-phrases/${btn.dataset.id}`); loadSection('phrases'); } catch (err) { alert(err.message); }
                    });
                });
                break;
            }
        }
    } catch (e) {
        content.innerHTML = `<div class="error-msg">${esc(e.message)}</div>`;
    }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
