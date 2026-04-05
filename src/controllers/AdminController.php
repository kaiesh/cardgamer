<?php

class AdminController {
    public static function stats(): array {
        requireAdmin();
        $db = DB::get();

        $stats = [];
        $stats['total_users'] = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $stats['active_tables'] = (int)$db->query("SELECT COUNT(*) FROM `tables` WHERE status = 'active'")->fetchColumn();
        $stats['lobby_tables'] = (int)$db->query("SELECT COUNT(*) FROM `tables` WHERE status = 'lobby'")->fetchColumn();
        $stats['total_games'] = (int)$db->query("SELECT COUNT(*) FROM `tables` WHERE status IN ('active','paused','closed')")->fetchColumn();
        $stats['online_users'] = (int)$db->prepare("SELECT COUNT(DISTINCT user_id) FROM sessions WHERE last_activity > ? AND user_id IS NOT NULL")
            ->execute([time() - 300]) ? $db->query("SELECT COUNT(DISTINCT user_id) FROM sessions WHERE last_activity > " . (time() - 300) . " AND user_id IS NOT NULL")->fetchColumn() : 0;

        return ['stats' => $stats];
    }

    public static function listUsers(): array {
        requireAdmin();
        $db = DB::get();
        $stmt = $db->query("
            SELECT u.id, u.email, u.display_name, u.avatar_color, u.created_at,
                   (SELECT MAX(last_activity) FROM sessions WHERE user_id = u.id) as last_active
            FROM users u ORDER BY u.created_at DESC
        ");
        return ['users' => $stmt->fetchAll()];
    }

    public static function listAllTables(): array {
        requireAdmin();
        $db = DB::get();
        $status = $_GET['status'] ?? null;

        $sql = "SELECT t.*, u.display_name as creator_name,
                (SELECT COUNT(*) FROM table_players WHERE table_id = t.id) as player_count
                FROM `tables` t JOIN users u ON u.id = t.creator_id";
        $params = [];
        if ($status) {
            $sql .= " WHERE t.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY t.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return ['tables' => $stmt->fetchAll()];
    }

    // Deck Skins
    public static function listDeckSkins(): array {
        requireAdmin();
        return ['skins' => DB::get()->query("SELECT * FROM deck_skins ORDER BY name")->fetchAll()];
    }

    public static function uploadDeckSkin(): array {
        requireAdmin();
        $name = sanitizeString($_POST['name'] ?? '', 100);
        if (!$name) errorResponse('Name required', 'MISSING_PARAM');

        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) errorResponse('Image upload failed', 'UPLOAD_FAILED');

        $config = getConfig();
        if ($file['size'] > $config['app']['max_upload_size']) errorResponse('File too large', 'FILE_TOO_LARGE');

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) errorResponse('Invalid file type', 'INVALID_TYPE');

        $ext = match($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' };
        $filename = 'skin_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $uploadDir = __DIR__ . '/../../public/assets/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        move_uploaded_file($file['tmp_name'], $uploadDir . $filename);

        $path = '/assets/uploads/' . $filename;
        $db = DB::get();
        $db->prepare("INSERT INTO deck_skins (name, back_image_path) VALUES (?, ?)")->execute([$name, $path]);

        return ['skin' => ['id' => (int)$db->lastInsertId(), 'name' => $name, 'back_image_path' => $path]];
    }

    public static function updateDeckSkin(int $id): array {
        requireAdmin();
        $body = jsonBody();
        $db = DB::get();

        $fields = [];
        $params = [];
        if (isset($body['name'])) { $fields[] = 'name = ?'; $params[] = sanitizeString($body['name'], 100); }
        if (isset($body['is_active'])) { $fields[] = 'is_active = ?'; $params[] = $body['is_active'] ? 1 : 0; }

        if (empty($fields)) errorResponse('No fields to update', 'NO_UPDATES');
        $params[] = $id;
        $db->prepare('UPDATE deck_skins SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

        return ['message' => 'Updated'];
    }

    public static function deleteDeckSkin(int $id): array {
        requireAdmin();
        $db = DB::get();

        // Check not in use
        $stmt = $db->prepare("SELECT COUNT(*) FROM `tables` WHERE deck_skin_id = ? AND status IN ('lobby','active','paused')");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) errorResponse('Skin is in use by active tables', 'IN_USE');

        $db->prepare("DELETE FROM deck_skins WHERE id = ?")->execute([$id]);
        return ['message' => 'Deleted'];
    }

    // Table Logos
    public static function listTableLogos(): array {
        requireAdmin();
        return ['logos' => DB::get()->query("SELECT * FROM table_logos ORDER BY name")->fetchAll()];
    }

    public static function uploadTableLogo(): array {
        requireAdmin();
        $name = sanitizeString($_POST['name'] ?? '', 100);
        if (!$name) errorResponse('Name required', 'MISSING_PARAM');

        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) errorResponse('Image upload failed', 'UPLOAD_FAILED');

        $config = getConfig();
        if ($file['size'] > $config['app']['max_upload_size']) errorResponse('File too large', 'FILE_TOO_LARGE');

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) errorResponse('Invalid file type', 'INVALID_TYPE');

        $ext = match($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' };
        $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $uploadDir = __DIR__ . '/../../public/assets/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        move_uploaded_file($file['tmp_name'], $uploadDir . $filename);

        $path = '/assets/uploads/' . $filename;
        $db = DB::get();
        $db->prepare("INSERT INTO table_logos (name, image_path) VALUES (?, ?)")->execute([$name, $path]);

        return ['logo' => ['id' => (int)$db->lastInsertId(), 'name' => $name, 'image_path' => $path]];
    }

    public static function updateTableLogo(int $id): array {
        requireAdmin();
        $body = jsonBody();
        $db = DB::get();

        $fields = [];
        $params = [];
        if (isset($body['name'])) { $fields[] = 'name = ?'; $params[] = sanitizeString($body['name'], 100); }
        if (isset($body['is_active'])) { $fields[] = 'is_active = ?'; $params[] = $body['is_active'] ? 1 : 0; }

        if (empty($fields)) errorResponse('No fields to update', 'NO_UPDATES');
        $params[] = $id;
        $db->prepare('UPDATE table_logos SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

        return ['message' => 'Updated'];
    }

    public static function deleteTableLogo(int $id): array {
        requireAdmin();
        $db = DB::get();
        $db->prepare("DELETE FROM table_logos WHERE id = ?")->execute([$id]);
        return ['message' => 'Deleted'];
    }

    // Chat Phrases
    public static function listDefaultPhrases(): array {
        requireAdmin();
        return ['phrases' => DB::get()->query("SELECT * FROM chat_phrases WHERE is_default = 1 AND table_id IS NULL ORDER BY id")->fetchAll()];
    }

    public static function addDefaultPhrase(): array {
        requireAdmin();
        $body = jsonBody();
        $phrase = sanitizeString(requireParam($body, 'phrase'), 200);
        $db = DB::get();
        $db->prepare("INSERT INTO chat_phrases (phrase, is_default, table_id) VALUES (?, 1, NULL)")->execute([$phrase]);
        return ['phrase' => ['id' => (int)$db->lastInsertId(), 'phrase' => $phrase]];
    }

    public static function deleteDefaultPhrase(int $id): array {
        requireAdmin();
        DB::get()->prepare("DELETE FROM chat_phrases WHERE id = ? AND is_default = 1")->execute([$id]);
        return ['message' => 'Deleted'];
    }
}
