/**
 * SPA shell - hash-based router, auth state, app bootstrap.
 */
import { api } from './api.js';
import store from './store.js';
import { pusherManager } from './pusher-client.js';

// View modules (lazy-ish imports)
const views = {
    login:       () => import('./views/login.js'),
    lobby:       () => import('./views/lobby.js'),
    create:      () => import('./views/table-create.js'),
    zones:       () => import('./views/zone-builder.js'),
    game:        () => import('./views/game.js'),
    templates:   () => import('./views/templates.js'),
    history:     () => import('./views/history.js'),
    admin:       () => import('./views/admin.js'),
};

let currentView = null;
let currentViewName = null;

const appEl = document.getElementById('app');

// Router
function parseHash() {
    const hash = window.location.hash.slice(1) || '/login';
    const parts = hash.split('/').filter(Boolean);
    return { path: parts[0] || 'login', params: { id: parts[1] } };
}

async function navigate() {
    const { path, params } = parseHash();

    // Auth guard
    if (path !== 'login' && !store.user) {
        try {
            const data = await api.get('/auth/me');
            store.set('user', data.user);
            if (store.user) {
                pusherManager.subscribeToUser(store.user.id);
            }
        } catch {
            window.location.hash = '#/login';
            return;
        }
    }

    // Don't re-mount same view
    if (currentViewName === path + (params.id || '')) return;

    // Unmount current
    if (currentView?.unmount) {
        currentView.unmount();
    }

    currentViewName = path + (params.id || '');

    // Map routes to views
    let viewKey;
    switch (path) {
        case 'login':     viewKey = 'login'; break;
        case 'lobby':     viewKey = 'lobby'; break;
        case 'create':    viewKey = 'create'; break;
        case 'zones':     viewKey = 'zones'; break;
        case 'table':     viewKey = 'game'; break;
        case 'templates': viewKey = 'templates'; break;
        case 'history':   viewKey = 'history'; break;
        case 'admin':     viewKey = 'admin'; break;
        default:
            window.location.hash = '#/lobby';
            return;
    }

    try {
        const viewModule = await views[viewKey]();
        currentView = viewModule;
        appEl.innerHTML = '';
        viewModule.mount(appEl, params);
    } catch (err) {
        console.error('View load error:', err);
        appEl.innerHTML = '<div class="error-page"><h1>Something went wrong</h1><p>' + err.message + '</p><a href="#/lobby">Go to Lobby</a></div>';
    }
}

// Listen for hash changes
window.addEventListener('hashchange', navigate);

// Initial load
navigate();
