<?php
// db.php - connection, schema, helpers, rollup math
const DB_FILE = __DIR__ . '/../data/tasktrack.db';
const UPLOAD_DIR = __DIR__ . '/../uploads/';
const UPLOAD_MAX = 10 * 1024 * 1024;

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        @mkdir(dirname(DB_FILE), 0775, true);
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        migrate($pdo);
    }
    return $pdo;
}

function migrate(PDO $p): void {
    $p->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "user", created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $p->exec('CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, remark TEXT,
        start_date TEXT, end_date TEXT, total_amount REAL DEFAULT 0, unit TEXT DEFAULT "",
        parent_id INTEGER REFERENCES tasks(id), roles TEXT NOT NULL DEFAULT "user",
        status TEXT NOT NULL DEFAULT "active", created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    // a task may name extra users besides the roles above
    $p->exec('CREATE TABLE IF NOT EXISTS task_access (
        task_id INTEGER REFERENCES tasks(id), user_id INTEGER REFERENCES users(id),
        PRIMARY KEY (task_id, user_id))');
    $p->exec('CREATE TABLE IF NOT EXISTS progress (
        id INTEGER PRIMARY KEY, task_id INTEGER NOT NULL REFERENCES tasks(id),
        done_amount REAL NOT NULL, done_date TEXT NOT NULL, remark TEXT,
        user_id INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $p->exec('CREATE TABLE IF NOT EXISTS attachments (
        id INTEGER PRIMARY KEY, task_id INTEGER NOT NULL REFERENCES tasks(id),
        filename TEXT NOT NULL, orig_name TEXT NOT NULL DEFAULT "", mime TEXT, size INTEGER DEFAULT 0,
        uploaded_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $p->exec('CREATE INDEX IF NOT EXISTS idx_progress_task ON progress(task_id, done_date)');
    $p->exec('CREATE INDEX IF NOT EXISTS idx_tasks_parent ON tasks(parent_id)');
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function fail(string $msg, int $code = 400): void { json_out(['error' => $msg], $code); }

function body(): array {
    $j = json_decode(file_get_contents('php://input'), true);
    return is_array($j) ? $j : $_POST;
}

function today(): string { return date('Y-m-d'); }

function valid_date(?string $d): bool {
    if (!$d) return true;
    $p = explode('-', $d);
    return count($p) === 3 && $p[0] && $p[1] && $p[2] && checkdate((int)$p[1], (int)$p[2], (int)$p[0]);
}

function uid(): int { return (int)($_SESSION['uid'] ?? 0); }
function is_admin(): bool { return ($_SESSION['role'] ?? '') === 'admin'; }
function current_user(): array {
    return ['id' => uid(), 'username' => $_SESSION['username'] ?? '', 'role' => $_SESSION['role'] ?? 'user'];
}

// a task is visible to admin always; to a user when their role is listed or they are named
function visible_sql(string $alias = 't'): string {
    if (is_admin()) return '1=1';
    $role = addslashes($_SESSION['role'] ?? 'user');
    return "(instr(','||{$alias}.roles||',', ','||'{$role}'||',') > 0
         OR EXISTS (SELECT 1 FROM task_access a WHERE a.task_id = {$alias}.id AND a.user_id = " . uid() . '))';
}

function can_see_task(int $taskId): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM tasks t WHERE t.id = ? AND ' . visible_sql());
    $st->execute([$taskId]);
    return (bool)$st->fetchColumn();
}

function need_task(int $taskId): void {
    if (!can_see_task($taskId)) fail('Not allowed', 403);
}

// Rollup: done = own latest cumulative amount + every descendant's rolled-up done.
function rollup(int $id, array $byId, array $latest): array {
    $byParent = [];
    foreach ($byId as $t) if ($t['parent_id'] !== null) $byParent[(int)$t['parent_id']][] = (int)$t['id'];
    $memo = [];
    $walk = function (int $n) use (&$walk, &$memo, $byId, $latest, $byParent): float {
        if (array_key_exists($n, $memo)) return $memo[$n];
        $memo[$n] = 0.0; // ponytail: cycle guard, self/descendant parent is rejected on save
        $sum = (float)($latest[$n] ?? 0);
        foreach ($byParent[$n] ?? [] as $c) $sum += $walk($c);
        return $memo[$n] = $sum;
    };
    $done = $walk($id);
    $total = (float)($byId[$id]['total_amount'] ?? 0);
    return [
        'done_amount' => round($done, 3),
        'percent' => $total > 0 ? min(100.0, round($done / $total * 100, 1)) : 0.0,
    ];
}