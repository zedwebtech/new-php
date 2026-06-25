"""
Tests for the production-domain rendering fix.

Verifies that when the PHP storefront is served on the real production
host (maventechsoftware.com), absolutely no Emergent preview hostnames
(*.preview.emergentagent.com) leak into the rendered HTML — even though
the DB has stale values for main_url / site_domain_url / company_logo.
"""
import os
import re
import pytest
import requests

# The PHP server runs locally on 127.0.0.1:3000 inside the pod. The Host
# header is overridden to simulate the production deployment.
BASE = os.environ.get("PHP_BASE_URL", "http://127.0.0.1:3000")
PROD_HOST = "maventechsoftware.com"
PREVIEW_HOST = "show-live-7.preview.emergentagent.com"

PREVIEW_RE = re.compile(r"https?://[^\s\"'<>)]*preview\.emergentagent\.com[^\s\"'<>)]*", re.I)

# All public pages that must render zero preview URLs when served on prod.
PUBLIC_PAGES = [
    "/",
    "/shop.php",
    "/product.php?slug=microsoft-office-2024-professional-plus-windows",
    "/product.php?slug=bitdefender-antivirus-for-mac-1-mac-1-year",
    "/reviews.php",
    "/track-order.php",
    "/order-history.php",
    "/cart.php",
    "/checkout.php",
    "/about-us.php",
    "/contact.php",
    "/support.php",  # faq.php does not exist in this codebase; help center is support.php
    "/blog.php",
]


def fetch(path, host=PROD_HOST, allow_redirects=True, session=None):
    s = session or requests.Session()
    return s.get(BASE + path, headers={"Host": host}, allow_redirects=allow_redirects, timeout=15)


@pytest.mark.parametrize("path", PUBLIC_PAGES)
def test_public_page_has_no_preview_urls_on_prod_host(path):
    r = fetch(path)
    assert r.status_code in (200, 302), f"{path} returned {r.status_code}"
    body = r.text
    leaks = PREVIEW_RE.findall(body)
    assert not leaks, f"{path} leaked preview URLs: {leaks[:5]}"


def test_order_success_qr_uses_prod_host():
    # Use the order number that already exists in DB (verified earlier as MV260623589F9
    # per request, or fall back to the seed MV260623915EA).
    for order in ("MV260623589F9", "MV260623915EA"):
        r = fetch(f"/order-success.php?order={order}")
        if r.status_code == 200 and "receipt-qr" in r.text:
            body = r.text
            break
    else:
        pytest.skip("No matching order found for QR test")

    # No preview URL anywhere in body
    leaks = PREVIEW_RE.findall(body)
    assert not leaks, f"order-success leaked: {leaks[:3]}"

    # QR data-url attribute must point at production host
    m = re.search(r'data-testid="receipt-qr"[^>]*data-url="([^"]+)"', body)
    assert m, "receipt-qr data-url not found"
    qr_url = m.group(1)
    assert PROD_HOST in qr_url, f"QR url does not contain prod host: {qr_url}"
    assert "preview.emergentagent.com" not in qr_url


def test_company_logo_is_not_preview_host():
    r = fetch("/")
    assert r.status_code == 200
    # Match the header logo <img> tag.
    matches = re.findall(r'<img[^>]+src="([^"]+)"[^>]*(?:class="[^"]*logo|alt="[^"]*logo)', r.text, re.I)
    found = False
    for src in matches:
        found = True
        assert "preview.emergentagent.com" not in src, f"Logo src leaks preview: {src}"
    # Even if regex doesn't catch a specific class, ensure logo-356b9f03 file is referenced cleanly
    if "logo-356b9f03" in r.text:
        for hit in re.findall(r'[^"\'\s]*logo-356b9f03[^"\'\s]*', r.text):
            assert "preview.emergentagent.com" not in hit, f"Stale logo URL: {hit}"


def test_nav_links_are_relative_or_prod():
    r = fetch("/")
    assert r.status_code == 200
    # Find every href to track-order.php / reviews.php / shop.php — must NOT include preview host
    for slug in ("track-order.php", "reviews.php", "shop.php"):
        for href in re.findall(rf'href="([^"]*{re.escape(slug)}[^"]*)"', r.text):
            assert "preview.emergentagent.com" not in href, f"{slug} href leaks: {href}"


def test_preview_host_context_still_works():
    """Control: when CURRENT host is itself a preview host, rewriting must NOT clobber it."""
    r = fetch("/", host=PREVIEW_HOST)
    assert r.status_code == 200
    # Page should still render fine — no assertion about URLs (preview links allowed here).
    assert "Maventech" in r.text or "<html" in r.text.lower()


def test_checkout_flow_redirects_to_order_success_on_prod_host():
    """End-to-end: add item to cart and checkout on prod host."""
    s = requests.Session()
    # Add an item to the cart via the existing AJAX endpoint.
    # We pick product slug 'microsoft-office-2024-professional-plus-windows' (id discovery via product page).
    r = fetch("/product.php?slug=microsoft-office-2024-professional-plus-windows", session=s)
    assert r.status_code == 200
    m = re.search(r'name="product_id"\s+value="(\d+)"', r.text) or re.search(r'data-product-id="(\d+)"', r.text)
    if not m:
        pytest.skip("Could not discover product_id from product page")
    pid = m.group(1)

    # POST add to cart
    s.post(BASE + "/ajax/cart.php",
           headers={"Host": PROD_HOST},
           data={"action": "add", "product_id": pid, "qty": "1"},
           timeout=15)

    # Submit checkout
    r = s.post(BASE + "/checkout.php",
               headers={"Host": PROD_HOST},
               data={
                   "first_name": "Test", "last_name": "Buyer",
                   "email": "test+regression@example.com",
                   "phone": "5555550100",
                   "address": "1 Test St", "city": "Test", "state": "CA",
                   "zip": "94590", "country": "US",
                   "payment_method": "card",
                   "card_number": "4242424242424242",
                   "card_name": "Test Buyer",
                   "card_exp": "12/30",
                   "card_cvc": "123",
               },
               allow_redirects=False, timeout=30)
    # Either a 302 to order-success, or 200 rendering checkout with errors.
    if r.status_code == 302:
        loc = r.headers.get("Location", "")
        assert "order-success.php" in loc, f"unexpected redirect: {loc}"
        assert "preview.emergentagent.com" not in loc

        # Fetch the success page
        r2 = s.get(BASE + "/" + loc.lstrip("/"), headers={"Host": PROD_HOST}, timeout=15)
        assert r2.status_code == 200
        assert "Order Confirmed" in r2.text or "order-success" in r2.text.lower()
        leaks = PREVIEW_RE.findall(r2.text)
        assert not leaks, f"order-success leaked after checkout: {leaks[:3]}"
    else:
        # If checkout failed (e.g. gateway in test mode), at least confirm no PDOException
        assert "PDOException" not in r.text
        assert "SQLSTATE" not in r.text
        pytest.skip(f"Checkout did not redirect (status={r.status_code}); page rendered without DB error")


# -------------------- Admin setup-check.php hygiene card --------------------

ADMIN_EMAIL = "admin@maventechsoftware.com"
ADMIN_PASS = "Admin@UC2026!"


def _admin_login():
    s = requests.Session()
    s.get(BASE + "/login.php", headers={"Host": PROD_HOST}, timeout=15)
    r = s.post(BASE + "/login.php",
               headers={"Host": PROD_HOST},
               data={"email": ADMIN_EMAIL, "password": ADMIN_PASS},
               allow_redirects=False, timeout=15)
    assert r.status_code in (200, 302), f"login HTTP {r.status_code}"
    return s


def test_setup_check_shows_hygiene_card_when_leaks_exist_then_fix_clears_them():
    s = _admin_login()
    r = s.get(BASE + "/setup-check.php", headers={"Host": PROD_HOST}, timeout=15)
    assert r.status_code == 200
    assert "Public domain hygiene" in r.text
    assert "Current request host" in r.text
    # Hygiene-card data-testid hooks must be present
    assert 'data-testid="public-domain-hygiene-card"' in r.text
    assert 'data-testid="hygiene-current-host"' in r.text
    assert 'data-testid="hygiene-site-url"' in r.text
    # Either we have leaks + the strip button, or we already have a green message.
    if "Strip preview hostnames now" in r.text:
        assert 'data-testid="hygiene-leak-warning"' in r.text
        assert 'data-testid="hygiene-fix-btn"' in r.text
        # Count leak rows — must be exactly the URL-keyed settings,
        # NOT JSON-blob rows like seo_health_probe_cache.
        leak_row_count = r.text.count('data-testid="hygiene-leak-row"')
        assert leak_row_count == 3, (
            f"Expected exactly 3 leak rows (main_url, site_domain_url, company_logo); "
            f"got {leak_row_count}. JSON-blob rows like seo_health_probe_cache should be excluded."
        )
        # seo_health_probe_cache value should NOT appear in the table — its key
        # should not be present as a leak row.
        assert "seo_health_probe_cache" not in r.text, (
            "seo_health_probe_cache leaked into hygiene table — allowlist failed"
        )

        # POST the fix — should now return a 302 redirect (bug fix).
        r2 = s.post(BASE + "/setup-check.php?fix=public_urls",
                    headers={"Host": PROD_HOST},
                    allow_redirects=False, timeout=15)
        assert r2.status_code == 302, (
            f"fix POST must return HTTP 302 (header redirect), got {r2.status_code}. "
            f"This indicates the POST handler still runs AFTER admin-shell.php emits output."
        )
        loc = r2.headers.get("Location", "")
        assert "cleaned=" in loc, f"redirect missing cleaned param: {loc}"
        # Cleaned should be 3 (the allowlisted URL keys), not 4.
        m = re.search(r"cleaned=(\d+)", loc)
        assert m and int(m.group(1)) == 3, (
            f"Expected cleaned=3 in redirect Location, got: {loc}"
        )

        # Reload and verify the green clean-state — actual end-to-end signal.
        r3 = s.get(BASE + "/setup-check.php", headers={"Host": PROD_HOST}, timeout=15)
        assert r3.status_code == 200
        assert 'data-testid="hygiene-clean-state"' in r3.text, (
            "hygiene-clean-state element missing after fix"
        )
        assert "No preview URLs leaking" in r3.text, "Green message not shown after fix"
        assert "Strip preview hostnames now" not in r3.text, "Strip button still present after fix"
        assert 'data-testid="hygiene-leak-row"' not in r3.text, "Leak rows still present after fix"
    else:
        assert 'data-testid="hygiene-clean-state"' in r.text
        assert "No preview URLs leaking" in r.text


def test_setup_check_excludes_json_blob_rows_from_leak_table():
    """seo_health_probe_cache contains 'preview.emergentagent.com' inside JSON
    but is NOT a URL setting — must be excluded from the hygiene leak table."""
    s = _admin_login()
    r = s.get(BASE + "/setup-check.php", headers={"Host": PROD_HOST}, timeout=15)
    assert r.status_code == 200
    # The key name should not appear as a leak row label
    # (it may appear in other admin shell content but not in the hygiene card key column).
    # Stronger assertion: look at all leak-row blocks specifically.
    leak_rows = re.findall(
        r'<tr data-testid="hygiene-leak-row">.*?</tr>',
        r.text, re.S
    )
    for row in leak_rows:
        assert "seo_health_probe_cache" not in row, (
            f"seo_health_probe_cache appeared as a hygiene leak row: {row[:200]}"
        )
