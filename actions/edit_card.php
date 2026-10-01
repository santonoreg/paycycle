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

$id = (int) ($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$last4 = trim($_POST['last4'] ?? '');

if (!isset(getCardsById(getDb())[$id])) {
    flash('danger', t('cards.not_found'));
} elseif ($name === '' || mb_strlen($name) > 40) {
    flash('danger', t('cards.err_name'));
} elseif (!preg_match('/^[0-9]{4}$/', $last4)) {
    flash('danger', t('cards.err_last4'));
} else {
    getDb()->prepare('UPDATE cards SET name = ?, last4 = ? WHERE id = ?')->execute([$name, $last4, $id]);
    flash('success', t('cards.saved', ['name' => $name]));
}

header('Location: ../cards.php');
exit;
