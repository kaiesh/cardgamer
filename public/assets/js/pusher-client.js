/**
 * Pusher subscription manager.
 */
import store from './store.js';

class PusherManager {
    constructor() {
        this.pusher = null;
        this.channels = {};
    }

    init() {
        if (this.pusher) return;
        this.pusher = new Pusher(window.PUSHER_KEY, {
            cluster: window.PUSHER_CLUSTER,
            authEndpoint: '/api/v1/pusher/auth',
            auth: {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            },
        });

        this.pusher.connection.bind('connected', () => {
            console.log('Pusher connected');
        });

        this.pusher.connection.bind('error', (err) => {
            console.error('Pusher error:', err);
        });
    }

    subscribeToLobby() {
        this.init();
        if (this.channels.lobby) return;

        const channel = this.pusher.subscribe('presence-lobby');
        this.channels.lobby = channel;

        channel.bind('pusher:subscription_succeeded', (members) => {
            const users = [];
            members.each(m => users.push({ id: m.id, ...m.info }));
            store.set('onlineUsers', users);
        });

        channel.bind('pusher:member_added', (member) => {
            const users = [...store.onlineUsers, { id: member.id, ...member.info }];
            store.set('onlineUsers', users);
        });

        channel.bind('pusher:member_removed', (member) => {
            store.set('onlineUsers', store.onlineUsers.filter(u => u.id !== member.id));
        });

        channel.bind('lobby-updates', (data) => {
            store.emit('lobbyUpdate');
        });
    }

    unsubscribeFromLobby() {
        if (this.channels.lobby) {
            this.pusher.unsubscribe('presence-lobby');
            delete this.channels.lobby;
        }
    }

    subscribeToTable(tableId) {
        this.init();
        const channelName = `private-table-${tableId}`;
        if (this.channels[channelName]) return;

        const channel = this.pusher.subscribe(channelName);
        this.channels[channelName] = channel;

        // Bind all game events
        const events = [
            'player.joined', 'player.left', 'player.kicked', 'player.connected', 'player.disconnected',
            'game.started', 'game.paused', 'game.resumed', 'game.closed',
            'card.shuffled', 'card.cut', 'card.dealt', 'card.dealt_to_zone', 'card.taken',
            'card.placed', 'card.given', 'card.flipped', 'card.peeked', 'card.unpeeked',
            'card.revealed', 'card.marked', 'card.removed', 'card.returned', 'card.swapped',
            'card.offered', 'card.offer_accepted', 'card.offer_declined', 'card.reordered',
            'card.discarded', 'card.forced_give', 'card.forced_take',
            'chips.transferred', 'chips.pot_created',
            'chat.message',
            'zone.created', 'zone.updated', 'zone.deleted',
        ];

        events.forEach(event => {
            channel.bind(event, (data) => {
                store.emit('tableEvent', { event, data });
                store.emit(`event:${event}`, data);
            });
        });
    }

    unsubscribeFromTable(tableId) {
        const channelName = `private-table-${tableId}`;
        if (this.channels[channelName]) {
            this.pusher.unsubscribe(channelName);
            delete this.channels[channelName];
        }
    }

    subscribeToUser(userId) {
        this.init();
        const channelName = `private-user-${userId}`;
        if (this.channels[channelName]) return;

        const channel = this.pusher.subscribe(channelName);
        this.channels[channelName] = channel;

        channel.bind('kicked', (data) => {
            store.emit('userKicked', data);
        });

        channel.bind('card.offered', (data) => {
            store.emit('offerReceived', data);
        });
    }

    disconnect() {
        if (this.pusher) {
            this.pusher.disconnect();
            this.pusher = null;
            this.channels = {};
        }
    }
}

export const pusherManager = new PusherManager();
