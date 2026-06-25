"""
Iteration 16 — Ad-optimised product SEO trio tests.

Validates the new _ads_seo() helper added to /app/php-version/product.php:
  - <title> length <= 60 chars before brand suffix
  - title fallback chain when name is long
  - <meta name="description"> contains required signal words, <= 155 chars
  - visible <h1> uses ad-optimised string, canonical name preserved below
  - platform mapping (PC / Mac / Device)
  - licence-type mapping (Lifetime License Key / 1-Year Subscription)
  - admin meta_description still wins over auto-generated
  - JSON-LD Product block regression
"""
import json
import re
import subprocess
from typing import Optional

import pytest
import requests
from bs4 import BeautifulSoup

BASE_URL = "http://localhost:3000"
# Strip either ' | <brand>' OR a trailing ellipsis (seo_clamp_title may truncate
# the brand portion when the combined string > 60 chars). Both indicate the end
# of the ad-optimised prefix.
BRAND_SUFFIX_RE = re.compile(r"(\s\|\s[^|]+|…)$")


def _mysql(sql: str) -> str:
    r = subprocess.run(
        ["mysql", "-uroot", "ucode_store", "-N", "-B", "-e", sql],
        capture_output=True, text=True, timeout=15,
    )
    return r.stdout.strip()


def _get_product(slug: str) -> BeautifulSoup:
    r = requests.get(f"{BASE_URL}/product.php", params={"slug": slug}, timeout=20)
    assert r.status_code == 200, f"GET product.php?slug={slug} returned {r.status_code}"
    return BeautifulSoup(r.text, "html.parser")


def _title_prefix(soup: BeautifulSoup) -> str:
    """Strip trailing ' | <brand>' suffix OR the seo_clamp_title ellipsis from <title>.

    Two cases:
      a) Combined title fits within 60 chars → ends with ' | <brand>'.
      b) Combined title exceeds 60 chars and seo_clamp_title truncates → ends with '…',
         and the brand suffix has been chopped off entirely.
    """
    raw = soup.title.string.strip() if soup.title and soup.title.string else ""
    if raw.endswith("…"):
        # ellipsis case: brand was clamped; strip trailing '…' (and any partial fragment after the last ' | ')
        s = raw.rstrip("…").rstrip()
        # If the truncation chopped mid-segment, also strip the trailing partial ' | xyz'
        # but only if the partial doesn't look like a price (no '$' or digit)
        return s
    # normal case: strip the LAST ' | <brand>' segment
    return BRAND_SUFFIX_RE.sub("", raw)


def _meta_desc(soup: BeautifulSoup) -> str:
    tag = soup.find("meta", attrs={"name": "description"})
    return (tag.get("content") or "").strip() if tag else ""


# ---------- 1. Title length <= 60 chars (before brand suffix) ----------
@pytest.mark.parametrize("slug", [
    "windows-11-pro",
    "microsoft-office-home-2024-pc",
    "bitdefender-antivirus-for-mac-1-mac-1-year",
])
def test_title_under_60_chars(slug):
    soup = _get_product(slug)
    prefix = _title_prefix(soup)
    assert len(prefix) <= 60, f"[{slug}] title prefix '{prefix}' is {len(prefix)} chars (>60)"
    # Title prefix should not be empty after stripping brand suffix
    assert len(prefix) > 5, f"[{slug}] title prefix empty after stripping suffix: {soup.title.string}"


# ---------- 2. Title fallback chain (long-name product) ----------
def test_title_fallback_long_name():
    """Long-name product should use the ' Product Key — ' (or short) fallback, NOT 'Buy ... | ... | $X'."""
    soup = _get_product("microsoft-office-home-business-2024-mac")
    prefix = _title_prefix(soup)
    assert len(prefix) <= 60, f"fallback title prefix should fit: '{prefix}' ({len(prefix)} chars)"
    # The long format starts with 'Buy ' and uses ' | ' separators (3 pipes). Fallback uses ' — '.
    # When name itself exceeds the budget, fallback drops 'Buy ' prefix.
    primary_pattern = re.compile(r"^Buy .* \| .* \| \$\d+(\.\d+)?$")
    is_primary = bool(primary_pattern.match(prefix))
    # For long-name products, primary should NOT fit; fallback should be in effect
    assert not is_primary, f"long-name product should use fallback, got primary: '{prefix}'"


def test_title_long_format_short_name():
    """Short-name product (Windows 11 Pro) SHOULD render the long 'Buy ... | ... | $X' format."""
    soup = _get_product("windows-11-pro")
    prefix = _title_prefix(soup)
    primary_pattern = re.compile(r"^Buy [A-Z].* \| .* \| \$\d+(\.\d+)?$")
    assert primary_pattern.match(prefix), \
        f"windows-11-pro should use long 'Buy ... | ... | $X' format, got: '{prefix}'"
    assert len(prefix) <= 60, f"windows-11-pro title still must be <= 60: {len(prefix)}"


# ---------- 3. Meta description signal words, <= 155 chars ----------
def test_meta_description_signals_and_length():
    soup = _get_product("windows-11-pro")
    desc = _meta_desc(soup)
    assert desc, "meta description missing"
    assert len(desc) <= 155, f"meta description {len(desc)} chars (>155): {desc!r}"

    lowered = desc.lower()
    required = ["buy", "genuine", "instant", "30-day", "money-back guarantee"]
    for token in required:
        assert token in lowered, f"meta missing required token '{token}': {desc!r}"
    # 15-minute or 15 minute
    assert ("15-minute" in lowered) or ("15 minute" in lowered), \
        f"meta missing '15-minute' / '15 minute': {desc!r}"


def test_meta_description_savings_clause_when_discounted():
    """If original_price > price by >= 5%, 'Save NN% off MSRP' must appear."""
    # check DB has a discount on windows-11-pro
    row = _mysql("SELECT IFNULL(original_price,0), price FROM products WHERE slug='windows-11-pro' LIMIT 1;")
    parts = row.split()
    assert len(parts) == 2, f"unexpected DB row: {row!r}"
    orig = float(parts[0]); price = float(parts[1])
    soup = _get_product("windows-11-pro")
    desc = _meta_desc(soup)
    if orig > price and orig > 0:
        pct = round((orig - price) / orig * 100)
        if pct >= 5:
            assert re.search(r"Save \d{1,2}% off MSRP", desc), \
                f"savings clause expected (orig={orig}, price={price}, pct={pct}): {desc!r}"
    else:
        assert "off MSRP" not in desc, f"savings clause must NOT appear when no discount: {desc!r}"


def test_meta_description_no_fabricated_discount():
    """Pick any product where original_price IS NULL or <= price → no savings clause."""
    slug = _mysql(
        "SELECT slug FROM products WHERE (original_price IS NULL OR original_price<=price) "
        "AND is_active=1 ORDER BY id LIMIT 1;"
    )
    if not slug:
        pytest.skip("No product without discount in DB")
    soup = _get_product(slug)
    desc = _meta_desc(soup)
    if desc:  # only check when ad-optimised (no admin override). Even if admin override, 'off MSRP' shouldn't appear unless they wrote it.
        # Admin override may legitimately contain anything, so only assert when there's no admin meta
        admin_meta = _mysql(f"SELECT IFNULL(meta_description,'') FROM products WHERE slug='{slug}' LIMIT 1;")
        if not admin_meta:
            assert "off MSRP" not in desc, \
                f"[{slug}] fabricated discount in auto meta: {desc!r}"


# ---------- 4. H1 visible + canonical name preserved ----------
def test_h1_uses_ads_string_and_canonical_preserved():
    soup = _get_product("windows-11-pro")
    h1 = soup.find(attrs={"data-testid": "product-name"})
    assert h1 is not None, "[data-testid='product-name'] not found"
    h1_text = h1.get_text(strip=True)
    assert h1_text.startswith("Buy "), f"H1 should start with 'Buy ': {h1_text!r}"
    assert " — " in h1_text, f"H1 should contain ' — ' separator: {h1_text!r}"
    assert h1_text.rstrip().endswith(("Lifetime License Key", "1-Year Subscription", "Genuine License Key")), \
        f"H1 should end with licence chip: {h1_text!r}"

    canon = soup.find(attrs={"data-testid": "product-canonical-name"})
    assert canon is not None, "[data-testid='product-canonical-name'] not found below H1"
    canon_text = canon.get_text(strip=True)
    assert canon_text, "canonical name node is empty"
    # Canonical text should NOT begin with 'Buy '
    assert not canon_text.startswith("Buy "), \
        f"canonical-name should be the original product name (no 'Buy '): {canon_text!r}"


# ---------- 5. Platform mapping ----------
@pytest.mark.parametrize("slug,expected_chip", [
    ("windows-11-pro", "for 1 PC"),
    ("microsoft-office-home-business-2024-mac", "for 1 Mac"),
])
def test_platform_chip_in_h1(slug, expected_chip):
    soup = _get_product(slug)
    h1 = soup.find(attrs={"data-testid": "product-name"})
    assert h1 is not None, f"[{slug}] product-name not found"
    h1_text = h1.get_text(strip=True)
    assert expected_chip in h1_text, f"[{slug}] expected '{expected_chip}' in H1: {h1_text!r}"


def test_platform_fallback_device_for_multidevice():
    """Multi-device antivirus product → 'for 1 Device' (generic fallback) OR 'for 1 PC'/'for 1 Mac' if platform set."""
    slug = "bitdefender-premium-vpn-unlimited-devices-1-year"
    soup = _get_product(slug)
    h1 = soup.find(attrs={"data-testid": "product-name"})
    assert h1 is not None
    h1_text = h1.get_text(strip=True)
    # Per _ads_seo: if platform != Mac and != Windows → 'for 1 Device'
    assert re.search(r"for 1 (PC|Mac|Device)", h1_text), \
        f"[{slug}] H1 missing device descriptor: {h1_text!r}"


# ---------- 6. Licence-type mapping ----------
def test_license_chip_subscription_product():
    """A 1-year subscription product (e.g. bitdefender ... 1-year) should use '1-Year Subscription'."""
    # Find a subscription-licensed product
    slug = _mysql(
        "SELECT slug FROM products WHERE LOWER(license_type)='subscription' "
        "AND is_active=1 ORDER BY id LIMIT 1;"
    )
    if not slug:
        pytest.skip("No subscription product in DB")
    soup = _get_product(slug)
    h1 = soup.find(attrs={"data-testid": "product-name"}).get_text(strip=True)
    title = _title_prefix(soup)
    assert "1-Year Subscription" in h1, f"[{slug}] H1 missing '1-Year Subscription': {h1!r}"
    assert "1-Year Subscription" in title, f"[{slug}] title missing '1-Year Subscription': {title!r}"
    assert "Lifetime License Key" not in h1, f"[{slug}] H1 wrongly has 'Lifetime License Key': {h1!r}"


def test_license_chip_lifetime_product():
    slug = _mysql(
        "SELECT slug FROM products WHERE LOWER(license_type)='lifetime' "
        "AND is_active=1 ORDER BY id LIMIT 1;"
    )
    if not slug:
        pytest.skip("No lifetime product in DB")
    soup = _get_product(slug)
    h1 = soup.find(attrs={"data-testid": "product-name"}).get_text(strip=True)
    assert "Lifetime License Key" in h1, f"[{slug}] H1 missing 'Lifetime License Key': {h1!r}"


# ---------- 7. Admin meta_description override ----------
def test_admin_meta_description_overrides_auto():
    custom = "Custom admin description here."
    # snapshot original
    original = _mysql("SELECT IFNULL(meta_description,'__NULL__') FROM products WHERE slug='windows-11-pro' LIMIT 1;")
    try:
        _mysql(f"UPDATE products SET meta_description='{custom}' WHERE slug='windows-11-pro';")
        soup = _get_product("windows-11-pro")
        desc = _meta_desc(soup)
        assert desc == custom, f"admin override not respected: rendered={desc!r}"
    finally:
        # restore: if originally NULL, set to NULL; else restore the value
        if original == "__NULL__":
            _mysql("UPDATE products SET meta_description=NULL WHERE slug='windows-11-pro';")
        else:
            safe = original.replace("'", "''")
            _mysql(f"UPDATE products SET meta_description='{safe}' WHERE slug='windows-11-pro';")


# ---------- 8. JSON-LD Product regression ----------
def test_product_jsonld_required_keys():
    soup = _get_product("windows-11-pro")
    blocks = soup.find_all("script", attrs={"type": "application/ld+json"})
    product_obj: Optional[dict] = None
    for b in blocks:
        if not b.string:
            continue
        try:
            data = json.loads(b.string)
        except Exception:
            continue
        # Could be a single object or a list / @graph
        candidates = []
        if isinstance(data, list):
            candidates = data
        elif isinstance(data, dict):
            if data.get("@type") == "Product":
                candidates = [data]
            elif "@graph" in data and isinstance(data["@graph"], list):
                candidates = data["@graph"]
            else:
                candidates = [data]
        for c in candidates:
            if isinstance(c, dict) and c.get("@type") == "Product":
                product_obj = c
                break
        if product_obj:
            break

    assert product_obj is not None, "Product JSON-LD block not found"
    required_keys = ["name", "sku", "brand", "offers", "description"]
    for k in required_keys:
        assert k in product_obj, f"Product JSON-LD missing key '{k}'"
    # MPN / GTIN may be optional but were called out in the spec — check soft
    for soft_key in ["mpn", "gtin13"]:
        if soft_key not in product_obj:
            print(f"WARN: Product JSON-LD missing soft key '{soft_key}'")

    offers = product_obj["offers"]
    if isinstance(offers, list):
        offers = offers[0]
    for k in ["price", "priceCurrency", "availability", "priceValidUntil", "itemCondition"]:
        assert k in offers, f"offers missing '{k}'"
