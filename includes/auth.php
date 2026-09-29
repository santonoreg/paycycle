<?php
/**
 * auth.php
 * Η προβολή (λίστα, dashboard) είναι ελεύθερη σε όλους.
 * Οι ενέργειες διαχείρισης (προσθήκη/επεξεργασία/πάγωμα/ακύρωση/διαγραφή)
 * απαιτούν να έχει γίνει login. Υπάρχουν χρήστες (πίνακας users) με ρόλο
 * 'admin' ή 'user'· μόνο ο admin προσθαφαιρεί χρήστες και αλλάζει τις ρυθμίσεις.
 */

require_once __DIR__ . '/i18n.php';

const MIN_PASSWORD_LENGTH = 8;

function hasUsers(): bool
{
    return (int) getDb()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

/** @return array{id:int,username:string,password_hash:string,role:string}|null */
function findUserByUsername(string $username): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    return $stmt->fetch() ?: null;
}

/** @return array{id:int,username:string,password_hash:string,role:string}|null */
function findUserById(int $id): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Επαληθεύει username + κωδικό. Επιστρέφει τον χρήστη ή null. */
function verifyLogin(string $username, string $password): ?array
{
    $user = findUserByUsername(trim($username));
    if ($user === null) {
        // Ίδιος χρόνος απάντησης είτε υπάρχει ο χρήστης είτε όχι.
        password_verify($password, '$2y$12$qMr/zM4PmPYhSyiVHVCCQOVgQfDqy5kj1fq9GhSreESZOJ0YVKJ12');
        return null;
    }
    return password_verify($password, $user['password_hash']) ? $user : null;
}

function validateUsername(string $username): ?string
{
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
        return t('user.invalid_name');
    }
    return null;
}

/** @return int το id του νέου χρήστη */
function createUser(string $username, string $password, string $role = 'user'): int
{
    $pdo = getDb();
    $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role === 'admin' ? 'admin' : 'user']);
    return (int) $pdo->lastInsertId();
}

function setUserPassword(int $userId, string $password): void
{
    getDb()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
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

/** Ο συνδεδεμένος χρήστης, ή null. Ελέγχεται σε κάθε αίτημα, ώστε ένας χρήστης που διαγράφηκε να αποσυνδέεται αμέσως. */
function currentUser(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = !empty($_SESSION['user_id']) ? findUserById((int) $_SESSION['user_id']) : null;
    }
    return $user;
}

function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

function isLoggedIn(): bool
{
    return currentUser() !== null;
}

function isAdmin(): bool
{
    $u = currentUser();
    return $u !== null && $u['role'] === 'admin';
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

/** Ενέργειες μόνο για τον admin (διαχείριση χρηστών, γενικές ρυθμίσεις). */
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        die(htmlspecialchars(t('err.admin_only')));
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
