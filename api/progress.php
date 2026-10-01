<?php
// progress.php - cumulative progress entries per task (amount, date, remark)
require_once __DIR__ . '/auth.php';
need_login();
$p = db();
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET') {
    $id = (int)($_GET['task_id'] ?? 0);
    if (!$p->query('SELECT 1 FROM tasks WHERE id = ' . $id)->fetchColumn()) fail('Task not found', 404);
    need_task($id);
    json_out($p->query('SELECT g.*, u.username FROM progress g LEFT JOIN users u ON u.id = g.user_id
                       WHERE g.task_id = ' . $id . ' ORDER BY g.done_date, g.id')->fetchAll());
}

if ($m === 'POST') {
    $b = $_POST ? $_POST : body();
    $id = (int)($b['task_id'] ?? 0);
    need_task($id);
    $amount = (float)($b['done_amount'] ?? -1);
    if (!is_numeric($b['done_amount'] ?? null) || $amount < 0) fail('done_amount must be zero or more');
    $date = trim((string)($b['done_date'] ?? '')) ?: today();
    if (!valid_date($date)) fail('Invalid date');
    $remark = trim((string)($b['remark'] ?? ''));
    $st = $p->prepare('INSERT INTO progress (task_id,done_amount,done_date,remark,user_id) VALUES (?,?,?,?,?)');
    $st->execute([$id, $amount, $date, $remark, uid()]);
    json_out(['ok' => true, 'id' => (int)$p->lastInsertId()]);
}

if ($m === 'DELETE') {
    $g = (int)($_GET['id'] ?? 0);
    $st = $p->prepare('SELECT task_id, user_id FROM progress WHERE id = ?');
    $st->execute([$g]);
    $row = $st->fetch();
    if (!$row) fail('Entry not found', 404);
    need_task((int)$row['task_id']);
    if (!is_admin() && (int)($row['user_id'] ?? 0) !== uid()) fail('Not allowed', 403);
    $p->prepare('DELETE FROM progress WHERE id = ?')->execute([$g]);
    json_out(['ok' => true]);
}

fail('Method not allowed', 405);