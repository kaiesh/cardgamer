<?php

class ChatController {
    public static function send(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();
        requireTableMember($tableId, $user['id']);

        $body = jsonBody();
        $phraseId = (int)requireParam($body, 'phrase_id');

        // Validate phrase exists and is available for this table
        $stmt = $db->prepare("SELECT * FROM chat_phrases WHERE id = ? AND (table_id IS NULL OR table_id = ?)");
        $stmt->execute([$phraseId, $tableId]);
        $phrase = $stmt->fetch();
        if (!$phrase) errorResponse('Phrase not found', 'PHRASE_NOT_FOUND', 404);

        $stmt = $db->prepare("INSERT INTO table_chat (table_id, user_id, phrase_id) VALUES (?, ?, ?)");
        $stmt->execute([$tableId, $user['id'], $phraseId]);

        triggerTableEvent($tableId, 'chat.message', [
            'user_id' => $user['id'], 'phrase_id' => $phraseId, 'phrase_text' => $phrase['phrase'],
        ], $user['id'], $_SESSION['display_name']);

        return ['message' => 'Sent'];
    }

    public static function getMessages(string $tableId): array {
        requireAuth();
        $db = DB::get();

        $before = isset($_GET['before']) ? (int)$_GET['before'] : PHP_INT_MAX;
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

        $stmt = $db->prepare("
            SELECT tc.*, u.display_name, cp.phrase
            FROM table_chat tc
            JOIN users u ON u.id = tc.user_id
            JOIN chat_phrases cp ON cp.id = tc.phrase_id
            WHERE tc.table_id = ? AND tc.id < ?
            ORDER BY tc.id DESC LIMIT ?
        ");
        $stmt->execute([$tableId, $before, $limit]);
        return ['messages' => array_reverse($stmt->fetchAll())];
    }

    public static function getPhrases(string $tableId): array {
        requireAuth();
        $db = DB::get();
        $stmt = $db->prepare("
            SELECT * FROM chat_phrases
            WHERE (is_default = 1 AND table_id IS NULL) OR table_id = ?
            ORDER BY is_default DESC, id ASC
        ");
        $stmt->execute([$tableId]);
        return ['phrases' => $stmt->fetchAll()];
    }

    public static function addPhrase(string $tableId): array {
        $user = requireAuth();
        requireTableCreator($tableId, $user['id']);

        $body = jsonBody();
        $phrase = sanitizeString(requireParam($body, 'phrase'), 200);

        $db = DB::get();
        $stmt = $db->prepare("INSERT INTO chat_phrases (phrase, is_default, table_id) VALUES (?, 0, ?)");
        $stmt->execute([$phrase, $tableId]);

        return ['phrase' => ['id' => (int)$db->lastInsertId(), 'phrase' => $phrase, 'table_id' => $tableId]];
    }

    public static function deletePhrase(string $tableId, int $phraseId): array {
        $user = requireAuth();
        requireTableCreator($tableId, $user['id']);

        $db = DB::get();
        $db->prepare("DELETE FROM chat_phrases WHERE id = ? AND table_id = ?")->execute([$phraseId, $tableId]);

        return ['message' => 'Phrase deleted'];
    }
}
