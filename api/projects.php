<?php
// projects.php - project CRUD and per-project access
require_once __DIR__ . '/auth.php';
need_login();
$p = db();
$m = $_SERVER['REQUEST_METHOD'];

function project_out(PDO $p, array $r): array {
    $id = (int)$r['id'];
    $tasks = (int)$p->query('SELECT COUNT(*) FROM tasks WHERE project_id = ' . $id)->fetchColumn();
    $msgs = (int)$p->query('SELECT COUNT(*) FROM messages WHERE project_id = ' . $id)->fetchColumn();
    return [
        'id' => $id,
        'name' => $r['name'],
        'remark' => $r['remark'],
        'status' => $r['status'],
        'task_count' => $tasks,
        'message_count' => $msgs,
        'users' => array_column($p->query('SELECT u.id, u.username FROM project_access a JOIN users u ON u.id = a.user_id WHERE a.project_id = ' . $id)->fetchAll(), null, 'id'),
        'created_at' => $r['created_at'],
    ];
}

if ($m === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        need_project($id);
        $st = $p->prepare('SELECT * FROM projects WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) fail('Project not found', 404);
        json_out(project_out($p, $r));
    }
    $rows = $p->query('SELECT * FROM projects p WHERE ' . project_visible_sql('p') . ' ORDER BY p.status, p.name')->fetchAll();
    json_out(array_map(fn($r) => project_out($p, $r), $rows));
}

if ($m === 'POST' || $m === 'PUT') {
    need_admin();
    $b = body();
    $id = (int)($b['id'] ?? ($_GET['id'] ?? 0));
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') fail('Project name required');
    $remark = trim((string)($b['remark'] ?? ''));
    $status = (string)($b['status'] ?? 'active');
    if (!in_array($status, ['active', 'archived'], true)) fail('status must be active or archived');

    if ($id) {
        $st = $p->prepare('SELECT id FROM projects WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) fail('Project not found', 404);
        $p->prepare('UPDATE projects SET name=?, remark=?, status=? WHERE id=?')->execute([$name, $remark, $status, $id]);
    } else {
        $p->prepare('INSERT INTO projects (name,remark,status,created_by) VALUES (?,?,?,?)')
            ->execute([$name, $remark, $status, uid()]);
        $id = (int)$p->lastInsertId();
    }
    // who can see it: named users only; the project's creator stays on it even if unnamed
    $p->prepare('DELETE FROM project_access WHERE project_id = ?')->execute([$id]);
    $seen = [];
    foreach (array_merge([(int)$p->query('SELECT created_by FROM projects WHERE id = ' . $id)->fetchColumn()], (array)($b['users'] ?? [])) as $u) {
        if (!is_numeric($u)) continue;
        $u = (int)$u;
        if (isset($seen[$u])) continue;
        $seen[$u] = 1;
        $p->prepare('INSERT OR IGNORE INTO project_access (project_id,user_id) VALUES (?,?)')->execute([$id, $u]);
    }
    json_out(['ok' => true, 'id' => $id]);
}

if ($m === 'DELETE') {
    need_admin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$p->query('SELECT 1 FROM projects WHERE id = ' . $id)->fetchColumn()) fail('Project not found', 404);
    // files survive their message/task row: detach instead of deleting bytes
    $p->exec("UPDATE uploads SET message_id = NULL, task_id = NULL, project_id = NULL WHERE project_id = $id");
    $p->exec("UPDATE tasks SET project_id = NULL WHERE project_id = $id");
    $p->exec("DELETE FROM messages WHERE project_id = $id");
    $p->exec("DELETE FROM project_access WHERE project_id = $id");
    $p->exec("DELETE FROM projects WHERE id = $id");
    json_out(['ok' => true]);
}

fail('Method not allowed', 405);