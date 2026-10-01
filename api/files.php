<?php
// files.php - upload/list/download task attachments (multipart)
require_once __DIR__ . '/auth.php';
// ponytail: must run before $_POST/$_FILES is touched; body is parsed lazily
ini_set('upload_max_filesize', (string)UPLOAD_MAX);
ini_set('post_max_size', (string)(UPLOAD_MAX + 1048576));
need_login();
$p = db();
$m = $_SERVER['REQUEST_METHOD'];

const OK_EXT = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,odt,png,jpg,jpeg,gif,webp,zip,rar,7z,dwg,dxf,kml,kmz,mp4';

if ($m === 'POST') {
    $id = (int)($_POST['task_id'] ?? 0);
    need_task($id);
    $files = $_FILES['files'] ?? null;
    $list = [];
    if ($files && $files['name']) {
        $list = is_array($files['name']) ? array_keys($files['name']) : ['0'];
    }
    if (!$list) fail('No file uploaded');

    @mkdir(UPLOAD_DIR, 0775, true);
    $st = $p->prepare('INSERT INTO attachments (task_id,filename,orig_name,mime,size,uploaded_by) VALUES (?,?,?,?,?,?)');
    $saved = [];
    foreach ($list as $k) {
        $err = $files['error'][$k];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) fail('File too large (max ' . round(UPLOAD_MAX / 1048576) . ' MB)', 413);
        if ($err !== UPLOAD_ERR_OK) fail('Upload failed (code ' . $err . ')', 400);
        if ($files['size'][$k] > UPLOAD_MAX) fail('File too large (max ' . round(UPLOAD_MAX / 1048576) . ' MB)', 413);
        $orig = (string)$files['name'][$k];
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, explode(',', OK_EXT), true)) fail('File type .' . $ext . ' not allowed');
        $tmp = (string)$files['tmp_name'][$k];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        $name = bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
        if (!move_uploaded_file($tmp, UPLOAD_DIR . $name)) fail('Could not store file', 500);
        $st->execute([$id, $name, $orig, $mime, (int)$files['size'][$k], uid()]);
        $saved[] = ['id' => (int)$p->lastInsertId(), 'filename' => $orig];
    }
    json_out(['ok' => true, 'files' => $saved]);
}

if ($m === 'GET') {
    if (isset($_GET['download'])) {
        $st = $p->prepare('SELECT * FROM attachments WHERE id = ?');
        $st->execute([(int)$_GET['download']]);
        $f = $st->fetch();
        if (!$f) fail('File not found', 404);
        need_task((int)$f['task_id']);
        $path = UPLOAD_DIR . $f['filename'];
        if (!is_file($path)) fail('File missing on disk', 404);
        header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string)($f['orig_name'] ?: $f['filename'])) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
    $id = (int)($_GET['task_id'] ?? 0);
    need_task($id);
    json_out($p->query('SELECT id, filename, size, created_at FROM attachments WHERE task_id = ' . $id . ' ORDER BY id')->fetchAll());
}

if ($m === 'DELETE') {
    $st = $p->prepare('SELECT * FROM attachments WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $f = $st->fetch();
    if (!$f) fail('File not found', 404);
    need_task((int)$f['task_id']);
    if (!is_admin() && (int)$f['uploaded_by'] !== uid()) fail('Not allowed', 403);
    @unlink(UPLOAD_DIR . $f['filename']);
    $p->prepare('DELETE FROM attachments WHERE id = ?')->execute([$f['id']]);
    json_out(['ok' => true]);
}

fail('Method not allowed', 405);