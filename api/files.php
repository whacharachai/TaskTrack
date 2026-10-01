<?php
// files.php - serve task/chat attachments (download or thumbnail)
require_once __DIR__ . '/auth.php';
need_login();
$p = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Method not allowed', 405);

if (isset($_GET['download']) || isset($_GET['thumb'])) {
    $st = $p->prepare('SELECT * FROM uploads WHERE id = ?');
    $st->execute([(int)($_GET['download'] ?? $_GET['thumb'])]);
    $f = $st->fetch();
    if (!$f) fail('File not found', 404);
    need_see_file((int)$f['id']);
    $isThumb = isset($_GET['thumb']);
    if ($isThumb) {
        if (!$f['thumb']) fail('No thumbnail', 404);
        $path = thumb_path($f['thumb']);
    } else $path = UPLOAD_DIR . $f['filename'];
    if (!is_file($path)) fail('File missing on disk', 404);
    if ($isThumb) {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400'); // per-user data, but not re-fetched on every poll
    } else {
        $path = UPLOAD_DIR . $f['filename'];
        header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string)($f['orig_name'] ?: $f['filename'])) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
    }
    readfile($path);
    exit;
}

$taskId = (int)($_GET['task_id'] ?? 0);
if (!$taskId) fail('task_id required');
need_task($taskId);
$rows = $p->query('SELECT * FROM uploads WHERE task_id = ' . $taskId . ' AND received = total_size ORDER BY id')->fetchAll();
json_out(array_map('file_row', $rows));