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

$username = trim($_POST['username'] ?? '');
$error = validateUsername($username) ?? validateNewPassword($_POST['password'] ?? '', $_POST['password_confirm'] ?? '');
if ($error === null && findUserByUsername($username) !== null) {
    $error = t('users.exists');
}

if ($error !== null) {
    flash('danger', $error);
} else {
    createUser($username, $_POST['password'], 'user');
    flash('success', t('users.added', ['name' => $username]));
}

header('Location: ../users.php');
exit;
