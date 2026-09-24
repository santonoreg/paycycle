<?php
// Γρήγορη αλλαγή γλώσσας από το μενού — αφορά μόνο τον συγκεκριμένο επισκέπτη
// (cookie). Δεν απαιτεί login και δεν αλλάζει την προεπιλογή των Ρυθμίσεων.
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/i18n.php';

$back = sanitizeRedirect($_POST['back'] ?? 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $lang = $_POST['lang'] ?? '';
    if (isset(SUPPORTED_LANGUAGES[$lang])) {
        setLangCookie($lang);
    }
}

header('Location: ../' . $back);
exit;
