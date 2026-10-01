<?php
// auth.php - session (remembered 2 days) + login/logout/whoami
// ponytail: 2-day memory = cookie lifetime + gc_maxlifetime. No remember-token table.

const SESSION_DAYS = 2;

function start_session(): void {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)(SESSION_DAYS * 86400));
    session_set_cookie_params([
        'lifetime' => SESSION_DAYS * 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('tasktrack');
    session_start();
}

function need_login(): void {
    if (!uid()) fail('Not logged in', 401);
}

function need_admin(): void {
    need_login();
    if (!is_admin()) fail('Admin only', 403);
}

start_session();

if (PHP_SAPI === 'cli') {
    // installer: php api/auth.php <username> <password> [role]
    require_once __DIR__ . '/db.php';
    $u = $argv[1] ?? null;
    $p = $argv[2] ?? null;
    $r = $argv[3] ?? 'admin';
    if (!$u || !$p) exit("usage: php api/auth.php <username> <password> [admin|user]\n");
    if (!in_array($r, ['admin', 'user'], true)) exit("role must be admin or user\n");
    db()->prepare('INSERT OR REPLACE INTO users (username, password_hash, role) VALUES (?,?,?)')
        ->execute([$u, password_hash($p, PASSWORD_DEFAULT), $r]);
    echo "user '$u' ($r) ready\n";
    exit;
}

require_once __DIR__ . '/db.php';

// only route when this file is the script the web server was asked for
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) return;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = body();
    $u = trim((string)($b['username'] ?? ''));
    $p = (string)($b['password'] ?? '');
    if ($u === '' || $p === '') fail('username and password required');
    $st = db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
    $st->execute([$u]);
    $user = $st->fetch();
    if (!$user || !password_verify($p, $user['password_hash'])) fail('Invalid credentials', 401);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['expires'] = time() + SESSION_DAYS * 86400;
    json_out(['ok' => true, 'user' => current_user(), 'expires_in' => $_SESSION['expires']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
}

need_login();
json_out(['ok' => true, 'user' => current_user(), 'expires_in' => $_SESSION['expires'] ?? 0]);