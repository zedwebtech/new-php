"""
Tests for Google Merchant Center feed at multiple alias URLs.
Iteration 9: /feed/google-products.xml alias and friends.
"""
import os
import re
import random
import pytest
import requests
import xml.etree.ElementTree as ET

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "https://show-live-7.preview.emergentagent.com").rstrip("/")
# Origin URL — bypass Cloudflare/preview-CF so Cache-Control is observed as Google would see in production
ORIGIN_URL = "http://localhost:3000"
PUBLIC_HOST = "show-live-7.preview.emergentagent.com"

FEED_URLS = [
    "/merchant-feed.xml",
    "/feed/google-products.xml",
    "/feeds/google-products.xml",
    "/google-merchant-feed.xml",
    "/google-shopping-feed.xml",
]

NS = {
    "g": "http://base.google.com/ns/1.0",
    "atom": "http://www.w3.org/2005/Atom",
}


@pytest.fixture(scope="module")
def feed_response():
    """Canonical feed fetch used by content tests."""
    r = requests.get(f"{BASE_URL}/feed/google-products.xml", timeout=30, allow_redirects=False)
    return r


# ---------- 1. URL aliases respond 200 + XML ----------
@pytest.mark.parametrize("path", FEED_URLS)
def test_feed_alias_returns_200_xml(path):
    r = requests.get(f"{BASE_URL}{path}", timeout=30, allow_redirects=False)
    assert r.status_code == 200, f"{path} returned {r.status_code}"
    ctype = r.headers.get("Content-Type", "")
    assert "xml" in ctype.lower(), f"{path} content-type is {ctype}"
    # Must be a valid RSS 2.0 doc with the Google namespace
    body = r.text
    assert body.lstrip().startswith("<?xml"), f"{path} not XML"
    assert 'xmlns:g="http://base.google.com/ns/1.0"' in body, f"{path} missing g namespace"
    assert "<rss" in body and 'version="2.0"' in body, f"{path} missing rss 2.0 root"
    # Parses cleanly
    ET.fromstring(body)


# ---------- 2. Cache headers / X-Robots-Tag (ORIGIN headers - what Google sees in production) ----------
@pytest.mark.parametrize("path", FEED_URLS)
def test_feed_cache_and_robots_headers_origin(path):
    """Assert against ORIGIN (localhost:3000). Preview-CF rewrites Cache-Control to no-store
    and injects __cf_bm cookie — that's an Emergent preview-env artifact, not a code bug.
    In production (real domain, no Emergent preview CF), Google sees these origin headers directly."""
    r = requests.get(f"{ORIGIN_URL}{path}",
                     headers={"Host": PUBLIC_HOST, "X-Forwarded-Host": PUBLIC_HOST, "X-Forwarded-Proto": "https"},
                     timeout=30, allow_redirects=False)
    assert r.status_code == 200, f"{path} returned {r.status_code}"
    cc = r.headers.get("Cache-Control", "")
    xrt = r.headers.get("X-Robots-Tag", "")
    pragma = r.headers.get("Pragma", "")
    expires = r.headers.get("Expires", "")
    set_cookie = r.headers.get("Set-Cookie", "")
    assert cc == "public, max-age=3600", f"{path} Cache-Control={cc!r}"
    assert "noindex" in xrt and "nofollow" in xrt, f"{path} X-Robots-Tag={xrt!r}"
    assert pragma == "", f"{path} Pragma should be absent, got {pragma!r}"
    assert expires == "", f"{path} Expires should be absent, got {expires!r}"
    assert "PHPSESSID" not in set_cookie, f"{path} Set-Cookie should not have PHPSESSID, got {set_cookie!r}"


# ---------- 3. Feed content structure ----------
def test_feed_has_37_items_with_required_google_fields(feed_response):
    assert feed_response.status_code == 200
    root = ET.fromstring(feed_response.text)
    channel = root.find("channel")
    assert channel is not None, "no <channel>"
    items = channel.findall("item")
    assert len(items) == 37, f"expected 37 items, got {len(items)}"

    required_g = [
        "id", "title", "description", "link", "image_link",
        "availability", "price", "brand", "mpn", "sku",
        "gtin", "condition", "google_product_category",
    ]
    first = items[0]
    missing = []
    for f in required_g:
        el = first.find(f"g:{f}", NS)
        if el is None or not (el.text or "").strip():
            missing.append(f)
    assert not missing, f"first item missing g:{missing}"

    # Validate first item value patterns
    price = first.find("g:price", NS).text.strip()
    gtin = first.find("g:gtin", NS).text.strip()
    link = first.find("g:link", NS).text.strip()
    condition = first.find("g:condition", NS).text.strip()

    assert price.endswith(" USD"), f"price doesn't end in USD: {price!r}"
    assert re.fullmatch(r"[0-9]{13}", gtin), f"gtin not 13 digits: {gtin!r}"
    assert link.startswith(("http://", "https://")), f"link not http(s): {link!r}"
    assert condition == "new", f"condition={condition!r}"


def test_random_5_items_have_gtin_and_resolvable_links(feed_response):
    root = ET.fromstring(feed_response.text)
    items = root.find("channel").findall("item")
    sample = random.sample(items, 5)
    for it in sample:
        gtin_el = it.find("g:gtin", NS)
        link_el = it.find("g:link", NS)
        assert gtin_el is not None and re.fullmatch(r"[0-9]{13}", (gtin_el.text or "").strip()), \
            f"item {it.find('g:id', NS).text} bad gtin"
        link = (link_el.text or "").strip()
        assert link.startswith(("http://", "https://"))
        r = requests.get(link, timeout=20, allow_redirects=True)
        assert r.status_code == 200, f"product link {link} -> {r.status_code}"


# ---------- 4. atom:link self-reference echoes request URL ----------
@pytest.mark.parametrize("path", [
    "/merchant-feed.xml",
    "/feed/google-products.xml",
    "/feeds/google-products.xml",
    "/google-merchant-feed.xml",
    "/google-shopping-feed.xml",
])
def test_atom_self_link_matches_request_url(path):
    r = requests.get(f"{BASE_URL}{path}", timeout=30, allow_redirects=False)
    assert r.status_code == 200
    root = ET.fromstring(r.text)
    channel = root.find("channel")
    atom_link = channel.find("atom:link", NS)
    assert atom_link is not None, f"{path} missing atom:link"
    href = atom_link.get("href", "")
    rel = atom_link.get("rel", "")
    assert rel == "self", f"{path} atom rel={rel!r}"
    assert href.endswith(path), f"{path} self href={href!r} (should end with request path)"


# ---------- 5. robots.txt discovery ----------
def test_robots_txt_lists_both_feed_sitemaps_with_public_host():
    r = requests.get(f"{BASE_URL}/robots.txt", timeout=20)
    assert r.status_code == 200
    body = r.text
    sitemaps = [ln.strip() for ln in body.splitlines() if ln.strip().lower().startswith("sitemap:")]
    joined = "\n".join(sitemaps)
    assert f"https://{PUBLIC_HOST}/merchant-feed.xml" in joined, f"robots.txt missing merchant-feed.xml with public host: {sitemaps}"
    assert f"https://{PUBLIC_HOST}/feed/google-products.xml" in joined, f"robots.txt missing /feed/google-products.xml with public host: {sitemaps}"


# ---------- 6. All absolute URLs in feed use the PUBLIC host (iteration 10 retest) ----------
def test_all_feed_urls_use_public_host(feed_response):
    """Every <g:link>, <g:image_link>, channel <link>, and <atom:link href> must use the public host."""
    body = feed_response.text
    # Negative: no cluster-internal host anywhere
    assert "cluster-" not in body and "emergentcf.cloud" not in body, \
        "feed contains cluster-internal host references"

    root = ET.fromstring(body)
    channel = root.find("channel")
    # Atom self link
    atom_href = channel.find("atom:link", NS).get("href", "")
    assert atom_href == f"https://{PUBLIC_HOST}/feed/google-products.xml", f"atom self={atom_href!r}"
    # Channel link
    chan_link = channel.find("link").text.strip()
    assert PUBLIC_HOST in chan_link, f"channel link={chan_link!r}"
    # Every item link & image_link
    for it in channel.findall("item"):
        link = it.find("g:link", NS).text.strip()
        img = it.find("g:image_link", NS).text.strip()
        assert PUBLIC_HOST in link, f"item {it.find('g:id', NS).text} link host wrong: {link}"
        assert PUBLIC_HOST in img, f"item {it.find('g:id', NS).text} image host wrong: {img}"


def test_sample_3_item_links_are_http_200(feed_response):
    """Pick 3 random items and confirm each g:link returns 200 (Google would crawl these)."""
    root = ET.fromstring(feed_response.text)
    items = root.find("channel").findall("item")
    sample = random.sample(items, 3)
    for it in sample:
        link = it.find("g:link", NS).text.strip()
        r = requests.get(link, timeout=20, allow_redirects=True)
        assert r.status_code == 200, f"g:link {link} -> {r.status_code}"


# ---------- 7. Iteration 11: g:sale_price_effective_date ----------
import datetime as _dt
import subprocess as _sp

ISO_INTERVAL_RE = re.compile(
    r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\+00:00/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\+00:00$"
)


def _mysql(sql: str) -> str:
    """Run a MySQL statement against ucode_store (no password, root)."""
    return _sp.check_output(
        ["mysql", "-uroot", "ucode_store", "-N", "-B", "-e", sql],
        stderr=_sp.STDOUT,
        timeout=15,
    ).decode()


def test_sale_effective_date_count_matches_sale_price(feed_response):
    """Every <g:sale_price> must be accompanied by <g:sale_price_effective_date>
    (one is useless to Google without the other). With current seed data, every
    product is on sale -> 37 occurrences each."""
    body = feed_response.text
    sp = body.count("<g:sale_price>")
    spd = body.count("<g:sale_price_effective_date>")
    assert sp == 37, f"<g:sale_price> count={sp}, expected 37"
    assert spd == 37, f"<g:sale_price_effective_date> count={spd}, expected 37"
    # Every item also has g:price (original) at item level — there is *also*
    # a separate <g:price> inside the per-item <g:shipping> block (=0.00 USD),
    # so total <g:price> open-tag count is 2x items. Parse XML to assert
    # exactly one item-level g:price per item.
    root = ET.fromstring(body)
    items = root.find("channel").findall("item")
    for it in items:
        # item-level g:price (direct child of <item>)
        item_level_prices = [c for c in it if c.tag.endswith("}price")]
        assert len(item_level_prices) == 1, (
            f"item {it.find('g:id', NS).text} has {len(item_level_prices)} item-level g:price"
        )
        # item-level g:sale_price (direct child)
        item_level_sale = [c for c in it if c.tag.endswith("}sale_price")]
        assert len(item_level_sale) == 1, (
            f"item {it.find('g:id', NS).text} missing item-level g:sale_price"
        )
        # item-level g:sale_price_effective_date (direct child)
        item_level_sped = [c for c in it if c.tag.endswith("}sale_price_effective_date")]
        assert len(item_level_sped) == 1, (
            f"item {it.find('g:id', NS).text} missing g:sale_price_effective_date"
        )


def test_sale_effective_date_format_and_rolling_window(feed_response):
    """All emitted intervals match ISO-8601 pattern. Where sale_starts_at /
    sale_ends_at are NULL (current state for every product), the range MUST
    be today 00:00 UTC -> today+30 days 23:59 UTC."""
    root = ET.fromstring(feed_response.text)
    items = root.find("channel").findall("item")

    # Today and today+30 in UTC
    today = _dt.datetime.utcnow().date()
    plus30 = today + _dt.timedelta(days=30)
    expected_rolling = (
        f"{today.isoformat()}T00:00+00:00/{plus30.isoformat()}T23:59+00:00"
    )

    # All products currently have NULL sale_starts_at/sale_ends_at
    nulls = _mysql(
        "SELECT COUNT(*) FROM products WHERE is_active=1 AND sale_starts_at IS NULL AND sale_ends_at IS NULL"
    ).strip()
    assert nulls == "37", f"expected 37 products with NULL sale dates, DB says {nulls}"

    for it in items:
        el = it.find("g:sale_price_effective_date", NS)
        assert el is not None, f"item {it.find('g:id', NS).text} missing sale_price_effective_date"
        val = (el.text or "").strip()
        assert ISO_INTERVAL_RE.match(val), f"bad ISO interval: {val!r}"
        assert val == expected_rolling, (
            f"item {it.find('g:id', NS).text} rolling window mismatch: {val!r} != {expected_rolling!r}"
        )


def test_admin_pinned_window_overrides_rolling_for_id2():
    """Pin a sale window on product id=2; the feed item with <g:id>2</g:id>
    MUST emit that pinned window while every other item retains the rolling
    window. Clean up by NULLing the columns after the assertion regardless of
    pass/fail."""
    try:
        _mysql(
            "UPDATE products SET sale_starts_at='2026-04-01 00:00:00', "
            "sale_ends_at='2026-04-30 23:59:00' WHERE id=2"
        )
        r = requests.get(f"{BASE_URL}/feed/google-products.xml", timeout=30)
        assert r.status_code == 200
        root = ET.fromstring(r.text)
        items = root.find("channel").findall("item")

        today = _dt.datetime.utcnow().date()
        plus30 = today + _dt.timedelta(days=30)
        expected_rolling = (
            f"{today.isoformat()}T00:00+00:00/{plus30.isoformat()}T23:59+00:00"
        )
        expected_pinned = "2026-04-01T00:00+00:00/2026-04-30T23:59+00:00"

        seen_pinned = False
        for it in items:
            gid = it.find("g:id", NS).text.strip()
            val = it.find("g:sale_price_effective_date", NS).text.strip()
            if gid == "2":
                assert val == expected_pinned, f"id=2 pinned mismatch: {val!r}"
                seen_pinned = True
            else:
                assert val == expected_rolling, (
                    f"id={gid} should still be rolling, got {val!r}"
                )
        assert seen_pinned, "did not find product id=2 in feed"
    finally:
        _mysql("UPDATE products SET sale_starts_at=NULL, sale_ends_at=NULL WHERE id=2")


def test_admin_form_has_sale_window_inputs():
    """Render the admin Edit Product modal and assert both datetime-local
    inputs are present with the documented data-testid selectors."""
    s = requests.Session()
    # Login
    r = s.post(
        f"{BASE_URL}/login.php",
        data={"email": "admin@maventechsoftware.com", "password": "Admin@UC2026!"},
        allow_redirects=True,
        timeout=20,
    )
    assert r.status_code == 200, f"login -> {r.status_code}"
    # Admin products edit modal (markup rendered when ?edit=<slug> is set)
    r = s.get(
        f"{BASE_URL}/admin.php?tab=products&edit=microsoft-office-2024-professional-plus-windows",
        timeout=20,
    )
    assert r.status_code == 200, f"admin edit -> {r.status_code}"
    body = r.text
    assert 'data-testid="product-sale-starts-input"' in body, "sale-starts input missing"
    assert 'data-testid="product-sale-ends-input"' in body, "sale-ends input missing"
    assert 'name="sale_starts_at"' in body and 'type="datetime-local"' in body
    assert 'name="sale_ends_at"' in body
    # Collapsible <details>/<summary> wrapper with the documented label
    assert "Optional: Pin sale window for Google Shopping" in body


# ---------- 8. Iteration 12: g:product_highlight ----------
def test_product_highlight_count_per_item(feed_response):
    """Every <item> must emit exactly 4 <g:product_highlight> tags (37 items -> 148 total)."""
    body = feed_response.text
    total = body.count("<g:product_highlight>")
    assert total == 148, f"<g:product_highlight> total={total}, expected 148"
    root = ET.fromstring(body)
    items = root.find("channel").findall("item")
    for it in items:
        highlights = it.findall("g:product_highlight", NS)
        gid = it.find("g:id", NS).text
        assert len(highlights) == 4, f"item {gid} has {len(highlights)} highlights, expected 4"


def test_product_highlight_content_sanity(feed_response):
    """Each highlight: <= 150 chars, no HTML tags, no UTF-8 replacement char."""
    root = ET.fromstring(feed_response.text)
    items = root.find("channel").findall("item")
    for it in items:
        gid = it.find("g:id", NS).text
        for h in it.findall("g:product_highlight", NS):
            txt = (h.text or "").strip()
            assert txt, f"item {gid} has empty highlight"
            assert len(txt) <= 150, f"item {gid} highlight too long ({len(txt)}): {txt!r}"
            assert "<" not in txt and ">" not in txt, f"item {gid} highlight has HTML: {txt!r}"
            assert "\ufffd" not in txt, f"item {gid} highlight has replacement char: {txt!r}"


def test_product_highlight_office_id1_synthesised_bullets(feed_response):
    """Product id=1 (Office 2024 Pro Plus, apps=word,excel,powerpoint,outlook,access):
       must emit the 4 synthesised Office bullets (genuine, lifetime, apps, delivery)."""
    root = ET.fromstring(feed_response.text)
    items = root.find("channel").findall("item")
    item1 = next((it for it in items if it.find("g:id", NS).text == "1"), None)
    assert item1 is not None, "no product id=1 in feed"
    bullets = [(h.text or "").strip() for h in item1.findall("g:product_highlight", NS)]
    assert any("Genuine Microsoft license for 1 Windows device" in b for b in bullets), bullets
    assert any("Lifetime activation" in b or "perpetual" in b.lower() or "subscription" in b.lower() for b in bullets), bullets
    apps_bullets = [b for b in bullets if b.startswith("Includes Word, Excel, Powerpoint, Outlook, Access")]
    assert apps_bullets, f"missing apps bullet starting with 'Includes Word, Excel, Powerpoint, Outlook, Access': {bullets}"
    assert any("Instant digital delivery by email in 15" in b for b in bullets), bullets


def test_product_highlight_windows_id21_no_apps_has_guarantee(feed_response):
    """Product id=21 (Windows 11 Home, no apps): must emit licence/genuine/delivery
       PLUS the guarantee bullet (no apps bullet, since apps is empty)."""
    root = ET.fromstring(feed_response.text)
    items = root.find("channel").findall("item")
    item21 = next((it for it in items if it.find("g:id", NS).text == "21"), None)
    assert item21 is not None, "no product id=21 in feed"
    bullets = [(h.text or "").strip() for h in item21.findall("g:product_highlight", NS)]
    assert any("Genuine Microsoft license" in b for b in bullets), bullets
    assert any("Instant digital delivery by email" in b for b in bullets), bullets
    assert any("30-day money-back guarantee" in b for b in bullets), bullets
    # No apps bullet
    assert not any(b.startswith("Includes ") for b in bullets), f"id=21 should not have apps bullet: {bullets}"


def test_product_highlight_antivirus_brand_correct(feed_response):
    """Antivirus products (Bitdefender, McAfee, Norton, etc.) must have the brand-correct
       first 'Genuine <Brand> license...' bullet, NOT 'Genuine Microsoft license...'."""
    root = ET.fromstring(feed_response.text)
    items = root.find("channel").findall("item")
    brand_keywords = {"Bitdefender": "Bitdefender", "McAfee": "McAfee",
                       "Norton": "Norton", "Kaspersky": "Kaspersky", "ESET": "ESET"}
    found_any = False
    for it in items:
        title = (it.find("g:title", NS).text or "")
        for kw, brand in brand_keywords.items():
            if kw.lower() in title.lower():
                bullets = [(h.text or "").strip() for h in it.findall("g:product_highlight", NS)]
                # The genuine-license bullet must reference the brand, not Microsoft
                genuine = [b for b in bullets if b.startswith("Genuine ")]
                assert genuine, f"item {it.find('g:id', NS).text} ({title!r}) no Genuine bullet"
                assert any(brand in b for b in genuine), (
                    f"item {it.find('g:id', NS).text} ({title!r}) genuine bullet not branded {brand}: {genuine}"
                )
                assert not any("Genuine Microsoft" in b for b in genuine), (
                    f"item {it.find('g:id', NS).text} ({title!r}) wrongly branded Microsoft: {genuine}"
                )
                found_any = True
                break
    assert found_any, "no antivirus products found to test branding"
