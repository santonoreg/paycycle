<?php
/**
 * db.php
 * Σύνδεση PDO στη SQLite βάση + δημιουργία schema αν δεν υπάρχει.
 */

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = dirname(DB_PATH);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }

    // Ο φάκελος data/ προστατεύεται από άμεση πρόσβαση μέσω browser.
    $htaccess = $dataDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
");
    }

    $isNew = !file_exists(DB_PATH);

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    initSchema($pdo, !$isNew);

    return $pdo;
}

/**
 * Τρέχει όλα τα migrations που δεν έχουν εφαρμοστεί ακόμα (βλ. includes/migrations.php).
 * Η βάση δημιουργείται αν δεν υπάρχει, και μια υπάρχουσα βάση αναβαθμίζεται
 * χωρίς να χάνεται κανένα δεδομένο. Πριν εφαρμοστεί οποιοδήποτε migration σε
 * υπάρχουσα βάση, παίρνεται αντίγραφο ασφαλείας στο data/backups/.
 *
 * @param bool $backupExisting true όταν η βάση υπήρχε ήδη πριν από αυτή την κλήση
 */
function initSchema(PDO $pdo, bool $backupExisting = false): void
{
    require_once __DIR__ . '/migrations.php';
    runMigrations($pdo, $backupExisting);
}

/** Migration 1: βασικό schema (idempotent — ασφαλές και σε ήδη υπάρχουσες βάσεις). */
function migrateBaseSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subscriptions (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            name            TEXT NOT NULL,
            category        TEXT NOT NULL,
            frequency       TEXT NOT NULL CHECK(frequency IN ('weekly','monthly','half-yearly','yearly','biennial','triennial')),
            payment_method  TEXT,
            start_date      TEXT NOT NULL,
            status          TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','trial','frozen','canceled')),
            canceled_date   TEXT,
            notes           TEXT,
            created_at      TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    migrateFrequencyCheck($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subscription_prices (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER NOT NULL,
            cost            REAL NOT NULL,
            effective_from  TEXT NOT NULL,
            note            TEXT,
            created_at      TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY(subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subscription_freezes (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER NOT NULL,
            frozen_from     TEXT NOT NULL,
            frozen_until    TEXT,
            created_at      TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY(subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
        )
    ");

    // Το πραγματικό "βιβλίο" πληρωμών: κάθε δόση που έχει καταχωρηθεί ως
    // πληρωμένη γράφεται εδώ ως ξεχωριστή γραμμή (ημερομηνία + ποσό), αντί να
    // υπολογίζεται εκ νέου κάθε φορά. Έτσι: (α) μια νέα συνδρομή με παλιά
    // ημερομηνία έναρξης "γεμίζει" αυτόματα το ιστορικό της μέχρι σήμερα κατά
    // την καταχώρηση, και (β) μια υπάρχουσα συνδρομή συνεχίζει τον υπολογισμό
    // από την τελευταία καταχωρημένη πληρωμή της, όχι από την αρχική ημερομηνία.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subscription_payments (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER NOT NULL,
            payment_date    TEXT NOT NULL,
            amount          REAL NOT NULL,
            created_at      TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY(subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
            UNIQUE(subscription_id, payment_date)
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prices_sub ON subscription_prices(subscription_id, effective_from)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_freezes_sub ON subscription_freezes(subscription_id, frozen_from)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payments_sub ON subscription_payments(subscription_id, payment_date)");
}

/**
 * Παλιές βάσεις έχουν CHECK χωρίς 'triennial'. Το SQLite δεν αλλάζει CHECK με
 * ALTER, οπότε ξαναχτίζουμε τον πίνακα (με τα foreign keys κλειστά ώστε να μην
 * διαγραφούν τα παιδικά records μέσω ON DELETE CASCADE).
 */
function migrateFrequencyCheck(PDO $pdo): void
{
    $sql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='subscriptions'")->fetchColumn();
    if ($sql === '' || str_contains($sql, "'triennial'")) {
        return;
    }
    $newSql = str_replace("'biennial')", "'biennial','triennial')", $sql);
    $newSql = preg_replace('/CREATE TABLE\s+"?subscriptions"?/i', 'CREATE TABLE subscriptions_new', $newSql, 1);

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        $pdo->exec($newSql);
        $pdo->exec('INSERT INTO subscriptions_new SELECT * FROM subscriptions');
        $pdo->exec('DROP TABLE subscriptions');
        $pdo->exec('ALTER TABLE subscriptions_new RENAME TO subscriptions');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}
