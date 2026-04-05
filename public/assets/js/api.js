/**
 * API client - fetch wrapper with auth handling.
 */
const API_BASE = '/api/v1';

async function apiRequest(path, options = {}) {
    const url = `${API_BASE}${path}`;
    const config = {
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
        ...options,
    };

    if (options.body && !(options.body instanceof FormData)) {
        config.headers['Content-Type'] = 'application/json';
        config.body = JSON.stringify(options.body);
    }

    const response = await fetch(url, config);

    if (response.status === 401) {
        window.location.hash = '#/login';
        throw new Error('Unauthorized');
    }

    const data = await response.json();

    if (!response.ok) {
        const err = new Error(data.error || 'Request failed');
        err.code = data.code;
        err.status = response.status;
        throw err;
    }

    return data.data;
}

export const api = {
    get:    (path) => apiRequest(path, { method: 'GET' }),
    post:   (path, body) => apiRequest(path, { method: 'POST', body }),
    put:    (path, body) => apiRequest(path, { method: 'PUT', body }),
    delete: (path) => apiRequest(path, { method: 'DELETE' }),
    upload: (path, formData) => apiRequest(path, { method: 'POST', body: formData }),
};
