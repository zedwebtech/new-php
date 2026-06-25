"""
Backend regression tests for the 4-phase ad-readiness sprint.
Tests Phase A (JSON-LD), Phase B (tracking pixels), Phase C (perf JS),
Phase D (About page Trust/FAQ), and the iteration_13 regression baseline.
"""
import json
import os
import re
import subprocess
import time
import pytest
import requests
from bs4 import BeautifulSoup

BASE_URL = "http://localhost:3000"
PRODUCT_SLUG = "microsoft-office-home-2024-pc"
TRACKING_KEYS = [
    "ga4_measurement_id",
    "google_ads_tag_id",
    "google_ads_purchase_label",
    "bing_uet_tag_id",
    "clarity_project_id",
]


def _mysql(sql: str) -> str:
    r = subprocess.run(
        ["mysql", "-uroot", "ucode_store", "-N", "-B", "-e", sql],
        capture_output=True, text=True, timeout=15,
    )
    return (r.stdout or "") + (r.stderr or "")


def _set_setting(key: str, value: str):
    val = value.replace("'", "''")
    _mysql(
        f"INSERT INTO settings (k,v) VALUES ('{key}','{val}') "
        f"ON DUPLICATE KEY UPDATE v=VALUES(v);"
    )


def _clear_trackers():
    for k in TRACKING_KEYS:
        _set_setting(k, "")


def _get_jsonld(html: str):
    soup = BeautifulSoup(html, "html.parser")
    blocks = []
    for s in soup.find_all("script", {"type": "application/ld+json"}):
        try:
            blocks.append(json.loads(s.string or ""))
        except Exception:
            pass
    return blocks


def _find_by_type(blocks, type_name):
    found = []
    for b in blocks:
        if isinstance(b, dict):
            if "@graph" in b:
                for g in b["@graph"]:
                    t = g.get("@type")
                    if t == type_name or (isinstance(t, list) and type_name in t):
                        found.append(g)
            else:
                t = b.get("@type")
                if t == type_name or (isinstance(t, list) and type_name in t):
                    found.append(b)
    return found


# ---------------- PHASE A ----------------
class TestPhaseAJsonLD:
    def test_organization_jsonld_required_fields(self):
        r = requests.get(BASE_URL + "/", timeout=20)
        assert r.status_code == 200
        orgs = _find_by_type(_get_jsonld(r.text), "Organization")
        assert orgs, "Organization @graph entry missing"
        org = orgs[0]
        for key in ("legalName", "address", "description", "knowsAbout",
                    "contactPoint", "sameAs"):
            assert key in org, f"Organization missing key: {key}"
        assert isinstance(org["legalName"], str) and org["legalName"]
        addr = org["address"]
        assert isinstance(addr, dict)
        assert "streetAddress" in addr and addr["streetAddress"]
        assert "addressCountry" in addr and addr["addressCountry"]

    def test_localbusiness_has_address(self):
        r = requests.get(BASE_URL + "/", timeout=20)
        lbs = _find_by_type(_get_jsonld(r.text), "LocalBusiness")
        assert lbs, "LocalBusiness entry missing"
        assert "address" in lbs[0]

    def test_product_pricevaliduntil_default_is_date(self):
        r = requests.get(f"{BASE_URL}/product.php?slug={PRODUCT_SLUG}", timeout=20)
        prods = _find_by_type(_get_jsonld(r.text), "Product")
        assert prods, "Product JSON-LD missing"
        offers = prods[0].get("offers")
        if isinstance(offers, list):
            offers = offers[0]
        pvu = offers.get("priceValidUntil")
        assert pvu and re.match(r"^\d{4}-\d{2}-\d{2}$", pvu), f"priceValidUntil bad: {pvu}"

    def test_product_pricevaliduntil_from_sale_window(self):
        try:
            _mysql(
                "UPDATE products SET sale_ends_at='2026-08-15 23:59:00' "
                f"WHERE slug='{PRODUCT_SLUG}';"
            )
            time.sleep(0.5)
            r = requests.get(f"{BASE_URL}/product.php?slug={PRODUCT_SLUG}", timeout=20)
            prods = _find_by_type(_get_jsonld(r.text), "Product")
            offers = prods[0]["offers"]
            if isinstance(offers, list):
                offers = offers[0]
            assert offers.get("priceValidUntil") == "2026-08-15", (
                f"expected 2026-08-15, got {offers.get('priceValidUntil')}"
            )
        finally:
            _mysql(f"UPDATE products SET sale_ends_at=NULL WHERE slug='{PRODUCT_SLUG}';")

    def test_tax_transparency_line_under_price(self):
        r = requests.get(f"{BASE_URL}/product.php?slug={PRODUCT_SLUG}", timeout=20)
        soup = BeautifulSoup(r.text, "html.parser")
        tax = soup.select_one("[data-testid='price-tax-line']")
        assert tax is not None, "price-tax-line missing"
        assert "sales tax / vat is calculated at checkout" in tax.get_text(" ", strip=True).lower(), \
            f"tax line text wrong: {tax.get_text(strip=True)!r}"
        price_el = soup.select_one("[data-testid='product-price']")
        assert price_el is not None
        # DOM order — price testid appears before tax-line testid
        html = str(soup)
        price_pos = html.find('data-testid="product-price"')
        tax_pos = html.find('data-testid="price-tax-line"')
        assert price_pos != -1 and tax_pos != -1 and price_pos < tax_pos, \
               f"price ({price_pos}) must appear before tax-line ({tax_pos}) in DOM"


# ---------------- PHASE B ----------------
class TestPhaseBTracking:
    @classmethod
    def setup_class(cls):
        _clear_trackers()

    @classmethod
    def teardown_class(cls):
        _clear_trackers()

    def test_dormant_no_pixels(self):
        _clear_trackers()
        r = requests.get(BASE_URL + "/", timeout=20)
        body = r.text
        for marker in ("googletagmanager.com", "bat.bing.com/bat.js", "clarity.ms/tag/"):
            assert marker not in body, f"unexpected tracker fragment: {marker}"

    @pytest.mark.parametrize("key,value,markers", [
        ("ga4_measurement_id", "G-TEST123ABC",
         ["googletagmanager.com/gtag/js?id=G-TEST123ABC"]),
        ("google_ads_tag_id", "AW-9999999999",
         ["googletagmanager.com/gtag/js?id=AW-9999999999"]),
        ("bing_uet_tag_id", "87654321",
         ["bat.bing.com/bat.js", 'ti:"87654321"']),
        ("clarity_project_id", "clarTest01",
         # snippet uses runtime concat: src = "https://www.clarity.ms/tag/" + i,
         # so we assert both fragments are present.
         ["clarity.ms/tag/", "clarTest01"]),
    ])
    def test_tracker_activates(self, key, value, markers):
        _clear_trackers()
        _set_setting(key, value)
        try:
            r = requests.get(BASE_URL + "/", timeout=20)
            for m in markers:
                assert m in r.text, f"{key}={value} did not emit marker {m!r}"
        finally:
            _set_setting(key, "")

    def test_view_item_event_on_product(self):
        _set_setting("ga4_measurement_id", "G-TEST123ABC")
        try:
            r = requests.get(f"{BASE_URL}/product.php?slug={PRODUCT_SLUG}", timeout=20)
            assert "gtag('event', 'view_item'" in r.text or 'gtag("event", "view_item"' in r.text
            assert PRODUCT_SLUG in r.text
        finally:
            _set_setting("ga4_measurement_id", "")

    def test_bing_uet_view_item_on_product(self):
        _set_setting("bing_uet_tag_id", "87654321")
        try:
            r = requests.get(f"{BASE_URL}/product.php?slug={PRODUCT_SLUG}", timeout=20)
            assert "uetq" in r.text and "product_view" in r.text, \
                "Bing UET product_view event not emitted"
        finally:
            _set_setting("bing_uet_tag_id", "")


# ---------------- PHASE C ----------------
class TestPhaseCPerformance:
    def test_deferred_scripts(self):
        r = requests.get(BASE_URL + "/", timeout=20)
        body = r.text
        assert 'defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"' in body \
            or 'defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js?' in body \
            or re.search(r'<script\s+defer\s+src="https://cdn\.jsdelivr\.net/npm/bootstrap@5\.3\.3/dist/js/bootstrap\.bundle\.min\.js', body), \
            "bootstrap bundle not deferred"
        assert re.search(r'<script\s+defer\s+src="assets/js/main\.js', body), \
            "assets/js/main.js not deferred"

    def test_lazyload_runtime_script_present(self):
        r = requests.get(BASE_URL + "/", timeout=20)
        assert "img.loading = 'lazy'" in r.text or 'img.loading = "lazy"' in r.text, \
            "lazy-load runtime script missing"


# ---------------- PHASE D ----------------
class TestPhaseDAbout:
    def test_about_trust_and_faq_blocks(self):
        r = requests.get(BASE_URL + "/about-us.php", timeout=20)
        assert r.status_code == 200
        soup = BeautifulSoup(r.text, "html.parser")
        trust = soup.select_one("[data-testid='about-trust-compliance']")
        assert trust is not None
        grid = trust.select_one("[data-testid='trust-grid']")
        assert grid is not None
        rows = grid.select("[data-testid='trust-row']")
        assert len(rows) == 6, f"expected 6 trust rows, got {len(rows)}"
        for row in rows:
            # row itself is the <a> tag in the current template
            assert row.name == "a" or row.find("a") is not None, "each trust row must link to a policy"
            href = row.get("href") if row.name == "a" else row.find("a").get("href")
            assert href, "trust row link must have href"

        faq = soup.select_one("[data-testid='about-faq']")
        assert faq is not None
        acc = faq.select_one("[data-testid='about-faq-accordion']")
        assert acc is not None
        qs = acc.select("[data-testid^='faq-q-']")
        assert len(qs) == 5, f"expected 5 FAQ questions, got {len(qs)}"
        first = acc.select_one("[data-testid='faq-q-0']")
        assert first is not None
        # Bootstrap accordion: expanded button has aria-expanded="true"
        assert first.get("aria-expanded") == "true", "first FAQ should be expanded by default"

    def test_about_faqpage_jsonld_and_dates(self):
        r = requests.get(BASE_URL + "/about-us.php", timeout=20)
        blocks = _get_jsonld(r.text)
        faqs = _find_by_type(blocks, "FAQPage")
        assert faqs, "FAQPage JSON-LD missing"
        main = faqs[0].get("mainEntity")
        assert isinstance(main, list) and len(main) == 5, \
            f"FAQPage mainEntity should have 5 entries, got {len(main) if isinstance(main, list) else 'N/A'}"
        for q in main:
            assert q.get("@type") == "Question"
            assert isinstance(q.get("acceptedAnswer"), dict)
            assert q["acceptedAnswer"].get("@type") == "Answer"

        abouts = _find_by_type(blocks, "AboutPage")
        assert abouts, "AboutPage JSON-LD missing"
        assert "datePublished" in abouts[0]
        assert "dateModified" in abouts[0]


# ---------------- BUG 1 RETEST — sameAs always emitted ----------------
class TestBug1SameAsFallback:
    """Iteration 14 HIGH bug: Organization JSON-LD `sameAs` was stripped
    when no social URLs were seeded. Header.php now falls back to the
    about-us canonical URL when ALL social fields are empty."""

    def _social_keys(self):
        return ["twitter", "facebook", "linkedin", "instagram"]

    def _snapshot_socials(self):
        out = {}
        for k in self._social_keys():
            r = subprocess.run(
                ["mysql", "-uroot", "ucode_store", "-N", "-B", "-e",
                 f"SELECT v FROM settings WHERE k='{k}'"],
                capture_output=True, text=True, timeout=10,
            )
            out[k] = (r.stdout or "").strip()
        return out

    def _clear_socials(self):
        for k in self._social_keys():
            _mysql(f"DELETE FROM settings WHERE k='{k}';")

    def _restore_socials(self, snap):
        for k, v in snap.items():
            if v:
                _set_setting(k, v)
            else:
                _mysql(f"DELETE FROM settings WHERE k='{k}';")

    def test_sameAs_fallback_when_no_socials(self):
        snap = self._snapshot_socials()
        try:
            self._clear_socials()
            r = requests.get(BASE_URL + "/", timeout=20)
            assert r.status_code == 200
            orgs = _find_by_type(_get_jsonld(r.text), "Organization")
            assert orgs, "Organization @graph entry missing"
            org = orgs[0]
            assert "sameAs" in org, \
                "sameAs MUST be present even when no social URLs are seeded"
            same = org["sameAs"]
            assert isinstance(same, list) and len(same) >= 1, \
                f"sameAs must be a non-empty list, got: {same!r}"
            # Fallback expected: about-us.php on this host
            assert any("/about-us.php" in u for u in same), \
                f"expected /about-us.php fallback in sameAs, got: {same!r}"
        finally:
            self._restore_socials(snap)

    def test_sameAs_uses_real_socials_when_seeded(self):
        snap = self._snapshot_socials()
        try:
            self._clear_socials()
            twitter_url = "https://twitter.com/maventechsoftware"
            _set_setting("twitter", twitter_url)
            r = requests.get(BASE_URL + "/", timeout=20)
            assert r.status_code == 200
            orgs = _find_by_type(_get_jsonld(r.text), "Organization")
            assert orgs, "Organization missing"
            same = orgs[0].get("sameAs")
            assert isinstance(same, list) and same, f"sameAs missing/empty: {same!r}"
            assert twitter_url in same, \
                f"seeded twitter URL not present in sameAs: {same!r}"
            # Fallback must NOT be used when a real social URL is present
            assert not any("/about-us.php" in u for u in same), \
                f"about-us fallback must not appear when twitter is seeded: {same!r}"
        finally:
            self._restore_socials(snap)


# ---------------- BUG 2 RETEST — save_tracking_ids preserves valid values ----------------
class TestBug2TrackingPreservation:
    """Iteration 14 HIGH bug: invalid IDs overwrote previously valid values.
    Main agent fixed admin.php save_tracking_ids — validates BEFORE setting_set
    and `continue`s on regex failure. This test uses a real authenticated
    PHP session (cookie jar) and asserts DB state directly."""

    ADMIN_EMAIL = "admin@maventechsoftware.com"
    ADMIN_PASSWORD = "Admin@UC2026!"

    def _login(self):
        s = requests.Session()
        # GET first to set the PHPSESSID cookie if needed
        s.get(BASE_URL + "/login.php", timeout=15)
        r = s.post(
            BASE_URL + "/login.php",
            data={"email": self.ADMIN_EMAIL, "password": self.ADMIN_PASSWORD},
            allow_redirects=False, timeout=15,
        )
        assert r.status_code in (301, 302, 303), \
            f"login did not redirect — status={r.status_code}, body[:200]={r.text[:200]!r}"
        loc = r.headers.get("Location", "")
        assert "admin.php" in loc, f"expected admin.php redirect, got: {loc!r}"
        # Verify authenticated by hitting admin.php
        r2 = s.get(BASE_URL + "/admin.php?tab=company", timeout=15)
        assert r2.status_code == 200
        assert "login.php" not in r2.url, "session lost after login"
        return s

    def test_invalid_input_preserves_valid_values(self):
        valid = {
            "ga4_measurement_id":        "G-TEST123ABC",
            "bing_uet_tag_id":           "12345678",
            "google_ads_tag_id":         "AW-9999999999",
            "google_ads_purchase_label": "aBcDeFgH123",
            "clarity_project_id":        "clartestxx",
        }
        try:
            # Seed all valid values
            for k, v in valid.items():
                _set_setting(k, v)

            session = self._login()

            # POST invalid for ga4 + uet; valid for the other three
            post_data = {
                "action":                    "save_tracking_ids",
                "ga4_measurement_id":        "not-a-tag",
                "bing_uet_tag_id":           "abc",
                "google_ads_tag_id":         valid["google_ads_tag_id"],
                "google_ads_purchase_label": valid["google_ads_purchase_label"],
                "clarity_project_id":        valid["clarity_project_id"],
            }
            r = session.post(
                BASE_URL + "/admin.php?tab=company",
                data=post_data, allow_redirects=False, timeout=15,
            )
            assert r.status_code in (301, 302, 303), \
                f"expected redirect, got {r.status_code}"
            loc = r.headers.get("Location", "")
            assert "tracking_msg=" in loc, f"flash redirect missing: {loc!r}"

            # Flash text on the redirected page
            r2 = session.get(BASE_URL + "/" + loc.lstrip("/"), timeout=15)
            assert r2.status_code == 200
            soup = BeautifulSoup(r2.text, "html.parser")
            flash = soup.select_one("[data-testid='tracking-flash']")
            assert flash is not None, "tracking-flash banner not rendered"
            txt = flash.get_text(" ", strip=True).lower()
            assert "invalid id(s) ignored" in txt, \
                f"flash text does not mention 'invalid ID(s) ignored': {txt!r}"
            for key in ("ga4_measurement_id", "bing_uet_tag_id"):
                assert key in txt, f"flash should list {key} as rejected; got: {txt!r}"

            # Directly inspect DB — most authoritative assertion
            out = _mysql(
                "SELECT k,v FROM settings WHERE k IN "
                "('ga4_measurement_id','bing_uet_tag_id','google_ads_tag_id',"
                "'google_ads_purchase_label','clarity_project_id');"
            )
            db_map = {}
            for line in out.strip().splitlines():
                parts = line.split("\t")
                if len(parts) >= 2:
                    db_map[parts[0]] = parts[1]
            assert db_map.get("ga4_measurement_id") == valid["ga4_measurement_id"], (
                f"ga4 was OVERWRITTEN by invalid input — got {db_map.get('ga4_measurement_id')!r}, "
                f"expected {valid['ga4_measurement_id']!r}"
            )
            assert db_map.get("bing_uet_tag_id") == valid["bing_uet_tag_id"], (
                f"uet was OVERWRITTEN by invalid input — got {db_map.get('bing_uet_tag_id')!r}, "
                f"expected {valid['bing_uet_tag_id']!r}"
            )
            # Sanity: the 3 valid values that were re-submitted unchanged
            for k in ("google_ads_tag_id", "google_ads_purchase_label", "clarity_project_id"):
                assert db_map.get(k) == valid[k], f"{k} unexpectedly changed: {db_map.get(k)!r}"
        finally:
            _clear_trackers()


# ---------------- REGRESSION ----------------
class TestRegressionFeed:
    def test_merchant_feed_counts(self):
        r = requests.get(BASE_URL + "/merchant-feed.xml", timeout=30)
        assert r.status_code == 200
        body = r.text
        assert body.count("<g:product_highlight>") == 148, \
            f"product_highlight count = {body.count('<g:product_highlight>')}"
        assert body.count("<g:sale_price_effective_date>") == 37, \
            f"sale_price_effective_date count = {body.count('<g:sale_price_effective_date>')}"
        assert body.count("<g:gtin>") == 37, \
            f"gtin count = {body.count('<g:gtin>')}"
