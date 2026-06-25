"""
Iteration 19 — Tests for:
  (1) Enhanced Conversions: hashed sha256 email/phone payload on /order-success.php
  (2) Google Customer Reviews opt-in (gapi.surveyoptin.render) gated on GMC + paid order
  (3) MerchantPromotion via <g:promotion_id>MAVEN20</g:promotion_id> on Google + Bing feed
  (4) <g:product_detail> attribute pairs (4 per item × 37 = 148) on Google + Bing feed
  (5) Admin GMC input wired into save_tracking_ids (regex ^[0-9]{6,15}$)
"""
import os
import re
import hashlib
import subprocess
import pytest
import requests

ORIGIN = "http://localhost:3000"  # internal origin (kubernetes ingress can mask cache headers; not relevant here)
PUBLIC = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/")
ADMIN_EMAIL = "admin@maventechsoftware.com"
ADMIN_PASS = "Admin@UC2026!"
PAID_ORDER = "MVT-DEMO-003"
PAID_EMAIL = "priya.demo@example.in"
PAID_PHONE = "+91 98765 43210"


def _mysql(sql: str) -> str:
    p = subprocess.run(["mysql", "-uroot", "-N", "-B", "ucode_store", "-e", sql],
                       capture_output=True, text=True, timeout=15)
    return (p.stdout or "").strip()


def _setting_set(k: str, v: str):
    safe = v.replace("'", "''")
    _mysql(f"INSERT INTO settings (k,v) VALUES ('{k}','{safe}') "
           f"ON DUPLICATE KEY UPDATE v='{safe}';")


def _setting_get(k: str) -> str:
    return _mysql(f"SELECT v FROM settings WHERE k='{k}';")


# ---------- fixtures ----------
@pytest.fixture(scope="module")
def saved_settings():
    keys = ["ga4_measurement_id", "google_ads_tag_id", "google_ads_purchase_label",
            "bing_uet_tag_id", "clarity_project_id", "google_merchant_id"]
    snap = {k: _setting_get(k) for k in keys}
    yield
    # restore
    for k, v in snap.items():
        _setting_set(k, v)


@pytest.fixture
def order_success_url():
    def _u(order):
        return f"{ORIGIN}/order-success.php?order={order}"
    return _u


def _get(url):
    return requests.get(url, timeout=15, allow_redirects=False)


# ============================================================
# (1) Enhanced Conversions — hashed payload
# ============================================================
class TestEnhancedConversions:
    def test_hashed_user_data_block_present_when_ga4_set(self, saved_settings, order_success_url):
        _setting_set("ga4_measurement_id", "G-TESTABC123")
        _setting_set("google_merchant_id", "")
        html = _get(order_success_url(PAID_ORDER)).text

        # Compute the expected hashes
        exp_email = hashlib.sha256(PAID_EMAIL.strip().lower().encode()).hexdigest()
        # phone normalised to E.164-ish (digits only, leading +)
        digits = re.sub(r"[^\d+]", "", PAID_PHONE)
        if digits and digits[0] != "+":
            digits = "+" + digits.lstrip("0")
        exp_phone = hashlib.sha256(digits.encode()).hexdigest()

        assert "gtag('set', 'user_data'" in html, "user_data block missing"
        assert exp_email in html, f"sha256 email hash not emitted (expected {exp_email})"
        assert exp_phone in html, f"sha256 phone hash not emitted (expected {exp_phone})"
        # both must be 64-hex lowercase
        assert re.search(r'sha256_email_address:\s*"[0-9a-f]{64}"', html)
        assert re.search(r'sha256_phone_number:\s*"[0-9a-f]{64}"', html)

        # ordering: user_data BEFORE purchase event
        ud_idx = html.index("gtag('set', 'user_data'")
        pe_idx = html.index("gtag('event', 'purchase'")
        assert ud_idx < pe_idx, "user_data must precede purchase event"

    def test_user_data_skipped_when_all_ids_blank(self, saved_settings, order_success_url):
        for k in ("ga4_measurement_id", "google_ads_tag_id", "google_ads_purchase_label",
                  "bing_uet_tag_id", "clarity_project_id"):
            _setting_set(k, "")
        html = _get(order_success_url(PAID_ORDER)).text
        assert "gtag('set', 'user_data'" not in html, "user_data block must NOT render with empty IDs"
        assert "gtag('event', 'purchase'" not in html, "purchase event must NOT render with empty IDs"

    def test_missing_phone_only_email_emitted(self, saved_settings, order_success_url):
        _setting_set("ga4_measurement_id", "G-TESTABC123")
        # find a paid order; clear its phone temporarily
        original_phone = _mysql(f"SELECT phone FROM orders WHERE order_number='{PAID_ORDER}';")
        try:
            _mysql(f"UPDATE orders SET phone='' WHERE order_number='{PAID_ORDER}';")
            html = _get(order_success_url(PAID_ORDER)).text
            assert "gtag('set', 'user_data'" in html
            assert "sha256_email_address" in html
            assert "sha256_phone_number" not in html, "phone hash must NOT appear when phone empty"
            # no empty-string hash
            assert not re.search(r'sha256_email_address:\s*""', html)
        finally:
            safe = (original_phone or "").replace("'", "''")
            _mysql(f"UPDATE orders SET phone='{safe}' WHERE order_number='{PAID_ORDER}';")

    def test_no_user_data_when_both_email_and_phone_empty(self, saved_settings, order_success_url):
        _setting_set("ga4_measurement_id", "G-TESTABC123")
        orig_phone = _mysql(f"SELECT phone FROM orders WHERE order_number='{PAID_ORDER}';")
        orig_email = _mysql(f"SELECT email FROM orders WHERE order_number='{PAID_ORDER}';")
        try:
            # email is NOT NULL — use a single space; the trim() in PHP will normalise to ''
            _mysql(f"UPDATE orders SET phone='', email=' ' WHERE order_number='{PAID_ORDER}';")
            html = _get(order_success_url(PAID_ORDER)).text
            assert "gtag('set', 'user_data'" not in html, \
                "user_data block must be skipped when both email AND phone are empty"
        finally:
            safe_p = (orig_phone or "").replace("'", "''")
            safe_e = (orig_email or "").replace("'", "''")
            _mysql(f"UPDATE orders SET phone='{safe_p}', email='{safe_e}' WHERE order_number='{PAID_ORDER}';")


# ============================================================
# (2) Google Customer Reviews opt-in
# ============================================================
class TestCustomerReviewsOptIn:
    def test_optin_present_with_gmc_and_paid(self, saved_settings, order_success_url):
        _setting_set("google_merchant_id", "12345678")
        html = _get(order_success_url(PAID_ORDER)).text
        assert 'apis.google.com/js/platform.js?onload=renderOptIn' in html
        assert 'gapi.surveyoptin.render(' in html
        assert '"merchant_id":' in html and '"12345678"' in html
        assert PAID_EMAIL in html
        assert PAID_ORDER in html
        # delivery_country
        assert '"delivery_country":' in html
        # YYYY-MM-DD estimated delivery date
        assert re.search(r'"estimated_delivery_date":\s*"\d{4}-\d{2}-\d{2}"', html)

    def test_optin_absent_when_gmc_empty(self, saved_settings, order_success_url):
        _setting_set("google_merchant_id", "")
        html = _get(order_success_url(PAID_ORDER)).text
        assert 'platform.js?onload=renderOptIn' not in html
        assert 'gapi.surveyoptin.render' not in html

    def test_optin_absent_for_demo_order(self, saved_settings, order_success_url):
        _setting_set("google_merchant_id", "12345678")
        # demo mode: open without ?order (renders the demo/sample order)
        html = _get(f"{ORIGIN}/order-success.php").text
        assert 'gapi.surveyoptin.render' not in html, "opt-in must NOT render in demo mode"


# ============================================================
# (3) & (4) Feed: g:promotion_id + g:product_detail
# ============================================================
@pytest.fixture(scope="module")
def google_feed():
    return _get(f"{ORIGIN}/feed/google-products.xml").text


@pytest.fixture(scope="module")
def bing_feed():
    return _get(f"{ORIGIN}/feed/bing-shopping.xml").text


class TestMerchantPromotion:
    def test_promotion_id_count_37_google(self, google_feed):
        count = google_feed.count("<g:promotion_id>MAVEN20</g:promotion_id>")
        assert count == 37, f"Expected 37 promotion_id, got {count}"

    def test_promotion_id_count_37_bing(self, bing_feed):
        count = bing_feed.count("<g:promotion_id>MAVEN20</g:promotion_id>")
        assert count == 37, f"Expected 37 promotion_id on Bing, got {count}"

    def test_promotion_id_dropped_when_no_sale(self, google_feed):
        # feed treats $hasSale = (original_price > price). Clear it by setting original_price=price.
        row = _mysql(
            "SELECT id, original_price, price FROM products "
            "WHERE original_price > price ORDER BY id ASC LIMIT 1;"
        )
        assert row, "Need at least one on-sale product"
        pid, orig, price = row.split("\t")
        try:
            _mysql(f"UPDATE products SET original_price=price, sale_starts_at=NULL, "
                   f"sale_ends_at=NULL WHERE id={pid};")
            feed = _get(f"{ORIGIN}/feed/google-products.xml").text
            count = feed.count("<g:promotion_id>MAVEN20</g:promotion_id>")
            assert count == 36, f"Expected 36 promotion_id after clearing sale on 1 product, got {count}"
        finally:
            _mysql(f"UPDATE products SET original_price={orig} WHERE id={pid};")
            verify = _get(f"{ORIGIN}/feed/google-products.xml").text
            assert verify.count("<g:promotion_id>MAVEN20</g:promotion_id>") == 37


class TestProductDetail:
    def test_product_detail_opening_count_148_google(self, google_feed):
        cnt = google_feed.count("<g:product_detail>")
        assert cnt == 148, f"Expected 148 g:product_detail (4*37) on Google, got {cnt}"

    def test_product_detail_opening_count_148_bing(self, bing_feed):
        cnt = bing_feed.count("<g:product_detail>")
        assert cnt == 148, f"Expected 148 g:product_detail (4*37) on Bing, got {cnt}"

    def test_each_block_has_three_ordered_children(self, google_feed):
        # Verify the structure of every g:product_detail
        # Pattern: <g:product_detail>\n<sect>...<g:section_name>X</g:section_name>\n
        #           <g:attribute_name>Y</g:attribute_name>\n<g:attribute_value>Z</g:attribute_value>\n</g:product_detail>
        pattern = re.compile(
            r"<g:product_detail>\s*"
            r"<g:section_name>([^<]+)</g:section_name>\s*"
            r"<g:attribute_name>([^<]+)</g:attribute_name>\s*"
            r"<g:attribute_value>([^<]+)</g:attribute_value>\s*"
            r"</g:product_detail>",
            re.MULTILINE
        )
        matches = pattern.findall(google_feed)
        assert len(matches) == 148, f"Structured parse only matched {len(matches)} of 148 blocks"

        allowed_sections = {"Compatibility", "Licensing", "Delivery"}
        allowed_attrs = {"Operating System", "License Type", "Number of Devices", "Activation Method"}
        for sect, aname, aval in matches:
            assert sect in allowed_sections, f"unexpected section: {sect}"
            assert aname in allowed_attrs, f"unexpected attribute: {aname}"
            assert aval.strip() != ""

    def test_attribute_values_match_db(self, google_feed):
        # Pick 3 random products and verify OS/License Type from DB
        rows = _mysql("SELECT id, platform, license_type FROM products ORDER BY RAND() LIMIT 3;")
        for line in rows.splitlines():
            pid, platform, ltype = line.split("\t")
            # locate this product's <item> by numeric g:id
            m = re.search(rf"<g:id>{re.escape(pid)}</g:id>(.*?)</item>", google_feed, re.DOTALL)
            assert m, f"item for id={pid} not in feed"
            block = m.group(1)
            # OS
            os_match = re.search(
                r"<g:section_name>Compatibility</g:section_name>\s*"
                r"<g:attribute_name>Operating System</g:attribute_name>\s*"
                r"<g:attribute_value>([^<]+)</g:attribute_value>",
                block
            )
            assert os_match, f"OS pair missing for id={pid}"
            assert os_match.group(1).strip() == (platform or "Windows"), \
                f"OS mismatch for id={pid}: feed={os_match.group(1)} db={platform}"
            # License Type — ucwords(lower)
            exp_lt = " ".join(w.capitalize() for w in (ltype or "Lifetime").lower().split())
            lt_match = re.search(
                r"<g:section_name>Licensing</g:section_name>\s*"
                r"<g:attribute_name>License Type</g:attribute_name>\s*"
                r"<g:attribute_value>([^<]+)</g:attribute_value>",
                block
            )
            assert lt_match, f"License Type pair missing for id={pid}"
            assert lt_match.group(1).strip() == exp_lt, \
                f"License Type mismatch for id={pid}: feed={lt_match.group(1)} expected={exp_lt}"
            # Number of Devices constant
            assert "<g:attribute_value>1 device</g:attribute_value>" in block
            # Activation method constant
            assert "Digital download" in block


# ============================================================
# (5) Admin GMC input + persistence + validation
# ============================================================
@pytest.fixture
def admin_session():
    s = requests.Session()
    s.headers.update({"User-Agent": "iter19-test"})
    r = s.post(f"{ORIGIN}/login.php",
               data={"email": ADMIN_EMAIL, "password": ADMIN_PASS},
               allow_redirects=True, timeout=15)
    assert r.status_code == 200, f"admin login failed: {r.status_code}"
    return s


class TestAdminGMCInput:
    def test_form_field_present(self, admin_session):
        r = admin_session.get(f"{ORIGIN}/admin.php?tab=company", timeout=15)
        html = r.text
        assert 'data-testid="tk-gmc-input"' in html
        assert 'name="google_merchant_id"' in html
        assert 'pattern="^[0-9]{6,15}$"' in html
        assert 'merchants.google.com' in html
        assert 'Verified by Google Customers' in html

    def test_save_valid_gmc_persists(self, admin_session, saved_settings):
        # ensure other tracking fields preserved
        _setting_set("ga4_measurement_id", "G-KEEPME12")
        r = admin_session.post(f"{ORIGIN}/admin.php",
                               data={
                                   "action": "save_tracking_ids",
                                   "google_merchant_id": "12345678",
                                   "ga4_measurement_id": "G-KEEPME12",
                                   "google_ads_tag_id": "",
                                   "google_ads_purchase_label": "",
                                   "bing_uet_tag_id": "",
                                   "clarity_project_id": "",
                               },
                               allow_redirects=False, timeout=15)
        assert r.status_code in (301, 302, 303)
        assert _setting_get("google_merchant_id") == "12345678"
        assert _setting_get("ga4_measurement_id") == "G-KEEPME12"

    def test_save_invalid_gmc_rejected_preserves_old(self, admin_session, saved_settings):
        _setting_set("google_merchant_id", "12345678")
        r = admin_session.post(f"{ORIGIN}/admin.php",
                               data={
                                   "action": "save_tracking_ids",
                                   "google_merchant_id": "abcdef",
                                   "ga4_measurement_id": "",
                                   "google_ads_tag_id": "",
                                   "google_ads_purchase_label": "",
                                   "bing_uet_tag_id": "",
                                   "clarity_project_id": "",
                               },
                               allow_redirects=False, timeout=15)
        assert r.status_code in (301, 302, 303)
        # invalid value rejected: old preserved
        assert _setting_get("google_merchant_id") == "12345678"
        # flash banner should list google_merchant_id as invalid (in redirect Location)
        loc = r.headers.get("Location", "")
        assert "google_merchant_id" in loc, f"flash banner missing google_merchant_id key: {loc}"


# ============================================================
# Regression: iter-18 still holds
# ============================================================
class TestRegression:
    FEEDS = [
        "/merchant-feed.xml", "/feed/google-products.xml", "/feeds/google-products.xml",
        "/google-merchant-feed.xml", "/google-shopping-feed.xml",
        "/feed/bing-shopping.xml", "/feeds/bing-shopping.xml",
        "/bing-shopping-feed.xml", "/microsoft-merchant-feed.xml",
    ]

    @pytest.mark.parametrize("path", FEEDS)
    def test_all_feeds_200(self, path):
        r = _get(f"{ORIGIN}{path}")
        assert r.status_code == 200, f"{path} returned {r.status_code}"

    def test_sitemap_xslt(self):
        r = _get(f"{ORIGIN}/sitemap.xml")
        assert r.status_code == 200
        assert "<?xml-stylesheet" in r.text and "sitemap.xsl" in r.text

    def test_product_highlight_148(self, google_feed):
        assert google_feed.count("<g:product_highlight>") == 148

    def test_sale_price_effective_date_37(self, google_feed):
        assert google_feed.count("<g:sale_price_effective_date>") == 37

    def test_return_policy_37(self, google_feed):
        assert google_feed.count("<g:return_policy>") == 37

    def test_free_shipping_threshold_37(self, google_feed):
        assert google_feed.count("<g:free_shipping_threshold>") == 37

    def test_atom_self_href_echoes_alias(self):
        for path in self.FEEDS:
            r = _get(f"{ORIGIN}{path}")
            assert path in r.text, f"atom:link self-href doesn't echo alias for {path}"
