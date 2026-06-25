#!/bin/bash
# ============================================================
# Emergent preview launcher — serves the PHP store on port 3000
# (replaces the React dev server; supervisor runs this via `yarn start`)
# Self-healing: starts MariaDB if needed and seeds the database
# on a fresh pod. NOT needed on normal PHP hosting (cPanel etc.)
# ============================================================
set -e

# Secrets ARE NOT HARDCODED HERE.  They are loaded from /app/backend/.env
# (which is git-ignored).  In production, set the same env vars in your
# hosting control panel.  See section "Export integration keys" below.

# 1) Ensure MariaDB is running
if ! mysqladmin ping --silent 2>/dev/null; then
  mkdir -p /run/mysqld
  chown mysql:mysql /run/mysqld 2>/dev/null || true
  (mysqld_safe --skip-grant-tables=0 >/dev/null 2>&1 &)
  for i in $(seq 1 30); do
    mysqladmin ping --silent 2>/dev/null && break
    sleep 1
  done
fi

# 2) Seed the database if missing (fresh pod)
if ! mysql -uroot -e "USE ucode_store" 2>/dev/null; then
  mysql -uroot -e "CREATE DATABASE IF NOT EXISTS ucode_store CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  mysql -uroot ucode_store < /app/php-version/database.sql
  echo "[start.sh] Database ucode_store created and seeded"
fi

# 2b) Idempotent schema migrations (safe on every boot)
mysql -uroot ucode_store -e "ALTER TABLE products ADD COLUMN IF NOT EXISTS activation_url VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
# gtin — Global Trade Item Number for the Google/Bing/Meta Shopping feed
mysql -uroot ucode_store -e "ALTER TABLE products ADD COLUMN IF NOT EXISTS gtin VARCHAR(20) DEFAULT NULL AFTER sku" 2>/dev/null || true
# delivery_status — 'delivered' once a license key is emailed, 'pending' when sold out of inventory (backorder, delivered within the hour)
mysql -uroot ucode_store -e "ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(20) NOT NULL DEFAULT 'delivered' AFTER fulfilled" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE products ADD COLUMN IF NOT EXISTS install_guide_url VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
# installer_url — per-product "Download installer" link (vendor CDN setup.exe etc.)
mysql -uroot ucode_store -e "ALTER TABLE products ADD COLUMN IF NOT EXISTS installer_url VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
# Keep the public base URL in sync with this preview pod so emails/PDFs build
# reachable absolute image URLs (the customer's mail client can load them).
# On a real domain this is left to the admin's "Site URL" setting / Host header.
PREVIEW_URL=$(grep -E '^REACT_APP_BACKEND_URL=' /app/frontend/.env 2>/dev/null | cut -d= -f2-)
if [ -n "$PREVIEW_URL" ]; then
  mysql -uroot ucode_store -e "INSERT INTO settings (k,v) VALUES ('site_domain_url','$PREVIEW_URL') ON DUPLICATE KEY UPDATE v=VALUES(v); INSERT INTO settings (k,v) VALUES ('main_url','$PREVIEW_URL') ON DUPLICATE KEY UPDATE v=VALUES(v);" 2>/dev/null || true
fi
# gw_mode on orders — captured at checkout so admins can filter test vs live orders
mysql -uroot ucode_store -e "ALTER TABLE orders ADD COLUMN IF NOT EXISTS gw_mode VARCHAR(10) NOT NULL DEFAULT 'test' AFTER status" 2>/dev/null || true
# chat_messages attachments — file uploads + voice notes in the support chat
mysql -uroot ucode_store -e "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS attachment_url  VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS attachment_type VARCHAR(20)  DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255) DEFAULT NULL" 2>/dev/null || true
# chat_leads admin_seen_at — drives the "needs attention" red badge for new callback/ProAssist leads
mysql -uroot ucode_store -e "ALTER TABLE chat_leads ADD COLUMN IF NOT EXISTS admin_seen_at DATETIME DEFAULT NULL" 2>/dev/null || true
# chat_leads agent_name — name of the agent who joined a live chat (for the "X has joined" notice)
mysql -uroot ucode_store -e "ALTER TABLE chat_leads ADD COLUMN IF NOT EXISTS agent_name VARCHAR(120) DEFAULT NULL" 2>/dev/null || true
# Staff accounts (RBAC) — username login, department, per-panel permissions, active flag
mysql -uroot ucode_store -e "ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(60) DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE users ADD COLUMN IF NOT EXISTS department VARCHAR(40) NOT NULL DEFAULT ''" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE users ADD COLUMN IF NOT EXISTS permissions TEXT DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE users ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE users MODIFY email VARCHAR(255) NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE users ADD UNIQUE KEY uniq_username (username)" 2>/dev/null || true
# customer_subscriptions assignment + notes (department / handler / track record)
mysql -uroot ucode_store -e "ALTER TABLE customer_subscriptions ADD COLUMN IF NOT EXISTS assigned_department VARCHAR(40) NOT NULL DEFAULT ''" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE customer_subscriptions ADD COLUMN IF NOT EXISTS assigned_user_id INT DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "CREATE TABLE IF NOT EXISTS subscription_notes (id INT AUTO_INCREMENT PRIMARY KEY, subscription_id INT NOT NULL, department VARCHAR(40) NOT NULL DEFAULT '', author_user_id INT DEFAULT NULL, author_name VARCHAR(120) NOT NULL DEFAULT '', note TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_sub (subscription_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" 2>/dev/null || true
# stripe_events — audit + idempotency table for the /stripe-webhook.php endpoint
mysql -uroot ucode_store -e "CREATE TABLE IF NOT EXISTS stripe_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id   VARCHAR(80) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    payload    LONGTEXT,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_id (event_id),
    KEY idx_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" 2>/dev/null || true

# Visitor analytics — one row per public page view from a real human (bots/admin skipped at the PHP layer).
mysql -uroot ucode_store -e "CREATE TABLE IF NOT EXISTS visitor_log (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL DEFAULT '',
    ip_hash VARCHAR(64) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    os VARCHAR(40) NOT NULL DEFAULT 'Unknown',
    browser VARCHAR(40) NOT NULL DEFAULT 'Unknown',
    device VARCHAR(20) NOT NULL DEFAULT 'Desktop',
    country VARCHAR(8) NOT NULL DEFAULT '',
    page_url VARCHAR(255) NOT NULL DEFAULT '',
    referer VARCHAR(255) NOT NULL DEFAULT '',
    visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_visited (visited_at),
    KEY idx_session (session_id),
    KEY idx_os (os),
    KEY idx_device (device)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" 2>/dev/null || true

# Enable the Europe (EU/EUR) storefront. Seeded active on fresh pods via
# database.sql; this keeps it on for any pod created before that change.
mysql -uroot ucode_store -e "UPDATE regions SET active=1 WHERE code='EU'" 2>/dev/null || true

# Self-host subscription plan icons. They were originally seeded with Emergent
# build-CDN URLs (static.prod-images.emergentagent.com) which are not reliable
# on a customer's production domain. Point them at the bundled local images so
# plan images never break. Idempotent — only rewrites the stale CDN value.
mysql -uroot ucode_store -e "UPDATE subscription_plans SET icon_image=CONCAT('/assets/images/subscriptions/', slug, '.png') WHERE icon_image LIKE '%static.prod-images.emergentagent.com%' OR icon_image='' OR icon_image IS NULL" 2>/dev/null || true


# 3) Export integration keys from .env files (preview convenience)
# Load /app/php-version/.env first (PHP-store-specific secrets like Emergent
# LLM key, Stripe, Resend), then /app/backend/.env (only kept for legacy
# MongoDB defaults — protected variables MUST NOT be removed).
for ENVF in /app/php-version/.env /app/backend/.env; do
  if [ -f "$ENVF" ]; then
    while IFS='=' read -r K V; do
      # Skip comments + empty lines
      [ -z "$K" ] && continue
      case "$K" in \#*) continue;; esac
      # Strip surrounding quotes
      V=$(echo "$V" | sed 's/^"//; s/"$//')
      export "$K=$V"
    done < "$ENVF"
  fi
done

# Tighten permissions on /app/php-version/.env so only the running user can read it.
chmod 600 /app/php-version/.env 2>/dev/null || true

# 4) Background heartbeat — pings /cron.php every hour so the AI Auto-Blogger
# runs daily even with zero traffic on the site. The 24 h cooldown inside
# seo_bot_run_if_due() guarantees only one fresh blog post per day no matter
# how many heartbeats hit.
(
  # Give the PHP server a moment to come up before the first ping.
  sleep 30
  # Read the cron token once (auto-generated on first cron.php access).
  # We hit /cron.php once with an empty token so the token is created, then
  # read it from the settings table and use it for subsequent pings.
  curl -s "http://127.0.0.1:3000/cron.php?token=bootstrap" >/dev/null 2>&1 || true
  while true; do
    TOKEN=$(mysql -uroot ucode_store -N -B -e "SELECT v FROM settings WHERE k='cron_token' LIMIT 1" 2>/dev/null)
    if [ -n "$TOKEN" ]; then
      curl -s --max-time 90 "http://127.0.0.1:3000/cron.php?token=$TOKEN" >>/tmp/seo-heartbeat.log 2>&1 || true
    fi
    sleep 3600   # 1 hour between heartbeats
  done
) &

# 5) Ensure every product image has a .jpg sibling (email clients that
#    don't render WebP fall back to the JPG — keeps images from breaking).
php /app/php-version/scripts/ensure-image-fallbacks.php >>/tmp/image-fallbacks.log 2>&1 || true

# 6) Serve the PHP store on port 3000
exec env PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:3000 -t /app/php-version /app/php-version/router.php
