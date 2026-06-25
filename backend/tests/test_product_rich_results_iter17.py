"""
Iteration 17 — Rich-result eligibility for Free returns + Free delivery badges.

Validates two surface areas:

A. /product.php JSON-LD (Product schema)
   - offers.shippingDetails is a LIST of OfferShippingDetails (>=6 entries),
     each with a SINGLE ISO addressCountry string (not array).
   - offers.hasMerchantReturnPolicy has the full Google-required key-set
     including applicableCountry list + refundType + merchantReturnLink.

B. /feed/google-products.xml (Merchant Center feed)
   - Exactly 37 <g:return_policy> blocks (one per active product).
   - Exactly 37 <g:free_shipping_threshold> blocks, each == "0.00 USD".
"""
import json
import re
from typing import Any, Dict, List

import pytest
import requests
from bs4 import BeautifulSoup

BASE_URL = "http://localhost:3000"
SAMPLE_SLUGS = [
    "windows-11-pro",
    "microsoft-office-home-2024-pc",
]
REQUIRED_COUNTRIES = {"US", "GB", "CA", "AU", "IN", "AE"}


# ---------- helpers ---------------------------------------------------------

def _fetch_jsonld_product(slug: str) -> Dict[str, Any]:
    r = requests.get(f"{BASE_URL}/product.php", params={"slug": slug}, timeout=20)
    assert r.status_code == 200, f"product.php?slug={slug} -> {r.status_code}"
    soup = BeautifulSoup(r.text, "html.parser")
    for tag in soup.find_all("script", attrs={"type": "application/ld+json"}):
        try:
            data = json.loads(tag.string or "")
        except Exception:
            continue
        candidates = data if isinstance(data, list) else [data]
        for blk in candidates:
            if isinstance(blk, dict) and blk.get("@type") == "Product":
                return blk
    pytest.fail(f"No Product JSON-LD block found for slug={slug}")


# ---------- A. shippingDetails per-country array ----------------------------

class TestShippingDetailsArray:
    """offers.shippingDetails must be a list of OfferShippingDetails, one
    rule per country, with a SINGLE ISO addressCountry string."""

    @pytest.mark.parametrize("slug", SAMPLE_SLUGS + ["windows-11-pro"])
    def test_shipping_details_is_array(self, slug):
        product = _fetch_jsonld_product(slug)
        offers = product.get("offers")
        assert isinstance(offers, dict), "offers should be a dict"
        sd = offers.get("shippingDetails")
        assert isinstance(sd, list), \
            f"shippingDetails must be a LIST (Google requires per-country rules); got {type(sd).__name__}"
        assert len(sd) >= 6, f"Expected >=6 shippingDetails entries, got {len(sd)}"

    def test_each_entry_has_required_shape(self):
        product = _fetch_jsonld_product("windows-11-pro")
        sd = product["offers"]["shippingDetails"]
        seen = set()
        for entry in sd:
            assert entry.get("@type") == "OfferShippingDetails"

            # shippingRate.value == "0"
            rate = entry.get("shippingRate")
            assert isinstance(rate, dict) and str(rate.get("value")) == "0", \
                f"shippingRate.value must be '0'; got {rate}"

            # addressCountry must be a SINGLE ISO STRING (not array)
            dest = entry.get("shippingDestination")
            assert isinstance(dest, dict)
            country = dest.get("addressCountry")
            assert isinstance(country, str), (
                f"addressCountry must be a single string ISO code (Google rejects arrays); "
                f"got {type(country).__name__}: {country!r}"
            )
            assert re.fullmatch(r"[A-Z]{2}", country), f"Bad ISO code: {country!r}"
            seen.add(country)

            # doesNotShip must be present and false
            assert entry.get("doesNotShip") is False, \
                f"doesNotShip must be boolean false; got {entry.get('doesNotShip')!r}"

            # deliveryTime with both handlingTime + transitTime
            dt = entry.get("deliveryTime")
            assert isinstance(dt, dict)
            assert isinstance(dt.get("handlingTime"), dict), "handlingTime missing"
            assert isinstance(dt.get("transitTime"), dict), "transitTime missing"

        missing = REQUIRED_COUNTRIES - seen
        assert not missing, f"Missing required countries in shippingDetails: {missing}"

    def test_shape_consistent_across_slugs(self):
        for slug in SAMPLE_SLUGS:
            product = _fetch_jsonld_product(slug)
            sd = product["offers"]["shippingDetails"]
            countries = {e["shippingDestination"]["addressCountry"] for e in sd}
            assert REQUIRED_COUNTRIES.issubset(countries), \
                f"Slug {slug} missing countries: {REQUIRED_COUNTRIES - countries}"
            for e in sd:
                ac = e["shippingDestination"]["addressCountry"]
                assert isinstance(ac, str), f"{slug}: addressCountry must be string, got {type(ac)}"


# ---------- B. hasMerchantReturnPolicy --------------------------------------

class TestMerchantReturnPolicy:
    """offers.hasMerchantReturnPolicy must contain all Google-required keys
    so the 'Free returns' badge is rich-result eligible."""

    REQUIRED_KEYS = {
        "@type",
        "applicableCountry",
        "returnPolicyCategory",
        "merchantReturnDays",
        "returnMethod",
        "returnFees",
        "refundType",
        "merchantReturnLink",
    }

    def test_all_required_keys_present(self):
        product = _fetch_jsonld_product("windows-11-pro")
        rp = product["offers"].get("hasMerchantReturnPolicy")
        assert isinstance(rp, dict), "hasMerchantReturnPolicy must be a dict"
        missing = self.REQUIRED_KEYS - set(rp.keys())
        assert not missing, f"Missing required hasMerchantReturnPolicy keys: {missing}"

    def test_key_values_correct(self):
        product = _fetch_jsonld_product("windows-11-pro")
        rp = product["offers"]["hasMerchantReturnPolicy"]

        assert rp["@type"] == "MerchantReturnPolicy"

        ac = rp["applicableCountry"]
        assert isinstance(ac, list), \
            "applicableCountry must be a list (Google requires it for multi-country policies)"
        assert set(ac) == REQUIRED_COUNTRIES, \
            f"applicableCountry mismatch. Got {ac}, expected {REQUIRED_COUNTRIES}"

        assert rp["returnPolicyCategory"] == "https://schema.org/MerchantReturnFiniteReturnWindow"
        assert int(rp["merchantReturnDays"]) == 30
        assert rp["returnMethod"] == "https://schema.org/ReturnByMail"
        assert rp["returnFees"] == "https://schema.org/FreeReturn"
        assert rp["refundType"] == "https://schema.org/FullRefund"
        assert rp["merchantReturnLink"].endswith("/page.php?slug=refund-policy"), \
            f"merchantReturnLink should point to refund-policy page; got {rp['merchantReturnLink']!r}"

    @pytest.mark.parametrize("slug", SAMPLE_SLUGS)
    def test_policy_consistent_across_slugs(self, slug):
        product = _fetch_jsonld_product(slug)
        rp = product["offers"]["hasMerchantReturnPolicy"]
        assert set(rp.keys()) >= self.REQUIRED_KEYS
        assert set(rp["applicableCountry"]) == REQUIRED_COUNTRIES


# ---------- C. Rich-result compatibility heuristics -------------------------

class TestRichResultCompat:
    """Simulates Google Rich Results Test validator concerns at structural
    level: no addressCountry-array, no missing-applicableCountry."""

    def test_no_address_country_array(self):
        product = _fetch_jsonld_product("windows-11-pro")
        for e in product["offers"]["shippingDetails"]:
            ac = e["shippingDestination"]["addressCountry"]
            assert not isinstance(ac, list), \
                "Google rejects array addressCountry — must be one rule per country"

    def test_return_policy_applicable_country_present(self):
        product = _fetch_jsonld_product("windows-11-pro")
        rp = product["offers"]["hasMerchantReturnPolicy"]
        assert "applicableCountry" in rp and rp["applicableCountry"], \
            "applicableCountry must be present + non-empty for Merchant Listing eligibility"


# ---------- D. Merchant feed return_policy + free_shipping_threshold --------

@pytest.fixture(scope="module")
def feed_text() -> str:
    r = requests.get(f"{BASE_URL}/feed/google-products.xml", timeout=30)
    assert r.status_code == 200, f"feed status {r.status_code}"
    return r.text


class TestMerchantFeed:

    def test_item_count_is_37(self, feed_text):
        items = re.findall(r"<item>", feed_text)
        assert len(items) == 37, f"Expected 37 <item>, got {len(items)}"

    def test_return_policy_count_is_37(self, feed_text):
        blocks = re.findall(r"<g:return_policy>", feed_text)
        assert len(blocks) == 37, f"Expected 37 <g:return_policy>, got {len(blocks)}"

    def test_return_policy_block_structure(self, feed_text):
        # every block must contain country + policy text
        block_re = re.compile(
            r"<g:return_policy>\s*"
            r"<g:return_policy_country>([^<]+)</g:return_policy_country>\s*"
            r"<g:return_policy_policy>([^<]+)</g:return_policy_policy>\s*"
            r"</g:return_policy>",
            re.S,
        )
        matches = block_re.findall(feed_text)
        assert len(matches) == 37, \
            f"return_policy block didn't match expected shape 37x; matched {len(matches)}"
        for country, policy in matches:
            assert country.strip() == "US", f"Expected return_policy_country=US, got {country!r}"
            assert policy.strip() == "30 days free returns", \
                f"Unexpected policy text: {policy!r}"

    def test_free_shipping_threshold_count_is_37(self, feed_text):
        tags = re.findall(
            r"<g:free_shipping_threshold>([^<]+)</g:free_shipping_threshold>", feed_text
        )
        assert len(tags) == 37, f"Expected 37 <g:free_shipping_threshold>, got {len(tags)}"
        for v in tags:
            assert v.strip() == "0.00 USD", \
                f"free_shipping_threshold must be '0.00 USD'; got {v!r}"
