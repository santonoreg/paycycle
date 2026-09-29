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
$pw = $_POST['new_password'] ?? '';

if ($user === null) {
    flash('danger', t('users.not_found'));
} elseif (($error = validateNewPassword($pw, $pw)) !== null) {
    flash('danger', $error);
} else {
    setUserPassword($id, $pw);
    flash('success', t('users.password_reset', ['name' => $user['username']]));
}

header('Location: ../users.php');
exit;
