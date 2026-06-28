#====================================================================================================
# START - Testing Protocol - DO NOT EDIT OR REMOVE THIS SECTION
#====================================================================================================

# THIS SECTION CONTAINS CRITICAL TESTING INSTRUCTIONS FOR BOTH AGENTS
# BOTH MAIN_AGENT AND TESTING_AGENT MUST PRESERVE THIS ENTIRE BLOCK

# Communication Protocol:
# If the `testing_agent` is available, main agent should delegate all testing tasks to it.
#
# You have access to a file called `test_result.md`. This file contains the complete testing state
# and history, and is the primary means of communication between main and the testing agent.
#
# Main and testing agents must follow this exact format to maintain testing data. 
# The testing data must be entered in yaml format Below is the data structure:
# 
## user_problem_statement: {problem_statement}
## backend:
##   - task: "Task name"
##     implemented: true
##     working: true  # or false or "NA"
##     file: "file_path.py"
##     stuck_count: 0
##     priority: "high"  # or "medium" or "low"
##     needs_retesting: false
##     status_history:
##         -working: true  # or false or "NA"
##         -agent: "main"  # or "testing" or "user"
##         -comment: "Detailed comment about status"
##
## frontend:
##   - task: "Task name"
##     implemented: true
##     working: true  # or false or "NA"
##     file: "file_path.js"
##     stuck_count: 0
##     priority: "high"  # or "medium" or "low"
##     needs_retesting: false
##     status_history:
##         -working: true  # or false or "NA"
##         -agent: "main"  # or "testing" or "user"
##         -comment: "Detailed comment about status"
##
## metadata:
##   created_by: "main_agent"
##   version: "1.0"
##   test_sequence: 0
##   run_ui: false
##
## test_plan:
##   current_focus:
##     - "Task name 1"
##     - "Task name 2"
##   stuck_tasks:
##     - "Task name with persistent issues"
##   test_all: false
##   test_priority: "high_first"  # or "sequential" or "stuck_first"
##
## agent_communication:
##     -agent: "main"  # or "testing" or "user"
##     -message: "Communication message between agents"

# Protocol Guidelines for Main agent
#
# 1. Update Test Result File Before Testing:
#    - Main agent must always update the `test_result.md` file before calling the testing agent
#    - Add implementation details to the status_history
#    - Set `needs_retesting` to true for tasks that need testing
#    - Update the `test_plan` section to guide testing priorities
#    - Add a message to `agent_communication` explaining what you've done
#
# 2. Incorporate User Feedback:
#    - When a user provides feedback that something is or isn't working, add this information to the relevant task's status_history
#    - Update the working status based on user feedback
#    - If a user reports an issue with a task that was marked as working, increment the stuck_count
#    - Whenever user reports issue in the app, if we have testing agent and task_result.md file so find the appropriate task for that and append in status_history of that task to contain the user concern and problem as well 
#
# 3. Track Stuck Tasks:
#    - Monitor which tasks have high stuck_count values or where you are fixing same issue again and again, analyze that when you read task_result.md
#    - For persistent issues, use websearch tool to find solutions
#    - Pay special attention to tasks in the stuck_tasks list
#    - When you fix an issue with a stuck task, don't reset the stuck_count until the testing agent confirms it's working
#
# 4. Provide Context to Testing Agent:
#    - When calling the testing agent, provide clear instructions about:
#      - Which tasks need testing (reference the test_plan)
#      - Any authentication details or configuration needed
#      - Specific test scenarios to focus on
#      - Any known issues or edge cases to verify
#
# 5. Call the testing agent with specific instructions referring to test_result.md
#
# IMPORTANT: Main agent must ALWAYS update test_result.md BEFORE calling the testing agent, as it relies on this file to understand what to test next.

#====================================================================================================
# END - Testing Protocol - DO NOT EDIT OR REMOVE THIS SECTION
#====================================================================================================



#====================================================================================================
# Testing Data - Main Agent and testing sub agent both should log testing data below this section

user_problem_statement: |
  Scan manuals.winandoffice.com and, for every product we sell, capture the
  Activation/Sign-in URL, the Installation Guide URL, and the one-click Installer
  Download URL (English, 64-bit). Save them into each product (activation_url /
  install_guide_url / installer_url, mode switched to 'manual') so they appear in
  the delivery email + order/account pages, and add a UI block on the product page.
  Products with no exact manual → closest manual. Bitdefender + McAfee → skipped
  (no manuals on the site).

backend:
  - task: "Seed per-product manual URLs (activation / install-guide / installer) from manuals.winandoffice.com"
    implemented: true
    working: true
    file: "php-version/scripts/seed-manual-urls.php, php-version/database.sql, php-version/start.sh"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "main"
        -comment: "Mapped 30 non-antivirus products to their (closest) manual page on manuals.winandoffice.com and seeded activation_url + install_guide_url + installer_url, flipping activation_url_mode/install_url_mode to 'manual'. Office/Project/Visio activation=https://setup.office.com; Windows activation=https://account.microsoft.com/account. Office-for-Mac (8 products) have NULL installer_url (installer is obtained after sign-in). All 7 antivirus products (Bitdefender x6, McAfee x1) intentionally skipped — no manuals exist. Idempotent: only fills rows whose install_guide_url is empty (never clobbers admin edits). Wired into start.sh (runs every boot) and appended to database.sql (fresh imports). Verified via curl: 30 rows have install_url_mode='manual', antivirus rows still NULL. Installer/guide/activation links return HTTP 200/206."
        -working: true
        -agent: "testing"
        -comment: "✅ VERIFIED - Database seeding working correctly. Confirmed: (1) Exactly 30 products have install_url_mode='manual' with non-empty install_guide_url. (2) All 8 Office-for-Mac products have non-NULL activation_url + install_guide_url but NULL installer_url (correct - Mac has no one-click installer). (3) All 7 antivirus products (6 Bitdefender + 1 McAfee) have NULL activation_url/install_guide_url/installer_url (correct - intentionally skipped). (4) Spot-checked key products: microsoft-office-2024-professional-plus-windows has correct URLs (Office_2024_EN_64Bits.exe, manuals.winandoffice.com/o24pp/, setup.office.com); windows-11-pro has MediaCreationTool.exe + w11p/ + account.microsoft.com; project-2024 and visio-2021 have correct exe + guide URLs. (5) Link liveness confirmed: Office 2024 installer returns HTTP 206, manual page returns HTTP 200, setup.office.com returns HTTP 200."

frontend:
  - task: "Product page 'Download, install & activate' block (Download installer / Installation guide / Activate-Sign in buttons)"
    implemented: true
    working: true
    file: "php-version/product.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "main"
        -comment: "Added a visible card after the product tabs that renders up to 3 buttons from the product's installer_url / install_guide_url / activation_url. Shows all 3 for Windows/PC Office/Project/Visio; for Mac shows Installation guide + Activate only (no one-click installer) plus a hint note; hidden entirely for products without any manual (e.g. Bitdefender). Verified via curl on Office PP (3 buttons, exe + manuals/o24pp + setup.office.com), Mac (2 buttons, no download), Bitdefender (block absent), and a screenshot."
        -working: true
        -agent: "testing"
        -comment: "✅ VERIFIED - Product page install blocks rendering correctly via HTTP GET tests. Confirmed: (1) microsoft-office-2024-professional-plus-windows shows 3 buttons with correct hrefs: install-download-btn → Office_2024_EN_64Bits.exe, install-guide-btn → manuals.winandoffice.com/o24pp/, install-activate-btn → setup.office.com. (2) windows-11-pro shows 3 buttons: MediaCreationTool.exe, w11p/, account.microsoft.com/account. (3) microsoft-project-2024-professional-pc shows 3 buttons: project_2024_EN_64Bits.exe, p24p/, setup.office.com. (4) microsoft-visio-2021-professional-windows-pc shows 3 buttons: visio_2021_EN_pro_64Bits.exe, v21p/, setup.office.com. (5) microsoft-office-home-business-2024-mac shows ONLY 2 buttons (install-guide-btn + install-activate-btn), install-download-btn correctly ABSENT. (6) bitdefender-antivirus-for-mac-1-mac-1-year has NO install block (data-testid='product-install-block' not present) - correct."
  - task: "Order-success per-item install/download/activate buttons now populated from manuals data"
    implemented: true
    working: true
    file: "php-version/order-success.php"
    stuck_count: 0
    priority: "medium"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "main"
        -comment: "No code change needed — order-success.php and the delivery email (includes/email.php build_installation_guide_cards) already read activation_url/install_guide_url/installer_url per order item. Now that products are seeded, these surfaces auto-show the manuals links + one-click installer for Microsoft products."
        -working: true
        -agent: "testing"
        -comment: "✅ VERIFIED - Order-success page buttons working correctly. Tested demo order MVT-DEMO-002 (contains microsoft-office-home-business-2024-pc). Found 4 buttons total: (1) success-installer-btn → Office_2024_EN_standard_64Bits.exe, (2) success-activate-btn → setup.office.com, (3) success-installguide-btn → manuals.winandoffice.com/o24s/, (4) guide-installer-btn → Office_2024_EN_standard_64Bits.exe. All buttons present with correct hrefs from seeded product data."
  - task: "Native on-site installation guide pages (/install-guide.php) with flowchart + screenshots"
    implemented: true
    working: true
    file: "php-version/install-guide.php, php-version/includes/install-guides.php, php-version/uploads/guides/*"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "main"
        -comment: "Built our OWN installation-guide pages (no longer link out to manuals.winandoffice.com). install-guide.php?slug=<slug> renders a branded guide with site header/footer: hero (Download installer + Activate/Sign in buttons), a CSS flowchart stepper, numbered step-by-step instructions with REAL screenshots self-hosted at /uploads/guides/{office,retail,mac,windows}/, system-requirements card, license & activation card, Need-help CTA, plus HowTo JSON-LD. 4 install-flow templates (office_key, office_retail, office_mac, windows) cover all 30 products, personalised with each product's name + its own installer/activation links. Antivirus (no template) gets a graceful fallback."
        -working: true
        -agent: "testing"
        -comment: "✅ VERIFIED - All native guide pages working correctly. Tested: (1) microsoft-office-2024-professional-plus-windows: HTTP 200, data-testid='install-guide' present, flowchart present, 6 steps with 5 screenshots in /uploads/guides/office/ (all HTTP 200), hero has Download installer → Office_2024_EN_64Bits.exe + Activate → setup.office.com. (2) microsoft-office-2021-home-business-windows: HTTP 200, 6 steps, ISO/retail flow with 5 screenshots (2 retail/, 3 office/), all HTTP 200. (3) microsoft-office-home-business-2024-mac: HTTP 200, 8 steps, NO Download installer button (correct for Mac), has Activate/Sign in → setup.office.com, 7 screenshots in /uploads/guides/mac/ (all HTTP 200). (4) windows-11-pro: HTTP 200, 6 steps, Download installer → MediaCreationTool.exe + Activate → account.microsoft.com/account, 5 screenshots in /uploads/guides/windows/ (all HTTP 200). (5) bitdefender-antivirus-for-mac-1-mac-1-year: HTTP 200, NO data-testid='install-guide' (correct graceful fallback), shows 'Browse products' + 'Contact support' buttons."
  - task: "Repoint install_guide_url to our own guide page + absolutize for email"
    implemented: true
    working: true
    file: "php-version/scripts/seed-manual-urls.php, php-version/database.sql, php-version/includes/functions.php, php-version/includes/email.php, php-version/email-view.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "main"
        -comment: "Migrated all 30 products install_guide_url from external manuals.winandoffice.com to our own relative /install-guide.php?slug=<slug> (0 external links remain in DB or database.sql). Added mv_absolute_url() helper, applied in email.php + email-view.php so relative guide/installer links become absolute (current host) inside emails."
        -working: true
        -agent: "testing"
        -comment: "✅ VERIFIED - URL migration complete and working correctly. Database checks: (1) 30 products have install_guide_url LIKE '/install-guide.php%' ✅. (2) 0 products have install_guide_url LIKE '%manuals.winandoffice.com%' ✅. (3) 7 antivirus products (6 Bitdefender + 1 McAfee) correctly have NULL install_guide_url/installer_url/activation_url ✅. Product page test (microsoft-office-2024-professional-plus-windows): Installation guide button (data-testid='install-guide-btn') now points to /install-guide.php?slug=microsoft-office-2024-professional-plus-windows (our own page) ✅, while Download installer + Activate buttons remain external (Office_2024_EN_64Bits.exe, setup.office.com) ✅. Order-success page test (MVT-DEMO-002 with microsoft-office-home-business-2024-pc): Installation guide button (data-testid='guide-installguide-btn') points to /install-guide.php?slug=microsoft-office-home-business-2024-pc ✅, Download installer → Office_2024_EN_standard_64Bits.exe ✅, Install & sign in → setup.office.com ✅."


metadata:
  created_by: "main_agent"
  version: "1.0"
  test_sequence: 2
  run_ui: false

test_plan:
  current_focus: []
  stuck_tasks: []
  test_all: false
  test_priority: "high_first"

agent_communication:
    -agent: "main"
    -message: |
      Please validate (PHP store on the preview URL; backend = PHP endpoints via curl/HTTP):
      1) Product pages render the new install block correctly:
         - microsoft-office-2024-professional-plus-windows → 3 buttons: Download installer (…/Office_2024_EN_64Bits.exe), Installation guide (manuals.winandoffice.com/o24pp/), Activate/Sign in (setup.office.com)
         - windows-11-pro → Download installer (…/MediaCreationTool.exe), guide (…/w11p/), Activate (account.microsoft.com)
         - microsoft-visio-2021-professional-windows-pc and microsoft-project-2024-professional-pc → 3 buttons with visio/project EN 64-bit exe
         - microsoft-office-home-business-2024-mac → ONLY Installation guide + Activate (NO Download installer button)
         - bitdefender-antivirus-for-mac-1-mac-1-year → install block MUST be absent (data-testid="product-install-block" not present)
      2) Confirm the seeded DB values: 30 products have install_url_mode='manual' and non-empty install_guide_url; antivirus products remain NULL.
      3) Spot-check that a couple of installer URLs + guide URLs return HTTP 200/206 (live links).
      4) (If feasible) Place a demo-mode order for an Office product and confirm the order-success page shows the per-item 'Download installer' / 'Install & sign in' / 'Installation guide' buttons.
      Do NOT test or modify anything related to Bitdefender/McAfee mapping — they are intentionally skipped.
    -agent: "testing"
    -message: |
      ✅ ALL BACKEND TESTS PASSED - Complete validation successful.
      
      BACKEND TASK: "Seed per-product manual URLs" - ✅ WORKING
      - Database state verified: 30 products with install_url_mode='manual'
      - All 8 Mac Office products correctly have NULL installer_url
      - All 7 antivirus products (6 Bitdefender + 1 McAfee) correctly have NULL for all manual fields
      - Sample products verified with correct URLs (Office 2024 PP, Windows 11 Pro, Project 2024, Visio 2021)
      - Link liveness confirmed (HTTP 200/206 responses)
      
      FRONTEND TASK: "Product page install block" - ✅ WORKING (tested via HTTP)
      - microsoft-office-2024-professional-plus-windows: 3 buttons with correct hrefs ✅
      - windows-11-pro: 3 buttons with correct hrefs ✅
      - microsoft-project-2024-professional-pc: 3 buttons with correct hrefs ✅
      - microsoft-visio-2021-professional-windows-pc: 3 buttons with correct hrefs ✅
      - microsoft-office-home-business-2024-mac: 2 buttons only (download button correctly absent) ✅
      - bitdefender-antivirus-for-mac-1-mac-1-year: install block correctly absent ✅
      
      FRONTEND TASK: "Order-success buttons" - ✅ WORKING (tested via HTTP)
      - Tested demo order MVT-DEMO-002 (Office Home & Business 2024 PC)
      - Found 4 buttons with correct hrefs: installer, activate, guide, guide-installer
      - All buttons populated from seeded product data ✅
      
      All requirements from the review request have been validated and are working correctly.

    -agent: "main"
    -message: |
      NEW WORK TO VALIDATE — we replaced the external manuals link with our OWN guide pages.
      PHP store on the preview URL; "backend" = PHP endpoints via curl/HTTP. You may read MariaDB locally (mysql -uroot ucode_store).

      1) NATIVE GUIDE PAGE — GET /install-guide.php?slug=<slug> and assert HTTP 200 + the page contains:
         data-testid="install-guide", a flowchart (data-testid="guide-flowchart"), numbered steps (data-testid="guide-step-N"),
         and locally-hosted screenshots (img src starting with /uploads/guides/...). Verify each <img> under /uploads/guides/ returns HTTP 200.
         Test one slug per family:
           - microsoft-office-2024-professional-plus-windows (office_key: 6 steps, Download+Activate buttons, installer .../Office_2024_EN_64Bits.exe)
           - microsoft-office-2021-home-business-windows (office_retail: ISO mount steps)
           - microsoft-office-home-business-2024-mac (office_mac: 8 steps, has Activate/Sign in; Mac has NO one-click installer button on the guide hero)
           - windows-11-pro (windows: MediaCreationTool installer, account.microsoft.com activate)
         And bitdefender-antivirus-for-mac-1-mac-1-year → graceful fallback page (HTTP 200, NO data-testid="install-guide", shows "Contact support").
      2) URL MIGRATION — confirm in DB: SELECT COUNT(*) FROM products WHERE install_guide_url LIKE '/install-guide.php%' == 30, and COUNT(*) WHERE install_guide_url LIKE '%manuals.winandoffice.com%' == 0. Antivirus rows still NULL.
      3) PRODUCT PAGE — GET /product.php?slug=microsoft-office-2024-professional-plus-windows: the "Installation guide" button (data-testid="install-guide-btn") href must now be /install-guide.php?slug=microsoft-office-2024-professional-plus-windows (our own page), while Download installer + Activate stay external.
      4) ORDER-SUCCESS — for demo order MVT-DEMO-002, the "Installation guide" button href should now point to our /install-guide.php?slug=... page (relative), not manuals.winandoffice.com.
      Do NOT modify code. Report pass/fail per item with actual hrefs/status codes observed.

    -agent: "testing"
    -message: |
      ✅ ALL TESTS PASSED - Native guide pages and URL migration fully validated.
      
      FRONTEND TASK: "Native on-site installation guide pages" - ✅ WORKING
      All guide pages tested via HTTP with complete validation:
      
      1) microsoft-office-2024-professional-plus-windows (office_key):
         - HTTP 200 ✅
         - data-testid="install-guide" present ✅
         - data-testid="guide-flowchart" present ✅
         - 6 steps (guide-step-1 through guide-step-6) ✅
         - 5 screenshots in /uploads/guides/office/ (step-run.jpg, step-install.jpg, step-open.jpg, step-changekey.jpg, step-enterkey.jpg) - all HTTP 200 ✅
         - Hero: Download installer → https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_64Bits.exe ✅
         - Hero: Activate → https://setup.office.com ✅
      
      2) microsoft-office-2021-home-business-windows (office_retail):
         - HTTP 200 ✅
         - 6 steps present ✅
         - 5 screenshots (2 in retail/, 3 in office/) - all HTTP 200 ✅
         - ISO/retail flow confirmed ✅
      
      3) microsoft-office-home-business-2024-mac (office_mac):
         - HTTP 200 ✅
         - 8 steps present ✅
         - NO Download installer button (correct for Mac - installer obtained after sign-in) ✅
         - Activate/Sign in button present → https://setup.office.com ✅
         - 7 screenshots in /uploads/guides/mac/ (mac-1.jpg, mac-2.png, mac-3.png, mac-4.png, mac-6.png, mac-7.png, mac-8.png) - all HTTP 200 ✅
      
      4) windows-11-pro (windows):
         - HTTP 200 ✅
         - 6 steps present ✅
         - Download installer → https://download.winandoffice.com/Retail/Desktop/MediaCreationTool.exe ✅
         - Activate → https://account.microsoft.com/account ✅
         - 5 screenshots in /uploads/guides/windows/ (step-media.png, step-settings.jpg, step-change.jpg, step-key.jpg, step-activated.jpg) - all HTTP 200 ✅
      
      5) bitdefender-antivirus-for-mac-1-mac-1-year (no template - graceful fallback):
         - HTTP 200 ✅
         - NO data-testid="install-guide" (correct - graceful fallback page) ✅
         - Shows "Browse products" and "Contact support" buttons ✅
      
      FRONTEND TASK: "Repoint install_guide_url to our own guide page" - ✅ WORKING
      
      Database migration verified:
      - 30 products have install_guide_url LIKE '/install-guide.php%' ✅
      - 0 products have install_guide_url LIKE '%manuals.winandoffice.com%' ✅
      - 7 antivirus products have NULL install_guide_url/installer_url/activation_url ✅
      
      Product page verified (microsoft-office-2024-professional-plus-windows):
      - Installation guide button (data-testid="install-guide-btn") → /install-guide.php?slug=microsoft-office-2024-professional-plus-windows ✅
      - Download installer button → https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_64Bits.exe (external) ✅
      - Activate button → https://setup.office.com (external) ✅
      
      Order-success page verified (MVT-DEMO-002 with microsoft-office-home-business-2024-pc):
      - Installation guide button (data-testid="guide-installguide-btn") → /install-guide.php?slug=microsoft-office-home-business-2024-pc ✅
      - Download installer button → https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_standard_64Bits.exe (external) ✅
      - Install & sign in button → https://setup.office.com (external) ✅
      
      All requirements from the review request validated successfully. No issues found.

#====================================================================================================
    -agent: "main"
    -message: |
      COMPREHENSIVE UI TESTING REQUEST — Test the installation guide feature end-to-end via browser automation.
      Focus ONLY on the new installation guide feature. Test at https://office-activate.preview.emergentagent.com
      
      PART 1-4: Test native guide pages, product page block, order history page, and admin filter.

    -agent: "testing"
    -message: |
      ✅ COMPREHENSIVE UI TESTING COMPLETE - All 4 parts validated via browser automation.
      
      PART 1: Native Installation Guide Pages - ✅ ALL PASSED
      Tested 5 guide pages with full visual verification:
      - Office 2024 PP (Windows): ✅ All elements present, 5 screenshots loaded, Download+Activate buttons
      - Office 2021 H&B (Windows): ✅ All elements present, 5 screenshots loaded
      - Office H&B 2024 (Mac): ✅ All elements present, 7 screenshots loaded, NO Download button (correct)
      - Windows 11 Pro: ✅ All elements present, 5 screenshots loaded, Download+Activate buttons
      - Bitdefender (Fallback): ✅ Graceful fallback page, NO install-guide testid, Browse/Contact buttons
      
      PART 2: Product Page Install Block - ✅ PASSED
      - All 3 buttons present with correct hrefs
      - Installation guide button points to /install-guide.php?slug=... (our own page)
      
      PART 3: Order History Page - ✅ PASSED
      - Lookup form works correctly
      - Install card appears with all 3 buttons
      - Installation guide button href contains /install-guide.php?slug=
      
      PART 4: Admin Filter - ✅ VERIFIED VIA DATABASE
      - 7 antivirus products have NULL install_guide_url (Bitdefender x6 + McAfee x1)
      - 30 Microsoft products have non-NULL install_guide_url
      - Filter logic confirmed correct via database queries
      
      All screenshots captured. No broken images or console errors. Feature is production-ready.


    -agent: "main"
    -message: |
      ENHANCEMENTS ADDED (round 2) + FULL BROWSER UI TESTING DONE (auto_frontend_testing_agent):
      1) order-history.php (customer "Track Order & Receipts") now shows a "Download & install" card
         (data-testid="oh-install-card") per purchased item with oh-installer-btn / oh-installguide-btn
         (→ /install-guide.php?slug=...) / oh-activate-btn. Verified with MVT-DEMO-002 (john.demo@example.com).
      2) admin.php Products tab: new "Install guide" filter (data-testid="filter-guide") with options
         "Missing guide URL" / "Has guide URL". Verified: missing = the 7 antivirus products only.
      3) Expanded the Mac (office_mac) guide step captions with richer, more accurate detail.
      4) Closest-match mappings reviewed and kept as-is (sensible: Office Home/Word/Excel → suite/Standard guide).
      Browser UI testing: ALL PASSED — guide pages render flowchart + numbered steps + screenshots load
      (naturalWidth>0), Mac hides Download button, antivirus shows graceful fallback, product page + order
      history link to our own guide page, admin filter logic verified. test_credentials.md populated.


    -agent: "main"
    -message: |
      USER REQUEST: order-success product card should show Download now + Installation guide + Sign in to activate.
      FIXED + verified:
      1) Added a code-level fallback (mv_product_install_meta / mv_resolve_install_links in includes/install-guides.php)
         so installer/guide/activation links resolve from a central mapping even when the products table isn't seeded
         or on the demo-preview path. Applied in order-success.php (both paths), order-history.php, product.php, includes/email.php.
      2) BUG: the green "Download" buttons used Bootstrap class btn-success, but the theme CSS rule
         .btn-success{...;background-image:none !important;} killed the inline gradient AND the shorthand reset
         background-color to transparent -> white-on-white invisible button. Removed btn-success from the 5 download
         buttons (order-success x2, order-history, product.php, install-guide.php) and applied the green gradient +
         color:#fff with !important inline. Verified via getComputedStyle: color rgb(255,255,255), bgImage green gradient.
      3) Renamed the order-success product-card installer button label to "Download now" per request.
      Screenshot confirms all three buttons render on order-success (Sign in to activate / View installation guide / Download now).
      NOTE: the live deployed site needs this code deployed (Save to GitHub -> deploy); the fallback means no DB seed is required for buttons to appear.

    -agent: "main"
    -message: |
      MAC OFFICE GUIDE rebuilt to be accurate (user: "make it according to Mac installation, get real stuff online").
      - Discovered the old Mac screenshots (i1-i13) were actually a Windows-style web-portal flow (mentioned Start Menu),
        which is why the Mac guide was wrong. Removed them.
      - Sourced the OFFICIAL macOS Office installer screenshots from Microsoft's support CDN and re-hosted them at
        /uploads/guides/mac/ (mac-portal-install, mac-dock-pkg, mac-continue, mac-password, mac-success, mac-word-icon,
        mac-getstarted). All serve HTTP 200, naturalWidth>0.
      - Rewrote office_mac template per the real Microsoft flow: Redeem key at setup.office.com -> download .pkg from
        account.microsoft.com -> open installer (.pkg) -> Continue/Agree/Install -> enter Mac password -> success ->
        open Word from Launchpad -> Get Started / Sign in to activate. 8 steps, proper Mac flowchart, uninstall-old-Office tip.
      - Verified: Mac guide renders 8 steps with 7 real macOS screenshots; hero shows Activate/Sign in + Back, NO one-click
        Download button (Mac downloads after sign-in). Screenshot confirms authentic macOS installer password + success screens.
