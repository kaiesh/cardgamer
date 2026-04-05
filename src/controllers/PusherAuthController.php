<?php

class PusherAuthController {
    public static function auth(): void {
        $user = requireAuth();
        $db = DB::get();

        $channelName = $_POST['channel_name'] ?? '';
        $socketId = $_POST['socket_id'] ?? '';

        if (!$channelName || !$socketId) {
            errorResponse('Missing channel_name or socket_id', 'MISSING_PARAM');
        }

        $pusher = getPusher();

        // Presence channel (lobby)
        if (str_starts_with($channelName, 'presence-lobby')) {
            $userData = [
                'user_id'   => $user['id'],
                'user_info' => [
                    'name'  => $_SESSION['display_name'] ?? 'Anonymous',
                    'color' => '',
                ],
            ];
            // Get color
            $stmt = $db->prepare('SELECT avatar_color FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $u = $stmt->fetch();
            if ($u) $userData['user_info']['color'] = $u['avatar_color'];

            $auth = $pusher->presenceAuth($channelName, $socketId, $user['id'], $userData['user_info']);
            header('Content-Type: application/json');
            echo $auth;
            exit;
        }

        // Private table channel
        if (preg_match('/^private-table-(.+)$/', $channelName, $m)) {
            $tableId = $m[1];
            // Verify membership
            $stmt = $db->prepare('SELECT id FROM table_players WHERE table_id = ? AND user_id = ?');
            $stmt->execute([$tableId, $user['id']]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                echo json_encode(['error' => 'Not a table member']);
                exit;
            }
            $auth = $pusher->socketAuth($channelName, $socketId);
            header('Content-Type: application/json');
            echo $auth;
            exit;
        }

        // Private user channel
        if (preg_match('/^private-user-(.+)$/', $channelName, $m)) {
            $targetUserId = $m[1];
            if ($targetUserId !== $user['id']) {
                http_response_code(403);
                echo json_encode(['error' => 'Unauthorized']);
                exit;
            }
            $auth = $pusher->socketAuth($channelName, $socketId);
            header('Content-Type: application/json');
            echo $auth;
            exit;
        }

        http_response_code(403);
        echo json_encode(['error' => 'Unknown channel']);
        exit;
    }
}
