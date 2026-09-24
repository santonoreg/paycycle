<?php
/**
 * i18n.php
 * Πολυγλωσσία (t()), καθώς και οι ρυθμίσεις της εφαρμογής (γλώσσα, θέμα,
 * προεπιλεγμένα φίλτρα) που αποθηκεύονται στον πίνακα app_settings.
 *
 * Σειρά προτεραιότητας γλώσσας: cookie του επισκέπτη (γρήγορη αλλαγή από το
 * μενού) -> προεπιλεγμένη γλώσσα από τις Ρυθμίσεις -> 'el'.
 */

require_once __DIR__ . '/db.php';

/** Διαθέσιμες γλώσσες: κωδικός => όνομα στη δική της γλώσσα. Για νέα γλώσσα: πρόσθεσε εδώ και φτιάξε lang/<κωδικός>.php */
const SUPPORTED_LANGUAGES = [
    'el' => 'Ελληνικά',
    'en' => 'English',
    'de' => 'Deutsch',
];
const DEFAULT_LANGUAGE = 'el';
const THEMES = ['light', 'dark', 'auto'];
const LANG_COOKIE = 'subs_lang';

// --- Ρυθμίσεις ------------------------------------------------------------

function settingDefaults(): array
{
    return ['language' => DEFAULT_LANGUAGE, 'theme' => 'light', 'default_status' => '', 'default_category' => ''];
}

/** @return array<string,string> */
function getSettings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = settingDefaults();
        try {
            foreach (getDb()->query('SELECT key, value FROM app_settings')->fetchAll() as $row) {
                $cache[$row['key']] = $row['value'];
            }
        } catch (Throwable $e) {
            // Αν για κάποιο λόγο δεν διαβάζονται, δουλεύουμε με τις προεπιλογές.
        }
    }
    return $cache;
}

function getSetting(string $key): string
{
    return getSettings()[$key] ?? (settingDefaults()[$key] ?? '');
}

function saveSettings(PDO $pdo, array $values): void
{
    $stmt = $pdo->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at");
    $pdo->beginTransaction();
    try {
        foreach ($values as $k => $v) {
            $stmt->execute([$k, (string) $v]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    getSettings(true);
}

// --- Γλώσσα ---------------------------------------------------------------

function currentLang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $cookie = $_COOKIE[LANG_COOKIE] ?? '';
    if (isset(SUPPORTED_LANGUAGES[$cookie])) {
        return $lang = $cookie;
    }
    $default = getSetting('language');
    return $lang = isset(SUPPORTED_LANGUAGES[$default]) ? $default : DEFAULT_LANGUAGE;
}

/** Το URL path της εφαρμογής (για το cookie), ανεξάρτητα αν καλείται από actions/ */
function appBasePath(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = preg_replace('#/actions$#', '', $dir);
    return $dir === '' ? '/' : $dir;
}

function setLangCookie(?string $lang): void
{
    $opts = ['expires' => $lang === null ? time() - 3600 : time() + 365 * 86400, 'path' => appBasePath(), 'samesite' => 'Lax'];
    setcookie(LANG_COOKIE, $lang ?? '', $opts);
    if ($lang === null) {
        unset($_COOKIE[LANG_COOKIE]);
    } else {
        $_COOKIE[LANG_COOKIE] = $lang;
    }
}

function loadLangFile(string $lang): array
{
    static $files = [];
    if (!isset($files[$lang])) {
        $path = __DIR__ . '/../lang/' . $lang . '.php';
        $files[$lang] = is_file($path) ? (require $path) : [];
    }
    return $files[$lang];
}

/**
 * Μετάφραση κλειδιού. Placeholders της μορφής {name}. Αν λείπει η μετάφραση,
 * πέφτει στα ελληνικά και τέλος στο ίδιο το κλειδί.
 */
function t(string $key, array $params = []): string
{
    $lang = currentLang();
    $val = loadLangFile($lang)[$key] ?? loadLangFile(DEFAULT_LANGUAGE)[$key] ?? $key;
    if (!is_string($val)) {
        return $key;
    }
    foreach ($params as $k => $v) {
        $val = str_replace('{' . $k . '}', (string) $v, $val);
    }
    return $val;
}

/** Μετάφραση με HTML escaping, για χρήση μέσα σε templates. */
function te(string $key, array $params = []): string
{
    return htmlspecialchars(t($key, $params), ENT_QUOTES);
}

/** Μετάφραση-λίστα (π.χ. ονόματα μηνών). */
function tList(string $key): array
{
    $val = loadLangFile(currentLang())[$key] ?? loadLangFile(DEFAULT_LANGUAGE)[$key] ?? [];
    return is_array($val) ? $val : [];
}

/** JS string literal ασφαλές για χρήση μέσα σε HTML attribute (π.χ. onsubmit="confirm(...)"). */
function jsq(string $s): string
{
    return htmlspecialchars(json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), ENT_QUOTES);
}

function htmlLocale(): string
{
    return t('meta.locale');
}
