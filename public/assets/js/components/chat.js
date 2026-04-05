/**
 * Chat component - phrase picker.
 */
import { api } from '../api.js';

export async function renderChat(container, state, tableId) {
    container.innerHTML = '<div class="chat-panel"><div class="chat-messages" id="chat-messages"></div><div class="chat-phrases" id="chat-phrases"><div class="loading">Loading phrases...</div></div></div>';

    // Load messages and phrases
    try {
        const [msgData, phraseData] = await Promise.all([
            api.get(`/tables/${tableId}/chat?limit=30`),
            api.get(`/tables/${tableId}/chat/phrases`),
        ]);

        renderMessages(msgData.messages);
        renderPhrases(phraseData.phrases, tableId);
    } catch (e) { console.error(e); }
}

function renderMessages(messages) {
    const el = document.getElementById('chat-messages');
    if (!el) return;
    el.innerHTML = messages.map(m => `
        <div class="chat-msg">
            <strong>${esc(m.display_name)}</strong>
            <span>${esc(m.phrase)}</span>
            <small>${new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</small>
        </div>
    `).join('') || '<div class="empty-state">No messages yet</div>';
    el.scrollTop = el.scrollHeight;
}

function renderPhrases(phrases, tableId) {
    const el = document.getElementById('chat-phrases');
    if (!el) return;
    el.innerHTML = '<div class="phrase-grid">' + phrases.map(p => `
        <button class="phrase-btn" data-id="${p.id}">${esc(p.phrase)}</button>
    `).join('') + '</div>';

    el.querySelectorAll('.phrase-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            try {
                await api.post(`/tables/${tableId}/chat`, { phrase_id: parseInt(btn.dataset.id) });
                btn.classList.add('phrase-sent');
                setTimeout(() => btn.classList.remove('phrase-sent'), 500);
            } catch (e) { console.error(e); }
        });
    });
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
