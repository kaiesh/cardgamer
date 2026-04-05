<?php
/**
 * Middleware functions for auth checks, CSRF, and JSON parsing.
 */

function requireAuth(): array {
    if (empty($_SESSION['user_id'])) {
        errorResponse('Authentication required', 'UNAUTHORIZED', 401);
    }
    return [
        'id'    => $_SESSION['user_id'],
        'email' => $_SESSION['email'] ?? '',
        'name'  => $_SESSION['display_name'] ?? '',
    ];
}

function requireAdmin(): array {
    $user = requireAuth();
    if (empty($_SESSION['is_admin'])) {
        errorResponse('Admin access required', 'FORBIDDEN', 403);
    }
    return $user;
}

function requireTableMember(string $tableId, string $userId): array {
    $db = DB::get();
    $stmt = $db->prepare('SELECT * FROM table_players WHERE table_id = ? AND user_id = ?');
    $stmt->execute([$tableId, $userId]);
    $player = $stmt->fetch();
    if (!$player) {
        errorResponse('You are not a member of this table', 'NOT_TABLE_MEMBER', 403);
    }
    return $player;
}

function requireTableCreator(string $tableId, string $userId): array {
    $db = DB::get();
    $stmt = $db->prepare('SELECT * FROM tables WHERE id = ? AND creator_id = ?');
    $stmt->execute([$tableId, $userId]);
    $table = $stmt->fetch();
    if (!$table) {
        errorResponse('Only the table creator can perform this action', 'NOT_CREATOR', 403);
    }
    return $table;
}

function requireTableStatus(string $tableId, array $validStatuses): array {
    $db = DB::get();
    $stmt = $db->prepare('SELECT * FROM tables WHERE id = ?');
    $stmt->execute([$tableId]);
    $table = $stmt->fetch();
    if (!$table) {
        errorResponse('Table not found', 'TABLE_NOT_FOUND', 404);
    }
    if (!in_array($table['status'], $validStatuses)) {
        errorResponse(
            'This action is not available in the current table state',
            'INVALID_TABLE_STATUS'
        );
    }
    return $table;
}

function validateCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $header = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if ($header !== 'XMLHttpRequest') {
            errorResponse('Invalid request', 'CSRF_FAILED', 403);
        }
    }
}
