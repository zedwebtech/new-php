"""
Iteration-18 regression suite.
  (A) Sitemap rendering bug fix:
       - /sitemap.xml: XML with xml-stylesheet PI -> /assets/sitemap.xsl
       - /assets/sitemap.xsl: reachable and contains the table/template
  (B) Bing Shopping feed routes (4 new) + Google routes (5 existing).
       - Bing routes add per-item RSS-native field aliases.
       - Google routes do NOT add those aliases.
       - Channel title differentiation.
       - atom:link self-reference echoes the alias.
  (C) robots.txt lists three Sitemap directives.
  (D) Prior regression: 37 items, 148 g:product_highlight, 37
       g:sale_price_effective_date, 37 g:return_policy, 37
       g:free_shipping_threshold, cache headers, no Set-Cookie.
"""
import os
import re
import pytest
import requests
from xml.etree import ElementTree as ET

def _load_base_url():
    v = os.environ.get("REACT_APP_BACKEND_URL", "").strip()
    if not v:
        # Read from frontend/.env
        try:
            with open("/app/frontend/.env") as f:
                for ln in f:
                    if ln.startswith("REACT_APP_BACKEND_URL="):
                        v = ln.split("=", 1)[1].strip()
                        break
        except Exception:
            pass
    return v.rstrip("/")

BASE_URL = _load_base_url()
assert BASE_URL, "REACT_APP_BACKEND_URL must be set"

NS = {
    "rss": "",
    "g": "http://base.google.com/ns/1.0",
    "atom": "http://www.w3.org/2005/Atom",
    "sm": "http://www.sitemaps.org/schemas/sitemap/0.9",
}

BING_ROUTES = [
    "/feed/bing-shopping.xml",
    "/feeds/bing-shopping.xml",
    "/bing-shopping-feed.xml",
    "/microsoft-merchant-feed.xml",
]
GOOGLE_ROUTES = [
    "/merchant-feed.xml",
    "/feed/google-products.xml",
    "/feeds/google-products.xml",
    "/google-merchant-feed.xml",
    "/google-shopping-feed.xml",
]


@pytest.fixture(scope="module")
def http():
    s = requests.Session()
    s.headers.update({"User-Agent": "iter18-tester/1.0"})
    return s


# -------------------- (A) Sitemap rendering --------------------

class TestSitemapRendering:
    def test_sitemap_xml_status_and_ct(self, http):
        r = http.get(f"{BASE_URL}/sitemap.xml", timeout=20)
        assert r.status_code == 200
        ct = r.headers.get("Content-Type", "")
        assert "application/xml" in ct or "text/xml" in ct, ct

    def test_sitemap_starts_with_decl_and_xsl_pi(self, http):
        body = http.get(f"{BASE_URL}/sitemap.xml", timeout=20).text
        # Must start with XML decl
        assert body.lstrip().startswith("<?xml version=\"1.0\" encoding=\"UTF-8\"?>"), body[:120]
        # Must contain the stylesheet PI directly after the decl
        assert "<?xml-stylesheet" in body[:300]
        assert 'href="/assets/sitemap.xsl"' in body[:300] or "href='/assets/sitemap.xsl'" in body[:300]
        assert 'type="text/xsl"' in body[:300] or "type='text/xsl'" in body[:300]

    def test_sitemap_has_at_least_80_url_entries(self, http):
        body = http.get(f"{BASE_URL}/sitemap.xml", timeout=20).text
        root = ET.fromstring(body)
        urls = root.findall("sm:url", {"sm": NS["sm"]})
        assert len(urls) >= 80, f"only {len(urls)} URLs"

    def test_xsl_asset_reachable(self, http):
        r = http.get(f"{BASE_URL}/assets/sitemap.xsl", timeout=20)
        assert r.status_code == 200
        ct = r.headers.get("Content-Type", "")
        assert "xml" in ct or "xsl" in ct, ct
        body = r.text
        assert "<xsl:stylesheet" in body
        assert "<xsl:template" in body
        assert "match=\"/\"" in body or "match='/'" in body
        assert "XML Sitemap" in body
        # Pills classes for priority rendering
        for cls in ("prio-high", "prio-med", "prio-low"):
            assert cls in body, f"missing CSS class {cls}"

    def test_xsl_table_headers_present(self, http):
        body = http.get(f"{BASE_URL}/assets/sitemap.xsl", timeout=20).text
        for col in ("URL", "Last Modified", "Frequency", "Priority"):
            assert col in body, f"missing column header {col}"


# -------------------- (B) Bing/Google feed routes --------------------

class TestFeedRoutesReachable:
    @pytest.mark.parametrize("path", BING_ROUTES + GOOGLE_ROUTES)
    def test_route_returns_200_and_xml(self, http, path):
        r = http.get(f"{BASE_URL}{path}", timeout=30)
        assert r.status_code == 200, f"{path} -> {r.status_code}"
        ct = r.headers.get("Content-Type", "")
        assert "application/xml" in ct or "text/xml" in ct, f"{path}: {ct}"


def _parse(body: str):
    return ET.fromstring(body)


def _channel(root):
    ch = root.find("channel")
    assert ch is not None, "no <channel>"
    return ch


def _items(root):
    return root.findall("./channel/item")


class TestBingNativeRssFieldAliases:
    """Bing routes MUST add per-item native RSS fields alongside g:* fields."""

    def test_bing_items_have_native_fields(self, http):
        body = http.get(f"{BASE_URL}/feed/bing-shopping.xml", timeout=30).text
        root = _parse(body)
        items = _items(root)
        assert len(items) == 37, f"expected 37 items, got {len(items)}"
        for i, it in enumerate(items):
            assert it.find("title") is not None, f"item {i} missing <title>"
            assert it.find("link") is not None, f"item {i} missing <link>"
            assert it.find("description") is not None, f"item {i} missing <description>"
            guid = it.find("guid")
            assert guid is not None, f"item {i} missing <guid>"
            # isPermaLink attribute must be 'true'
            assert guid.attrib.get("isPermaLink") == "true", \
                f"item {i} guid isPermaLink={guid.attrib}"
            assert it.find("pubDate") is not None, f"item {i} missing <pubDate>"
            # And the g:* tags should ALSO still be present
            assert it.find(f"{{{NS['g']}}}id") is not None, f"item {i} missing g:id"
            assert it.find(f"{{{NS['g']}}}title") is not None
            assert it.find(f"{{{NS['g']}}}link") is not None

    def test_google_items_do_not_have_native_fields(self, http):
        body = http.get(f"{BASE_URL}/feed/google-products.xml", timeout=30).text
        root = _parse(body)
        items = _items(root)
        assert len(items) == 37
        for i, it in enumerate(items):
            # default-namespace 'title' / 'link' / etc. should be absent on <item>
            assert it.find("title") is None, f"item {i} unexpected <title>"
            assert it.find("link") is None, f"item {i} unexpected <link>"
            assert it.find("description") is None, f"item {i} unexpected <description>"
            assert it.find("guid") is None, f"item {i} unexpected <guid>"
            assert it.find("pubDate") is None, f"item {i} unexpected <pubDate>"
            # g:* must still be there
            assert it.find(f"{{{NS['g']}}}id") is not None


class TestChannelTitleDifferentiation:
    def test_bing_channel_title_suffix(self, http):
        body = http.get(f"{BASE_URL}/feed/bing-shopping.xml", timeout=30).text
        root = _parse(body)
        ch = _channel(root)
        title = (ch.find("title").text or "").strip()
        assert title.endswith("Bing Shopping Feed"), f"got: {title!r}"

    def test_google_channel_title_suffix(self, http):
        body = http.get(f"{BASE_URL}/feed/google-products.xml", timeout=30).text
        root = _parse(body)
        ch = _channel(root)
        title = (ch.find("title").text or "").strip()
        assert title.endswith("Software Product Feed"), f"got: {title!r}"


class TestAtomSelfLinkEchoesAlias:
    @pytest.mark.parametrize("path", BING_ROUTES)
    def test_self_link_matches_request_path(self, http, path):
        body = http.get(f"{BASE_URL}{path}", timeout=30).text
        root = _parse(body)
        ch = _channel(root)
        # atom:link rel='self'
        found = None
        for el in ch.findall(f"{{{NS['atom']}}}link"):
            if el.attrib.get("rel") == "self":
                found = el
                break
        assert found is not None, f"{path}: missing atom:link rel='self'"
        href = found.attrib.get("href", "")
        assert href.endswith(path), f"{path}: self href = {href}"


# -------------------- (C) robots.txt --------------------

class TestRobotsTxt:
    def test_three_sitemap_directives(self, http):
        body = http.get(f"{BASE_URL}/robots.txt", timeout=20).text
        # Required entries (suffixes; host part is dynamic)
        required = [
            "/merchant-feed.xml",
            "/feed/google-products.xml",
            "/feed/bing-shopping.xml",
        ]
        sitemap_lines = [
            ln.strip() for ln in body.splitlines() if ln.strip().lower().startswith("sitemap:")
        ]
        joined = "\n".join(sitemap_lines)
        for suffix in required:
            assert any(ln.endswith(suffix) for ln in sitemap_lines), \
                f"missing Sitemap line for {suffix}. Lines:\n{joined}"


# -------------------- (D) Regression on Google feed --------------------

class TestGoogleFeedRegression:
    @pytest.fixture(scope="class")
    def google_body(self):
        r = requests.get(f"{BASE_URL}/feed/google-products.xml", timeout=30)
        assert r.status_code == 200
        return r

    def test_37_items(self, google_body):
        root = _parse(google_body.text)
        assert len(_items(root)) == 37

    def test_148_product_highlights(self, google_body):
        # Count g:product_highlight occurrences in raw text (4 per item x 37 = 148)
        assert google_body.text.count("<g:product_highlight>") == 148

    def test_37_sale_price_effective_date(self, google_body):
        assert google_body.text.count("<g:sale_price_effective_date>") == 37

    def test_37_return_policy(self, google_body):
        assert google_body.text.count("<g:return_policy>") == 37

    def test_37_free_shipping_threshold(self, google_body):
        assert google_body.text.count("<g:free_shipping_threshold>") == 37

    def test_cache_headers(self, google_body):
        # Cache headers must be tested on ORIGIN (the public ingress may
        # override Cache-Control with no-store, which is acceptable).
        origin_r = requests.get("http://localhost:3000/feed/google-products.xml", timeout=15)
        cc = origin_r.headers.get("Cache-Control", "")
        assert "public" in cc and "max-age=3600" in cc, f"origin Cache-Control: {cc!r}"
        assert "Set-Cookie" not in origin_r.headers
        for h in ("Pragma", "Expires"):
            v = origin_r.headers.get(h, "")
            assert v == "" or v.lower() in ("", "0"), f"origin {h}: {v}"


class TestBingFeedRegression:
    @pytest.fixture(scope="class")
    def bing_body(self):
        r = requests.get(f"{BASE_URL}/feed/bing-shopping.xml", timeout=30)
        assert r.status_code == 200
        return r

    def test_37_items(self, bing_body):
        root = _parse(bing_body.text)
        assert len(_items(root)) == 37

    def test_148_product_highlights(self, bing_body):
        assert bing_body.text.count("<g:product_highlight>") == 148

    def test_37_return_policy(self, bing_body):
        assert bing_body.text.count("<g:return_policy>") == 37

    def test_37_free_shipping_threshold(self, bing_body):
        assert bing_body.text.count("<g:free_shipping_threshold>") == 37


# -------------------- (E) Product page iter-16/17 still hold --------------------

class TestProductPageRegression:
    @pytest.fixture(scope="class")
    def product_html(self):
        r = requests.get(f"{BASE_URL}/product.php?slug=windows-11-pro", timeout=20)
        assert r.status_code == 200
        return r.text

    def test_title_under_60_chars(self, product_html):
        m = re.search(r"<title>(.*?)</title>", product_html, re.I | re.S)
        assert m, "no <title>"
        title = m.group(1).strip()
        assert len(title) <= 60, f"title len {len(title)}: {title!r}"

    def test_meta_description_present(self, product_html):
        m = re.search(
            r'<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']',
            product_html, re.I,
        )
        assert m, "no meta description"
        assert len(m.group(1)) >= 50

    def test_product_jsonld_has_shipping_array_and_return_policy(self, product_html):
        # Find Product JSON-LD block
        blocks = re.findall(
            r'<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',
            product_html, re.S | re.I,
        )
        import json as _json
        found_product = None
        for b in blocks:
            try:
                data = _json.loads(b.strip())
            except Exception:
                continue
            if isinstance(data, dict) and data.get("@type") == "Product":
                found_product = data
                break
        assert found_product, "no Product JSON-LD"
        offers = found_product.get("offers", {})
        sd = offers.get("shippingDetails")
        assert isinstance(sd, list) and len(sd) == 6, f"shippingDetails={sd}"
        rp = offers.get("hasMerchantReturnPolicy")
        assert isinstance(rp, dict) and rp.get("applicableCountry"), rp
