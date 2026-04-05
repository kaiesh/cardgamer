<?php

class TemplateController {
    public static function list(): array {
        $user = requireAuth();
        $db = DB::get();
        $stmt = $db->prepare("
            SELECT t.*, u.display_name as creator_name
            FROM templates t JOIN users u ON u.id = t.creator_id
            WHERE t.is_public = 1 OR t.creator_id = ?
            ORDER BY t.name
        ");
        $stmt->execute([$user['id']]);
        return ['templates' => $stmt->fetchAll()];
    }

    public static function get(int $id): array {
        $user = requireAuth();
        $db = DB::get();
        $stmt = $db->prepare("SELECT * FROM templates WHERE id = ? AND (is_public = 1 OR creator_id = ?)");
        $stmt->execute([$id, $user['id']]);
        $template = $stmt->fetch();
        if (!$template) errorResponse('Template not found', 'NOT_FOUND', 404);

        $stmt = $db->prepare("SELECT * FROM template_zones WHERE template_id = ? ORDER BY z_order");
        $stmt->execute([$id]);
        $template['zones'] = $stmt->fetchAll();

        return ['template' => $template];
    }

    public static function create(): array {
        $user = requireAuth();
        $body = jsonBody();
        $db = DB::get();

        $name = sanitizeString(requireParam($body, 'name'), 100);
        $isPublic = !empty($body['is_public']) ? 1 : 0;
        $numDecks = max(1, min(8, (int)($body['num_decks'] ?? 1)));
        $includeJokers = !empty($body['include_jokers']) ? 1 : 0;
        $deckBacks = in_array($body['deck_backs'] ?? '', ['uniform', 'random_per_deck']) ? $body['deck_backs'] : 'uniform';
        $chipInitial = max(0, (int)($body['chip_initial'] ?? 0));
        $customPhrases = isset($body['custom_phrases']) ? json_encode($body['custom_phrases']) : null;

        $stmt = $db->prepare("
            INSERT INTO templates (creator_id, name, is_public, num_decks, include_jokers, deck_backs, chip_initial, custom_phrases)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user['id'], $name, $isPublic, $numDecks, $includeJokers, $deckBacks, $chipInitial, $customPhrases]);
        $templateId = (int)$db->lastInsertId();

        // Copy zones from table if provided
        if (!empty($body['from_table_id'])) {
            $zStmt = $db->prepare("SELECT * FROM zones WHERE table_id = ? AND owner_player_id IS NULL");
            $zStmt->execute([$body['from_table_id']]);
            $zones = $zStmt->fetchAll();
            $ins = $db->prepare("
                INSERT INTO template_zones (template_id, label, zone_type, layout_mode, flip_visibility, pos_x, pos_y, width, height, color, z_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($zones as $z) {
                $ins->execute([$templateId, $z['label'], $z['zone_type'], $z['layout_mode'], $z['flip_visibility'],
                    $z['pos_x'], $z['pos_y'], $z['width'], $z['height'], $z['color'], $z['z_order']]);
            }
        }

        // Or zones from body
        if (!empty($body['zones'])) {
            $ins = $db->prepare("
                INSERT INTO template_zones (template_id, label, zone_type, layout_mode, flip_visibility, pos_x, pos_y, width, height, color, z_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($body['zones'] as $z) {
                $ins->execute([
                    $templateId, $z['label'] ?? 'Zone', $z['zone_type'] ?? 'shared',
                    $z['layout_mode'] ?? 'stacked', $z['flip_visibility'] ?? 'private',
                    $z['pos_x'] ?? 10, $z['pos_y'] ?? 10, $z['width'] ?? 15, $z['height'] ?? 10,
                    $z['color'] ?? '#1E3A5F', $z['z_order'] ?? 0,
                ]);
            }
        }

        return ['template_id' => $templateId];
    }

    public static function update(int $id): array {
        $user = requireAuth();
        $body = jsonBody();
        $db = DB::get();

        $stmt = $db->prepare("SELECT * FROM templates WHERE id = ? AND creator_id = ?");
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) errorResponse('Template not found or not yours', 'NOT_FOUND', 404);

        $fields = [];
        $params = [];
        if (isset($body['name'])) { $fields[] = 'name = ?'; $params[] = sanitizeString($body['name'], 100); }
        if (isset($body['is_public'])) { $fields[] = 'is_public = ?'; $params[] = !empty($body['is_public']) ? 1 : 0; }
        if (isset($body['num_decks'])) { $fields[] = 'num_decks = ?'; $params[] = max(1, min(8, (int)$body['num_decks'])); }
        if (isset($body['include_jokers'])) { $fields[] = 'include_jokers = ?'; $params[] = !empty($body['include_jokers']) ? 1 : 0; }

        if (!empty($fields)) {
            $params[] = $id;
            $db->prepare('UPDATE templates SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }

        return ['message' => 'Updated'];
    }

    public static function delete(int $id): array {
        $user = requireAuth();
        $db = DB::get();
        $db->prepare("DELETE FROM templates WHERE id = ? AND creator_id = ?")->execute([$id, $user['id']]);
        return ['message' => 'Deleted'];
    }

    public static function applyToTable(string $tableId): array {
        $user = requireAuth();
        requireTableCreator($tableId, $user['id']);
        requireTableStatus($tableId, ['lobby']);

        $body = jsonBody();
        $templateId = (int)requireParam($body, 'template_id');

        $db = DB::get();
        $stmt = $db->prepare("SELECT * FROM templates WHERE id = ? AND (is_public = 1 OR creator_id = ?)");
        $stmt->execute([$templateId, $user['id']]);
        $template = $stmt->fetch();
        if (!$template) errorResponse('Template not found', 'NOT_FOUND', 404);

        // Clear existing zones
        $db->prepare("DELETE FROM zones WHERE table_id = ?")->execute([$tableId]);

        // Copy template zones
        $stmt = $db->prepare("SELECT * FROM template_zones WHERE template_id = ?");
        $stmt->execute([$templateId]);
        $zones = $stmt->fetchAll();

        $ins = $db->prepare("
            INSERT INTO zones (table_id, label, zone_type, layout_mode, flip_visibility, pos_x, pos_y, width, height, color, z_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($zones as $z) {
            $ins->execute([$tableId, $z['label'], $z['zone_type'], $z['layout_mode'], $z['flip_visibility'],
                $z['pos_x'], $z['pos_y'], $z['width'], $z['height'], $z['color'], $z['z_order']]);
        }

        // Update table settings from template
        $db->prepare("UPDATE `tables` SET num_decks = ?, include_jokers = ?, deck_backs = ?, chip_initial = ? WHERE id = ?")
            ->execute([$template['num_decks'], $template['include_jokers'], $template['deck_backs'], $template['chip_initial'], $tableId]);

        return ['message' => 'Template applied'];
    }
}
