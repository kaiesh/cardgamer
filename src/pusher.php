<?php
/**
 * Pusher server-side wrapper.
 */

function getPusher(): Pusher\Pusher {
    static $pusher = null;
    if ($pusher === null) {
        $config = getConfig()['pusher'];
        $pusher = new Pusher\Pusher(
            $config['key'],
            $config['secret'],
            $config['app_id'],
            [
                'cluster'   => $config['cluster'],
                'useTLS'    => $config['use_tls'],
            ]
        );
    }
    return $pusher;
}

function triggerTableEvent(string $tableId, string $event, array $payload, ?string $actorId = null, ?string $actorName = null): void {
    $data = [
        'actor'     => $actorId ? ['id' => $actorId, 'name' => $actorName ?? ''] : null,
        'action'    => $event,
        'payload'   => $payload,
        'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
    ];
    getPusher()->trigger("private-table-{$tableId}", $event, $data);
}

function triggerLobbyEvent(string $event, array $payload): void {
    getPusher()->trigger('presence-lobby', $event, $payload);
}

function triggerUserEvent(string $userId, string $event, array $payload): void {
    getPusher()->trigger("private-user-{$userId}", $event, $payload);
}
