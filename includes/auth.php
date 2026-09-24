<?php
/**
 * auth.php
 * Η προβολή (λίστα, dashboard) είναι ελεύθερη σε όλους.
 * Οι ενέργειες διαχείρισης (προσθήκη/επεξεργασία/πάγωμα/ακύρωση/διαγραφή)
 * απαιτούν να έχει γίνει login.
 */

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

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
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
