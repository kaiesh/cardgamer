<?php

class ZoneController {
    public static function list(string $tableId): array {
        requireAuth();
        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM zones WHERE table_id = ? ORDER BY z_order');
        $stmt->execute([$tableId]);
        return ['zones' => $stmt->fetchAll()];
    }

    public static function create(string $tableId): array {
        $user = requireAuth();
        requireTableCreator($tableId, $user['id']);
        requireTableStatus($tableId, ['lobby']);

        $body = jsonBody();
        $db = DB::get();

        $label = sanitizeString(requireParam($body, 'label'), 50);
        $zoneType = in_array($body['zone_type'] ?? '', ['shared', 'per_player']) ? $body['zone_type'] : 'shared';
        $layoutMode = in_array($body['layout_mode'] ?? '', ['stacked', 'spread']) ? $body['layout_mode'] : 'stacked';
        $flipVis = in_array($body['flip_visibility'] ?? '', ['private', 'public']) ? $body['flip_visibility'] : 'private';
        $posX = max(0, min(100, (float)($body['pos_x'] ?? 10)));
        $posY = max(0, min(100, (float)($body['pos_y'] ?? 10)));
        $width = max(5, min(100, (float)($body['width'] ?? 15)));
        $height = max(5, min(100, (float)($body['height'] ?? 10)));
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', $body['color'] ?? '') ? $body['color'] : '#1E3A5F';
        $zOrder = max(0, (int)($body['z_order'] ?? 0));

        $stmt = $db->prepare("
            INSERT INTO zones (table_id, label, zone_type, layout_mode, flip_visibility, pos_x, pos_y, width, height, color, z_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tableId, $label, $zoneType, $layoutMode, $flipVis, $posX, $posY, $width, $height, $color, $zOrder]);
        $zoneId = (int)$db->lastInsertId();

        $zone = ['id' => $zoneId, 'table_id' => $tableId, 'label' => $label, 'zone_type' => $zoneType,
            'layout_mode' => $layoutMode, 'flip_visibility' => $flipVis,
            'pos_x' => $posX, 'pos_y' => $posY, 'width' => $width, 'height' => $height,
            'color' => $color, 'z_order' => $zOrder];

        triggerTableEvent($tableId, 'zone.created', ['zone' => $zone], $user['id'], $_SESSION['display_name']);

        return ['zone' => $zone];
    }

    public static function update(string $tableId, int $zoneId): array {
        $user = requireAuth();
        requireTableCreator($tableId, $user['id']);
        requireTableStatus($tableId, ['lobby']);

        $body = jsonBody();
        $db = DB::get();

        $fields = [];
        $params = [];

        foreach (['label' => 50] as $f => $max) {
            if (isset($body[$f])) { $fields[] = "{$f} = ?"; $params[] = sanitizeString($body[$f], $max); }
        }
        foreach (['zone_type' => ['shared','per_player'], 'layout_mode' => ['stacked','spread'], 'flip_visibility' => ['private','public']] as $f => $valid) {
            if (isset($body[$f]) && in_array($body[$f], $valid)) { $fields[] = "{$f} = ?"; $params[] = $body[$f]; }
        }
        foreach (['pos_x','pos_y','width','height'] as $f) {
            if (isset($body[$f])) { $fields[] = "{$f} = ?"; $params[] = (float)$body[$f]; }
        }
        if (isset($body['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $body['color'])) {
            $fields[] = 'color = ?'; $params[] = $body['color'];
        }
        if (isset($body['z_order'])) { $fields[] = 'z_order = ?'; $params[] = (int)$body['z_order']; }

        if (empty($fields)) errorResponse('No fields to update', 'NO_UPDATES');

        $params[] = $zoneId;
        $params[] = $tableId;
        $db->prepare('UPDATE zones SET ' . implode(', ', $fields) . ' WHERE id = ? AND table_id = ?')->execute($params);

        $stmt = $db->prepare('SELECT * FROM zones WHERE id = ?');
        $stmt->execute([$zoneId]);
        $zone = $stmt->fetch();

        triggerTableEvent($tableId, 'zone.updated', ['zone' => $zone], $user['id'], $_SESSION['display_name']);

        return ['zone' => $zone];
    }

    public static function delete(string $tableId, int $zoneId): array {
        $user = requireAuth();
        requireTableCreator($tableId, $user['id']);
        requireTableStatus($tableId, ['lobby']);

        $db = DB::get();
        $db->prepare('DELETE FROM zones WHERE id = ? AND table_id = ?')->execute([$zoneId, $tableId]);

        triggerTableEvent($tableId, 'zone.deleted', ['zone_id' => $zoneId], $user['id'], $_SESSION['display_name']);

        return ['message' => 'Zone deleted'];
    }
}
