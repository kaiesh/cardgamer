/**
 * Simple pub/sub state store.
 */
const store = {
    user: null,
    currentTable: null,
    tableState: null,
    tables: [],
    onlineUsers: [],
    _listeners: new Map(),

    set(key, value) {
        this[key] = value;
        this.emit(key);
    },

    on(key, fn) {
        if (!this._listeners.has(key)) {
            this._listeners.set(key, new Set());
        }
        this._listeners.get(key).add(fn);
    },

    off(key, fn) {
        if (this._listeners.has(key)) {
            this._listeners.get(key).delete(fn);
        }
    },

    emit(key, ...args) {
        if (this._listeners.has(key)) {
            const payload = args.length > 0 ? args[0] : this[key];
            this._listeners.get(key).forEach(fn => fn(payload));
        }
    },
};

export default store;
