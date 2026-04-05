<?php
/**
 * Run database migrations.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/db.php';

echo "Running migrations...\n";

$db = DB::get();
$migrationsDir = __DIR__ . '/../src/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    echo "  Applying {$name}... ";
    $sql = file_get_contents($file);
    // Split on semicolons that end a statement (not inside quotes)
    $statements = array_filter(
        array_map('trim', preg_split('/;\s*$/m', $sql)),
        fn($s) => $s !== ''
    );
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
    echo "OK\n";
}

echo "All migrations applied.\n";
