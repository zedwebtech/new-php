<?php
/**
 * Subscription engine — plans catalogue, customer subscriptions, unique
 * customer-ID generation, fulfilment (record + receipt/certificate PDFs +
 * confirmation email).  Included from functions.php so the schema self-heals
 * on boot (cheap, statically guarded).
 *
 * Plans come from the "sub maven.pdf" spec: Quick Fix (one-time), Starter
 * Care (1 yr), Pro Shield (3 yr), Lifetime Elite (10 yr).  Admin sets the
 * USD price for each in Admin → Subscription.
 */

if (!function_exists('sub_migrate')) {

function sub_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(64) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            tagline VARCHAR(255) NOT NULL DEFAULT '',
            tenure_label VARCHAR(64) NOT NULL DEFAULT '',
            duration_months INT NOT NULL DEFAULT 0,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            devices VARCHAR(40) NOT NULL DEFAULT '',
            features_json TEXT,
            sort_order INT NOT NULL DEFAULT 100,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id VARCHAR(32) NOT NULL DEFAULT '',
            order_id INT NULL,
            order_number VARCHAR(40) NOT NULL DEFAULT '',
            plan_slug VARCHAR(64) NOT NULL DEFAULT '',
            plan_name VARCHAR(120) NOT NULL DEFAULT '',
            customer_name VARCHAR(160) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            phone VARCHAR(48) NOT NULL DEFAULT '',
            country VARCHAR(8) NOT NULL DEFAULT 'US',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            gateway VARCHAR(20) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            start_date DATE NULL,
            end_date DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_email (email), KEY idx_plan (plan_slug),
            KEY idx_cust (customer_id), KEY idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // orders.subscription_plan — set during a subscription checkout so
        // fulfil_order knows to run the subscription path instead of keys.
        try { $pdo->exec("ALTER TABLE orders ADD COLUMN subscription_plan VARCHAR(64) DEFAULT NULL"); }
        catch (Throwable $e) { /* already exists */ }

        // Assignment + notes (department / handler / running note log).
        try { $pdo->exec("ALTER TABLE customer_subscriptions ADD COLUMN assigned_department VARCHAR(40) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_subscriptions ADD COLUMN assigned_user_id INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE subscription_plans ADD COLUMN icon_image VARCHAR(500) DEFAULT NULL"); } catch (Throwable $e) {}
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NOT NULL,
            department VARCHAR(40) NOT NULL DEFAULT '',
            author_user_id INT DEFAULT NULL,
            author_name VARCHAR(120) NOT NULL DEFAULT '',
            note TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sub (subscription_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Seed the four plans once (prices left at 0.00 for the admin to set).
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM subscription_plans")->fetchColumn();
        if ($cnt === 0) {
            $seed = sub_seed_data();
            $ins = $pdo->prepare("INSERT INTO subscription_plans
                (slug, name, tagline, tenure_label, duration_months, price, devices, features_json, sort_order, active)
                VALUES (?,?,?,?,?,?,?,?,?,1)");
            foreach ($seed as $i => $p) {
                $ins->execute([
                    $p['slug'], $p['name'], $p['tagline'], $p['tenure_label'],
                    $p['duration_months'], 0.00, $p['devices'],
                    json_encode($p['features']), ($i + 1) * 10,
                ]);
            }
        }

        // Backfill subscription tier icons (cloud-hosted URLs so they survive
        // pod restarts / reseeds).  Only sets a row's icon when it's missing.
        $iconUpd = $pdo->prepare("UPDATE subscription_plans SET icon_image=? WHERE slug=? AND (icon_image IS NULL OR icon_image='')");
        foreach (sub_seed_data() as $sp) {
            if (!empty($sp['icon_image'])) $iconUpd->execute([$sp['icon_image'], $sp['slug']]);
        }
    } catch (Throwable $e) { /* fresh-install timing — retry next boot */ }
}

/** Canonical seed definitions for the four plans (features from the PDF). */
function sub_seed_data(): array
{
    return [
        [
            'slug' => 'quick-fix', 'name' => 'Quick Fix',
            'icon_image' => '/assets/images/subscriptions/plan-1.png',
            'tagline' => 'One-time service · single session',
            'tenure_label' => 'One-Time Service', 'duration_months' => 0, 'devices' => '1 Device',
            'features' => [
                'Immediate issue resolution', 'Virus and malware removal', 'PC performance optimization',
                'Software installation and setup', 'Printer and peripheral configuration',
                'Email setup and troubleshooting', 'Internet and Wi-Fi troubleshooting',
                'Operating system error fixes', 'Driver updates', 'Browser issues and cleanup',
                'Basic data backup assistance', 'Microsoft Office troubleshooting', 'One-time security health check',
            ],
        ],
        [
            'slug' => 'starter-care', 'name' => 'Starter Care',
            'icon_image' => '/assets/images/subscriptions/plan-2.png',
            'tagline' => 'Unlimited remote support for 1 year',
            'tenure_label' => '1 Year', 'duration_months' => 12, 'devices' => '1 Device',
            'features' => [
                'Unlimited remote support for 1 year', 'Unlimited software troubleshooting',
                'Operating system support', 'Email and account assistance', 'Security and antivirus support',
                'Device health checks', 'Performance tune-ups', 'Software updates assistance',
                'Printer and scanner support', 'Browser and application support',
                'New software installation assistance', 'Data backup guidance', 'Monthly maintenance recommendations',
            ],
        ],
        [
            'slug' => 'pro-shield', 'name' => 'Pro Shield',
            'icon_image' => '/assets/images/subscriptions/plan-3.png',
            'tagline' => 'Transferable protection · up to 3 devices',
            'tenure_label' => '3 Years', 'duration_months' => 36, 'devices' => 'Up to 3 Devices',
            'features' => [
                'Transferable device protection', 'Device replacement enrollment',
                'Advanced malware and security support', 'Network and Wi-Fi optimization',
                'Multi-device maintenance', 'Priority support queue', 'Annual security audits',
                'Cloud storage setup assistance', 'Advanced software troubleshooting',
                'Device migration support', 'Operating system upgrade assistance', 'Productivity software support',
            ],
        ],
        [
            'slug' => 'lifetime-elite', 'name' => 'Lifetime Elite',
            'icon_image' => '/assets/images/subscriptions/plan-4.png',
            'tagline' => '10 years support · unlimited devices',
            'tenure_label' => '10 Years Support', 'duration_months' => 120, 'devices' => 'Unlimited Devices',
            'features' => [
                'Unlimited device coverage', 'Unlimited device transfers', 'Premium priority support',
                'Dedicated support specialists', 'Comprehensive security assistance',
                'Advanced malware and ransomware guidance', 'New device onboarding assistance',
                'Device replacement support', 'Remote setup for computers, printers, and peripherals',
                'Cloud account support', 'Data migration assistance', 'System optimization services',
                'Annual technology health reviews', 'Personalized technical guidance',
                'Priority scheduling', 'Family and business device support',
            ],
        ],
    ];
}
sub_migrate();

/** All plans (optionally only active), ordered. */
function sub_plans(bool $activeOnly = false): array
{
    try {
        $sql = "SELECT * FROM subscription_plans " . ($activeOnly ? "WHERE active=1 " : "") . "ORDER BY sort_order ASC, id ASC";
        $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) { $r['features'] = json_decode((string)$r['features_json'], true) ?: []; }
        return $rows;
    } catch (Throwable $e) { return []; }
}

/** One plan by slug. */
function sub_plan_get(string $slug): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM subscription_plans WHERE slug=? LIMIT 1");
        $st->execute([$slug]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['features'] = json_decode((string)$r['features_json'], true) ?: [];
        return $r;
    } catch (Throwable $e) { return null; }
}

/** Map an order to the MVN country code (US / CA / UK / AU / EU).
 *  Prefers the customer's chosen billing country, then the store region. */
function sub_country_code(array $order): string
{
    $map = ['GB' => 'UK', 'EN' => 'UK', 'AUS' => 'AU', 'USA' => 'US', 'CAN' => 'CA'];
    foreach ([(string)($order['country'] ?? ''), (string)($order['region'] ?? '')] as $raw) {
        $cc = strtoupper(trim($raw));
        if ($cc === '') continue;
        $cc = $map[$cc] ?? $cc;
        if (in_array($cc, ['US', 'CA', 'UK', 'AU', 'EU'], true)) return $cc;
    }
    return 'US';
}

/**
 * Create the customer_subscriptions record for a paid subscription order and
 * generate the unique customer ID (MVN + country + zero-padded sequence,
 * e.g. MVNUS00001).  Returns the row, or an existing one if already created.
 */
function sub_record_for_order(array $order): ?array
{
    $pdo = db();
    $orderId = (int)($order['id'] ?? 0);
    if ($orderId) {
        $ex = $pdo->prepare("SELECT * FROM customer_subscriptions WHERE order_id=? LIMIT 1");
        $ex->execute([$orderId]);
        if ($row = $ex->fetch(PDO::FETCH_ASSOC)) return $row;
    }
    $plan = sub_plan_get((string)($order['subscription_plan'] ?? ''));
    if (!$plan) return null;

    $cc        = sub_country_code($order);
    $start     = date('Y-m-d');
    $months    = (int)$plan['duration_months'];
    $end       = $months > 0 ? date('Y-m-d', strtotime("+{$months} months")) : null;
    $name      = trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? ''));
    $gateway   = (string)($order['payment_method'] ?? '');

    $ins = $pdo->prepare("INSERT INTO customer_subscriptions
        (order_id, order_number, plan_slug, plan_name, customer_name, email, phone, country,
         amount, currency, gateway, status, start_date, end_date)
        VALUES (?,?,?,?,?,?,?,?,?,?,?, 'active', ?, ?)");
    $ins->execute([
        $orderId, (string)($order['order_number'] ?? ''), $plan['slug'], $plan['name'],
        $name, (string)($order['email'] ?? ''), (string)($order['phone'] ?? ''), $cc,
        (float)($order['total'] ?? $plan['price']), (string)($order['currency'] ?? 'USD'),
        $gateway, $start, $end,
    ]);
    $id = (int)$pdo->lastInsertId();
    $prefix = strtoupper((string)(company_info()['id_prefix'] ?? 'MVN')) ?: 'MVN';
    $customerId = $prefix . $cc . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE customer_subscriptions SET customer_id=? WHERE id=?")->execute([$customerId, $id]);

    $st = $pdo->prepare("SELECT * FROM customer_subscriptions WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Build a one-line tenure / dates string for emails + PDFs. */
function sub_tenure_text(array $sub, array $plan): string
{
    if ((int)$plan['duration_months'] === 0) {
        return $plan['tenure_label'] . ' (single session on ' . date('F j, Y', strtotime((string)$sub['start_date'])) . ')';
    }
    return $plan['tenure_label'] . ' · ' . date('F j, Y', strtotime((string)$sub['start_date']))
         . ' → ' . date('F j, Y', strtotime((string)$sub['end_date']));
}

/**
 * Generate the Receipt + Subscription Certificate PDFs for a subscription
 * order and return their file paths (for email attachment).
 */
/** "What's included" description block appended to the subscription receipt PDF. */
function sub_receipt_extra_html(array $sub, array $plan): string
{
    $feat = '';
    foreach (($plan['features'] ?? []) as $f) {
        $feat .= '<tr><td style="padding:2px 0;color:#047857;width:16px;">&#10003;</td><td style="padding:2px 0;color:#334155;">'
               . htmlspecialchars((string)$f, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    $tenure = htmlspecialchars(sub_tenure_text($sub, $plan), ENT_QUOTES, 'UTF-8');
    $custId = htmlspecialchars((string)($sub['customer_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $devices= htmlspecialchars((string)($plan['devices'] ?? ''), ENT_QUOTES, 'UTF-8');
    $tag    = htmlspecialchars((string)($plan['tagline'] ?? ''), ENT_QUOTES, 'UTF-8');
    return '<div style="margin-top:18px;padding-top:12px;border-top:1px solid #e2e8f0;">'
         . '<div style="font-weight:700;color:#0f172a;font-size:11pt;margin-bottom:4px;">Your subscription</div>'
         . '<div style="font-size:9.5pt;color:#475569;margin-bottom:4px;">' . $tag . '</div>'
         . '<table style="width:100%;border-collapse:collapse;font-size:9.5pt;margin-bottom:8px;">'
         . '<tr><td style="color:#64748b;width:130px;padding:2px 0;">Customer ID</td><td style="font-weight:700;padding:2px 0;">' . $custId . '</td></tr>'
         . '<tr><td style="color:#64748b;padding:2px 0;">Coverage</td><td style="padding:2px 0;">' . $devices . '</td></tr>'
         . '<tr><td style="color:#64748b;padding:2px 0;">Tenure</td><td style="padding:2px 0;">' . $tenure . '</td></tr>'
         . '</table>'
         . '<div style="font-weight:700;color:#0f172a;font-size:10pt;margin-bottom:2px;">What\'s included</div>'
         . '<table style="width:100%;border-collapse:collapse;font-size:9pt;">' . $feat . '</table>'
         . '</div>';
}

function sub_pdf_paths(array $order, array $sub, array $plan): array
{
    require_once __DIR__ . '/pdf.php';
    $dir = __DIR__ . '/../uploads/order-pdfs/' . (int)($order['id'] ?? 0);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $paths = [];

    $items = [[
        'name'       => $plan['name'] . ' Subscription (' . $plan['tenure_label'] . ')',
        'unit_price' => (float)($sub['amount'] ?? $plan['price']),
        'quantity'   => 1,
        'price'      => (float)($sub['amount'] ?? $plan['price']),
        'qty'        => 1,
    ]];

    // 1) Receipt — standard branded receipt + the full plan description block.
    try {
        $payment = [
            'method' => ucfirst((string)($sub['gateway'] ?: 'card')),
            'date'   => date('F j, Y', strtotime((string)($order['created_at'] ?? 'now'))),
        ];
        $rPath = $dir . '/Receipt-' . (string)($order['order_number'] ?? 'SUB') . '.pdf';
        @file_put_contents($rPath, generate_receipt_pdf($order, $items, $payment, sub_receipt_extra_html($sub, $plan)));
        if (is_file($rPath)) $paths[] = $rPath;
    } catch (Throwable $e) { @error_log('[sub receipt pdf] ' . $e->getMessage()); }

    // 2) Invoice — itemised tax invoice for the subscription.
    try {
        $iPath = $dir . '/Invoice-' . (string)($order['order_number'] ?? 'SUB') . '.pdf';
        @file_put_contents($iPath, generate_invoice_pdf($order, $items));
        if (is_file($iPath)) $paths[] = $iPath;
    } catch (Throwable $e) { @error_log('[sub invoice pdf] ' . $e->getMessage()); }

    // 3) Subscription details certificate.
    try {
        $cPath = $dir . '/Subscription-Details-' . (string)($sub['customer_id'] ?? 'MVN') . '.pdf';
        @file_put_contents($cPath, sub_generate_certificate_pdf($order, $sub, $plan));
        if (is_file($cPath)) $paths[] = $cPath;
    } catch (Throwable $e) { @error_log('[sub certificate pdf] ' . $e->getMessage()); }

    return $paths;
}

/** Subscription certificate PDF (binary string) — branded via _pdf_shell. */
function sub_generate_certificate_pdf(array $order, array $sub, array $plan): string
{
    require_once __DIR__ . '/pdf.php';
    $co  = function_exists('company_info') ? company_info() : ['name' => 'Maventech Software'];
    $cur = (string)($sub['currency'] ?? 'USD');

    $featRows = '';
    foreach (($plan['features'] ?? []) as $f) {
        $featRows .= '<tr><td style="padding:3px 0;color:#047857;width:18px;">&#10003;</td><td style="padding:3px 0;color:#334155;">'
                   . htmlspecialchars((string)$f, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    $rows = [
        ['Customer ID',   (string)$sub['customer_id']],
        ['Plan',          $plan['name'] . ' — ' . $plan['tagline']],
        ['Coverage',      (string)$plan['devices']],
        ['Tenure',        sub_tenure_text($sub, $plan)],
        ['Order number',  (string)($order['order_number'] ?? '')],
        ['Amount paid',   _pdf_money((float)($sub['amount'] ?? 0), $cur)],
        ['Payment method',ucfirst((string)($sub['gateway'] ?: 'card'))],
        ['Status',        ucfirst((string)($sub['status'] ?? 'active'))],
    ];
    $detailRows = '';
    foreach ($rows as $r) {
        $detailRows .= '<tr><td style="padding:6px 0;color:#64748b;width:150px;">' . htmlspecialchars($r[0], ENT_QUOTES, 'UTF-8')
                     . '</td><td style="padding:6px 0;color:#0f172a;font-weight:700;">' . htmlspecialchars($r[1], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    $bodyHtml = '<div class="amount-banner">
            <div class="amt">' . htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') . ' — Active Subscription</div>
            <div class="sub">Customer ID <strong>' . htmlspecialchars((string)$sub['customer_id'], ENT_QUOTES, 'UTF-8') . '</strong> · keep this document for your records.</div>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:10.5pt;margin:6px 0 14px;">' . $detailRows . '</table>
        <div style="font-weight:700;color:#0f172a;margin:6px 0 6px;font-size:11pt;">What\'s included in your ' . htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') . ' plan</div>
        <table style="width:100%;border-collapse:collapse;font-size:9.5pt;">' . $featRows . '</table>';

    // Company information block (with toll-free) so the customer always has
    // our contact details on the downloaded subscription document.
    $cName = htmlspecialchars((string)($co['name']    ?? 'Maventech Software'), ENT_QUOTES, 'UTF-8');
    $cAddr = htmlspecialchars((string)($co['address'] ?? ''), ENT_QUOTES, 'UTF-8');
    $cPh   = htmlspecialchars((string)(function_exists('company_phone_for_country') ? company_phone_for_country($order['country'] ?? null) : ($co['phone'] ?? (defined('SITE_PHONE') ? SITE_PHONE : ''))), ENT_QUOTES, 'UTF-8');
    $cEm   = htmlspecialchars((string)($co['email']   ?? ''), ENT_QUOTES, 'UTF-8');
    $cWeb  = htmlspecialchars((string)($co['website'] ?? (function_exists('site_url') ? site_url() : '')), ENT_QUOTES, 'UTF-8');
    $bodyHtml .= '<div style="margin-top:16px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:9.5pt;color:#334155;">'
        . '<div style="font-weight:700;color:#0f172a;margin-bottom:4px;font-size:10.5pt;">' . $cName . ' — Support &amp; Company Information</div>'
        . ($cPh   ? '<div><strong>Toll-free / Support:</strong> ' . $cPh . '</div>' : '')
        . ($cEm   ? '<div><strong>Email:</strong> ' . $cEm . '</div>' : '')
        . ($cWeb  ? '<div><strong>Website:</strong> ' . $cWeb . '</div>' : '')
        . ($cAddr ? '<div><strong>Address:</strong> ' . $cAddr . '</div>' : '')
        . '<div style="margin-top:6px;color:#64748b;">Quote your Customer ID <strong>' . htmlspecialchars((string)$sub['customer_id'], ENT_QUOTES, 'UTF-8') . '</strong> whenever you contact support.</div>'
        . '</div>';

    $html = _pdf_shell([
        'co'             => $co,
        'logo'           => _pdf_company_logo_path(),
        'title'          => 'Subscription Details',
        'invoice_number' => (string)($order['order_number'] ?? ''),
        'receipt_number' => (string)$sub['customer_id'],
        'date_paid'      => date('F j, Y', strtotime((string)($sub['start_date'] ?? 'now'))),
        'bill_to'        => array_filter([
            (string)$sub['customer_name'], (string)$sub['email'], (string)$sub['phone'],
        ], fn($l) => trim((string)$l) !== ''),
        'brand_key'      => '',
        'first_name'     => (string)($order['first_name'] ?? ''),
        'stamp_text'     => 'ACTIVE',
        'stamp_color'    => '#047857',
        'qr_data_uri'    => '',
    ], $bodyHtml);

    $dompdf = _pdf_dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/** Send the subscription confirmation email with both PDFs attached. */
function sub_send_confirmation(array $order, array $sub, array $plan): void
{
    $co     = function_exists('company_info') ? company_info() : [];
    $brand  = $co['name']  ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
    $phone  = (function_exists('company_phone_for_country') ? company_phone_for_country($order['country'] ?? null) : ($co['phone'] ?? '')) ?: (defined('SITE_PHONE') ? SITE_PHONE : '');
    $email  = $co['email'] ?? '';
    $cur    = (string)($sub['currency'] ?? 'USD');
    $first  = htmlspecialchars((string)($order['first_name'] ?? '') ?: 'there', ENT_QUOTES, 'UTF-8');
    $custId = htmlspecialchars((string)$sub['customer_id'], ENT_QUOTES, 'UTF-8');
    $tenure = htmlspecialchars(sub_tenure_text($sub, $plan), ENT_QUOTES, 'UTF-8');
    $amount = function_exists('_pdf_money') ? _pdf_money((float)$sub['amount'], $cur) : ('$' . number_format((float)$sub['amount'], 2));
    $planNm = htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8');
    $devices= htmlspecialchars((string)$plan['devices'], ENT_QUOTES, 'UTF-8');
    $gw     = htmlspecialchars(ucfirst((string)($sub['gateway'] ?: 'card')), ENT_QUOTES, 'UTF-8');
    $ordNo  = htmlspecialchars((string)($order['order_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $brandE = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
    $phoneE = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
    $emailE = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    // Bill-To (customer billing address from the order)
    $billName = htmlspecialchars(trim(((string)($order['first_name'] ?? '')) . ' ' . ((string)($order['last_name'] ?? ''))) ?: (string)$sub['customer_name'], ENT_QUOTES, 'UTF-8');
    $billLines = array_filter([
        trim((string)($order['address'] ?? '')),
        trim(implode(', ', array_filter([(string)($order['city'] ?? ''), (string)($order['state'] ?? ''), (string)($order['zip'] ?? '')]))),
        trim((string)($order['country'] ?? '')),
    ]);
    $billHtml = $billName . ($billLines ? '<br>' . implode('<br>', array_map(fn($l) => htmlspecialchars($l, ENT_QUOTES, 'UTF-8'), $billLines)) : '');
    $billEmail = htmlspecialchars((string)($order['email'] ?? $sub['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $coAddr = htmlspecialchars((string)($co['address'] ?? ''), ENT_QUOTES, 'UTF-8');

    // Payment statement descriptor (what shows on the card statement)
    $gwRaw = strtolower((string)($sub['gateway'] ?: 'card'));
    $descriptor = trim((string)($order['card_statement_name'] ?? ''));
    if ($descriptor === '') $descriptor = $gwRaw === 'paypal' ? (string)setting_get('statement_name_paypal', '') : (string)setting_get('statement_name_card', '');
    if ($descriptor === '') $descriptor = $brand;
    $descriptorE = htmlspecialchars($descriptor, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">
  <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:22px 26px;border-radius:12px 12px 0 0;color:#fff;">
    <div style="font-size:11px;letter-spacing:.14em;font-weight:800;text-transform:uppercase;opacity:.85;">{$brandE} — Subscription</div>
    <div style="font-size:22px;font-weight:800;margin-top:4px;">You're all set, {$first}! 🎉</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 12px 12px;padding:26px;line-height:1.55;">
    <p style="margin:0 0 16px;font-size:14px;">Thank you for subscribing to <strong>{$planNm}</strong>. Your subscription is now active. Your <strong>paid invoice</strong>, receipt and subscription certificate are attached as PDFs.</p>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin:4px 0 18px;">
      <tr><td style="padding:7px 0;color:#64748b;width:150px;">Your Customer ID</td><td style="padding:7px 0;font-weight:800;color:#1e3a8a;font-family:ui-monospace,Menlo,monospace;">{$custId}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Plan</td><td style="padding:7px 0;font-weight:700;">{$planNm}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Coverage</td><td style="padding:7px 0;">{$devices}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Subscription period</td><td style="padding:7px 0;">{$tenure}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Order number</td><td style="padding:7px 0;">{$ordNo}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Amount paid</td><td style="padding:7px 0;font-weight:700;">{$amount}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Payment method</td><td style="padding:7px 0;">{$gw}</td></tr>
    </table>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin:0 0 16px;">
      <div style="flex:1;min-width:210px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;">
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;font-weight:700;margin-bottom:5px;">Billed to</div>
        <div style="font-size:13px;color:#0f172a;line-height:1.5;">{$billHtml}<br><span style="color:#64748b;">{$billEmail}</span></div>
      </div>
      <div style="flex:1;min-width:210px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;">
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;font-weight:700;margin-bottom:5px;">From</div>
        <div style="font-size:13px;color:#0f172a;line-height:1.5;">{$brandE}<br><span style="color:#64748b;">{$coAddr}</span></div>
      </div>
    </div>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:11px 14px;margin:0 0 16px;font-size:13px;color:#166534;">
      <span style="display:inline-block;background:#16a34a;color:#fff;font-weight:800;font-size:11px;padding:2px 9px;border-radius:5px;letter-spacing:.06em;margin-right:8px;">PAID</span>
      {$amount} via {$gw} · Charge appears as <strong>{$descriptorE}</strong> on your statement.
    </div>
    <div style="background:#fff7ed;border:1px solid #fdba74;border-left:4px solid #f59e0b;border-radius:10px;padding:11px 15px;margin:0 0 16px;font-size:13px;color:#7c2d12;">
      <strong style="color:#7c2d12;">Billing note:</strong> this charge appears as <strong style="background:#fde68a;color:#9a3412;padding:2px 8px;border-radius:5px;">{$descriptorE}</strong> on your card statement.
    </div>
    <div style="background:#eff6ff;border-left:4px solid #2563eb;border-radius:8px;padding:12px 16px;font-size:13px;color:#1e3a8a;">
      Need help? Our support team is here for you. Call <strong>{$phoneE}</strong> or email <a href="mailto:{$emailE}" style="color:#1d4ed8;">{$emailE}</a> and quote your Customer ID <strong>{$custId}</strong>.
    </div>
    <p style="margin:20px 0 0;font-size:12px;color:#94a3b8;">{$brandE}<br>{$coAddr}<br>This is an automated confirmation for your subscription purchase.</p>
  </div>
</div>
HTML;

    $subject = $brand . ' — ' . $plan['name'] . ' subscription confirmed (' . (string)$sub['customer_id'] . ')';
    $pdfPaths = sub_pdf_paths($order, $sub, $plan);
    send_email((string)$order['email'], $subject, $html, (int)($order['id'] ?? 0) ?: null, 'subscription_confirm', 0, $pdfPaths);
}

/**
 * Fulfilment entry point for a subscription order — called from
 * fulfill_order().  Creates the record, generates the customer ID, emails
 * the confirmation + PDFs, notifies the admin, and marks the order fulfilled.
 */
function sub_fulfill_order(array $order): void
{
    $pdo = db();
    $sub = sub_record_for_order($order);
    if (!$sub) return;
    $plan = sub_plan_get((string)$sub['plan_slug']);
    if (!$plan) return;

    try { sub_send_confirmation($order, $sub, $plan); }
    catch (Throwable $e) { @error_log('[sub confirmation email] ' . $e->getMessage()); }

    // Notify the COMPANY (Company Info email) of the subscription sale, with the
    // Receipt + Invoice + Subscription Details PDFs attached.
    try {
        if (function_exists('notify_company_of_sale')) {
            $pdfPaths = sub_pdf_paths($order, $sub, $plan);
            $items = [[
                'name'  => $plan['name'] . ' Subscription (' . $plan['tenure_label'] . ')',
                'qty'   => 1,
                'price' => (float)($sub['amount'] ?? $plan['price']),
            ]];
            $co = array_merge($order, [
                'order_number'   => (string)($sub['order_number'] ?? $order['order_number'] ?? ''),
                'currency'       => (string)($sub['currency'] ?? 'USD'),
                'total'          => (float)($sub['amount'] ?? 0),
                'payment_method' => (string)($sub['gateway'] ?? $order['payment_method'] ?? 'card'),
                'customer_name'  => (string)$sub['customer_name'],
                'email'          => (string)$sub['email'],
                'phone'          => (string)$sub['phone'],
            ]);
            notify_company_of_sale($co, $items, $pdfPaths, 'subscription');
        }
    } catch (Throwable $e) { @error_log('[sub company notify] ' . $e->getMessage()); }

    // Admin bell — new subscription sale.
    try {
        admin_notify(
            'order',
            'New subscription — ' . $plan['name'],
            trim((string)$sub['customer_name']) . ' · ' . (string)$sub['customer_id']
                . ' · ' . (function_exists('_pdf_money') ? _pdf_money((float)$sub['amount'], (string)$sub['currency']) : (string)$sub['amount']),
            '/admin.php?tab=subscription&sub=subscribers&view=' . (int)$sub['id']
        );
    } catch (Throwable $e) { /* best-effort */ }

    $pdo->prepare('UPDATE orders SET fulfilled = 1, status = IF(status<>"paid","paid",status) WHERE id = ?')
        ->execute([(int)$order['id']]);
}

} // function_exists guard
