<?php
// db.php - connection, schema, helpers, rollup math
const DB_FILE = __DIR__ . '/../data/tasktrack.db';
const UPLOAD_DIR = __DIR__ . '/../uploads/';
const CHUNK_DIR = __DIR__ . '/../data/chunks/';
const UPLOAD_MAX = 100 * 1024 * 1024;   // 100 MB per file
const THUMB_MAX = 320;                  // longest edge of a generated photo thumbnail
const OK_EXT = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,odt,png,jpg,jpeg,gif,webp,zip,rar,7z,dwg,dxf,kml,kmz,mp4,mov,heic';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        @mkdir(dirname(DB_FILE), 0775, true);
        @mkdir(CHUNK_DIR, 0775, true);
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        migrate($pdo);
    }
    return $pdo;
}

function columns(PDO $p, string $table): array {
    $out = [];
    foreach ($p->query("PRAGMA table_info($table)") as $c) $out[] = $c['name'];
    return $out;
}

function add_column(PDO $p, string $table, string $col, string $def): void {
    if (!in_array($col, columns($p, $table), true)) $p->exec("ALTER TABLE $table ADD COLUMN $col $def");
}

function migrate(PDO $p): void {
    $p->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "user", created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $p->exec('CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, remark TEXT, status TEXT NOT NULL DEFAULT "active",
        created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    // a project may name extra users besides the roles above
    $p->exec('CREATE TABLE IF NOT EXISTS project_access (
        project_id INTEGER REFERENCES projects(id), user_id INTEGER REFERENCES users(id),
        PRIMARY KEY (project_id, user_id))');
    $p->exec('CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, remark TEXT,
        start_date TEXT, end_date TEXT, total_amount REAL DEFAULT 0, unit TEXT DEFAULT "",
        parent_id INTEGER REFERENCES tasks(id), roles TEXT NOT NULL DEFAULT "user",
        status TEXT NOT NULL DEFAULT "active", created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    // ponytail: access is configured on the project; tasks inherit it. roles/task_access kept only for pre-project rows.
    add_column($p, 'tasks', 'project_id', 'INTEGER REFERENCES projects(id)');
    $p->exec('CREATE TABLE IF NOT EXISTS task_access (
        task_id INTEGER REFERENCES tasks(id), user_id INTEGER REFERENCES users(id),
        PRIMARY KEY (task_id, user_id))');
    // visibility is by named user now; fold any pre-existing role grants into explicit rows, then drop
// the column so nothing can silently re-grant access by role afterwards
    $grant = $p->prepare('INSERT OR IGNORE INTO project_access (project_id, user_id) VALUES (?,?)');
    if (in_array('roles', columns($p, 'projects'), true)) {
        foreach ($p->query("SELECT id, roles FROM projects WHERE roles IS NOT NULL AND roles <> ''") as $pr)
            foreach (array_filter(explode(',', (string)$pr['roles'])) as $role)
                foreach ($p->query('SELECT id FROM users WHERE role = ' . $p->quote($role)) as $u) $grant->execute([(int)$pr['id'], (int)$u['id']]);
        $p->exec('ALTER TABLE projects DROP COLUMN roles');
    }
    $p->exec('CREATE TABLE IF NOT EXISTS progress (
        id INTEGER PRIMARY KEY, task_id INTEGER NOT NULL REFERENCES tasks(id),
        done_amount REAL NOT NULL, done_date TEXT NOT NULL, remark TEXT,
        user_id INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $p->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL REFERENCES projects(id),
        user_id INTEGER, body TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $p->exec('CREATE TABLE IF NOT EXISTS uploads (
        id INTEGER PRIMARY KEY, project_id INTEGER REFERENCES projects(id), task_id INTEGER REFERENCES tasks(id),
        message_id INTEGER REFERENCES messages(id), filename TEXT NOT NULL, orig_name TEXT NOT NULL DEFAULT "",
        mime TEXT, size INTEGER DEFAULT 0, thumb TEXT, received INTEGER DEFAULT 0, total_size INTEGER DEFAULT 0,
        uploaded_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $p->exec('CREATE INDEX IF NOT EXISTS idx_progress_task ON progress(task_id, done_date)');
    $p->exec('CREATE INDEX IF NOT EXISTS idx_tasks_parent ON tasks(parent_id)');
    $p->exec('CREATE INDEX IF NOT EXISTS idx_tasks_project ON tasks(project_id)');
    $p->exec('CREATE INDEX IF NOT EXISTS idx_messages_project ON messages(project_id, id)');
    $p->exec('CREATE INDEX IF NOT EXISTS idx_uploads_message ON uploads(message_id)');
    $p->exec('CREATE INDEX IF NOT EXISTS idx_uploads_task ON uploads(task_id)');
    // drop the old attachment table once its rows are gone from uploads
    if (columns($p, 'attachments') && !(int)$p->query('SELECT COUNT(*) FROM attachments')->fetchColumn() && !(int)$p->query('SELECT COUNT(*) FROM uploads')->fetchColumn())
        $p->exec('DROP TABLE attachments');
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
// a worker reports progress and edits tasks; a plain user only looks
function can_write(): bool { return in_array($_SESSION['role'] ?? 'user', ['admin', 'worker'], true); }
function need_write(): void { if (!can_write()) fail('Not allowed', 403); }
function current_user(): array {
    return ['id' => uid(), 'username' => $_SESSION['username'] ?? '', 'role' => $_SESSION['role'] ?? 'user',
        'admin' => is_admin(), 'can_write' => can_write()];
}

/* ---------- access ---------- */
// visibility is per named user; admins bypass it
function project_visible_sql(string $alias = 'p'): string {
    if (is_admin()) return '1=1';
    return "EXISTS (SELECT 1 FROM project_access a WHERE a.project_id = {$alias}.id AND a.user_id = " . uid() . ')';
}

function can_see_project(int $id): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM projects p WHERE p.id = ? AND ' . project_visible_sql());
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

function need_project(int $id): void {
    if (!can_see_project($id)) fail('Not allowed', 403);
}

// tasks inherit their project's access; project_id NULL rows fall back to the legacy per-task rules
function task_visible_sql(string $alias = 't'): string {
    if (is_admin()) return '1=1';
    $role = addslashes($_SESSION['role'] ?? 'user');
    return "({$alias}.project_id IN (SELECT p.id FROM projects p WHERE " . project_visible_sql('p') . ")
         OR ({$alias}.project_id IS NULL AND (instr(','||{$alias}.roles||',', ','||'{$role}'||',') > 0
             OR EXISTS (SELECT 1 FROM task_access a WHERE a.task_id = {$alias}.id AND a.user_id = " . uid() . '))))';
}

function can_see_task(int $taskId): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM tasks t WHERE t.id = ? AND ' . task_visible_sql());
    $st->execute([$taskId]);
    return (bool)$st->fetchColumn();
}

function need_task(int $taskId): void {
    if (!can_see_task($taskId)) fail('Not allowed', 403);
}

function need_see_file(int $id): array {
    $st = db()->prepare('SELECT * FROM uploads WHERE id = ?');
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f) fail('File not found', 404);
    if ($f['task_id'] !== null) need_task((int)$f['task_id']);
    elseif ($f['message_id'] !== null) {
        $m = db()->query('SELECT project_id FROM messages WHERE id = ' . (int)$f['message_id'])->fetch();
        if (!$m) fail('File not found', 404);
        need_project((int)$m['project_id']);
    } elseif ($f['project_id'] !== null) need_project((int)$f['project_id']);
    else fail('File has no owner', 403); // orphaned by a deleted project: reachable by nobody
    return $f;
}

// Rollup: done = own entries summed + every descendant's rolled-up done.
function rollup(int $id, array $byId, array $own): array {
    $byParent = [];
    foreach ($byId as $t) if ($t['parent_id'] !== null) $byParent[(int)$t['parent_id']][] = (int)$t['id'];
    $memo = [];
    $walk = function (int $n) use (&$walk, &$memo, $byId, $own, $byParent): float {
        if (array_key_exists($n, $memo)) return $memo[$n];
        $memo[$n] = 0.0; // ponytail: cycle guard, self/descendant parent is rejected on save
        $sum = (float)($own[$n] ?? 0);
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

/* ---------- files ---------- */
function file_row(array $f): array {
    return [
        'id' => (int)$f['id'],
        'name' => $f['orig_name'] ?: $f['filename'],
        'mime' => $f['mime'],
        'size' => (int)$f['size'],
        'thumb' => $f['thumb'] ? 'api/files.php?thumb=' . (int)$f['id'] : null,
        'url' => 'api/files.php?download=' . (int)$f['id'],
        'created_at' => $f['created_at'],
    ];
}

function thumb_path(string $stored): string { return UPLOAD_DIR . 'thumb_' . $stored; }

// Longest-edge thumbnail; returns the stored thumb filename or null when the type is not an image.
function make_thumb(string $path, string $mime): ?string {
    if (!function_exists('imagecreatetruecolor')) return null;
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
        default => null,
    };
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, THUMB_MAX / max($w, $h));
    $tw = (int)max(1, $w * $scale);
    $th = (int)max(1, $h * $scale);
    $dst = imagecreatetruecolor($tw, $th);
    if (in_array($mime, ['image/png', 'image/webp'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    $name = bin2hex(random_bytes(8)) . '.jpg';
    $ok = imagejpeg($dst, thumb_path($name), 78); // always jpeg: one format for the browser to cache
    imagedestroy($src);
    imagedestroy($dst);
    return $ok ? $name : null;
}

// Drop orphaned chunks and their rows; called opportunistically from upload.php.
function sweep_chunks(): void {
    $p = db();
    // created_at is CURRENT_TIMESTAMP text, so compare as datetime and not against a unix int
    foreach ($p->query("SELECT id, filename FROM uploads WHERE received < total_size
                       AND created_at < datetime('now','-1 day') AND message_id IS NULL AND task_id IS NULL") as $r) {
        @unlink(CHUNK_DIR . $r['filename']);
        $p->prepare('DELETE FROM uploads WHERE id = ?')->execute([$r['id']]);
    }
}