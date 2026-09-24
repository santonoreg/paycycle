<?php
/**
 * auth.php
 * Η προβολή (λίστα, dashboard) είναι ελεύθερη σε όλους.
 * Οι ενέργειες διαχείρισης (προσθήκη/επεξεργασία/πάγωμα/ακύρωση/διαγραφή)
 * απαιτούν να έχει γίνει login.
 */

require_once __DIR__ . '/i18n.php';

const MIN_PASSWORD_LENGTH = 8;

/**
 * Το hash του κωδικού διαχείρισης από τη βάση ('' αν δεν έχει οριστεί).
 * Αν λείπει από τη βάση αλλά υπάρχει παλιό APP_PASSWORD_HASH (config.local.php
 * από προηγούμενη έκδοση), εισάγεται εδώ μία φορά.
 */
function adminPasswordHash(): string
{
    $hash = getSetting('admin_password_hash');
    if ($hash === '' && defined('APP_PASSWORD_HASH') && APP_PASSWORD_HASH !== '') {
        saveSettings(getDb(), ['admin_password_hash' => APP_PASSWORD_HASH]);
        $hash = APP_PASSWORD_HASH;
    }
    return $hash;
}

function isPasswordSet(): bool
{
    return adminPasswordHash() !== '';
}

function verifyAdminPassword(string $password): bool
{
    $hash = adminPasswordHash();
    return $hash !== '' && password_verify($password, $hash);
}

function setAdminPassword(string $password): void
{
    saveSettings(getDb(), ['admin_password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
}

/** @return string|null μήνυμα σφάλματος, ή null αν ο νέος κωδικός είναι έγκυρος */
function validateNewPassword(string $password, string $confirm): ?string
{
    if ((function_exists('mb_strlen') ? mb_strlen($password) : strlen($password)) < MIN_PASSWORD_LENGTH) {
        return t('pw.too_short', ['n' => MIN_PASSWORD_LENGTH]);
    }
    if ($password !== $confirm) {
        return t('pw.mismatch');
    }
    return null;
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

/**
 * Καλείται στην αρχή από κάθε action script που τροποποιεί δεδομένα.
 * Αν ο χρήστης δεν έχει κάνει login, τον στέλνει στη σελίδα login με
 * ένα "redirect_to" ώστε να γυρίσει πίσω μετά.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        $back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        header('Location: ' . loginUrl($back));
        exit;
    }
}

function loginUrl(string $backTo = 'index.php'): string
{
    $base = strpos($_SERVER['SCRIPT_NAME'], '/actions/') !== false ? '../login.php' : 'login.php';
    return $base . '?redirect_to=' . urlencode($backTo);
}

// --- CSRF ------------------------------------------------------------------

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Σελίδες λίστας στις οποίες επιστρέφουν οι ενέργειες μετά την ολοκλήρωση. */
const LIST_PAGES = ['index.php', 'recurring.php'];

/** Η σελίδα λίστας στην οποία γυρίζει ο χρήστης μετά από μια ενέργεια (whitelist). */
function backPage(): string
{
    $b = $_POST['back'] ?? '';
    return in_array($b, LIST_PAGES, true) ? $b : 'index.php';
}

function csrfField(): string
{
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $back = in_array($page, LIST_PAGES, true) ? '<input type="hidden" name="back" value="' . $page . '">' : '';
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">' . $back;
}

function verifyCsrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(400);
        die(function_exists('t') ? htmlspecialchars(t('csrf.invalid')) : 'Invalid request (CSRF).');
    }
}

/** Επιτρέπει redirect μόνο σε σχετικές διαδρομές μέσα στην εφαρμογή. */
function sanitizeRedirect(string $target, string $default = 'index.php'): string
{
    if (!preg_match('#^[a-zA-Z0-9_./?=&%-]+$#', $target) || str_starts_with($target, '//') || str_contains($target, '..')) {
        return $default;
    }
    return $target;
}
