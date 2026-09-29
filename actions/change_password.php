<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../settings.php');
    exit;
}
verifyCsrf();

$error = null;
if (!password_verify($_POST['current_password'] ?? '', currentUser()['password_hash'])) {
    $error = t('pw.wrong_current');
} else {
    $error = validateNewPassword($_POST['new_password'] ?? '', $_POST['new_password_confirm'] ?? '');
}

if ($error !== null) {
    flash('danger', $error);
} else {
    setUserPassword((int) currentUser()['id'], $_POST['new_password']);
    loginUser(currentUser());
    flash('success', t('pw.changed'));
}

header('Location: ../settings.php');
exit;