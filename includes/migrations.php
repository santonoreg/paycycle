<?php
/**
 * migrations.php
 * Απλό σύστημα migrations για τη SQLite βάση.
 *
 * Κάθε migration έχει αύξοντα αριθμό έκδοσης και τρέχει ΜΙΑ φορά. Οι εκδόσεις
 * που έχουν εφαρμοστεί καταγράφονται στον πίνακα schema_migrations. Τα
 * migrations δεν διαγράφουν ποτέ δεδομένα — μόνο προσθέτουν πίνακες/στήλες.
 *
 * Για νέα αλλαγή schema: πρόσθεσε ένα νέο στοιχείο στο getMigrations() με τον
 * επόμενο αριθμό. ΜΗΝ αλλάζεις migrations που έχουν ήδη κυκλοφορήσει.
 */

/** @return array<int, array{name: string, up: callable}> */
function getMigrations(): array
{
    return [
        1 => [
            'name' => 'base schema (subscriptions, prices, freezes, payments)',
            // Idempotent: σε υπάρχουσες βάσεις (πριν από το σύστημα migrations)
            // δεν αλλάζει τίποτα, απλώς καταγράφεται ως εφαρμοσμένο.
            'up'   => 'migrateBaseSchema',
        ],
        2 => [
            'name' => 'app_settings table (language, theme, default filters)',
            'up'   => function (PDO $pdo): void {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS app_settings (
                        key        TEXT PRIMARY KEY,
                        value      TEXT NOT NULL,
                        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                    )
                ");
                // Οι προεπιλογές διατηρούν τη μέχρι τώρα συμπεριφορά της εφαρμογής
                // (ελληνικά, φωτεινό θέμα, χωρίς προεπιλεγμένο φίλτρο).
                $ins = $pdo->prepare('INSERT OR IGNORE INTO app_settings (key, value) VALUES (?, ?)');
                foreach (['language' => 'el', 'theme' => 'light', 'default_status' => '', 'default_category' => ''] as $k => $v) {
                    $ins->execute([$k, $v]);
                }
            },
        ],
        3 => [
            'name' => 'subscriptions.kind (subscription | recurring payment)',
            'up'   => function (PDO $pdo): void {
                // Οι υπάρχουσες εγγραφές γίνονται αυτόματα 'subscription' (DEFAULT).
                $cols = array_column($pdo->query('PRAGMA table_info(subscriptions)')->fetchAll(), 'name');
                if (!in_array('kind', $cols, true)) {
                    $pdo->exec("ALTER TABLE subscriptions ADD COLUMN kind TEXT NOT NULL DEFAULT 'subscription' CHECK(kind IN ('subscription','recurring'))");
                }
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subscriptions_kind ON subscriptions(kind)');
            },
        ],
        4 => [
            'name' => 'subscriptions.total_installments + status paid_off (table rebuild, data preserved)',
            'own_transaction' => true,
            'up'   => 'migrateInstallmentsAndPaidOff',
        ],
    ];
}

/**
 * Προσθέτει τη στήλη total_installments και την κατάσταση 'paid_off'.
 * Το SQLite δεν αλλάζει CHECK constraints με ALTER, οπότε ο πίνακας ξαναχτίζεται
 * (τυπική διαδικασία SQLite): τα foreign keys κλείνουν προσωρινά ώστε τα παιδικά
 * records (τιμές, παγώματα, πληρωμές) να ΜΗΝ διαγραφούν από το DROP TABLE.
 */
function migrateInstallmentsAndPaidOff(PDO $pdo): void
{
    $cols = 'id, name, category, frequency, payment_method, start_date, status, canceled_date, notes, created_at, updated_at, kind';

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();

        $pdo->exec('DROP TABLE IF EXISTS subscriptions_new');
        $pdo->exec("
            CREATE TABLE subscriptions_new (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                name               TEXT NOT NULL,
                category           TEXT NOT NULL,
                frequency          TEXT NOT NULL CHECK(frequency IN ('weekly','monthly','half-yearly','yearly','biennial','triennial')),
                payment_method     TEXT,
                start_date         TEXT NOT NULL,
                status             TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','trial','frozen','canceled','paid_off')),
                canceled_date      TEXT,
                notes              TEXT,
                created_at         TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at         TEXT NOT NULL DEFAULT (datetime('now')),
                kind               TEXT NOT NULL DEFAULT 'subscription' CHECK(kind IN ('subscription','recurring')),
                total_installments INTEGER CHECK(total_installments IS NULL OR total_installments >= 1)
            )
        ");
        $pdo->exec("INSERT INTO subscriptions_new ($cols) SELECT $cols FROM subscriptions");

        $after = (int) $pdo->query('SELECT COUNT(*) FROM subscriptions_new')->fetchColumn();
        if ($before !== $after) {
            throw new RuntimeException("Migration aborted: row count mismatch ($before vs $after)");
        }

        $pdo->exec('DROP TABLE subscriptions');
        $pdo->exec('ALTER TABLE subscriptions_new RENAME TO subscriptions');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subscriptions_kind ON subscriptions(kind)');

        if ($pdo->query('PRAGMA foreign_key_check')->fetch()) {
            throw new RuntimeException('Migration aborted: foreign key check failed');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

function runMigrations(PDO $pdo, bool $backupExisting = false): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            version    INTEGER PRIMARY KEY,
            name       TEXT NOT NULL,
            applied_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $applied = array_map('intval', $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    $pending = array_diff_key(getMigrations(), array_flip($applied));
    if (empty($pending)) {
        return;
    }
    ksort($pending);

    if ($backupExisting) {
        backupDatabaseFile();
    }

    foreach ($pending as $version => $migration) {
        // Τα migrations 1 και 4 χειρίζονται μόνα τους τα transactions/foreign keys.
        if ($version === 1 || !empty($migration['own_transaction'])) {
            call_user_func($migration['up'], $pdo);
        } else {
            $pdo->beginTransaction();
            try {
                call_user_func($migration['up'], $pdo);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
        $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (version, name) VALUES (?, ?)')
            ->execute([$version, $migration['name']]);
    }
}

/** Αντίγραφο ασφαλείας της υπάρχουσας βάσης πριν από οποιαδήποτε αναβάθμιση schema. */
function backupDatabaseFile(): void
{
    if (!defined('DB_PATH') || !is_file(DB_PATH) || filesize(DB_PATH) === 0) {
        return;
    }
    $dir = dirname(DB_PATH) . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @copy(DB_PATH, $dir . '/' . basename(DB_PATH, '.sqlite') . '-' . date('Ymd-His') . '.sqlite');
}
