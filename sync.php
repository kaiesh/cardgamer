<?php
/**
 * Card Table - Incremental code sync tool
 *
 * Ships code changes from a clean local repo to a server already set up by
 * deploy.php. Runs from the operator's machine. Reuses deploy-lib.php for
 * all SSH/sudo transport.
 *
 * Usage:
 *   php sync.php                      # interactive, uses ~/.cardtable/sync.json
 *   php sync.php --config=/path.json  # override config path
 *   php sync.php --dry-run            # rsync --dry-run, no writes
 *   php sync.php --rollback           # interactive snapshot picker
 *
 * Flow (sync mode):
 *   0. Preflight (clean git tree, confirm)
 *   1. Load or create local config
 *   2. SSH + sudo probe
 *   3. Acquire remote sentinel lock
 *   4. Snapshot current deployment via hardlink tree
 *   5. Diff remote vs local to decide composer/migrate/seed
 *   6. rsync --delete (preserves src/config.php and src/admins.php)
 *   7. composer install (if needed)
 *   8. migrate (prompts; default depends on diff)
 *   9. seed_phrases (prompt only if needed)
 *  10. Write VERSION file
 *  11. Smoke tests (DB SELECT 1 + HTTP probe)
 *  12. Prune old snapshots
 *  13. Release sentinel, success banner
 *
 * Any failure after Phase 4 triggers a rollback from the snapshot.
 *
 * Note: unlike heavier pipelines, Card Table has no kill switch / batch
 * pipeline to drain — the only background job is a 5-minute session cleanup
 * cron, which is safe to run during a sync.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    die("PHP 8.0+ required.\n");
}

require_once __DIR__ . '/deploy-lib.php';

// ──────────────────────────────────────────────
// Constants
// ──────────────────────────────────────────────

const SYNC_SENTINEL_PATH  = '/var/lock/cardtable-sync.lock';
const SYNC_DEFAULT_CONFIG = '~/.cardtable/sync.json';

// ──────────────────────────────────────────────
// Arg parsing
// ──────────────────────────────────────────────

$dryRun   = false;
$rollback = false;
$cfgPath  = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--rollback') {
        $rollback = true;
    } elseif (str_starts_with($arg, '--config=')) {
        $cfgPath = substr($arg, 9);
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "Usage: php sync.php [--dry-run] [--rollback] [--config=PATH]\n";
        exit(0);
    } else {
        error("Unknown argument: {$arg}");
        exit(1);
    }
}

$cfgPath = $cfgPath ?? expandHome(SYNC_DEFAULT_CONFIG);

// ──────────────────────────────────────────────
// Dispatch
// ──────────────────────────────────────────────

try {
    if ($rollback) {
        runRollback($cfgPath);
    } else {
        runSync($cfgPath, $dryRun);
    }
} catch (\Throwable $e) {
    error($e->getMessage());
    exit(1);
}

// ══════════════════════════════════════════════
// Main flows
// ══════════════════════════════════════════════

function runSync(string $cfgPath, bool $dryRun): void
{
    banner('Card Table - Sync Tool' . ($dryRun ? ' (DRY RUN)' : ''));

    // ─── Phase 0: local preflight ───
    [$gitSha, $gitBranch] = preflightGit();

    // ─── Phase 1: config ───
    $config = loadOrCreateConfig($cfgPath);

    echo "\n";
    info("Target:    {$config['ssh_user']}@{$config['server_ip']}:{$config['ssh_port']}");
    info("App dir:   {$config['app_dir']}");
    info("Git SHA:   {$gitSha} (branch {$gitBranch})");
    info("Dry run:   " . ($dryRun ? 'yes' : 'no'));
    echo "\n";

    if (!confirm('Proceed with sync?', true)) {
        echo "Aborted.\n";
        return;
    }

    // ─── Phase 2: connection + sudo probe ───
    banner('Phase 2: Connection + sudo probe');
    probeConnection($config);

    // ─── Dry run short-circuit ───
    if ($dryRun) {
        banner('Phase 6: rsync (dry run)');
        performRsync($config, __DIR__, true);
        success('Dry run complete. No changes were made.');
        return;
    }

    // ─── Phase 3: sentinel lock ───
    banner('Phase 3: Acquire sync sentinel');
    acquireSentinel($config);

    $snapshotName = null;

    try {
        // ─── Phase 4: snapshot ───
        banner('Phase 4: Snapshot current deployment');
        $snapshotName = takeSnapshot($config, $gitSha);
        success("Snapshot created: {$snapshotName}");

        // ─── Phase 5: diff remote vs local ───
        banner('Phase 5: Detect what needs to run');
        [$needsComposer, $needsMigration, $needsSeed] = detectChanges($config);
        info('composer install needed: ' . ($needsComposer ? 'yes' : 'no'));
        info('migrations changed:      ' . ($needsMigration ? 'yes' : 'no'));
        info('seed phrases changed:    ' . ($needsSeed ? 'yes' : 'no'));

        // ─── Phase 6: rsync ───
        banner('Phase 6: rsync');
        performRsync($config, __DIR__, false);
        sshSudo($config, "chown -R www-data:www-data " . escapeshellarg($config['app_dir']));

        // ─── Phase 7: composer ───
        if ($needsComposer) {
            banner('Phase 7: composer install');
            runComposer($config);
        } else {
            info('Skipping composer install (composer.lock unchanged).');
        }

        // ─── Phase 8: migrations ───
        banner('Phase 8: Migrations');
        if (confirm('Run migrations?', $needsMigration)) {
            runMigrate($config);
        } else {
            info('Skipping migrations at operator request.');
        }

        // ─── Phase 9: seed ───
        if ($needsSeed) {
            banner('Phase 9: Seed default chat phrases');
            if (confirm('Re-run seed_phrases?', true)) {
                runSeed($config);
            } else {
                info('Skipping seed at operator request.');
            }
        }

        // ─── Phase 10: VERSION ───
        banner('Phase 10: Write VERSION file');
        writeVersionFile($config, $gitSha);

        // ─── Phase 11: smoke tests ───
        banner('Phase 11: Smoke tests');
        smokeTest($config);

        // ─── Phase 12: prune snapshots ───
        banner('Phase 12: Prune old snapshots');
        pruneSnapshots($config);
    } catch (\Throwable $e) {
        error('Sync failed: ' . $e->getMessage());

        $rollbackOk = false;
        if ($snapshotName !== null) {
            warn("Attempting rollback to snapshot: {$snapshotName}");
            try {
                rollbackTo($config, $snapshotName);
                $rollbackOk = true;
            } catch (\Throwable $rb) {
                error('ROLLBACK FAILED: ' . $rb->getMessage());
                error('Snapshot still available at: ' . $config['snapshot_dir'] . '/' . $snapshotName);
            }
        } else {
            warn('No snapshot was taken — nothing to roll back to.');
        }

        if ($rollbackOk) {
            try {
                smokeTest($config);
                success('Rollback complete — server is back on the previous release.');
            } catch (\Throwable $sm) {
                error('Post-rollback smoke test failed: ' . $sm->getMessage());
                error('The server is in a broken state. Manual intervention required.');
            }
        } else {
            error('The server is in a broken state. Manual intervention required.');
        }

        releaseSentinel($config);
        throw $e;
    }

    // ─── Phase 13: teardown ───
    releaseSentinel($config);
    banner('Sync Complete');
    success("Deployed {$gitSha} to {$config['server_ip']}");
    info("Snapshot retained: {$snapshotName}");
    info("Site: https://{$config['domain']}/");
}

function runRollback(string $cfgPath): void
{
    banner('Card Table - Rollback Tool');

    $config = loadOrCreateConfig($cfgPath);

    probeConnection($config);

    $snapshots = listSnapshots($config);
    if (count($snapshots) === 0) {
        error('No snapshots found at ' . $config['snapshot_dir']);
        exit(1);
    }

    echo "\nAvailable snapshots (most recent first):\n";
    foreach ($snapshots as $i => $name) {
        printf("  [%d] %s\n", $i + 1, $name);
    }
    echo "\n";

    $choice = (int) prompt('Pick a snapshot number', '1');
    if ($choice < 1 || $choice > count($snapshots)) {
        error('Invalid choice.');
        exit(1);
    }
    $snapshotName = $snapshots[$choice - 1];

    if (!confirm("Roll back to {$snapshotName}? This will overwrite {$config['app_dir']}.", false)) {
        echo "Aborted.\n";
        return;
    }

    acquireSentinel($config);
    try {
        rollbackTo($config, $snapshotName);
        smokeTest($config);
    } catch (\Throwable $e) {
        error('Rollback failed: ' . $e->getMessage());
        releaseSentinel($config);
        throw $e;
    }

    releaseSentinel($config);
    banner('Rollback Complete');
    success("Rolled back to {$snapshotName}");
}

// ══════════════════════════════════════════════
// Phase 0: local git preflight
// ══════════════════════════════════════════════

/**
 * @return array{0:string,1:string} [shortSha, branch]
 */
function preflightGit(): array
{
    $insideRepo = trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --is-inside-work-tree 2>/dev/null'));
    if ($insideRepo !== 'true') {
        throw new RuntimeException('sync.php must run from inside a git checkout of the cardtable repo.');
    }

    $status = (string) shell_exec('git -C ' . escapeshellarg(__DIR__) . ' status --porcelain 2>/dev/null');
    if (trim($status) !== '') {
        error('Working tree is not clean. Commit or stash changes before syncing:');
        echo $status . "\n";
        throw new RuntimeException('Dirty working tree.');
    }

    $sha    = trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --short HEAD 2>/dev/null'));
    $branch = trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --abbrev-ref HEAD 2>/dev/null'));

    if ($sha === '') {
        throw new RuntimeException('Could not read git SHA — is this a git repository?');
    }

    return [$sha, $branch];
}

// ══════════════════════════════════════════════
// Phase 1: config load / create
// ══════════════════════════════════════════════

function loadOrCreateConfig(string $path): array
{
    if (is_file($path)) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Could not read config file: {$path}");
        }
        $cfg = json_decode($raw, true);
        if (!is_array($cfg)) {
            throw new RuntimeException("Config file is not valid JSON: {$path}");
        }
        info("Loaded config from {$path}");
        return normalizeConfig($cfg);
    }

    info("No config at {$path} — let's create one.");
    $cfg = promptConfigFields();

    if (confirm("Save to {$path}?", true)) {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create config directory: {$dir}");
        }
        file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT) . "\n");
        chmod($path, 0600);
        success("Wrote {$path} (mode 600)");
    }

    return normalizeConfig($cfg);
}

function promptConfigFields(): array
{
    $cfg = [];
    $cfg['server_ip']      = prompt('Server IP address');
    $cfg['ssh_port']       = prompt('SSH port', '22');
    $cfg['ssh_user']       = prompt('SSH username', 'deploy');
    $cfg['ssh_key']        = prompt('SSH private key path (leave empty for default)', '', false);
    $cfg['sudo_pass']      = ($cfg['ssh_user'] === 'root')
        ? ''
        : prompt('Sudo password (leave empty for NOPASSWD)', '', false, true);
    $cfg['domain']         = prompt('Site domain (e.g. cardtable.example.com)');
    $cfg['app_dir']        = prompt('App directory', '/var/www/cardtable');
    $cfg['snapshot_dir']   = prompt('Snapshot directory', '/var/www/cardtable-snapshots');
    $cfg['keep_snapshots'] = (int) prompt('Snapshots to retain', '5');
    return $cfg;
}

function normalizeConfig(array $cfg): array
{
    $defaults = [
        'server_ip'      => '',
        'ssh_port'       => '22',
        'ssh_user'       => 'deploy',
        'ssh_key'        => '',
        'sudo_pass'      => '',
        'domain'         => '',
        'app_dir'        => '/var/www/cardtable',
        'snapshot_dir'   => '/var/www/cardtable-snapshots',
        'keep_snapshots' => 5,
    ];
    $cfg = array_merge($defaults, $cfg);
    $cfg['keep_snapshots'] = max(1, (int) $cfg['keep_snapshots']);
    if ($cfg['server_ip'] === '' || $cfg['ssh_user'] === '') {
        throw new RuntimeException('Config is missing server_ip or ssh_user.');
    }
    return $cfg;
}

// ══════════════════════════════════════════════
// Phase 2: connection + sudo probe
// ══════════════════════════════════════════════

function probeConnection(array $config): void
{
    $output = sshExec($config, 'echo SSH_OK', true);
    if (strpos($output, 'SSH_OK') === false) {
        throw new RuntimeException("SSH connection failed:\n" . $output);
    }
    success('SSH connection OK.');

    if (!sshSudoProbe($config)) {
        error("sudo access check failed.");
        error("The SSH user must either be root or have sudo privileges.");
        error("For NOPASSWD sudo, add to /etc/sudoers.d/{$config['ssh_user']}:");
        error("  {$config['ssh_user']} ALL=(ALL) NOPASSWD:ALL");
        throw new RuntimeException('sudo probe failed.');
    }
    success('sudo access confirmed.');

    $check = sshSudo(
        $config,
        'test -d ' . escapeshellarg($config['app_dir']) . ' && echo APP_DIR_OK',
        true
    );
    if (strpos($check, 'APP_DIR_OK') === false) {
        throw new RuntimeException(
            "Target app directory {$config['app_dir']} does not exist on the server. "
            . "Run deploy.php first."
        );
    }
    success("App directory exists: {$config['app_dir']}");

    // Sanity-check that src/config.php is present — without it, the app can't
    // boot and our smoke test will fail in a confusing way.
    $cfgCheck = sshSudo(
        $config,
        'test -f ' . escapeshellarg(rtrim($config['app_dir'], '/') . '/src/config.php') . ' && echo CONFIG_OK',
        true
    );
    if (strpos($cfgCheck, 'CONFIG_OK') === false) {
        throw new RuntimeException(
            "src/config.php is missing on the server. Re-run deploy.php first."
        );
    }
    success('src/config.php present.');
}

// ══════════════════════════════════════════════
// Phase 3: sentinel lock
// ══════════════════════════════════════════════

function acquireSentinel(array $config): void
{
    $sentinel = SYNC_SENTINEL_PATH;

    $check = sshSudo(
        $config,
        'test -f ' . escapeshellarg($sentinel) . ' && cat ' . escapeshellarg($sentinel) . ' || echo NO_LOCK',
        true
    );

    if (strpos($check, 'NO_LOCK') === false) {
        error("Remote sentinel already exists at {$sentinel}:");
        echo $check . "\n";
        error('Another sync may be running, or a previous one crashed.');
        error("If you are sure no sync is in progress, remove it with:");
        error("  ssh {$config['ssh_user']}@{$config['server_ip']} sudo rm {$sentinel}");
        throw new RuntimeException('Sync sentinel already present.');
    }

    $content = "pid=" . getmypid() . "\n"
             . "host=" . gethostname() . "\n"
             . "start=" . date('c') . "\n";

    sshWriteFile($config, $sentinel, $content, 'root', 'root', '644');
    success("Acquired sentinel {$sentinel}");
}

function releaseSentinel(array $config): void
{
    sshSudo($config, 'rm -f ' . escapeshellarg(SYNC_SENTINEL_PATH), true);
}

// ══════════════════════════════════════════════
// Phase 4: snapshot
// ══════════════════════════════════════════════

function takeSnapshot(array $config, string $gitSha): string
{
    $name = date('Ymd-His') . '-' . $gitSha;
    $dest = rtrim($config['snapshot_dir'], '/') . '/' . $name;

    sshSudo($config, 'mkdir -p ' . escapeshellarg($config['snapshot_dir']));
    // cp -al = hardlink tree, near-instant and disk-cheap.
    sshSudo(
        $config,
        'cp -al ' . escapeshellarg($config['app_dir']) . ' ' . escapeshellarg($dest)
    );

    $check = sshSudo(
        $config,
        'test -d ' . escapeshellarg($dest) . ' && echo SNAP_OK',
        true
    );
    if (strpos($check, 'SNAP_OK') === false) {
        throw new RuntimeException("Snapshot directory was not created: {$dest}");
    }
    return $name;
}

// ══════════════════════════════════════════════
// Phase 5: diff remote vs local
// ══════════════════════════════════════════════

/**
 * @return array{0:bool,1:bool,2:bool} [needsComposer, needsMigration, needsSeed]
 */
function detectChanges(array $config): array
{
    return [
        remoteFileDiffers($config, __DIR__ . '/composer.lock', '/composer.lock'),
        anyMigrationDiffers($config),
        remoteFileDiffers($config, __DIR__ . '/scripts/seed_phrases.php', '/scripts/seed_phrases.php'),
    ];
}

function anyMigrationDiffers(array $config): bool
{
    $localDir = __DIR__ . '/src/migrations';
    if (!is_dir($localDir)) {
        return false;
    }
    $files = glob($localDir . '/*.sql') ?: [];
    foreach ($files as $f) {
        $rel = '/src/migrations/' . basename($f);
        if (remoteFileDiffers($config, $f, $rel)) {
            return true;
        }
    }
    return false;
}

function remoteFileDiffers(array $config, string $localPath, string $remoteRel): bool
{
    if (!is_file($localPath)) {
        // If the local file is missing, we can't compare — treat as "changed"
        // so the caller prompts and the operator can decide.
        return true;
    }

    $remoteAbs = rtrim($config['app_dir'], '/') . $remoteRel;

    $out = sshSudo(
        $config,
        'sha1sum ' . escapeshellarg($remoteAbs) . ' 2>/dev/null || echo MISSING',
        true
    );
    if (strpos($out, 'MISSING') !== false) {
        return true;
    }

    $parts = preg_split('/\s+/', trim($out));
    $remoteSha = $parts[0] ?? '';
    $localSha  = sha1_file($localPath);

    return $remoteSha !== $localSha;
}

// ══════════════════════════════════════════════
// Phase 6: rsync
// ══════════════════════════════════════════════

function performRsync(array $config, string $projectDir, bool $dryRun): void
{
    $excludes = [
        '.git',
        'vendor',
        'node_modules',
        '*.log',
        '.env',
        // Production secrets — never sync these from local.
        'src/config.php',
        'src/admins.php',
        // Runtime user data.
        'public/assets/uploads',
        // Repo metadata.
        'card-platform-spec.md',
        'README.md',
        'examples',
        // Tooling — these live alongside the app source but are dev-side only.
        'deploy.php',
        'sync.php',
        'deploy-lib.php',
        '/VERSION',
    ];
    $extraFlags = ['--delete'];
    if ($dryRun) {
        $extraFlags[] = '--dry-run';
    }

    // Before rsync, hand the deploy user write access to app_dir so rsync can
    // land files. We chown back to www-data after the rsync completes.
    sshSudo(
        $config,
        'chown -R ' . escapeshellarg($config['ssh_user']) . ':' . escapeshellarg($config['ssh_user'])
        . ' ' . escapeshellarg($config['app_dir'])
    );

    $ok = rsyncUpload($config, $projectDir, $config['app_dir'], $excludes, $extraFlags);
    if (!$ok) {
        throw new RuntimeException('rsync failed.');
    }
    success($dryRun ? 'rsync dry run complete.' : 'rsync complete.');
}

// ══════════════════════════════════════════════
// Phase 7–9: app commands as www-data / root
// ══════════════════════════════════════════════

function runComposer(array $config): void
{
    $cmd = 'cd ' . escapeshellarg($config['app_dir'])
        . ' && HOME=/tmp/cardtable composer install --no-dev --optimize-autoloader';
    $code = sshSudoAsStream($config, 'www-data', $cmd);
    if ($code !== 0) {
        throw new RuntimeException("composer install exited with code {$code}");
    }
    success('composer install complete.');
}

function runMigrate(array $config): void
{
    // The application user only has DML privileges. Temporarily grant DDL,
    // run the migrations, then revoke. This matches the bootstrap path used
    // by deploy.php so the runtime grant set stays minimal.
    $dbName = readDbNameFromConfig($config);
    $dbUser = readDbUserFromConfig($config);

    $grantSql = "GRANT CREATE, ALTER, INDEX, REFERENCES ON `{$dbName}`.* TO '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;";
    sshSudo($config, "mysql -e " . escapeshellarg($grantSql));

    try {
        $cmd = 'cd ' . escapeshellarg($config['app_dir']) . ' && HOME=/tmp/cardtable php scripts/migrate.php';
        $code = sshSudoAsStream($config, 'www-data', $cmd);
        if ($code !== 0) {
            throw new RuntimeException("migrate.php exited with code {$code}");
        }
    } finally {
        $revokeSql = "REVOKE CREATE, ALTER, INDEX, REFERENCES ON `{$dbName}`.* FROM '{$dbUser}'@'localhost'; FLUSH PRIVILEGES;";
        sshSudo($config, "mysql -e " . escapeshellarg($revokeSql));
    }

    success('Migrations complete.');
}

function runSeed(array $config): void
{
    $cmd = 'cd ' . escapeshellarg($config['app_dir']) . ' && HOME=/tmp/cardtable php scripts/seed_phrases.php';
    $code = sshSudoAsStream($config, 'www-data', $cmd);
    if ($code !== 0) {
        throw new RuntimeException("seed_phrases.php exited with code {$code}");
    }
    success('Seed complete.');
}

// ══════════════════════════════════════════════
// Phase 10: VERSION file
// ══════════════════════════════════════════════

function writeVersionFile(array $config, string $gitSha): void
{
    $path = rtrim($config['app_dir'], '/') . '/VERSION';
    sshWriteFile($config, $path, $gitSha . "\n", 'www-data', 'www-data', '644');
}

// ══════════════════════════════════════════════
// Phase 11: smoke tests
// ══════════════════════════════════════════════

function smokeTest(array $config): void
{
    // 1. DB SELECT 1 via the app's own config — proves both that config.php
    // parses and that the credentials it contains can connect.
    $phpProbe = 'cd ' . escapeshellarg($config['app_dir']) . ' && php -r '
        . escapeshellarg(
            '$c = (require "src/config.php")["db"];'
            . ' $pdo = new PDO("mysql:host=".$c["host"].";port=".$c["port"].";dbname=".$c["database"], $c["username"], $c["password"]);'
            . ' echo $pdo->query("SELECT 1")->fetchColumn();'
        );
    $out = trim(sshSudoAs($config, 'www-data', $phpProbe, true));
    if ($out !== '1') {
        throw new RuntimeException("DB probe via src/config.php failed: " . $out);
    }
    success('DB connection OK.');

    // 2. Apache is serving requests on the public domain. We accept any
    // 2xx/3xx/4xx — we just want to confirm Apache + PHP are alive. A 5xx
    // means PHP errored on the request, which is a real failure.
    $domain = $config['domain'] ?? '';
    if ($domain === '') {
        throw new RuntimeException('Config is missing "domain" — required for HTTP smoke test.');
    }
    $url = "https://{$domain}/";
    $code = trim(sshExec(
        $config,
        "curl -sk -o /dev/null -w '%{http_code}' " . escapeshellarg($url),
        true
    ));
    if ($code === '' || $code[0] === '5') {
        throw new RuntimeException("HTTP smoke test failed for {$url}: status {$code}");
    }
    success("HTTP smoke test OK ({$url} → {$code}).");
}

// ══════════════════════════════════════════════
// Phase 12: prune snapshots
// ══════════════════════════════════════════════

function pruneSnapshots(array $config): void
{
    $keep = max(1, (int) $config['keep_snapshots']);
    $snapDir = escapeshellarg(rtrim($config['snapshot_dir'], '/'));

    // Timestamped names sort lexicographically == chronologically.
    $cmd = "ls -1 {$snapDir} 2>/dev/null | sort | head -n -{$keep} "
         . "| xargs -r -I{} rm -rf {$snapDir}/{}";
    sshSudo($config, $cmd);

    $remaining = trim(sshSudo($config, "ls -1 {$snapDir} 2>/dev/null | wc -l", true));
    info("Snapshots retained: {$remaining} (target: {$keep})");
}

// ══════════════════════════════════════════════
// Rollback
// ══════════════════════════════════════════════

/**
 * @return string[] Most recent first.
 */
function listSnapshots(array $config): array
{
    $out = sshSudo(
        $config,
        'ls -1 ' . escapeshellarg(rtrim($config['snapshot_dir'], '/')) . ' 2>/dev/null || true',
        true
    );
    $names = array_filter(array_map('trim', explode("\n", $out)), fn($x) => $x !== '');
    rsort($names);
    return array_values($names);
}

function rollbackTo(array $config, string $snapshotName): void
{
    $snapPath = rtrim($config['snapshot_dir'], '/') . '/' . $snapshotName;
    $appDir   = rtrim($config['app_dir'], '/');

    $check = sshSudo(
        $config,
        'test -d ' . escapeshellarg($snapPath) . ' && echo SNAP_OK',
        true
    );
    if (strpos($check, 'SNAP_OK') === false) {
        throw new RuntimeException("Snapshot not found: {$snapPath}");
    }

    info("Rolling back {$appDir} from {$snapPath}...");
    sshSudo(
        $config,
        'rsync -a --delete ' . escapeshellarg($snapPath . '/') . ' ' . escapeshellarg($appDir . '/')
    );
    sshSudo($config, 'chown -R www-data:www-data ' . escapeshellarg($appDir));
    success("Rolled back to {$snapshotName}");
}

// ══════════════════════════════════════════════
// Utilities
// ══════════════════════════════════════════════

function readDbNameFromConfig(array $config): string
{
    return readConfigValue($config, 'database');
}

function readDbUserFromConfig(array $config): string
{
    return readConfigValue($config, 'username');
}

function readConfigValue(array $config, string $key): string
{
    // Read $key from src/config.php['db'] as www-data so we can see the file
    // (mode 640, owned by www-data:www-data).
    $php = 'echo (require ' . escapeshellarg(rtrim($config['app_dir'], '/') . '/src/config.php') . ')["db"]['
         . escapeshellarg($key) . '];';
    $out = trim(sshSudoAs($config, 'www-data', 'php -r ' . escapeshellarg($php), true));
    if ($out === '') {
        throw new RuntimeException("Could not read db.{$key} from remote src/config.php.");
    }
    return $out;
}

function expandHome(string $path): string
{
    if (str_starts_with($path, '~/')) {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
        if ($home === '') {
            throw new RuntimeException('Could not determine $HOME to expand ~.');
        }
        return rtrim($home, '/') . '/' . substr($path, 2);
    }
    return $path;
}
