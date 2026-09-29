<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../users.php');
    exit;
}
verifyCsrf();

$id = (int) ($_POST['id'] ?? 0);
$user = findUserById($id);

if ($user === null) {
    flash('danger', t('users.not_found'));
} elseif ($id === (int) currentUser()['id']) {
    flash('danger', t('users.cannot_delete_self'));
} elseif ($user['role'] === 'admin' && (int) getDb()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
    flash('danger', t('users.cannot_delete_last_admin'));
} else {
    // Οι συνδρομές που είχε προσθέσει μένουν· το created_by γίνεται NULL (ON DELETE SET NULL)
    // και το created_by_name κρατά το όνομα για την καταγραφή.
    getDb()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    flash('success', t('users.deleted', ['name' => $user['username']]));
}

header('Location: ../users.php');
exit;
