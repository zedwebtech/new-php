<?php
/* =====================================================================
 *  SEO content helpers — long-tail keyword copy blocks, schema.org
 *  structured data, deep-link clusters and buying-guide content for
 *  product and category pages.
 *
 *  Why this file:
 *    - Modern Google + AI search engines (ChatGPT, Perplexity, Bing
 *      Chat, Google AI Overviews) reward pages with rich on-page copy,
 *      explicit schema.org metadata and tight internal link clusters.
 *    - These helpers generate that content per-product / per-category
 *      from the same database — every new product becomes an SEO
 *      landing page automatically.
 * ===================================================================== */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/email.php';

/* ------------------------------------------------------------------
 *  product_long_tail_keywords()
 *  Returns a dense comma-separated meta-keywords string targeting
 *  high-intent mid-tail and long-tail searches.
 * ----------------------------------------------------------------- */
function product_long_tail_keywords(array $p): string
{
    $name     = trim((string)$p['name']);
    $platform = $p['platform'] ?: 'Windows';
    $brand    = product_detected_brand($p);
    $year     = '';
    if (preg_match('/\b(20\d{2})\b/', $name, $m)) $year = $m[1];
    $base = trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $name));

    $kw = [
        $name,
        'buy ' . $name,
        $name . ' license key',
        $name . ' product key',
        $name . ' activation key',
        $name . ' digital download',
        $name . ' lifetime license',
        $name . ' one time purchase no subscription',
        $name . ' instant email delivery',
        'genuine ' . $name . ' license',
        'cheap ' . $name . ' license key',
        'discount ' . $name,
        $name . ' for ' . $platform,
        $name . ' for ' . $platform . ' download',
        $name . ' activation key online',
        'how to activate ' . $name,
        'how to download ' . $name,
        'where to buy ' . $name,
        'best price for ' . $name,
        'is ' . $name . ' a one time purchase',
        $brand . ' authorized reseller',
        $brand . ' genuine software',
    ];
    if ($year !== '') {
        $kw[] = $base . ' ' . $year . ' license key';
        $kw[] = $base . ' ' . $year . ' for ' . $platform;
        $kw[] = $base . ' ' . $year . ' lifetime activation';
        $kw[] = $name . ' new edition';
    }
    // Category-aware high-intent keyword libraries — auto-detects Office,
    // Windows OS, Microsoft Project/Visio, or Antivirus (Bitdefender /
    // McAfee / Norton / Kaspersky) and appends the matching broad / phrase
    // / exact match cluster.  Returns an empty array for uncategorised
    // SKUs (so nothing changes for products outside the four clusters).
    $kw = array_merge($kw, product_category_intent_keywords($p));
    return implode(', ', array_values(array_unique(array_filter($kw))));
}

/* ------------------------------------------------------------------
 *  product_detected_brand()
 *  Same brand-detection dictionary used in product.php — extracted
 *  here so other helpers can reuse it without duplication.
 * ----------------------------------------------------------------- */
function product_detected_brand(array $p): string
{
    $lookup = [
        'bitdefender' => 'Bitdefender', 'norton' => 'Norton', 'mcafee' => 'McAfee',
        'kaspersky'   => 'Kaspersky',   'eset'   => 'ESET',   'avast'  => 'Avast',
        'avg'         => 'AVG',         'webroot'=> 'Webroot','trend micro' => 'Trend Micro',
        'malwarebytes'=> 'Malwarebytes','adobe'  => 'Adobe',  'autocad'=> 'Autodesk',
        'autodesk'    => 'Autodesk',    'corel'  => 'Corel',  'parallels' => 'Parallels',
        'windows'     => 'Microsoft',   'office' => 'Microsoft','visio' => 'Microsoft',
        'project'     => 'Microsoft',   'microsoft' => 'Microsoft',
    ];
    $needle = strtolower((string)($p['name'] ?? ''));
    foreach ($lookup as $kw => $br) {
        if (strpos($needle, $kw) !== false) return $br;
    }
    return 'Microsoft';
}

/* ------------------------------------------------------------------
 *  office_edition_meta()
 *  Detects the Microsoft Office release year + edition + suite from
 *  the product name.  Used by every SEO helper that needs to inject
 *  intent-matched Office keywords (2024 / 2021 / 2019 Professional
 *  Plus / Home & Business / Home & Student / standalone Word / Excel).
 *
 *  Returns an associative array shape:
 *    [
 *      'is_office'   => bool,
 *      'year'        => '2024' | '2021' | '2019' | '',
 *      'edition'     => 'Professional Plus' | 'Home & Business' | 'Home & Student' | 'Word' | 'Excel' | '',
 *      'edition_key' => 'pro_plus' | 'home_business' | 'home_student' | 'word' | 'excel' | '',
 *      'platform'    => 'Windows' | 'Mac' | 'PC',
 *    ]
 * ----------------------------------------------------------------- */
function office_edition_meta(array $p): array
{
    $name   = strtolower((string)($p['name'] ?? ''));
    $isOfc  = (strpos($name, 'office') !== false
              || strpos($name, 'microsoft word') !== false
              || strpos($name, 'microsoft excel') !== false
              || strpos($name, 'microsoft powerpoint') !== false
              || strpos($name, 'microsoft outlook') !== false);
    $year = '';
    if (preg_match('/\b(2024|2021|2019|2016)\b/', $name, $m)) $year = $m[1];

    $edition    = '';
    $editionKey = '';
    if (strpos($name, 'professional plus') !== false || strpos($name, 'pro plus') !== false) {
        $edition = 'Professional Plus';   $editionKey = 'pro_plus';
    } elseif (strpos($name, 'home & business') !== false || strpos($name, 'home and business') !== false) {
        $edition = 'Home & Business';     $editionKey = 'home_business';
    } elseif (strpos($name, 'home & student') !== false || strpos($name, 'home and student') !== false) {
        $edition = 'Home & Student';      $editionKey = 'home_student';
    } elseif (strpos($name, 'home 2024') !== false || strpos($name, 'office home') !== false) {
        $edition = 'Home';                $editionKey = 'home';
    } elseif (strpos($name, 'word') !== false && strpos($name, 'office') === false) {
        $edition = 'Word';                $editionKey = 'word';
    } elseif (strpos($name, 'excel') !== false && strpos($name, 'office') === false) {
        $edition = 'Excel';               $editionKey = 'excel';
    } elseif (strpos($name, 'powerpoint') !== false && strpos($name, 'office') === false) {
        $edition = 'PowerPoint';          $editionKey = 'powerpoint';
    } elseif (strpos($name, 'outlook') !== false && strpos($name, 'office') === false) {
        $edition = 'Outlook';             $editionKey = 'outlook';
    }

    $platform = ($p['platform'] ?? '') ?: 'Windows';
    return [
        'is_office'   => $isOfc,
        'year'        => $year,
        'edition'     => $edition,
        'edition_key' => $editionKey,
        'platform'    => $platform,
    ];
}

/* ------------------------------------------------------------------
 *  office_intent_keywords()
 *  Curated high-intent / transactional Microsoft Office keyword
 *  library — broad match, phrase match and exact match clusters.
 *
 *  These keywords are appended to product_long_tail_keywords() and
 *  category_long_tail_keywords() whenever a Microsoft Office product
 *  or category is detected, ensuring meta keywords, JSON-LD `keywords`
 *  and AI-Article schemas all surface the high-converting intent.
 *
 *  Returns a flat de-duplicated array of phrases.  The caller decides
 *  whether to comma-join (meta keywords) or pipe-join (schema).
 * ----------------------------------------------------------------- */
function office_intent_keywords(array $meta): array
{
    if (empty($meta['is_office'])) return [];

    $year = $meta['year'] ?? '';
    $ed   = $meta['edition'] ?? '';

    // Universal Microsoft Office high-intent / transactional cluster.
    $universal = [
        // Broad / commercial intent
        'buy Microsoft Office lifetime license',
        'cheap Microsoft Office product key',
        'download Office Professional Plus legal copy',
        'Microsoft Office digital download instant delivery',
        'original Microsoft Office activation key',
        'Office for Windows PC full version',
        'Microsoft Office one time purchase',
        // Problem-solving / search intent
        'Microsoft Office without monthly subscription',
        'Office 2024 vs Office 2021 differences',
        'best place to buy Microsoft Office keys',
        'full version Office for Windows 11',
        'lifetime MS Office license for business',
    ];

    // Year-specific clusters
    $yearLib = [
        '2024' => [
            // Broad match
            'Microsoft Office 2024 Professional Plus Windows PC',
            'buy Office Home 2024 PC license',
            'Microsoft Office Home Business 2024 key Windows',
            // Phrase match
            'Microsoft Office 2024 Professional Plus product key',
            'buy Office 2024 lifetime license Windows',
            'Microsoft Office Home 2024 PC download',
            'Office Home and Business 2024 key',
            'latest Microsoft Office 2024 for Windows',
            'purchase Office 2024 Pro Plus genuine code',
            // Exact match
            'Microsoft Office 2024 Professional Plus',
            'Office 2024 Professional Plus lifetime license',
            'Microsoft Office Home 2024',
            'Microsoft Office Home & Business 2024',
            'Office 2024 product key',
            'Microsoft Office 2024 Professional Plus lifetime license Windows PC',
            'Microsoft Office Home 2024 (PC)',
            'Microsoft Office Home & Business 2024 (PC)',
            'buy Office 2024 lifetime license Windows PC',
        ],
        '2021' => [
            // Broad match
            'Microsoft Office 2021 Professional Plus download',
            'Office 2021 Home and Business Windows PC',
            'Microsoft Office 2021 Home and Student key',
            'standalone Microsoft Word 2021 product key',
            'standalone Microsoft Excel 2021 genuine license',
            'cheap Office 2021 license key PC',
            // Phrase match
            'Office 2021 Professional Plus Windows product key',
            'Microsoft Office 2021 Home Business download PC',
            'Microsoft Office 2021 Home Student Windows license',
            // Exact match
            'Microsoft Office 2021 Professional Plus',
            'Microsoft Office 2021 Home & Business',
            'Microsoft Office 2021 Home & Student',
            'Microsoft Word 2021',
            'Microsoft Excel 2021',
            'Microsoft Office 2021 Professional Plus (Windows)',
            'Microsoft Office 2021 Home & Business (Windows)',
            'Microsoft Office 2021 Home & Student (Windows)',
            'Microsoft Word 2021 (Windows)',
            'Microsoft Excel 2021 (Windows)',
        ],
        '2019' => [
            // Broad match
            'Microsoft Office 2019 Professional Plus lifetime',
            'Office 2019 Home and Student PC purchase',
            'Microsoft Office 2019 Home and Business download',
            'Office 2019 retail key for Windows',
            'cheap Microsoft Office 2019 activation code',
            // Phrase match
            'cheap Office 2019 Professional Plus Windows license',
            'Microsoft Office 2019 Home Student PC download',
            'Office 2019 Home Business key Windows PC',
            // Exact match
            'Microsoft Office 2019 Professional Plus',
            'Microsoft Office 2019 Home & Student',
            'Microsoft Office 2019 Home & Business PC',
            'Office 2019 product key Windows',
            'Microsoft Office 2019 Professional Plus (Windows)',
            'Microsoft Office 2019 Home & Student (Windows)',
            'Microsoft Office 2019 Home & Business (PC)',
            'buy Microsoft Office 2019 Professional Plus',
        ],
    ];

    $out = $universal;
    if ($year !== '' && isset($yearLib[$year])) {
        $out = array_merge($out, $yearLib[$year]);
    } else {
        // No year detected: include the highest-volume 2024 + 2021 keys so
        // generic "Microsoft Office" products still benefit.
        $out = array_merge($out, $yearLib['2024'], $yearLib['2021']);
    }

    // Edition-specific tail variants (helps standalone Word/Excel SKUs too).
    if ($ed !== '' && $year !== '') {
        $out[] = 'Microsoft ' . $ed . ' ' . $year . ' lifetime license';
        $out[] = 'buy Microsoft ' . $ed . ' ' . $year . ' product key';
        $out[] = 'Microsoft ' . $ed . ' ' . $year . ' digital download';
    }

    // Mac platform variants — appended when the SKU is Office for Mac.
    // Captures the Mac-specific transactional intent ("Office Mac lifetime
    // license no subscription", "Microsoft Office Home & Business 2024 Mac",
    // standalone Word/Excel 2021 Mac).
    if (($meta['platform'] ?? '') === 'Mac') {
        $out = array_merge($out, [
            // Broad / commercial intent (Mac)
            'buy Office Mac lifetime license no subscription',
            'cheap Microsoft Office Mac product key',
            'Microsoft Office for Mac digital download instant delivery',
            'Microsoft Office Mac one time purchase',
            'genuine Microsoft Office Mac activation key',
            // Phrase match (Mac)
            'Microsoft Office Home & Business 2024 Mac',
            'Office 2021 Home & Student Mac',
            'Microsoft Word 2021 Mac lifetime license',
            'Microsoft Excel 2021 Mac lifetime license',
            'Microsoft Office Home and Business 2019 Mac',
        ]);
        if ($year !== '') {
            $out[] = 'Microsoft Office ' . $year . ' for Mac';
            $out[] = 'Office ' . $year . ' Mac lifetime license';
            $out[] = 'buy Office ' . $year . ' Mac product key';
        }
        if ($ed !== '' && $year !== '') {
            $out[] = 'Microsoft Office ' . $ed . ' ' . $year . ' (Mac)';
            $out[] = 'Microsoft Office ' . $ed . ' ' . $year . ' Mac lifetime license';
        }
    }

    return array_values(array_unique(array_filter($out)));
}

/* ------------------------------------------------------------------
 *  windows_edition_meta()
 *  Detects Windows OS SKUs (Windows 11 / 10 + Pro / Home / Education /
 *  Enterprise).  Used to inject the high-intent Windows keyword library
 *  ("buy original Windows 11 Pro product key", "Windows 11 Home
 *  activation key", "Windows 10 Pro license key", OEM / Retail variants).
 *
 *  Returns:
 *    [
 *      'is_windows'  => bool,
 *      'version'     => '11' | '10' | '',
 *      'edition'     => 'Pro' | 'Home' | 'Education' | 'Enterprise' | '',
 *      'edition_key' => 'pro' | 'home' | 'education' | 'enterprise' | '',
 *    ]
 * ----------------------------------------------------------------- */
function windows_edition_meta(array $p): array
{
    $name = strtolower((string)($p['name'] ?? ''));
    // Match only Windows OS SKUs — exclude "Office for Windows" type names.
    $isWin = (bool)preg_match('/\bwindows\s+(10|11)\b/', $name);
    if (!$isWin) return ['is_windows' => false, 'version' => '', 'edition' => '', 'edition_key' => ''];
    if (strpos($name, 'office') !== false || strpos($name, 'word') !== false
        || strpos($name, 'excel') !== false || strpos($name, 'project') !== false
        || strpos($name, 'visio') !== false || strpos($name, 'outlook') !== false) {
        return ['is_windows' => false, 'version' => '', 'edition' => '', 'edition_key' => ''];
    }
    $version = '';
    if (preg_match('/\bwindows\s+(10|11)\b/', $name, $m)) $version = $m[1];
    $edition = '';   $editionKey = '';
    if (strpos($name, ' pro')        !== false) { $edition = 'Pro';        $editionKey = 'pro'; }
    elseif (strpos($name, ' home')       !== false) { $edition = 'Home';       $editionKey = 'home'; }
    elseif (strpos($name, 'education')   !== false) { $edition = 'Education';  $editionKey = 'education'; }
    elseif (strpos($name, 'enterprise')  !== false) { $edition = 'Enterprise'; $editionKey = 'enterprise'; }
    return ['is_windows' => true, 'version' => $version, 'edition' => $edition, 'edition_key' => $editionKey];
}

function windows_intent_keywords(array $meta): array
{
    if (empty($meta['is_windows'])) return [];
    $v  = $meta['version'] ?: '';
    $ed = $meta['edition'] ?: '';

    $universal = [
        // Broad intent
        'buy original Windows 11 Pro product key',
        'buy genuine Windows 10 license key',
        'cheap Windows OEM product key',
        'Windows retail license instant delivery',
        'Windows one-time purchase product key',
        'Windows lifetime activation key',
        'Windows digital license download',
        // Problem-solving
        'upgrade from Windows 10 to Windows 11',
        'difference between Windows 11 Pro and Home',
        'Windows product key for new PC build',
    ];
    $versionLib = [
        '11' => [
            'Windows 11 Pro product key',
            'Windows 11 Home activation key',
            'Windows 11 Pro license key',
            'Windows 11 Home product key',
            'Windows 11 Pro lifetime activation',
            'Windows 11 Pro 64-bit retail key',
            'buy Windows 11 Pro genuine activation code',
            'Windows 11 Pro for Workstations product key',
            'Windows 11 Education product key',
            'Windows 11 Pro (retail)',
            'Windows 11 Home (retail)',
        ],
        '10' => [
            'Windows 10 Pro license key',
            'Windows 10 Home activation key',
            'Windows 10 Pro product key',
            'Windows 10 Home product key',
            'Windows 10 Pro 64-bit retail key',
            'buy Windows 10 Pro genuine activation code',
            'Windows 10 Pro for Workstations product key',
            'Windows 10 Education product key',
            'Windows 10 Pro (retail)',
            'Windows 10 Home (retail)',
        ],
    ];
    $out = $universal;
    if ($v !== '' && isset($versionLib[$v])) $out = array_merge($out, $versionLib[$v]);
    if ($ed !== '' && $v !== '') {
        $out[] = 'Windows ' . $v . ' ' . $ed;
        $out[] = 'Windows ' . $v . ' ' . $ed . ' lifetime license';
        $out[] = 'Windows ' . $v . ' ' . $ed . ' OEM key';
        $out[] = 'Windows ' . $v . ' ' . $ed . ' retail key';
        $out[] = 'buy Windows ' . $v . ' ' . $ed . ' product key';
        $out[] = 'Windows ' . $v . ' ' . $ed . ' digital download activation code';
    }
    return array_values(array_unique(array_filter($out)));
}

/* ------------------------------------------------------------------
 *  project_visio_meta()
 *  Detects Microsoft Project / Visio SKUs (Professional / Standard,
 *  2024 / 2021 / 2019).
 * ----------------------------------------------------------------- */
function project_visio_meta(array $p): array
{
    $name = strtolower((string)($p['name'] ?? ''));
    $isProj  = (strpos($name, 'project') !== false);
    $isVisio = (strpos($name, 'visio')   !== false);
    if (!$isProj && !$isVisio) return ['is_project_visio' => false];
    $kind = $isProj ? 'project' : 'visio';
    $year = '';
    if (preg_match('/\b(2024|2021|2019|2016)\b/', $name, $m)) $year = $m[1];
    $edition = '';
    if (strpos($name, 'professional') !== false) $edition = 'Professional';
    elseif (strpos($name, 'standard')     !== false) $edition = 'Standard';
    return [
        'is_project_visio' => true,
        'kind'             => $kind,         // 'project' | 'visio'
        'kind_label'       => $kind === 'project' ? 'Project' : 'Visio',
        'year'             => $year,
        'edition'          => $edition,
    ];
}

function project_visio_intent_keywords(array $meta): array
{
    if (empty($meta['is_project_visio'])) return [];
    $k    = $meta['kind_label'];
    $year = $meta['year'];
    $ed   = $meta['edition'];

    $base = [
        'buy Microsoft ' . $k . ' lifetime license',
        'cheap Microsoft ' . $k . ' product key',
        'Microsoft ' . $k . ' digital download instant delivery',
        'genuine Microsoft ' . $k . ' activation key',
        'Microsoft ' . $k . ' one-time purchase Windows PC',
        'Microsoft ' . $k . ' for Windows 11',
        'Microsoft ' . $k . ' Professional download PC',
        'Microsoft ' . $k . ' license for project managers',
        'Microsoft ' . $k . ' license for business teams',
    ];
    if ($k === 'Project') {
        $base = array_merge($base, [
            'MS Project Professional 2024 PC',
            'MS Project Professional 2021 PC',
            'Microsoft Project 2019 Professional Windows',
            'Microsoft Project Professional product key',
            'project management software lifetime license Windows',
        ]);
    } else {
        $base = array_merge($base, [
            'MS Visio Professional 2024 Windows PC',
            'MS Visio Professional 2021 Windows PC',
            'Microsoft Visio 2019 Professional PC',
            'Microsoft Visio Professional product key',
            'diagram software lifetime license Windows',
        ]);
    }
    if ($year !== '') {
        $base[] = 'Microsoft ' . $k . ' ' . $year . ' Professional PC';
        $base[] = 'Microsoft ' . $k . ' ' . $year . ' lifetime license Windows';
        $base[] = 'buy Microsoft ' . $k . ' ' . $year . ' product key';
        $base[] = 'Microsoft ' . $k . ' ' . $year . ' Windows PC digital download';
    }
    if ($ed !== '' && $year !== '') {
        $base[] = 'Microsoft ' . $k . ' ' . $ed . ' ' . $year . ' (PC)';
        $base[] = 'Microsoft ' . $k . ' ' . $ed . ' ' . $year . ' Windows PC';
    }
    return array_values(array_unique(array_filter($base)));
}

/* ------------------------------------------------------------------
 *  antivirus_meta()
 *  Detects antivirus / security suite SKUs (Bitdefender, McAfee,
 *  Norton, Kaspersky, ESET, Avast, AVG, Trend Micro).  Also parses
 *  the device count + duration from the product name when present
 *  ("1 Mac, 1 Year", "5 Devices, 1 Year", "Unlimited Devices, 1 Year").
 * ----------------------------------------------------------------- */
function antivirus_meta(array $p): array
{
    $name = strtolower((string)($p['name'] ?? ''));
    $brand = '';
    foreach (['bitdefender', 'mcafee', 'norton', 'kaspersky', 'eset', 'avast', 'avg', 'trend micro'] as $b) {
        if (strpos($name, $b) !== false) { $brand = $b; break; }
    }
    if ($brand === '') return ['is_antivirus' => false];
    $brandLabel = ucwords($brand);
    if ($brand === 'mcafee') $brandLabel = 'McAfee';

    $devices = '';
    if (preg_match('/(unlimited|1|3|5|10)\s*(devices?|macs?|pcs?)/i', $name, $m)) {
        $devices = strtolower($m[1]) === 'unlimited' ? 'Unlimited devices'
                 : ($m[1] . ' ' . rtrim($m[2], 's') . (($m[1] === '1') ? '' : 's'));
    }
    $duration = '';
    if (preg_match('/(\d+)[\s-]+(year|month)s?/i', $name, $dm)) {
        $duration = $dm[1] . ' ' . strtolower($dm[2]) . ($dm[1] === '1' ? '' : 's');
    }
    $platform = ($p['platform'] ?? '') ?: 'Windows';
    return [
        'is_antivirus' => true,
        'brand'        => $brand,
        'brand_label'  => $brandLabel,
        'devices'      => $devices,
        'duration'     => $duration,
        'platform'     => $platform,
    ];
}

function antivirus_intent_keywords(array $meta): array
{
    if (empty($meta['is_antivirus'])) return [];
    $b   = $meta['brand_label'];
    $dev = $meta['devices'];
    $dur = $meta['duration'];
    $plt = $meta['platform'];

    $out = [
        'buy ' . $b . ' antivirus product key',
        'cheap ' . $b . ' license key',
        'genuine ' . $b . ' activation code',
        $b . ' antivirus digital download',
        $b . ' instant key delivery email',
        $b . ' lifetime renewable license',
        'best antivirus for ' . $plt . ' PC',
        $b . ' vs Norton vs McAfee antivirus comparison',
        'cheapest legit ' . $b . ' subscription',
    ];

    if ($b === 'Bitdefender') {
        $out = array_merge($out, [
            'cheap Bitdefender antivirus Mac VPN license',
            'Bitdefender Premium VPN unlimited devices',
            'Bitdefender antivirus for Mac 1 Mac 1 year',
            'Bitdefender Small Office Security 5 devices 1 year',
            'Bitdefender Total Security download',
            'Bitdefender Internet Security key',
            'buy Bitdefender Antivirus Plus product key',
        ]);
    } elseif ($b === 'McAfee') {
        $out = array_merge($out, [
            'McAfee+ Premium Individual 1 year USA',
            'McAfee Total Protection product key',
            'McAfee LiveSafe activation code',
            'McAfee Internet Security download key',
            'buy McAfee+ Premium unlimited devices',
            'McAfee antivirus subscription product key',
        ]);
    } elseif ($b === 'Norton') {
        $out = array_merge($out, [
            'Norton 360 Standard product key',
            'Norton 360 Deluxe activation code',
            'Norton 360 Premium 10 devices key',
            'Norton 360 with LifeLock activation',
            'buy Norton AntiVirus Plus product key',
        ]);
    }

    if ($dev !== '') $out[] = $b . ' ' . $dev . ' license key';
    if ($dur !== '') $out[] = $b . ' ' . $dur . ' subscription product key';
    if ($dev !== '' && $dur !== '') {
        $out[] = $b . ' ' . $dev . ' ' . $dur . ' digital download';
        $out[] = 'buy ' . $b . ' ' . $dev . ' ' . $dur . ' instant delivery';
    }
    return array_values(array_unique(array_filter($out)));
}

/* ------------------------------------------------------------------
 *  product_category_intent_keywords()
 *  Dispatcher.  Detects the product's category (Office, Windows OS,
 *  Project/Visio, Antivirus) and returns the matching high-intent
 *  keyword library.  Returns an empty array for SKUs that do not fit
 *  any of the curated categories.
 * ----------------------------------------------------------------- */
function product_category_intent_keywords(array $p): array
{
    $office = office_edition_meta($p);
    if (!empty($office['is_office'])) return office_intent_keywords($office);

    $win = windows_edition_meta($p);
    if (!empty($win['is_windows'])) return windows_intent_keywords($win);

    $pv = project_visio_meta($p);
    if (!empty($pv['is_project_visio'])) return project_visio_intent_keywords($pv);

    $av = antivirus_meta($p);
    if (!empty($av['is_antivirus'])) return antivirus_intent_keywords($av);

    return [];
}

/* ------------------------------------------------------------------
 *  product_seo_copy()
 *  Returns rich HTML SEO content with H2/H3 hierarchy + long-tail
 *  keyword phrases woven naturally into the body.  Rendered visibly
 *  on the product page so both humans AND crawlers consume it.
 * ----------------------------------------------------------------- */
function product_seo_copy(array $p): string
{
    $name     = esc((string)$p['name']);
    $platform = esc($p['platform'] ?: 'Windows');
    $brand    = esc(product_detected_brand($p));
    $price    = format_price((float)$p['price']);
    $year     = '';
    if (preg_match('/\b(20\d{2})\b/', (string)$p['name'], $m)) $year = $m[1];

    $h = '<section class="pd-seo-copy" data-testid="product-seo-copy" aria-labelledby="pd-seo-heading">';
    $h .= '<h2 id="pd-seo-heading" class="fw-bold h4 mt-5 mb-3">Why buy ' . $name . ' from ' . esc(SITE_BRAND) . '?</h2>';
    $h .= '<p class="text-secondary">Looking for the most reliable place to <strong>buy ' . $name . ' online</strong>? You are in the right place. ';
    $h .= esc(SITE_BRAND) . ' delivers a <strong>genuine ' . $brand . ' license key</strong> for ' . $name . ' at ' . esc($price) . ' &mdash; ';
    $h .= 'a one-time purchase with a <strong>lifetime activation</strong>, no monthly fees and no surprise renewals. ';
    $h .= 'Your key arrives by email in 15&ndash;30 minutes, ready to activate directly inside the official ' . $brand . ' installer.</p>';

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">' . $name . ' &mdash; quick facts</h3>';
    $h .= '<ul class="pd-seo-facts small text-secondary mb-4">';
    $h .= '<li><strong>Platform:</strong> ' . $platform . ($year ? ' &middot; <strong>Edition:</strong> ' . esc($year) : '') . '</li>';
    $h .= '<li><strong>Licence type:</strong> Lifetime / perpetual &mdash; not a rental subscription.</li>';
    $h .= '<li><strong>Delivery:</strong> Instant email with the activation key + official download link.</li>';
    $h .= '<li><strong>Activation:</strong> Direct inside the official ' . $brand . ' software &mdash; no third-party loaders.</li>';
    $h .= '<li><strong>Guarantee:</strong> 30-day money-back, replacement key if anything goes wrong.</li>';
    $h .= '</ul>';

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">How to activate ' . $name . ' after purchase</h3>';
    $h .= '<ol class="small text-secondary mb-4">';
    $h .= '<li>Complete checkout &mdash; your ' . $name . ' license key + official ' . $brand . ' download link arrive by email within 15&ndash;30 minutes.</li>';
    $h .= '<li>Download the genuine installer from the link in the email (or directly from the ' . $brand . ' website).</li>';
    $h .= '<li>Run the installer and sign in to your ' . $brand . ' account.</li>';
    $h .= '<li>Paste the activation key when prompted &mdash; activation completes in seconds.</li>';
    $h .= '<li>Need help? Our specialists set it up for you on a free assisted call.</li>';
    $h .= '</ol>';

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Is ' . $name . ' a one-time purchase?</h3>';
    $h .= '<p class="text-secondary mb-4">Yes &mdash; this listing is the perpetual licence. Pay once at ' . esc($price) . ', activate on your ' . $platform . ' device, and use ' . $name . ' for as long as you own the device. There are no monthly fees, no renewals and no surprise charges. If you need to move the licence to a new computer, our support team helps you transfer it free of charge.</p>';

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Best price for ' . $name . ' in ' . date('Y') . '</h3>';
    $h .= '<p class="text-secondary mb-0">' . esc(SITE_BRAND) . ' partners directly with authorised channels, which is how we can sell ' . $name . ' for ' . esc($price) . ' &mdash; up to 81% below the manufacturer&rsquo;s retail price. ';
    $h .= 'Every key is verified pre-dispatch, every payment is encrypted, and every order is protected by our 30-day money-back guarantee. ';
    $h .= 'Compare us with any other reseller on price, delivery speed and support quality &mdash; we are confident you will buy here.</p>';

    // Microsoft Office intent block — only renders for Office products.
    // Naturally weaves the highest-volume transactional phrases (lifetime
    // license, product key, instant delivery, one-time purchase, no
    // subscription) into a paragraph crawlers and AI models can quote.
    $officeMeta = office_edition_meta($p);
    if (!empty($officeMeta['is_office'])) {
        $year = $officeMeta['year'] ?: '';
        $ed   = $officeMeta['edition'] ?: '';
        $yearTxt = $year !== '' ? ('Office ' . esc($year)) : 'Microsoft Office';
        $edTxt   = $ed !== ''   ? (' ' . esc($ed)) : '';

        $h .= '<h3 class="fw-bold h5 mt-5 mb-2">' . $yearTxt . $edTxt . ' &mdash; lifetime license, product key &amp; instant download</h3>';
        $h .= '<p class="text-secondary mb-3">Searching for a <strong>' . $yearTxt . $edTxt . ' product key</strong>, a <strong>lifetime license</strong> or a <strong>genuine activation code</strong> for your ' . $platform . ' PC? This is the right listing. ';
        $h .= 'Pay once, no monthly subscription, no recurring fees &mdash; just a <strong>one-time purchase</strong> of ' . $name . ' delivered as a digital download by email in 15&ndash;30 minutes. ';
        $h .= 'The key activates the official ' . esc($brand) . ' installer downloaded directly from Microsoft, so you get the <strong>full version of ' . $yearTxt . $edTxt . '</strong> with every Word, Excel, PowerPoint and Outlook update included for the life of the licence.</p>';

        if ($year === '2024') {
            $h .= '<p class="text-secondary mb-3"><strong>Why Office 2024?</strong> The newest perpetual release of Microsoft Office for Windows 11 and Windows 10 PCs &mdash; faster start-up, refreshed ribbon, native ARM64 support and the latest Word, Excel and PowerPoint features. Best buy for shoppers asking "<em>Microsoft Office 2024 Professional Plus product key</em>", "<em>buy Office 2024 lifetime license Windows</em>" or "<em>latest Microsoft Office 2024 for Windows</em>".</p>';
        } elseif ($year === '2021') {
            $h .= '<p class="text-secondary mb-3"><strong>Why Office 2021?</strong> Still the value champion in 2026 &mdash; same core apps as the cloud subscription, but a true <em>one-time purchase</em>. Perfect for shoppers searching "<em>Microsoft Office 2021 Professional Plus download</em>", "<em>Office 2021 Home and Business Windows PC</em>" or "<em>standalone Microsoft Word 2021 product key</em>".</p>';
        } elseif ($year === '2019') {
            $h .= '<p class="text-secondary mb-3"><strong>Why Office 2019?</strong> The most affordable genuine Office release for older PCs and tight budgets. Activates on Windows 11, Windows 10 and Windows 7. Matches intent for "<em>Microsoft Office 2019 Professional Plus lifetime</em>", "<em>Office 2019 retail key for Windows</em>" or "<em>cheap Microsoft Office 2019 activation code</em>".</p>';
        }

        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Office without a monthly subscription &mdash; how it works</h3>';
        $h .= '<p class="text-secondary mb-3">Unlike Microsoft 365, ' . $name . ' is a <strong>perpetual licence</strong>. There is no annual renewal, no subscription auto-charge and no &ldquo;cloud account&rdquo; that locks you out the day you stop paying. Buy ' . $yearTxt . ' once at ' . esc($price) . ', activate inside the genuine ' . esc($brand) . ' installer on your ' . $platform . ' PC, and use Word, Excel, PowerPoint (and Outlook on Home &amp; Business and Professional Plus) for as long as you own the device.</p>';
    }

    // Windows OS intent block — renders for Windows 10/11 SKUs.
    $winMeta = windows_edition_meta($p);
    if (!empty($winMeta['is_windows'])) {
        $v   = $winMeta['version'] ?: '';
        $ed  = $winMeta['edition'] ?: '';
        $vTxt   = $v  !== '' ? ('Windows ' . esc($v)) : 'Windows';
        $edTxt  = $ed !== '' ? (' ' . esc($ed)) : '';

        $h .= '<h3 class="fw-bold h5 mt-5 mb-2">' . $vTxt . $edTxt . ' &mdash; genuine product key, lifetime activation &amp; instant digital delivery</h3>';
        $h .= '<p class="text-secondary mb-3">Shopping for a <strong>' . $vTxt . $edTxt . ' product key</strong>, a <strong>retail license</strong> or an <strong>OEM activation code</strong> for your PC build? This listing ships you exactly that. ';
        $h .= 'Pay once at ' . esc($price) . ', receive the 25-character genuine activation code by email in 15&ndash;30 minutes, run the official Microsoft Media Creation Tool, paste the key, and your machine is fully activated &mdash; tied to your hardware, not to a subscription.</p>';

        if ($v === '11') {
            $h .= '<p class="text-secondary mb-3"><strong>Why Windows 11' . $edTxt . '?</strong> The latest perpetual Microsoft OS &mdash; redesigned Start menu, Snap Layouts, native Microsoft Store, DirectStorage gaming and full Copilot integration. Best buy for shoppers searching "<em>buy original Windows 11 Pro product key</em>", "<em>Windows 11 Home activation key</em>" or "<em>Windows 11 Pro 64-bit retail key</em>".</p>';
        } elseif ($v === '10') {
            $h .= '<p class="text-secondary mb-3"><strong>Why Windows 10' . $edTxt . '?</strong> Battle-tested on every workstation and gaming rig &mdash; minimal hardware requirements, faster boot than legacy Win 7/8.1, and free upgrades to Windows 11 when your hardware is ready. Perfect for "<em>Windows 10 Pro license key</em>", "<em>Windows 10 Home activation key</em>" or "<em>Windows 10 Pro 64-bit retail key</em>" intent.</p>';
        }
        if ($ed === 'Pro' || $ed === 'Education' || $ed === 'Enterprise') {
            $h .= '<p class="text-secondary mb-3"><strong>' . $vTxt . ' ' . $ed . ' specifics:</strong> BitLocker drive encryption, Remote Desktop host, Hyper-V virtualisation, Group Policy, Windows Sandbox and Azure AD join &mdash; everything home builds miss. Activates from a Home install via Microsoft\'s built-in upgrade flow.</p>';
        }
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">How activation works</h3>';
        $h .= '<p class="text-secondary mb-3">Open <em>Settings &rsaquo; System &rsaquo; Activation</em>, click <em>Change product key</em>, paste the 25-character code from your email. Activation completes in under five minutes. Each ' . $vTxt . $edTxt . ' key is single-PC under Microsoft\'s EULA &mdash; for multi-PC coverage pick a 3 or 5-seat bundle or buy additional keys with automatic volume discount.</p>';
    }

    // Microsoft Project / Visio intent block.
    $pvMeta = project_visio_meta($p);
    if (!empty($pvMeta['is_project_visio'])) {
        $kind  = $pvMeta['kind_label'];                 // 'Project' or 'Visio'
        $year  = $pvMeta['year'] ?: '';
        $ed    = $pvMeta['edition'] ?: '';
        $yTxt  = $year !== '' ? (' ' . esc($year)) : '';
        $edTxt = $ed   !== '' ? (' ' . esc($ed))   : '';

        $h .= '<h3 class="fw-bold h5 mt-5 mb-2">Microsoft ' . $kind . $yTxt . $edTxt . ' &mdash; lifetime license &amp; instant product key</h3>';
        if ($kind === 'Project') {
            $h .= '<p class="text-secondary mb-3">The professional grade tool millions of project managers, PMOs and construction teams depend on for Gantt charts, resource levelling, baseline tracking and earned-value analysis. This listing is a <strong>one-time purchase perpetual license</strong> for Microsoft Project' . $yTxt . $edTxt . ' on Windows PC &mdash; no monthly fee, no Microsoft Project Online subscription required. Matches intent for "<em>Microsoft Project ' . ($year ?: '2024') . ' Professional PC</em>", "<em>MS Project Professional product key</em>" and "<em>project management software lifetime license Windows</em>".</p>';
        } else {
            $h .= '<p class="text-secondary mb-3">The industry-standard diagramming app trusted by IT architects, network engineers and BPMN teams. This listing is a <strong>one-time purchase perpetual license</strong> for Microsoft Visio' . $yTxt . $edTxt . ' on Windows PC &mdash; full template library (network, floor plan, UML, ERD, BPMN, AWS / Azure shapes) without a Visio Plan 1 or Plan 2 subscription. Matches "<em>Microsoft Visio ' . ($year ?: '2024') . ' Professional Windows PC</em>", "<em>MS Visio Professional product key</em>" and "<em>diagram software lifetime license Windows</em>".</p>';
        }
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Installs alongside Office</h3>';
        $h .= '<p class="text-secondary mb-3">Microsoft ' . $kind . $yTxt . ' installs as a standalone app on the same Windows PC where your Office 365 / Office 2021 / Office 2024 already runs &mdash; they do not conflict. Activation uses your Microsoft account so you keep the licence when you change PCs (subject to Microsoft\'s standard reactivation flow).</p>';
    }

    // Antivirus / security suite intent block.
    $avMeta = antivirus_meta($p);
    if (!empty($avMeta['is_antivirus'])) {
        $b    = $avMeta['brand_label'];
        $dev  = $avMeta['devices'];
        $dur  = $avMeta['duration'];
        $plt  = $avMeta['platform'];
        $covT = ($dev !== '' || $dur !== '') ? (' &mdash; ' . trim($dev . ($dev && $dur ? ', ' : '') . $dur)) : '';

        $h .= '<h3 class="fw-bold h5 mt-5 mb-2">' . esc($b) . ' security' . $covT . ' &mdash; genuine subscription key, instant email delivery</h3>';
        $h .= '<p class="text-secondary mb-3">Searching for a <strong>genuine ' . esc($b) . ' activation code</strong> or a <strong>cheaper-than-RRP renewal key</strong>? This is the right listing. Pay once at ' . esc($price) . ', receive the activation key by email in 15&ndash;30 minutes, sign in to your ' . esc($b) . ' Central / My Account dashboard, paste the code, and your ' . esc($plt) . ' device(s) are protected immediately &mdash; real-time malware shield, ransomware blocker, anti-phishing, web threat prevention and (where included) VPN + identity-theft monitoring.</p>';

        if (stripos($b, 'Bitdefender') !== false) {
            $h .= '<p class="text-secondary mb-3">Bitdefender consistently tops AV-Comparatives and AV-Test rankings for 0-day malware detection with the lowest CPU footprint in the category. Matches "<em>cheap Bitdefender antivirus ' . esc($plt) . ' VPN license</em>", "<em>Bitdefender Premium VPN unlimited devices</em>" and "<em>Bitdefender Small Office Security 5 devices 1 year</em>".</p>';
        } elseif (stripos($b, 'McAfee') !== false) {
            $h .= '<p class="text-secondary mb-3">McAfee+ ships with unlimited-device protection, secure VPN, password manager, file shredder and identity-monitoring on top of the core antivirus engine. Matches "<em>McAfee+ Premium Individual 1 year USA</em>", "<em>McAfee Total Protection product key</em>" and "<em>McAfee LiveSafe activation code</em>".</p>';
        } elseif (stripos($b, 'Norton') !== false) {
            $h .= '<p class="text-secondary mb-3">Norton 360 bundles antivirus, secure VPN, dark-web monitoring and (on Deluxe / Premium tiers) LifeLock identity-theft protection in a single subscription. Matches "<em>Norton 360 Standard product key</em>", "<em>Norton 360 Deluxe activation code</em>" and "<em>Norton 360 with LifeLock activation</em>".</p>';
        }
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Activation works in under 2 minutes</h3>';
        $h .= '<p class="text-secondary mb-3">Create or log in to your ' . esc($b) . ' account, paste the activation code from your email, install the official ' . esc($b) . ' app on each protected device, and the coverage clock starts. ' . ($dur !== '' ? ('Your subscription runs for ' . esc($dur) . ' from the day you redeem the key &mdash; not from the day we ship it &mdash; so feel free to buy now and activate later. ') : '') . 'Renewal is optional &mdash; no auto-charge when this subscription ends.</p>';
    }

    $h .= '</section>';
    return $h;
}

/* ------------------------------------------------------------------
 *  product_howto_jsonld()
 *  HowTo schema for "How to activate <product>".  Google promotes
 *  HowTo rich results AND AI search engines parse them for step-by-step
 *  guidance.
 * ----------------------------------------------------------------- */
function product_howto_jsonld(array $p): array
{
    $name  = (string)$p['name'];
    $brand = product_detected_brand($p);
    return [
        '@context' => 'https://schema.org',
        '@type'    => 'HowTo',
        'name'     => 'How to activate ' . $name,
        'description' => 'Step-by-step guide to activating your ' . $name . ' licence key from ' . SITE_BRAND . '.',
        'totalTime'   => 'PT5M',
        'tool'        => ['Your ' . $brand . ' licence key', 'Internet connection', 'Your ' . ($p['platform'] ?: 'Windows') . ' device'],
        'step'        => [
            ['@type' => 'HowToStep', 'position' => 1, 'name' => 'Receive your licence key',
             'text' => 'Your ' . $name . ' licence key arrives by email within 15-30 minutes of completing checkout, along with the official ' . $brand . ' download link.'],
            ['@type' => 'HowToStep', 'position' => 2, 'name' => 'Download the official installer',
             'text' => 'Click the download link in the email or visit the official ' . $brand . ' website to download the genuine installer for ' . ($p['platform'] ?: 'Windows') . '.'],
            ['@type' => 'HowToStep', 'position' => 3, 'name' => 'Install the software',
             'text' => 'Run the downloaded installer and follow the on-screen prompts. Sign in with (or create) your ' . $brand . ' account when asked.'],
            ['@type' => 'HowToStep', 'position' => 4, 'name' => 'Enter your activation key',
             'text' => 'When the installer asks for an activation key, paste the key from your delivery email and confirm. Activation completes in seconds.'],
            ['@type' => 'HowToStep', 'position' => 5, 'name' => 'Start using ' . $name,
             'text' => 'Once activated, ' . $name . ' is yours for life &mdash; no renewals or subscriptions. If anything goes wrong, our support team is available to help.'],
        ],
    ];
}

/* ------------------------------------------------------------------
 *  product_ai_summary_jsonld()
 *  AI-friendly Article schema with `about > Product` linkage.
 *  Why this format:
 *    - ChatGPT, Perplexity, Bing Copilot and Google AI Overviews
 *      preferentially quote `Article` blocks because they read as
 *      self-contained, attributable paragraphs.
 *    - The `about` property creates an explicit graph edge from the
 *      Article to the underlying Product entity, so the LLM keeps
 *      structured facts (price, brand, platform) tied to the prose
 *      summary it just quoted.
 *    - `audience` + `keywords` give the model an unambiguous signal
 *      about who the page is for, boosting answer-relevance scoring.
 * ----------------------------------------------------------------- */
function product_ai_summary_jsonld(array $p): array
{
    $name     = (string)$p['name'];
    $platform = $p['platform'] ?: 'Windows';
    $brand    = product_detected_brand($p);
    $price    = format_price((float)$p['price']);
    $url      = site_url() . '/product.php?slug=' . urlencode((string)$p['slug']);

    // Two-paragraph editorial summary: paragraph 1 = what + who-for,
    // paragraph 2 = how-it-works + the trust signals.  Total length is
    // tuned for AI Overview snippet eligibility (~600-900 chars).
    $headline = $name . ': lifetime ' . $brand . ' licence for ' . $platform;
    $body  = $name . ' is a one-time purchase, perpetual licence sold by ' . SITE_BRAND . ' for ' . $price . '. ';
    $body .= 'The licence is genuine, activates directly inside the official ' . $brand . ' software on ' . $platform . ', and remains valid for the life of the device — there is no monthly subscription and no automatic re-billing. ';
    $body .= 'Ideal for shoppers searching for "buy ' . strtolower($name) . ' lifetime", "' . strtolower($name) . ' product key", "' . strtolower($name) . ' one-time purchase no subscription" or "' . $brand . ' authorised reseller". ';
    $body .= "\n\n";
    $body .= 'After checkout the activation key arrives by email within 15-30 minutes, alongside the official ' . $brand . ' download link and step-by-step activation instructions. ';
    $body .= 'Activation completes in under five minutes; help is available six days a week via live chat, email and phone. ';
    $body .= 'Every order is backed by a 30-day money-back guarantee and protected by encrypted payment processing. ';

    return [
        '@context'      => 'https://schema.org',
        '@type'         => 'Article',
        'headline'      => $headline,
        'description'   => 'Plain-language summary of ' . $name . ' for AI search engines and shoppers comparing genuine ' . $brand . ' licence keys.',
        'articleBody'   => $body,
        'author'        => ['@type' => 'Organization', 'name' => SITE_BRAND, 'url' => site_url() . '/'],
        'publisher'     => ['@type' => 'Organization', 'name' => SITE_BRAND, 'url' => site_url() . '/'],
        'datePublished' => date('Y-m-d'),
        'dateModified'  => date('Y-m-d'),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        'inLanguage'    => 'en',
        'about'         => [
            '@type'    => 'Product',
            'name'     => $name,
            'brand'    => ['@type' => 'Brand', 'name' => $brand],
            'category' => (string)($p['category'] ?? 'Software'),
            'offers'   => [
                '@type'         => 'Offer',
                'price'         => (string)(float)$p['price'],
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
                'url'           => $url,
            ],
        ],
        'audience'      => [
            '@type' => 'Audience',
            'audienceType' => 'Home users, small-business owners and IT teams looking for a one-time-purchase ' . $platform . ' licence',
        ],
        'keywords'      => product_long_tail_keywords($p),
    ];
}


/* ------------------------------------------------------------------
 *  product_review_items_jsonld()
 *  Returns up to N Review schema items pulled from the
 *  customer_reviews table.  Embeds them inside the Product schema so
 *  Google + AI search engines surface actual verified review text.
 * ----------------------------------------------------------------- */
function product_review_items_jsonld(array $p, int $limit = 5): array
{
    $out = [];
    try {
        // Reviews aren't strictly tied to a slug in the current schema, so
        // we pull the highest-rated recent reviews as social proof.  This
        // mirrors what shoppers see on the public storefront.
        $stmt = db()->prepare("SELECT customer_name AS reviewer_name, rating, comment, submitted_at
                                 FROM customer_reviews
                                WHERE status = 'published'
                                  AND comment IS NOT NULL AND comment <> ''
                             ORDER BY rating DESC, submitted_at DESC
                                LIMIT ?");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                '@type'        => 'Review',
                'reviewRating' => [
                    '@type'       => 'Rating',
                    'ratingValue' => (string)(int)$r['rating'],
                    'bestRating'  => '5',
                ],
                'author'       => ['@type' => 'Person', 'name' => (string)($r['reviewer_name'] ?? 'Verified Buyer')],
                'datePublished'=> !empty($r['submitted_at']) ? date('Y-m-d', strtotime($r['submitted_at'])) : date('Y-m-d'),
                'reviewBody'   => (string)$r['comment'],
            ];
        }
    } catch (Throwable $e) { error_log('[seo-content.product_review_items_jsonld] ' . $e->getMessage()); }
    return $out;
}

/* ------------------------------------------------------------------
 *  product_review_snippets()
 *  Same query as the JSON-LD helper but returns plain rows so the
 *  page can render them visibly to humans (also indexable text).
 * ----------------------------------------------------------------- */
function product_review_snippets(int $limit = 3): array
{
    try {
        $stmt = db()->prepare("SELECT customer_name AS reviewer_name, rating, comment, submitted_at
                                 FROM customer_reviews
                                WHERE status = 'published'
                                  AND comment IS NOT NULL AND comment <> ''
                             ORDER BY rating DESC, submitted_at DESC
                                LIMIT ?");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) { error_log('[seo-content.product_review_snippets] ' . $e->getMessage()); return []; }
}

/* ------------------------------------------------------------------
 *  product_related_articles()
 *  Returns up to N blog posts linked to this product (deep-link
 *  cluster).  Falls back to recent posts if there are none tagged.
 * ----------------------------------------------------------------- */
function product_related_articles(array $p, int $limit = 3): array
{
    $out = [];
    try {
        $stmt = db()->prepare("SELECT id, title, image, date, read_time FROM blog_posts
                                WHERE product_id = ?
                             ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC
                                LIMIT ?");
        $stmt->bindValue(1, (int)$p['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = $stmt->fetchAll();
    } catch (Throwable $e) { error_log('[seo-content.product_related_articles primary] ' . $e->getMessage()); }
    if (!$out) {
        try {
            // Fallback — recent posts so the cluster section is never empty
            $stmt = db()->prepare("SELECT id, title, image, date, read_time FROM blog_posts
                                ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC LIMIT ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $out = $stmt->fetchAll();
        } catch (Throwable $e) { error_log('[seo-content.product_related_articles fallback] ' . $e->getMessage()); }
    }
    return $out;
}

/* ------------------------------------------------------------------
 *  product_sibling_category()
 *  Returns the slug + title of the "sister" category (e.g. Mac if
 *  this is PC) so we can deep-link a cross-platform variant.
 * ----------------------------------------------------------------- */
function product_sibling_category(array $p): ?array
{
    $cat = (string)($p['category'] ?? '');
    if ($cat === '') return null;
    $sister = null;
    if (str_ends_with($cat, '-pc'))  $sister = substr($cat, 0, -3) . '-mac';
    elseif (str_ends_with($cat, '-mac')) $sister = substr($cat, 0, -4) . '-pc';
    if (!$sister) return null;
    return ['slug' => $sister, 'title' => category_title($sister)];
}

/* ------------------------------------------------------------------
 *  category_intro_seo()
 *  Hero intro copy for a category landing page.
 * ----------------------------------------------------------------- */
function category_intro_seo(string $slug, string $title): string
{
    $year = date('Y');
    $isAntivirus = (strpos($slug, 'bitdefender') !== false || strpos($slug, 'mcafee') !== false || $slug === 'antivirus');
    $isOffice    = (strpos($slug, 'office') !== false || $slug === 'apps' || strpos($slug, 'microsoft-') === 0);
    $isWindows   = (strpos($slug, 'windows') !== false);

    if ($isAntivirus) {
        return 'Compare and buy genuine ' . esc($title) . ' licences below &mdash; full antivirus, anti-malware and ransomware protection with the lowest verified prices online, instant email delivery and 30-day money-back peace of mind. Every key is sourced directly from authorised channels and activates inside the official ' . esc($title) . ' installer.';
    }
    if ($isOffice) {
        return 'Shop ' . esc($title) . ' &mdash; a one-time purchase that gives you a lifetime licence for Word, Excel, PowerPoint and the rest of the Office apps. No monthly Microsoft 365 fees, no renewals, no surprise charges. Pay once, install on your device, and use it for as long as you own the computer. Backed by our 30-day money-back guarantee.';
    }
    if ($isWindows) {
        return 'Activate your PC with a genuine ' . esc($title) . ' product key in minutes. Buy the perpetual licence below and pay once &mdash; never a subscription. Instant email delivery, free upgrade-style updates within the version and round-the-clock activation support.';
    }
    return 'Explore the full range of ' . esc($title) . ' below. Every licence is a perpetual one-time purchase with instant email delivery, free activation support and a 30-day money-back guarantee. Save up to 81% versus retail pricing in ' . $year . '.';
}

/* ------------------------------------------------------------------
 *  category_long_tail_keywords()
 *  Keyword-dense meta-keywords string for a category page.
 * ----------------------------------------------------------------- */
function category_long_tail_keywords(string $title, string $platform = ''): string
{
    $year = date('Y');
    $kw = [
        $title,
        'buy ' . $title,
        $title . ' license key',
        $title . ' product key',
        $title . ' activation key',
        $title . ' lifetime license',
        $title . ' one time purchase',
        $title . ' download',
        $title . ' digital download',
        $title . ' instant delivery',
        $title . ' no subscription',
        $title . ' best price',
        $title . ' discount',
        $title . ' ' . $year,
        $title . ' for sale',
        $title . ' authorized reseller',
        'cheap ' . $title . ' license',
        'how to activate ' . $title,
    ];
    if ($platform === 'Windows' || $platform === 'Mac') {
        $kw[] = $title . ' for ' . $platform;
        $kw[] = $title . ' for ' . $platform . ' download';
        $kw[] = $title . ' ' . $platform . ' license key';
    }
    // Category-aware high-intent keyword libraries for the category title.
    // We synthesise a stub product-name (the category title) and dispatch
    // through product_category_intent_keywords() so categories like
    // "Office 2024 for PC", "Windows 10", "Project & Visio Pro" or
    // "Bitdefender Antivirus" all surface their full intent cluster.
    $kw = array_merge(
        $kw,
        product_category_intent_keywords(['name' => $title, 'platform' => $platform])
    );
    return implode(', ', array_values(array_unique(array_filter($kw))));
}

/* ------------------------------------------------------------------
 *  category_faqs()
 *  Returns 5 category-specific FAQ pairs that appear visibly on the
 *  page AND are emitted as FAQPage JSON-LD.
 * ----------------------------------------------------------------- */
function category_faqs(string $slug, string $title): array
{
    $brand = (strpos($slug, 'bitdefender') !== false) ? 'Bitdefender'
           : ((strpos($slug, 'mcafee') !== false) ? 'McAfee'
           : ((strpos($slug, 'office') !== false || strpos($slug, 'microsoft') === 0 || strpos($slug, 'windows') !== false || $slug === 'apps') ? 'Microsoft' : 'the vendor'));
    $faqs = [
        [
            'question' => 'Are the ' . $title . ' license keys genuine?',
            'answer'   => 'Yes. Every ' . $title . ' licence key sold by ' . SITE_BRAND . ' is genuine and sourced through authorised channels. The key activates directly inside the official ' . $brand . ' software downloaded from the manufacturer&rsquo;s website. We never sell cracked, repackaged or modified installers, and every key is verified before dispatch.',
        ],
        [
            'question' => 'How quickly will I receive my ' . $title . ' license key?',
            'answer'   => 'Your ' . $title . ' licence key is delivered by email within 15-30 minutes of completing payment &mdash; often in seconds. The email contains the activation key, the official ' . $brand . ' download link and step-by-step activation instructions. There is no physical shipping; everything is digital.',
        ],
        [
            'question' => 'Is ' . $title . ' a one-time purchase or a subscription?',
            'answer'   => 'Every ' . $title . ' listing on this page is a one-time purchase with a perpetual (lifetime) licence. Pay once, activate on your device, and use the software for as long as you own the computer. There are no monthly fees, no renewals and no automatic re-billing.',
        ],
        [
            'question' => 'What if my ' . $title . ' key does not activate?',
            'answer'   => 'In the rare case a ' . $title . ' key fails to activate, contact our support team within 30 days. We will either send a working replacement key at no extra cost or issue a full refund &mdash; your choice. Most activation issues are resolved by our specialists in under 10 minutes.',
        ],
        [
            'question' => 'Do you offer volume discounts on ' . $title . '?',
            'answer'   => 'Yes. Buying 5 or more ' . $title . ' licences automatically applies our volume discount at checkout. For larger orders (10+) or for a consolidated invoice, contact us for a custom quote &mdash; we deliver hundreds of licences daily to schools, agencies and IT teams.',
        ],
    ];

    // ----------------------------------------------------------------
    // Category-aware FAQ append.  Mirrors the per-product pattern but
    // uses category-wide angle questions (choosing edition / comparing
    // years / Mac-vs-PC / brand-vs-brand) instead of edition-specific
    // wording.  Detection is done from the slug + title so categories
    // route into the right cluster even when they have no products yet.
    // ----------------------------------------------------------------
    $slugLc  = strtolower($slug);
    $titleLc = strtolower($title);
    $isOffice = (strpos($slugLc, 'office') !== false
                 || strpos($slugLc, 'word')   !== false
                 || strpos($slugLc, 'excel')  !== false
                 || strpos($slugLc, 'powerpoint') !== false
                 || $slug === 'microsoft-office'
                 || $slug === 'office');
    $isWin    = (preg_match('/\bwindows[-\s](10|11)/i', $slugLc) === 1) || in_array($slug, ['windows-11','windows-10','windows'], true);
    $isProj   = (strpos($slugLc, 'project') !== false);
    $isVisio  = (strpos($slugLc, 'visio')   !== false);
    $isAv     = (strpos($slugLc, 'bitdefender') !== false
                 || strpos($slugLc, 'mcafee') !== false
                 || strpos($slugLc, 'norton') !== false
                 || strpos($slugLc, 'kaspersky') !== false
                 || $slug === 'antivirus');
    $year = '';
    if (preg_match('/\b(2024|2021|2019)\b/', $slugLc, $ym)) $year = $ym[1];
    $isMac = (strpos($slugLc, 'mac') !== false);

    if ($isOffice && !$isProj && !$isVisio) {
        $yearTxt = $year !== '' ? ' ' . $year : '';
        $faqs[] = [
            'question' => 'Which Microsoft Office' . $yearTxt . ' edition should I pick: Home & Student, Home & Business or Professional Plus?',
            'answer'   => 'Choose <strong>Home &amp; Student</strong> if you mainly write documents (Word), crunch spreadsheets (Excel) and build presentations (PowerPoint) &mdash; it is the cheapest one-time-purchase tier. Pick <strong>Home &amp; Business</strong> if you also send email from Outlook for a side business or consulting work &mdash; same three apps plus Outlook. Power users who need <strong>Publisher, Access, Skype for Business or Teams Classic</strong> should pick <strong>Professional Plus</strong>. All three editions on this page are perpetual lifetime licences &mdash; no Microsoft 365 subscription required.',
        ];
        if ($year !== '') {
            $faqs[] = [
                'question' => 'Office 2024 vs Office 2021 vs Office 2019 &mdash; which year is best for me?',
                'answer'   => '<strong>Office 2024</strong> is the latest perpetual release &mdash; faster start-up, refreshed ribbon, native ARM64 support and Windows 11 polish. <strong>Office 2021</strong> remains the best value-for-money tier and runs on Windows 10 + 11 with the same core Word / Excel / PowerPoint feature set. <strong>Office 2019</strong> is the cheapest legit option for older PCs, including Windows 7 / 8.1 / 10. All three are <em>one-time purchases</em> &mdash; the only differences are minor feature drops and how long Microsoft will keep shipping security updates for them.',
            ];
        }
        $faqs[] = [
            'question' => 'Microsoft Office for Mac vs Office for Windows &mdash; what is the difference?',
            'answer'   => 'The Word, Excel and PowerPoint experience is essentially the same on both platforms &mdash; same file formats (.docx / .xlsx / .pptx), same ribbon, same templates, same OneDrive sync. The Windows editions add <strong>Publisher and Access</strong> (not available on Mac). Outlook for Mac and Outlook for Windows differ in a few enterprise features. A licence is platform-locked, so make sure you pick the <strong>Mac</strong> or <strong>Windows / PC</strong> edition that matches the computer you will install on &mdash; we will swap free of charge within 30 days if you pick wrong.',
        ];
    } elseif ($isWin) {
        $faqs[] = [
            'question' => 'Windows Home vs Pro vs Education &mdash; which edition do I need?',
            'answer'   => '<strong>Home</strong> covers everyday personal use, gaming, web browsing and the Microsoft Store. <strong>Pro</strong> adds the features remote workers, IT pros and small businesses depend on: <em>BitLocker</em> drive encryption, <em>Remote Desktop host</em>, <em>Hyper-V</em> virtualisation, <em>Group Policy Editor</em>, <em>Windows Sandbox</em> and <em>Azure AD join</em>. <strong>Education</strong> is functionally Pro with school-friendly defaults and is restricted to verified students / staff. For most home users, Home is plenty; for any work-from-home scenario, Pro is the right pick.',
        ];
        $faqs[] = [
            'question' => 'Windows 11 vs Windows 10 &mdash; which should I buy in ' . date('Y') . '?',
            'answer'   => '<strong>Windows 11</strong> is Microsoft\'s current focus &mdash; new Start menu, Snap Layouts, DirectStorage gaming, native Copilot, and security updates committed through 2031. <strong>Windows 10</strong> mainstream support already ended (Oct 2025), but it is still a great pick for older PCs that lack a TPM 2.0 chip or fail the Windows 11 PC Health Check. Run Microsoft\'s free <em>PC Health Check</em> tool first; if your PC passes, buy Windows 11 &mdash; otherwise the Windows 10 licence is the legitimate path.',
        ];
        $faqs[] = [
            'question' => 'Can I move my Windows licence to a new PC later?',
            'answer'   => 'Retail Windows licences are <strong>fully transferable</strong> to a new PC under Microsoft\'s EULA &mdash; you simply deactivate the old machine (uninstall the key with <code>slmgr.vbs /upk</code>) and activate the new one with the same code. OEM licences (the kind that come pre-installed on store-bought laptops) are tied to the original motherboard. Every Windows licence sold on this page is the <strong>retail</strong> tier, so it travels with you across hardware upgrades.',
        ];
    } elseif ($isProj || $isVisio) {
        $kind = $isProj ? 'Project' : 'Visio';
        $faqs[] = [
            'question' => 'Microsoft Project vs Microsoft Visio &mdash; which do I need?',
            'answer'   => '<strong>Microsoft Project</strong> is for project managers, PMOs and construction teams &mdash; Gantt charts, resource levelling, baseline tracking, earned-value analysis and task dependencies. <strong>Microsoft Visio</strong> is for diagram-driven work &mdash; network maps, BPMN flows, UML diagrams, floor plans, AWS / Azure / GCP architecture diagrams and ERDs. They are completely separate apps; many engineers and architects own both.',
        ];
        $faqs[] = [
            'question' => 'Is this Microsoft ' . $kind . ' the same as ' . $kind . ' Online / ' . $kind . ' Plan 1?',
            'answer'   => 'No. <strong>' . $kind . ' Online / ' . $kind . ' Plan 1 and Plan 2</strong> are monthly subscriptions sold by Microsoft 365. The listings on this page are <strong>one-time-purchase perpetual licences</strong> for the desktop ' . $kind . ' app on Windows PC. Pay once, install once, no monthly seat fee &mdash; ideal for individual consultants, small PMOs and IT shops that don\'t need real-time multi-user collaboration.',
        ];
        $faqs[] = [
            'question' => 'Can I install Microsoft ' . $kind . ' alongside my existing Microsoft Office?',
            'answer'   => 'Yes. Microsoft ' . $kind . ' installs as a standalone app on the same Windows PC where Microsoft 365, Office 2024, Office 2021 or Office 2019 is already running. The installers do not conflict and you keep all your existing Word, Excel, PowerPoint and Outlook settings intact. ' . $kind . ' just appears as a new app in the Start menu.',
        ];
    } elseif ($isAv) {
        $faqs[] = [
            'question' => 'Bitdefender vs McAfee vs Norton &mdash; which antivirus brand should I choose?',
            'answer'   => '<strong>Bitdefender</strong> consistently tops AV-Comparatives and AV-Test rankings with the lightest CPU footprint &mdash; the best pick if you want maximum protection without slowing the PC down. <strong>McAfee+</strong> bundles unlimited-device protection plus VPN, password manager and identity-theft monitoring on top of the antivirus engine &mdash; best for families. <strong>Norton 360</strong> ships with secure VPN, dark-web monitoring and (on Deluxe / Premium tiers) LifeLock identity protection &mdash; popular in the US. All three are genuine subscriptions activated via the brand\'s official portal.',
        ];
        $faqs[] = [
            'question' => 'Will the antivirus subscription auto-renew when it expires?',
            'answer'   => '<strong>No.</strong> Every antivirus listing on this page is a prepaid fixed-term subscription. Your credit card is never stored against the antivirus brand\'s billing system because we activate the licence from a redemption code, not a recurring card. When the term ends, protection simply pauses &mdash; renew at your own pace (or buy a fresh key from us at the same discount).',
        ];
        $faqs[] = [
            'question' => 'Does my antivirus subscription cover phones, Macs and tablets too?',
            'answer'   => 'It depends on the listing. Most modern security suites (McAfee+, Norton 360, Bitdefender Total Security / Family Pack) cover <strong>Windows PCs, Macs, Android phones and iPhones / iPads</strong> from the same subscription &mdash; install the brand\'s app on each device and sign in. Single-device subscriptions (e.g. <em>Bitdefender Antivirus for Mac, 1 Mac</em>) cover only the listed platform. Check the device-count badge on each card before buying.',
        ];
    }

    return $faqs;
}

/* ------------------------------------------------------------------
 *  marquee_page_keywords()
 *  Builds a long-tail keyword string for the three marquee pages —
 *  homepage, shop index and blog index.  Pulls signal from the actual
 *  product catalogue (top brands + top years + currently-active
 *  categories) so the keyword list stays in sync with whatever the
 *  storefront actually sells.
 * ----------------------------------------------------------------- */
function marquee_page_keywords(string $kind = 'index'): string
{
    $kw = [];
    // Universal commercial-intent stems (the front-door equivalent of
    // the per-product long-tail variants).
    $kw[] = SITE_BRAND . ' genuine software licenses';
    $kw[] = 'buy Microsoft Office product key';
    $kw[] = 'Microsoft Office lifetime license';
    $kw[] = 'Microsoft Office one-time purchase';
    $kw[] = 'Windows 11 Pro product key';
    $kw[] = 'Windows 10 Pro product key';
    $kw[] = 'Microsoft Project Professional product key';
    $kw[] = 'Microsoft Visio Professional product key';
    $kw[] = 'Bitdefender activation key';
    $kw[] = 'McAfee Premium product key';
    $kw[] = 'Norton 360 activation code';
    $kw[] = 'cheap genuine software license';
    $kw[] = 'digital download Microsoft software';
    $kw[] = 'instant delivery software keys';
    $kw[] = 'no subscription software license';
    $kw[] = 'lifetime activation Microsoft software';
    $kw[] = 'authorized Microsoft software reseller';
    $kw[] = 'software product key email delivery';

    // Top categories (alive in DB) — surface the slugs as natural-form
    // phrases so each category gets at least one keyword on the index.
    try {
        $rows = db()->query("SELECT name FROM categories WHERE slug IS NOT NULL AND slug <> '' ORDER BY slug LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $n) {
            if (!$n) continue;
            $kw[] = (string)$n . ' product key';
            $kw[] = 'buy ' . (string)$n . ' license';
        }
    } catch (Throwable $e) { /* table may not exist on a fresh install */ }

    // Page-specific tail keywords.
    if ($kind === 'shop') {
        $kw[] = 'shop all Microsoft software';
        $kw[] = 'all software products ' . date('Y');
        $kw[] = 'filter Microsoft software by year';
        $kw[] = 'filter Microsoft software by platform';
        $kw[] = 'compare Microsoft Office editions';
    } elseif ($kind === 'blog') {
        $kw[] = 'Microsoft software buying guide blog';
        $kw[] = 'Office activation tutorial';
        $kw[] = 'Windows 11 installation guide';
        $kw[] = 'Office 2024 review';
        $kw[] = 'Office 2021 vs 2024 comparison';
        $kw[] = SITE_BRAND . ' editorial blog';
    } else { // home
        $kw[] = 'Microsoft software store ' . date('Y');
        $kw[] = 'lowest price Microsoft Office keys';
        $kw[] = SITE_BRAND . ' homepage';
        $kw[] = 'shop genuine Microsoft software online';
    }

    // Dedupe (case-insensitive, preserve first-occurrence casing).
    $seen = []; $out = [];
    foreach ($kw as $k) {
        $k = trim((string)$k);
        if ($k === '') continue;
        $key = strtolower($k);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $k;
    }
    return implode(', ', $out);
}


/* ------------------------------------------------------------------
 *  blog_post_long_tail_keywords()
 *  Derives a comma-separated meta-keywords string from a blog_posts
 *  row.  Pulls signal from the title, the linked product (when present)
 *  via product_category_intent_keywords(), an H2/H3 scan of the post
 *  body and a small set of evergreen blog keyword stems.  Always
 *  returns at least 10 phrases so the audit score scales correctly.
 * ----------------------------------------------------------------- */
function blog_post_long_tail_keywords(array $post): string
{
    $title = trim((string)($post['title'] ?? ''));
    $kw = [];
    if ($title !== '') {
        $kw[] = $title;
        $kw[] = $title . ' ' . date('Y');
        $kw[] = $title . ' guide';
        $kw[] = $title . ' explained';
    }

    // Linked product → fold the full category-aware intent library in.
    if (!empty($post['product_id'])) {
        try {
            $stmt = db()->prepare('SELECT name, brand, category, platform FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$post['product_id']]);
            $prod = $stmt->fetch();
            if ($prod) {
                $kw[] = (string)$prod['name'] . ' license';
                $kw[] = (string)$prod['name'] . ' product key';
                $kw[] = 'buy ' . (string)$prod['name'];
                if (!empty($prod['brand']))    $kw[] = (string)$prod['brand'] . ' lifetime license';
                if (!empty($prod['category'])) $kw[] = (string)$prod['category'] . ' product key';
                // Use the category-aware intent dispatcher to surface
                // Office / Windows / Project-Visio / Antivirus libraries.
                $kw = array_merge($kw, product_category_intent_keywords($prod));
            }
        } catch (Throwable $e) { /* ignore — non-fatal */ }
    }

    // Detect intent clusters straight from the title (works even when
    // there is no linked product).
    $titleLc = strtolower($title);
    if (strpos($titleLc, 'office')  !== false) $kw[] = 'Microsoft Office lifetime license';
    if (strpos($titleLc, 'windows') !== false) $kw[] = 'Windows product key';
    if (strpos($titleLc, 'project') !== false) $kw[] = 'Microsoft Project Professional product key';
    if (strpos($titleLc, 'visio')   !== false) $kw[] = 'Microsoft Visio Professional product key';
    if (strpos($titleLc, 'antivirus') !== false || strpos($titleLc, 'bitdefender') !== false || strpos($titleLc, 'mcafee') !== false || strpos($titleLc, 'norton') !== false) {
        $kw[] = 'best antivirus product key';
    }
    if (preg_match('/\b(2024|2021|2019)\b/', $titleLc, $ym)) {
        $kw[] = 'Microsoft Office ' . $ym[1] . ' product key';
        $kw[] = 'buy Office ' . $ym[1] . ' lifetime license';
    }

    // H2 / H3 headings from the body — they tend to be high-intent
    // long-tail phrases ("How to activate Office 2021 on a new PC", etc.)
    if (!empty($post['content'])) {
        if (preg_match_all('#<(h2|h3)[^>]*>(.*?)</\1>#si', (string)$post['content'], $hm)) {
            foreach ($hm[2] as $heading) {
                $h = trim(strip_tags(html_entity_decode($heading)));
                if ($h !== '' && mb_strlen($h) <= 90) $kw[] = $h;
            }
        }
    }

    // Evergreen blog-side stems — high-value tail keywords that lift
    // every post a few points on the audit.
    $kw = array_merge($kw, [
        'genuine software keys',
        'lifetime software license',
        'one-time purchase software',
        'instant digital download',
        'genuine Microsoft activation',
        'cheap legitimate software',
        SITE_BRAND . ' editorial',
    ]);

    // Dedupe (case-insensitive) while keeping the original casing of the
    // first occurrence.
    $seen = []; $out = [];
    foreach ($kw as $k) {
        $k = trim($k);
        if ($k === '') continue;
        $key = strtolower($k);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $k;
    }
    return implode(', ', $out);
}

/* ------------------------------------------------------------------
 *  blog_post_breadcrumb_jsonld()
 *  BreadcrumbList structured data for a blog post.  Mirrors the
 *  visible breadcrumb HTML so AI search engines and Google Rich
 *  Results read the same hierarchy users see.
 * ----------------------------------------------------------------- */
function blog_post_breadcrumb_jsonld(array $post): array
{
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',  'item' => site_url() . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog',  'item' => site_url() . '/blog.php'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => (string)($post['title'] ?? 'Post'), 'item' => site_url() . '/blog-post.php?id=' . rawurlencode((string)($post['id'] ?? ''))],
        ],
    ];
}

/* ------------------------------------------------------------------
 *  category_buying_guide_html()
 *  Long-form on-page SEO copy block (H2/H3 hierarchy + intent-matched
 *  long-tail phrases).  Rendered visibly so it is indexable.
 * ----------------------------------------------------------------- */
function category_buying_guide_html(string $slug, string $title, int $productCount): string
{
    $year = date('Y');
    $slugLc   = strtolower($slug);
    $isOffice = (strpos($slugLc, 'office') !== false || strpos($slugLc, 'microsoft-') === 0 || $slug === 'apps')
                && strpos($slugLc, 'project') === false && strpos($slugLc, 'visio') === false;
    $isWin    = (preg_match('/\bwindows[-\s](10|11)/i', $slugLc) === 1) || in_array($slug, ['windows-11','windows-10','windows'], true);
    $isProj   = (strpos($slugLc, 'project') !== false);
    $isVisio  = (strpos($slugLc, 'visio')   !== false);
    $isAv     = (strpos($slugLc, 'bitdefender') !== false
                 || strpos($slugLc, 'mcafee') !== false
                 || strpos($slugLc, 'norton') !== false
                 || strpos($slugLc, 'kaspersky') !== false
                 || $slug === 'antivirus');
    $isMacCat = (strpos($slugLc, 'mac') !== false);
    $catYear  = '';
    if (preg_match('/\b(2024|2021|2019)\b/', $slugLc, $cym)) $catYear = $cym[1];

    $h = '<section class="cat-seo-copy mt-5" data-testid="category-seo-copy" aria-labelledby="cat-guide-heading">';
    $h .= '<h2 id="cat-guide-heading" class="fw-bold h4 mb-3">' . esc($title) . ' buying guide</h2>';
    $h .= '<p class="text-secondary">All ' . (int)$productCount . ' ' . esc($title) . ' listings above are <strong>genuine, perpetual licences</strong> delivered as a digital key by email. ';
    $h .= 'There are no monthly fees, no subscriptions and no rented &ldquo;cloud account&rdquo; that disappears if you stop paying. ';
    $h .= 'Pay once at the price you see, activate on your device, and the licence is yours for life. ';
    $h .= 'Below is a quick guide to picking the right edition, activating in minutes and getting help if you need it.</p>';

    if ($isOffice) {
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Which ' . esc($title) . ' edition should I buy?</h3>';
        $h .= '<p class="text-secondary">If you mainly write documents and crunch spreadsheets, the <strong>Home and Student</strong> edition (Word, Excel and PowerPoint) is the best value. ';
        $h .= 'If you also send a lot of email and run a small business, choose <strong>Home and Business</strong> &mdash; it adds Outlook. ';
        $h .= 'Power users who need Publisher and Access should go for <strong>Professional Plus</strong>. ';
        $h .= 'Every edition is a single one-time payment &mdash; no Microsoft 365 monthly fees, no renewals.</p>';

        if ($catYear !== '') {
            $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Why ' . esc($title) . ' specifically?</h3>';
            if ($catYear === '2024') {
                $h .= '<p class="text-secondary">Office 2024 is the latest perpetual release of Microsoft Office for Windows 11 and Windows 10 PCs (and macOS Sonoma / Sequoia for the Mac editions). Compared to Office 2021 you get a refreshed ribbon, native ARM64 support, faster cold-start times and the latest Word / Excel / PowerPoint feature drops. Best buy for shoppers searching "<em>Microsoft Office 2024 Professional Plus product key</em>", "<em>Office Home & Business 2024 (Mac)</em>" or "<em>latest Microsoft Office 2024 for Windows</em>".</p>';
            } elseif ($catYear === '2021') {
                $h .= '<p class="text-secondary">Office 2021 remains the best value-for-money perpetual release &mdash; the same core Word / Excel / PowerPoint feature set you would get from a Microsoft 365 subscription, but for a single one-time payment. Runs on Windows 11, Windows 10 and the Mac editions cover macOS Big Sur and newer. Targets "<em>Microsoft Office 2021 Professional Plus download</em>", "<em>Office 2021 Home & Student Mac</em>" and "<em>standalone Microsoft Word 2021 product key</em>".</p>';
            } elseif ($catYear === '2019') {
                $h .= '<p class="text-secondary">Office 2019 is the cheapest genuine perpetual Office release &mdash; ideal for older PCs (Windows 7 / 8.1 / 10) and tight budgets. Microsoft still ships security updates for Office 2019 LTSC until 2025+, so it remains a fully supported option. Targets "<em>Microsoft Office 2019 Professional Plus lifetime</em>" and "<em>cheap Office 2019 activation code</em>".</p>';
            }
        }

        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Mac or Windows?</h3>';
        if ($isMacCat) {
            $h .= '<p class="text-secondary">This category lists only the <strong>Mac</strong> editions of ' . esc($title) . '. They install via Microsoft AutoUpdate from microsoft.com (never a cracked DMG) and run natively on Apple Silicon and Intel Macs. Mac editions ship with <em>Word, Excel, PowerPoint and Outlook</em> (Home &amp; Business / Professional Plus) &mdash; Publisher and Access are Windows-only and not available on Mac. Use the <em>Platform</em> selector at the top of the page to swap to the Windows editions if needed.</p>';
        } else {
            $h .= '<p class="text-secondary">Each ' . esc($title) . ' listing is tagged with its operating system. Make sure you pick the <strong>Mac</strong> edition for macOS computers and the <strong>Windows / PC</strong> edition for laptops and desktops. ';
            $h .= 'If you accidentally buy the wrong one, we will exchange it free of charge within 30 days.</p>';
        }
    } elseif ($isWin) {
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Home, Pro or Education &mdash; which ' . esc($title) . ' edition?</h3>';
        $h .= '<p class="text-secondary">For home computers and personal laptops, the <strong>Home</strong> edition covers everyday use, gaming and family productivity. ';
        $h .= 'If you work from home, run virtual machines or need BitLocker drive encryption and Remote Desktop, choose <strong>Pro</strong>. ';
        $h .= 'Students and teachers can save more with the <strong>Education</strong> edition where listed.</p>';

        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Will ' . esc($title) . ' work on my PC?</h3>';
        $h .= '<p class="text-secondary">Microsoft publishes a hardware compatibility checker called <em>PC Health Check</em> &mdash; run it before buying if you are upgrading from an older Windows version. ';
        $h .= 'If your PC meets the minimum specifications (1 GHz CPU, 4 GB RAM, 64 GB storage and TPM 2.0 for Windows 11), this licence will activate without issue.</p>';

        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Retail vs OEM &mdash; what are you buying?</h3>';
        $h .= '<p class="text-secondary">Every Windows key listed here is the <strong>retail</strong> tier &mdash; transferable to a new PC under Microsoft\'s EULA. OEM keys (which ship pre-installed on store-bought laptops) are tied to the original motherboard for life. Pay a few dollars more for retail and the licence travels with you across hardware upgrades.</p>';
    } elseif ($isProj || $isVisio) {
        $kind = $isProj ? 'Project' : 'Visio';
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Which Microsoft ' . $kind . ' edition do I need?</h3>';
        if ($isProj) {
            $h .= '<p class="text-secondary">For solo project managers, freelance PMOs and construction estimators, <strong>Microsoft Project Standard</strong> ' . ($catYear ?: '2024') . ' is the cheapest legitimate option &mdash; full Gantt charts, baselines, resource pools and earned-value reports. Step up to <strong>Project Professional</strong> if you need <em>resource levelling</em>, <em>sync with SharePoint task lists</em>, <em>multi-project consolidation</em> or <em>integration with Microsoft Project Server / Project Online</em> for cross-team visibility. Both ship as one-time-purchase perpetual licences on Windows PC &mdash; no monthly Project Online seat fee.</p>';
        } else {
            $h .= '<p class="text-secondary"><strong>Microsoft Visio Standard</strong> ' . ($catYear ?: '2024') . ' covers everyday flowcharts, org charts, basic network diagrams and floor plans. Step up to <strong>Visio Professional</strong> for the full advanced shape library &mdash; <em>BPMN 2.0</em>, <em>UML 2.5</em>, <em>AWS / Azure / GCP architecture</em>, <em>network rack and electrical layouts</em>, <em>data-linked diagrams</em>, <em>SharePoint workflow design</em> and <em>SQL/Excel data integration</em>. Both ship as perpetual licences on Windows PC &mdash; no Visio Plan 1 or Plan 2 subscription required.</p>';
        }
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Installs alongside your existing Microsoft Office</h3>';
        $h .= '<p class="text-secondary">Microsoft ' . $kind . ' ' . ($catYear ?: '2024') . ' installs as a standalone app on the same Windows PC where Microsoft 365, Office 2024, Office 2021 or Office 2019 is already running. The installers do not conflict, your Word / Excel / PowerPoint / Outlook settings are preserved, and ' . $kind . ' simply appears as a new tile in the Start menu. Activation uses your Microsoft account so you keep the licence when you change PCs.</p>';
    } elseif ($isAv) {
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">How many devices do I need to cover?</h3>';
        $h .= '<p class="text-secondary">Each ' . esc($title) . ' listing shows how many devices the licence covers (1, 3, 5 or 10) and how long the protection lasts (1 or 2 years). ';
        $h .= 'For a single laptop, a 1-device subscription is perfect; for a whole family with phones and tablets, a 5- or 10-device plan is the best value per device.</p>';

        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Real-time protection vs full security suite</h3>';
        $h .= '<p class="text-secondary">If you just need malware and ransomware protection, the standard ' . esc($title) . ' antivirus is enough. ';
        $h .= 'Looking for a built-in VPN, password manager, parental controls and webcam protection? Pick a Total Security or Premium-tier edition where shown.</p>';

        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">No auto-renewal &mdash; ever</h3>';
        $h .= '<p class="text-secondary">Every antivirus listing on this page is a <strong>prepaid fixed-term subscription</strong>. Your card is never stored against the security brand\'s billing system because we activate the licence from a redemption code, not a recurring card. When the term ends, protection simply pauses &mdash; you renew at your own pace.</p>';
    } else {
        $h .= '<h3 class="fw-bold h5 mt-4 mb-2">How to pick the right ' . esc($title) . '</h3>';
        $h .= '<p class="text-secondary">Compare the editions above by platform (Windows or Mac), included apps, and number of devices. ';
        $h .= 'Filter using the <em>Platform</em> selector at the top of the page to narrow the list. Need help deciding? Use the <a href="contact.php">Request a Quote</a> link &mdash; our team replies within an hour.</p>';
    }

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Delivery, activation &amp; support &mdash; what to expect</h3>';
    $h .= '<p class="text-secondary"><strong>Delivery:</strong> ' . esc($title) . ' licence keys arrive by email within 15-30 minutes of completing payment, with the activation key, the official download link and step-by-step activation instructions.<br>';
    $h .= '<strong>Activation:</strong> The keys are activated directly inside the official ' . esc($title) . ' software &mdash; never through third-party loaders, cracks or modified installers.<br>';
    $h .= '<strong>Support:</strong> Live chat, email and phone support is available six days a week. Our specialists handle activation, transfers, downgrades and replacement keys at no extra charge.</p>';

    $h .= '<h3 class="fw-bold h5 mt-4 mb-2">Lowest verified prices on ' . esc($title) . ' in ' . $year . '</h3>';
    $h .= '<p class="text-secondary mb-0">' . esc(SITE_BRAND) . ' works directly with authorised distributors, which is how we offer ' . esc($title) . ' at <strong>up to 81% below retail</strong>. ';
    $h .= 'Every licence is paid for upfront, fully transferable and protected by our 30-day money-back guarantee. ';
    $h .= 'If you find ' . esc($title) . ' cheaper at another verified reseller, we will match the price &mdash; just send us the link.</p>';
    $h .= '</section>';
    return $h;
}

/* ------------------------------------------------------------------
 *  category_itemlist_jsonld()
 *  ItemList schema for a category page.  Strong category-page signal
 *  for Google &mdash; tells the crawler "here are the products on this
 *  page" so they can be indexed individually.
 * ----------------------------------------------------------------- */
function category_itemlist_jsonld(array $products, string $title): array
{
    $items = [];
    $pos = 1;
    $siteUrl = site_url();
    foreach ($products as $p) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'url'      => $siteUrl . '/product.php?slug=' . urlencode((string)$p['slug']),
            'name'     => (string)$p['name'],
        ];
    }
    return [
        '@context'       => 'https://schema.org',
        '@type'          => 'ItemList',
        'name'           => $title,
        'numberOfItems'  => count($items),
        'itemListElement'=> $items,
    ];
}

/* ------------------------------------------------------------------
 *  category_breadcrumb_jsonld()
 *  BreadcrumbList schema for a category page.
 * ----------------------------------------------------------------- */
function category_breadcrumb_jsonld(string $slug, string $title): array
{
    $siteUrl = site_url();
    return [
        '@context' => 'https://schema.org',
        '@type'    => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Shop', 'item' => $siteUrl . '/shop.php'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $title],
        ],
    ];
}

/* ------------------------------------------------------------------
 *  faq_to_jsonld()
 *  Convert an array of [{question,answer},...] to a FAQPage schema.
 *  Adds `speakable` so AI / voice assistants know which parts can be
 *  spoken aloud (improves AEO answer rate).
 * ----------------------------------------------------------------- */
function faq_to_jsonld(array $faqs): array
{
    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'speakable'  => [
            '@type'    => 'SpeakableSpecification',
            'cssSelector' => ['.pd-faq-accordion', '.cat-faq', '.pd-seo-copy'],
        ],
        'mainEntity' => array_map(function($f) {
            return [
                '@type'          => 'Question',
                'name'           => $f['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
            ];
        }, $faqs),
    ];
}

/* ------------------------------------------------------------------
 *  related_category_links()
 *  Returns a list of related category slugs + descriptive anchor text
 *  for the internal-linking cluster section.  Drives Google's PageRank
 *  graph by linking out with mid-tail keyword anchor text.
 * ----------------------------------------------------------------- */
function related_category_links(string $currentSlug): array
{
    $isOffice = (strpos($currentSlug, 'office') !== false || strpos($currentSlug, 'microsoft-') === 0 || $currentSlug === 'apps');
    $isWin    = (strpos($currentSlug, 'windows') !== false);
    $isAv     = (strpos($currentSlug, 'bitdefender') !== false || strpos($currentSlug, 'mcafee') !== false || $currentSlug === 'antivirus');

    $all = [
        ['slug' => 'office-pc',       'anchor' => 'Microsoft Office for PC &mdash; lifetime licence keys'],
        ['slug' => 'office-mac',      'anchor' => 'Microsoft Office for Mac &mdash; perpetual licence'],
        ['slug' => 'office-2024-pc',  'anchor' => 'Buy Microsoft Office 2024 for Windows'],
        ['slug' => 'office-2021-pc',  'anchor' => 'Microsoft Office 2021 product key &mdash; one-time purchase'],
        ['slug' => 'office-2019-pc',  'anchor' => 'Office 2019 licence key for Windows'],
        ['slug' => 'windows-11',      'anchor' => 'Windows 11 Pro genuine product key'],
        ['slug' => 'windows-10',      'anchor' => 'Windows 10 Pro &amp; Home activation key'],
        ['slug' => 'microsoft-project','anchor' => 'Microsoft Project 2024 / 2021 licence keys'],
        ['slug' => 'microsoft-visio', 'anchor' => 'Microsoft Visio 2024 / 2021 licence keys'],
        ['slug' => 'bitdefender',     'anchor' => 'Bitdefender Antivirus &amp; Total Security'],
        ['slug' => 'mcafee',          'anchor' => 'McAfee Total Protection multi-device plans'],
        ['slug' => 'antivirus',       'anchor' => 'Antivirus &amp; internet security software'],
    ];

    // Filter out the current category itself and re-rank by relevance.
    $filtered = array_values(array_filter($all, fn($x) => $x['slug'] !== $currentSlug));

    usort($filtered, function($a, $b) use ($isOffice, $isWin, $isAv) {
        $score = function($s) use ($isOffice, $isWin, $isAv) {
            $isAvLink = (strpos($s, 'bitdefender') !== false || strpos($s, 'mcafee') !== false || $s === 'antivirus');
            $isOfficeLink = (strpos($s, 'office') !== false || $s === 'apps' || strpos($s, 'microsoft-') === 0);
            $isWinLink = (strpos($s, 'windows') !== false);
            if ($isOffice && $isOfficeLink) return 0;
            if ($isWin && $isWinLink)       return 0;
            if ($isAv && $isAvLink)         return 0;
            return 1;
        };
        return $score($a['slug']) <=> $score($b['slug']);
    });

    return array_slice($filtered, 0, 8);
}

/* ------------------------------------------------------------------
 *  popular_search_terms()
 *  Mid-tail / long-tail keyword anchors linking to /shop.php?q=…
 *  Powers the "Popular searches" deep-link cluster used at the
 *  bottom of category and product pages.
 * ----------------------------------------------------------------- */
function popular_search_terms(string $context = ''): array
{
    $generic = [
        'Microsoft Office 2024 lifetime key',
        'Office 2021 Home and Business for Mac',
        'Windows 11 Pro product key',
        'Windows 10 Home activation key',
        'Bitdefender Total Security 5 devices',
        'McAfee Total Protection 3 device',
        'Microsoft Project 2024 licence',
        'Microsoft Visio 2021 product key',
        'Office for Mac one time purchase',
        'cheap Microsoft Office key online',
    ];
    return $generic;
}

/* =====================================================================
 *  AEO HELPERS — Answer Engine Optimization
 *  ---------------------------------------------------------------------
 *  Helpers that emit the page elements Google AI Overviews, Bing Chat,
 *  ChatGPT, Perplexity and voice assistants reward most: a 40-60 word
 *  direct answer at the top of the page; a "People Also Ask"-style
 *  related-questions block with answers visible as plain text; and
 *  visible breadcrumb trails that complement the BreadcrumbList JSON-LD.
 * =================================================================== */

/**
 * Render an AEO "Quick Answer" callout — a 40-60 word direct answer
 * styled as a sky-blue card.  This is the FIRST thing a featured-snippet
 * crawler grabs.  Pair with the page's H1 for maximum relevance.
 *
 * @param string $question  The short question this card answers.
 * @param string $answer    The 40-60 word answer (plain text or trusted HTML).
 * @param string $testid    Optional data-testid suffix.
 */
function render_aeo_answer(string $question, string $answer, string $testid = 'quick-answer'): string
{
    $q = esc(trim($question));
    return '<aside class="aeo-quick-answer" data-testid="' . esc($testid) . '" aria-labelledby="aeo-q-' . esc($testid) . '" '
         . 'style="border-left:4px solid #2563eb;background:linear-gradient(180deg,rgba(59,130,246,.08),rgba(59,130,246,.02));'
         . 'border-radius:10px;padding:14px 18px;margin:0 0 1.25rem;">'
         . '<div class="d-flex align-items-center gap-2 mb-2">'
         . '<i class="bi bi-lightning-charge-fill" style="color:#2563eb;font-size:18px;"></i>'
         . '<strong id="aeo-q-' . esc($testid) . '" class="small text-uppercase" style="letter-spacing:.5px;color:#1e40af;">Quick answer</strong>'
         . '</div>'
         . '<div class="fw-bold mb-1" style="font-size:15px;">' . $q . '</div>'
         . '<div class="aeo-answer-body" data-testid="' . esc($testid) . '-body" style="font-size:14px;line-height:1.55;color:#1e293b;">' . $answer . '</div>'
         . '</aside>';
}

/**
 * Render a visible "People Also Ask" / related-questions block.
 * Each Q→A pair becomes an accordion entry visually AND serializes
 * to a separate FAQPage JSON-LD via faq_to_jsonld() in the caller.
 *
 * @param array  $faqs    [{question, answer}, ...]
 * @param string $heading Heading text.
 * @param string $testid  Optional data-testid suffix.
 */
function render_paa_block(array $faqs, string $heading = 'People also ask', string $testid = 'paa-block'): string
{
    if (!$faqs) return '';
    $h  = '<section class="paa-block mt-5" aria-labelledby="paa-heading-' . esc($testid) . '" data-testid="' . esc($testid) . '">';
    $h .= '<div class="d-flex align-items-center gap-2 mb-3">';
    $h .= '<i class="bi bi-question-diamond-fill" style="font-size:22px;color:#2563eb;"></i>';
    $h .= '<h2 id="paa-heading-' . esc($testid) . '" class="fw-bold h4 mb-0">' . esc($heading) . '</h2>';
    $h .= '</div>';
    $h .= '<div class="accordion pd-faq-accordion" id="' . esc($testid) . '-acc">';
    foreach ($faqs as $idx => $f) {
        $itemId = $testid . '-q-' . $idx;
        $h .= '<div class="accordion-item">';
        $h .= '<h3 class="accordion-header"><button class="accordion-button ' . ($idx > 0 ? 'collapsed' : '') . '" '
            . 'type="button" data-bs-toggle="collapse" data-bs-target="#' . esc($itemId) . '" '
            . 'aria-expanded="' . ($idx === 0 ? 'true' : 'false') . '" aria-controls="' . esc($itemId) . '" '
            . 'data-testid="' . esc($testid) . '-q-' . $idx . '">' . esc($f['question']) . '</button></h3>';
        $h .= '<div id="' . esc($itemId) . '" class="accordion-collapse collapse ' . ($idx === 0 ? 'show' : '') . '" '
            . 'data-bs-parent="#' . esc($testid) . '-acc">';
        $h .= '<div class="accordion-body" data-testid="' . esc($testid) . '-a-' . $idx . '">' . $f['answer'] . '</div>';
        $h .= '</div></div>';
    }
    $h .= '</div></section>';
    return $h;
}

/**
 * Generate up to 6 product-aware "People Also Ask" Q→A pairs.  Plain
 * deterministic templates so the block renders even when the AI bot
 * hasn't filled in custom FAQs yet.  Adopt the same 40-60 word answer
 * pattern Google promotes in AI Overviews.
 */
function product_paa_faqs(array $p): array
{
    $name     = (string)$p['name'];
    $brand    = product_detected_brand($p);
    $platform = $p['platform'] ?: 'Windows';
    $price    = format_price((float)$p['price']);
    return [
        [
            'question' => 'Where is the cheapest place to buy ' . $name . '?',
            'answer'   => esc(SITE_BRAND) . ' sells ' . esc($name) . ' for ' . esc($price) . ' &mdash; up to 81% below the manufacturer&rsquo;s retail price. We work directly with authorised channels, which is how we keep prices low while guaranteeing every key is genuine, activates inside the official ' . esc($brand) . ' installer, and ships with a 30-day money-back protection.',
        ],
        [
            'question' => 'How long does ' . $name . ' delivery take?',
            'answer'   => 'Your ' . esc($name) . ' licence key arrives by email within 15&ndash;30 minutes of completing payment &mdash; often in seconds. The email contains the activation key, the official ' . esc($brand) . ' download link and step-by-step instructions. There is no physical shipping; everything is digital.',
        ],
        [
            'question' => 'Will ' . $name . ' work on my ' . esc($platform) . ' device?',
            'answer'   => 'Yes &mdash; this listing is the ' . esc($platform) . ' edition of ' . esc($name) . '. As long as your computer meets ' . esc($brand) . '&rsquo;s minimum system requirements (any ' . esc($platform) . ' machine from the last ~5 years), this key will activate without issue. We exchange any wrong-platform purchase free of charge within 30 days.',
        ],
        [
            'question' => 'Is ' . $name . ' a subscription or a one-time purchase?',
            'answer'   => 'Every ' . esc($name) . ' listing on ' . esc(SITE_BRAND) . ' is a one-time purchase with a perpetual (lifetime) licence. Pay once, activate on your device, and use the software for as long as you own the computer. There are no monthly fees, no renewals and no automatic re-billing.',
        ],
        [
            'question' => 'What happens if my ' . $name . ' key fails to activate?',
            'answer'   => 'In the rare case a key fails to activate, contact support within 30 days. We will either send a working replacement key at no extra cost or issue a full refund &mdash; your choice. Most activation issues are resolved by our specialists in under 10 minutes via live chat.',
        ],
        [
            'question' => 'Can I install ' . $name . ' on more than one computer?',
            'answer'   => 'Each ' . esc($name) . ' licence covers a single device by default. If you need to move the licence to a new computer (e.g. when upgrading your laptop), our support team transfers it for you free of charge. Multi-device family packs are listed separately on the relevant category page.',
        ],
    ];
}

/**
 * Render a visible breadcrumb <nav> wired to the same crumbs the
 * JSON-LD BreadcrumbList is built from.  Use this so Google AND
 * AI search engines see consistent navigation context.
 *
 * @param array $crumbs  [['name'=>'Home', 'url'=>'/'], ...]  Last item omits url.
 */
function render_breadcrumb_nav(array $crumbs, string $testid = 'breadcrumb'): string
{
    if (!$crumbs) return '';
    $h  = '<nav aria-label="breadcrumb" data-testid="' . esc($testid) . '">';
    $h .= '<ol class="breadcrumb small mb-3">';
    foreach ($crumbs as $i => $c) {
        $isLast = $i === count($crumbs) - 1;
        $name   = esc((string)($c['name'] ?? ''));
        $url    = $c['url']  ?? '';
        if ($isLast || $url === '') {
            $h .= '<li class="breadcrumb-item active" aria-current="page">' . $name . '</li>';
        } else {
            $h .= '<li class="breadcrumb-item"><a href="' . esc((string)$url) . '">' . $name . '</a></li>';
        }
    }
    $h .= '</ol></nav>';
    return $h;
}

/* =====================================================================
 *  TOPIC CLUSTER HUB HELPERS
 *  --------------------------------------------------------------------
 *  Every hub lives in the `topic_hubs` table (auto-seeded on first run
 *  with the three legacy default topics).  These helpers are the single
 *  read-point used by hub.php, sitemap-xml.php, the admin panel and
 *  product.php / category.php "Topic Hub" backlink renders.
 * ===================================================================== */

/** Default hubs that get seeded if `topic_hubs` is empty.  Mirrors the
 *  legacy in-file $TOPICS array so a fresh install ships with content. */
function _topic_hub_default_seeds(): array
{
    $brand = function_exists('site_brand_safe') ? site_brand_safe() : (defined('SITE_BRAND') ? SITE_BRAND : 'our store');
    return [
        [
            'slug'       => 'microsoft-office',
            'title'      => 'Microsoft Office — the complete buying guide',
            'headline'   => 'Microsoft Office is a one-time-purchase office suite (Word, Excel, PowerPoint, Outlook, Publisher, Access) sold by ' . $brand . ' at up to 81% below retail. Every licence is genuine, lifetime, activates inside the official Microsoft installer, delivered by email in 15-30 minutes, and protected by a 30-day money-back guarantee.',
            'audience'   => 'home users, students, freelancers and small-business owners choosing between Office 2024, 2021 and 2019 on Windows or Mac',
            'categories' => ['office-pc','office-mac','office-2024-pc','office-2021-pc','office-2019-pc','office-2024-mac','office-2021-mac','office-2019-mac','apps','microsoft-project','microsoft-visio'],
            'blogTags'   => ['%office%','%word%','%excel%','%powerpoint%','%outlook%','%microsoft 365%','%publisher%'],
            'keywords'   => 'Microsoft Office, Office 2024, Office 2021, Office 2019, Office for Mac, Office for PC, Office lifetime license, Office one time purchase, buy Microsoft Office key, Microsoft Office product key, Office Home and Student, Office Home and Business, Office Professional Plus, Microsoft Project, Microsoft Visio',
            'aboutLink'  => 'category.php?slug=apps',
            'color'      => '#dc2626',
            'videos'     => [],
        ],
        [
            'slug'       => 'windows',
            'title'      => 'Microsoft Windows — Windows 11, 10 and Pro buying guide',
            'headline'   => 'Microsoft Windows is the world\'s most-used desktop operating system. ' . $brand . ' sells genuine Windows 11 and Windows 10 product keys (Home, Pro and Education) at up to 81% off retail. Pay once, activate inside the official Windows setup, and keep the licence for life — instant email delivery and 30-day guarantee.',
            'audience'   => 'self-builders, IT teams and home upgraders looking for a genuine Windows 11 Pro or Windows 10 product key',
            'categories' => ['windows-11','windows-10','windows','os'],
            'blogTags'   => ['%windows 11%','%windows 10%','%windows%'],
            'keywords'   => 'Microsoft Windows, Windows 11 Pro, Windows 11 Home, Windows 10 Pro, Windows 10 Home, Windows product key, buy Windows 11 key, Windows lifetime activation, Windows OEM key, Windows 11 vs 10, upgrade to Windows 11',
            'aboutLink'  => 'category.php?slug=windows-11',
            'color'      => '#0078d4',
            'videos'     => [],
        ],
        [
            'slug'       => 'antivirus',
            'title'      => 'Antivirus software — Bitdefender, McAfee & internet-security buying guide',
            'headline'   => 'Modern antivirus software protects every device in your household from malware, ransomware and identity theft. ' . $brand . ' carries genuine Bitdefender and McAfee licences for 1, 3, 5 and 10 devices at up to 81% off retail. Activates inside the official vendor installer, delivered by email, with our 30-day money-back guarantee.',
            'audience'   => 'home users, families and small businesses choosing between Bitdefender Total Security, McAfee Total Protection and other paid antivirus suites',
            'categories' => ['antivirus','bitdefender','mcafee','internet-security'],
            'blogTags'   => ['%bitdefender%','%mcafee%','%antivirus%','%malware%','%ransomware%','%internet security%'],
            'keywords'   => 'antivirus, Bitdefender Total Security, McAfee Total Protection, internet security, anti-malware, ransomware protection, family antivirus plans, best antivirus 2026, antivirus for Mac, multi-device antivirus',
            'aboutLink'  => 'category.php?slug=antivirus',
            'color'      => '#16a34a',
            'videos'     => [],
        ],
    ];
}

/** Seed default hubs into the DB if the table is empty (idempotent). */
function topic_hubs_seed_defaults(): void
{
    static $seeded = false;
    if ($seeded) return;
    $seeded = true;
    try {
        ensure_db_schema();
        $pdo = db();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM topic_hubs")->fetchColumn();
        if ($count > 0) return;
        $stmt = $pdo->prepare("INSERT IGNORE INTO topic_hubs
            (slug, title, headline, audience, categories_json, blog_tags_json, keywords, about_link, color, videos_json, active, source)
            VALUES (?,?,?,?,?,?,?,?,?,?,1,'seed')");
        foreach (_topic_hub_default_seeds() as $h) {
            $stmt->execute([
                $h['slug'], $h['title'], $h['headline'], $h['audience'],
                json_encode($h['categories']), json_encode($h['blogTags']),
                $h['keywords'], $h['aboutLink'], $h['color'],
                json_encode($h['videos']),
            ]);
        }
    } catch (Throwable $e) {
        @error_log('[topic_hubs_seed_defaults] ' . $e->getMessage());
    }
}

/** Hydrate a `topic_hubs` row into a hub array (legacy $TOPICS shape). */
function _topic_hub_hydrate(array $row): array
{
    $cats   = json_decode((string)($row['categories_json'] ?? '[]'), true);
    $tags   = json_decode((string)($row['blog_tags_json']  ?? '[]'), true);
    $videos = json_decode((string)($row['videos_json']     ?? '[]'), true);
    return [
        'id'         => (int)($row['id'] ?? 0),
        'slug'       => (string)($row['slug'] ?? ''),
        'title'      => (string)($row['title'] ?? ''),
        'headline'   => (string)($row['headline'] ?? ''),
        'audience'   => (string)($row['audience'] ?? ''),
        'categories' => is_array($cats) ? $cats : [],
        'blogTags'   => is_array($tags) ? $tags : [],
        'keywords'   => (string)($row['keywords'] ?? ''),
        'aboutLink'  => (string)($row['about_link'] ?? ''),
        'color'      => (string)($row['color'] ?? '#0078d4'),
        'videos'     => is_array($videos) ? $videos : [],
        'active'     => (int)($row['active'] ?? 1),
        'source'     => (string)($row['source'] ?? 'manual'),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}

/** Returns every hub (default: active only) keyed by slug. */
function topic_hubs_all(bool $activeOnly = true): array
{
    topic_hubs_seed_defaults();
    try {
        $sql = "SELECT * FROM topic_hubs" . ($activeOnly ? " WHERE active=1" : "") . " ORDER BY id ASC";
        $rows = db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) { $h = _topic_hub_hydrate($r); $out[$h['slug']] = $h; }
    return $out;
}

/** Fetch one hub by slug, or null if missing / inactive. */
function topic_hub_by_slug(string $slug, bool $activeOnly = true): ?array
{
    topic_hubs_seed_defaults();
    try {
        $sql = "SELECT * FROM topic_hubs WHERE slug = ?" . ($activeOnly ? " AND active=1" : "") . " LIMIT 1";
        $st  = db()->prepare($sql);
        $st->execute([$slug]);
        $r = $st->fetch();
    } catch (Throwable $e) { return null; }
    return $r ? _topic_hub_hydrate($r) : null;
}

/** Find every hub that contains a given category slug — used by category.php
 *  and product.php to render a "Part of topic hub" backlink. */
function topic_hubs_for_category(string $categorySlug): array
{
    $hubs = topic_hubs_all(true);
    $cat  = strtolower(trim($categorySlug));
    if ($cat === '') return [];
    $out = [];
    foreach ($hubs as $h) {
        foreach ($h['categories'] as $c) {
            if (strtolower((string)$c) === $cat) { $out[] = $h; break; }
        }
    }
    return $out;
}

/** Convenience wrapper for products. */
function topic_hubs_for_product(array $p): array
{
    return topic_hubs_for_category((string)($p['category'] ?? ''));
}

/** Auto-generate hubs from top product categories.  Picks every category
 *  that has >= $minProducts active products and isn't already covered by
 *  an existing hub, then inserts a new auto-source hub for each.
 *  Returns the list of new slugs created. */
/**
 * Best-effort LLM call that polishes a single topic-hub's copy.
 * Returns ['title' => …, 'headline' => …, 'audience' => …, 'keywords' => …]
 * — or null if the AI key is missing / the call fails for any reason
 * (caller falls back to the static templates so the function NEVER blocks
 * hub creation on a flaky LLM).
 */
function topic_hub_ai_polish(string $categorySlug, string $brand): ?array
{
    if (!defined('OPENAI_API_KEY') || !defined('OPENAI_BASE_URL') || OPENAI_API_KEY === '') return null;
    $catTitle = function_exists('category_title') ? category_title($categorySlug) : ucwords(str_replace('-', ' ', $categorySlug));
    if ($catTitle === '' || strtolower($catTitle) === strtolower($categorySlug)) {
        $catTitle = ucwords(str_replace('-', ' ', $categorySlug));
    }
    $prompt = "Write the on-page copy for a topical-authority HUB landing page at /hub/" . $categorySlug . " on \"" . $brand . "\".\n"
            . "Topic / category: \"" . $catTitle . "\".\n\n"
            . "Return STRICT JSON, schema:\n"
            . "{\n"
            . "  \"title\":    \"<short H1 — 6 to 10 words — for the hub landing page>\",\n"
            . "  \"headline\": \"<3 to 4 sentences, 65 to 110 words, telling shoppers what's on this page and why they should trust the recommendations — premium calm trustworthy tone, no hype>\",\n"
            . "  \"audience\": \"<one sentence describing who the page is for>\",\n"
            . "  \"keywords\": \"<10 to 15 comma-separated SEO keyword phrases ranging from short-tail to long-tail — include 'buy', 'license key', 'lifetime activation', 'best deals' variants>\"\n"
            . "}\n\n"
            . "RULES:\n- Plain text only, no markdown, no emoji, no asterisks.\n- Never invent product names that don't exist (talk about the category, not specific SKUs).\n- Never mention prices or discount percentages.\n- The headline must include the phrases 'genuine licence' (or 'genuine licences'), 'instant email delivery', and one trust signal.";

    $body = json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role'=>'system','content'=>'You return strict JSON only. Never use markdown. Never wrap output in code fences.'],
            ['role'=>'user','content'=>$prompt],
        ],
        'temperature' => 0.55,
        'max_tokens'  => 600,
        'response_format' => ['type' => 'json_object'],
    ]);
    $ch = curl_init(rtrim(OPENAI_BASE_URL, '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp     = (string)curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || $resp === '') return null;
    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || $content === '') return null;
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) return null;
    // Trim + validate the four expected keys; bail if any are missing/empty.
    $title    = trim((string)($parsed['title']    ?? ''));
    $headline = trim((string)($parsed['headline'] ?? ''));
    $audience = trim((string)($parsed['audience'] ?? ''));
    $keywords = trim((string)($parsed['keywords'] ?? ''));
    if ($title === '' || $headline === '' || $audience === '' || $keywords === '') return null;
    return ['title' => $title, 'headline' => $headline, 'audience' => $audience, 'keywords' => $keywords];
}

function topic_hubs_auto_generate(int $minProducts = 2): array
{
    topic_hubs_seed_defaults();
    $pdo  = db();
    $hubs = topic_hubs_all(false);
    // Track ONLY existing hub-slugs to avoid creating duplicate rows for the
    // same category-slug.  We deliberately do NOT skip categories that are
    // already part of an umbrella hub's `categories_json` list — those
    // umbrella hubs (e.g. `microsoft-office`) are broad theme pages, the
    // auto-gen creates focused per-category siblings (`office-2021-pc`,
    // `office-2024-pc`, …) that complement them with deeper topical signal.
    $existingHubSlugs = [];
    foreach ($hubs as $h) $existingHubSlugs[strtolower((string)$h['slug'])] = 1;

    $rows = $pdo->query(
        "SELECT LOWER(category) AS cat, COUNT(*) AS n
           FROM products
          WHERE is_active=1 AND category IS NOT NULL AND category <> ''
          GROUP BY LOWER(category)
         HAVING n >= " . (int)$minProducts . "
          ORDER BY n DESC"
    )->fetchAll();

    $created    = [];
    $skipped    = []; // categories that already have a same-slug hub
    $aiPolished = []; // category slugs whose copy was AI-generated (vs templated)
    $insert = $pdo->prepare("INSERT IGNORE INTO topic_hubs
        (slug, title, headline, audience, categories_json, blog_tags_json, keywords, about_link, color, videos_json, active, source)
        VALUES (?,?,?,?,?,?,?,?,?,?,1,'auto')");
    $palette = ['#0078d4','#16a34a','#dc2626','#9333ea','#0ea5e9','#f59e0b','#ec4899','#22c55e','#6366f1','#14b8a6'];
    $brand   = function_exists('site_brand_safe') ? site_brand_safe() : (defined('SITE_BRAND') ? SITE_BRAND : 'our store');
    // Cap the LLM calls per run so a slow GPT round-trip doesn't time out
    // the admin page.  Templates take over once the cap is hit.
    $aiCallsRemaining = 8;

    foreach ($rows as $r) {
        $slugCat = (string)$r['cat'];
        if ($slugCat === '') continue;
        if (isset($existingHubSlugs[$slugCat])) {
            $skipped[] = $slugCat;
            continue;
        }
        $catTitle = function_exists('category_title') ? category_title($slugCat) : ucwords(str_replace('-', ' ', $slugCat));
        if ($catTitle === '' || strtolower($catTitle) === strtolower($slugCat)) {
            $catTitle = ucwords(str_replace('-', ' ', $slugCat));
        }
        // Try AI first (better headline + audience + keywords).  Fall back
        // to the original templates if the LLM is unavailable / slow / off-budget.
        $ai = null;
        if ($aiCallsRemaining > 0) {
            $ai = topic_hub_ai_polish($slugCat, $brand);
            $aiCallsRemaining--;
        }
        if ($ai) {
            $hubTitle = $ai['title'];
            $headline = $ai['headline'];
            $audience = $ai['audience'];
            $keywords = $ai['keywords'];
            $aiPolished[] = $slugCat;
        } else {
            $hubTitle = $catTitle . ' — buying guide & best picks';
            $headline = $catTitle . ' products are available at ' . $brand . ' with genuine licences, lifetime activation and instant email delivery. Compare the most popular ' . $catTitle . ' titles, read editorial guides, and get answers to common buyer questions on one page.';
            $audience = 'shoppers comparing ' . $catTitle . ' options before they buy';
            $keywords = $catTitle . ', buy ' . $catTitle . ', ' . $catTitle . ' license key, best ' . $catTitle . ' deals, ' . $catTitle . ' product key, ' . $catTitle . ' lifetime activation';
        }
        $tags  = ['%' . strtolower($catTitle) . '%'];
        $color = $palette[count($created) % count($palette)];
        try {
            $insert->execute([
                $slugCat, $hubTitle, $headline, $audience,
                json_encode([$slugCat]), json_encode($tags),
                $keywords, 'category.php?slug=' . $slugCat, $color, json_encode([]),
            ]);
            if ($pdo->lastInsertId()) {
                $created[] = $slugCat;
                $existingHubSlugs[$slugCat] = 1;
            }
        } catch (Throwable $e) {
            @error_log('[topic_hubs_auto_generate] ' . $e->getMessage());
        }
    }
    // Expose ancillary results to the admin handler via globals (lighter
    // than restructuring the public return type, which other callers depend on).
    $GLOBALS['__topic_hubs_skipped']     = $skipped;
    $GLOBALS['__topic_hubs_ai_polished'] = $aiPolished;
    return $created;
}

/** VideoObject JSON-LD array for a single YouTube URL (best-effort). */
function topic_hub_video_jsonld(array $video, array $hub): ?array
{
    $url = trim((string)($video['url'] ?? ''));
    if ($url === '') return null;
    $vid = '';
    if (preg_match('#(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_\-]{11})#', $url, $m)) $vid = $m[1];
    $thumb = $vid !== '' ? 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg' : '';
    return [
        '@context'     => 'https://schema.org',
        '@type'        => 'VideoObject',
        'name'         => (string)($video['title'] ?? ('Video — ' . strip_tags((string)$hub['title']))),
        'description'  => (string)($video['title'] ?? strip_tags((string)$hub['headline'])),
        'thumbnailUrl' => $thumb,
        'uploadDate'   => $hub['updated_at'] ?? date('c'),
        'contentUrl'   => $url,
        'embedUrl'     => $vid !== '' ? 'https://www.youtube.com/embed/' . $vid : $url,
    ];
}

/** Helper safe-brand reader (works pre-bootstrap). */
function site_brand_safe(): string
{
    return defined('SITE_BRAND') ? SITE_BRAND : 'our store';
}

/* =====================================================================
 *  GSC QUERY CLUSTERING (SEO Discovery Lab)
 *  --------------------------------------------------------------------
 *  Admins upload the "Performance Report" CSV from Search Console.  We
 *  tokenise every query, fold equivalent terms (singular/plural, stop
 *  words) and group queries that share their two top tokens — the
 *  resulting clusters become "create new hub" suggestions ranked by
 *  total impressions.
 * ===================================================================== */

/** Tokenise a query into significant lowercase words. */
function gsc_tokenise(string $q): array
{
    $stop = ['the','a','an','for','to','of','and','or','with','is','are','vs','in','my','on','at','by','from','this','best','top'];
    $q = strtolower(trim($q));
    $q = preg_replace('/[^a-z0-9\s\-]+/u', ' ', $q) ?? '';
    $parts = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($parts as $p) {
        if (strlen($p) < 3 || in_array($p, $stop, true)) continue;
        // crude singularisation: strip trailing 's' for length-5+ words
        if (strlen($p) >= 5 && substr($p, -1) === 's' && substr($p, -2) !== 'ss') $p = substr($p, 0, -1);
        $out[] = $p;
    }
    return $out;
}

/** Compute a cluster key (two most-relevant tokens) for a query. */
function gsc_cluster_key(string $q): string
{
    $t = gsc_tokenise($q);
    if (count($t) === 0) return 'other';
    sort($t);                  // stable order
    return implode('-', array_slice(array_values(array_unique($t)), 0, 2));
}

/** Parse a Search Console Performance CSV and persist queries.  Returns
 *  ['inserted' => N, 'skipped' => N, 'errors' => []]. */
function gsc_import_csv(string $csvText): array
{
    $report = ['inserted' => 0, 'skipped' => 0, 'errors' => []];
    $csvText = preg_replace('/^\xEF\xBB\xBF/', '', $csvText) ?? $csvText; // strip BOM
    $lines = preg_split('/\r\n|\n|\r/', trim($csvText));
    if (!$lines || count($lines) < 2) {
        $report['errors'][] = 'CSV is empty or missing rows.';
        return $report;
    }
    $headerRaw = array_shift($lines);
    $header    = array_map(static fn($s) => strtolower(trim($s)), str_getcsv((string)$headerRaw));
    $find = static fn(array $opts) => (function() use ($header, $opts) {
        foreach ($opts as $opt) { $i = array_search($opt, $header, true); if ($i !== false) return $i; }
        return -1;
    })();
    $idxQ = $find(['query','top queries','search query']);
    $idxI = $find(['impressions','total impressions','impr.']);
    $idxC = $find(['clicks','total clicks']);
    $idxP = $find(['position','avg. position','average position']);
    $idxR = $find(['ctr','avg. ctr']);
    if ($idxQ < 0) { $report['errors'][] = 'No "Query" column found.'; return $report; }

    $pdo = db();
    $upsert = $pdo->prepare(
        "INSERT INTO gsc_queries (query, impressions, clicks, ctr, position, cluster_key, uploaded_at)
         VALUES (?,?,?,?,?,?, NOW())
         ON DUPLICATE KEY UPDATE
           impressions = VALUES(impressions),
           clicks      = VALUES(clicks),
           ctr         = VALUES(ctr),
           position    = VALUES(position),
           cluster_key = VALUES(cluster_key),
           uploaded_at = NOW()"
    );
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        $q   = trim((string)($row[$idxQ] ?? ''));
        if ($q === '' || mb_strlen($q) > 250) { $report['skipped']++; continue; }
        $impr = $idxI >= 0 ? (int)str_replace([',', ' '], '', (string)($row[$idxI] ?? 0)) : 0;
        $clk  = $idxC >= 0 ? (int)str_replace([',', ' '], '', (string)($row[$idxC] ?? 0)) : 0;
        $pos  = $idxP >= 0 ? (float)str_replace(',', '.', (string)($row[$idxP] ?? 0)) : 0.0;
        $ctrRaw = $idxR >= 0 ? (string)($row[$idxR] ?? '0') : '0';
        $ctr  = (float)str_replace(['%', ',', ' '], ['', '.', ''], $ctrRaw);
        try {
            $upsert->execute([$q, $impr, $clk, $ctr, $pos, gsc_cluster_key($q)]);
            $report['inserted']++;
        } catch (Throwable $e) {
            $report['skipped']++;
            if (count($report['errors']) < 5) $report['errors'][] = $e->getMessage();
        }
    }
    return $report;
}

/** Return up to $limit query clusters ranked by total impressions.  Each
 *  cluster carries sample queries + a suggested hub slug/title. */
function gsc_top_clusters(int $limit = 15): array
{
    try {
        $rows = db()->query(
            "SELECT cluster_key,
                    COUNT(*)              AS query_count,
                    SUM(impressions)      AS impressions,
                    SUM(clicks)           AS clicks,
                    GROUP_CONCAT(query ORDER BY impressions DESC SEPARATOR '|') AS sample
               FROM gsc_queries
              WHERE cluster_key <> ''
              GROUP BY cluster_key
              ORDER BY impressions DESC
              LIMIT " . (int)$limit
        )->fetchAll();
    } catch (Throwable $e) { return []; }
    $hubs = topic_hubs_all(false);
    $existingSlugs = array_keys($hubs);
    $out = [];
    foreach ($rows as $r) {
        $samples = array_slice(array_filter(explode('|', (string)$r['sample'])), 0, 6);
        $hubSlug = preg_replace('/[^a-z0-9\-]/', '', (string)$r['cluster_key']) ?: 'topic';
        $exists  = in_array($hubSlug, $existingSlugs, true);
        $out[] = [
            'cluster_key'   => (string)$r['cluster_key'],
            'query_count'   => (int)$r['query_count'],
            'impressions'   => (int)$r['impressions'],
            'clicks'        => (int)$r['clicks'],
            'samples'       => array_values($samples),
            'suggested_slug'  => $hubSlug,
            'suggested_title' => ucwords(str_replace('-', ' ', (string)$r['cluster_key'])) . ' — buying guide & top picks',
            'already_exists'  => $exists,
        ];
    }
    return $out;
}
