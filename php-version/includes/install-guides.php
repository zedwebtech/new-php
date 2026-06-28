<?php
/* ============================================================================
 *  Maventech native installation-guide content.
 *
 *  We host our OWN step-by-step install/activation guides (instead of linking
 *  out to a third-party manuals site). The catalog is grouped into four
 *  install "flows" that cover every Microsoft product we sell — each guide is
 *  personalised at render time with the specific product's name + its own
 *  one-click installer link. Screenshots are served locally from
 *  /uploads/guides/<flow>/.
 *
 *  All copy below is original. Antivirus products are not mapped (no guide).
 *  ========================================================================== */

if (!function_exists('mv_guide_template_for_slug')) {

/** Map a product slug → install-flow template key (or null when none). */
function mv_guide_template_for_slug(string $slug): ?string
{
    static $map = [
        // ── Office / Project / Visio — Windows, product-key activation ──────
        'microsoft-office-2024-professional-plus-windows'                    => 'office_key',
        'microsoft-office-2024-professional-plus-lifetime-license-windows-pc'=> 'office_key',
        'microsoft-office-home-2024-pc'                                      => 'office_key',
        'microsoft-office-home-business-2024-pc'                             => 'office_key',
        'microsoft-office-2021-professional-plus-windows'                    => 'office_key',
        'microsoft-word-2021-windows'                                        => 'office_key',
        'microsoft-excel-2021-windows'                                       => 'office_key',
        'microsoft-office-2019-home-student-windows'                         => 'office_key',
        'microsoft-office-2019-home-business-pc'                             => 'office_key',
        'microsoft-office-2019-professional-plus-windows'                    => 'office_key',
        'microsoft-project-2024-professional-pc'                             => 'office_key',
        'microsoft-project-professional-2021-pc'                            => 'office_key',
        'ms-project-professional-2019-pc'                                    => 'office_key',
        'microsoft-visio-2024-professional-windows-pc'                       => 'office_key',
        'microsoft-visio-2021-professional-windows-pc'                       => 'office_key',
        'ms-visio-professional-2019-pc'                                      => 'office_key',
        // ── Office Retail (ISO image + sign-in) ─────────────────────────────
        'microsoft-office-2021-home-business-windows'                        => 'office_retail',
        'microsoft-office-2021-home-student-windows'                         => 'office_retail',
        // ── Office for Mac ──────────────────────────────────────────────────
        'microsoft-office-home-business-2024-mac'                            => 'office_mac',
        'microsoft-office-home-2024-mac'                                     => 'office_mac',
        'microsoft-office-2021-home-student-mac'                             => 'office_mac',
        'microsoft-office-2021-home-business-mac'                            => 'office_mac',
        'microsoft-word-2021-mac-lifetime-license-no-subscription'           => 'office_mac',
        'microsoft-excel-2021-mac-lifetime-license-no-subscription'          => 'office_mac',
        'microsoft-office-home-and-business-2019-mac'                        => 'office_mac',
        'microsoft-office-home-and-student-2019-mac'                         => 'office_mac',
        // ── Windows desktop ─────────────────────────────────────────────────
        'windows-11-home' => 'windows',
        'windows-11-pro'  => 'windows',
        'windows-10-home' => 'windows',
        'windows-10-pro'  => 'windows',
    ];
    return $map[$slug] ?? null;
}

/** True when the product has a native on-site install guide. */
function mv_product_has_guide(?array $product): bool
{
    return $product && !empty($product['slug']) && mv_guide_template_for_slug((string)$product['slug']) !== null;
}

/** Relative path to the native guide page for a slug. */
function mv_guide_path(string $slug): string
{
    return '/install-guide.php?slug=' . urlencode($slug);
}

/**
 * Absolute guide URL (for emails / PDFs that can't resolve relative paths).
 * Uses site_url() so it always resolves to the current public host.
 */
function mv_guide_abs_url(string $slug): string
{
    $base = function_exists('site_url') ? rtrim((string)site_url(), '/') : '';
    return $base . mv_guide_path($slug);
}

/**
 * Per-product installer (one-click EN 64-bit) + activation/sign-in URL.
 * This is the single source of truth used both to seed the products table AND
 * as a LIVE fallback (so the Download / Activate buttons render everywhere even
 * before the DB has been seeded). Office-for-Mac has no one-click installer
 * (obtained after sign-in), so installer is null there.
 */
function mv_product_install_meta(string $slug): array
{
    static $map = null;
    if ($map === null) {
        $DL  = 'https://download.winandoffice.com';
        $OFF = 'https://setup.office.com';              // Office / Project / Visio
        $WIN = 'https://account.microsoft.com/account'; // Windows
        $map = [
            // Office / Project / Visio — Windows (key)
            'microsoft-office-2024-professional-plus-windows'                     => ["$DL/Volume/office/2024/EN/Office_2024_EN_64Bits.exe", $OFF],
            'microsoft-office-2024-professional-plus-lifetime-license-windows-pc' => ["$DL/Volume/office/2024/EN/Office_2024_EN_64Bits.exe", $OFF],
            'microsoft-office-home-2024-pc'                                       => ["$DL/Volume/office/2024/EN/Office_2024_EN_standard_64Bits.exe", $OFF],
            'microsoft-office-home-business-2024-pc'                              => ["$DL/Volume/office/2024/EN/Office_2024_EN_standard_64Bits.exe", $OFF],
            'microsoft-office-2021-professional-plus-windows'                     => ["$DL/Volume/office/2021/EN/Office_2021_EN_64Bits.exe", $OFF],
            'microsoft-word-2021-windows'                                         => ["$DL/Volume/office/2021/EN/Office_2021_EN_64Bits.exe", $OFF],
            'microsoft-excel-2021-windows'                                        => ["$DL/Volume/office/2021/EN/Office_2021_EN_64Bits.exe", $OFF],
            'microsoft-office-2019-home-student-windows'                          => ["$DL/Volume/office/2019/EN/Office_2019_EN_standard_64Bits.exe", $OFF],
            'microsoft-office-2019-home-business-pc'                              => ["$DL/Volume/office/2019/EN/Office_2019_EN_standard_64Bits.exe", $OFF],
            'microsoft-office-2019-professional-plus-windows'                     => ["$DL/Volume/office/2019/EN/Office_2019_EN_64Bits.exe", $OFF],
            'microsoft-project-2024-professional-pc'                              => ["$DL/Volume/project/2024/EN/project_2024_EN_64Bits.exe", $OFF],
            'microsoft-project-professional-2021-pc'                              => ["$DL/Volume/project/2021/EN/project_2021_EN_64Bits.exe", $OFF],
            'ms-project-professional-2019-pc'                                     => ["$DL/Volume/project/2019/EN/project_2019_EN_64Bits.exe", $OFF],
            'microsoft-visio-2024-professional-windows-pc'                        => ["$DL/Volume/visio/2024/EN/visio_2024_EN_64Bits.exe", $OFF],
            'microsoft-visio-2021-professional-windows-pc'                        => ["$DL/Volume/visio/2021/EN/visio_2021_EN_pro_64Bits.exe", $OFF],
            'ms-visio-professional-2019-pc'                                       => ["$DL/Volume/visio/2019/EN/visio_2019_EN_64Bits.exe", $OFF],
            // Office Retail (ISO)
            'microsoft-office-2021-home-business-windows'                         => ["$DL/Retail/Office/EN/HomeBusiness2021Retail.iso", $OFF],
            'microsoft-office-2021-home-student-windows'                          => ["$DL/Retail/Office/EN/HomeStudent2021Retail.iso", $OFF],
            // Office for Mac (no one-click installer)
            'microsoft-office-home-business-2024-mac'                             => [null, $OFF],
            'microsoft-office-home-2024-mac'                                      => [null, $OFF],
            'microsoft-office-2021-home-student-mac'                              => [null, $OFF],
            'microsoft-office-2021-home-business-mac'                             => [null, $OFF],
            'microsoft-word-2021-mac-lifetime-license-no-subscription'            => [null, $OFF],
            'microsoft-excel-2021-mac-lifetime-license-no-subscription'           => [null, $OFF],
            'microsoft-office-home-and-business-2019-mac'                         => [null, $OFF],
            'microsoft-office-home-and-student-2019-mac'                          => [null, $OFF],
            // Windows desktop (Media Creation Tool)
            'windows-11-home' => ["$DL/Retail/Desktop/MediaCreationTool.exe",     $WIN],
            'windows-11-pro'  => ["$DL/Retail/Desktop/MediaCreationTool.exe",     $WIN],
            'windows-10-home' => ["$DL/Retail/Desktop/MediaCreationTool22H2.exe", $WIN],
            'windows-10-pro'  => ["$DL/Retail/Desktop/MediaCreationTool22H2.exe", $WIN],
        ];
    }
    if (!isset($map[$slug])) return [];
    return ['installer' => $map[$slug][0], 'activation' => $map[$slug][1]];
}

/**
 * Resolve the three install links for a product, preferring values already
 * stored on the product row (admin-editable) and falling back to the central
 * mapping above. Returns ['installer','guide','activation'] (strings, '' when
 * none). Guide always points to our own /install-guide.php page when a guide
 * template exists for the product.
 */
function mv_resolve_install_links(string $slug, array $existing = []): array
{
    $meta      = mv_product_install_meta($slug);
    $installer = trim((string)($existing['installer_url'] ?? ''));
    if ($installer === '' && !empty($meta['installer'])) $installer = (string)$meta['installer'];

    $guide = trim((string)($existing['install_guide_url'] ?? ''));
    if ($guide === '' && mv_guide_template_for_slug($slug) !== null) $guide = mv_guide_path($slug);

    $activation = trim((string)($existing['activation_url'] ?? ''));
    if ($activation === '' && !empty($meta['activation'])) $activation = (string)$meta['activation'];

    return ['installer' => $installer, 'guide' => $guide, 'activation' => $activation];
}

/**
 * The four install-flow templates. Each: label, platform, flow[] (flowchart
 * pills), system[] (requirements), steps[] (numbered, optional screenshot),
 * activation (HTML). {{installer}} / {{activation}} placeholders in the copy
 * are filled with the product's own links by the page.
 */
function mv_install_guides(): array
{
    return [

    /* ───────────────────────── Office / Project / Visio (key) ───────────── */
    'office_key' => [
        'label'    => 'Microsoft Office, Project & Visio — Windows',
        'platform' => 'Windows 10 / 11',
        'flow' => [
            ['icon' => 'bi-download',      'label' => 'Download'],
            ['icon' => 'bi-shield-lock',   'label' => 'Run as admin'],
            ['icon' => 'bi-gear-wide-connected', 'label' => 'Install'],
            ['icon' => 'bi-window',        'label' => 'Open an app'],
            ['icon' => 'bi-key',           'label' => 'Change key'],
            ['icon' => 'bi-patch-check',   'label' => 'Activated'],
        ],
        'system' => [
            '1 GHz or faster, 2-core 32-bit (x86) or 64-bit (x64) processor',
            '4 GB RAM (2 GB minimum on 32-bit)',
            '4 GB of free hard-disk space',
            'Windows 10 or Windows 11',
            'An internet connection to download Office files during setup',
        ],
        'steps' => [
            ['title' => 'Download the official installer',
             'html'  => 'Click <strong>Download installer</strong> below to get the genuine Microsoft setup file. Save it somewhere easy to find &mdash; usually your <em>Downloads</em> folder. Need a different language or the 32-bit build? Just ask our support team.',
             'img'   => null],
            ['title' => 'Run the installer as administrator',
             'html'  => 'Right-click the downloaded file and choose <strong>&ldquo;Run as administrator&rdquo;</strong>. When Windows asks whether to allow the app to make changes, click <strong>Yes</strong>. Keep your PC online &mdash; the installer pulls the latest files straight from Microsoft.',
             'img'   => 'office/step-run.jpg'],
            ['title' => 'Let Office install',
             'html'  => 'Installation runs automatically and takes a few minutes depending on your connection. You don&rsquo;t need to enter anything yet &mdash; just wait for it to finish.',
             'img'   => 'office/step-install.jpg'],
            ['title' => 'Open any Office app',
             'html'  => 'Once setup completes, open the Start menu and launch any installed app, for example <strong>Word</strong> or <strong>Excel</strong>. You can pin it to the taskbar for faster access later.',
             'img'   => 'office/step-open.jpg'],
            ['title' => 'Go to Account → Change Product Key',
             'html'  => 'Inside the app, open <strong>File ▸ Account</strong> (bottom-left). Click <strong>Change Product Key</strong> below the Office icons to open the activation box.',
             'img'   => 'office/step-changekey.jpg'],
            ['title' => 'Enter your 25-character key',
             'html'  => 'Type or paste the product key from your order email and confirm. Your copy of Office is now genuinely activated &mdash; the key is yours for life on this device.',
             'img'   => 'office/step-enterkey.jpg'],
        ],
        'activation' => 'Activation happens directly inside the Office app using the <strong>Change Product Key</strong> box shown above &mdash; no third-party tools are ever needed. If you also want to manage the licence online, sign in with a free Microsoft account at <a href="{{activation}}" target="_blank" rel="noopener">{{activation_host}}</a>. Tip: remove any pre-installed trial version of Office first and restart the PC to avoid conflicts.',
    ],

    /* ───────────────────────── Office Retail (ISO) ──────────────────────── */
    'office_retail' => [
        'label'    => 'Microsoft Office Home editions — Windows (disc image)',
        'platform' => 'Windows 10 / 11',
        'flow' => [
            ['icon' => 'bi-download',    'label' => 'Download ISO'],
            ['icon' => 'bi-disc',        'label' => 'Mount image'],
            ['icon' => 'bi-gear-wide-connected', 'label' => 'Run setup'],
            ['icon' => 'bi-window',      'label' => 'Open an app'],
            ['icon' => 'bi-key',         'label' => 'Enter key'],
            ['icon' => 'bi-patch-check', 'label' => 'Activated'],
        ],
        'system' => [
            '1 GHz or faster, 2-core processor (x86/x64)',
            '4 GB RAM',
            '4 GB of free hard-disk space',
            'Windows 10 or Windows 11',
            'An internet connection during setup',
        ],
        'steps' => [
            ['title' => 'Download the disc image (.iso)',
             'html'  => 'Click <strong>Download installer</strong> below to download the official Office disc image (an <em>.iso</em> file). Save it to your <em>Downloads</em> folder.',
             'img'   => null],
            ['title' => 'Mount the ISO',
             'html'  => 'Right-click the downloaded <em>.iso</em> file and choose <strong>Mount</strong>. Windows opens it as a virtual DVD drive in File Explorer.',
             'img'   => 'retail/step-mount.png'],
            ['title' => 'Run Setup',
             'html'  => 'Open the new drive and double-click <strong>Setup</strong> (use <em>Setup64</em> for the 64-bit version). Approve the Windows prompt and let Office install.',
             'img'   => 'retail/step-setup.png'],
            ['title' => 'Open any Office app',
             'html'  => 'When installation finishes, launch an app such as <strong>Word</strong> from the Start menu.',
             'img'   => 'office/step-open.jpg'],
            ['title' => 'Activate with your key',
             'html'  => 'Open <strong>File ▸ Account ▸ Change Product Key</strong> and enter the 25-character key from your order email. (Home editions can also be linked to a Microsoft account at sign-in.)',
             'img'   => 'office/step-changekey.jpg'],
            ['title' => 'Done — Office is activated',
             'html'  => 'Confirm the key and your Office suite is activated for life on this PC.',
             'img'   => 'office/step-enterkey.jpg'],
        ],
        'activation' => 'After installing, enter your key via <strong>File ▸ Account ▸ Change Product Key</strong>, or sign in / redeem online with a free Microsoft account at <a href="{{activation}}" target="_blank" rel="noopener">{{activation_host}}</a>. Remove any older pre-installed Office trial and restart before activating.',
    ],

    /* ───────────────────────── Office for Mac ───────────────────────────── */
    'office_mac' => [
        'label'    => 'Microsoft Office for Mac',
        'platform' => 'macOS',
        'flow' => [
            ['icon' => 'bi-key',              'label' => 'Redeem key'],
            ['icon' => 'bi-cloud-arrow-down', 'label' => 'Download .pkg'],
            ['icon' => 'bi-box-seam',         'label' => 'Run installer'],
            ['icon' => 'bi-shield-lock',      'label' => 'Mac password'],
            ['icon' => 'bi-check2-circle',    'label' => 'Finish'],
            ['icon' => 'bi-person-check',     'label' => 'Sign in & activate'],
        ],
        'system' => [
            'One of the three most recent versions of macOS (Apple silicon or Intel)',
            '4 GB RAM',
            '10 GB of available disk space on an APFS-formatted drive',
            '1280 x 800 or higher screen resolution',
            'An internet connection and a Microsoft account',
        ],
        'steps' => [
            ['title' => 'Redeem your product key',
             'html'  => 'Office for Mac is downloaded <em>after</em> you redeem the licence. Click <strong>Activate / Sign in</strong> below (this opens <em>setup.office.com</em>), sign in with a free Microsoft account or create one, then enter the <strong>25-character product key</strong> from your order email, choose your country/region and click <strong>Continue</strong>. Your product now appears under <em>Services &amp; subscriptions</em> in your account.',
             'img'   => null],
            ['title' => 'Download Office for Mac from your account',
             'html'  => 'Go to your Microsoft account (<em>account.microsoft.com</em>), find <strong>Office Home &amp; Business</strong> under the products you own, click <strong>Install</strong> and choose <strong>Mac</strong>. The <strong>Microsoft Office installer.pkg</strong> file downloads to your <em>Downloads</em> folder.',
             'img'   => 'mac/mac-portal-install.png'],
            ['title' => 'Open the installer package',
             'html'  => 'Open <strong>Finder ▸ Downloads</strong> and double-click <strong>Microsoft Office installer.pkg</strong> to launch the wizard. <em>Tip:</em> if macOS says it&rsquo;s from an unidentified developer, wait a moment, then Control-click the file and choose <strong>Open</strong>.',
             'img'   => 'mac/mac-dock-pkg.png'],
            ['title' => 'Run the installer',
             'html'  => 'On the first screen click <strong>Continue</strong>, review the software licence and click <strong>Continue</strong>, then <strong>Agree</strong>. Confirm the disk-space requirement / install location and click <strong>Install</strong>. (To install only certain apps, click <strong>Customise</strong> first and untick the ones you don&rsquo;t need.)',
             'img'   => 'mac/mac-continue.png'],
            ['title' => 'Enter your Mac password',
             'html'  => 'When prompted, type your <strong>Mac login password</strong> (the one you use to sign in to your Mac) and click <strong>Install Software</strong> to begin copying the apps.',
             'img'   => 'mac/mac-password.png'],
            ['title' => 'Finish the installation',
             'html'  => 'Office installs in a few minutes. When you see <strong>&ldquo;The installation was successful&rdquo;</strong>, click <strong>Close</strong>. You can drag the installer package to the Trash afterwards.',
             'img'   => 'mac/mac-success.png'],
            ['title' => 'Open Word from Launchpad',
             'html'  => 'Click the <strong>Launchpad</strong> icon in the Dock and open <strong>Microsoft Word</strong> (or Excel / PowerPoint). Tip: Control-click the app in the Dock and choose <strong>Options ▸ Keep in Dock</strong> to pin it for quick access.',
             'img'   => 'mac/mac-word-icon.png'],
            ['title' => 'Sign in to activate',
             'html'  => 'The <strong>What&rsquo;s New</strong> window opens automatically &mdash; click <strong>Get Started</strong>, then <strong>Sign in</strong> and use the <em>same</em> Microsoft account you redeemed the key with. Activation completes instantly (there is no product-key box on Mac). You can confirm any time under <strong>File ▸ Account</strong>.',
             'img'   => 'mac/mac-getstarted.png'],
        ],
        'activation' => 'On Mac the licence lives in your Microsoft account. Redeem your key at <a href="{{activation}}" target="_blank" rel="noopener">{{activation_host}}</a>, then sign in with the same account inside Word/Excel to activate &mdash; there is no product-key box on Mac. <strong>Important:</strong> if an older Office version or a Microsoft&nbsp;365 trial is already installed, uninstall it first (drag those apps from <em>Applications</em> to the Trash) to avoid activation conflicts. Confirm activation under <strong>File ▸ Account</strong>.',
    ],

    /* ───────────────────────── Windows desktop ──────────────────────────── */
    'windows' => [
        'label'    => 'Microsoft Windows',
        'platform' => 'PC',
        'flow' => [
            ['icon' => 'bi-download',    'label' => 'Download tool'],
            ['icon' => 'bi-usb-drive',   'label' => 'Create media'],
            ['icon' => 'bi-gear-wide-connected', 'label' => 'Settings ▸ Activation'],
            ['icon' => 'bi-key',         'label' => 'Change key'],
            ['icon' => 'bi-input-cursor-text', 'label' => 'Enter key'],
            ['icon' => 'bi-patch-check', 'label' => 'Activated'],
        ],
        'system' => [
            '1 GHz or faster compatible 64-bit processor (Windows 11 needs TPM 2.0)',
            '4 GB RAM or more',
            '64 GB or larger storage device',
            'A blank USB drive (8 GB+) or DVD if you create installation media',
            'A stable internet connection',
        ],
        'steps' => [
            ['title' => 'Download the Media Creation Tool',
             'html'  => 'Click <strong>Download installer</strong> below to get the official Media Creation Tool. This is used to install or upgrade Windows from your existing PC.',
             'img'   => 'windows/step-media.png'],
            ['title' => 'Run the tool to install or upgrade Windows',
             'html'  => 'Launch the tool and follow the prompts to either <strong>upgrade this PC now</strong> or <strong>create installation media</strong> (USB/DVD) for a clean install. Accept the licence terms and let it complete.',
             'img'   => null],
            ['title' => 'Open Settings → Activation',
             'html'  => 'Once Windows is installed, go to <strong>Settings ▸ System ▸ Activation</strong> (on Windows 10: <em>Settings ▸ Update &amp; Security ▸ Activation</em>).',
             'img'   => 'windows/step-settings.jpg'],
            ['title' => 'Click "Change product key"',
             'html'  => 'Under the activation panel, choose <strong>Change product key</strong> to open the key-entry box.',
             'img'   => 'windows/step-change.jpg'],
            ['title' => 'Enter your 25-character key',
             'html'  => 'Type the product key from your order email and click <strong>Next</strong>, then <strong>Activate</strong>.',
             'img'   => 'windows/step-key.jpg'],
            ['title' => 'Windows is activated',
             'html'  => 'You&rsquo;ll see &ldquo;Windows is activated&rdquo;. That&rsquo;s it &mdash; your licence is permanent on this device.',
             'img'   => 'windows/step-activated.jpg'],
        ],
        'activation' => 'Activate from <strong>Settings ▸ System ▸ Activation ▸ Change product key</strong> as shown above. To make re-installs effortless, sign in with a free Microsoft account at <a href="{{activation}}" target="_blank" rel="noopener">{{activation_host}}</a> so your digital licence is linked to your account.',
    ],

    ];
}

}
