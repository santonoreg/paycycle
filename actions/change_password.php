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
if (!verifyAdminPassword($_POST['current_password'] ?? '')) {
    $error = t('pw.wrong_current');
} else {
    $error = validateNewPassword($_POST['new_password'] ?? '', $_POST['new_password_confirm'] ?? '');
}

if ($error !== null) {
    flash('danger', $error);
} else {
    setAdminPassword($_POST['new_password']);
    session_regenerate_id(true);
    flash('success', t('pw.changed'));
}

header('Location: ../settings.php');
exit;