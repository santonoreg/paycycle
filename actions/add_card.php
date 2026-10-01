<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../cards.php');
    exit;
}
verifyCsrf();

$name = trim($_POST['name'] ?? '');
$last4 = trim($_POST['last4'] ?? '');

if ($name === '' || mb_strlen($name) > 40) {
    flash('danger', t('cards.err_name'));
} elseif (!preg_match('/^[0-9]{4}$/', $last4)) {
    flash('danger', t('cards.err_last4'));
} else {
    getDb()->prepare('INSERT INTO cards (name, last4) VALUES (?, ?)')->execute([$name, $last4]);
    flash('success', t('cards.added', ['name' => $name]));
}

header('Location: ../cards.php');
exit;
