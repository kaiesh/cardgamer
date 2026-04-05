<?php

class LobbyController {
    public static function listTables(): array {
        requireAuth();
        $db = DB::get();
        $stmt = $db->query("
            SELECT t.id, t.name, t.status, t.num_decks, t.include_jokers, t.chip_initial,
                   t.created_at, u.display_name as creator_name, u.avatar_color as creator_color,
                   (SELECT COUNT(*) FROM table_players WHERE table_id = t.id) as player_count
            FROM `tables` t
            JOIN users u ON u.id = t.creator_id
            WHERE t.status = 'lobby'
            ORDER BY t.created_at DESC
        ");
        return ['tables' => $stmt->fetchAll()];
    }

    public static function getTable(string $id): array {
        requireAuth();
        $db = DB::get();
        $stmt = $db->prepare("
            SELECT t.*, u.display_name as creator_name
            FROM `tables` t
            JOIN users u ON u.id = t.creator_id
            WHERE t.id = ?
        ");
        $stmt->execute([$id]);
        $table = $stmt->fetch();
        if (!$table) {
            errorResponse('Table not found', 'TABLE_NOT_FOUND', 404);
        }

        $stmt = $db->prepare("
            SELECT tp.*, u.display_name, u.avatar_color, u.email
            FROM table_players tp
            JOIN users u ON u.id = tp.user_id
            WHERE tp.table_id = ?
            ORDER BY tp.seat_index
        ");
        $stmt->execute([$id]);
        $table['players'] = $stmt->fetchAll();

        return ['table' => $table];
    }

    public static function onlineUsers(): array {
        requireAuth();
        $db = DB::get();
        $config = getConfig();
        $ttl = $config['app']['session_ttl'];
        $stmt = $db->prepare("
            SELECT DISTINCT u.id, u.display_name, u.avatar_color
            FROM sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.last_activity > ? AND s.user_id IS NOT NULL
        ");
        $stmt->execute([time() - 300]); // Active in last 5 minutes
        return ['users' => $stmt->fetchAll()];
    }
}
