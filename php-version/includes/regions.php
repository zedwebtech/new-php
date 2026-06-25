<?php
// Region helpers — multi-region inventory + per-region pricing/tax/currency.

/**
 * Auto-bootstrap the `regions` and `settings` tables on first request.
 * Runs at most once per PHP process. Protects against partial/legacy
 * database.sql imports on shared hosting (cPanel/phpMyAdmin) where the
 * admin uploaded an older dump that didn't include these tables.
 */
function regions_bootstrap(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        // settings (Company Info, statement names, active_region, etc.)
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(80) NOT NULL PRIMARY KEY,
            v MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // regions
        $pdo->exec("CREATE TABLE IF NOT EXISTS regions (
            code VARCHAR(8) NOT NULL PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            currency VARCHAR(8) NOT NULL,
            currency_symbol VARCHAR(4) NOT NULL,
            tax_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Seed default regions if the table is empty
        $count = (int)$pdo->query("SELECT COUNT(*) FROM regions")->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO regions (code, name, currency, currency_symbol, tax_rate, active) VALUES
                ('US', 'United States',  'USD', '$',  0.0000, 1),
                ('UK', 'United Kingdom', 'GBP', '£',  0.2000, 1),
                ('CA', 'Canada',         'CAD', 'C$', 0.1300, 1),
                ('EU', 'Europe',         'EUR', '€',  0.2000, 0)");
        }

        // products / orders / license_keys / customer_reviews need a `region` column.
        // Detect via INFORMATION_SCHEMA so this works on MySQL 5.6 / 5.7 / 8 / MariaDB.
        $needs = [
            'products'     => "VARCHAR(8) NOT NULL DEFAULT 'US'",
            'orders'       => "VARCHAR(8) NOT NULL DEFAULT 'US'",
            'license_keys' => "VARCHAR(8) NOT NULL DEFAULT 'US'",
        ];
        foreach ($needs as $table => $colDef) {
            try {
                // Skip if the table itself doesn't exist on this host.
                $tableExists = $pdo->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
                );
                $tableExists->execute([$table]);
                if (!(int)$tableExists->fetchColumn()) continue;

                $colExists = $pdo->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'region'"
                );
                $colExists->execute([$table]);
                if (!(int)$colExists->fetchColumn()) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `region` $colDef");
                }
            } catch (Throwable $e) { /* ignore — keep going */ }
        }

        // Stock-notification subscribers (back-in-stock alerts)
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_slug VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            region VARCHAR(8) NOT NULL DEFAULT 'US',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notified_at DATETIME NULL DEFAULT NULL,
            KEY idx_pending (product_slug, region, notified_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (Throwable $e) {
        // DB not reachable yet or insufficient privileges — silently skip,
        // the page will surface the underlying error normally.
    }
}
regions_bootstrap();

function active_region_code(): string {
    if (isset($_GET['region'])) {
        $r = strtoupper(preg_replace('/[^A-Z]/i', '', $_GET['region']));
        if ($r) {
            $_SESSION['region'] = $r;
            setting_set('active_region', $r);
        }
    }
    return $_SESSION['region'] ?? setting_get('active_region', 'US');
}

function active_region(): array {
    $code = active_region_code();
    $row = db()->prepare('SELECT * FROM regions WHERE code = ? AND active = 1');
    $row->execute([$code]);
    $r = $row->fetch();
    if ($r) return $r;
    // Session region was deactivated — fall back to first available active region
    $fb = db()->query('SELECT * FROM regions WHERE active = 1 ORDER BY code LIMIT 1')->fetch();
    if ($fb) {
        $_SESSION['region'] = $fb['code'];
        return $fb;
    }
    return ['code'=>'US','name'=>'United States','currency'=>'USD','currency_symbol'=>'$','tax_rate'=>0,'active'=>1];
}

function all_regions(): array {
    return db()->query('SELECT * FROM regions WHERE active=1 ORDER BY code')->fetchAll();
}

/** SQL snippet that limits a query to products belonging to currently-active regions.
 *  Use it inside any public-facing product query, e.g.
 *      SELECT * FROM products WHERE region IN (SELECT code FROM regions WHERE active=1)
 *  When no region is active (edge case), the helper returns a clause that yields 0 rows
 *  so deactivated regions never leak through.
 */
function active_regions_sql_in(string $column = 'region'): string {
    return "$column IN (SELECT code FROM regions WHERE active=1)";
}

function region_money(float $amount): string {
    $r = active_region();
    return $r['currency_symbol'] . number_format($amount, 2);
}

function region_filter_sql(string $alias = ''): string {
    $pre = $alias === '' ? '' : ($alias . '.');
    return $pre . "region = " . db()->quote(active_region_code());
}

/** Static FX map (USD base). For production wire to live FX API. */
function region_rates(): array {
    return ['US' => 1.00, 'UK' => 0.79, 'CA' => 1.37, 'EU' => 0.92];
}

/** Convert a USD-stored price into the active region's currency value. */
function region_price(float $usd): float {
    $rates = region_rates();
    return $usd * ($rates[active_region_code()] ?? 1.0);
}

/** Format an originally-USD price into the active region's currency string. */
function region_money_from_usd(float $usd): string {
    return region_money(region_price($usd));
}
