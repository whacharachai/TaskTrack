<?php
// chat.php - per-project chat room
require_once __DIR__ . '/auth.php';
need_login();
$p = db();
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET') {
    $pid = (int)($_GET['project_id'] ?? 0);
    need_project($pid);
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
    // before = page backwards through history; since = poll forward for anything new
    $before = (int)($_GET['before'] ?? 0);
    $since = (int)($_GET['since'] ?? 0);
    $sel = 'SELECT g.*, u.username, u.role FROM messages g LEFT JOIN users u ON u.id = g.user_id WHERE g.project_id = ' . $pid;
    if ($before > 0) {
        // newest-first so the LIMIT takes the closest ones, then flipped back for display
        $msgs = $p->query($sel . ' AND g.id < ' . $before . ' ORDER BY g.id DESC LIMIT ' . $limit)->fetchAll();
        $msgs = array_reverse($msgs);
    } elseif ($since > 0) {
        $msgs = $p->query($sel . ' AND g.id > ' . $since . ' ORDER BY g.id LIMIT 200')->fetchAll();
    } else {
        // opening a room: the latest page, oldest-first
        $msgs = array_reverse($p->query($sel . ' ORDER BY g.id DESC LIMIT ' . $limit)->fetchAll());
    }
    $ids = array_column($msgs, 'id');
    $files = [];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach ($p->query("SELECT * FROM uploads WHERE message_id IN ($in) ORDER BY id") as $f) $files[$f['message_id']][] = file_row($f);
    }
    $out = [];
    foreach ($msgs as $g) $out[] = [
        'id' => (int)$g['id'],
        'user_id' => (int)$g['user_id'],
        'username' => $g['username'] ?? '',
        'body' => $g['body'] ?? '',
        'created_at' => $g['created_at'],
        'can_delete' => is_admin() || (int)$g['user_id'] === uid(),
        'files' => $files[$g['id']] ?? [],
    ];
    json_out($out);
}

if ($m === 'POST') {
    $b = body();
    $pid = (int)($b['project_id'] ?? 0);
    need_project($pid);
    $text = trim((string)($b['body'] ?? ''));
    $attach = array_values(array_filter(array_map('intval', (array)($b['files'] ?? []))));
    if ($text === '' && !$attach) fail('Empty message');
    if (mb_strlen($text) > 5000) fail('Message too long (5000 characters max)');
    $p->prepare('INSERT INTO messages (project_id,user_id,body) VALUES (?,?,?)')->execute([$pid, uid(), $text]);
    $id = (int)$p->lastInsertId();
    foreach ($attach as $f) {
        // only link files this user uploaded and that are still unattached, into this project
        $p->prepare('UPDATE uploads SET message_id = ? WHERE id = ? AND uploaded_by = ? AND message_id IS NULL AND project_id = ?')
            ->execute([$id, $f, uid(), $pid]);
    }
    json_out(['ok' => true, 'id' => $id]);
}

if ($m === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $g = $p->query('SELECT * FROM messages WHERE id = ' . $id)->fetch();
    if (!$g) fail('Message not found', 404);
    need_project((int)$g['project_id']);
    if (!is_admin() && (int)$g['user_id'] !== uid()) fail('Not allowed', 403);
    // ponytail: detach the files rather than deleting them - a deleted message should not destroy uploads
    $p->prepare('UPDATE uploads SET message_id = NULL WHERE message_id = ?')->execute([$id]);
    $p->prepare('DELETE FROM messages WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

fail('Method not allowed', 405);