<?php
/* ===========================================================================
 *  STOREFRONT PWA MANIFEST  —  /manifest.webmanifest
 *  ---------------------------------------------------------------------------
 *  Lets users "Install Maventech Software" to their iOS / Android home screen.
 *  Installed instances get a real app icon (no browser chrome on iOS 16.4+
 *  and all modern Android browsers), a dedicated splash screen, and — most
 *  importantly for us — appear in the OS's app-switcher / dock, giving us
 *  far higher repeat-visit rates than a plain bookmark.
 *
 *  Generated dynamically so the brand name, theme colour and start URL
 *  always reflect the live Company Info admin settings.  Served at
 *  /manifest.webmanifest via router.php; referenced from <head> by
 *  includes/header.php's <link rel="manifest"> tag.
 *  =========================================================================== */

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$brand = function_exists('company_info') ? (company_info()['name'] ?? SITE_BRAND) : SITE_BRAND;
$short = preg_replace('/\s+software\s*$/i', '', $brand);
if (mb_strlen($short) > 12) $short = mb_substr($short, 0, 12);

$manifest = [
    'name'             => $brand,
    'short_name'       => $short,
    'description'      => 'Buy genuine Microsoft Office, Windows 11 and antivirus license keys — instant digital delivery, lifetime activation.',
    'start_url'        => '/?source=pwa',
    'scope'            => '/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#0b1220',
    'theme_color'      => '#0066CC',
    'id'               => '/?source=pwa',
    'categories'       => ['shopping', 'business', 'productivity'],
    'lang'             => 'en-US',
    'icons'            => [
        ['src' => '/favicon.svg',                              'sizes' => 'any',     'type' => 'image/svg+xml', 'purpose' => 'any'],
        ['src' => '/assets/images/favicon/icon-192.png',       'sizes' => '192x192', 'type' => 'image/png',     'purpose' => 'any maskable'],
        ['src' => '/assets/images/favicon/icon-512.png',       'sizes' => '512x512', 'type' => 'image/png',     'purpose' => 'any maskable'],
    ],
    'shortcuts'        => [
        ['name' => 'Track an order',  'short_name' => 'Track', 'url' => '/order-history.php?source=pwa-shortcut',
         'icons' => [['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']]],
        ['name' => 'Shop catalog',    'short_name' => 'Shop',  'url' => '/shop.php?source=pwa-shortcut',
         'icons' => [['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']]],
        ['name' => 'Get support',     'short_name' => 'Help',  'url' => '/support.php?source=pwa-shortcut',
         'icons' => [['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']]],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
