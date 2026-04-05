<?php

class ChipController {
    public static function transfer(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();
        $player = requireTableMember($tableId, $user['id']);
        requireTableStatus($tableId, ['active']);

        $body = jsonBody();
        $fromType = requireParam($body, 'from_type');
        $fromId = (int)requireParam($body, 'from_id');
        $toType = requireParam($body, 'to_type');
        $toId = (int)requireParam($body, 'to_id');
        $amount = (int)requireParam($body, 'amount');

        if ($amount <= 0) errorResponse('Amount must be positive', 'INVALID_AMOUNT');

        // Validate from
        if ($fromType === 'player') {
            $stmt = $db->prepare('SELECT * FROM table_players WHERE id = ? AND table_id = ?');
            $stmt->execute([$fromId, $tableId]);
            $fromPlayer = $stmt->fetch();
            if (!$fromPlayer) errorResponse('Source player not found', 'NOT_FOUND', 404);

            // Only own chips or creator can move anyone's
            $table = $db->prepare('SELECT creator_id FROM `tables` WHERE id = ?');
            $table->execute([$tableId]);
            $tableData = $table->fetch();
            if ($fromPlayer['user_id'] !== $user['id'] && $tableData['creator_id'] !== $user['id']) {
                errorResponse('Cannot transfer other player chips', 'FORBIDDEN', 403);
            }
            if ($fromPlayer['chips'] < $amount) errorResponse('Insufficient chips', 'INSUFFICIENT_CHIPS');

            $db->prepare('UPDATE table_players SET chips = chips - ? WHERE id = ?')->execute([$amount, $fromId]);
        } else {
            $stmt = $db->prepare('SELECT * FROM chip_pots WHERE id = ? AND table_id = ?');
            $stmt->execute([$fromId, $tableId]);
            $fromPot = $stmt->fetch();
            if (!$fromPot) errorResponse('Source pot not found', 'NOT_FOUND', 404);

            // Only creator can take from pots
            $table = $db->prepare('SELECT creator_id FROM `tables` WHERE id = ?');
            $table->execute([$tableId]);
            if ($table->fetch()['creator_id'] !== $user['id']) {
                errorResponse('Only the table creator can transfer from pots', 'FORBIDDEN', 403);
            }
            if ($fromPot['amount'] < $amount) errorResponse('Insufficient chips in pot', 'INSUFFICIENT_CHIPS');

            $db->prepare('UPDATE chip_pots SET amount = amount - ? WHERE id = ?')->execute([$amount, $fromId]);
        }

        // Apply to
        if ($toType === 'player') {
            $db->prepare('UPDATE table_players SET chips = chips + ? WHERE id = ? AND table_id = ?')->execute([$amount, $toId, $tableId]);
        } else {
            $db->prepare('UPDATE chip_pots SET amount = amount + ? WHERE id = ? AND table_id = ?')->execute([$amount, $toId, $tableId]);
        }

        // Log transaction
        $db->prepare("INSERT INTO chip_transactions (table_id, from_type, from_id, to_type, to_id, amount) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$tableId, $fromType, $fromId, $toType, $toId, $amount]);

        // Action log
        $db->prepare("INSERT INTO action_log (table_id, user_id, action_type, payload) VALUES (?, ?, 'chips.transferred', ?)")
            ->execute([$tableId, $user['id'], json_encode(['from_type' => $fromType, 'from_id' => $fromId, 'to_type' => $toType, 'to_id' => $toId, 'amount' => $amount])]);

        triggerTableEvent($tableId, 'chips.transferred', [
            'from_type' => $fromType, 'from_id' => $fromId,
            'to_type' => $toType, 'to_id' => $toId, 'amount' => $amount,
        ], $user['id'], $_SESSION['display_name']);

        return ['message' => 'Transfer complete'];
    }

    public static function createPot(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();
        requireTableMember($tableId, $user['id']);
        requireTableStatus($tableId, ['active']);

        $body = jsonBody();
        $label = sanitizeString(requireParam($body, 'label'), 50);
        $zoneId = !empty($body['zone_id']) ? (int)$body['zone_id'] : null;

        $stmt = $db->prepare("INSERT INTO chip_pots (table_id, label, zone_id) VALUES (?, ?, ?)");
        $stmt->execute([$tableId, $label, $zoneId]);
        $potId = (int)$db->lastInsertId();

        $pot = ['id' => $potId, 'table_id' => $tableId, 'label' => $label, 'zone_id' => $zoneId, 'amount' => 0];

        triggerTableEvent($tableId, 'chips.pot_created', ['pot' => $pot], $user['id'], $_SESSION['display_name']);

        return ['pot' => $pot];
    }

    public static function getChips(string $tableId): array {
        requireAuth();
        $db = DB::get();

        $stmt = $db->prepare("SELECT tp.id, tp.user_id, u.display_name, tp.chips FROM table_players tp JOIN users u ON u.id = tp.user_id WHERE tp.table_id = ?");
        $stmt->execute([$tableId]);
        $players = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT * FROM chip_pots WHERE table_id = ?");
        $stmt->execute([$tableId]);
        $pots = $stmt->fetchAll();

        return ['players' => $players, 'pots' => $pots];
    }
}
