"""
Iteration 20 tests:
  1. Zero 'emergent' references in public-facing HTML (case-insensitive).
  2. Subscription plan icons render from local /assets/images/subscriptions/plan-{N}.png.
  3. DB scrubbing: subscription_plans / email_templates / email_outbox cleaned.
  4. Loading speed: gzip Content-Encoding header active; compressed size << uncompressed.
  5. Loading speed: plan icons <50 KB each, HTTP 200, image/png.
  6. Sitemap.xml still XSLT-styled and has >=80 <url> entries with recent lastmod.
"""
import os
import re
import subprocess
import datetime
from xml.etree import ElementTree as ET

import pytest
import requests

BASE = os.environ.get("PHP_BASE_URL", "http://127.0.0.1:3000")
PROD_HOST = "maventechsoftware.com"

PUBLIC_PAGES = [
    "/",
    "/subscriptions.php",
    "/product.php?slug=windows-11-pro",
    "/about-us.php",
    "/shop.php",
    "/checkout.php",
    "/contact.php",
]


def fetch(path, host=PROD_HOST, **kw):
    return requests.get(BASE + path, headers={"Host": host}, timeout=20, **kw)


# ---------------------------------------------------------------------------
# 1. No 'emergent' substring in public HTML
# ---------------------------------------------------------------------------
@pytest.mark.parametrize("path", PUBLIC_PAGES)
def test_no_emergent_substring_in_public_html(path):
    r = fetch(path)
    assert r.status_code == 200, f"{path} returned HTTP {r.status_code}"
    body_lower = r.text.lower()
    # The literal substring 'emergent' must not appear anywhere on public pages.
    if "emergent" in body_lower:
        # Surface up to 5 snippets to help debug
        snippets = []
        for m in re.finditer(r"emergent", body_lower):
            start = max(0, m.start() - 60)
            end = min(len(body_lower), m.end() + 60)
            snippets.append(r.text[start:end])
            if len(snippets) >= 5:
                break
        pytest.fail(f"{path} contains 'emergent' references: {snippets}")


# ---------------------------------------------------------------------------
# 2. Subscription plan icons render from local URLs
# ---------------------------------------------------------------------------
def test_subscription_plan_icons_are_local():
    r = fetch("/subscriptions.php")
    assert r.status_code == 200
    # Find all <img> src values
    srcs = re.findall(r'<img[^>]+src="([^"]+)"', r.text, re.I)
    # Filter to plan icons (path or filename hints)
    plan_srcs = [s for s in srcs if "/assets/images/subscriptions/plan-" in s]
    assert len(plan_srcs) >= 4, f"Expected 4+ plan icon imgs, got {plan_srcs}"
    for src in plan_srcs:
        assert src.endswith(".png"), f"Plan icon not PNG: {src}"
        assert "prod-images.emergentagent.com" not in src, f"emergent CDN leak: {src}"
        assert "emergentagent" not in src.lower(), f"emergent leak: {src}"
    # No image anywhere on the page should reference the emergent CDN
    for src in srcs:
        assert "prod-images.emergentagent.com" not in src, f"emergent CDN leak: {src}"


@pytest.mark.parametrize("n", [1, 2, 3, 4])
def test_plan_icon_file_served_under_50kb(n):
    r = fetch(f"/assets/images/subscriptions/plan-{n}.png")
    assert r.status_code == 200, f"plan-{n}.png HTTP {r.status_code}"
    ct = r.headers.get("Content-Type", "").lower()
    assert ct.startswith("image/png"), f"plan-{n}.png Content-Type={ct}"
    size = len(r.content)
    assert size < 50_000, f"plan-{n}.png is {size} bytes (>=50000)"


# ---------------------------------------------------------------------------
# 3. DB scrubbing
# ---------------------------------------------------------------------------
def _mysql(q):
    res = subprocess.run(
        ["mysql", "-uroot", "ucode_store", "-N", "-B", "-e", q],
        capture_output=True, text=True, timeout=15,
    )
    assert res.returncode == 0, f"mysql failed: {res.stderr}"
    return res.stdout.strip()


def test_db_subscription_plans_no_emergent():
    out = _mysql(
        "SELECT COUNT(*) FROM subscription_plans WHERE icon_image LIKE '%emergent%'"
    )
    assert out == "0", f"subscription_plans still has emergent rows: {out}"


def test_db_email_templates_no_emergent():
    out = _mysql(
        "SELECT COUNT(*) FROM email_templates WHERE html LIKE '%emergent%'"
    )
    assert out == "0", f"email_templates still has emergent rows: {out}"


def test_db_email_outbox_pending_no_emergent():
    out = _mysql(
        "SELECT COUNT(*) FROM email_outbox "
        "WHERE html LIKE '%emergent%' "
        "AND (status IS NULL OR status IN ('queued','pending'))"
    )
    assert out == "0", f"email_outbox pending still has emergent rows: {out}"


# ---------------------------------------------------------------------------
# 4. Gzip active + significant compression ratio
# ---------------------------------------------------------------------------
GZIP_PAGES = ["/", "/shop.php", "/product.php?slug=windows-11-pro"]


@pytest.mark.parametrize("path", GZIP_PAGES)
def test_response_uses_gzip_content_encoding(path):
    r = requests.get(
        BASE + path,
        headers={"Host": PROD_HOST, "Accept-Encoding": "gzip"},
        timeout=20,
    )
    assert r.status_code == 200
    ce = r.headers.get("Content-Encoding", "").lower()
    assert ce == "gzip", f"{path} Content-Encoding={ce!r}, expected 'gzip'"


@pytest.mark.parametrize("path", GZIP_PAGES)
def test_gzip_compression_ratio_at_least_50pct(path):
    # Uncompressed
    r_u = requests.get(
        BASE + path,
        headers={"Host": PROD_HOST, "Accept-Encoding": "identity"},
        timeout=20,
    )
    assert r_u.status_code == 200
    uncompressed = len(r_u.content)

    # Compressed wire size — use curl to get raw bytes off the wire
    res = subprocess.run(
        [
            "curl", "-s", "-o", "/dev/null",
            "-w", "%{size_download}",
            "-H", f"Host: {PROD_HOST}",
            "-H", "Accept-Encoding: gzip",
            BASE + path,
        ],
        capture_output=True, text=True, timeout=20,
    )
    assert res.returncode == 0, f"curl failed: {res.stderr}"
    compressed = int(res.stdout.strip())
    assert compressed > 0, f"compressed size is 0 for {path}"
    assert compressed * 2 < uncompressed, (
        f"{path}: compressed={compressed}B not <50% of uncompressed={uncompressed}B"
    )


# ---------------------------------------------------------------------------
# 6. Sitemap: XSLT styled + >=80 URLs + recent lastmod
# ---------------------------------------------------------------------------
def test_sitemap_xslt_styled_and_url_count():
    r = fetch("/sitemap.xml")
    assert r.status_code == 200, f"sitemap.xml HTTP {r.status_code}"
    body = r.text.lstrip("\ufeff").lstrip()
    # First line: XML declaration
    assert body.startswith("<?xml version=") and "encoding=\"UTF-8\"" in body[:60] \
        or body.startswith("<?xml version=") and "encoding='UTF-8'" in body[:60], \
        f"sitemap missing XML declaration. First 120 chars: {body[:120]!r}"
    # XSLT stylesheet
    assert "xml-stylesheet" in body[:300] and "sitemap.xsl" in body[:300], \
        f"sitemap missing xml-stylesheet ref. First 300 chars: {body[:300]!r}"
    # urlset open tag
    assert "<urlset" in body[:600]

    # Count <url>
    url_count = body.count("<url>")
    assert url_count >= 80, f"sitemap has only {url_count} <url> entries (<80)"


def test_sitemap_lastmod_recent():
    r = fetch("/sitemap.xml")
    assert r.status_code == 200
    # Strip the XSLT processing instruction so ET parses cleanly
    body = re.sub(r"<\?xml-stylesheet[^?]*\?>", "", r.text)
    # Drop default namespace for simpler XPath
    body = re.sub(r'\sxmlns="[^"]+"', "", body, count=1)
    root = ET.fromstring(body)
    lastmods = [el.text.strip() for el in root.iter("lastmod") if el.text]
    assert lastmods, "no <lastmod> elements found"
    parsed = []
    for lm in lastmods:
        # Accept either full ISO-8601 with time or plain date
        try:
            if "T" in lm:
                # Normalize trailing 'Z' to '+00:00'
                lm_norm = lm.replace("Z", "+00:00")
                dt = datetime.datetime.fromisoformat(lm_norm)
            else:
                dt = datetime.datetime.fromisoformat(lm + "T00:00:00+00:00")
            parsed.append(dt)
        except ValueError:
            pytest.fail(f"Invalid ISO-8601 lastmod: {lm!r}")
    # Max lastmod within last 7 days
    now = datetime.datetime.now(datetime.timezone.utc)
    # Make naive datetimes timezone-aware as UTC
    parsed_aware = [
        d if d.tzinfo else d.replace(tzinfo=datetime.timezone.utc) for d in parsed
    ]
    max_lm = max(parsed_aware)
    delta = now - max_lm
    assert delta.days <= 7, f"max lastmod {max_lm.isoformat()} is older than 7 days (delta={delta})"
