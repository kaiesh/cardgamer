<?php
/**
 * Utility functions.
 */

function uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function jsonResponse(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function errorResponse(string $message, string $code, int $status = 400): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message, 'code' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        errorResponse('Invalid JSON body', 'INVALID_JSON');
    }
    return $data;
}

function requireParam(array $body, string $key): mixed {
    if (!array_key_exists($key, $body) || $body[$key] === '') {
        errorResponse("Missing required parameter: {$key}", 'MISSING_PARAM');
    }
    return $body[$key];
}

function validateEmail(string $email): string {
    $email = trim(strtolower($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        errorResponse('Invalid email address', 'INVALID_EMAIL');
    }
    return $email;
}

function sanitizeString(string $value, int $maxLength = 255): string {
    return mb_substr(trim(strip_tags($value)), 0, $maxLength);
}

function getConfig(): array {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function isAdmin(): bool {
    return !empty($_SESSION['is_admin']);
}

function currentUserId(): ?string {
    return $_SESSION['user_id'] ?? null;
}
