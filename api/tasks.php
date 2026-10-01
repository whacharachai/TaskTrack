<?php
// tasks.php - list/create/update/delete tasks (access inherited from the project)
require_once __DIR__ . '/auth.php';
need_login();
$p = db();
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET') {
    $where = ' WHERE ' . task_visible_sql('t');
    if (isset($_GET['project_id'])) {
        need_project((int)$_GET['project_id']);
        $where .= ' AND t.project_id = ' . (int)$_GET['project_id']; // no legacy null rows here, they belong to no project
    }
    $rows = $p->query('SELECT t.* FROM tasks t' . $where . ' ORDER BY COALESCE(t.start_date,"9999"), t.id')->fetchAll();
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    // each entry is the amount done on that day, so a task's own done is their sum
    $own = [];
    if ($byId) {
        $ids = implode(',', array_keys($byId));
        foreach ($p->query("SELECT task_id, SUM(done_amount) s FROM progress WHERE task_id IN ($ids) GROUP BY task_id") as $r)
            $own[(int)$r['task_id']] = (float)$r['s'];
    }

    $kids = [];
    foreach ($byId as $id => $t) {
        $par = $t['parent_id'];
        if ($par !== null) $kids[(int)$par][] = $id;
    }
    $files = [];
    foreach ($p->query('SELECT task_id, COUNT(*) c FROM uploads WHERE task_id IS NOT NULL AND received = total_size GROUP BY task_id') as $r) $files[(int)$r['task_id']] = (int)$r['c'];
    $entries = [];
    foreach ($p->query('SELECT task_id, COUNT(*) c FROM progress GROUP BY task_id') as $r) $entries[(int)$r['task_id']] = (int)$r['c'];

    $out = [];
    foreach ($byId as $id => $t) {
        $out[] = [
            'id' => $id,
            'name' => $t['name'],
            'remark' => $t['remark'],
            'project_id' => $t['project_id'] === null ? null : (int)$t['project_id'],
            'start_date' => $t['start_date'],
            'end_date' => $t['end_date'],
            'total_amount' => (float)$t['total_amount'],
            'unit' => $t['unit'],
            'parent_id' => $t['parent_id'] === null ? null : (int)$t['parent_id'],
            'status' => $t['status'],
            'children' => $kids[$id] ?? [],
            'file_count' => $files[$id] ?? 0,
            'progress_count' => $entries[$id] ?? 0,
            'own_done' => round((float)($own[$id] ?? 0), 3),
        ] + rollup($id, $byId, $own);
    }

    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if (!$p->query('SELECT 1 FROM tasks WHERE id = ' . $id)->fetchColumn()) fail('Task not found', 404);
        need_task($id);
        $t = null;
        foreach ($out as $o) if ($o['id'] === $id) $t = $o;
        if (!$t) fail('Task not found', 404);
        $t['progress'] = $p->query('SELECT g.*, u.username FROM progress g LEFT JOIN users u ON u.id = g.user_id WHERE g.task_id = ' . $id . ' ORDER BY g.done_date, g.id')->fetchAll();
        $t['files'] = array_map('file_row', $p->query('SELECT * FROM uploads WHERE task_id = ' . $id . ' AND received = total_size ORDER BY id')->fetchAll());
        json_out($t);
    }
    json_out($out);
}

if ($m === 'POST' || $m === 'PUT') {
    need_write();
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

    // ponytail: access is set on the project, so a task outside one is unreachable and unmanageable
    $project = ($b['project_id'] ?? '') === '' || $b['project_id'] === null ? 0 : (int)$b['project_id'];
    if ($project <= 0) fail('project_id required');
    if (!$p->query('SELECT 1 FROM projects WHERE id = ' . $project)->fetchColumn()) fail('Project not found', 404);
    need_project($project);
    $parent = ($b['parent_id'] ?? '') === '' || $b['parent_id'] === null ? null : (int)$b['parent_id'];

    if ($parent !== null) {
        $prow = $p->query('SELECT project_id FROM tasks WHERE id = ' . $parent)->fetch();
        if (!$prow) fail('Parent task not found', 404);
        if ((int)$prow['project_id'] !== $project) fail('Parent task belongs to another project');
        if ($id && $parent === $id) fail('A task cannot be its own parent');
        for ($n = $parent; $n !== null;) { // reject cycles: parent may not be a descendant of self
            $row = $p->query('SELECT parent_id FROM tasks WHERE id = ' . $n)->fetch();
            $n = $row && $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
            if ($id && $n === $id) fail('Cannot put a task under its own sub-task');
        }
    }

    if ($id) {
        $st = $p->prepare('UPDATE tasks SET name=?, remark=?, start_date=?, end_date=?, total_amount=?, unit=?, parent_id=?, project_id=?, status=? WHERE id=?');
        $st->execute([$name, $remark, $start, $end, $total, $unit, $parent, $project, (string)($b['status'] ?? 'active'), $id]);
    } else {
        $p->prepare('INSERT INTO tasks (name,remark,start_date,end_date,total_amount,unit,parent_id,project_id,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$name, $remark, $start, $end, $total, $unit, $parent, $project, (string)($b['status'] ?? 'active'), uid()]);
        $id = (int)$p->lastInsertId();
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
    for ($i = 0; $i < count($ids); $i++)
        foreach ($p->query('SELECT id FROM tasks WHERE parent_id = ' . $ids[$i]) as $c) $ids[] = (int)$c['id'];
    $in = implode(',', $ids);
    foreach ($p->query("SELECT filename, thumb FROM uploads WHERE task_id IN ($in)") as $f) {
        @unlink(UPLOAD_DIR . $f['filename']);
        if ($f['thumb']) @unlink(thumb_path($f['thumb']));
    }
    $p->exec("DELETE FROM uploads WHERE task_id IN ($in)");
    $p->exec("DELETE FROM progress WHERE task_id IN ($in)");
    $p->exec("UPDATE tasks SET parent_id = NULL WHERE parent_id IN ($in)");
    $p->exec("DELETE FROM tasks WHERE id IN ($in)");
    json_out(['ok' => true, 'deleted' => count($ids)]);
}

fail('Method not allowed', 405);