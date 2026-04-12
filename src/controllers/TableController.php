<?php

class TableController {
    public static function create(): array {
        $user = requireAuth();
        $body = jsonBody();
        $db = DB::get();

        $id = uuid();
        $name = sanitizeString(requireParam($body, 'name'), 100);
        $numDecks = max(1, min(8, (int)($body['num_decks'] ?? 1)));
        $includeJokers = !empty($body['include_jokers']) ? 1 : 0;
        $deckBacks = in_array($body['deck_backs'] ?? '', ['uniform', 'random_per_deck']) ? $body['deck_backs'] : 'uniform';
        $deckSkinId = !empty($body['deck_skin_id']) ? (int)$body['deck_skin_id'] : null;
        $tableLogoId = !empty($body['table_logo_id']) ? (int)$body['table_logo_id'] : null;
        $chipInitial = max(0, (int)($body['chip_initial'] ?? 0));

        $stmt = $db->prepare("
            INSERT INTO `tables` (id, name, creator_id, status, num_decks, include_jokers, deck_backs, deck_skin_id, table_logo_id, chip_initial)
            VALUES (?, ?, ?, 'lobby', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id, $name, $user['id'], $numDecks, $includeJokers, $deckBacks, $deckSkinId, $tableLogoId, $chipInitial]);

        // Creator auto-joins seat 0
        $stmt = $db->prepare("INSERT INTO table_players (table_id, user_id, seat_index, chips) VALUES (?, ?, 0, ?)");
        $stmt->execute([$id, $user['id'], $chipInitial]);

        triggerLobbyEvent('lobby-updates', ['type' => 'table.created', 'table_id' => $id, 'name' => $name]);

        return ['table_id' => $id];
    }

    public static function join(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();
        $table = requireTableStatus($tableId, ['lobby']);

        // Check not already joined
        $stmt = $db->prepare('SELECT id FROM table_players WHERE table_id = ? AND user_id = ?');
        $stmt->execute([$tableId, $user['id']]);
        if ($stmt->fetch()) {
            errorResponse('Already at this table', 'ALREADY_JOINED');
        }

        // Find next seat
        $stmt = $db->prepare('SELECT seat_index FROM table_players WHERE table_id = ? ORDER BY seat_index');
        $stmt->execute([$tableId]);
        $taken = array_column($stmt->fetchAll(), 'seat_index');
        $seat = 0;
        while (in_array($seat, $taken)) $seat++;

        $stmt = $db->prepare("INSERT INTO table_players (table_id, user_id, seat_index, chips) VALUES (?, ?, ?, ?)");
        $stmt->execute([$tableId, $user['id'], $seat, $table['chip_initial']]);

        $playerData = ['user_id' => $user['id'], 'display_name' => $_SESSION['display_name'], 'seat_index' => $seat];
        triggerTableEvent($tableId, 'player.joined', $playerData, $user['id'], $_SESSION['display_name']);
        triggerLobbyEvent('lobby-updates', ['type' => 'table.updated', 'table_id' => $tableId]);

        return ['seat_index' => $seat];
    }

    public static function leave(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();

        $stmt = $db->prepare('DELETE FROM table_players WHERE table_id = ? AND user_id = ?');
        $stmt->execute([$tableId, $user['id']]);

        if ($stmt->rowCount() === 0) {
            errorResponse('Not at this table', 'NOT_AT_TABLE');
        }

        triggerTableEvent($tableId, 'player.left', ['user_id' => $user['id']], $user['id'], $_SESSION['display_name']);
        triggerLobbyEvent('lobby-updates', ['type' => 'table.updated', 'table_id' => $tableId]);

        return ['message' => 'Left table'];
    }

    public static function kick(string $tableId): array {
        $user = requireAuth();
        $body = jsonBody();
        $db = DB::get();

        requireTableCreator($tableId, $user['id']);
        $targetUserId = requireParam($body, 'user_id');

        $stmt = $db->prepare('DELETE FROM table_players WHERE table_id = ? AND user_id = ?');
        $stmt->execute([$tableId, $targetUserId]);

        triggerTableEvent($tableId, 'player.kicked', ['user_id' => $targetUserId, 'by' => $user['id']], $user['id'], $_SESSION['display_name']);
        triggerUserEvent($targetUserId, 'kicked', ['table_id' => $tableId]);

        return ['message' => 'Player kicked'];
    }

    public static function start(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();
        $table = requireTableCreator($tableId, $user['id']);

        if ($table['status'] !== 'lobby') {
            errorResponse('Table must be in lobby status to start', 'INVALID_STATUS');
        }

        // Get players
        $stmt = $db->prepare('SELECT * FROM table_players WHERE table_id = ? ORDER BY seat_index');
        $stmt->execute([$tableId]);
        $players = $stmt->fetchAll();

        if (count($players) < 1) {
            errorResponse('Need at least 1 player to start', 'NOT_ENOUGH_PLAYERS');
        }

        // Clone per_player zones. Each prototype is drawn on the zone-builder
        // canvas at whole-table scale and represents a template for a single
        // player's layout. We fit the bounding box of all prototypes into a
        // per-seat box, scaling uniformly (preserving aspect ratio), then
        // translate each cloned group so its centroid sits on the seat.
        $stmt = $db->prepare("SELECT * FROM zones WHERE table_id = ? AND zone_type = 'per_player' AND owner_player_id IS NULL");
        $stmt->execute([$tableId]);
        $protoZones = $stmt->fetchAll();

        $seatPositions = self::getSeatPositions(count($players));

        if (!empty($protoZones)) {
            $minX = PHP_FLOAT_MAX; $minY = PHP_FLOAT_MAX;
            $maxX = -PHP_FLOAT_MAX; $maxY = -PHP_FLOAT_MAX;
            foreach ($protoZones as $p) {
                $minX = min($minX, (float) $p['pos_x']);
                $minY = min($minY, (float) $p['pos_y']);
                $maxX = max($maxX, (float) $p['pos_x'] + (float) $p['width']);
                $maxY = max($maxY, (float) $p['pos_y'] + (float) $p['height']);
            }
            $bboxW = max(1.0, $maxX - $minX);
            $bboxH = max(1.0, $maxY - $minY);

            // Per-seat target box. Uniform scale so the bbox fits inside it
            // without distortion; never upscale beyond 1.0.
            $areaW = 38.0;
            $areaH = 24.0;
            $scale = min($areaW / $bboxW, $areaH / $bboxH, 1.0);
            $scaledW = $bboxW * $scale;
            $scaledH = $bboxH * $scale;

            foreach ($protoZones as $proto) {
                $clonedW = (float) $proto['width'] * $scale;
                $clonedH = (float) $proto['height'] * $scale;
                // Offset within the scaled bbox, then center the bbox on the seat.
                $localX = ((float) $proto['pos_x'] - $minX) * $scale;
                $localY = ((float) $proto['pos_y'] - $minY) * $scale;

                foreach ($players as $i => $player) {
                    $pos = $seatPositions[$i];
                    $clonedX = $pos['x'] - $scaledW / 2 + $localX;
                    $clonedY = $pos['y'] - $scaledH / 2 + $localY;
                    // Clamp to table bounds.
                    $clonedX = max(0.0, min(100.0 - $clonedW, $clonedX));
                    $clonedY = max(0.0, min(100.0 - $clonedH, $clonedY));

                    $db->prepare("
                        INSERT INTO zones (table_id, label, zone_type, owner_player_id, layout_mode, flip_visibility, pos_x, pos_y, width, height, color, z_order)
                        VALUES (?, ?, 'per_player', ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $tableId,
                        $proto['label'],
                        $player['id'],
                        $proto['layout_mode'],
                        $proto['flip_visibility'],
                        $clonedX,
                        $clonedY,
                        $clonedW,
                        $clonedH,
                        $proto['color'],
                        $proto['z_order'],
                    ]);
                }
                // Remove prototype
                $db->prepare('DELETE FROM zones WHERE id = ?')->execute([$proto['id']]);
            }
        }

        // Generate cards
        self::generateCards($db, $tableId, $table['num_decks'], $table['include_jokers'], $table['deck_backs']);

        // Update status
        $db->prepare("UPDATE `tables` SET status = 'active' WHERE id = ?")->execute([$tableId]);

        triggerTableEvent($tableId, 'game.started', [], $user['id'], $_SESSION['display_name']);
        triggerLobbyEvent('lobby-updates', ['type' => 'table.started', 'table_id' => $tableId]);

        return ['message' => 'Game started'];
    }

    private static function generateCards(PDO $db, string $tableId, int $numDecks, bool $includeJokers, string $deckBacks): void {
        $suits = ['hearts', 'diamonds', 'clubs', 'spades'];
        $ranks = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];

        // Find the first shared zone to place cards (or null)
        $stmt = $db->prepare("SELECT id FROM zones WHERE table_id = ? AND zone_type = 'shared' ORDER BY z_order LIMIT 1");
        $stmt->execute([$tableId]);
        $zone = $stmt->fetch();
        $zoneId = $zone ? $zone['id'] : null;

        $position = 0;
        $insertStmt = $db->prepare("
            INSERT INTO cards (table_id, deck_index, suit, `rank`, zone_id, position_in_zone, back_skin_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        for ($deck = 0; $deck < $numDecks; $deck++) {
            $backSkin = ($deckBacks === 'random_per_deck') ? $deck : null;
            foreach ($suits as $suit) {
                foreach ($ranks as $rank) {
                    $insertStmt->execute([$tableId, $deck, $suit, $rank, $zoneId, $position++, $backSkin]);
                }
            }
            if ($includeJokers) {
                $insertStmt->execute([$tableId, $deck, 'joker', 'joker', $zoneId, $position++, $backSkin]);
                $insertStmt->execute([$tableId, $deck, 'joker', 'joker', $zoneId, $position++, $backSkin]);
            }
        }
    }

    private static function getSeatPositions(int $count): array {
        $positions = [];
        for ($i = 0; $i < $count; $i++) {
            $angle = (2 * M_PI * $i / $count) - (M_PI / 2);
            $positions[] = [
                'x' => 50 + 35 * cos($angle),
                'y' => 50 + 35 * sin($angle),
            ];
        }
        return $positions;
    }

    public static function pause(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();
        requireTableCreator($tableId, $user['id']);
        requireTableStatus($tableId, ['active']);

        $db->prepare("UPDATE `tables` SET status = 'paused', paused_at = NOW() WHERE id = ?")->execute([$tableId]);
        triggerTableEvent($tableId, 'game.paused', [], $user['id'], $_SESSION['display_name']);

        return ['message' => 'Game paused'];
    }

    public static function resume(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();
        requireTableCreator($tableId, $user['id']);
        requireTableStatus($tableId, ['paused']);

        $db->prepare("UPDATE `tables` SET status = 'active', paused_at = NULL WHERE id = ?")->execute([$tableId]);
        triggerTableEvent($tableId, 'game.resumed', [], $user['id'], $_SESSION['display_name']);

        return ['message' => 'Game resumed'];
    }

    public static function close(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();
        requireTableCreator($tableId, $user['id']);
        requireTableStatus($tableId, ['active', 'paused']);

        $db->prepare("UPDATE `tables` SET status = 'closed' WHERE id = ?")->execute([$tableId]);
        triggerTableEvent($tableId, 'game.closed', [], $user['id'], $_SESSION['display_name']);
        triggerLobbyEvent('lobby-updates', ['type' => 'table.closed', 'table_id' => $tableId]);

        return ['message' => 'Game closed'];
    }

    public static function getState(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();

        $stmt = $db->prepare('SELECT * FROM `tables` WHERE id = ?');
        $stmt->execute([$tableId]);
        $table = $stmt->fetch();
        if (!$table) errorResponse('Table not found', 'TABLE_NOT_FOUND', 404);

        // Players
        $stmt = $db->prepare("
            SELECT tp.*, u.display_name, u.avatar_color
            FROM table_players tp JOIN users u ON u.id = tp.user_id
            WHERE tp.table_id = ? ORDER BY tp.seat_index
        ");
        $stmt->execute([$tableId]);
        $players = $stmt->fetchAll();

        // Current player
        $currentPlayer = null;
        foreach ($players as $p) {
            if ($p['user_id'] === $user['id']) { $currentPlayer = $p; break; }
        }

        // Zones
        $stmt = $db->prepare('SELECT * FROM zones WHERE table_id = ? ORDER BY z_order');
        $stmt->execute([$tableId]);
        $zones = $stmt->fetchAll();

        // Cards (filtered by visibility)
        $stmt = $db->prepare('SELECT * FROM cards WHERE table_id = ? ORDER BY position_in_zone');
        $stmt->execute([$tableId]);
        $rawCards = $stmt->fetchAll();

        $cards = array_map(function($card) use ($user, $currentPlayer) {
            return self::filterCardForPlayer($card, $user['id'], $currentPlayer);
        }, $rawCards);

        // Chip pots
        $stmt = $db->prepare('SELECT * FROM chip_pots WHERE table_id = ?');
        $stmt->execute([$tableId]);
        $pots = $stmt->fetchAll();

        // Recent actions
        $stmt = $db->prepare("
            SELECT al.*, u.display_name as actor_name
            FROM action_log al JOIN users u ON u.id = al.user_id
            WHERE al.table_id = ? ORDER BY al.created_at DESC LIMIT 50
        ");
        $stmt->execute([$tableId]);
        $actions = array_reverse($stmt->fetchAll());

        return [
            'table'   => $table,
            'players' => $players,
            'zones'   => $zones,
            'cards'   => $cards,
            'pots'    => $pots,
            'actions' => $actions,
        ];
    }

    public static function filterCardForPlayer(array $card, string $userId, ?array $currentPlayer): array {
        $canSee = false;

        // Face up cards are visible
        if ($card['face_up']) {
            $canSee = true;
        }

        // Cards in own hand are visible
        if ($currentPlayer && $card['holder_player_id'] == $currentPlayer['id']) {
            $canSee = true;
        }

        // Peeked cards visible to peekers
        if ($card['peeked_by']) {
            $peekers = json_decode($card['peeked_by'], true) ?? [];
            if (in_array($userId, $peekers)) {
                $canSee = true;
            }
        }

        // Check zone visibility
        if ($card['zone_id'] && $card['face_up']) {
            $canSee = true;
        }

        $result = [
            'id'               => $card['id'],
            'deck_index'       => $card['deck_index'],
            'face_up'          => (bool)$card['face_up'],
            'zone_id'          => $card['zone_id'],
            'holder_player_id' => $card['holder_player_id'],
            'position_in_zone' => $card['position_in_zone'],
            'in_play'          => (bool)$card['in_play'],
            'back_skin_id'     => $card['back_skin_id'],
        ];

        if ($canSee) {
            $result['suit'] = $card['suit'];
            $result['rank'] = $card['rank'];
        } else {
            $result['suit'] = null;
            $result['rank'] = null;
        }

        // Peeked_by info (for showing "someone is peeking" indicator)
        $peekers = json_decode($card['peeked_by'] ?? '[]', true) ?? [];
        $result['is_peeked'] = !empty($peekers);
        $result['peeked_by_you'] = in_array($userId, $peekers);

        // Marked info (only visible to marker)
        $markers = json_decode($card['marked_by'] ?? '[]', true) ?? [];
        $result['marked_by_you'] = in_array($userId, $markers);

        return $result;
    }

    public static function getActions(string $tableId): array {
        requireAuth();
        $db = DB::get();

        $before = isset($_GET['before']) ? (int)$_GET['before'] : PHP_INT_MAX;
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

        $stmt = $db->prepare("
            SELECT al.*, u.display_name as actor_name
            FROM action_log al JOIN users u ON u.id = al.user_id
            WHERE al.table_id = ? AND al.id < ?
            ORDER BY al.id DESC LIMIT ?
        ");
        $stmt->execute([$tableId, $before, $limit]);
        return ['actions' => array_reverse($stmt->fetchAll())];
    }
}
