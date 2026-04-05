<?php
/**
 * OTP generation, sending, and verification.
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function generateOtp(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendOtpEmail(string $email, string $otp): bool {
    $config = getConfig()['mail'];
    $appName = getConfig()['app']['name'];

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_user'];
        $mail->Password   = $config['smtp_pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $config['smtp_port'];

        $mail->setFrom($config['from_addr'], $config['from_name']);
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = "{$appName} - Your Login Code";
        $mail->Body    = "<h2>Your login code</h2><p style='font-size:32px;font-weight:bold;letter-spacing:8px'>{$otp}</p><p>This code expires in 10 minutes.</p><p>If you didn't request this, you can safely ignore this email.</p>";
        $mail->AltBody = "Your login code is: {$otp}\nThis code expires in 10 minutes.";

        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log("OTP email failed: " . $e->getMessage());
        return false;
    }
}

function checkOtpRateLimit(string $email): void {
    $db = DB::get();
    $config = getConfig()['app'];
    $stmt = $db->prepare(
        'SELECT COUNT(*) as cnt FROM otp_codes WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
    $stmt->execute([$email]);
    if ($stmt->fetch()['cnt'] >= $config['otp_max_attempts']) {
        errorResponse('Too many OTP requests. Please wait 15 minutes.', 'RATE_LIMITED', 429);
    }
}

function storeOtp(string $email, string $otp): void {
    $db = DB::get();
    $config = getConfig()['app'];
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + $config['otp_ttl']);
    $stmt = $db->prepare('INSERT INTO otp_codes (email, otp_hash, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$email, $hash, $expiresAt]);
}

function verifyOtp(string $email, string $otp): bool {
    $db = DB::get();
    $stmt = $db->prepare(
        'SELECT id, otp_hash FROM otp_codes WHERE email = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($otp, $row['otp_hash'])) {
        return false;
    }
    // Mark used
    $stmt = $db->prepare('UPDATE otp_codes SET used = 1 WHERE id = ?');
    $stmt->execute([$row['id']]);
    return true;
}

function getOrCreateUser(string $email): array {
    $db = DB::get();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user) {
        return $user;
    }
    $id = uuid();
    $colors = ['#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#06B6D4', '#F97316'];
    $color = $colors[array_rand($colors)];
    $stmt = $db->prepare('INSERT INTO users (id, email, avatar_color) VALUES (?, ?, ?)');
    $stmt->execute([$id, $email, $color]);
    return ['id' => $id, 'email' => $email, 'display_name' => null, 'avatar_color' => $color, 'is_new' => true];
}

function loginUser(array $user): void {
    $admins = require __DIR__ . '/admins.php';
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['display_name'] = $user['display_name'];
    $_SESSION['is_admin'] = in_array($user['email'], $admins);
    session_regenerate_id(true);
}
