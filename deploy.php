#!/usr/bin/env php
<?php
/**
 * Card Table Platform - One-Shot Deployment Script
 *
 * Run: php deploy.php
 *
 * This script prompts for all required configuration, installs dependencies,
 * sets up the database, configures Apache, and gets the platform running.
 */

echo "\n";
echo "╔══════════════════════════════════════════╗\n";
echo "║     Card Table Platform - Deployer       ║\n";
echo "║          🃏 Let's set it up! 🃏          ║\n";
echo "╚══════════════════════════════════════════╝\n";
echo "\n";

// Determine install path
$defaultPath = '/var/www/cardtable';
$installPath = prompt("Install path", $defaultPath);

// ─── Step 1: Gather all configuration ───────────────────────────────

echo "\n── Database Configuration ──\n";
$dbHost     = prompt("MySQL host", "localhost");
$dbPort     = prompt("MySQL port", "3306");
$dbName     = prompt("Database name", "cardtable");
$dbUser     = prompt("Database username", "cardtable_user");
$dbPass     = prompt("Database password (new user will be created)", "", true);
$dbRootUser = prompt("MySQL root/admin username (for setup)", "root");
$dbRootPass = prompt("MySQL root/admin password", "", true);

echo "\n── Pusher Configuration ──\n";
echo "  (Create a free app at https://pusher.com)\n";
$pusherAppId  = prompt("Pusher App ID");
$pusherKey    = prompt("Pusher Key");
$pusherSecret = prompt("Pusher Secret");
$pusherCluster = prompt("Pusher Cluster", "eu");

echo "\n── Email (SMTP) Configuration ──\n";
$smtpHost = prompt("SMTP host", "smtp.mailgun.org");
$smtpPort = prompt("SMTP port", "587");
$smtpUser = prompt("SMTP username");
$smtpPass = prompt("SMTP password", "", true);
$mailFrom = prompt("From email address", "noreply@cardtable.example.com");
$mailName = prompt("From name", "Card Table");

echo "\n── Application Configuration ──\n";
$appName   = prompt("Application name", "Card Table");
$appDomain = prompt("Domain name (e.g., cardtable.example.com)");
$useSSL    = strtolower(prompt("Set up SSL with Let's Encrypt? (y/n)", "y")) === 'y';
$appUrl    = ($useSSL ? 'https://' : 'http://') . $appDomain;

echo "\n── Admin Configuration ──\n";
$adminEmails = prompt("Admin email addresses (comma-separated)");
$adminList = array_map('trim', explode(',', $adminEmails));

// ─── Step 2: Confirm ────────────────────────────────────────────────

echo "\n── Configuration Summary ──\n";
echo "  Install path:  {$installPath}\n";
echo "  Database:      {$dbUser}@{$dbHost}:{$dbPort}/{$dbName}\n";
echo "  Pusher:        {$pusherKey} ({$pusherCluster})\n";
echo "  SMTP:          {$smtpUser}@{$smtpHost}:{$smtpPort}\n";
echo "  App URL:       {$appUrl}\n";
echo "  SSL:           " . ($useSSL ? 'Yes' : 'No') . "\n";
echo "  Admins:        " . implode(', ', $adminList) . "\n";
echo "\n";

if (strtolower(prompt("Proceed with deployment? (y/n)", "y")) !== 'y') {
    echo "Aborted.\n";
    exit(1);
}

// ─── Step 3: Check prerequisites ────────────────────────────────────

echo "\n── Checking prerequisites ──\n";

$checks = [
    'PHP 8.2+' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'php-mysql' => extension_loaded('pdo_mysql'),
    'php-json' => extension_loaded('json'),
    'php-mbstring' => extension_loaded('mbstring'),
    'php-curl' => extension_loaded('curl'),
];

$allOk = true;
foreach ($checks as $name => $ok) {
    echo "  {$name}: " . ($ok ? "✓" : "✗ MISSING") . "\n";
    if (!$ok) $allOk = false;
}

$composerExists = shell_exec('which composer 2>/dev/null');
echo "  Composer: " . ($composerExists ? "✓" : "✗ MISSING") . "\n";
if (!$composerExists) $allOk = false;

if (!$allOk) {
    echo "\n  Some prerequisites are missing. Install them first:\n";
    echo "  sudo apt update && sudo apt install -y php8.2 php8.2-mysql php8.2-mbstring php8.2-curl php8.2-xml composer\n\n";

    if (strtolower(prompt("Try to install missing packages automatically? (y/n)", "y")) === 'y') {
        run("sudo apt update -y");
        run("sudo apt install -y php php-mysql php-mbstring php-curl php-xml apache2 libapache2-mod-php mysql-server composer");
        echo "  Packages installed. Continuing...\n";
    } else {
        echo "  Please install prerequisites and re-run this script.\n";
        exit(1);
    }
}

// ─── Step 4: Copy application files ─────────────────────────────────

echo "\n── Deploying application files ──\n";

if (!is_dir($installPath)) {
    run("sudo mkdir -p {$installPath}");
}

$scriptDir = __DIR__;
run("sudo rsync -a --exclude='.git' --exclude='deploy.php' --exclude='card-platform-spec.md' --exclude='README.md' {$scriptDir}/ {$installPath}/");
echo "  Files copied to {$installPath}\n";

// ─── Step 5: Write configuration ────────────────────────────────────

echo "\n── Writing configuration ──\n";

$configContent = "<?php\nreturn " . var_export([
    'db' => [
        'host'     => $dbHost,
        'port'     => (int)$dbPort,
        'database' => $dbName,
        'username' => $dbUser,
        'password' => $dbPass,
        'charset'  => 'utf8mb4',
    ],
    'pusher' => [
        'app_id'  => $pusherAppId,
        'key'     => $pusherKey,
        'secret'  => $pusherSecret,
        'cluster' => $pusherCluster,
        'use_tls' => true,
    ],
    'mail' => [
        'smtp_host' => $smtpHost,
        'smtp_port' => (int)$smtpPort,
        'smtp_user' => $smtpUser,
        'smtp_pass' => $smtpPass,
        'from_addr' => $mailFrom,
        'from_name' => $mailName,
    ],
    'app' => [
        'name'             => $appName,
        'url'              => $appUrl,
        'session_ttl'      => 86400,
        'otp_ttl'          => 600,
        'otp_max_attempts' => 5,
        'max_upload_size'  => 2097152,
    ],
], true) . ";\n";

file_put_contents("{$installPath}/src/config.php", $configContent);
echo "  config.php written\n";

$adminsContent = "<?php\nreturn " . var_export($adminList, true) . ";\n";
file_put_contents("{$installPath}/src/admins.php", $adminsContent);
echo "  admins.php written\n";

// ─── Step 6: Install Composer dependencies ──────────────────────────

echo "\n── Installing Composer dependencies ──\n";
run("cd {$installPath} && composer install --no-dev --optimize-autoloader 2>&1");

// ─── Step 7: Database setup ─────────────────────────────────────────

echo "\n── Setting up database ──\n";

$escapedDbPass = escapeshellarg($dbPass);
$escapedRootPass = $dbRootPass ? "-p" . escapeshellarg($dbRootPass) : "";

// Create database and user
$sqlSetup = "
CREATE DATABASE IF NOT EXISTS \`{$dbName}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}';
GRANT ALL PRIVILEGES ON \`{$dbName}\`.* TO '{$dbUser}'@'localhost';
FLUSH PRIVILEGES;
";

$tmpSql = tempnam(sys_get_temp_dir(), 'cardtable_setup_');
file_put_contents($tmpSql, $sqlSetup);
run("mysql -u {$dbRootUser} {$escapedRootPass} < {$tmpSql} 2>&1");
unlink($tmpSql);
echo "  Database and user created\n";

// Run migrations
run("php {$installPath}/scripts/migrate.php 2>&1");
echo "  Migrations applied\n";

// Seed phrases
run("php {$installPath}/scripts/seed_phrases.php 2>&1");
echo "  Default chat phrases seeded\n";

// ─── Step 8: File permissions ───────────────────────────────────────

echo "\n── Setting permissions ──\n";
run("sudo chown -R www-data:www-data {$installPath}");
run("sudo chmod -R 755 {$installPath}");
run("sudo chmod -R 775 {$installPath}/public/assets/uploads");
echo "  Permissions set\n";

// ─── Step 9: Apache configuration ───────────────────────────────────

echo "\n── Configuring Apache ──\n";

run("sudo a2enmod rewrite ssl 2>/dev/null");

$apacheConf = "<VirtualHost *:80>
    ServerName {$appDomain}
    DocumentRoot {$installPath}/public

    <Directory {$installPath}/public>
        AllowOverride All
        Require all granted
    </Directory>

    <Directory {$installPath}/src>
        Require all denied
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/cardtable-error.log
    CustomLog \${APACHE_LOG_DIR}/cardtable-access.log combined
</VirtualHost>
";

$confPath = "/etc/apache2/sites-available/cardtable.conf";
file_put_contents('/tmp/cardtable-apache.conf', $apacheConf);
run("sudo mv /tmp/cardtable-apache.conf {$confPath}");
run("sudo a2ensite cardtable.conf 2>/dev/null");
run("sudo a2dissite 000-default.conf 2>/dev/null");
run("sudo systemctl reload apache2 2>&1");
echo "  Apache configured and reloaded\n";

// ─── Step 10: SSL ───────────────────────────────────────────────────

if ($useSSL) {
    echo "\n── Setting up SSL with Let's Encrypt ──\n";
    $certbotExists = shell_exec('which certbot 2>/dev/null');
    if (!$certbotExists) {
        run("sudo apt install -y certbot python3-certbot-apache 2>&1");
    }
    run("sudo certbot --apache -d {$appDomain} --non-interactive --agree-tos --email " . escapeshellarg($adminList[0]) . " 2>&1");
    echo "  SSL certificate installed\n";
}

// ─── Step 11: Cron job ──────────────────────────────────────────────

echo "\n── Setting up cron job ──\n";
$cronLine = "*/5 * * * * php {$installPath}/scripts/cleanup_sessions.php > /dev/null 2>&1";
$existingCron = shell_exec('crontab -l 2>/dev/null') ?? '';
if (strpos($existingCron, 'cleanup_sessions.php') === false) {
    $newCron = trim($existingCron) . "\n{$cronLine}\n";
    file_put_contents('/tmp/cardtable-cron', $newCron);
    run("crontab /tmp/cardtable-cron");
    unlink('/tmp/cardtable-cron');
    echo "  Session cleanup cron installed\n";
} else {
    echo "  Cron already exists, skipping\n";
}

// ─── Done! ──────────────────────────────────────────────────────────

echo "\n";
echo "╔══════════════════════════════════════════╗\n";
echo "║         Deployment Complete! 🎉          ║\n";
echo "╚══════════════════════════════════════════╝\n";
echo "\n";
echo "  Your Card Table is live at: {$appUrl}\n";
echo "\n";
echo "  Admin access: Log in with one of these emails:\n";
foreach ($adminList as $email) {
    echo "    - {$email}\n";
}
echo "\n";
echo "  Next steps:\n";
echo "    1. Verify the site loads at {$appUrl}\n";
echo "    2. Log in with an admin email to test OTP flow\n";
echo "    3. Create your first table!\n";
echo "\n";
echo "  Logs:\n";
echo "    Apache error: /var/log/apache2/cardtable-error.log\n";
echo "    Apache access: /var/log/apache2/cardtable-access.log\n";
echo "\n";

// ─── Helper functions ───────────────────────────────────────────────

function prompt(string $message, string $default = '', bool $hidden = false): string {
    $display = $default ? " [{$default}]" : '';
    echo "  {$message}{$display}: ";

    if ($hidden && function_exists('readline')) {
        // Try to hide input
        system('stty -echo 2>/dev/null');
        $input = trim(fgets(STDIN));
        system('stty echo 2>/dev/null');
        echo "\n";
    } else {
        $input = trim(fgets(STDIN));
    }

    return $input !== '' ? $input : $default;
}

function run(string $command): void {
    $output = [];
    $code = 0;
    exec($command, $output, $code);
    if ($code !== 0) {
        $outputStr = implode("\n", $output);
        echo "    Warning: Command returned code {$code}\n";
        if ($outputStr) echo "    Output: {$outputStr}\n";
    }
}
