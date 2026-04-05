<?php

class AuthController {
    public static function requestOtp(): array {
        $body = jsonBody();
        $email = validateEmail(requireParam($body, 'email'));
        checkOtpRateLimit($email);

        $otp = generateOtp();
        storeOtp($email, $otp);

        if (!sendOtpEmail($email, $otp)) {
            errorResponse('Failed to send OTP email', 'EMAIL_FAILED', 500);
        }

        return ['message' => 'OTP sent to your email'];
    }

    public static function verifyOtp(): array {
        $body = jsonBody();
        $email = validateEmail(requireParam($body, 'email'));
        $otp = requireParam($body, 'otp');

        if (!verifyOtp($email, $otp)) {
            errorResponse('Invalid or expired OTP', 'INVALID_OTP', 401);
        }

        $user = getOrCreateUser($email);
        loginUser($user);

        return [
            'user' => [
                'id'           => $user['id'],
                'email'        => $user['email'],
                'display_name' => $user['display_name'],
                'avatar_color' => $user['avatar_color'],
                'is_new'       => !empty($user['is_new']),
                'is_admin'     => !empty($_SESSION['is_admin']),
            ],
        ];
    }

    public static function logout(): array {
        session_destroy();
        return ['message' => 'Logged out'];
    }

    public static function me(): array {
        $user = requireAuth();
        $db = DB::get();
        $stmt = $db->prepare('SELECT id, email, display_name, avatar_color, created_at FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $userData = $stmt->fetch();
        if (!$userData) {
            errorResponse('User not found', 'USER_NOT_FOUND', 404);
        }
        $userData['is_admin'] = isAdmin();
        return ['user' => $userData];
    }

    public static function updateMe(): array {
        $user = requireAuth();
        $body = jsonBody();
        $db = DB::get();

        $updates = [];
        $params = [];

        if (isset($body['display_name'])) {
            $name = sanitizeString($body['display_name'], 50);
            if (strlen($name) < 1) {
                errorResponse('Display name must be at least 1 character', 'INVALID_NAME');
            }
            $updates[] = 'display_name = ?';
            $params[] = $name;
            $_SESSION['display_name'] = $name;
        }

        if (isset($body['avatar_color'])) {
            $color = $body['avatar_color'];
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                errorResponse('Invalid color format', 'INVALID_COLOR');
            }
            $updates[] = 'avatar_color = ?';
            $params[] = $color;
        }

        if (empty($updates)) {
            errorResponse('No fields to update', 'NO_UPDATES');
        }

        $params[] = $user['id'];
        $db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

        return self::me()['data'] ?? self::me();
    }
}
