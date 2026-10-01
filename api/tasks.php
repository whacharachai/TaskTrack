<?php
// tasks.php - list/create/update/delete/assign tasks
require_once __DIR__ . '/auth.php';
need_login();
$p = db();
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET') {
    $rows = $p->query('SELECT t.* FROM tasks t WHERE ' . visible_sql('t') . ' ORDER BY COALESCE(t.start_date,"9999"), t.id')->fetchAll();
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    $latest = [];
    // ponytail: SQLite bare-column + MAX() returns the row of the max done_date
    if ($byId) {
        $ids = implode(',', array_keys($byId));
        foreach ($p->query("SELECT task_id, done_amount FROM progress p WHERE done_date = (SELECT MAX(q.done_date) FROM progress q WHERE q.task_id = p.task_id) AND task_id IN ($ids)") as $r)
            $latest[(int)$r['task_id']] = (float)$r['done_amount'];
    }

    $kids = [];
    foreach ($byId as $id => $t) {
        $par = $t['parent_id'];
        if ($par !== null) $kids[(int)$par][] = $id;
    }
    $files = [];
    foreach ($p->query('SELECT task_id, COUNT(*) c FROM attachments GROUP BY task_id') as $r) $files[(int)$r['task_id']] = (int)$r['c'];
    $entries = [];
    foreach ($p->query('SELECT task_id, COUNT(*) c FROM progress GROUP BY task_id') as $r) $entries[(int)$r['task_id']] = (int)$r['c'];

    $out = [];
    foreach ($byId as $id => $t) {
        $out[] = [
            'id' => $id,
            'name' => $t['name'],
            'remark' => $t['remark'],
            'start_date' => $t['start_date'],
            'end_date' => $t['end_date'],
            'total_amount' => (float)$t['total_amount'],
            'unit' => $t['unit'],
            'parent_id' => $t['parent_id'] === null ? null : (int)$t['parent_id'],
            'roles' => array_values(array_filter(explode(',', (string)$t['roles']))),
            'status' => $t['status'],
            'children' => $kids[$id] ?? [],
            'file_count' => $files[$id] ?? 0,
            'progress_count' => $entries[$id] ?? 0,
            'own_done' => round((float)($latest[$id] ?? 0), 3),
        ] + rollup($id, $byId, $latest);
    }

    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if (!$p->query('SELECT 1 FROM tasks WHERE id = ' . $id)->fetchColumn()) fail('Task not found', 404);
        need_task($id);
        $t = null;
        foreach ($out as $o) if ($o['id'] === $id) $t = $o;
        if (!$t) fail('Task not found', 404);
        $t['users'] = array_column($p->query('SELECT u.id, u.username FROM task_access a JOIN users u ON u.id = a.user_id WHERE a.task_id = ' . $id)->fetchAll(), null, 'id');
        $t['progress'] = $p->query('SELECT g.*, u.username FROM progress g LEFT JOIN users u ON u.id = g.user_id WHERE g.task_id = ' . $id . ' ORDER BY g.done_date, g.id')->fetchAll();
        $t['files'] = $p->query('SELECT id, filename, mime, size, created_at FROM attachments WHERE task_id = ' . $id . ' ORDER BY id')->fetchAll();
        json_out($t);
    }
    json_out($out);
}

if ($m === 'POST' || $m === 'PUT') {
    need_admin();
    $b = body();
    $id = (int)($b['id'] ?? ($_GET['id'] ?? 0));
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') fail('Task name required');

    $start = trim((string)($b['start_date'] ?? '')) ?: null;
    $end = trim((string)($b['end_date'] ?? '')) ?: null;
    if (!valid_date($start) || !valid_date($end)) fail('Invalid date');
    if ($start && $end && $end < $start) fail('End date must be on/after start date');
    $total = (float)($b['total_amount'] ?? 0);
    if ($total < 0) fail('Total amount cannot be negative');
    $unit = trim((string)($b['unit'] ?? ''));
    $remark = trim((string)($b['remark'] ?? ''));
    $parent = ($b['parent_id'] ?? '') === '' || $b['parent_id'] === null ? null : (int)$b['parent_id'];

    $roles = (array)($b['roles'] ?? ['user']);
    $roles = array_values(array_intersect(['admin', 'user'], array_map('strval', $roles)));
    if (!$roles) $roles = ['admin'];

    if ($parent !== null) {
        if (!$p->query('SELECT 1 FROM tasks WHERE id = ' . $parent)->fetchColumn()) fail('Parent task not found');
        if ($id && $parent === $id) fail('A task cannot be its own parent');
        for ($n = $parent; $n !== null;) { // reject cycles: parent may not be a descendant of self
            $row = $p->query('SELECT parent_id FROM tasks WHERE id = ' . $n)->fetch();
            $n = $row && $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
            if ($id && $n === $id) fail('Cannot put a task under its own sub-task');
        }
    }

    if ($id) {
        $st = $p->prepare('UPDATE tasks SET name=?, remark=?, start_date=?, end_date=?, total_amount=?, unit=?, parent_id=?, roles=?, status=? WHERE id=?');
        $st->execute([$name, $remark, $start, $end, $total, $unit, $parent, implode(',', $roles), (string)($b['status'] ?? 'active'), $id]);
        if (!$st->rowCount()) fail('Task not found', 404);
    } else {
        $p->prepare('INSERT INTO tasks (name,remark,start_date,end_date,total_amount,unit,parent_id,roles,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$name, $remark, $start, $end, $total, $unit, $parent, implode(',', $roles), (string)($b['status'] ?? 'active'), uid()]);
        $id = (int)$p->lastInsertId();
    }

    $p->prepare('DELETE FROM task_access WHERE task_id = ?')->execute([$id]);
    foreach ((array)($b['users'] ?? []) as $u) {
        if (is_numeric($u)) $p->prepare('INSERT OR IGNORE INTO task_access (task_id,user_id) VALUES (?,?)')->execute([$id, (int)$u]);
    }
    json_out(['ok' => true, 'id' => $id]);
}

if ($m === 'DELETE') {
    need_admin();
    $id = (int)($_GET['id'] ?? 0);
    $st = $p->prepare('SELECT id FROM tasks WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetch()) fail('Task not found', 404);
    // pull the whole subtree so sub-task progress is not orphaned
    $ids = [$id];
    for ($i = 0; $i < count($ids); $i++) {
        foreach ($p->query('SELECT id FROM tasks WHERE parent_id = ' . $ids[$i]) as $c) $ids[] = (int)$c['id'];
    }
    $in = implode(',', $ids);
    foreach ($p->query("SELECT filename FROM attachments WHERE task_id IN ($in)") as $f)
        @unlink(UPLOAD_DIR . $f['filename']);
    $p->exec("DELETE FROM attachments WHERE task_id IN ($in)");
    $p->exec("DELETE FROM progress WHERE task_id IN ($in)");
    $p->exec("DELETE FROM task_access WHERE task_id IN ($in)");
    $p->exec("UPDATE tasks SET parent_id = NULL WHERE parent_id IN ($in)"); // orphan children survive
    $p->exec("DELETE FROM tasks WHERE id IN ($in)");
    json_out(['ok' => true, 'deleted' => count($ids)]);
}

fail('Method not allowed', 405);