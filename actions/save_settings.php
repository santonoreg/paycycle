<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/i18n.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../settings.php');
    exit;
}
verifyCsrf();

$language = $_POST['language'] ?? '';
$theme = $_POST['theme'] ?? '';
$status = $_POST['default_status'] ?? '';
$category = trim($_POST['default_category'] ?? '');

$pdo = getDb();
$validCategory = $category === '' || in_array($category, getDistinctCategories($pdo), true);

if (!isset(SUPPORTED_LANGUAGES[$language]) || !in_array($theme, THEMES, true)
    || ($status !== '' && !in_array($status, STATUS_KEYS, true)) || !$validCategory) {
    flash('danger', t('settings.invalid'));
    header('Location: ../settings.php');
    exit;
}

saveSettings($pdo, [
    'language'         => $language,
    'theme'            => $theme,
    'default_status'   => $status,
    'default_category' => $category,
]);

// Ο διαχειριστής μόλις όρισε τη γλώσσα: ξεκαθάρισε τυχόν προσωπική επιλογή
// (cookie) ώστε να δει αμέσως το αποτέλεσμα.
setLangCookie(null);
flash('success', t('settings.saved'));
header('Location: ../settings.php');
exit;
