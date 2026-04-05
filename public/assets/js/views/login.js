import { api } from '../api.js';
import store from '../store.js';

let container;

export function mount(el) {
    container = el;
    container.innerHTML = `
        <div class="auth-page">
            <div class="auth-card">
                <h1 class="auth-title">&#x1F0CF; Card Table</h1>
                <p class="auth-subtitle">Sign in with your email to play</p>
                <div id="email-step">
                    <form id="email-form">
                        <input type="email" id="email-input" placeholder="your@email.com" required autocomplete="email">
                        <button type="submit" class="btn btn-primary" id="email-btn">Send Login Code</button>
                    </form>
                </div>
                <div id="otp-step" style="display:none">
                    <p class="otp-hint">Check your email for a 6-digit code</p>
                    <form id="otp-form">
                        <input type="text" id="otp-input" placeholder="000000" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required>
                        <button type="submit" class="btn btn-primary" id="otp-btn">Verify</button>
                    </form>
                    <button class="btn btn-link" id="back-btn">Use different email</button>
                </div>
                <div id="name-step" style="display:none">
                    <p>Choose a display name</p>
                    <form id="name-form">
                        <input type="text" id="name-input" placeholder="Your name" maxlength="50" required>
                        <button type="submit" class="btn btn-primary">Let's Play</button>
                    </form>
                </div>
                <div id="auth-error" class="error-msg" style="display:none"></div>
            </div>
        </div>
    `;

    let email = '';

    document.getElementById('email-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('email-btn');
        const errEl = document.getElementById('auth-error');
        errEl.style.display = 'none';
        email = document.getElementById('email-input').value.trim();
        btn.disabled = true;
        btn.textContent = 'Sending...';
        try {
            await api.post('/auth/request-otp', { email });
            document.getElementById('email-step').style.display = 'none';
            document.getElementById('otp-step').style.display = 'block';
            document.getElementById('otp-input').focus();
        } catch (err) {
            errEl.textContent = err.message;
            errEl.style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Send Login Code';
        }
    });

    document.getElementById('otp-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('otp-btn');
        const errEl = document.getElementById('auth-error');
        errEl.style.display = 'none';
        const otp = document.getElementById('otp-input').value.trim();
        btn.disabled = true;
        btn.textContent = 'Verifying...';
        try {
            const data = await api.post('/auth/verify-otp', { email, otp });
            store.set('user', data.user);
            if (data.user.is_new || !data.user.display_name) {
                document.getElementById('otp-step').style.display = 'none';
                document.getElementById('name-step').style.display = 'block';
                document.getElementById('name-input').focus();
            } else {
                window.location.hash = '#/lobby';
            }
        } catch (err) {
            errEl.textContent = err.message;
            errEl.style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Verify';
        }
    });

    document.getElementById('back-btn').addEventListener('click', () => {
        document.getElementById('otp-step').style.display = 'none';
        document.getElementById('email-step').style.display = 'block';
    });

    document.getElementById('name-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = document.getElementById('name-input').value.trim();
        try {
            await api.put('/auth/me', { display_name: name });
            store.user.display_name = name;
            store.emit('user');
            window.location.hash = '#/lobby';
        } catch (err) {
            document.getElementById('auth-error').textContent = err.message;
            document.getElementById('auth-error').style.display = 'block';
        }
    });
}

export function unmount() {
    if (container) container.innerHTML = '';
}
