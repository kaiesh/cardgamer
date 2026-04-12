#!/usr/bin/env php
<?php
/**
 * Card Table - Single-script deployment tool
 *
 * Deploys the entire Card Table platform to a remote Ubuntu server.
 * Run locally: php deploy.php
 *
 * This script will:
 * 1. Prompt for all required configuration (server, DB, Pusher, SMTP, domain)
 * 2. Connect to the server via SSH
 * 3. Install all system dependencies (PHP 8.3, MySQL 8, Apache, Composer)
 * 4. Upload the application code (excludes secrets, vendor, uploads, .git)
 * 5. Generate src/config.php and src/admins.php on the server
 * 6. Configure MySQL, Apache, SSL, firewall
 * 7. Run migrations and seed default chat phrases
 * 8. Install the session-cleanup cron job
 * 9. Harden the server (firewall, fail2ban, optional SSH port change)
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    die("PHP 8.0+ required to run this deploy script.\n");
}

require_once __DIR__ . '/deploy-lib.php';

// ──────────────────────────────────────────────
// Main deployment flow
// ──────────────────────────────────────────────

banner('Card Table - Deployment Tool');

echo "This script will deploy the Card Table platform to your remote server.\n";
echo "You will be prompted for all required configuration.\n";
echo "Make sure you have SSH access to the server before continuing.\n\n";

if (!confirm("Ready to proceed?")) {
    echo "Aborted.\n";
    exit(0);
}

// ─── Step 1: Server connection details ───

banner('Step 1: Server Connection');

$config = [];
$config['server_ip'] = prompt('Server IP address');
$config['ssh_user']  = prompt('SSH username (non-root sudoer recommended)', 'deploy');
$config['ssh_port']  = prompt('SSH port', '22');
$config['ssh_key']   = prompt('SSH private key path (leave empty for default)', '', false);
$config['domain']    = prompt('Site domain (e.g. cardtable.example.com)');
$config['app_dir']   = prompt('Install path on server', '/var/www/cardtable');
$config['new_ssh_port'] = prompt('New SSH port for hardening (leave empty to keep current)', '', false);

$config['sudo_pass'] = '';
if ($config['ssh_user'] !== 'root') {
    echo "\n";
    echo "  The SSH user '{$config['ssh_user']}' is not root. The script will use sudo for\n";
    echo "  privileged operations. If you have passwordless sudo (NOPASSWD) configured for\n";
    echo "  this user, leave the password blank. Otherwise enter your sudo password.\n\n";
    $config['sudo_pass'] = prompt('Sudo password (leave empty for NOPASSWD)', '', false, true);
}

info("Testing SSH connection...");
$testOutput = sshExec($config, 'echo "SSH_OK"', true);
if (strpos($testOutput, 'SSH_OK') === false) {
    error("Cannot connect to {$config['server_ip']}. Check your credentials.");
    error("Output: $testOutput");
    exit(1);
}
success("SSH connection successful.");

if ($config['ssh_user'] !== 'root') {
    info("Testing sudo access...");
    if (!sshSudoProbe($config)) {
        error("sudo access check failed.");
        error("The SSH user '{$config['ssh_user']}' must be able to run sudo.");
        error("");
        error("To enable passwordless sudo, run on the server as root:");
        error("  echo '{$config['ssh_user']} ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/{$config['ssh_user']}");
        error("  chmod 440 /etc/sudoers.d/{$config['ssh_user']}");
        error("");
        error("Or re-run this script and enter the sudo password when prompted.");
        exit(1);
    }
    success("Sudo access confirmed.");
}

// ─── Step 2: Database credentials ───

banner('Step 2: Database Configuration');

$config['db_host'] = prompt('MySQL host', '127.0.0.1');
$config['db_port'] = prompt('MySQL port', '3306');
$config['db_name'] = prompt('MySQL database name', 'cardtable');
$config['db_user'] = prompt('MySQL application username', 'cardtable_user');
$config['db_pass'] = prompt('MySQL application password (will be created)', '', true, true);
// Note: Ubuntu's MySQL uses auth_socket for root, so we invoke `sudo mysql` for
// admin DDL — no root password is ever stored or transmitted. The application
// connects only as the dedicated $db_user with restricted privileges.

// ─── Step 3: Pusher ───

banner('Step 3: Pusher Configuration');

echo "Create a free app at https://pusher.com if you don't have one.\n\n";
$config['pusher_app_id']  = prompt('Pusher App ID');
$config['pusher_key']     = prompt('Pusher Key');
$config['pusher_secret']  = prompt('Pusher Secret', '', true, true);
$config['pusher_cluster'] = prompt('Pusher Cluster', 'eu');

// ─── Step 4: SMTP ───

banner('Step 4: SMTP (OTP email delivery)');

$config['smtp_host'] = prompt('SMTP host', 'smtp.mailgun.org');
$config['smtp_port'] = prompt('SMTP port', '587');
$config['smtp_user'] = prompt('SMTP username');
$config['smtp_pass'] = prompt('SMTP password', '', true, true);
$config['mail_from'] = prompt('From email address', "noreply@{$config['domain']}");
$config['mail_name'] = prompt('From name', 'Card Table');

// ─── Step 5: Application & admins ───

banner('Step 5: Application & Admins');

$config['app_name']    = prompt('Application name', 'Card Table');
$config['admin_emails'] = prompt('Admin email addresses (comma-separated)');

// ─── Step 6: Tunables ───

banner('Step 6: Application Tunables');

echo "These control session lifetime, OTP behaviour, and upload limits.\n";
echo "Defaults are sensible — press Enter to accept each one.\n\n";

$config['session_ttl']      = prompt('Session lifetime in seconds', '86400');
$config['otp_ttl']          = prompt('OTP code lifetime in seconds', '600');
$config['otp_max_attempts'] = prompt('Max OTP verify attempts before lockout', '5');
$config['max_upload_size']  = prompt('Max upload size in bytes', '2097152');

// ─── Step 7: Confirm and deploy ───

banner('Step 7: Deployment Summary');

echo "  Server:    {$config['server_ip']}:{$config['ssh_port']}\n";
echo "  Domain:    {$config['domain']}\n";
echo "  App dir:   {$config['app_dir']}\n";
echo "  Database:  {$config['db_user']}@{$config['db_host']}:{$config['db_port']}/{$config['db_name']}\n";
echo "  Pusher:    {$config['pusher_key']} ({$config['pusher_cluster']})\n";
echo "  SMTP:      {$config['smtp_user']}@{$config['smtp_host']}:{$config['smtp_port']}\n";
echo "  Admins:    {$config['admin_emails']}\n";
echo "  Session:   {$config['session_ttl']}s   OTP: {$config['otp_ttl']}s (max {$config['otp_max_attempts']} attempts)\n";
echo "  Uploads:   {$config['max_upload_size']} bytes\n";
echo "\n";

if (!confirm("Deploy with these settings?")) {
    echo "Aborted.\n";
    exit(0);
}

// ──────────────────────────────────────────────
// DEPLOYMENT EXECUTION
// ──────────────────────────────────────────────

banner('Deploying Card Table');

// ─── Install system packages ───

info("Installing system packages (PHP 8.3, MySQL 8, Apache, etc.)...");
sshSudoStream($config, 'export DEBIAN_FRONTEND=noninteractive && apt-get update -qq && apt-get install -y -qq software-properties-common 2>&1 | tail -5');

sshSudoStream($config, 'export DEBIAN_FRONTEND=noninteractive && add-apt-repository -y ppa:ondrej/php 2>&1 | tail -3');

sshSudoStream($config, 'export DEBIAN_FRONTEND=noninteractive && apt-get update -qq && apt-get install -y -qq \
    php8.3 php8.3-cli php8.3-mysql php8.3-curl php8.3-xml php8.3-mbstring php8.3-zip php8.3-gd php8.3-intl \
    libapache2-mod-php8.3 \
    apache2 apache2-utils \
    mysql-server \
    certbot python3-certbot-apache \
    fail2ban \
    ufw \
    unzip curl git rsync \
    2>&1 | tail -10');

success("System packages installed.");

// ─── Install Composer ───

info("Installing Composer...");
sshSudo($config, 'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer');
success("Composer installed.");

// ─── Create directory structure ───

$appDir = $config['app_dir'];
$logDir = '/var/log/cardtable';
$tmpDir = '/tmp/cardtable';

info("Creating directory structure...");
sshSudo($config, "mkdir -p " . escapeshellarg($appDir) . " " . escapeshellarg($logDir) . " " . escapeshellarg($tmpDir));
sshSudo($config, "chown -R www-data:www-data " . escapeshellarg($logDir) . " " . escapeshellarg($tmpDir));

// ─── Upload application code ───

// rsync over ssh runs the remote side as the deploy user. Since the target
// parent is owned by www-data at this point, we temporarily hand ownership
// to the deploy user, rsync, then hand it back.
info("Preparing upload target...");
$sshUser = $config['ssh_user'];
sshSudo($config, "chown {$sshUser}:{$sshUser} " . escapeshellarg($appDir));

info("Uploading application code...");
$projectDir = __DIR__;
$uploaded = rsyncUpload($config, $projectDir, $appDir);
if (!$uploaded) {
    error("Failed to upload application code.");
    sshSudo($config, "chown -R www-data:www-data " . escapeshellarg($appDir));
    exit(1);
}
success("Application code uploaded.");

// ─── Set ownership and permissions ───

info("Setting file permissions...");
sshSudo($config, "chown -R www-data:www-data " . escapeshellarg($appDir));
sshSudo($config, "find " . escapeshellarg($appDir) . " -type f -exec chmod 644 {} \\;");
sshSudo($config, "find " . escapeshellarg($appDir) . " -type d -exec chmod 755 {} \\;");

// ─── Write src/config.php and src/admins.php ───

info("Writing src/config.php...");
$configPhp = renderConfigPhp($config);
sshWriteFile($config, "{$appDir}/src/config.php", $configPhp, 'www-data', 'www-data', '640');
success("src/config.php written (mode 640, owner www-data).");

info("Writing src/admins.php...");
$adminsPhp = renderAdminsPhp($config['admin_emails']);
sshWriteFile($config, "{$appDir}/src/admins.php", $adminsPhp, 'www-data', 'www-data', '640');
success("src/admins.php written.");

// ─── Ensure uploads directory exists and is writable ───

info("Creating uploads directory...");
sshSudo($config, "mkdir -p " . escapeshellarg("{$appDir}/public/assets/uploads"));
sshSudo($config, "chown -R www-data:www-data " . escapeshellarg("{$appDir}/public/assets/uploads"));
sshSudo($config, "chmod 775 " . escapeshellarg("{$appDir}/public/assets/uploads"));

// ─── Install Composer dependencies ───

info("Installing Composer dependencies...");
$composerCode = sshSudoAsStream(
    $config,
    'www-data',
    "cd " . escapeshellarg($appDir) . " && HOME=" . escapeshellarg($tmpDir) . " composer install --no-dev --optimize-autoloader"
);
if ($composerCode !== 0) {
    error("composer install failed with exit code {$composerCode}.");
    error("Re-run manually to inspect the full output:");
    error("  sudo -u www-data bash -c 'cd {$appDir} && HOME={$tmpDir} composer install --no-dev --optimize-autoloader'");
    exit(1);
}
success("Composer dependencies installed.");

// ─── Configure MySQL ───

info("Configuring MySQL...");

$escapedDbPass = str_replace("'", "''", $config['db_pass']);
$dbName = $config['db_name'];
$dbUser = $config['db_user'];

// Create the database and application user. Ubuntu's fresh-install root MySQL
// uses auth_socket — requires UID 0 — so we invoke via sudo. The app user
// gets only DML privileges; CREATE/ALTER are granted only for migrations.
$createSql = "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
    . "CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$escapedDbPass}'; "
    . "ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$escapedDbPass}'; "
    . "GRANT SELECT, INSERT, UPDATE, DELETE ON `{$dbName}`.* TO '{$dbUser}'@'localhost'; "
    . "FLUSH PRIVILEGES;";
sshSudo($config, "mysql -e " . escapeshellarg($createSql));

// MySQL tuning — drop-in file rather than mutating mysqld.cnf.
$mycnfContent = <<<'MYCNF'
[mysqld]
# Card Table optimizations
bind-address = 127.0.0.1
innodb_buffer_pool_size = 256M
max_connections = 30
slow_query_log = 1
long_query_time = 2
slow_query_log_file = /var/log/mysql/mysql-slow.log
MYCNF;
sshWriteFile($config, '/etc/mysql/mysql.conf.d/99-cardtable.cnf', $mycnfContent, 'root', 'root', '644');

sshSudo($config, 'systemctl restart mysql');
success("MySQL configured.");

// ─── Run migrations (temporarily grant CREATE/ALTER privileges) ───

info("Running database migrations...");
$grantSql = "GRANT CREATE, ALTER, INDEX, REFERENCES ON `{$dbName}`.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;";
sshSudo($config, "mysql -e " . escapeshellarg($grantSql));

$migrateCode = sshSudoAsStream(
    $config,
    'www-data',
    "cd " . escapeshellarg($appDir) . " && HOME=" . escapeshellarg($tmpDir) . " php scripts/migrate.php 2>&1"
);

$revokeSql = "REVOKE CREATE, ALTER, INDEX, REFERENCES ON `{$dbName}`.* FROM '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;";
sshSudo($config, "mysql -e " . escapeshellarg($revokeSql));

if ($migrateCode !== 0) {
    error("Migrations failed with exit code {$migrateCode}.");
    exit(1);
}
success("Migrations complete.");

// ─── Seed default chat phrases ───

info("Seeding default chat phrases...");
$seedCode = sshSudoAsStream(
    $config,
    'www-data',
    "cd " . escapeshellarg($appDir) . " && HOME=" . escapeshellarg($tmpDir) . " php scripts/seed_phrases.php 2>&1"
);
if ($seedCode !== 0) {
    error("Seed failed with exit code {$seedCode}.");
    exit(1);
}
success("Default phrases seeded.");

// ─── Configure Apache ───

info("Configuring Apache...");

sshSudo($config, 'a2enmod rewrite ssl headers php8.3 2>&1');
sshSudo($config, 'a2dissite 000-default 2>&1');

$domain = $config['domain'];
$vhostConfig = <<<VHOST
# Redirect HTTP to HTTPS
<VirtualHost *:80>
    ServerName {$domain}
    RewriteEngine On
    RewriteRule ^(.*)\$ https://%{HTTP_HOST}\$1 [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName {$domain}

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/{$domain}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/{$domain}/privkey.pem
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1

    DocumentRoot {$appDir}/public

    <Directory {$appDir}/public>
        AllowOverride All
        Require all granted
    </Directory>

    <Directory {$appDir}/src>
        Require all denied
    </Directory>

    # Security headers
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"

    php_admin_value memory_limit 128M
    php_admin_value upload_max_filesize 4M
    php_admin_value post_max_size 8M
    php_admin_value open_basedir "{$appDir}:{$tmpDir}:{$logDir}:/tmp"

    ErrorLog \${APACHE_LOG_DIR}/cardtable-error.log
    CustomLog \${APACHE_LOG_DIR}/cardtable-access.log combined
</VirtualHost>
VHOST;

sshWriteFile($config, '/etc/apache2/sites-available/cardtable.conf', $vhostConfig, 'root', 'root', '644');

// Apache MPM tuning for small droplets
$mpmConfig = <<<'MPMCONF'
<IfModule mpm_prefork_module>
    StartServers           2
    MinSpareServers        2
    MaxSpareServers        4
    MaxRequestWorkers      8
    MaxConnectionsPerChild 1000
</IfModule>
MPMCONF;
sshWriteFile($config, '/etc/apache2/mods-available/mpm_prefork.conf', $mpmConfig, 'root', 'root', '644');

sshSudo($config, 'a2ensite cardtable 2>&1');

success("Apache configured.");

// ─── SSL Certificate ───

info("Obtaining SSL certificate via Let's Encrypt...");
echo "\n";
warn("Make sure DNS for {$domain} points to {$config['server_ip']} before continuing.");
if (confirm("DNS is configured and ready for SSL?")) {
    // Temporarily enable HTTP-only VHost so certbot can solve the http-01 challenge.
    $tempVhost = <<<TVHOST
<VirtualHost *:80>
    ServerName {$domain}
    DocumentRoot {$appDir}/public
    <Directory {$appDir}/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
TVHOST;
    sshWriteFile($config, '/etc/apache2/sites-available/cardtable.conf', $tempVhost, 'root', 'root', '644');
    sshSudo($config, 'systemctl restart apache2 2>&1');

    // Pick the first admin email for Let's Encrypt notifications.
    $firstAdmin = trim(explode(',', $config['admin_emails'])[0]) ?: "admin@{$domain}";
    $escapedDomain = escapeshellarg($domain);
    $escapedEmail = escapeshellarg($firstAdmin);
    sshSudoStream($config, "certbot --apache -d {$escapedDomain} --non-interactive --agree-tos --email {$escapedEmail} --redirect 2>&1");

    // Now write the full VHost with SSL.
    sshWriteFile($config, '/etc/apache2/sites-available/cardtable.conf', $vhostConfig, 'root', 'root', '644');
    sshSudo($config, 'systemctl restart apache2 2>&1');
    success("SSL certificate obtained and configured.");
} else {
    warn("Skipping SSL. You can run 'certbot --apache -d {$domain}' later.");
    $httpVhost = <<<HVHOST
<VirtualHost *:80>
    ServerName {$domain}
    DocumentRoot {$appDir}/public

    <Directory {$appDir}/public>
        AllowOverride All
        Require all granted
    </Directory>

    <Directory {$appDir}/src>
        Require all denied
    </Directory>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"

    php_admin_value memory_limit 128M
    php_admin_value open_basedir "{$appDir}:{$tmpDir}:{$logDir}:/tmp"

    ErrorLog \${APACHE_LOG_DIR}/cardtable-error.log
    CustomLog \${APACHE_LOG_DIR}/cardtable-access.log combined
</VirtualHost>
HVHOST;
    sshWriteFile($config, '/etc/apache2/sites-available/cardtable.conf', $httpVhost, 'root', 'root', '644');
    sshSudo($config, 'systemctl restart apache2 2>&1');
}

// ─── PHP Configuration ───

info("Configuring PHP...");

$cliPhpIni = <<<'PHPCLI'
memory_limit = 256M
max_execution_time = 300
PHPCLI;
sshWriteFile($config, '/etc/php/8.3/cli/conf.d/99-cardtable.ini', $cliPhpIni, 'root', 'root', '644');

$apachePhpIni = <<<'PHPAPACHE'
memory_limit = 128M
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,parse_ini_file,show_source
expose_php = Off
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = "Strict"
PHPAPACHE;
sshWriteFile($config, '/etc/php/8.3/apache2/conf.d/99-cardtable.ini', $apachePhpIni, 'root', 'root', '644');

sshSudo($config, 'systemctl restart apache2 2>&1');
success("PHP configured.");

// ─── Firewall (UFW) ───

info("Configuring firewall...");
$sshPort = $config['new_ssh_port'] ?: $config['ssh_port'];
sshSudo($config, "ufw default deny incoming 2>&1");
sshSudo($config, "ufw default allow outgoing 2>&1");
sshSudo($config, "ufw allow {$sshPort}/tcp 2>&1");
sshSudo($config, "ufw allow 80/tcp 2>&1");
sshSudo($config, "ufw allow 443/tcp 2>&1");
sshSudo($config, "ufw --force enable 2>&1");
success("Firewall configured.");

// ─── Fail2Ban ───

info("Configuring fail2ban...");
$jailLocal = <<<'JAILCONF'
[sshd]
enabled = true
maxretry = 5
bantime = 3600

[apache-auth]
enabled = true
maxretry = 5
bantime = 1800
JAILCONF;
sshWriteFile($config, '/etc/fail2ban/jail.local', $jailLocal, 'root', 'root', '644');

sshSudo($config, 'systemctl enable fail2ban && systemctl restart fail2ban 2>&1');
success("fail2ban configured.");

// ─── SSH Hardening ───

if ($config['new_ssh_port'] && $config['new_ssh_port'] !== $config['ssh_port']) {
    info("Hardening SSH (changing port to {$config['new_ssh_port']})...");
    $newPort = (int) $config['new_ssh_port'];
    sshSudo($config, "sed -i 's/^#\\?Port .*/Port {$newPort}/' /etc/ssh/sshd_config");
    sshSudo($config, "sed -i 's/^#\\?PasswordAuthentication .*/PasswordAuthentication no/' /etc/ssh/sshd_config");
    sshSudo($config, "sed -i 's/^#\\?PermitRootLogin .*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config");
    sshSudo($config, 'systemctl restart sshd 2>&1');
    success("SSH hardened. New port: {$config['new_ssh_port']}");
    warn("Update your SSH config to use port {$config['new_ssh_port']} for future connections.");
}

// ─── Cron Jobs ───

info("Setting up cron jobs...");
$cronContent = <<<CRON
# Card Table - Automated Cron Jobs

# Session cleanup - every 5 minutes
*/5 * * * * /usr/bin/php {$appDir}/scripts/cleanup_sessions.php >> {$logDir}/cleanup.log 2>&1
CRON;

// Stage cron content to /tmp as the deploy user, then install via sudo.
$tmpCronPath = '/tmp/ct-cron-' . bin2hex(random_bytes(6));
$cronHeredoc = "cat > " . escapeshellarg($tmpCronPath) . " << 'CTCRONEOF'\n{$cronContent}\nCTCRONEOF";
sshExec($config, $cronHeredoc);
sshSudo($config, "crontab -u www-data {$tmpCronPath} && rm -f {$tmpCronPath}");
success("Cron jobs installed.");

// ─── Log rotation ───

info("Setting up log rotation...");
$logrotateConfig = <<<LROTATE
{$logDir}/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
}
LROTATE;
sshWriteFile($config, '/etc/logrotate.d/cardtable', $logrotateConfig, 'root', 'root', '644');
success("Log rotation configured.");

// ─── Unattended upgrades ───

info("Enabling unattended security upgrades...");
sshSudo($config, 'export DEBIAN_FRONTEND=noninteractive && apt-get install -y -qq unattended-upgrades 2>&1 | tail -2');
sshSudo($config, "export DEBIAN_FRONTEND=noninteractive && dpkg-reconfigure -f noninteractive unattended-upgrades 2>&1");
success("Unattended upgrades enabled.");

// ─── Final ───

banner('Deployment Complete');

echo "Card Table has been deployed to your server.\n\n";

echo "  \033[32mSite URL:\033[0m   https://{$config['domain']}/\n";
echo "  \033[32mAdmin emails:\033[0m {$config['admin_emails']}\n\n";

echo "  \033[33mNext steps:\033[0m\n";
echo "  1. Visit https://{$config['domain']}/ and verify it loads\n";
echo "  2. Log in via OTP using one of the admin emails to test the email flow\n";
echo "  3. Create your first table\n";
echo "  4. Use sync.php for incremental code updates from your dev machine\n\n";

if ($config['new_ssh_port'] && $config['new_ssh_port'] !== $config['ssh_port']) {
    warn("SSH port changed to {$config['new_ssh_port']}. Update your connection settings.");
}

success("Deployment finished successfully!");

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────

function renderConfigPhp(array $c): string
{
    $appUrl = "https://{$c['domain']}";
    $cfg = [
        'db' => [
            'host'     => $c['db_host'],
            'port'     => (int) $c['db_port'],
            'database' => $c['db_name'],
            'username' => $c['db_user'],
            'password' => $c['db_pass'],
            'charset'  => 'utf8mb4',
        ],
        'pusher' => [
            'app_id'  => $c['pusher_app_id'],
            'key'     => $c['pusher_key'],
            'secret'  => $c['pusher_secret'],
            'cluster' => $c['pusher_cluster'],
            'use_tls' => true,
        ],
        'mail' => [
            'smtp_host' => $c['smtp_host'],
            'smtp_port' => (int) $c['smtp_port'],
            'smtp_user' => $c['smtp_user'],
            'smtp_pass' => $c['smtp_pass'],
            'from_addr' => $c['mail_from'],
            'from_name' => $c['mail_name'],
        ],
        'app' => [
            'name'             => $c['app_name'],
            'url'              => $appUrl,
            'session_ttl'      => (int) $c['session_ttl'],
            'otp_ttl'          => (int) $c['otp_ttl'],
            'otp_max_attempts' => (int) $c['otp_max_attempts'],
            'max_upload_size'  => (int) $c['max_upload_size'],
        ],
    ];
    return "<?php\nreturn " . var_export($cfg, true) . ";\n";
}

function renderAdminsPhp(string $csv): string
{
    $emails = array_values(array_filter(array_map('trim', explode(',', $csv)), fn($e) => $e !== ''));
    return "<?php\nreturn " . var_export($emails, true) . ";\n";
}
