<?php
// users.php - admin user management
require_once __DIR__ . '/auth.php';
need_admin();
$p = db();
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET') {
    json_out($p->query('SELECT id, username, role, created_at FROM users ORDER BY username')->fetchAll());
}

if ($m === 'POST' || $m === 'PUT') {
    $b = body();
    $id = (int)($b['id'] ?? ($_GET['id'] ?? 0));
    $name = trim((string)($b['username'] ?? ''));
    $pass = (string)($b['password'] ?? '');
    if (isset($b['role']) && !in_array($b['role'], ['admin', 'worker', 'user'], true)) fail('role must be admin, worker or user');
    if ($id) {
        $st = $p->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) fail('User not found', 404);
        // ponytail: only touch the fields that were sent, so a password-only PUT cannot demote an admin
        $role = (string)($b['role'] ?? $u['role']);
        if ($u['role'] === 'admin' && $role !== 'admin' && (int)$p->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() < 2)
            fail('Cannot remove the last admin');
        if ($name !== '' && $name !== $u['username']) {
            $c = $p->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ?');
            $c->execute([$name, $id]);
            if ($c->fetchColumn()) fail('Username already taken');
            $p->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$name, $id]);
        }
        if ($pass !== '') $p->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        $p->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
        if ($id === uid()) $_SESSION['role'] = $role;
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($name === '' || $pass === '') fail('username and password required');
    $c = $p->prepare('SELECT 1 FROM users WHERE username = ?');
    $c->execute([$name]);
    if ($c->fetchColumn()) fail('Username already taken');
    $p->prepare('INSERT INTO users (username,password_hash,role) VALUES (?,?,?)')->execute([$name, password_hash($pass, PASSWORD_DEFAULT), (string)($b['role'] ?? 'user')]);
    json_out(['ok' => true, 'id' => (int)$p->lastInsertId()]);
}

if ($m === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id === uid()) fail('Cannot delete your own account');
    $st = $p->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetch()) fail('User not found', 404);
    if ($st->fetch()['role'] === 'admin' && (int)$p->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() < 2)
        fail('Cannot remove the last admin');
    $p->exec('DELETE FROM task_access WHERE user_id = ' . $id);
    $p->exec('DELETE FROM project_access WHERE user_id = ' . $id);
    $p->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

fail('Method not allowed', 405);