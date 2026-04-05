<?php
/**
 * MySQL-backed session handler.
 */
class DbSessionHandler implements SessionHandlerInterface {
    private PDO $db;
    private int $ttl;

    public function __construct(PDO $db, int $ttl = 86400) {
        $this->db = $db;
        $this->ttl = $ttl;
    }

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        $stmt = $this->db->prepare(
            'SELECT data FROM sessions WHERE id = ? AND last_activity > ?'
        );
        $stmt->execute([$id, time() - $this->ttl]);
        $row = $stmt->fetch();
        return $row ? $row['data'] : '';
    }

    public function write(string $id, string $data): bool {
        $stmt = $this->db->prepare(
            'REPLACE INTO sessions (id, user_id, data, last_activity) VALUES (?, ?, ?, ?)'
        );
        $userId = $_SESSION['user_id'] ?? null;
        return $stmt->execute([$id, $userId, $data, time()]);
    }

    public function destroy(string $id): bool {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE last_activity < ?');
        $stmt->execute([time() - $this->ttl]);
        return $stmt->rowCount();
    }
}

function initSession(): void {
    $config = getConfig();
    $db = DB::get();
    $handler = new DbSessionHandler($db, $config['app']['session_ttl']);
    session_set_save_handler($handler, true);

    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly'  => true,
        'samesite' => 'Strict',
    ]);
    session_name('cardtable_session');
    session_start();
}
