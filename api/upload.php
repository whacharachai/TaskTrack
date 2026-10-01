<?php
// upload.php - chunked resumable upload, one path for task docs and chat photos
ini_set('upload_max_filesize', '12M');
ini_set('post_max_size', '16M');
ini_set('max_execution_time', '600');
require_once __DIR__ . '/auth.php';
need_login();
$p = db();
$m = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'sweep') { // opportunistic cleanup, cheap enough to call on start
    sweep_chunks();
    json_out(['ok' => true]);
}

// resolves the scope a chunk/finish belongs to and checks the caller may use it
function scope(array $b): array {
    $p = db();
    $taskId = ($b['task_id'] ?? '') === '' ? null : (int)$b['task_id'];
    $msgId = ($b['message_id'] ?? '') === '' ? null : (int)$b['message_id'];
    $projId = ($b['project_id'] ?? '') === '' ? null : (int)$b['project_id'];
    if ($taskId !== null) {
        need_task($taskId);
        $projId = $projId ?? (int)($p->query('SELECT project_id FROM tasks WHERE id = ' . $taskId)->fetchColumn() ?: 0) ?: null;
    } elseif ($msgId !== null) {
        $row = $p->query('SELECT project_id FROM messages WHERE id = ' . $msgId)->fetch();
        if (!$row) fail('Message not found', 404);
        $projId = (int)$row['project_id'];
    } elseif ($projId !== null) need_project($projId);
    else fail('task_id, message_id or project_id required');
    return [$taskId, $msgId, $projId];
}

function own_upload(int $id): array {
    $st = db()->prepare('SELECT * FROM uploads WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) fail('Upload not found', 404);
    if ((int)$u['uploaded_by'] !== uid() && !is_admin()) fail('Not allowed', 403);
    return $u;
}

if ($m === 'POST' && $action === 'start') {
    $b = body();
    [$taskId, $msgId, $projId] = scope($b);
    $name = trim((string)($b['name'] ?? 'file'));
    $total = (int)($b['total_size'] ?? 0);
    if ($total <= 0) fail('total_size required');
    if ($total > UPLOAD_MAX) fail('File exceeds the ' . (UPLOAD_MAX / 1048576) . ' MB limit', 413);
    // check the extension up front so a bad type is not streamed for 100 MB first
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, explode(',', OK_EXT), true)) fail('File type .' . $ext . ' not allowed');
    $stored = bin2hex(random_bytes(12)) . '.part';
    $p->prepare('INSERT INTO uploads (project_id,task_id,message_id,filename,orig_name,received,total_size,uploaded_by)
                 VALUES (?,?,?,?,?,0,?,?)')->execute([$projId, $taskId, $msgId, $stored, $name, $total, uid()]);
    json_out(['upload_id' => (int)$p->lastInsertId(), 'received' => 0, 'total_size' => $total]);
}

if ($m === 'POST' && $action === 'chunk') {
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $u = own_upload($id);
    $received = (int)$u['received'];
    $offset = (int)($_SERVER['HTTP_X_CHUNK_OFFSET'] ?? $_POST['offset'] ?? -1);
    if ($offset < 0 || $offset > UPLOAD_MAX) fail('Bad chunk offset');
    $data = file_get_contents('php://input');
    if ($data === false || $data === '') fail('Empty chunk');
    $path = CHUNK_DIR . $u['filename'];
    $onDisk = is_file($path) ? (int)filesize($path) : 0;
    // the file on disk is the truth; the row is only the client's view of it
    if ($onDisk !== $received) $received = $onDisk;
    if ($offset !== $received) json_out(['received' => $received, 'retry' => true]); // client resumed from the wrong byte
    $fh = fopen($path, 'ab');
    if (!$fh) fail('Cannot write chunk', 500);
    flock($fh, LOCK_EX);
    fwrite($fh, $data);
    fflush($fh);
    clearstatcache(true, $path); // filesize() is cached per request, so it would answer with the pre-write size
    $received = (int)filesize($path);
    flock($fh, LOCK_UN);
    fclose($fh);
    $p->prepare('UPDATE uploads SET received = ? WHERE id = ?')->execute([$received, $id]);
    json_out(['received' => $received, 'total_size' => (int)$u['total_size'], 'done' => $received >= (int)$u['total_size']]);
}

if ($m === 'POST' && $action === 'finish') {
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $u = own_upload($id);
    $path = CHUNK_DIR . $u['filename'];
    clearstatcache(true, $path);
    $size = is_file($path) ? (int)filesize($path) : 0;
    if ($size !== (int)$u['total_size']) fail('Upload incomplete: ' . $size . ' of ' . (int)$u['total_size'] . ' bytes', 409);

    $orig = (string)$u['orig_name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, explode(',', OK_EXT), true)) fail('File type .' . $ext . ' not allowed');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    $stored = bin2hex(random_bytes(12)) . ($ext ? '.' . $ext : '');
    if (!@rename($path, UPLOAD_DIR . $stored)) fail('Could not store file', 500);
    $thumb = make_thumb(UPLOAD_DIR . $stored, $mime);
    $p->prepare('UPDATE uploads SET filename=?, mime=?, size=?, thumb=?, received=? WHERE id=?')
        ->execute([$stored, $mime, $size, $thumb, $size, $id]);
    json_out(['ok' => true, 'file' => file_row($p->query('SELECT * FROM uploads WHERE id = ' . $id)->fetch())]);
}

if ($m === 'GET' && $action === 'status') {
    // lets the client resume a tab that was closed mid-upload; scope arrives as query params
    [$taskId, $msgId, $projId] = scope($_GET);
    $st = $p->prepare('SELECT * FROM uploads WHERE uploaded_by = ? AND (task_id IS ? OR message_id IS ?) AND received < total_size ORDER BY id DESC LIMIT 20');
    $st->execute([uid(), $taskId, $msgId]);
    $out = [];
    foreach ($st->fetchAll() as $u) $out[] = ['upload_id' => (int)$u['id'], 'name' => $u['orig_name'], 'received' => (int)$u['received'], 'total_size' => (int)$u['total_size']];
    json_out($out);
}

if ($m === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $u = own_upload($id);
    @unlink(CHUNK_DIR . $u['filename']);
    @unlink(UPLOAD_DIR . $u['filename']);
    if ($u['thumb']) @unlink(thumb_path($u['thumb']));
    $p->prepare('DELETE FROM uploads WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

fail('Method not allowed', 405);