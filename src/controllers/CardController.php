<?php

class CardController {
    public static function handleAction(string $tableId): array {
        $user = requireAuth();
        $db = DB::get();
        $player = requireTableMember($tableId, $user['id']);
        requireTableStatus($tableId, ['active']);

        $body = jsonBody();
        $action = requireParam($body, 'action');

        $result = match ($action) {
            'shuffle'          => self::shuffle($db, $tableId, $body, $player),
            'cut'              => self::cut($db, $tableId, $body, $player),
            'count'            => self::count($db, $tableId, $body),
            'deal'             => self::deal($db, $tableId, $body, $player),
            'deal_to_zone'     => self::dealToZone($db, $tableId, $body, $player),
            'deal_to_self'     => self::dealToSelf($db, $tableId, $body, $player),
            'take_from_zone'   => self::takeFromZone($db, $tableId, $body, $player),
            'place_in_zone'    => self::placeInZone($db, $tableId, $body, $player),
            'give_to_player'   => self::giveToPlayer($db, $tableId, $body, $player),
            'return_to_zone'   => self::returnToZone($db, $tableId, $body, $player),
            'swap_with_player' => self::swapWithPlayer($db, $tableId, $body, $player),
            'force_give'       => self::forceGive($db, $tableId, $body, $player),
            'force_take'       => self::forceTake($db, $tableId, $body, $player),
            'offer'            => self::offer($db, $tableId, $body, $player),
            'accept_offer'     => self::acceptOffer($db, $tableId, $body, $player),
            'decline_offer'    => self::declineOffer($db, $tableId, $body, $player),
            'discard'          => self::discard($db, $tableId, $body, $player),
            'flip'             => self::flip($db, $tableId, $body, $player),
            'peek'             => self::peek($db, $tableId, $body, $player, $user['id']),
            'unpeek'           => self::unpeek($db, $tableId, $body, $player, $user['id']),
            'reveal'           => self::reveal($db, $tableId, $body, $player),
            'mark'             => self::mark($db, $tableId, $body, $player, $user['id']),
            'unmark'           => self::unmark($db, $tableId, $body, $player, $user['id']),
            'remove_from_play' => self::removeFromPlay($db, $tableId, $body, $player),
            'return_to_play'   => self::returnToPlay($db, $tableId, $body, $player),
            'reorder_hand'     => self::reorderHand($db, $tableId, $body, $player),
            default            => errorResponse("Unknown action: {$action}", 'UNKNOWN_ACTION'),
        };

        return $result;
    }

    private static function logAction(PDO $db, string $tableId, string $userId, string $actionType, array $payload): int {
        $stmt = $db->prepare("INSERT INTO action_log (table_id, user_id, action_type, payload) VALUES (?, ?, ?, ?)");
        $stmt->execute([$tableId, $userId, $actionType, json_encode($payload)]);
        return (int)$db->lastInsertId();
    }

    private static function getCards(PDO $db, array $cardIds, string $tableId): array {
        if (empty($cardIds)) return [];
        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $stmt = $db->prepare("SELECT * FROM cards WHERE id IN ({$placeholders}) AND table_id = ?");
        $stmt->execute([...$cardIds, $tableId]);
        return $stmt->fetchAll();
    }

    private static function shuffle(PDO $db, string $tableId, array $body, array $player): array {
        $zoneId = requireParam($body, 'zone_id');
        $stmt = $db->prepare("SELECT id FROM cards WHERE table_id = ? AND zone_id = ? AND in_play = 1");
        $stmt->execute([$tableId, $zoneId]);
        $cardIds = array_column($stmt->fetchAll(), 'id');
        shuffle($cardIds);

        $updateStmt = $db->prepare("UPDATE cards SET position_in_zone = ? WHERE id = ?");
        foreach ($cardIds as $pos => $id) {
            $updateStmt->execute([$pos, $id]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.shuffled', ['zone_id' => $zoneId]);
        triggerTableEvent($tableId, 'card.shuffled', ['zone_id' => $zoneId], $userId, $_SESSION['display_name']);

        return ['message' => 'Shuffled'];
    }

    private static function cut(PDO $db, string $tableId, array $body, array $player): array {
        $zoneId = requireParam($body, 'zone_id');
        $position = (int)requireParam($body, 'position');

        $stmt = $db->prepare("SELECT id FROM cards WHERE table_id = ? AND zone_id = ? AND in_play = 1 ORDER BY position_in_zone");
        $stmt->execute([$tableId, $zoneId]);
        $cardIds = array_column($stmt->fetchAll(), 'id');

        $bottom = array_slice($cardIds, 0, $position);
        $top = array_slice($cardIds, $position);
        $reordered = array_merge($top, $bottom);

        $updateStmt = $db->prepare("UPDATE cards SET position_in_zone = ? WHERE id = ?");
        foreach ($reordered as $pos => $id) {
            $updateStmt->execute([$pos, $id]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.cut', ['zone_id' => $zoneId, 'position' => $position]);
        triggerTableEvent($tableId, 'card.cut', ['zone_id' => $zoneId, 'position' => $position], $userId, $_SESSION['display_name']);

        return ['message' => 'Cut'];
    }

    private static function count(PDO $db, string $tableId, array $body): array {
        $zoneId = requireParam($body, 'zone_id');
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM cards WHERE table_id = ? AND zone_id = ? AND in_play = 1");
        $stmt->execute([$tableId, $zoneId]);
        return ['count' => (int)$stmt->fetch()['cnt']];
    }

    private static function deal(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');
        $targetPlayerId = requireParam($body, 'target_player_id');

        $stmt = $db->prepare("UPDATE cards SET zone_id = NULL, holder_player_id = ?, face_up = 0, position_in_zone = 0 WHERE id = ? AND table_id = ?");
        foreach ($cardIds as $cardId) {
            $stmt->execute([$targetPlayerId, $cardId, $tableId]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.dealt', ['card_ids' => $cardIds, 'target_player_id' => $targetPlayerId]);
        triggerTableEvent($tableId, 'card.dealt', ['card_ids' => $cardIds, 'target_player_id' => $targetPlayerId], $userId, $_SESSION['display_name']);

        return ['message' => 'Dealt'];
    }

    private static function dealToZone(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');
        $targetZoneId = requireParam($body, 'target_zone_id');

        $stmt = $db->prepare("SELECT COALESCE(MAX(position_in_zone), -1) + 1 as next_pos FROM cards WHERE zone_id = ?");
        $stmt->execute([$targetZoneId]);
        $nextPos = (int)$stmt->fetch()['next_pos'];

        $updateStmt = $db->prepare("UPDATE cards SET zone_id = ?, holder_player_id = NULL, position_in_zone = ? WHERE id = ? AND table_id = ?");
        foreach ($cardIds as $i => $cardId) {
            $updateStmt->execute([$targetZoneId, $nextPos + $i, $cardId, $tableId]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.dealt_to_zone', ['card_ids' => $cardIds, 'target_zone_id' => $targetZoneId]);
        triggerTableEvent($tableId, 'card.dealt_to_zone', ['card_ids' => $cardIds, 'target_zone_id' => $targetZoneId], $userId, $_SESSION['display_name']);

        return ['message' => 'Dealt to zone'];
    }

    private static function dealToSelf(PDO $db, string $tableId, array $body, array $player): array {
        $count = (int)requireParam($body, 'count');
        $sourceZoneId = requireParam($body, 'source_zone_id');

        $stmt = $db->prepare("SELECT id FROM cards WHERE table_id = ? AND zone_id = ? AND in_play = 1 ORDER BY position_in_zone LIMIT ?");
        $stmt->execute([$tableId, $sourceZoneId, $count]);
        $cardIds = array_column($stmt->fetchAll(), 'id');

        $updateStmt = $db->prepare("UPDATE cards SET zone_id = NULL, holder_player_id = ?, face_up = 0 WHERE id = ?");
        foreach ($cardIds as $cardId) {
            $updateStmt->execute([$player['id'], $cardId]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.taken', ['card_ids' => $cardIds, 'source_zone_id' => $sourceZoneId]);
        triggerTableEvent($tableId, 'card.taken', ['card_ids' => $cardIds, 'source_zone_id' => $sourceZoneId, 'player_id' => $player['id']], $userId, $_SESSION['display_name']);

        return ['card_ids' => $cardIds];
    }

    private static function takeFromZone(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');

        $updateStmt = $db->prepare("UPDATE cards SET zone_id = NULL, holder_player_id = ?, face_up = 0 WHERE id = ? AND table_id = ?");
        foreach ($cardIds as $cardId) {
            $updateStmt->execute([$player['id'], $cardId, $tableId]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.taken', ['card_ids' => $cardIds, 'player_id' => $player['id']]);
        triggerTableEvent($tableId, 'card.taken', ['card_ids' => $cardIds, 'player_id' => $player['id']], $userId, $_SESSION['display_name']);

        return ['message' => 'Taken'];
    }

    private static function placeInZone(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');
        $targetZoneId = requireParam($body, 'target_zone_id');
        $position = $body['position'] ?? 'top';

        if ($position === 'top') {
            $stmt = $db->prepare("SELECT COALESCE(MAX(position_in_zone), -1) + 1 as next_pos FROM cards WHERE zone_id = ?");
            $stmt->execute([$targetZoneId]);
            $startPos = (int)$stmt->fetch()['next_pos'];
        } elseif ($position === 'bottom') {
            // Shift existing cards up
            $db->prepare("UPDATE cards SET position_in_zone = position_in_zone + ? WHERE zone_id = ?")->execute([count($cardIds), $targetZoneId]);
            $startPos = 0;
        } else {
            $startPos = (int)$position;
        }

        $updateStmt = $db->prepare("UPDATE cards SET zone_id = ?, holder_player_id = NULL, position_in_zone = ? WHERE id = ? AND table_id = ?");
        foreach ($cardIds as $i => $cardId) {
            $updateStmt->execute([$targetZoneId, $startPos + $i, $cardId, $tableId]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.placed', ['card_ids' => $cardIds, 'target_zone_id' => $targetZoneId, 'position' => $position]);
        triggerTableEvent($tableId, 'card.placed', ['card_ids' => $cardIds, 'target_zone_id' => $targetZoneId, 'position' => $position], $userId, $_SESSION['display_name']);

        return ['message' => 'Placed'];
    }

    private static function giveToPlayer(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');
        $targetPlayerId = requireParam($body, 'target_player_id');

        $updateStmt = $db->prepare("UPDATE cards SET holder_player_id = ?, zone_id = NULL WHERE id = ? AND table_id = ? AND holder_player_id = ?");
        foreach ($cardIds as $cardId) {
            $updateStmt->execute([$targetPlayerId, $cardId, $tableId, $player['id']]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.given', ['card_ids' => $cardIds, 'from_player_id' => $player['id'], 'to_player_id' => $targetPlayerId]);
        triggerTableEvent($tableId, 'card.given', ['card_ids' => $cardIds, 'from_player_id' => $player['id'], 'to_player_id' => $targetPlayerId], $userId, $_SESSION['display_name']);

        return ['message' => 'Given'];
    }

    private static function returnToZone(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');
        $targetZoneId = requireParam($body, 'target_zone_id');

        $stmt = $db->prepare("SELECT COALESCE(MAX(position_in_zone), -1) + 1 as next_pos FROM cards WHERE zone_id = ?");
        $stmt->execute([$targetZoneId]);
        $nextPos = (int)$stmt->fetch()['next_pos'];

        $updateStmt = $db->prepare("UPDATE cards SET zone_id = ?, holder_player_id = NULL, position_in_zone = ?, face_up = 0 WHERE id = ? AND table_id = ?");
        foreach ($cardIds as $i => $cardId) {
            $updateStmt->execute([$targetZoneId, $nextPos + $i, $cardId, $tableId]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.returned', ['card_ids' => $cardIds, 'target_zone_id' => $targetZoneId]);
        triggerTableEvent($tableId, 'card.returned', ['card_ids' => $cardIds, 'target_zone_id' => $targetZoneId], $userId, $_SESSION['display_name']);

        return ['message' => 'Returned'];
    }

    private static function swapWithPlayer(PDO $db, string $tableId, array $body, array $player): array {
        $cardId = requireParam($body, 'card_id');
        $targetPlayerId = requireParam($body, 'target_player_id');
        $targetCardId = requireParam($body, 'target_card_id');

        $db->prepare("UPDATE cards SET holder_player_id = ? WHERE id = ? AND table_id = ?")->execute([$targetPlayerId, $cardId, $tableId]);
        $db->prepare("UPDATE cards SET holder_player_id = ? WHERE id = ? AND table_id = ?")->execute([$player['id'], $targetCardId, $tableId]);

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.swapped', ['card_id_a' => $cardId, 'card_id_b' => $targetCardId]);
        triggerTableEvent($tableId, 'card.swapped', ['card_id_a' => $cardId, 'card_id_b' => $targetCardId, 'player_a' => $player['id'], 'player_b' => $targetPlayerId], $userId, $_SESSION['display_name']);

        return ['message' => 'Swapped'];
    }

    private static function forceGive(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');
        $targetPlayerId = requireParam($body, 'target_player_id');

        $updateStmt = $db->prepare("UPDATE cards SET holder_player_id = ?, zone_id = NULL WHERE id = ? AND table_id = ?");
        foreach ($cardIds as $cardId) {
            $updateStmt->execute([$targetPlayerId, $cardId, $tableId]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.forced_give', ['card_ids' => $cardIds, 'from' => $player['id'], 'to' => $targetPlayerId]);
        triggerTableEvent($tableId, 'card.forced_give', ['card_ids' => $cardIds, 'from' => $player['id'], 'to' => $targetPlayerId], $userId, $_SESSION['display_name']);

        return ['message' => 'Force given'];
    }

    private static function forceTake(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');
        $targetPlayerId = requireParam($body, 'target_player_id');

        $updateStmt = $db->prepare("UPDATE cards SET holder_player_id = ? WHERE id = ? AND table_id = ? AND holder_player_id = ?");
        foreach ($cardIds as $cardId) {
            $updateStmt->execute([$player['id'], $cardId, $tableId, $targetPlayerId]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.forced_take', ['card_ids' => $cardIds, 'from' => $targetPlayerId, 'to' => $player['id']]);
        triggerTableEvent($tableId, 'card.forced_take', ['card_ids' => $cardIds, 'from' => $targetPlayerId, 'to' => $player['id']], $userId, $_SESSION['display_name']);

        return ['message' => 'Force taken'];
    }

    private static function offer(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');
        $targetPlayerId = requireParam($body, 'target_player_id');

        $stmt = $db->prepare("INSERT INTO offers (table_id, from_player_id, to_player_id, card_ids) VALUES (?, ?, ?, ?)");
        $stmt->execute([$tableId, $player['id'], $targetPlayerId, json_encode($cardIds)]);
        $offerId = (int)$db->lastInsertId();

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.offered', ['offer_id' => $offerId, 'card_ids' => $cardIds, 'to' => $targetPlayerId]);
        triggerTableEvent($tableId, 'card.offered', ['offer_id' => $offerId, 'card_ids' => $cardIds, 'from' => $player['id'], 'to' => $targetPlayerId], $userId, $_SESSION['display_name']);

        // Notify target
        $stmt = $db->prepare("SELECT user_id FROM table_players WHERE id = ?");
        $stmt->execute([$targetPlayerId]);
        $target = $stmt->fetch();
        if ($target) {
            triggerUserEvent($target['user_id'], 'card.offered', ['offer_id' => $offerId, 'table_id' => $tableId]);
        }

        return ['offer_id' => $offerId];
    }

    private static function acceptOffer(PDO $db, string $tableId, array $body, array $player): array {
        $offerId = requireParam($body, 'offer_id');

        $stmt = $db->prepare("SELECT * FROM offers WHERE id = ? AND table_id = ? AND to_player_id = ? AND status = 'pending'");
        $stmt->execute([$offerId, $tableId, $player['id']]);
        $offer = $stmt->fetch();
        if (!$offer) errorResponse('Offer not found or already resolved', 'OFFER_NOT_FOUND', 404);

        $cardIds = json_decode($offer['card_ids'], true);
        $updateStmt = $db->prepare("UPDATE cards SET holder_player_id = ? WHERE id = ? AND table_id = ?");
        foreach ($cardIds as $cardId) {
            $updateStmt->execute([$player['id'], $cardId, $tableId]);
        }

        $db->prepare("UPDATE offers SET status = 'accepted', resolved_at = NOW() WHERE id = ?")->execute([$offerId]);

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.offer_accepted', ['offer_id' => $offerId]);
        triggerTableEvent($tableId, 'card.offer_accepted', ['offer_id' => $offerId], $userId, $_SESSION['display_name']);

        return ['message' => 'Offer accepted'];
    }

    private static function declineOffer(PDO $db, string $tableId, array $body, array $player): array {
        $offerId = requireParam($body, 'offer_id');

        $stmt = $db->prepare("SELECT * FROM offers WHERE id = ? AND table_id = ? AND to_player_id = ? AND status = 'pending'");
        $stmt->execute([$offerId, $tableId, $player['id']]);
        if (!$stmt->fetch()) errorResponse('Offer not found', 'OFFER_NOT_FOUND', 404);

        $db->prepare("UPDATE offers SET status = 'declined', resolved_at = NOW() WHERE id = ?")->execute([$offerId]);

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.offer_declined', ['offer_id' => $offerId]);
        triggerTableEvent($tableId, 'card.offer_declined', ['offer_id' => $offerId], $userId, $_SESSION['display_name']);

        return ['message' => 'Offer declined'];
    }

    private static function discard(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');
        $targetZoneId = requireParam($body, 'target_zone_id');

        $stmt = $db->prepare("SELECT COALESCE(MAX(position_in_zone), -1) + 1 as next_pos FROM cards WHERE zone_id = ?");
        $stmt->execute([$targetZoneId]);
        $nextPos = (int)$stmt->fetch()['next_pos'];

        $updateStmt = $db->prepare("UPDATE cards SET zone_id = ?, holder_player_id = NULL, face_up = 1, position_in_zone = ? WHERE id = ? AND table_id = ?");
        foreach ($cardIds as $i => $cardId) {
            $updateStmt->execute([$targetZoneId, $nextPos + $i, $cardId, $tableId]);
        }

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.discarded', ['card_ids' => $cardIds, 'zone_id' => $targetZoneId]);
        triggerTableEvent($tableId, 'card.discarded', ['card_ids' => $cardIds, 'zone_id' => $targetZoneId], $userId, $_SESSION['display_name']);

        return ['message' => 'Discarded'];
    }

    private static function flip(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');

        $stmt = $db->prepare("UPDATE cards SET face_up = NOT face_up WHERE id = ? AND table_id = ?");
        foreach ($cardIds as $cardId) {
            $stmt->execute([$cardId, $tableId]);
        }

        // Get updated states
        $cards = self::getCards($db, $cardIds, $tableId);
        $faceUp = !empty($cards) && $cards[0]['face_up'];

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.flipped', ['card_ids' => $cardIds, 'face_up' => $faceUp]);
        triggerTableEvent($tableId, 'card.flipped', ['card_ids' => $cardIds, 'face_up' => $faceUp], $userId, $_SESSION['display_name']);

        return ['message' => 'Flipped'];
    }

    private static function peek(PDO $db, string $tableId, array $body, array $player, string $userId): array {
        $cardIds = requireParam($body, 'card_ids');

        foreach ($cardIds as $cardId) {
            $stmt = $db->prepare("SELECT peeked_by FROM cards WHERE id = ? AND table_id = ?");
            $stmt->execute([$cardId, $tableId]);
            $card = $stmt->fetch();
            $peekers = json_decode($card['peeked_by'] ?? '[]', true) ?? [];
            if (!in_array($userId, $peekers)) {
                $peekers[] = $userId;
            }
            $db->prepare("UPDATE cards SET peeked_by = ? WHERE id = ?")->execute([json_encode($peekers), $cardId]);
        }

        self::logAction($db, $tableId, $userId, 'card.peeked', ['card_ids' => $cardIds]);
        triggerTableEvent($tableId, 'card.peeked', ['card_ids' => $cardIds, 'player_id' => $player['id']], $userId, $_SESSION['display_name']);

        // Return card faces to the peeking player
        $cards = self::getCards($db, $cardIds, $tableId);
        $faces = array_map(fn($c) => ['id' => $c['id'], 'suit' => $c['suit'], 'rank' => $c['rank']], $cards);

        return ['cards' => $faces];
    }

    private static function unpeek(PDO $db, string $tableId, array $body, array $player, string $userId): array {
        $cardIds = requireParam($body, 'card_ids');

        foreach ($cardIds as $cardId) {
            $stmt = $db->prepare("SELECT peeked_by FROM cards WHERE id = ? AND table_id = ?");
            $stmt->execute([$cardId, $tableId]);
            $card = $stmt->fetch();
            $peekers = json_decode($card['peeked_by'] ?? '[]', true) ?? [];
            $peekers = array_values(array_filter($peekers, fn($p) => $p !== $userId));
            $db->prepare("UPDATE cards SET peeked_by = ? WHERE id = ?")->execute([json_encode($peekers), $cardId]);
        }

        self::logAction($db, $tableId, $userId, 'card.unpeeked', ['card_ids' => $cardIds]);
        triggerTableEvent($tableId, 'card.unpeeked', ['card_ids' => $cardIds, 'player_id' => $player['id']], $userId, $_SESSION['display_name']);

        return ['message' => 'Unpeeked'];
    }

    private static function reveal(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');

        $db->prepare("UPDATE cards SET face_up = 1 WHERE id IN (" . implode(',', array_fill(0, count($cardIds), '?')) . ") AND table_id = ?")
            ->execute([...$cardIds, $tableId]);

        $cards = self::getCards($db, $cardIds, $tableId);
        $revealed = array_map(fn($c) => ['id' => $c['id'], 'suit' => $c['suit'], 'rank' => $c['rank']], $cards);

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.revealed', ['card_ids' => $cardIds, 'cards' => $revealed]);
        triggerTableEvent($tableId, 'card.revealed', ['card_ids' => $cardIds, 'cards' => $revealed], $userId, $_SESSION['display_name']);

        return ['cards' => $revealed];
    }

    private static function mark(PDO $db, string $tableId, array $body, array $player, string $userId): array {
        $cardIds = requireParam($body, 'card_ids');

        foreach ($cardIds as $cardId) {
            $stmt = $db->prepare("SELECT marked_by FROM cards WHERE id = ? AND table_id = ?");
            $stmt->execute([$cardId, $tableId]);
            $card = $stmt->fetch();
            $markers = json_decode($card['marked_by'] ?? '[]', true) ?? [];
            if (!in_array($userId, $markers)) {
                $markers[] = $userId;
            }
            $db->prepare("UPDATE cards SET marked_by = ? WHERE id = ?")->execute([json_encode($markers), $cardId]);
        }

        self::logAction($db, $tableId, $userId, 'card.marked', ['card_ids' => $cardIds]);
        triggerTableEvent($tableId, 'card.marked', ['card_ids' => $cardIds], $userId, $_SESSION['display_name']);

        return ['message' => 'Marked'];
    }

    private static function unmark(PDO $db, string $tableId, array $body, array $player, string $userId): array {
        $cardIds = requireParam($body, 'card_ids');

        foreach ($cardIds as $cardId) {
            $stmt = $db->prepare("SELECT marked_by FROM cards WHERE id = ? AND table_id = ?");
            $stmt->execute([$cardId, $tableId]);
            $card = $stmt->fetch();
            $markers = json_decode($card['marked_by'] ?? '[]', true) ?? [];
            $markers = array_values(array_filter($markers, fn($m) => $m !== $userId));
            $db->prepare("UPDATE cards SET marked_by = ? WHERE id = ?")->execute([json_encode($markers), $cardId]);
        }

        return ['message' => 'Unmarked'];
    }

    private static function removeFromPlay(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $db->prepare("UPDATE cards SET in_play = 0 WHERE id IN ({$placeholders}) AND table_id = ?")
            ->execute([...$cardIds, $tableId]);

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.removed', ['card_ids' => $cardIds]);
        triggerTableEvent($tableId, 'card.removed', ['card_ids' => $cardIds], $userId, $_SESSION['display_name']);

        return ['message' => 'Removed from play'];
    }

    private static function returnToPlay(PDO $db, string $tableId, array $body, array $player): array {
        $cardIds = requireParam($body, 'card_ids');
        $targetZoneId = requireParam($body, 'target_zone_id');

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $db->prepare("UPDATE cards SET in_play = 1, zone_id = ?, holder_player_id = NULL WHERE id IN ({$placeholders}) AND table_id = ?")
            ->execute([$targetZoneId, ...$cardIds, $tableId]);

        $userId = $player['user_id'];
        self::logAction($db, $tableId, $userId, 'card.returned', ['card_ids' => $cardIds, 'target_zone_id' => $targetZoneId]);
        triggerTableEvent($tableId, 'card.returned', ['card_ids' => $cardIds, 'target_zone_id' => $targetZoneId], $userId, $_SESSION['display_name']);

        return ['message' => 'Returned to play'];
    }

    private static function reorderHand(PDO $db, string $tableId, array $body, array $player): array {
        $cardIdsOrdered = requireParam($body, 'card_ids_ordered');

        $stmt = $db->prepare("UPDATE cards SET position_in_zone = ? WHERE id = ? AND table_id = ? AND holder_player_id = ?");
        foreach ($cardIdsOrdered as $pos => $cardId) {
            $stmt->execute([$pos, $cardId, $tableId, $player['id']]);
        }

        $userId = $player['user_id'];
        triggerTableEvent($tableId, 'card.reordered', ['player_id' => $player['id'], 'count' => count($cardIdsOrdered)], $userId, $_SESSION['display_name']);

        return ['message' => 'Reordered'];
    }
}
