<?php
/**
 * config.php
 * Βασικές ρυθμίσεις της εφαρμογής.
 */

// Ζώνη ώρας
date_default_timezone_set('Europe/Athens');

// --- Κωδικός διαχείρισης --------------------------------------------------
// Ο κωδικός ΔΕΝ αποθηκεύεται σε καθαρό κείμενο, αλλά ως hash.
// Ο προεπιλεγμένος κωδικός είναι: changeme
// Για να ορίσεις τον δικό σου κωδικό, τρέξε στο τερματικό:
//     php -r "echo password_hash('ο-δικος-μου-κωδικος', PASSWORD_DEFAULT), PHP_EOL;"
// και βάλε το αποτέλεσμα στο αρχείο config.local.php (δεν ανεβαίνει στο git):
//     <?php define('APP_PASSWORD_HASH', '...');
// Αν δεν υπάρχει config.local.php, ισχύει ο προεπιλεγμένος κωδικός.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
if (!defined('APP_PASSWORD_HASH')) {
    define('APP_PASSWORD_HASH', '$2y$12$YhRXfAm6dqPtWgj7ZwSDG.s9us7.zSKb/w4gMaEvFPT/Gzh0vSi5S');
}

// --- Διαδρομές -------------------------------------------------------------
define('APP_ROOT', __DIR__);
define('DB_PATH', __DIR__ . '/data/subscriptions.sqlite');

// --- Session -----------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Εμφάνιση σφαλμάτων (απενεργοποίησε σε production αν θέλεις) ---------
error_reporting(E_ALL);
ini_set('display_errors', '1');
