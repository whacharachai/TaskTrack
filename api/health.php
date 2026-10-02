<?php
// health.php - no auth, reports why the API might be failing on this host.
// Safe to keep: it exposes versions, paths and writability, never data or secrets.
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
$out = [
    'php' => PHP_VERSION,
    'sqlite_lib' => extension_loaded('pdo_sqlite') ? (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn() : null,
    'sqlite_compiled_out' => in_array('sqlite3', get_loaded_extensions(), true) || extension_loaded('pdo_sqlite'),
    'pdo_drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
    'gd' => extension_loaded('gd'),
    'mbstring' => extension_loaded('mbstring'),
    'json' => function_exists('json_encode'),
    'data_dir' => ['path' => dirname(DB_FILE), 'exists' => is_dir(dirname(DB_FILE)), 'writable' => is_writable(dirname(DB_FILE))],
    'chunk_dir' => ['path' => CHUNK_DIR, 'exists' => is_dir(CHUNK_DIR), 'writable' => is_writable(CHUNK_DIR)],
    'upload_dir' => ['path' => UPLOAD_DIR, 'exists' => is_dir(UPLOAD_DIR), 'writable' => is_writable(UPLOAD_DIR)],
];

// the real test: does connecting and migrating actually work here?
try {
    $p = db();
    $out['db'] = [
        'ok' => true,
        'file' => DB_FILE,
        'sqlite' => $p->query('SELECT sqlite_version()')->fetchColumn(),
        'users' => (int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'projects' => (int)$p->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
        'projects_roles_column' => in_array('roles', columns($p, 'projects'), true),
        'journal_mode' => (string)$p->query('PRAGMA journal_mode')->fetchColumn(),
    ];
} catch (Throwable $e) {
    $out['db'] = ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);