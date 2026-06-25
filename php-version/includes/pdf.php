<?php
/*
 * Receipt + Invoice PDF generators — used by send_email() to attach
 * proper, professionally-formatted PDFs to every paid order email.
 *
 * Layout closely mirrors the reference Emergent receipt / invoice style
 * the product owner provided: clean sans-serif, two-column header with
 * company info on the left + brand logo / receipt number on the right,
 * "Bill to" customer block, single line-items table with right-aligned
 * currency, summary totals, payment-history table (for the receipt
 * variant only), and a clear statement-name line so the customer knows
 * what to look for on their bank statement.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Resolve the company logo to a local file path that Dompdf can embed.
 * Falls back to the bundled email logo if no company logo is configured
 * (or the configured URL points to an external host — Dompdf is sandboxed
 * to the local filesystem via isRemoteEnabled=false).
 */
function _pdf_company_logo_path(): string
{
    $fallback = __DIR__ . '/../assets/images/brand/email-logo.gif';
    $logoSetting = function_exists('company_info') ? (string)(company_info()['logo'] ?? '') : '';
    if ($logoSetting === '') return $fallback;

    // Already an absolute filesystem path?
    if ($logoSetting[0] === '/' && file_exists($logoSetting)) return $logoSetting;

    // Strip the site URL prefix if the operator pasted a full URL.
    $rel = $logoSetting;
    $candidates = [
        function_exists('site_url') ? rtrim((string)site_url(), '/') : '',
        function_exists('base_url') ? rtrim((string)base_url(), '/') : '',
    ];
    foreach (array_filter($candidates) as $c) {
        if (str_starts_with($rel, $c)) { $rel = substr($rel, strlen($c)); break; }
    }
    $rel = ltrim((string)$rel, '/');
    $abs = __DIR__ . '/../' . $rel;
    return (file_exists($abs) && !is_dir($abs)) ? $abs : $fallback;
}

/**
 * Build a Dompdf instance with sane defaults for our receipts/invoices.
 */
function _pdf_dompdf(): Dompdf
{
    $o = new Options();
    $o->set('defaultFont',           'DejaVu Sans');   // ships with Dompdf
    $o->set('isHtml5ParserEnabled',  true);
    $o->set('isRemoteEnabled',       false);           // we never load remote assets
    $o->set('chroot',                __DIR__ . '/..'); // keep file access local
    return new Dompdf($o);
}

/**
 * Number → currency formatter that matches what we show on the site
 * (uses the symbol of the order's currency, not the active session one).
 */
function _pdf_money(float $amount, string $cur = 'USD'): string
{
    $sym = ['USD'=>'$','GBP'=>'£','EUR'=>'€','CAD'=>'CA$','AUD'=>'A$','INR'=>'₹','AED'=>'د.إ'][$cur] ?? '';
    return $sym . number_format($amount, 2);
}

/**
 * Detect the dominant brand for an order from its line items.
 * Used to pick the right watermark on the Receipt / Invoice.
 *
 * Order of precedence:
 *   1) products.brand column (DB-authoritative)
 *   2) keyword scan of the product name
 *   3) generic 'maventech' fallback (our own brand mark)
 */
function _pdf_brand_for_items(array $items): string
{
    if (empty($items)) return 'maventech';
    // Honour an explicit `brand` field if the caller passes it.
    foreach ($items as $it) {
        $b = strtolower(trim((string)($it['brand'] ?? '')));
        if ($b !== '') {
            foreach (['microsoft','bitdefender','mcafee','norton','kaspersky','eset','adobe','autodesk','corel','parallels'] as $known) {
                if (str_contains($b, $known)) return $known;
            }
        }
    }
    // Fall back to keyword scan on the first item's name.
    $name = strtolower(trim((string)($items[0]['name'] ?? $items[0]['product_name'] ?? '')));
    foreach ([
        'bitdefender' => ['bitdefender'],
        'mcafee'      => ['mcafee'],
        'norton'      => ['norton'],
        'kaspersky'   => ['kaspersky'],
        'eset'        => ['eset'],
        'adobe'       => ['adobe', 'acrobat', 'photoshop'],
        'autodesk'    => ['autocad', 'autodesk'],
        'corel'       => ['corel'],
        'parallels'   => ['parallels'],
        'microsoft'   => ['microsoft', 'office', 'windows', 'visio', 'project', 'excel', 'word', 'powerpoint', 'outlook'],
    ] as $brandKey => $needles) {
        foreach ($needles as $n) {
            if (str_contains($name, $n)) return $brandKey;
        }
    }
    return 'maventech';
}

/**
 * Build the HTML for a colourful product-icon "scatter" watermark.
 *
 * For Microsoft orders we tile actual app icons (Word/Excel/PowerPoint/
 * Outlook/OneNote/Teams/Windows) in their real brand colours at ~14%
 * opacity, rotated lightly and positioned at deterministic but
 * scattered-looking coordinates across the page.  Feels like a Microsoft
 * marketing piece rather than a sterile invoice.
 *
 * For non-Microsoft brands we fall back to repeating the single brand
 * silhouette (already pre-rendered in /assets/images/brand-watermarks/)
 * in a softer scatter — still adds the "brand feel" but without 7 different
 * logos that don't exist for non-Microsoft brands.
 */
function _pdf_brand_scatter_html(string $brandKey): string
{
    $brandKey = strtolower($brandKey);
    // Deterministic scatter positions (top, left in %).  Picked by hand to
    // look balanced across the page — slight rotation per piece adds life.
    // Format: [top_pct, left_pct, size_px, rotate_deg, icon_filename].
    if ($brandKey === 'microsoft') {
        $dir = __DIR__ . '/../assets/images/brand-watermarks/microsoft-suite';
        $scatter = [
            // Top band
            [ 7,  6, 56, -10, 'word.png'],
            [12, 78, 68,   6, 'excel.png'],
            // Upper-middle
            [22, 32, 60,  12, 'outlook.png'],
            [27, 64, 54,  -8, 'powerpoint.png'],
            // Middle band
            [38, 12, 64,  -4, 'teams.png'],
            [42, 50, 70,  18, 'windows.png'],
            [48, 84, 56,  -6, 'onenote.png'],
            // Lower-middle
            [60, 22, 58,   8, 'access.png'],
            [64, 70, 62, -14, 'word.png'],
            // Bottom band
            [78, 10, 54,  10, 'excel.png'],
            [82, 46, 60,  -3, 'powerpoint.png'],
            [86, 78, 56,  16, 'outlook.png'],
        ];
        $items = [];
        foreach ($scatter as $s) {
            [$top, $left, $size, $rot, $icon] = $s;
            $path = $dir . '/' . $icon;
            if (!is_file($path)) continue;
            $items[] = sprintf(
                '<img class="scatter-icon" style="top:%d%%;left:%d%%;width:%dpx;height:%dpx;transform:rotate(%ddeg);" src="%s" alt="">',
                $top, $left, $size, $size, $rot, $path
            );
        }
        return '<div class="scatter-wrap">' . implode('', $items) . '</div>';
    }

    // Non-Microsoft brands — softer 5-icon scatter using the single brand
    // silhouette.  Still adds branded feel; no need to manufacture fake
    // sub-brand icons.
    $path = _pdf_brand_watermark_path($brandKey);
    if (!is_file($path)) return '';
    $scatter = [
        [10, 12, 72, -10],
        [22, 70, 84,  12],
        [44, 32, 96,  -5],
        [62, 76, 80,  16],
        [78, 18, 76,  -8],
    ];
    $items = [];
    foreach ($scatter as $s) {
        [$top, $left, $size, $rot] = $s;
        $items[] = sprintf(
            '<img class="scatter-icon" style="top:%d%%;left:%d%%;width:%dpx;height:%dpx;transform:rotate(%ddeg);" src="%s" alt="">',
            $top, $left, $size, $size, $rot, $path
        );
    }
    return '<div class="scatter-wrap">' . implode('', $items) . '</div>';
}

/**
 * Return the absolute filesystem path to the brand-watermark PNG for the
 * given brand key.  All watermarks are 600×600 dark-grey silhouettes
 * pre-rendered into /assets/images/brand-watermarks/ so Dompdf can embed
 * them locally (we keep `isRemoteEnabled` false).  Unknown brand keys
 * fall back to the Maventech "M" mark.
 */
function _pdf_brand_watermark_path(string $brandKey): string
{
    $dir = __DIR__ . '/../assets/images/brand-watermarks';
    $key = strtolower(trim($brandKey));
    $path = $dir . '/' . $key . '.png';
    if (is_file($path)) return $path;
    // Fallback to our own brand mark for any unknown / missing brand.
    return $dir . '/maventech.png';
}

/**
 * Generate a QR-code PNG (base64-encoded data URI) that links to the
 * customer's Order History entry pre-filled with their email + order
 * number.  Returned as a `data:image/png;base64,...` URI so Dompdf can
 * embed it directly without a remote fetch (and without writing yet
 * another tmp file on disk).  Returns '' if encoding fails — caller
 * gracefully omits the QR in that case.
 */
function _pdf_order_history_qr(array $order): string
{
    if (empty($order['email']) || empty($order['order_number'])) return '';
    if (!class_exists(\chillerlan\QRCode\QRCode::class)) return '';
    $url = rtrim(function_exists('site_url') ? site_url() : '', '/')
         . '/order-history.php?email=' . rawurlencode((string)$order['email'])
         . '&order=' . rawurlencode((string)$order['order_number']);
    try {
        $opts = new \chillerlan\QRCode\QROptions([
            // Auto-size QR to fit any URL length — version 1..10 covers up
            // to a few hundred chars at ECC level M, plenty for our URL.
            'versionMin'          => 5,
            'versionMax'          => 10,
            'eccLevel'            => \chillerlan\QRCode\Common\EccLevel::M,
            'scale'               => 4,
            'imageBase64'         => true,
            'imageTransparent'    => false,
            // PNG via GD — yields a `data:image/png;base64,...` URI that
            // Dompdf embeds without needing remote-fetch.
            'outputInterface'     => \chillerlan\QRCode\Output\QRGdImagePNG::class,
        ]);
        return (new \chillerlan\QRCode\QRCode($opts))->render($url);
    } catch (Throwable $e) {
        error_log('[pdf-qr] ' . $e->getMessage());
        return '';
    }
}

/**
 * Shared HTML head + brand header used by both Receipt and Invoice.
 * Variant: 'receipt' or 'invoice' — only the title + sub-line change.
 */
function _pdf_shell(array $ctx, string $bodyHtml): string
{
    $co       = $ctx['co'];
    $brand    = htmlspecialchars($co['name']    ?? 'Maventech Software', ENT_QUOTES, 'UTF-8');
    $brandAddr= nl2br(htmlspecialchars($co['address'] ?? '',             ENT_QUOTES, 'UTF-8'));
    $brandEm  = htmlspecialchars($co['email']   ?? '',                   ENT_QUOTES, 'UTF-8');
    $logoUrl  = $ctx['logo']  ?? '';   // local file path is fine for Dompdf
    $docTitle = htmlspecialchars($ctx['title'] ?? 'Document',            ENT_QUOTES, 'UTF-8');
    $invNo    = htmlspecialchars($ctx['invoice_number'] ?? '',           ENT_QUOTES, 'UTF-8');
    // Brand-specific colourful scattered watermark (Microsoft suite icons
    // for Microsoft orders; soft repeated brand mark for other brands).
    $brandKey  = $ctx['brand_key'] ?? 'maventech';
    $scatterHtml = _pdf_brand_scatter_html($brandKey);
    // Personalised greeting at the top of the document — pulled from the
    // customer's first name.  Adds a human touch without changing any
    // structural layout.
    $firstName = trim((string)($ctx['first_name'] ?? ''));
    $greetHtml = '';
    if ($firstName !== '') {
        $greetHtml = '<div class="thank-you">Thank you, '
                   . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8')
                   . '!</div>';
    }
    // Diagonal "PAID" / "INVOICE" / "DUE" stamp under the brand logo —
    // accounting-software vibe.  Caller passes `stamp_text` + `stamp_color`
    // (defaults to dark grey; green for PAID, amber for DUE).
    $stampText  = trim((string)($ctx['stamp_text']  ?? ''));
    $stampColor = (string)($ctx['stamp_color'] ?? '#1f2937');
    $stampHtml  = '';
    if ($stampText !== '') {
        $stampHtml = '<div class="stamp" style="color:' . htmlspecialchars($stampColor, ENT_QUOTES, 'UTF-8') . ';border-color:' . htmlspecialchars($stampColor, ENT_QUOTES, 'UTF-8') . ';">'
                   . htmlspecialchars($stampText, ENT_QUOTES, 'UTF-8')
                   . '</div>';
    }
    // Bottom-right QR — links to the customer's Order History entry with
    // email + order number pre-filled.  Anyone holding the printed copy
    // (accountant, auditor, finance team) can scan and get a fresh PDF
    // on the spot — no need to email support.
    $qrDataUri = (string)($ctx['qr_data_uri'] ?? '');
    $qrHtml    = '';
    if ($qrDataUri !== '') {
        $qrHtml = '<div class="qr-stamp">'
                . '  <img src="' . $qrDataUri . '" alt="QR code">'
                . '  <div class="qr-label">Scan to re-download<br>Receipt &amp; Invoice</div>'
                . '</div>';
    }
    // Active vibe-schedule promo banner — admin-defined label + optional
    // logo upload.  Renders as a thin red bar at the top of every PDF
    // generated while the schedule is live (e.g. "BLACK FRIDAY SALE").
    $promoBarHtml = '';
    if (function_exists('active_vibe_promo')) {
        $promo = active_vibe_promo();
        if ($promo && trim((string)$promo['label']) !== '') {
            $promoLabel = htmlspecialchars((string)$promo['label'], ENT_QUOTES, 'UTF-8');
            $promoLogo  = '';
            $promoLogoFile = (string)($promo['logo_file'] ?? '');
            if ($promoLogoFile !== '' && is_file($promoLogoFile) && !preg_match('/\.svg$/i', $promoLogoFile)) {
                $promoLogo = '<img src="' . htmlspecialchars($promoLogoFile, ENT_QUOTES, 'UTF-8') . '" alt="" style="height:22px;width:auto;vertical-align:middle;background:#fff;border-radius:4px;padding:2px;margin-right:8px;">';
            }
            $promoCoupon = '';
            $code = strtoupper(trim((string)($promo['coupon_code'] ?? '')));
            $pct  = (int)($promo['coupon_percent'] ?? 0);
            if ($code !== '' && $pct > 0) {
                $promoCoupon = '<span style="display:inline-block;margin-left:12px;font-size:10pt;font-weight:600;letter-spacing:.4px;text-transform:none;color:#fcd34d;">Use <span style="background:#fbbf24;color:#0f172a;padding:1px 6px;border-radius:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span> · ' . $pct . '% off</span>';
            }
            $promoBarHtml = '<div class="promo-bar" style="background:#0f172a;color:#fff;padding:8px 14px;border-radius:8px;text-align:center;font-weight:800;letter-spacing:.6px;font-size:11pt;margin:0 0 14px;text-transform:uppercase;border-left:3px solid #fbbf24;">'
                          . $promoLogo . $promoLabel . $promoCoupon . '</div>';
        }
    }
    $secondRow= '';
    if (!empty($ctx['receipt_number'])) {
        $secondRow .= '<tr><td>Receipt number</td><td class="r">' . htmlspecialchars($ctx['receipt_number'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!empty($ctx['date_paid'])) {
        $secondRow .= '<tr><td>Date paid</td><td class="r">' . htmlspecialchars($ctx['date_paid'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!empty($ctx['date_issued'])) {
        $secondRow .= '<tr><td>Date of issue</td><td class="r">' . htmlspecialchars($ctx['date_issued'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!empty($ctx['date_due'])) {
        $secondRow .= '<tr><td>Date due</td><td class="r">'  . htmlspecialchars($ctx['date_due'], ENT_QUOTES, 'UTF-8')  . '</td></tr>';
    }
    $billLines = '';
    foreach ((array)($ctx['bill_to'] ?? []) as $line) {
        $billLines .= '<div>' . htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $logoTag = $logoUrl && file_exists($logoUrl)
        ? '<img src="' . $logoUrl . '" alt="' . $brand . '" style="height:44px;width:auto;vertical-align:top;">'
        : '<div style="font-size:18px;font-weight:800;color:#06b6d4;letter-spacing:.5px;">' . $brand . '</div>';

    return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8">
<style>
  @page { margin: 56px 48px; }
  body  { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #1f2937; }
  h1    { font-size: 22pt; font-weight: 700; margin: 0 0 14px; color: #0f172a; letter-spacing: .3px; }
  .head-grid { width: 100%; border-collapse: collapse; margin-bottom: 26px; }
  .head-grid td { vertical-align: top; }
  .head-meta { width: 50%; }
  .head-meta table { width: 100%; border-collapse: collapse; font-size: 9.5pt; color: #475569; }
  .head-meta table td { padding: 2px 0; }
  .head-meta table td.r { text-align: right; color: #0f172a; font-weight: 600; }
  .head-brand { width: 50%; text-align: right; }
  .head-brand .brand-line { margin-top: 6px; font-size: 9pt; color: #64748b; line-height: 1.45; }

  .from-bill { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
  .from-bill td { vertical-align: top; width: 50%; padding-right: 12px; font-size: 9.5pt; color: #1f2937; }
  .from-bill .label { font-size: 8pt; text-transform: uppercase; letter-spacing: 1.2px; color: #94a3b8; font-weight: 700; margin-bottom: 4px; }
  .from-bill .bold  { color: #0f172a; font-weight: 700; }

  .amount-banner { background: #f8fafc; border-left: 4px solid #06b6d4; padding: 14px 16px; margin-bottom: 22px; }
  .amount-banner .amt { font-size: 18pt; font-weight: 700; color: #0f172a; }
  .amount-banner .sub { font-size: 9pt; color: #64748b; margin-top: 2px; }

  table.items, table.payhist { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
  table.items th, table.items td, table.payhist th, table.payhist td { padding: 9px 4px; font-size: 9.5pt; }
  table.items thead, table.payhist thead { border-bottom: 2px solid #0f172a; }
  table.items th, table.payhist th { text-align: left; font-weight: 700; color: #0f172a; font-size: 9pt; text-transform: uppercase; letter-spacing: .5px; }
  table.items td, table.payhist td { border-bottom: 1px solid #e2e8f0; }
  table.items td.num, table.items th.num, table.payhist td.num, table.payhist th.num { text-align: right; }
  .totals { width: 50%; margin-left: 50%; border-collapse: collapse; font-size: 10pt; }
  .totals td { padding: 5px 4px; }
  .totals td.label { color: #475569; }
  .totals td.value { text-align: right; color: #0f172a; font-weight: 600; }
  .totals tr.total-row td { border-top: 2px solid #0f172a; padding-top: 9px; font-size: 11.5pt; font-weight: 700; color: #0f172a; }
  .totals tr.amount-paid td { padding-top: 9px; color: #047857; font-weight: 700; }
  .totals tr.amount-due td { padding-top: 9px; color: #b91c1c; font-weight: 700; }

  .statement {
    background: #fff7ed; border: 1px solid #fdba74; border-left: 4px solid #f59e0b;
    padding: 10px 14px; border-radius: 10px; margin: 22px 0; font-size: 9.5pt; color: #7c2d12;
  }
  .statement .lbl { font-weight: 700; color: #7c2d12; }
  .statement .hl { background: #fde68a; color: #9a3412; font-weight: 700; padding: 1px 7px; border-radius: 5px; }

  .footer {
    margin-top: 14px; padding-top: 10px; border-top: 1px solid #e2e8f0;
    font-size: 8pt; color: #94a3b8; line-height: 1.6;
  }

  /* Colourful scattered brand watermark — Microsoft suite icons (or the
     single brand silhouette for non-Microsoft) tiled at low opacity behind
     the content.  Position:absolute on each <img> with deterministic top/
     left % values gives a "scattered marketing piece" feel without hurting
     readability.  Light opacity (≈14%) keeps text fully legible. */
  .scatter-wrap {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    width: 100%; height: 100%;
  }
  .scatter-icon {
    position: absolute;
    opacity: 0.14;
  }

  /* Personalised greeting above the doc title — friendly, premium touch. */
  .thank-you {
    font-size: 11pt;
    font-weight: 600;
    color: #0f766e;        /* teal — picks up the brand accent */
    margin: 0 0 4px;
    letter-spacing: .2px;
  }

  /* Diagonal "PAID" / "INVOICE" / "DUE" stamp sitting underneath the
     brand watermark — accounting-software vibe.  Subtle (12% opacity)
     and rotated -22° so it reads naturally without screaming.  Border +
     padding form the classic rubber-stamp look. */
  .stamp {
    position: absolute;
    top: 480px; left: 50%;
    margin-left: -130px;
    width: 260px;
    text-align: center;
    font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
    font-weight: 900;
    font-size: 44pt;
    letter-spacing: 6px;
    padding: 10px 16px;
    border: 6px solid #1f2937;
    border-radius: 12px;
    opacity: 0.12;
    transform: rotate(-22deg);
  }
  /* QR — sits in its own block at the very bottom of the document so a
     printed copy can be scanned back to the customer's Order History
     (email + order# pre-filled).  Right-aligned via a single-cell
     table so Dompdf (which is finicky with floats) renders it reliably. */
  /* QR — sits in the empty right cell next to the "Bill to" block so a
     printed copy can be scanned back to the customer's Order History
     (email + order# pre-filled).  This keeps it inside the existing
     2-column header layout and guarantees it fits on page 1. */
  .from-bill td.qr-cell { text-align: right; vertical-align: top; }
  .qr-stamp {
    display: inline-block;
    width: 90px;
    text-align: center;
    font-family: Helvetica, Arial, sans-serif;
  }
  .qr-stamp img {
    width: 78px; height: 78px;
    display: block; margin: 0 auto;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 2px;
    background: #ffffff;
  }
  .qr-stamp .qr-label {
    margin-top: 4px;
    font-size: 6.5pt;
    color: #64748b;
    line-height: 1.25;
    letter-spacing: .2px;
  }
</style>
</head>
<body>
  {$scatterHtml}
  {$promoBarHtml}
  {$stampHtml}
  {$greetHtml}
  <h1>{$docTitle}</h1>
  <table class="head-grid"><tr>
    <td class="head-meta">
      <table>
        <tr><td>Invoice number</td><td class="r">{$invNo}</td></tr>
        {$secondRow}
      </table>
    </td>
    <td class="head-brand">
      {$logoTag}
      <div class="brand-line">
        <strong style="color:#0f172a;">{$brand}</strong><br>
        {$brandAddr}<br>
        {$brandEm}
      </div>
    </td>
  </tr></table>

  <table class="from-bill"><tr>
    <td><div class="label">Bill to</div>{$billLines}</td>
    <td class="qr-cell">{$qrHtml}</td>
  </tr></table>

  {$bodyHtml}

  <div class="footer">
    Questions? Reply to this email or visit our support page. Thanks for choosing {$brand}.
  </div>
</body></html>
HTML;
}

/**
 * Generate a Receipt PDF (paid orders).  Returns the binary PDF string.
 * Throws on rendering failure.
 */
function generate_receipt_pdf(array $order, array $items, ?array $payment = null, string $extraBodyHtml = ''): string
{
    $co  = function_exists('company_info') ? company_info() : ['name' => 'Maventech Software'];
    $co['phone'] = function_exists('company_phone_for_country') ? company_phone_for_country($order['country'] ?? null) : ($co['phone'] ?? '');
    $cur = (string)($order['currency'] ?? 'USD');
    $invoiceNo = (string)($order['order_number'] ?? '');
    $receiptNo = strtoupper(substr(bin2hex(sha1((string)$order['id'] . '-' . $invoiceNo, true)), 0, 9));
    // Insert a hyphen so it looks like "2797-4805"
    $receiptNo = substr($receiptNo, 0, 4) . '-' . substr($receiptNo, 4, 4);

    $datePaid = $order['paid_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s');
    $datePaid = date('F j, Y', strtotime($datePaid));

    // Bill-to block — sanitised, multi-line.
    $billTo = array_filter([
        trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? '')),
        (string)$order['email'],
        trim(((string)($order['address']  ?? '')) . (empty($order['address2']) ? '' : ', ' . $order['address2'])),
        trim(((string)($order['city']     ?? '')) . ', ' . ((string)($order['state'] ?? '')) . ' ' . ((string)($order['zip'] ?? ''))),
        (string)($order['country'] ?? ''),
    ], fn($l) => trim((string)$l) !== '');

    $stmtName = !empty($order['card_statement_name'])
        ? (string)$order['card_statement_name']
        : (function_exists('statement_name_for')
            ? (string)statement_name_for((string)($order['payment_method'] ?? 'card'))
            : (string)($co['name'] ?? 'Maventech Software'));

    // Items table rows.
    $itemsHtml = '<table class="items"><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead><tbody>';
    $subtotal = 0.0;
    foreach ($items as $it) {
        $qty   = (int)($it['quantity'] ?? $it['qty'] ?? 1);
        $unit  = (float)($it['unit_price'] ?? $it['price'] ?? 0);
        $amt   = $qty * $unit;
        $subtotal += $amt;
        $itemsHtml .= '<tr><td>' . htmlspecialchars((string)($it['name'] ?? $it['product_name'] ?? '—'), ENT_QUOTES, 'UTF-8')
                   . '</td><td class="num">' . $qty
                   . '</td><td class="num">' . _pdf_money($unit, $cur)
                   . '</td><td class="num">' . _pdf_money($amt, $cur) . '</td></tr>';
    }
    $itemsHtml .= '</tbody></table>';

    $total = (float)($order['total'] ?? $subtotal);

    $payRow = '';
    $pmRawPdf = strtolower(trim((string)($order['payment_method'] ?? 'card')));
    $gatewayPdf = $pmRawPdf === 'paypal'
        ? (setting_get('gw_paypal_provider', 'PayPal') ?: 'PayPal')
        : (setting_get('gw_card_provider', 'Stripe') ?: 'Stripe');
    $gwEsc = htmlspecialchars($gatewayPdf, ENT_QUOTES, 'UTF-8');
    if ($payment) {
        $payMethod = htmlspecialchars((string)($payment['method'] ?? 'Card'), ENT_QUOTES, 'UTF-8') . ' · via ' . $gwEsc;
        $payDate   = htmlspecialchars((string)($payment['date']   ?? $datePaid), ENT_QUOTES, 'UTF-8');
        $payRow = "<tr><td>{$payMethod}</td><td>{$payDate}</td><td class=\"num\">" . _pdf_money($total, $cur) . "</td><td class=\"num\">{$receiptNo}</td></tr>";
    } elseif (!empty($order['card_brand']) || !empty($order['payment_method'])) {
        $brand = $order['card_brand'] ?: ucfirst((string)$order['payment_method']);
        $tail  = !empty($order['card_last4']) ? ' - ' . $order['card_last4'] : '';
        $payRow = "<tr><td>{$brand}{$tail} · via {$gwEsc}</td><td>{$datePaid}</td><td class=\"num\">" . _pdf_money($total, $cur) . "</td><td class=\"num\">{$receiptNo}</td></tr>";
    }

    $bodyHtml = '<div class="amount-banner">
                    <div class="amt">' . _pdf_money($total, $cur) . ' paid on ' . htmlspecialchars($datePaid, ENT_QUOTES, 'UTF-8') . '</div>
                    <div class="sub">Thanks for your purchase — your license keys are delivered in the accompanying email.</div>
                 </div>'
              . $itemsHtml
              . '<table class="totals">
                    <tr><td class="label">Subtotal</td><td class="value">' . _pdf_money($subtotal, $cur) . '</td></tr>
                    <tr class="total-row"><td class="label">Total</td><td class="value">' . _pdf_money($total, $cur) . '</td></tr>
                    <tr class="amount-paid"><td class="label">Amount paid</td><td class="value">' . _pdf_money($total, $cur) . '</td></tr>
                 </table>'
              . '<div class="statement"><span class="lbl">Billing note:</span> this charge will appear as <strong class="hl">' . htmlspecialchars($stmtName, ENT_QUOTES, 'UTF-8') . '</strong> on your card statement.</div>'
              . ($payRow ? '<div style="font-weight:700;color:#0f172a;margin:18px 0 6px;font-size:11pt;">Payment history</div>
                            <table class="payhist"><thead><tr><th>Payment method</th><th>Date</th><th class="num">Amount paid</th><th class="num">Receipt number</th></tr></thead><tbody>' . $payRow . '</tbody></table>' : '')
              . $extraBodyHtml;

    $html = _pdf_shell([
        'co'              => $co,
        'logo'            => _pdf_company_logo_path(),
        'title'           => 'Receipt',
        'invoice_number'  => $invoiceNo,
        'receipt_number'  => $receiptNo,
        'date_paid'       => $datePaid,
        'bill_to'         => $billTo,
        'brand_key'       => _pdf_brand_for_items($items),
        'first_name'      => (string)($order['first_name'] ?? ''),
        'stamp_text'      => 'PAID',
        'stamp_color'     => '#047857', // emerald — universal "all good"
        'qr_data_uri'     => _pdf_order_history_qr($order),
    ], $bodyHtml);

    $dompdf = _pdf_dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/**
 * Generate an Invoice PDF (issued at order time — works for both paid and
 * pending orders).  Returns the binary PDF string.
 */
function generate_invoice_pdf(array $order, array $items): string
{
    $co  = function_exists('company_info') ? company_info() : ['name' => 'Maventech Software'];
    $co['phone'] = function_exists('company_phone_for_country') ? company_phone_for_country($order['country'] ?? null) : ($co['phone'] ?? '');
    $cur = (string)($order['currency'] ?? 'USD');
    $invoiceNo = (string)($order['order_number'] ?? '');

    $dateIssued = date('F j, Y', strtotime((string)($order['created_at'] ?? 'now')));
    $dateDue    = $dateIssued;  // For our digital goods, due-on-issue.

    $billTo = array_filter([
        trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? '')),
        (string)$order['email'],
        trim(((string)($order['address']  ?? '')) . (empty($order['address2']) ? '' : ', ' . $order['address2'])),
        trim(((string)($order['city']     ?? '')) . ', ' . ((string)($order['state'] ?? '')) . ' ' . ((string)($order['zip'] ?? ''))),
        (string)($order['country'] ?? ''),
    ], fn($l) => trim((string)$l) !== '');

    $stmtName = !empty($order['card_statement_name'])
        ? (string)$order['card_statement_name']
        : (function_exists('statement_name_for')
            ? (string)statement_name_for((string)($order['payment_method'] ?? 'card'))
            : (string)($co['name'] ?? 'Maventech Software'));

    $itemsHtml = '<table class="items"><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead><tbody>';
    $subtotal = 0.0;
    foreach ($items as $it) {
        $qty   = (int)($it['quantity'] ?? $it['qty'] ?? 1);
        $unit  = (float)($it['unit_price'] ?? $it['price'] ?? 0);
        $amt   = $qty * $unit;
        $subtotal += $amt;
        $itemsHtml .= '<tr><td>' . htmlspecialchars((string)($it['name'] ?? $it['product_name'] ?? '—'), ENT_QUOTES, 'UTF-8')
                   . '</td><td class="num">' . $qty
                   . '</td><td class="num">' . _pdf_money($unit, $cur)
                   . '</td><td class="num">' . _pdf_money($amt, $cur) . '</td></tr>';
    }
    $itemsHtml .= '</tbody></table>';

    $total = (float)($order['total'] ?? $subtotal);
    $isPaid = (string)($order['status'] ?? '') === 'paid';

    $bodyHtml = '<div class="amount-banner">
                    <div class="amt">' . _pdf_money($total, $cur) . ' ' . htmlspecialchars($cur, ENT_QUOTES, 'UTF-8') . ($isPaid ? ' &mdash; paid' : ' due ' . htmlspecialchars($dateDue, ENT_QUOTES, 'UTF-8')) . '</div>
                    <div class="sub">' . ($isPaid ? 'Already paid — keep this invoice for your records.' : 'Please complete payment to receive your license keys.') . '</div>
                 </div>'
              . $itemsHtml
              . '<table class="totals">
                    <tr><td class="label">Subtotal</td><td class="value">' . _pdf_money($subtotal, $cur) . '</td></tr>
                    <tr class="total-row"><td class="label">Total</td><td class="value">' . _pdf_money($total, $cur) . '</td></tr>
                    <tr class="' . ($isPaid ? 'amount-paid' : 'amount-due') . '">
                        <td class="label">' . ($isPaid ? 'Amount paid' : 'Amount due') . '</td>
                        <td class="value">' . _pdf_money($total, $cur) . ' ' . htmlspecialchars($cur, ENT_QUOTES, 'UTF-8') . '</td>
                    </tr>
                 </table>'
              . '<div class="statement"><span class="lbl">Billing note:</span> this charge ' . ($isPaid ? 'appears' : 'will appear') . ' as <strong class="hl">' . htmlspecialchars($stmtName, ENT_QUOTES, 'UTF-8') . '</strong> on your card statement.</div>';

    $html = _pdf_shell([
        'co'              => $co,
        'logo'            => _pdf_company_logo_path(),
        'title'           => 'Invoice',
        'invoice_number'  => $invoiceNo,
        'date_issued'     => $dateIssued,
        'date_due'        => $dateDue,
        'bill_to'         => $billTo,
        'brand_key'       => _pdf_brand_for_items($items),
        'first_name'      => (string)($order['first_name'] ?? ''),
        // Stamp reads "PAID" if the invoice is already settled, otherwise "DUE".
        'stamp_text'      => $isPaid ? 'PAID' : 'DUE',
        'stamp_color'     => $isPaid ? '#047857' : '#b91c1c', // emerald vs red
        'qr_data_uri'     => _pdf_order_history_qr($order),
    ], $bodyHtml);

    $dompdf = _pdf_dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/**
 * Save both PDFs to /uploads/order-pdfs/{order_id}/ and return their
 * absolute paths so send_email() can attach them.  Idempotent — overwrites
 * existing files if called repeatedly for the same order.
 */
function generate_order_pdfs(array $order, array $items): array
{
    $dir = __DIR__ . '/../uploads/order-pdfs/' . (int)($order['id'] ?? 0);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $rcptPath = $dir . '/Receipt-'   . (string)($order['order_number'] ?? 'X') . '.pdf';
    $invPath  = $dir . '/Invoice-'   . (string)($order['order_number'] ?? 'X') . '.pdf';
    try {
        @file_put_contents($rcptPath, generate_receipt_pdf($order, $items));
    } catch (Throwable $e) { @error_log('[pdf receipt] ' . $e->getMessage()); $rcptPath = ''; }
    try {
        @file_put_contents($invPath,  generate_invoice_pdf($order, $items));
    } catch (Throwable $e) { @error_log('[pdf invoice] ' . $e->getMessage()); $invPath  = ''; }
    return array_values(array_filter([$rcptPath, $invPath]));
}
