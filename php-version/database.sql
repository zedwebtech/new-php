-- ===========================================================================
-- Maventech — Admin Panel database schema + seed data
-- ===========================================================================
-- Auto-generated from the live MariaDB schema.  Idempotent — safe to re-run.
-- All tables use CREATE TABLE IF NOT EXISTS; all seed rows use INSERT IGNORE.
-- 
-- HOW TO INSTALL ON PRODUCTION (cPanel / shared MySQL):
--   1) Create an empty database (e.g. `ucode_store`) and a DB user.
--   2) phpMyAdmin → Import → upload THIS file.  That's it.
--   3) Edit /config.php (or set env vars) to point to that database.
-- 
-- If you ever upgrade the codebase, just re-import this file — it never
-- drops or overwrites existing data thanks to the IF NOT EXISTS / INSERT
-- IGNORE pattern.  Any extra columns / tables added by a newer release
-- will also be auto-applied by ensure_db_schema() in includes/functions.php
-- on the first page load after the upload.
-- ===========================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `admin_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(40) NOT NULL DEFAULT 'info',
  `title` varchar(180) NOT NULL,
  `body` varchar(400) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_read` (`read_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ai_citations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `engine` varchar(40) NOT NULL,
  `model` varchar(60) DEFAULT NULL,
  `query` text NOT NULL,
  `response` mediumtext NOT NULL,
  `mentions_brand` tinyint(1) NOT NULL DEFAULT 0,
  `mentions_url` tinyint(1) NOT NULL DEFAULT 0,
  `product_count` int(11) NOT NULL DEFAULT 0,
  `cited_urls_json` text DEFAULT NULL,
  `tokens_in` int(11) DEFAULT NULL,
  `tokens_out` int(11) DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `ran_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_engine_time` (`engine`,`ran_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `date` varchar(50) NOT NULL,
  `read_time` varchar(20) NOT NULL,
  `image` varchar(500) DEFAULT NULL,
  `content` mediumtext NOT NULL,
  `ai_generated` tinyint(1) NOT NULL DEFAULT 0,
  `product_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `target_region` varchar(4) NOT NULL DEFAULT 'US',
  `indexnow_status` varchar(20) NOT NULL DEFAULT '',
  `verified_http` smallint(6) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `internal_links_count` int(11) NOT NULL DEFAULT 0,
  `content_fingerprint` varchar(64) NOT NULL DEFAULT '',
  `is_featured_trends` tinyint(1) NOT NULL DEFAULT 0,
  `faq_json` mediumtext DEFAULT NULL,
  `lead` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_featured_trends` (`is_featured_trends`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `categories` (
  `slug` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category_group` varchar(24) NOT NULL DEFAULT 'standalone',
  `nav_heading` varchar(48) NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT 100,
  PRIMARY KEY (`slug`),
  KEY `idx_cat_group` (`category_group`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `chat_leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `callback_requested` tinyint(1) NOT NULL DEFAULT 0,
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('new','contacted','qualified','converted','lost') NOT NULL DEFAULT 'new',
  `assigned_to` int(11) DEFAULT NULL,
  `requested_product` varchar(120) DEFAULT NULL,
  `country` varchar(8) DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  `chat_token` varchar(40) NOT NULL DEFAULT '',
  `typing_admin_at` datetime DEFAULT NULL,
  `typing_customer_at` datetime DEFAULT NULL,
  `admin_notified_at` datetime DEFAULT NULL,
  `admin_seen_at` datetime DEFAULT NULL,
  `agent_name` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `sender` enum('customer','admin') NOT NULL DEFAULT 'customer',
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  `attachment_url` varchar(500) DEFAULT NULL,
  `attachment_type` varchar(20) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_sent` (`sent_at`),
  KEY `idx_unread` (`sender`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `customer_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) DEFAULT NULL,
  `product_slug` varchar(80) DEFAULT NULL,
  `customer_email` varchar(180) NOT NULL,
  `customer_name` varchar(120) NOT NULL,
  `rating` tinyint(4) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `ai_generated` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('pending','published','hidden') NOT NULL DEFAULT 'pending',
  `request_token` varchar(64) NOT NULL,
  `request_sent_at` timestamp NULL DEFAULT current_timestamp(),
  `submitted_at` timestamp NULL DEFAULT NULL,
  `region` varchar(8) NOT NULL DEFAULT 'US',
  `admin_seen_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_token` (`request_token`),
  KEY `order_id` (`order_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `customer_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` varchar(32) NOT NULL DEFAULT '',
  `order_id` int(11) DEFAULT NULL,
  `order_number` varchar(40) NOT NULL DEFAULT '',
  `plan_slug` varchar(64) NOT NULL DEFAULT '',
  `plan_name` varchar(120) NOT NULL DEFAULT '',
  `customer_name` varchar(160) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL DEFAULT '',
  `phone` varchar(48) NOT NULL DEFAULT '',
  `country` varchar(8) NOT NULL DEFAULT 'US',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(8) NOT NULL DEFAULT 'USD',
  `gateway` varchar(20) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_department` varchar(40) NOT NULL DEFAULT '',
  `assigned_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_plan` (`plan_slug`),
  KEY `idx_cust` (`customer_id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `dmca_findings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` varchar(100) NOT NULL,
  `suspected_url` varchar(500) NOT NULL,
  `suspected_host` varchar(200) DEFAULT NULL,
  `confidence` varchar(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','dismissed','reported','taken_down') NOT NULL DEFAULT 'pending',
  `scanned_with` varchar(60) DEFAULT NULL,
  `ran_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post` (`post_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `email_outbox` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `html` mediumtext NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'queued',
  `note` varchar(255) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `delivered_at` datetime DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `opened_count` int(11) NOT NULL DEFAULT 0,
  `tracking_token` varchar(64) DEFAULT NULL,
  `provider_id` varchar(120) DEFAULT NULL,
  `clicked_at` datetime DEFAULT NULL,
  `click_count` int(11) NOT NULL DEFAULT 0,
  `template_code` varchar(60) DEFAULT NULL,
  `attachments_json` text DEFAULT NULL,
  `retry_count` int(11) NOT NULL DEFAULT 0,
  `max_retries` int(11) NOT NULL DEFAULT 3,
  `next_retry_at` timestamp NULL DEFAULT NULL,
  `error_details` text DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `message_id` varchar(190) DEFAULT NULL,
  `bounced_at` timestamp NULL DEFAULT NULL,
  `priority` tinyint(4) NOT NULL DEFAULT 5,
  PRIMARY KEY (`id`),
  KEY `idx_outbox_status_retry` (`status`,`next_retry_at`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `email_template_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `version_num` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `html` mediumtext NOT NULL,
  `edited_by_email` varchar(120) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `email_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `html` mediumtext NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `current_version` int(11) NOT NULL DEFAULT 1,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `faqs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `gsc_queries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `query` varchar(255) NOT NULL,
  `impressions` int(11) NOT NULL DEFAULT 0,
  `clicks` int(11) NOT NULL DEFAULT 0,
  `ctr` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `position` decimal(8,2) NOT NULL DEFAULT 0.00,
  `cluster_key` varchar(120) NOT NULL DEFAULT '',
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_query` (`query`),
  KEY `idx_cluster` (`cluster_key`),
  KEY `idx_impr` (`impressions`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `lead_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `note` text NOT NULL,
  `author_name` varchar(120) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `license_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_slug` varchar(191) NOT NULL,
  `license_key` varchar(120) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'available',
  `order_id` int(11) DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `region` varchar(8) NOT NULL DEFAULT 'US',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_slug` varchar(191) NOT NULL,
  `name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(20) NOT NULL,
  `email` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `address2` varchar(255) DEFAULT NULL,
  `country` varchar(5) NOT NULL DEFAULT 'US',
  `city` varchar(100) NOT NULL,
  `state` varchar(100) NOT NULL,
  `zip` varchar(20) NOT NULL,
  `payment_method` varchar(20) NOT NULL DEFAULT 'card',
  `currency` varchar(5) NOT NULL DEFAULT 'USD',
  `subtotal` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `pro_assist` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `gw_mode` varchar(10) NOT NULL DEFAULT 'test',
  `fulfilled` tinyint(1) NOT NULL DEFAULT 0,
  `delivery_status` varchar(20) NOT NULL DEFAULT 'delivered',
  `stripe_session_id` varchar(120) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `card_statement_name` varchar(120) DEFAULT NULL,
  `region` varchar(8) NOT NULL DEFAULT 'US',
  `ip_address` varchar(45) DEFAULT NULL,
  `card_brand` varchar(30) DEFAULT NULL,
  `card_type` varchar(20) DEFAULT NULL,
  `billing_country` varchar(8) DEFAULT NULL,
  `timeline` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`timeline`)),
  `card_last4` varchar(4) DEFAULT NULL,
  `card_exp` varchar(7) DEFAULT NULL,
  `card_country` varchar(8) DEFAULT NULL,
  `card_funding` varchar(20) DEFAULT NULL,
  `paypal_funding_source` varchar(40) DEFAULT NULL,
  `paypal_payer_email` varchar(180) DEFAULT NULL,
  `paypal_payer_id` varchar(60) DEFAULT NULL,
  `paypal_funding_card_brand` varchar(30) DEFAULT NULL,
  `paypal_funding_card_last4` varchar(4) DEFAULT NULL,
  `paypal_funding_bank_name` varchar(60) DEFAULT NULL,
  `transaction_id` varchar(120) DEFAULT NULL,
  `subscription_plan` varchar(64) DEFAULT NULL,
  `risk_score` smallint(6) DEFAULT NULL,
  `risk_level` varchar(20) NOT NULL DEFAULT '',
  `company_name` varchar(120) NOT NULL DEFAULT '',
  `payment_intent_id` varchar(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `pages` (
  `slug` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `updated` varchar(50) DEFAULT NULL,
  `content` mediumtext NOT NULL,
  PRIMARY KEY (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(128) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_token` (`token_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `proassist_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `order_number` varchar(40) NOT NULL DEFAULT '',
  `customer_name` varchar(120) NOT NULL DEFAULT '',
  `customer_email` varchar(160) NOT NULL DEFAULT '',
  `customer_phone` varchar(40) NOT NULL DEFAULT '',
  `scheduled_at` datetime NOT NULL,
  `scheduled_utc` datetime NOT NULL,
  `tz` varchar(40) NOT NULL DEFAULT 'America/New_York',
  `status` enum('pending','confirmed','done','missed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lead` (`lead_id`),
  KEY `idx_sched_utc` (`scheduled_utc`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `product_ai_chats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_slug` varchar(160) NOT NULL,
  `product_name` varchar(255) NOT NULL DEFAULT '',
  `session_id` varchar(64) NOT NULL DEFAULT '',
  `question` text NOT NULL,
  `answer` mediumtext DEFAULT NULL,
  `tokens_in` int(11) DEFAULT NULL,
  `tokens_out` int(11) DEFAULT NULL,
  `ms_latency` int(11) DEFAULT NULL,
  `helpful` tinyint(1) DEFAULT NULL,
  `user_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_slug_time` (`product_slug`,`created_at`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(191) NOT NULL,
  `name` varchar(255) NOT NULL,
  `platform` varchar(20) NOT NULL DEFAULT 'Windows',
  `category` varchar(50) NOT NULL,
  `badge` varchar(50) DEFAULT NULL,
  `is_new` tinyint(1) NOT NULL DEFAULT 0,
  `price` decimal(10,2) NOT NULL,
  `original_price` decimal(10,2) DEFAULT NULL,
  `rating` decimal(2,1) NOT NULL DEFAULT 4.5,
  `reviews` int(11) NOT NULL DEFAULT 0,
  `image` varchar(500) DEFAULT NULL,
  `apps` varchar(255) NOT NULL DEFAULT '',
  `region` varchar(8) NOT NULL DEFAULT 'US',
  `sku` varchar(80) DEFAULT NULL,
  `gtin` varchar(20) DEFAULT NULL,
  `year` int(11) DEFAULT NULL,
  `license_type` varchar(30) NOT NULL DEFAULT 'lifetime',
  `version` varchar(60) DEFAULT NULL,
  `brand` varchar(60) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `meta_description` varchar(180) DEFAULT NULL,
  `seo_refreshed_at` datetime DEFAULT NULL,
  `ai_summary` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `activation_url_mode` varchar(10) NOT NULL DEFAULT 'ai',
  `install_url_mode` varchar(10) NOT NULL DEFAULT 'ai',
  `activation_url` varchar(500) DEFAULT NULL,
  `install_guide_url` varchar(500) DEFAULT NULL,
  `installer_url` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `refund_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(40) NOT NULL,
  `email` varchar(190) NOT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `regional_pricing` (
  `product_slug` varchar(80) NOT NULL,
  `region` varchar(8) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `original_price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`product_slug`,`region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `regions` (
  `code` varchar(8) NOT NULL,
  `name` varchar(60) NOT NULL,
  `currency` varchar(8) NOT NULL,
  `currency_symbol` varchar(4) NOT NULL,
  `tax_rate` decimal(5,4) NOT NULL DEFAULT 0.0000,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `initials` varchar(4) NOT NULL,
  `location` varchar(60) DEFAULT '',
  `review_date` date NOT NULL,
  `rating` tinyint(4) NOT NULL,
  `text` text NOT NULL,
  `product` varchar(120) DEFAULT '',
  `verified` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `seo_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `indexnow_status` varchar(20) DEFAULT NULL,
  `indexnow_count` int(11) DEFAULT NULL,
  `google_ping` varchar(20) DEFAULT NULL,
  `bing_ping` varchar(20) DEFAULT NULL,
  `wayback_status` varchar(20) DEFAULT NULL,
  `wayback_count` int(11) DEFAULT NULL,
  `llm_calls` int(11) DEFAULT NULL,
  `llm_tokens_in` int(11) DEFAULT NULL,
  `llm_tokens_out` int(11) DEFAULT NULL,
  `products_updated` int(11) DEFAULT NULL,
  `blog_post_id` varchar(100) DEFAULT NULL,
  `blog_post_title` varchar(255) DEFAULT NULL,
  `blog_product_id` int(11) DEFAULT NULL,
  `blog_post_image` varchar(500) DEFAULT NULL,
  `errors_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `settings` (
  `k` varchar(80) NOT NULL,
  `v` mediumtext NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `stock_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_slug` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `region` varchar(8) NOT NULL DEFAULT 'US',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pending` (`product_slug`,`region`,`notified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `stripe_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` varchar(80) NOT NULL,
  `event_type` varchar(80) NOT NULL,
  `payload` longtext DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_id` (`event_id`),
  KEY `idx_event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `subscription_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subscription_id` int(11) NOT NULL,
  `department` varchar(40) NOT NULL DEFAULT '',
  `author_user_id` int(11) DEFAULT NULL,
  `author_name` varchar(120) NOT NULL DEFAULT '',
  `note` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sub` (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `name` varchar(120) NOT NULL,
  `tagline` varchar(255) NOT NULL DEFAULT '',
  `tenure_label` varchar(64) NOT NULL DEFAULT '',
  `duration_months` int(11) NOT NULL DEFAULT 0,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `devices` varchar(40) NOT NULL DEFAULT '',
  `features_json` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `icon_image` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `support_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(40) DEFAULT '',
  `order_number` varchar(40) DEFAULT '',
  `subject` varchar(190) NOT NULL,
  `message` text NOT NULL,
  `source` varchar(20) DEFAULT 'contact',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `testimonials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `initials` varchar(5) NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `product` varchar(100) DEFAULT NULL,
  `text` text NOT NULL,
  `rating` int(11) NOT NULL DEFAULT 5,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `topic_hubs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(120) NOT NULL,
  `title` varchar(255) NOT NULL,
  `headline` text NOT NULL,
  `audience` varchar(255) NOT NULL DEFAULT '',
  `categories_json` text NOT NULL,
  `blog_tags_json` text NOT NULL,
  `keywords` text NOT NULL,
  `about_link` varchar(255) NOT NULL DEFAULT '',
  `color` varchar(20) NOT NULL DEFAULT '#0078d4',
  `videos_json` text NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `source` varchar(16) NOT NULL DEFAULT 'manual',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `transaction_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gateway` varchar(20) NOT NULL,
  `transaction_id` varchar(120) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(8) NOT NULL DEFAULT 'USD',
  `status` varchar(40) NOT NULL,
  `raw_response` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `gateway` (`gateway`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'customer',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `username` varchar(60) DEFAULT NULL,
  `department` varchar(40) NOT NULL DEFAULT '',
  `permissions` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `vibe_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vibe` varchar(20) NOT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'manual',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `vibe_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vibe` varchar(20) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime DEFAULT NULL,
  `label` varchar(120) NOT NULL DEFAULT '',
  `logo_path` varchar(255) NOT NULL DEFAULT '',
  `coupon_code` varchar(40) NOT NULL DEFAULT '',
  `coupon_percent` int(11) NOT NULL DEFAULT 0,
  `applied_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_starts` (`starts_at`),
  KEY `idx_ends` (`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `visitor_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL DEFAULT '',
  `ip_hash` varchar(64) NOT NULL DEFAULT '',
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `os` varchar(40) NOT NULL DEFAULT 'Unknown',
  `browser` varchar(40) NOT NULL DEFAULT 'Unknown',
  `device` varchar(20) NOT NULL DEFAULT 'Desktop',
  `country` varchar(8) NOT NULL DEFAULT '',
  `page_url` varchar(255) NOT NULL DEFAULT '',
  `referer` varchar(255) NOT NULL DEFAULT '',
  `visited_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_visited` (`visited_at`),
  KEY `idx_session` (`session_id`),
  KEY `idx_os` (`os`),
  KEY `idx_device` (`device`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- ===================== SEED DATA =====================
/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('antivirus','Antivirus','antivirus','ANTIVIRUS',99);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('apps','Microsoft Apps','microsoft','APPS',99);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('bitdefender','Bitdefender','antivirus','ANTIVIRUS',10);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('mcafee','McAfee','antivirus','ANTIVIRUS',20);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('microsoft-project','Microsoft Project','microsoft','APPS',10);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('microsoft-visio','Microsoft Visio','microsoft','APPS',20);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('office','Microsoft Office','microsoft','OFFICE FOR PC',99);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('office-2019-mac','Office 2019 for Mac','microsoft','OFFICE FOR MAC',30);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('office-2019-pc','Office 2019 for PC','microsoft','OFFICE FOR PC',30);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('office-2021-mac','Office 2021 for Mac','microsoft','OFFICE FOR MAC',20);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('office-2021-pc','Office 2021 for PC','microsoft','OFFICE FOR PC',20);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('office-2024-mac','Office 2024 for Mac','microsoft','OFFICE FOR MAC',10);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('office-2024-pc','Office 2024 for PC','microsoft','OFFICE FOR PC',10);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('office-mac','Office for Mac','microsoft','OFFICE FOR MAC',99);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('office-pc','Office for PC','microsoft','OFFICE FOR PC',99);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('windows','Windows OS','microsoft','WINDOWS',99);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('windows-10','Windows 10','microsoft','WINDOWS',20);
INSERT IGNORE INTO `categories` (`slug`, `name`, `category_group`, `nav_heading`, `sort_order`) VALUES ('windows-11','Windows 11','microsoft','WINDOWS',10);
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `chat_leads` WRITE;
/*!40000 ALTER TABLE `chat_leads` DISABLE KEYS */;
INSERT IGNORE INTO `chat_leads` (`id`, `session_id`, `name`, `email`, `phone`, `callback_requested`, `message`, `created_at`, `status`, `assigned_to`, `requested_product`, `country`, `last_seen`, `chat_token`, `typing_admin_at`, `typing_customer_at`, `admin_notified_at`, `admin_seen_at`, `agent_name`) VALUES (1,'demo-sess-1','Alex Carter','alex@example.com','+44 20 7946 0123',1,'I need 50 license keys for our office. Please call back.','2026-06-12 10:46:40','lost',NULL,'Office 2024 Pro Plus','UK',NULL,'',NULL,NULL,NULL,NULL,NULL);
INSERT IGNORE INTO `chat_leads` (`id`, `session_id`, `name`, `email`, `phone`, `callback_requested`, `message`, `created_at`, `status`, `assigned_to`, `requested_product`, `country`, `last_seen`, `chat_token`, `typing_admin_at`, `typing_customer_at`, `admin_notified_at`, `admin_seen_at`, `agent_name`) VALUES (2,'demo-sess-2','Priya Sharma','priya@example.in','+91 98765 43210',1,'Interested in bulk Windows licenses','2026-06-12 10:46:40','contacted',NULL,'Windows 11 Pro','IN',NULL,'',NULL,NULL,NULL,NULL,NULL);
INSERT IGNORE INTO `chat_leads` (`id`, `session_id`, `name`, `email`, `phone`, `callback_requested`, `message`, `created_at`, `status`, `assigned_to`, `requested_product`, `country`, `last_seen`, `chat_token`, `typing_admin_at`, `typing_customer_at`, `admin_notified_at`, `admin_seen_at`, `agent_name`) VALUES (3,'demo-sess-3','Mike Anderson',NULL,NULL,0,'Spoke yesterday — sending quote','2026-06-12 10:46:40','qualified',NULL,'Bitdefender Premium','CA',NULL,'',NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `chat_leads` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `customer_reviews` WRITE;
/*!40000 ALTER TABLE `customer_reviews` DISABLE KEYS */;
INSERT IGNORE INTO `customer_reviews` (`id`, `order_id`, `product_slug`, `customer_email`, `customer_name`, `rating`, `comment`, `ai_generated`, `status`, `request_token`, `request_sent_at`, `submitted_at`, `region`, `admin_seen_at`) VALUES (7,4,'microsoft-office-2024-professional-plus-windows','test@example.com','Test Buyer',NULL,NULL,0,'pending','819b03952ff13e84049aaca17edd5b93','2026-06-23 10:44:06',NULL,'US',NULL);
/*!40000 ALTER TABLE `customer_reviews` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `email_outbox` WRITE;
/*!40000 ALTER TABLE `email_outbox` DISABLE KEYS */;
INSERT IGNORE INTO `email_outbox` (`id`, `recipient`, `subject`, `html`, `status`, `note`, `order_id`, `created_at`, `delivered_at`, `opened_at`, `opened_count`, `tracking_token`, `provider_id`, `clicked_at`, `click_count`, `template_code`, `attachments_json`, `retry_count`, `max_retries`, `next_retry_at`, `error_details`, `last_error`, `message_id`, `bounced_at`, `priority`) VALUES (1,'jane.demo@example.com','Your Office 2024 License Key - Order MVT-DEMO-001','<h2>Thank you for your purchase</h2><p>Your license key: <code>OFFICE-2024-AAAA1-BBBB1-CCCC1</code></p>','sent',NULL,1,'2026-06-12 09:53:07','2026-06-12 09:53:07',NULL,0,NULL,NULL,NULL,0,NULL,NULL,0,3,NULL,NULL,NULL,NULL,NULL,5);
INSERT IGNORE INTO `email_outbox` (`id`, `recipient`, `subject`, `html`, `status`, `note`, `order_id`, `created_at`, `delivered_at`, `opened_at`, `opened_count`, `tracking_token`, `provider_id`, `clicked_at`, `click_count`, `template_code`, `attachments_json`, `retry_count`, `max_retries`, `next_retry_at`, `error_details`, `last_error`, `message_id`, `bounced_at`, `priority`) VALUES (11,'john.demo@example.com','Your Microsoft product key — Order #MVT-DEMO-002','<!doctype html><html><body style=\"margin:0;padding:0;background:#fbfcfd;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;\">\n<div style=\"position:relative;max-width:640px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);\">\n  <!-- Watermark Microsoft icon -->\n  <div style=\"position:absolute;top:80px;right:-40px;opacity:.05;pointer-events:none;\">\n    <svg width=\"320\" height=\"320\" viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\">\n      <rect x=\"2\"  y=\"2\"  width=\"9\" height=\"9\" fill=\"#F35325\"/>\n      <rect x=\"13\" y=\"2\"  width=\"9\" height=\"9\" fill=\"#81BC06\"/>\n      <rect x=\"2\"  y=\"13\" width=\"9\" height=\"9\" fill=\"#05A6F0\"/>\n      <rect x=\"13\" y=\"13\" width=\"9\" height=\"9\" fill=\"#FFBA08\"/>\n    </svg>\n  </div>\n  <div style=\"background:#ffffff;padding:26px 32px;border-bottom:1px solid #f1f3f5;display:flex;align-items:center;justify-content:space-between;\">\n    <div>\n      <div style=\"font-size:20px;font-weight:800;color:#0f172a;letter-spacing:.3px;\">Maventech</div>\n      <div style=\"font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;\">AUTHORIZED MICROSOFT RESELLER</div>\n    </div>\n    <span style=\"font-size:11px;color:#10b981;font-weight:700;background:#d1fae5;padding:6px 12px;border-radius:999px;\">&#10003; ORDER CONFIRMED</span>\n  </div>\n\n  <div style=\"padding:30px 32px;position:relative;\">\n    <h1 style=\"margin:0 0 6px;font-size:22px;color:#0f172a;font-weight:700;\">Thank you for your purchase, John!</h1>\n    <p style=\"margin:0 0 22px;font-size:14px;color:#475569;line-height:1.6;\">Your payment was received and your genuine license key is ready to use.</p>\n\n    <table width=\"100%\" style=\"border-collapse:separate;border-spacing:0;background:#f8fafc;border-radius:12px;margin-bottom:22px;font-size:13px;color:#475569;\">\n      <tr>\n        <td style=\"padding:14px 18px;\">Order #<br><strong style=\"color:#0f172a;font-size:15px;\">MVT-DEMO-002</strong></td>\n        <td style=\"padding:14px 18px;\">Amount Paid<br><strong style=\"color:#0f172a;font-size:15px;\">$129.99</strong></td>\n        <td style=\"padding:14px 18px;\">Delivered to<br><strong style=\"color:#0f172a;font-size:13px;\">john.demo@example.com</strong></td>\n      </tr>\n    </table>\n\n    <table width=\"100%\" style=\"border:1px solid #eef0f3;border-radius:12px;margin-bottom:14px;background:#fff;\"><tr><td style=\"padding:14px;\">\n            <table width=\"100%\"><tr><td width=\"80\" valign=\"top\"><img src=\"/uploads/products/microsoft-office-home-business-2024-pc.webp\" width=\"68\" height=\"68\" alt=\"\" style=\"border-radius:8px;background:#f8fafc;object-fit:contain;\"></td>\n            <td valign=\"top\" style=\"padding-left:10px;\">\n              <div style=\"font-size:15px;font-weight:bold;color:#0f172a;\">Microsoft Office Home &amp; Business 2024 (PC)</div>\n              <div style=\"font-size:12px;color:#94a3b8;margin-top:2px;\"></div>\n            </td></tr></table><div style=\"margin-top:10px;border:2px dashed #3b82f6;border-radius:10px;background:#eff6ff;padding:12px 14px;text-align:center;\">\n                 <div style=\"font-size:10px;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px;font-weight:600;\">License Key</div>\n                 <div style=\"font-family:\'Courier New\',monospace;font-size:17px;font-weight:bold;color:#1d4ed8;letter-spacing:1.8px;\">OFC24-HB-AAAA5-BBBB5-CCCC5</div></div><div style=\"margin-top:12px;text-align:center;\"><a href=\"https://setup.office.com\" style=\"display:inline-block;margin:4px 6px;padding:11px 22px;background:linear-gradient(135deg,#10b981,#047857);color:#ffffff;border-radius:999px;text-decoration:none;font-weight:700;font-size:13px;letter-spacing:.3px;\">&#128274; Sign in to activate &rarr;</a><a href=\"https://support.microsoft.com/office/install\" style=\"display:inline-block;margin:4px 6px;padding:11px 22px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#ffffff;border-radius:999px;text-decoration:none;font-weight:700;font-size:13px;letter-spacing:.3px;\">&#128214; View installation guide &rarr;</a><div style=\"font-size:11px;color:#94a3b8;margin-top:6px;\">Activate above &middot; step-by-step setup in the guide.</div></div></td></tr></table>\n\n    <h2 style=\"font-size:15px;color:#0f172a;margin:24px 0 10px;\">Installation Guide</h2>\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin:0;\">\n      <tr><td style=\"padding:10px 14px;background:#f0f9ff;border-radius:10px;border:1px solid #bfdbfe;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">1</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128229; Download Installer</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">Visit the official site to download the installer for your product.</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n      <tr><td style=\"height:8px;\"></td></tr>\n      <tr><td style=\"padding:10px 14px;background:#fff7ed;border-radius:10px;border:1px solid #fed7aa;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">2</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128190; Install &amp; Sign-in</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">Run the installer and sign in with a Microsoft Account (or create one).</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n      <tr><td style=\"height:8px;\"></td></tr>\n      <tr><td style=\"padding:10px 14px;background:#ecfdf5;border-radius:10px;border:1px solid #a7f3d0;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">3</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128273; Activate</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">Enter the license key shown above and click Activate.</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n      <tr><td style=\"height:8px;\"></td></tr>\n      <tr><td style=\"padding:10px 14px;background:#fef2f2;border-radius:10px;border:1px solid #fecaca;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">4</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128295; Troubleshooting</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">If activation fails: check internet connection, sign out and back in, then use the <strong>Sign in to activate</strong> button above to open the official page. Still stuck? Contact support below.</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n    </table>\n    <div style=\"margin-top:14px;background:#f8fafc;border-radius:10px;padding:12px 14px;font-size:12.5px;color:#475569;line-height:1.7;\">\n      <strong style=\"color:#0f172a;\">Product-specific notes:</strong><br><strong>Microsoft Office Home &amp; Business 2024 (PC):</strong> word,excel,powerpoint,outlook\n    </div>\n\n    <div style=\"margin-top:22px;border-top:1px solid #f1f3f5;padding-top:16px;font-size:12px;color:#64748b;line-height:1.7;\">\n      <strong style=\"color:#0f172a;\">Billing note:</strong> this charge appears as <strong>MAVENTECH CO LLC</strong> on your card statement.\n    </div>\n  </div>\n\n  <div style=\"background:#f8fafc;padding:20px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;\">\n    <strong style=\"color:#0f172a;\">Need help?</strong> <a href=\"mailto:services@maventechsoftware.com\" style=\"color:#3b82f6;text-decoration:none;\">services@maventechsoftware.com</a> &middot; 1-888-632-9902<br>\n    <span style=\"font-size:11px;color:#94a3b8;\">&copy; 2026 Maventech. All rights reserved.</span>\n  </div>\n</div>\n<img src=\"https://robot-rules.preview.emergentagent.com/track-open.php?t=b65dc78c56092750edfd23c2bfd64b13\" width=\"1\" height=\"1\" alt=\"\" style=\"display:block;width:1px;height:1px;border:0;\">\n</body></html>','sent',NULL,2,'2026-06-12 13:00:48','2026-06-12 13:00:48',NULL,0,'c3f60886768fe4d7ecf743a02b9b1f71',NULL,NULL,0,'order_delivery',NULL,0,3,NULL,NULL,NULL,NULL,NULL,5);
INSERT IGNORE INTO `email_outbox` (`id`, `recipient`, `subject`, `html`, `status`, `note`, `order_id`, `created_at`, `delivered_at`, `opened_at`, `opened_count`, `tracking_token`, `provider_id`, `clicked_at`, `click_count`, `template_code`, `attachments_json`, `retry_count`, `max_retries`, `next_retry_at`, `error_details`, `last_error`, `message_id`, `bounced_at`, `priority`) VALUES (12,'john.demo@example.com','How was your Microsoft Office Home & Business 2024 (PC)? · Quick 30-second review','<!doctype html><html><body style=\"font-family:Arial,sans-serif;background:#f8fafc;padding:30px;\">\n          <div style=\"max-width:580px;margin:0 auto;background:#fff;border-radius:14px;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,.05);\">\n            <div style=\"text-align:center;font-size:18px;font-weight:800;color:#0f172a;\">Maventech</div>\n            <h2 style=\"color:#0f172a;text-align:center;margin-top:16px;\">How was your purchase, John?</h2>\n            <p style=\"color:#64748b;text-align:center;\">We hope <strong>Microsoft Office Home &amp; Business 2024 (PC)</strong> is working great. Would you take 30 seconds to share your experience?</p>\n            <div style=\"text-align:center;margin:24px 0;\">\n              <div style=\"font-size:32px;letter-spacing:6px;\">★★★★★</div>\n              <a href=\"https://robot-rules.preview.emergentagent.com/review.php?t=e15f3511172a294be5ecc77dbaad644e\" style=\"display:inline-block;margin-top:18px;padding:12px 32px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;\">Leave a Review</a>\n            </div>\n            <p style=\"font-size:12px;color:#94a3b8;text-align:center;\">Includes an AI-assist option to help write your comment based on your rating. Thank you!</p>\n          </div></body></html><img src=\"https://robot-rules.preview.emergentagent.com/track-open.php?t=3da5a8c71804365439609b936d4e4af0\" width=\"1\" height=\"1\" alt=\"\">','sent',NULL,2,'2026-06-12 13:00:48','2026-06-12 13:00:48',NULL,0,'3da5a8c71804365439609b936d4e4af0',NULL,NULL,0,'review_request',NULL,0,3,NULL,NULL,NULL,NULL,NULL,5);
INSERT IGNORE INTO `email_outbox` (`id`, `recipient`, `subject`, `html`, `status`, `note`, `order_id`, `created_at`, `delivered_at`, `opened_at`, `opened_count`, `tracking_token`, `provider_id`, `clicked_at`, `click_count`, `template_code`, `attachments_json`, `retry_count`, `max_retries`, `next_retry_at`, `error_details`, `last_error`, `message_id`, `bounced_at`, `priority`) VALUES (13,'john.demo@example.com','Your Microsoft product key — Order #MVT-DEMO-002','<!doctype html><html><body style=\"margin:0;padding:0;background:#fbfcfd;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;\">\n<div style=\"position:relative;max-width:640px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);\">\n  <!-- Watermark Microsoft icon -->\n  <div style=\"position:absolute;top:80px;right:-40px;opacity:.05;pointer-events:none;\">\n    <svg width=\"320\" height=\"320\" viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\">\n      <rect x=\"2\"  y=\"2\"  width=\"9\" height=\"9\" fill=\"#F35325\"/>\n      <rect x=\"13\" y=\"2\"  width=\"9\" height=\"9\" fill=\"#81BC06\"/>\n      <rect x=\"2\"  y=\"13\" width=\"9\" height=\"9\" fill=\"#05A6F0\"/>\n      <rect x=\"13\" y=\"13\" width=\"9\" height=\"9\" fill=\"#FFBA08\"/>\n    </svg>\n  </div>\n  <div style=\"background:#ffffff;padding:26px 32px;border-bottom:1px solid #f1f3f5;display:flex;align-items:center;justify-content:space-between;\">\n    <div>\n      <div style=\"font-size:20px;font-weight:800;color:#0f172a;letter-spacing:.3px;\">Maventech</div>\n      <div style=\"font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;\">AUTHORIZED MICROSOFT RESELLER</div>\n    </div>\n    <span style=\"font-size:11px;color:#10b981;font-weight:700;background:#d1fae5;padding:6px 12px;border-radius:999px;\">&#10003; ORDER CONFIRMED</span>\n  </div>\n\n  <div style=\"padding:30px 32px;position:relative;\">\n    <h1 style=\"margin:0 0 6px;font-size:22px;color:#0f172a;font-weight:700;\">Thank you for your purchase, John!</h1>\n    <p style=\"margin:0 0 22px;font-size:14px;color:#475569;line-height:1.6;\">Your payment was received and your genuine license key is ready to use.</p>\n\n    <table width=\"100%\" style=\"border-collapse:separate;border-spacing:0;background:#f8fafc;border-radius:12px;margin-bottom:22px;font-size:13px;color:#475569;\">\n      <tr>\n        <td style=\"padding:14px 18px;\">Order #<br><strong style=\"color:#0f172a;font-size:15px;\">MVT-DEMO-002</strong></td>\n        <td style=\"padding:14px 18px;\">Amount Paid<br><strong style=\"color:#0f172a;font-size:15px;\">$129.99</strong></td>\n        <td style=\"padding:14px 18px;\">Delivered to<br><strong style=\"color:#0f172a;font-size:13px;\">john.demo@example.com</strong></td>\n      </tr>\n    </table>\n\n    <table width=\"100%\" style=\"border:1px solid #eef0f3;border-radius:12px;margin-bottom:14px;background:#fff;\"><tr><td style=\"padding:14px;\">\n            <table width=\"100%\"><tr><td width=\"80\" valign=\"top\"><img src=\"/uploads/products/microsoft-office-home-business-2024-pc.webp\" width=\"68\" height=\"68\" alt=\"\" style=\"border-radius:8px;background:#f8fafc;object-fit:contain;\"></td>\n            <td valign=\"top\" style=\"padding-left:10px;\">\n              <div style=\"font-size:15px;font-weight:bold;color:#0f172a;\">Microsoft Office Home &amp; Business 2024 (PC)</div>\n              <div style=\"font-size:12px;color:#94a3b8;margin-top:2px;\"></div>\n            </td></tr></table><div style=\"margin-top:10px;border:2px dashed #3b82f6;border-radius:10px;background:#eff6ff;padding:12px 14px;text-align:center;\">\n                 <div style=\"font-size:10px;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px;font-weight:600;\">License Key</div>\n                 <div style=\"font-family:\'Courier New\',monospace;font-size:17px;font-weight:bold;color:#1d4ed8;letter-spacing:1.8px;\">OFC24-HB-AAAA5-BBBB5-CCCC5</div></div><div style=\"margin-top:12px;text-align:center;\"><a href=\"https://setup.office.com\" style=\"display:inline-block;margin:4px 6px;padding:11px 22px;background:linear-gradient(135deg,#10b981,#047857);color:#ffffff;border-radius:999px;text-decoration:none;font-weight:700;font-size:13px;letter-spacing:.3px;\">&#128274; Sign in to activate &rarr;</a><a href=\"https://support.microsoft.com/office/install\" style=\"display:inline-block;margin:4px 6px;padding:11px 22px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#ffffff;border-radius:999px;text-decoration:none;font-weight:700;font-size:13px;letter-spacing:.3px;\">&#128214; View installation guide &rarr;</a><div style=\"font-size:11px;color:#94a3b8;margin-top:6px;\">Activate above &middot; step-by-step setup in the guide.</div></div></td></tr></table>\n\n    <h2 style=\"font-size:15px;color:#0f172a;margin:24px 0 10px;\">Installation Guide</h2>\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin:0;\">\n      <tr><td style=\"padding:10px 14px;background:#f0f9ff;border-radius:10px;border:1px solid #bfdbfe;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">1</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128229; Download Installer</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">Visit the official site to download the installer for your product.</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n      <tr><td style=\"height:8px;\"></td></tr>\n      <tr><td style=\"padding:10px 14px;background:#fff7ed;border-radius:10px;border:1px solid #fed7aa;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">2</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128190; Install &amp; Sign-in</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">Run the installer and sign in with a Microsoft Account (or create one).</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n      <tr><td style=\"height:8px;\"></td></tr>\n      <tr><td style=\"padding:10px 14px;background:#ecfdf5;border-radius:10px;border:1px solid #a7f3d0;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">3</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128273; Activate</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">Enter the license key shown above and click Activate.</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n      <tr><td style=\"height:8px;\"></td></tr>\n      <tr><td style=\"padding:10px 14px;background:#fef2f2;border-radius:10px;border:1px solid #fecaca;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">4</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128295; Troubleshooting</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">If activation fails: check internet connection, sign out and back in, then use the <strong>Sign in to activate</strong> button above to open the official page. Still stuck? Contact support below.</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n    </table>\n    <div style=\"margin-top:14px;background:#f8fafc;border-radius:10px;padding:12px 14px;font-size:12.5px;color:#475569;line-height:1.7;\">\n      <strong style=\"color:#0f172a;\">Product-specific notes:</strong><br><strong>Microsoft Office Home &amp; Business 2024 (PC):</strong> word,excel,powerpoint,outlook\n    </div>\n\n    <div style=\"margin-top:22px;border-top:1px solid #f1f3f5;padding-top:16px;font-size:12px;color:#64748b;line-height:1.7;\">\n      <strong style=\"color:#0f172a;\">Billing note:</strong> this charge appears as <strong>MAVENTECH CO LLC</strong> on your card statement.\n    </div>\n  </div>\n\n  <div style=\"background:#f8fafc;padding:20px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;\">\n    <strong style=\"color:#0f172a;\">Need help?</strong> <a href=\"mailto:services@maventechsoftware.com\" style=\"color:#3b82f6;text-decoration:none;\">services@maventechsoftware.com</a> &middot; 1-888-632-9902<br>\n    <span style=\"font-size:11px;color:#94a3b8;\">&copy; 2026 Maventech. All rights reserved.</span>\n  </div>\n</div>\n\n</body></html><img src=\"https://robot-rules.preview.emergentagent.com/track-open.php?t=e8b6d820bd525912861f73b1c54737ee\" width=\"1\" height=\"1\" alt=\"\">','sent',NULL,2,'2026-06-12 13:02:57','2026-06-12 13:02:57','2026-06-12 13:09:41',1,'e8b6d820bd525912861f73b1c54737ee',NULL,NULL,0,'order_delivery',NULL,0,3,NULL,NULL,NULL,NULL,NULL,5);
INSERT IGNORE INTO `email_outbox` (`id`, `recipient`, `subject`, `html`, `status`, `note`, `order_id`, `created_at`, `delivered_at`, `opened_at`, `opened_count`, `tracking_token`, `provider_id`, `clicked_at`, `click_count`, `template_code`, `attachments_json`, `retry_count`, `max_retries`, `next_retry_at`, `error_details`, `last_error`, `message_id`, `bounced_at`, `priority`) VALUES (16,'john.demo@example.com','How was your Microsoft Office Home & Business 2024 (PC)? · Quick 30-second review','<!doctype html><html><body style=\"font-family:Arial,sans-serif;background:#f8fafc;padding:30px;\">\n          <div style=\"max-width:580px;margin:0 auto;background:#fff;border-radius:14px;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,.05);\">\n            <div style=\"text-align:center;font-size:18px;font-weight:800;color:#0f172a;\">Maventech</div>\n            <h2 style=\"color:#0f172a;text-align:center;margin-top:16px;\">How was your purchase, John?</h2>\n            <p style=\"color:#64748b;text-align:center;\">We hope <strong>Microsoft Office Home &amp; Business 2024 (PC)</strong> is working great. Would you take 30 seconds to share your experience?</p>\n            <div style=\"text-align:center;margin:24px 0;\">\n              <div style=\"font-size:32px;letter-spacing:6px;\">★★★★★</div>\n              <a href=\"https://robot-rules.preview.emergentagent.com/review.php?t=e15f3511172a294be5ecc77dbaad644e\" style=\"display:inline-block;margin-top:18px;padding:12px 32px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;\">Leave a Review</a>\n            </div>\n            <p style=\"font-size:12px;color:#94a3b8;text-align:center;\">Includes an AI-assist option to help write your comment based on your rating. Thank you!</p>\n          </div></body></html><img src=\"https://robot-rules.preview.emergentagent.com/track-open.php?t=fc8ccdaaab95bec13d60c3ce73deb679\" width=\"1\" height=\"1\" alt=\"\">','opened',NULL,2,'2026-06-12 13:12:44','2026-06-12 13:12:44','2026-06-12 15:19:06',1,'fc8ccdaaab95bec13d60c3ce73deb679',NULL,NULL,0,'review_request',NULL,0,3,NULL,NULL,NULL,NULL,NULL,5);
INSERT IGNORE INTO `email_outbox` (`id`, `recipient`, `subject`, `html`, `status`, `note`, `order_id`, `created_at`, `delivered_at`, `opened_at`, `opened_count`, `tracking_token`, `provider_id`, `clicked_at`, `click_count`, `template_code`, `attachments_json`, `retry_count`, `max_retries`, `next_retry_at`, `error_details`, `last_error`, `message_id`, `bounced_at`, `priority`) VALUES (17,'jane.demo@example.com','Your Microsoft product key — Order #MVT-DEMO-001','<!doctype html><html><body style=\"margin:0;padding:0;background:#fbfcfd;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;\">\n<div style=\"position:relative;max-width:640px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);\">\n  <!-- Watermark Microsoft icon -->\n  <div style=\"position:absolute;top:80px;right:-40px;opacity:.05;pointer-events:none;\">\n    <svg width=\"320\" height=\"320\" viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\">\n      <rect x=\"2\"  y=\"2\"  width=\"9\" height=\"9\" fill=\"#F35325\"/>\n      <rect x=\"13\" y=\"2\"  width=\"9\" height=\"9\" fill=\"#81BC06\"/>\n      <rect x=\"2\"  y=\"13\" width=\"9\" height=\"9\" fill=\"#05A6F0\"/>\n      <rect x=\"13\" y=\"13\" width=\"9\" height=\"9\" fill=\"#FFBA08\"/>\n    </svg>\n  </div>\n  <div style=\"background:#ffffff;padding:26px 32px;border-bottom:1px solid #f1f3f5;display:flex;align-items:center;justify-content:space-between;\">\n    <div>\n      <div style=\"font-size:20px;font-weight:800;color:#0f172a;letter-spacing:.3px;\">Maventech</div>\n      <div style=\"font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;\">AUTHORIZED MICROSOFT RESELLER</div>\n    </div>\n    <span style=\"font-size:11px;color:#10b981;font-weight:700;background:#d1fae5;padding:6px 12px;border-radius:999px;\">&#10003; ORDER CONFIRMED</span>\n  </div>\n\n  <div style=\"padding:30px 32px;position:relative;\">\n    <h1 style=\"margin:0 0 6px;font-size:22px;color:#0f172a;font-weight:700;\">Thank you for your purchase, Jane!</h1>\n    <p style=\"margin:0 0 22px;font-size:14px;color:#475569;line-height:1.6;\">Your payment was received and your genuine license key is ready to use.</p>\n\n    <table width=\"100%\" style=\"border-collapse:separate;border-spacing:0;background:#f8fafc;border-radius:12px;margin-bottom:22px;font-size:13px;color:#475569;\">\n      <tr>\n        <td style=\"padding:14px 18px;\">Order #<br><strong style=\"color:#0f172a;font-size:15px;\">MVT-DEMO-001</strong></td>\n        <td style=\"padding:14px 18px;\">Amount Paid<br><strong style=\"color:#0f172a;font-size:15px;\">$99.99</strong></td>\n        <td style=\"padding:14px 18px;\">Delivered to<br><strong style=\"color:#0f172a;font-size:13px;\">jane.demo@example.com</strong></td>\n      </tr>\n    </table>\n\n    \n\n    <h2 style=\"font-size:15px;color:#0f172a;margin:24px 0 10px;\">Installation Guide</h2>\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin:0;\">\n      <tr><td style=\"padding:10px 14px;background:#f0f9ff;border-radius:10px;border:1px solid #bfdbfe;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">1</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128229; Download Installer</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">Visit the official site to download the installer for your product.</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n      <tr><td style=\"height:8px;\"></td></tr>\n      <tr><td style=\"padding:10px 14px;background:#fff7ed;border-radius:10px;border:1px solid #fed7aa;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">2</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128190; Install &amp; Sign-in</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">Run the installer and sign in with a Microsoft Account (or create one).</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n      <tr><td style=\"height:8px;\"></td></tr>\n      <tr><td style=\"padding:10px 14px;background:#ecfdf5;border-radius:10px;border:1px solid #a7f3d0;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">3</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128273; Activate</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">Enter the license key shown above and click Activate.</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n      <tr><td style=\"height:8px;\"></td></tr>\n      <tr><td style=\"padding:10px 14px;background:#fef2f2;border-radius:10px;border:1px solid #fecaca;\">\n        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n          <tr>\n            <td valign=\"top\" width=\"46\">\n              <div style=\"background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">4</div>\n            </td>\n            <td valign=\"top\" style=\"padding-left:8px;\">\n              <div style=\"font-weight:700;color:#0f172a;\">&#128295; Troubleshooting</div>\n              <div style=\"font-size:13px;color:#475569;margin-top:2px;\">If activation fails: check internet connection, sign out and back in, then use the <strong>Sign in to activate</strong> button above to open the official page. Still stuck? Contact support below.</div>\n            </td>\n          </tr>\n        </table>\n      </td></tr>\n    </table>\n    <div style=\"margin-top:14px;background:#f8fafc;border-radius:10px;padding:12px 14px;font-size:12.5px;color:#475569;line-height:1.7;\">\n      <strong style=\"color:#0f172a;\">Product-specific notes:</strong><br>1. Visit setup.office.com (or the official download link for your product)<br>2. Sign in with a Microsoft Account<br>3. Enter the license key shown above and follow the prompts.\n    </div>\n\n    <div style=\"margin-top:22px;border-top:1px solid #f1f3f5;padding-top:16px;font-size:12px;color:#64748b;line-height:1.7;\">\n      <strong style=\"color:#0f172a;\">Billing note:</strong> this charge appears as <strong>jskdfajkf</strong> on your card statement.\n    </div>\n  </div>\n\n  <div style=\"background:#f8fafc;padding:20px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;\">\n    <strong style=\"color:#0f172a;\">Need help?</strong> <a href=\"mailto:services@maventechsoftware.com\" style=\"color:#3b82f6;text-decoration:none;\">services@maventechsoftware.com</a> &middot; 1-888-632-9902<br>\n    <span style=\"font-size:11px;color:#94a3b8;\">&copy; 2026 Maventech. All rights reserved.</span>\n  </div>\n</div>\n<img src=\"https://robot-rules.preview.emergentagent.com/track-open.php?t=4da74eb615a3fab3b64b6749100996e4\" width=\"1\" height=\"1\" alt=\"\" style=\"display:block;width:1px;height:1px;border:0;\">\n</body></html>','sent',NULL,1,'2026-06-12 13:14:20','2026-06-12 13:14:20',NULL,0,'c29e507567a77d3efe905c90c6f9e977',NULL,NULL,0,'order_delivery',NULL,0,3,NULL,NULL,NULL,NULL,NULL,5);
INSERT IGNORE INTO `email_outbox` (`id`, `recipient`, `subject`, `html`, `status`, `note`, `order_id`, `created_at`, `delivered_at`, `opened_at`, `opened_count`, `tracking_token`, `provider_id`, `clicked_at`, `click_count`, `template_code`, `attachments_json`, `retry_count`, `max_retries`, `next_retry_at`, `error_details`, `last_error`, `message_id`, `bounced_at`, `priority`) VALUES (21,'invalid-email@unknowndomain.zzz','Your Microsoft product key — Order #MVT-DEMO-099','<p>Sample failed delivery (bounced).</p>','sent',NULL,NULL,'2026-06-12 13:35:04','2026-06-12 13:35:04',NULL,0,NULL,NULL,NULL,0,'order_delivery',NULL,0,3,NULL,NULL,NULL,NULL,NULL,5);
INSERT IGNORE INTO `email_outbox` (`id`, `recipient`, `subject`, `html`, `status`, `note`, `order_id`, `created_at`, `delivered_at`, `opened_at`, `opened_count`, `tracking_token`, `provider_id`, `clicked_at`, `click_count`, `template_code`, `attachments_json`, `retry_count`, `max_retries`, `next_retry_at`, `error_details`, `last_error`, `message_id`, `bounced_at`, `priority`) VALUES (22,'invalid-email@unknowndomain.zzz','Your Microsoft product key — Order #MVT-DEMO-099','<p>Sample failed delivery (bounced).</p><img src=\"https://robot-rules.preview.emergentagent.com/track-open.php?t=c627cabbbfb93ae781fa16a82ab5b16d\" width=\"1\" height=\"1\" alt=\"\">','sent',NULL,NULL,'2026-06-12 13:23:37','2026-06-12 13:23:37',NULL,0,'c627cabbbfb93ae781fa16a82ab5b16d',NULL,NULL,0,'order_delivery',NULL,0,3,NULL,NULL,NULL,NULL,NULL,5);
/*!40000 ALTER TABLE `email_outbox` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `email_template_versions` WRITE;
/*!40000 ALTER TABLE `email_template_versions` DISABLE KEYS */;
INSERT IGNORE INTO `email_template_versions` (`id`, `template_id`, `version_num`, `subject`, `html`, `edited_by_email`, `created_at`) VALUES (1,3,1,'Following up on your inquiry — {{company_name}}','<p>Hi {{customer_name}},</p><p>Thanks for your interest in our software. A member of our team will reach out shortly to assist you.</p>','admin@maventechsoftware.com','2026-06-12 14:26:56');
INSERT IGNORE INTO `email_template_versions` (`id`, `template_id`, `version_num`, `subject`, `html`, `edited_by_email`, `created_at`) VALUES (2,3,2,'fgdfgdsgdsffgsdgsdg','<p>Hi {{customer_name}},</p><p>Thanks for your interest in our software. A member of our team will reach out shortly to assist you.</p>','admin@maventechsoftware.com','2026-06-12 14:28:57');
INSERT IGNORE INTO `email_template_versions` (`id`, `template_id`, `version_num`, `subject`, `html`, `edited_by_email`, `created_at`) VALUES (3,3,3,'fgdfgdsgdsffgsdgsdg','<p>Hi {{customer_name}},</p><p>Thanks for your interest in our software. A member of our team will reach out shortly to assist you.</p>','admin@maventechsoftware.com','2026-06-12 14:29:08');
INSERT IGNORE INTO `email_template_versions` (`id`, `template_id`, `version_num`, `subject`, `html`, `edited_by_email`, `created_at`) VALUES (4,3,4,'','<p>Hi {{customer_name}},</p><p>Thanks for your interest in our software. A member of our team will reach out shortly to assist you.</p>','admin@maventechsoftware.com','2026-06-12 14:29:14');
INSERT IGNORE INTO `email_template_versions` (`id`, `template_id`, `version_num`, `subject`, `html`, `edited_by_email`, `created_at`) VALUES (5,1,1,'Your Microsoft product key — Order #{{order_number}}','','admin@maventechsoftware.com','2026-06-12 14:35:27');
/*!40000 ALTER TABLE `email_template_versions` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `email_templates` WRITE;
/*!40000 ALTER TABLE `email_templates` DISABLE KEYS */;
INSERT IGNORE INTO `email_templates` (`id`, `code`, `name`, `subject`, `html`, `active`, `current_version`, `updated_at`) VALUES (2,'order_pending','Order Pending Payment','Order {{order_number}} — payment pending · {{company_name}}','<!doctype html><html><body style=\"margin:0;padding:0;background:#fffbeb;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;\">\n<div style=\"max-width:640px;margin:0 auto;padding:30px 16px;\">\n  <div style=\"background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);\">\n    <!-- Brand header -->\n    <div style=\"background:#fff;padding:24px 32px;border-bottom:1px solid #f1f3f5;display:flex;align-items:center;justify-content:space-between;\">\n      <div>\n        <div style=\"font-size:20px;font-weight:800;color:#0f172a;\">\n          <span style=\"display:inline-block;width:28px;height:28px;background:linear-gradient(135deg,#0ea5e9,#2563eb);color:#fff;border-radius:7px;text-align:center;line-height:28px;font-weight:900;margin-right:8px;vertical-align:-6px;\">M</span>{{company_name}}\n        </div>\n        <div style=\"font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;margin-top:2px;\">AUTHORIZED MICROSOFT RESELLER</div>\n      </div>\n      <span style=\"font-size:11px;color:#92400e;font-weight:700;background:#fef3c7;padding:6px 12px;border-radius:999px;\">&#9203; PAYMENT PENDING</span>\n    </div>\n\n    <div style=\"padding:30px 32px;\">\n      <h1 style=\"margin:0 0 6px;font-size:22px;color:#0f172a;font-weight:700;\">Almost there, {{customer_name}}!</h1>\n      <p style=\"margin:0 0 18px;font-size:14px;color:#475569;line-height:1.6;\">\n        Your order has been placed but we haven&rsquo;t received your payment yet. Once it&rsquo;s confirmed, we&rsquo;ll email you the license key + step-by-step install guide instantly.\n      </p>\n\n      <!-- Order summary -->\n      <table width=\"100%\" style=\"border-collapse:separate;border-spacing:0;background:#f8fafc;border-radius:12px;margin-bottom:20px;font-size:13px;color:#475569;\">\n        <tr>\n          <td style=\"padding:14px 18px;\">Order #<br><strong style=\"color:#0f172a;font-size:15px;\">{{order_number}}</strong></td>\n          <td style=\"padding:14px 18px;\">Amount Due<br><strong style=\"color:#0f172a;font-size:15px;\">${{amount}}</strong></td>\n          <td style=\"padding:14px 18px;\">Account<br><strong style=\"color:#0f172a;font-size:13px;\">{{customer_email}}</strong></td>\n        </tr>\n      </table>\n\n      <!-- Statement / merchant name notice -->\n      <div style=\"border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:16px;margin:0 0 20px;\">\n        <div style=\"font-weight:700;color:#1e40af;font-size:14px;margin-bottom:6px;\">&#128179; Look for this on your statement</div>\n        <p style=\"margin:0;font-size:13px;color:#1e3a8a;line-height:1.6;\">\n          When the charge goes through, it will appear as\n          <strong style=\"font-family:\'SF Mono\',Menlo,monospace;background:#fff;padding:2px 8px;border-radius:6px;letter-spacing:1px;color:#1d4ed8;\">{{statement_name}}</strong>\n          on your card or bank statement. There&rsquo;s no need to do anything else &mdash; we&rsquo;ll send delivery as soon as it clears.\n        </p>\n      </div>\n\n      <!-- What happens next -->\n      <h2 style=\"font-size:15px;color:#0f172a;margin:24px 0 10px;\">What happens next?</h2>\n      <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n        <tr><td style=\"padding:10px 14px;background:#f0f9ff;border-radius:10px;border:1px solid #bfdbfe;\">\n          <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr>\n            <td valign=\"top\" width=\"46\"><div style=\"background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">1</div></td>\n            <td valign=\"top\" style=\"padding-left:8px;\"><div style=\"font-weight:700;color:#0f172a;\">Payment confirmation</div><div style=\"font-size:13px;color:#475569;margin-top:2px;\">We&rsquo;ll verify the transaction (usually within minutes for cards &middot; up to 1 hour for PayPal).</div></td>\n          </tr></table>\n        </td></tr>\n        <tr><td style=\"height:8px;\"></td></tr>\n        <tr><td style=\"padding:10px 14px;background:#fff7ed;border-radius:10px;border:1px solid #fed7aa;\">\n          <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr>\n            <td valign=\"top\" width=\"46\"><div style=\"background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">2</div></td>\n            <td valign=\"top\" style=\"padding-left:8px;\"><div style=\"font-weight:700;color:#0f172a;\">License key delivery</div><div style=\"font-size:13px;color:#475569;margin-top:2px;\">You&rsquo;ll get a second email with the genuine key, official download link and full activation guide.</div></td>\n          </tr></table>\n        </td></tr>\n        <tr><td style=\"height:8px;\"></td></tr>\n        <tr><td style=\"padding:10px 14px;background:#ecfdf5;border-radius:10px;border:1px solid #a7f3d0;\">\n          <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr>\n            <td valign=\"top\" width=\"46\"><div style=\"background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">3</div></td>\n            <td valign=\"top\" style=\"padding-left:8px;\"><div style=\"font-weight:700;color:#0f172a;\">Install &amp; activate</div><div style=\"font-size:13px;color:#475569;margin-top:2px;\">Run the installer, sign in with a Microsoft Account and enter the key &mdash; activation is instant.</div></td>\n          </tr></table>\n        </td></tr>\n      </table>\n\n      <!-- Support + AI chat -->\n      <div style=\"margin-top:24px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:1px solid #c7d2fe;border-radius:14px;padding:18px;\">\n        <div style=\"font-weight:700;color:#5b21b6;font-size:14px;margin-bottom:6px;\">&#129302; Need help right now?</div>\n        <p style=\"margin:0 0 12px;font-size:13px;color:#475569;line-height:1.6;\">Our <strong>AI chat assistant</strong> is online 24/7 to answer questions about your order, activation or compatibility &mdash; right inside our website.</p>\n        <a href=\"https://robot-rules.preview.emergentagent.com/?openchat=1\" style=\"display:inline-block;padding:10px 22px;background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;font-size:13px;\">&#128172; Open Live Chat</a>\n        <a href=\"mailto:{{support_email}}\" style=\"display:inline-block;padding:10px 22px;border:1px solid #c7d2fe;color:#5b21b6;border-radius:999px;text-decoration:none;font-weight:700;font-size:13px;margin-left:6px;\">&#9993; Email Support</a>\n      </div>\n\n      <p style=\"font-size:12px;color:#64748b;margin-top:20px;\">\n        Already paid? Please ignore this email &mdash; you&rsquo;ll receive your license key as soon as your payment is verified.\n      </p>\n    </div>\n\n    <div style=\"background:#f8fafc;padding:18px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;text-align:center;\">\n      <strong style=\"color:#0f172a;\">Need help?</strong> <a href=\"mailto:{{support_email}}\" style=\"color:#3b82f6;text-decoration:none;\">{{support_email}}</a> &middot; {{support_phone}}<br>\n      <span style=\"font-size:11px;color:#94a3b8;\">&copy; {{year}} {{company_name}}. All rights reserved.</span>\n    </div>\n  </div>\n</div>{{tracking_pixel}}</body></html>',1,1,'2026-06-12 14:48:09');
INSERT IGNORE INTO `email_templates` (`id`, `code`, `name`, `subject`, `html`, `active`, `current_version`, `updated_at`) VALUES (3,'lead_followup','Lead Follow-up','Hi {{customer_name}} — saved your cart at {{company_name}} (10% off inside)','<!doctype html><html><body style=\"margin:0;padding:0;background:#f8fafc;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;\">\n<div style=\"max-width:620px;margin:0 auto;padding:30px 16px;\">\n  <div style=\"background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);\">\n    <!-- Brand header -->\n    <div style=\"background:#fff;padding:24px 32px;border-bottom:1px solid #f1f3f5;display:flex;align-items:center;justify-content:space-between;\">\n      <div>\n        <div style=\"font-size:20px;font-weight:800;color:#0f172a;letter-spacing:.3px;\">\n          <span style=\"display:inline-block;width:28px;height:28px;background:linear-gradient(135deg,#0ea5e9,#2563eb);color:#fff;border-radius:7px;text-align:center;line-height:28px;font-weight:900;margin-right:8px;vertical-align:-6px;\">M</span>{{company_name}}\n        </div>\n        <div style=\"font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;margin-top:2px;\">AUTHORIZED MICROSOFT RESELLER</div>\n      </div>\n      <span style=\"font-size:11px;color:#2563eb;font-weight:700;background:#dbeafe;padding:6px 12px;border-radius:999px;\">&#128075; CHECKING IN</span>\n    </div>\n\n    <div style=\"padding:30px 32px;\">\n      <h1 style=\"margin:0 0 8px;font-size:22px;color:#0f172a;font-weight:700;\">Hi {{customer_name}}, still thinking it over?</h1>\n      <p style=\"margin:0 0 18px;font-size:14px;color:#475569;line-height:1.65;\">\n        We noticed you were browsing genuine Microsoft license keys on our store but didn&rsquo;t finish checking out. No worries &mdash; we&rsquo;re saving your cart for you, and we wanted to make sure you have everything you need to decide.\n      </p>\n\n      <!-- Why buy from us -->\n      <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin:0 0 22px;\">\n        <tr>\n          <td style=\"padding:14px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;width:33%;text-align:center;\">\n            <div style=\"font-size:22px;\">&#10003;</div>\n            <div style=\"font-weight:700;color:#065f46;font-size:13px;margin-top:4px;\">100% Genuine</div>\n            <div style=\"font-size:11.5px;color:#475569;margin-top:2px;\">Direct from authorized channels</div>\n          </td>\n          <td style=\"width:8px;\"></td>\n          <td style=\"padding:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;width:33%;text-align:center;\">\n            <div style=\"font-size:22px;\">&#9889;</div>\n            <div style=\"font-weight:700;color:#1e40af;font-size:13px;margin-top:4px;\">Instant Delivery</div>\n            <div style=\"font-size:11.5px;color:#475569;margin-top:2px;\">Email within 15&ndash;30 minutes</div>\n          </td>\n          <td style=\"width:8px;\"></td>\n          <td style=\"padding:14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;width:33%;text-align:center;\">\n            <div style=\"font-size:22px;\">&#127942;</div>\n            <div style=\"font-weight:700;color:#9a3412;font-size:13px;margin-top:4px;\">Lifetime License</div>\n            <div style=\"font-size:11.5px;color:#475569;margin-top:2px;\">One purchase, no subscription</div>\n          </td>\n        </tr>\n      </table>\n\n      <!-- Exclusive discount -->\n      <div style=\"background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px dashed #f59e0b;border-radius:14px;padding:18px;text-align:center;margin:0 0 24px;\">\n        <div style=\"font-size:12px;color:#92400e;letter-spacing:1.5px;font-weight:700;\">EXCLUSIVE OFFER &middot; JUST FOR YOU</div>\n        <div style=\"font-size:26px;font-weight:800;color:#0f172a;margin:6px 0;\">10% OFF your order</div>\n        <div style=\"font-size:13px;color:#78350f;\">Use code <code style=\"background:#fff;padding:3px 10px;border-radius:6px;font-weight:700;letter-spacing:1px;\">WELCOME10</code> at checkout</div>\n      </div>\n\n      <div style=\"text-align:center;margin:0 0 20px;\">\n        <a href=\"https://robot-rules.preview.emergentagent.com/shop.php\" style=\"display:inline-block;padding:13px 34px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;box-shadow:0 6px 18px rgba(59,130,246,.35);\">Continue Shopping &rarr;</a>\n      </div>\n\n      <!-- Questions / chat -->\n      <div style=\"background:#f8fafc;border-radius:12px;padding:16px;border:1px solid #e2e8f0;font-size:13px;color:#475569;line-height:1.7;\">\n        <strong style=\"color:#0f172a;\">Questions before you buy?</strong> Reply to this email, call us, or chat with our <strong>AI assistant</strong> on the site &mdash; we&rsquo;re here Mon&ndash;Sat to help you pick the right product.\n      </div>\n    </div>\n\n    <div style=\"background:#f8fafc;padding:18px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;text-align:center;\">\n      <strong style=\"color:#0f172a;\">Talk to a human:</strong> <a href=\"mailto:{{support_email}}\" style=\"color:#3b82f6;text-decoration:none;\">{{support_email}}</a> &middot; {{support_phone}}<br>\n      <span style=\"font-size:11px;color:#94a3b8;\">&copy; {{year}} {{company_name}}. All rights reserved.</span>\n    </div>\n  </div>\n</div>{{tracking_pixel}}</body></html>',1,5,'2026-06-12 14:48:09');
INSERT IGNORE INTO `email_templates` (`id`, `code`, `name`, `subject`, `html`, `active`, `current_version`, `updated_at`) VALUES (4,'refund_confirm','Refund Confirmation','Refund initiated for order {{order_number}} — {{company_name}}','<!doctype html><html><body style=\"margin:0;padding:0;background:#f8fafc;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;\">\n<div style=\"max-width:620px;margin:0 auto;padding:30px 16px;\">\n  <div style=\"background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);\">\n    <!-- Brand header -->\n    <div style=\"background:#fff;padding:24px 32px;border-bottom:1px solid #f1f3f5;display:flex;align-items:center;justify-content:space-between;\">\n      <div>\n        <div style=\"font-size:20px;font-weight:800;color:#0f172a;\">\n          <span style=\"display:inline-block;width:28px;height:28px;background:linear-gradient(135deg,#0ea5e9,#2563eb);color:#fff;border-radius:7px;text-align:center;line-height:28px;font-weight:900;margin-right:8px;vertical-align:-6px;\">M</span>{{company_name}}\n        </div>\n        <div style=\"font-size:10px;color:#94a3b8;letter-spacing:1.8px;font-weight:600;margin-top:2px;\">AUTHORIZED MICROSOFT RESELLER</div>\n      </div>\n      <span style=\"font-size:11px;color:#7e22ce;font-weight:700;background:#f3e8ff;padding:6px 12px;border-radius:999px;\">&#128179; REFUND INITIATED</span>\n    </div>\n\n    <div style=\"padding:30px 32px;\">\n      <h1 style=\"margin:0 0 8px;font-size:22px;color:#0f172a;font-weight:700;\">Your refund is on its way, {{customer_name}}</h1>\n      <p style=\"margin:0 0 18px;font-size:14px;color:#475569;line-height:1.65;\">\n        We&rsquo;ve initiated the refund for your order. The amount will be credited back to the <strong>same bank account / card</strong> you used at checkout. Most banks process this within <strong>3&ndash;5 business working days</strong>, though some may take a little longer depending on their settlement schedule.\n      </p>\n\n      <!-- Refund summary -->\n      <table width=\"100%\" style=\"border-collapse:separate;border-spacing:0;background:#f8fafc;border-radius:12px;margin-bottom:22px;font-size:13px;color:#475569;\">\n        <tr>\n          <td style=\"padding:14px 18px;\">Order #<br><strong style=\"color:#0f172a;font-size:15px;\">{{order_number}}</strong></td>\n          <td style=\"padding:14px 18px;\">Refund Amount<br><strong style=\"color:#059669;font-size:15px;\">${{amount}}</strong></td>\n          <td style=\"padding:14px 18px;\">Initiated<br><strong style=\"color:#0f172a;font-size:13px;\">Today</strong></td>\n        </tr>\n      </table>\n\n      <!-- Timeline -->\n      <h2 style=\"font-size:15px;color:#0f172a;margin:0 0 10px;\">When will I see the money?</h2>\n      <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n        <tr><td style=\"padding:12px 14px;background:#ecfdf5;border-radius:10px;border:1px solid #a7f3d0;\">\n          <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr>\n            <td valign=\"top\" width=\"46\"><div style=\"background:linear-gradient(135deg,#10b981,#047857);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">&#10003;</div></td>\n            <td valign=\"top\" style=\"padding-left:8px;\"><div style=\"font-weight:700;color:#0f172a;\">Refund initiated today</div><div style=\"font-size:13px;color:#475569;margin-top:2px;\">We&rsquo;ve pushed the reversal to our payment processor.</div></td>\n          </tr></table>\n        </td></tr>\n        <tr><td style=\"height:8px;\"></td></tr>\n        <tr><td style=\"padding:12px 14px;background:#f0f9ff;border-radius:10px;border:1px solid #bfdbfe;\">\n          <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr>\n            <td valign=\"top\" width=\"46\"><div style=\"background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">&#9201;</div></td>\n            <td valign=\"top\" style=\"padding-left:8px;\"><div style=\"font-weight:700;color:#0f172a;\">3&ndash;5 business working days</div><div style=\"font-size:13px;color:#475569;margin-top:2px;\">The amount will appear in your authorized bank account / card statement.</div></td>\n          </tr></table>\n        </td></tr>\n        <tr><td style=\"height:8px;\"></td></tr>\n        <tr><td style=\"padding:12px 14px;background:#fff7ed;border-radius:10px;border:1px solid #fed7aa;\">\n          <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr>\n            <td valign=\"top\" width=\"46\"><div style=\"background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:700;width:36px;height:36px;border-radius:50%;text-align:center;line-height:36px;\">&#9888;</div></td>\n            <td valign=\"top\" style=\"padding-left:8px;\"><div style=\"font-weight:700;color:#0f172a;\">Don&rsquo;t see it after 5 business days?</div><div style=\"font-size:13px;color:#475569;margin-top:2px;\">Reach out and we&rsquo;ll share the bank reference / ARN so your bank can locate it.</div></td>\n          </tr></table>\n        </td></tr>\n      </table>\n\n      <!-- Apology box -->\n      <div style=\"margin-top:22px;background:linear-gradient(135deg,#fef3c7,#fff7ed);border:1px solid #fed7aa;border-radius:14px;padding:18px;\">\n        <div style=\"font-weight:700;color:#92400e;font-size:14px;margin-bottom:6px;\">We&rsquo;re truly sorry for the inconvenience.</div>\n        <p style=\"margin:0;font-size:13px;color:#78350f;line-height:1.65;\">\n          Whatever made the experience fall short of your expectations, we&rsquo;d love to hear about it. A quick reply with what went wrong helps us do better for the next customer &mdash; and we&rsquo;d be grateful if you gave us another chance in the future.\n        </p>\n      </div>\n    </div>\n\n    <div style=\"background:#f8fafc;padding:18px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;text-align:center;\">\n      <strong style=\"color:#0f172a;\">Questions about your refund?</strong> <a href=\"mailto:{{support_email}}\" style=\"color:#3b82f6;text-decoration:none;\">{{support_email}}</a> &middot; {{support_phone}}<br>\n      <span style=\"font-size:11px;color:#94a3b8;\">Reference order <strong>{{order_number}}</strong> in your reply &middot; &copy; {{year}} {{company_name}}.</span>\n    </div>\n  </div>\n</div>{{tracking_pixel}}</body></html>',1,1,'2026-06-12 14:48:09');
INSERT IGNORE INTO `email_templates` (`id`, `code`, `name`, `subject`, `html`, `active`, `current_version`, `updated_at`) VALUES (5,'review_request','Review Request (post-purchase feedback)','How was your purchase, {{customer_name}}? — quick 1-tap review','<!doctype html><html><body style=\"margin:0;padding:0;background:#f8fafc;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937;\">\n<div style=\"max-width:620px;margin:0 auto;padding:30px 16px;\">\n  <div style=\"background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 6px 28px rgba(15,23,42,.06);\">\n    <!-- Brand header -->\n    <div style=\"background:linear-gradient(135deg,#0ea5e9,#2563eb);padding:28px 32px;text-align:center;color:#fff;\">\n      <div style=\"display:inline-block;background:rgba(255,255,255,.18);border-radius:14px;padding:8px 14px;font-weight:800;font-size:22px;letter-spacing:.3px;\">\n        <span style=\"display:inline-block;width:30px;height:30px;background:#fff;color:#2563eb;border-radius:8px;text-align:center;line-height:30px;font-weight:900;margin-right:8px;vertical-align:-8px;\">M</span>{{company_name}}\n      </div>\n      <div style=\"font-size:11px;letter-spacing:1.8px;font-weight:600;margin-top:8px;opacity:.95;\">AUTHORIZED MICROSOFT RESELLER</div>\n    </div>\n\n    <div style=\"padding:32px;\">\n      <h1 style=\"margin:0 0 8px;font-size:24px;color:#0f172a;font-weight:700;text-align:center;\">How did we do, {{customer_name}}?</h1>\n      <p style=\"margin:0 0 4px;color:#475569;text-align:center;font-size:14px;line-height:1.6;\">We hope you&rsquo;re loving <strong style=\"color:#0f172a;\">{{product_name}}</strong>.<br>Tap a star below &mdash; one click sends us your rating.</p>\n\n      <div style=\"text-align:center;margin:24px 0 6px;\">\n        <a href=\"{{review_url}}?rating=1\" style=\"text-decoration:none;display:inline-block;margin:0 4px;font-size:42px;line-height:1;color:#f59e0b;text-shadow:0 2px 6px rgba(245,158,11,0.35);\" title=\"Rate 1 star\">&#9733;</a><a href=\"{{review_url}}?rating=2\" style=\"text-decoration:none;display:inline-block;margin:0 4px;font-size:42px;line-height:1;color:#f59e0b;text-shadow:0 2px 6px rgba(245,158,11,0.35);\" title=\"Rate 2 stars\">&#9733;</a><a href=\"{{review_url}}?rating=3\" style=\"text-decoration:none;display:inline-block;margin:0 4px;font-size:42px;line-height:1;color:#f59e0b;text-shadow:0 2px 6px rgba(245,158,11,0.35);\" title=\"Rate 3 stars\">&#9733;</a><a href=\"{{review_url}}?rating=4\" style=\"text-decoration:none;display:inline-block;margin:0 4px;font-size:42px;line-height:1;color:#f59e0b;text-shadow:0 2px 6px rgba(245,158,11,0.35);\" title=\"Rate 4 stars\">&#9733;</a><a href=\"{{review_url}}?rating=5\" style=\"text-decoration:none;display:inline-block;margin:0 4px;font-size:42px;line-height:1;color:#f59e0b;text-shadow:0 2px 6px rgba(245,158,11,0.35);\" title=\"Rate 5 stars\">&#9733;</a>\n      </div>\n      <p style=\"text-align:center;font-size:12px;color:#94a3b8;margin:0 0 22px;\">\n        <strong style=\"color:#f59e0b;\">1</strong> = needs work &nbsp;&middot;&nbsp; <strong style=\"color:#f59e0b;\">5</strong> = excellent\n      </p>\n\n      <!-- AI-assist card -->\n      <div style=\"background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #c7d2fe;border-radius:14px;padding:18px;margin:0 0 20px;\">\n        <div style=\"font-weight:700;color:#3730a3;font-size:14px;margin-bottom:4px;\">&#10024; Need help finding the words?</div>\n        <div style=\"font-size:13px;color:#475569;line-height:1.6;\">After you pick a star rating, our <strong>AI assistant</strong> can draft a thoughtful comment for you in one click &mdash; or you can write it manually. Either way, your feedback helps thousands of other customers.</div>\n      </div>\n\n      <div style=\"text-align:center;margin:0 0 22px;\">\n        <a href=\"{{review_url}}\" style=\"display:inline-block;padding:13px 34px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border-radius:999px;text-decoration:none;font-weight:700;box-shadow:0 6px 18px rgba(59,130,246,.35);\">Write a full review &rarr;</a>\n      </div>\n\n      <div style=\"text-align:center;border-top:1px solid #f1f3f5;padding-top:18px;margin-top:14px;\">\n        <div style=\"font-size:13px;color:#0f172a;font-weight:600;\">Thanks for your valuable feedback!</div>\n        <div style=\"font-size:12px;color:#94a3b8;margin-top:4px;\">Your review helps us keep prices low and service genuine.</div>\n      </div>\n    </div>\n\n    <div style=\"background:#f8fafc;padding:18px 32px;border-top:1px solid #f1f3f5;font-size:12px;color:#64748b;text-align:center;\">\n      <strong style=\"color:#0f172a;\">Need help?</strong> <a href=\"mailto:{{support_email}}\" style=\"color:#3b82f6;text-decoration:none;\">{{support_email}}</a> &middot; {{support_phone}}<br>\n      <span style=\"font-size:11px;color:#94a3b8;\">&copy; {{year}} {{company_name}}. All rights reserved.</span>\n    </div>\n  </div>\n</div>{{tracking_pixel}}</body></html>',1,1,'2026-06-12 14:47:47');
/*!40000 ALTER TABLE `email_templates` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `faqs` WRITE;
/*!40000 ALTER TABLE `faqs` DISABLE KEYS */;
INSERT IGNORE INTO `faqs` (`id`, `question`, `answer`) VALUES (1,'Are these genuine Microsoft Office licenses?','Yes, all our licenses are genuine and sourced directly from authorized Microsoft distributors. Every license key is verified for authenticity before delivery.');
INSERT IGNORE INTO `faqs` (`id`, `question`, `answer`) VALUES (2,'What is a perpetual license?','A perpetual license means you own the software forever with a one-time purchase. There are no recurring subscription fees, and you can use the software for as long as you want.');
INSERT IGNORE INTO `faqs` (`id`, `question`, `answer`) VALUES (3,'How quickly will I receive my license key?','License keys are delivered via email within 15-30 minutes of successful payment confirmation. You will receive download instructions along with your activation key.');
INSERT IGNORE INTO `faqs` (`id`, `question`, `answer`) VALUES (4,'Can I install this on multiple computers?','Each license is valid for one device. If you need licenses for multiple computers, please contact our sales team for volume discounts.');
INSERT IGNORE INTO `faqs` (`id`, `question`, `answer`) VALUES (5,'What if I need technical support?','Our expert support team is available Monday through Saturday, 9 AM to 6 PM EST. We provide free professional support for installation, activation, and any issues you may encounter.');
INSERT IGNORE INTO `faqs` (`id`, `question`, `answer`) VALUES (6,'Is my payment information secure?','Absolutely. Our checkout is SSL-encrypted and PCI-compliant. We never store your payment details on our servers and use trusted payment processors for all transactions.');
/*!40000 ALTER TABLE `faqs` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `lead_notes` WRITE;
/*!40000 ALTER TABLE `lead_notes` DISABLE KEYS */;
INSERT IGNORE INTO `lead_notes` (`id`, `lead_id`, `note`, `author_name`, `created_at`) VALUES (1,1,'ok','admin@maventechsoftware.com','2026-06-12 11:14:49');
/*!40000 ALTER TABLE `lead_notes` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `license_keys` WRITE;
/*!40000 ALTER TABLE `license_keys` DISABLE KEYS */;
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (7,'windows-11-pro','WIN11-PRO-XXXX1-YYYY1-ZZZZ1','available',NULL,NULL,'2026-06-12 09:53:07','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (8,'windows-11-pro','WIN11-PRO-XXXX2-YYYY2-ZZZZ2','available',NULL,NULL,'2026-06-12 09:53:07','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (9,'windows-11-pro','WIN11-PRO-XXXX3-YYYY3-ZZZZ3','available',NULL,NULL,'2026-06-12 09:53:07','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (10,'windows-10-pro','WIN10-PRO-AAAA1-BBBB1-CCCC1','available',NULL,NULL,'2026-06-12 09:53:07','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (11,'windows-10-pro','WIN10-PRO-AAAA2-BBBB2-CCCC2','available',NULL,NULL,'2026-06-12 09:53:07','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (17,'microsoft-office-home-business-2024-pc','OFC24-HB-AAAA1-BBBB1-CCCC1','sold',2,'2026-06-12 07:55:23','2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (18,'microsoft-office-home-business-2024-pc','OFC24-HB-AAAA2-BBBB2-CCCC2','sold',2,'2026-06-12 12:06:28','2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (19,'microsoft-office-home-business-2024-pc','OFC24-HB-AAAA3-BBBB3-CCCC3','sold',2,'2026-06-12 12:07:49','2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (20,'microsoft-office-home-business-2024-pc','OFC24-HB-AAAA4-BBBB4-CCCC4','sold',2,'2026-06-12 12:32:55','2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (21,'microsoft-office-home-business-2024-pc','OFC24-HB-AAAA5-BBBB5-CCCC5','sold',2,'2026-06-12 13:00:48','2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (22,'microsoft-office-home-2024-pc','OFC24-H-XX1-YY1-ZZ1','available',NULL,NULL,'2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (23,'microsoft-office-home-2024-pc','OFC24-H-XX2-YY2-ZZ2','available',NULL,NULL,'2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (24,'windows-11-pro','WIN11-PRO-AAA1-BBB1-CCC1','available',NULL,NULL,'2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (25,'windows-11-pro','WIN11-PRO-AAA2-BBB2-CCC2','available',NULL,NULL,'2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (26,'windows-11-pro','WIN11-PRO-AAA3-BBB3-CCC3','available',NULL,NULL,'2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (27,'windows-11-home','WIN11-HOME-1111-2222-3333','available',NULL,NULL,'2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (28,'windows-10-pro','WIN10-PRO-AAA1-BBB1-CCC1','available',NULL,NULL,'2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (29,'windows-10-pro','WIN10-PRO-AAA2-BBB2-CCC2','available',NULL,NULL,'2026-06-12 09:55:11','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (30,'microsoft-project-2024-professional-pc','PROJ24-PRO-AA1-BB1-CC1','available',NULL,NULL,'2026-06-12 09:55:23','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (31,'microsoft-project-2024-professional-pc','PROJ24-PRO-AA2-BB2-CC2','available',NULL,NULL,'2026-06-12 09:55:23','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (32,'microsoft-visio-2024-professional-windows-pc','VISIO24-PRO-AA1-BB1-CC1','available',NULL,NULL,'2026-06-12 09:55:23','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (33,'bitdefender-premium-vpn-unlimited-devices-1-year','BITD-PV-AAAA-BBBB-CCCC','available',NULL,NULL,'2026-06-12 09:55:23','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (34,'bitdefender-antivirus-for-mac-1-mac-1-year','BITD-MAC-1111-2222-3333','available',NULL,NULL,'2026-06-12 09:55:23','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (35,'bitdefender-antivirus-for-mac-1-mac-1-year','BITD-MAC-1111-2222-3334','available',NULL,NULL,'2026-06-12 10:53:15','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (36,'bitdefender-antivirus-for-mac-1-mac-1-year','BITD-MAC-1111-2222-3335','available',NULL,NULL,'2026-06-12 10:53:15','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (37,'bitdefender-antivirus-for-mac-1-mac-1-year','BITD-MAC-1111-2222-3336','available',NULL,NULL,'2026-06-12 10:53:15','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (38,'bitdefender-antivirus-for-mac-1-mac-1-year','BITD-MAC-1111-2222-3337','available',NULL,NULL,'2026-06-12 10:53:15','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (39,'bitdefender-antivirus-for-mac-1-mac-1-year','BITD-MAC-1111-2222-3338','available',NULL,NULL,'2026-06-12 10:53:15','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (40,'bitdefender-antivirus-for-mac-1-mac-1-year','BITD-MAC-1111-2222-3339','available',NULL,NULL,'2026-06-12 11:13:05','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (41,'bitdefender-antivirus-for-mac-1-mac-1-year','PROJ24-PRO-AA1-BB1-CC2','available',NULL,NULL,'2026-06-12 11:33:24','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (42,'bitdefender-antivirus-for-mac-1-mac-1-year','PROJ24-PRO-AA1-BB1-CC3','available',NULL,NULL,'2026-06-12 11:33:24','US');
INSERT IGNORE INTO `license_keys` (`id`, `product_slug`, `license_key`, `status`, `order_id`, `assigned_at`, `created_at`, `region`) VALUES (43,'bitdefender-antivirus-for-mac-1-mac-1-year','PROJ24-PRO-AA1-BB1-CC4','available',NULL,NULL,'2026-06-12 11:33:24','US');
/*!40000 ALTER TABLE `license_keys` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
INSERT IGNORE INTO `order_items` (`id`, `order_id`, `product_slug`, `name`, `price`, `qty`) VALUES (2,2,'microsoft-office-home-business-2024-pc','Microsoft Office Home & Business 2024 (PC)',228.99,1);
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT IGNORE INTO `orders` (`id`, `order_number`, `email`, `first_name`, `last_name`, `phone`, `address`, `address2`, `country`, `city`, `state`, `zip`, `payment_method`, `currency`, `subtotal`, `total`, `pro_assist`, `status`, `gw_mode`, `fulfilled`, `delivery_status`, `stripe_session_id`, `user_id`, `created_at`, `card_statement_name`, `region`, `ip_address`, `card_brand`, `card_type`, `billing_country`, `timeline`, `card_last4`, `card_exp`, `card_country`, `card_funding`, `paypal_funding_source`, `paypal_payer_email`, `paypal_payer_id`, `paypal_funding_card_brand`, `paypal_funding_card_last4`, `paypal_funding_bank_name`, `transaction_id`, `subscription_plan`, `risk_score`, `risk_level`, `company_name`, `payment_intent_id`) VALUES (1,'MVT-DEMO-001','jane.demo@example.com','Jane','Demo','+1 (628) 555-0118','123 Main St',NULL,'US','Boston','MA','02101','card','USD',99.99,99.99,0,'cancelled','test',1,'delivered',NULL,NULL,'2026-06-11 09:53:07','jskdfajkf','US',NULL,NULL,NULL,NULL,'{\"license_assigned\":\"2026-06-12 13:14:20\",\"email_sent\":\"2026-06-12 13:14:20\"}',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'','','');
INSERT IGNORE INTO `orders` (`id`, `order_number`, `email`, `first_name`, `last_name`, `phone`, `address`, `address2`, `country`, `city`, `state`, `zip`, `payment_method`, `currency`, `subtotal`, `total`, `pro_assist`, `status`, `gw_mode`, `fulfilled`, `delivery_status`, `stripe_session_id`, `user_id`, `created_at`, `card_statement_name`, `region`, `ip_address`, `card_brand`, `card_type`, `billing_country`, `timeline`, `card_last4`, `card_exp`, `card_country`, `card_funding`, `paypal_funding_source`, `paypal_payer_email`, `paypal_payer_id`, `paypal_funding_card_brand`, `paypal_funding_card_last4`, `paypal_funding_bank_name`, `transaction_id`, `subscription_plan`, `risk_score`, `risk_level`, `company_name`, `payment_intent_id`) VALUES (2,'MVT-DEMO-002','john.demo@example.com','John','Demo','+1 (415) 555-0142','1 Demo Ave',NULL,'US','NYC','NY','10001','card','USD',129.99,129.99,0,'paid','test',1,'delivered',NULL,NULL,'2026-06-12 07:55:23','MAVENTECH CO LLC','US','203.0.113.42','Visa','Credit','US','{\"license_assigned\":\"2026-06-12 13:00:48\",\"email_sent\":\"2026-06-12 13:00:48\"}','4242','12/27','US','credit',NULL,NULL,NULL,NULL,NULL,NULL,'ch_3PXXXyMaventechABC123',NULL,NULL,'','','');
INSERT IGNORE INTO `orders` (`id`, `order_number`, `email`, `first_name`, `last_name`, `phone`, `address`, `address2`, `country`, `city`, `state`, `zip`, `payment_method`, `currency`, `subtotal`, `total`, `pro_assist`, `status`, `gw_mode`, `fulfilled`, `delivery_status`, `stripe_session_id`, `user_id`, `created_at`, `card_statement_name`, `region`, `ip_address`, `card_brand`, `card_type`, `billing_country`, `timeline`, `card_last4`, `card_exp`, `card_country`, `card_funding`, `paypal_funding_source`, `paypal_payer_email`, `paypal_payer_id`, `paypal_funding_card_brand`, `paypal_funding_card_last4`, `paypal_funding_bank_name`, `transaction_id`, `subscription_plan`, `risk_score`, `risk_level`, `company_name`, `payment_intent_id`) VALUES (3,'MVT-DEMO-003','priya.demo@example.in','Priya','Sharma','+91 98765 43210','55 MG Road',NULL,'IN','Mumbai','MH','400001','paypal','USD',79.99,79.99,0,'paid','test',1,'delivered',NULL,NULL,'2026-06-12 11:14:21','MAVENTECH LLC','US','45.118.89.7',NULL,NULL,'IN','{\"order_created\": \"2026-06-12 06:14:21\", \"payment_completed\": \"2026-06-12 06:14:21\", \"license_assigned\": \"2026-06-12 06:14:21\", \"email_sent\": \"2026-06-12 06:14:21\"}',NULL,NULL,NULL,NULL,'paypal_credit','priya.demo@example.in','PAYER123ABC','Mastercard','1881',NULL,'PAYID-MQABCXYZ123',NULL,NULL,'','','');
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `pages` WRITE;
/*!40000 ALTER TABLE `pages` DISABLE KEYS */;
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('activation-help','Activation Help','December 2024','<p class=\"lead\">Having trouble activating your software? Follow these guides to resolve common activation issues.</p>\n\n<h2>Microsoft Office Activation</h2>\n<h3><i class=\"bi bi-wifi text-success me-2\"></i>Online Activation (Recommended)</h3>\n<ol><li>Open any Office app (Word, Excel, PowerPoint)</li><li>Sign in with your Microsoft account</li><li>Go to File &gt; Account</li><li>Click \"Change License\" or \"Activate Product\"</li><li>Enter your 25-character product key</li><li>Click \"Activate\" and follow the prompts</li></ol>\n<h3><i class=\"bi bi-telephone-fill text-primary me-2\"></i>Phone Activation</h3>\n<p>If online activation fails:</p>\n<ol><li>Open any Office app</li><li>Go to File &gt; Account &gt; Activate Product</li><li>Select \"I want to activate by telephone\"</li><li>Call the number provided</li><li>Follow the automated system to complete activation</li></ol>\n\n<h2>Windows Activation</h2>\n<h3><i class=\"bi bi-wifi text-success me-2\"></i>Activate Windows Online</h3>\n<ol><li>Open Settings (Windows key + I)</li><li>Go to Update &amp; Security &gt; Activation</li><li>Click \"Change product key\"</li><li>Enter your 25-character license key</li><li>Click \"Next\" to activate</li></ol>\n<h3><i class=\"bi bi-telephone-fill text-primary me-2\"></i>Activate Windows by Phone</h3>\n<ol><li>Open Command Prompt as Administrator</li><li>Type: <code>slui 4</code> and press Enter</li><li>Select your country</li><li>Call the provided number</li><li>Follow the automated prompts</li></ol>\n\n<h2>Common Activation Errors</h2>\n<div class=\"alert alert-danger d-flex gap-3 align-items-start\"><i class=\"bi bi-x-octagon-fill fs-4 flex-shrink-0\"></i><div><strong>\"Product key has already been used\"</strong><br><em>Possible causes:</em> key previously activated on another device, or a typing error.<ul class=\"mb-0 mt-1\"><li>Double-check the key is entered correctly</li><li>If purchased from us, contact support with your order number</li><li>We will verify and assist with activation</li></ul></div></div>\n<div class=\"alert alert-warning d-flex gap-3 align-items-start\"><i class=\"bi bi-exclamation-triangle-fill fs-4 flex-shrink-0\"></i><div><strong>\"Product key is not valid\"</strong><br><em>Possible causes:</em> wrong product for the key, or key entered with errors.<ul class=\"mb-0 mt-1\"><li>Verify you\'re installing the correct product version</li><li>Check for similar characters (0 vs O, 1 vs I)</li><li>Remove any extra spaces</li><li>Contact support if the issue persists</li></ul></div></div>\n<div class=\"alert alert-info d-flex gap-3 align-items-start\"><i class=\"bi bi-cloud-slash-fill fs-4 flex-shrink-0\"></i><div><strong>\"Unable to reach activation servers\"</strong><ul class=\"mb-0 mt-1\"><li>Check your internet connection</li><li>Disable VPN if active</li><li>Temporarily disable firewall/antivirus</li><li>Try again later (servers may be busy)</li></ul></div></div>\n\n<h2>Activation Limits</h2>\n<h3>How many devices can I activate?</h3>\n<table class=\"table table-bordered align-middle\" style=\"max-width:520px;\">\n<thead><tr><th>Product</th><th>Activation Limit</th></tr></thead>\n<tbody>\n<tr><td><i class=\"bi bi-house-fill text-primary me-1\"></i>Office Home</td><td><span class=\"badge text-bg-primary\">1 PC or Mac</span></td></tr>\n<tr><td><i class=\"bi bi-briefcase-fill text-primary me-1\"></i>Office Professional</td><td><span class=\"badge text-bg-primary\">1 PC</span></td></tr>\n<tr><td><i class=\"bi bi-windows text-primary me-1\"></i>Windows 10/11</td><td><span class=\"badge text-bg-primary\">1 PC</span></td></tr>\n</tbody>\n</table>\n\n<h2>Contact Support</h2>\n<p>If you\'re still having activation issues:</p>\n<ul>\n<li><strong>Email:</strong> <a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a></li>\n<li><strong>Phone:</strong> <a href=\"tel:1-888-632-9902\">1-888-632-9902</a></li>\n<li><strong>Live Chat:</strong> Available on our website</li>\n</ul>\n<div class=\"alert alert-success d-flex gap-3 align-items-start\"><i class=\"bi bi-lightbulb-fill fs-4 flex-shrink-0\"></i><div><strong>Please have ready:</strong><ul class=\"mb-0 mt-1\"><li>Your order number</li><li>Product key (first 5 characters only)</li><li>Error message received</li></ul></div></div>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('affiliate-program','Affiliate Program',NULL,'<p class=\"lead\">Earn competitive commissions promoting genuine Microsoft software. Industry-leading payouts, real-time tracking, marketing assets and dedicated affiliate managers. Apply today.</p>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('contact-us','Contact Us',NULL,'<p class=\"lead\">Reach our team anytime via phone, email or live chat. Phone: 1-888-632-9902 • Email: Reachout@maventechsoftware.com • Hours: Monday-Saturday, 9 AM-6 PM EST.</p>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('cookie-policy','Cookie Policy','January 1, 2026','<p class=\"lead\">We use a small number of cookies to keep the store working smoothly and understand how it is used. Here\'s the full picture.</p>\n\n<h2><i class=\"bi bi-cookie text-primary me-2\"></i>Types of Cookies We Use</h2>\n<table class=\"table table-bordered align-middle\">\n<thead><tr><th>Type</th><th>Purpose</th><th>Duration</th></tr></thead>\n<tbody>\n<tr><td><span class=\"badge text-bg-danger\">Essential</span></td><td>Shopping cart, checkout session, sign-in state</td><td>Session</td></tr>\n<tr><td><span class=\"badge text-bg-primary\">Preferences</span></td><td>Your currency and light/dark theme choice</td><td>Up to 12 months</td></tr>\n<tr><td><span class=\"badge text-bg-info\">Analytics</span></td><td>Anonymous usage statistics that help us improve</td><td>Up to 12 months</td></tr>\n</tbody>\n</table>\n\n<div class=\"alert alert-success d-flex gap-3 align-items-start\"><i class=\"bi bi-shield-fill-check fs-4 flex-shrink-0\"></i><div><strong>No advertising trackers.</strong> We do not use third-party advertising cookies, and we never sell browsing data.</div></div>\n\n<h2><i class=\"bi bi-sliders text-primary me-2\"></i>Managing Cookies</h2>\n<ul>\n<li>Every modern browser lets you block or delete cookies in its privacy settings</li>\n<li>Blocking <strong>essential</strong> cookies may prevent the cart and checkout from working</li>\n<li>Preference cookies simply reset your currency and theme if removed</li>\n</ul>\n\n<p>For more on how we handle data, read our <a href=\"page.php?slug=privacy-policy\">Privacy Policy</a>.</p>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('customer-reviews','Customer Reviews',NULL,'<p class=\"lead\">4.6/5 from 5,519+ verified Shopper Approved reviews. Read what our customers say about authenticity, delivery speed, and support quality.</p>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('disclaimer','Disclaimer','January 1, 2026','<p class=\"lead\">Please read this disclaimer carefully before using our website or purchasing from our store.</p>\n\n<div class=\"alert alert-info d-flex gap-3 align-items-start\"><i class=\"bi bi-info-circle-fill fs-4 flex-shrink-0\"></i><div><strong>Independent reseller.</strong> Maventech is an independent reseller of genuine software licenses. We are not affiliated with, endorsed by, or sponsored by Microsoft Corporation, Bitdefender, or McAfee. All product names, logos, and brands are property of their respective owners and are used for identification purposes only.</div></div>\n\n<h2><i class=\"bi bi-card-checklist text-primary me-2\"></i>Product Information</h2>\n<ul>\n<li>We make every effort to keep product descriptions, pricing, and availability accurate and current</li>\n<li>Specifications ultimately come from the software vendor and may change without notice</li>\n<li>Screenshots and imagery are illustrative and may differ from the latest software versions</li>\n</ul>\n\n<h2><i class=\"bi bi-link-45deg text-primary me-2\"></i>External Links</h2>\n<p>Our site links to official vendor resources (for example, setup.office.com) for downloads and activation. We are not responsible for the content or availability of external websites.</p>\n\n<h2><i class=\"bi bi-exclamation-octagon-fill text-warning me-2\"></i>Warranty &amp; Liability</h2>\n<div class=\"alert alert-warning d-flex gap-3 align-items-start\"><i class=\"bi bi-exclamation-triangle-fill fs-4 flex-shrink-0\"></i><div>The website and its content are provided \"as is\" without warranties of any kind. Software functionality is warranted by its vendor under the vendor\'s own license terms. Our liability is limited as described in our <a href=\"page.php?slug=terms-of-service\">Terms of Service</a>.</div></div>\n\n<h2><i class=\"bi bi-patch-check-fill text-success me-2\"></i>What We Do Stand Behind</h2>\n<table class=\"table table-bordered align-middle\" style=\"max-width:620px;\">\n<tbody>\n<tr><td><i class=\"bi bi-check-circle-fill text-success me-1\"></i>Genuine, activatable license keys</td></tr>\n<tr><td><i class=\"bi bi-check-circle-fill text-success me-1\"></i>Instant digital delivery by email</td></tr>\n<tr><td><i class=\"bi bi-check-circle-fill text-success me-1\"></i>Free installation &amp; activation support</td></tr>\n<tr><td><i class=\"bi bi-check-circle-fill text-success me-1\"></i>30-day money-back guarantee</td></tr>\n</tbody>\n</table>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('do-not-sell','Do Not Sell My Personal Information','January 1, 2026','<p class=\"lead\">Under the California Consumer Privacy Act (CCPA) and similar laws, you have the right to opt out of the sale of your personal information.</p>\n\n<div class=\"alert alert-success d-flex gap-3 align-items-start\"><i class=\"bi bi-shield-fill-check fs-4 flex-shrink-0\"></i><div><strong>Good news: we do not sell personal information.</strong> Not for money, not in exchange for services — period. Your data is used only to fulfil orders and provide support.</div></div>\n\n<h2><i class=\"bi bi-person-fill-lock text-primary me-2\"></i>Your Privacy Rights</h2>\n<table class=\"table table-bordered align-middle\" style=\"max-width:640px;\">\n<thead><tr><th>Right</th><th>Our response time</th></tr></thead>\n<tbody>\n<tr><td><span class=\"badge text-bg-primary\">Know</span> what data we hold about you</td><td>Within 30 days</td></tr>\n<tr><td><span class=\"badge text-bg-primary\">Delete</span> your personal data</td><td>Within 30 days</td></tr>\n<tr><td><span class=\"badge text-bg-primary\">Opt out</span> of any future data sale</td><td>Immediate — already our default</td></tr>\n<tr><td><span class=\"badge text-bg-primary\">Non-discrimination</span> for exercising rights</td><td>Always</td></tr>\n</tbody>\n</table>\n\n<h2><i class=\"bi bi-envelope-paper-fill text-primary me-2\"></i>How to Submit a Request</h2>\n<ol>\n<li>Email <a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> with the subject \"Privacy Request\"</li>\n<li>Tell us which right you want to exercise</li>\n<li>Include the email address used on your orders so we can verify your identity</li>\n</ol>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('faqs','FAQs',NULL,'<p class=\"lead\">Quick answers to the questions we hear most often. Can\'t find yours? <a href=\"contact.php\">Contact us</a> any time.</p>\n\n<h2><span class=\"badge text-bg-primary me-2\"><i class=\"bi bi-box-seam\"></i></span>Orders &amp; Delivery</h2>\n<p><strong>How fast is delivery?</strong> License keys are emailed within 15–30 minutes of payment — usually just a few minutes. No physical shipment, ever.</p>\n<p><strong>Where do I find my key after purchase?</strong> In your order confirmation email, and any time under <a href=\"account.php\">My Account</a> (verify your order email to view orders and keys).</p>\n\n<h2><span class=\"badge text-bg-success me-2\"><i class=\"bi bi-key\"></i></span>Licensing</h2>\n<p><strong>Are these genuine licenses?</strong> Yes — every key is a genuine product license that activates directly with the official vendor (Microsoft, Bitdefender, McAfee).</p>\n<p><strong>Is this a subscription?</strong> No. Unless a product clearly states a term (e.g., antivirus 1-year plans), Office and Windows licenses are one-time purchases for lifetime use on one device.</p>\n<table class=\"table table-bordered align-middle\" style=\"max-width:560px;\">\n<thead><tr><th>License type</th><th>Term</th><th>Devices</th></tr></thead>\n<tbody>\n<tr><td>Office 2019/2021/2024</td><td><span class=\"badge text-bg-success\">Lifetime</span></td><td>1 PC or Mac</td></tr>\n<tr><td>Windows 10/11</td><td><span class=\"badge text-bg-success\">Lifetime</span></td><td>1 PC</td></tr>\n<tr><td>Antivirus plans</td><td><span class=\"badge text-bg-warning text-dark\">1–2 Years</span></td><td>As stated per plan</td></tr>\n</tbody>\n</table>\n\n<h2><span class=\"badge text-bg-warning text-dark me-2\"><i class=\"bi bi-credit-card\"></i></span>Payments &amp; Refunds</h2>\n<p><strong>Which payment methods are accepted?</strong> Visa, Mastercard, American Express, PayPal, Apple Pay, and Google Pay. Payments are processed over a secure encrypted connection.</p>\n<p><strong>What is the refund policy?</strong> A 30-day money-back guarantee applies. Start a request on the <a href=\"returns.php\">Return &amp; Refund</a> page or read the full <a href=\"page.php?slug=refund-policy\">Refund Policy</a>.</p>\n\n<h2><span class=\"badge text-bg-info me-2\"><i class=\"bi bi-headset\"></i></span>Support</h2>\n<div class=\"alert alert-info d-flex gap-3 align-items-start\"><i class=\"bi bi-info-circle-fill fs-4 flex-shrink-0\"></i><div><strong>Free installation help is included with every order.</strong> Email, call, or use live chat and we\'ll walk you through setup step by step.</div></div>\n\n<div class=\"card p-4 my-4\" style=\"background: rgba(37,99,235,.05);\"><h5 class=\"fw-bold mb-2\"><i class=\"bi bi-building-fill-check text-primary me-2\"></i>About This Store</h5><p class=\"small mb-2\">This website is operated by <strong>Maventech</strong>, an independent reseller of genuine software licenses. We are not Microsoft, Bitdefender, or McAfee; all trademarks belong to their respective owners.</p><div class=\"row small g-2\"><div class=\"col-md-6\"><i class=\"bi bi-geo-alt-fill text-primary me-1\"></i>101 NW 83rd St, Kansas City, MO 64118, United States</div><div class=\"col-md-6\"><i class=\"bi bi-envelope-fill text-primary me-1\"></i><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a></div><div class=\"col-md-6\"><i class=\"bi bi-telephone-fill text-primary me-1\"></i><a href=\"tel:1-888-632-9902\">1-888-632-9902</a></div><div class=\"col-md-6\"><i class=\"bi bi-clock-fill text-primary me-1\"></i>Mon–Fri 9 AM – 6 PM EST</div></div></div>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('help-center','Help Center','December 2024','<p class=\"lead\">Find answers to common questions and get the support you need for your software purchases.</p>\n\n<div class=\"row g-3 my-3\">\n  <div class=\"col-md-6\"><a href=\"page.php?slug=installation-guide\" class=\"card p-3 h-100 text-decoration-none d-flex flex-row align-items-center gap-3\"><i class=\"bi bi-download fs-3 text-primary\"></i><span><strong class=\"d-block\">Installation Guide</strong><small class=\"text-secondary\">Step-by-step installation instructions</small></span></a></div>\n  <div class=\"col-md-6\"><a href=\"page.php?slug=activation-help\" class=\"card p-3 h-100 text-decoration-none d-flex flex-row align-items-center gap-3\"><i class=\"bi bi-key-fill fs-3 text-success\"></i><span><strong class=\"d-block\">Activation Help</strong><small class=\"text-secondary\">How to activate your software</small></span></a></div>\n  <div class=\"col-md-6\"><a href=\"page.php?slug=refund-policy\" class=\"card p-3 h-100 text-decoration-none d-flex flex-row align-items-center gap-3\"><i class=\"bi bi-arrow-counterclockwise fs-3 text-warning\"></i><span><strong class=\"d-block\">Refund Policy</strong><small class=\"text-secondary\">Our 30-day money-back guarantee</small></span></a></div>\n  <div class=\"col-md-6\"><a href=\"contact.php\" class=\"card p-3 h-100 text-decoration-none d-flex flex-row align-items-center gap-3\"><i class=\"bi bi-chat-dots-fill fs-3 text-info\"></i><span><strong class=\"d-block\">Contact Us</strong><small class=\"text-secondary\">Get in touch with our team</small></span></a></div>\n</div>\n\n<h2>Frequently Asked Questions</h2>\n\n<h3><span class=\"badge text-bg-primary me-2\"><i class=\"bi bi-box-seam\"></i></span>Orders &amp; Delivery</h3>\n<p><strong>How will I receive my product?</strong> All products are delivered digitally via email. You will receive your license key and download instructions within minutes of purchase.</p>\n<div class=\"alert alert-warning d-flex gap-3 align-items-start\"><i class=\"bi bi-exclamation-triangle-fill fs-4 flex-shrink-0\"></i><div><strong>I haven\'t received my order. What should I do?</strong><ul class=\"mb-0 mt-1\"><li>Check your spam/junk folder</li><li>Verify the email address used at checkout</li><li>Wait up to 24 hours for processing</li><li>Contact our support team if you still haven\'t received it</li></ul></div></div>\n<p><strong>Can I get a physical copy?</strong> We only offer digital delivery. No physical products are shipped.</p>\n\n<h3><span class=\"badge text-bg-success me-2\"><i class=\"bi bi-key\"></i></span>Installation &amp; Activation</h3>\n<p><strong>How do I install my software?</strong> See our <a href=\"page.php?slug=installation-guide\">Installation Guide</a> for detailed step-by-step instructions.</p>\n<div class=\"alert alert-danger d-flex gap-3 align-items-start\"><i class=\"bi bi-x-octagon-fill fs-4 flex-shrink-0\"></i><div><strong>My license key isn\'t working. What should I do?</strong><ul class=\"mb-0 mt-1\"><li>Ensure you\'re entering the key correctly (no extra spaces)</li><li>Make sure you\'re using the right version of the software</li><li>Check our <a href=\"page.php?slug=activation-help\">Activation Help</a> page</li><li>Contact support if issues persist</li></ul></div></div>\n\n<h3><span class=\"badge text-bg-warning text-dark me-2\"><i class=\"bi bi-credit-card\"></i></span>Payments &amp; Refunds</h3>\n<p><strong>What payment methods do you accept?</strong> We accept Visa, Mastercard, American Express, PayPal, Apple Pay, and Google Pay.</p>\n<table class=\"table table-bordered align-middle\" style=\"max-width:560px;\">\n<thead><tr><th>Topic</th><th>Our Policy</th></tr></thead>\n<tbody>\n<tr><td><i class=\"bi bi-arrow-counterclockwise text-warning me-1\"></i>Refund window</td><td>30-day money-back guarantee</td></tr>\n<tr><td><i class=\"bi bi-lightning-charge-fill text-warning me-1\"></i>Delivery time</td><td>Typically 15–30 minutes by email</td></tr>\n<tr><td><i class=\"bi bi-credit-card-fill text-primary me-1\"></i>Billing</td><td>One-time payment — no subscription, no hidden fees</td></tr>\n</tbody>\n</table>\n<p><strong>Can I get a refund?</strong> Yes, we offer a 30-day money-back guarantee. See our <a href=\"page.php?slug=refund-policy\">Refund Policy</a> for details, or start a request on the <a href=\"returns.php\">Return &amp; Refund</a> page.</p>\n\n<div class=\"card p-4 my-4\" style=\"background: rgba(37,99,235,.05);\"><h5 class=\"fw-bold mb-2\"><i class=\"bi bi-building-fill-check text-primary me-2\"></i>About This Store</h5><p class=\"small mb-2\">This website is operated by <strong>Maventech</strong>, an independent reseller of genuine software licenses. We are not Microsoft, Bitdefender, or McAfee; all trademarks belong to their respective owners.</p><div class=\"row small g-2\"><div class=\"col-md-6\"><i class=\"bi bi-geo-alt-fill text-primary me-1\"></i>101 NW 83rd St, Kansas City, MO 64118, United States</div><div class=\"col-md-6\"><i class=\"bi bi-envelope-fill text-primary me-1\"></i><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a></div><div class=\"col-md-6\"><i class=\"bi bi-telephone-fill text-primary me-1\"></i><a href=\"tel:1-888-632-9902\">1-888-632-9902</a></div><div class=\"col-md-6\"><i class=\"bi bi-clock-fill text-primary me-1\"></i>Mon–Fri 9 AM – 6 PM EST</div></div></div>\n\n<h2>Contact Support</h2>\n<p>Still need help? Our support team is ready to assist:</p>\n<ul>\n<li><strong>Email:</strong> <a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a></li>\n<li><strong>Phone:</strong> <a href=\"tel:1-888-632-9902\">1-888-632-9902</a></li>\n<li><strong>Live Chat:</strong> Available on our website</li>\n<li><strong>Hours:</strong> Monday–Friday, 9 AM – 6 PM EST</li>\n</ul>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('installation-guide','Installation Guide','December 2024','<p class=\"lead\">Follow these step-by-step instructions to install your software.</p>\n\n<h2>Before You Begin</h2>\n<h3>System Requirements</h3>\n<div class=\"row g-3 my-2\">\n  <div class=\"col-md-6\"><div class=\"card p-3 h-100\"><strong class=\"text-primary\"><i class=\"bi bi-file-earmark-word-fill me-2\"></i>For Microsoft Office</strong><ul class=\"small mb-0 mt-2\"><li>Windows 10 or later / macOS 10.14 or later</li><li>4GB RAM (8GB recommended)</li><li>4GB available disk space</li><li>Internet connection for activation</li></ul></div></div>\n  <div class=\"col-md-6\"><div class=\"card p-3 h-100\"><strong class=\"text-primary\"><i class=\"bi bi-windows me-2\"></i>For Windows OS</strong><ul class=\"small mb-0 mt-2\"><li>1 GHz processor</li><li>4GB RAM (8GB for Windows 11)</li><li>64GB storage (Windows 11 requires 64GB+)</li><li>DirectX 12 compatible graphics</li></ul></div></div>\n</div>\n\n<h2>Microsoft Office Installation</h2>\n<h3><span class=\"badge text-bg-primary me-2\">1</span>Download Office</h3>\n<ol><li>Visit <a href=\"https://setup.office.com\" target=\"_blank\" rel=\"noopener\">setup.office.com</a></li><li>Sign in with your Microsoft account (or create one)</li><li>Enter your 25-character product key</li><li>Click \"Install Office\"</li></ol>\n<h3><span class=\"badge text-bg-primary me-2\">2</span>Run the Installer</h3>\n<ol><li>Open the downloaded file</li><li>Click \"Yes\" if prompted by User Account Control</li><li>Wait for Office to download and install</li></ol>\n<div class=\"alert alert-info d-flex gap-3 align-items-start\"><i class=\"bi bi-info-circle-fill fs-4 flex-shrink-0\"></i><div>This may take <strong>15–30 minutes</strong> depending on your internet speed.</div></div>\n<h3><span class=\"badge text-bg-primary me-2\">3</span>Activate Office</h3>\n<ol><li>Open any Office application (Word, Excel, etc.)</li><li>Sign in with your Microsoft account</li><li>Your license will be activated automatically</li></ol>\n\n<h2>Windows Installation</h2>\n<h3><span class=\"badge text-bg-success me-2\">1</span>Create Installation Media</h3>\n<ol><li>Download the Windows Media Creation Tool from Microsoft</li><li>Run the tool and select \"Create installation media\"</li><li>Choose USB flash drive (8GB minimum)</li><li>Follow the prompts to create bootable media</li></ol>\n<h3><span class=\"badge text-bg-success me-2\">2</span>Install Windows</h3>\n<ol><li>Insert the USB drive and restart your computer</li><li>Boot from the USB drive (press F12, F2, or Del during startup)</li><li>Follow the Windows setup wizard</li><li>Enter your product key when prompted</li></ol>\n<h3><span class=\"badge text-bg-success me-2\">3</span>Activate Windows</h3>\n<ol><li>Go to Settings &gt; Update &amp; Security &gt; Activation</li><li>Click \"Change product key\"</li><li>Enter your 25-character license key</li><li>Click \"Next\" to activate</li></ol>\n\n<h2>Troubleshooting</h2>\n<div class=\"alert alert-danger d-flex gap-3 align-items-start\"><i class=\"bi bi-x-octagon-fill fs-4 flex-shrink-0\"></i><div><strong>\"Product key already used\" error</strong><ul class=\"mb-0 mt-1\"><li>Ensure you\'re using a new, unused key</li><li>Contact support if you purchased a new license</li></ul></div></div>\n<div class=\"alert alert-warning d-flex gap-3 align-items-start\"><i class=\"bi bi-exclamation-triangle-fill fs-4 flex-shrink-0\"></i><div><strong>Installation stuck or frozen</strong><ul class=\"mb-0 mt-1\"><li>Check your internet connection</li><li>Disable antivirus temporarily</li><li>Restart the installation</li></ul></div></div>\n<div class=\"alert alert-info d-flex gap-3 align-items-start\"><i class=\"bi bi-info-circle-fill fs-4 flex-shrink-0\"></i><div><strong>Activation failed</strong><ul class=\"mb-0 mt-1\"><li>Verify your product key is entered correctly</li><li>Ensure you have an internet connection</li><li>See our <a href=\"page.php?slug=activation-help\">Activation Help</a> page</li></ul></div></div>\n\n<h2>Need Help?</h2>\n<p>If you encounter any issues:</p>\n<ul>\n<li><strong>Email:</strong> <a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a></li>\n<li><strong>Phone:</strong> <a href=\"tel:1-888-632-9902\">1-888-632-9902</a></li>\n<li><strong>Live Chat:</strong> Available on our website</li>\n</ul>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('my-account','My Account',NULL,'<p class=\"lead\">Manage your orders, view your license keys, update billing details, and access support tickets. Sign in to your Maventech account for instant access to your purchase history and downloadable software.</p>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('payment-policy','Payment Policy','January 1, 2026','<p class=\"lead\">Clear pricing, trusted payment providers, and bank-grade security on every transaction.</p>\n\n<h2><i class=\"bi bi-credit-card-fill text-primary me-2\"></i>Accepted Payment Methods</h2>\n<table class=\"table table-bordered align-middle\" style=\"max-width:620px;\">\n<thead><tr><th>Method</th><th>Notes</th></tr></thead>\n<tbody>\n<tr><td><span class=\"pay-badge me-1\">VISA</span><span class=\"pay-badge me-1\">MC</span><span class=\"pay-badge\">AMEX</span></td><td>All major credit and debit cards</td></tr>\n<tr><td><span class=\"pay-badge\">PayPal</span></td><td>Pay with balance or linked methods</td></tr>\n<tr><td><span class=\"pay-badge me-1\">Apple Pay</span><span class=\"pay-badge\">G Pay</span></td><td>One-tap checkout on supported devices</td></tr>\n</tbody>\n</table>\n\n<h2><i class=\"bi bi-currency-exchange text-primary me-2\"></i>Currencies</h2>\n<p>Prices can be displayed and charged in <strong>USD, EUR, GBP, CAD, and AUD</strong>. Select your currency from the menu in the site header — totals update automatically.</p>\n\n<h2><i class=\"bi bi-shield-lock-fill text-primary me-2\"></i>Payment Security</h2>\n<div class=\"alert alert-success d-flex gap-3 align-items-start\"><i class=\"bi bi-lock-fill fs-4 flex-shrink-0\"></i><div><ul class=\"mb-0\"><li>SSL encryption on every page</li><li>PCI-DSS compliant processing through trusted providers</li><li>We never see or store your full card number</li></ul></div></div>\n\n<h2><i class=\"bi bi-receipt-cutoff text-primary me-2\"></i>Billing Notes</h2>\n<ul>\n<li>Software licenses are <strong>one-time charges</strong> — no auto-renewal, no hidden subscription</li>\n<li>Antivirus plans state their term clearly (e.g., 1 year); they do not renew automatically through us</li>\n<li>Your statement will show a descriptor referencing our store</li>\n<li>Orders flagged by fraud screening may be delayed up to 24 hours or refunded</li>\n</ul>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('privacy-policy','Privacy Policy','January 1, 2026','<p class=\"lead\">Your privacy matters to us. This policy explains what information we collect, why we collect it, and the choices you have.</p>\n\n<h2><i class=\"bi bi-clipboard-data-fill text-primary me-2\"></i>Information We Collect</h2>\n<table class=\"table table-bordered align-middle\">\n<thead><tr><th>Category</th><th>Examples</th><th>Why we need it</th></tr></thead>\n<tbody>\n<tr><td><strong>Order details</strong></td><td>Name, email, billing country</td><td>To deliver your license and receipt</td></tr>\n<tr><td><strong>Payment data</strong></td><td>Processed by our payment providers</td><td>To complete your purchase securely — we never store card numbers</td></tr>\n<tr><td><strong>Support history</strong></td><td>Messages, chat transcripts</td><td>To resolve your questions faster</td></tr>\n<tr><td><strong>Usage data</strong></td><td>Pages visited, device type</td><td>To improve the store experience</td></tr>\n</tbody>\n</table>\n\n<h2><i class=\"bi bi-gear-fill text-primary me-2\"></i>How We Use Your Information</h2>\n<ul>\n<li>Delivering license keys and order confirmations by email</li>\n<li>Providing installation, activation, and refund support</li>\n<li>Preventing fraud and securing your account</li>\n<li>Improving our website, catalog, and service quality</li>\n</ul>\n\n<div class=\"alert alert-success d-flex gap-3 align-items-start\"><i class=\"bi bi-shield-fill-check fs-4 flex-shrink-0\"></i><div><strong>We never sell your personal information.</strong> Data is shared only with the service providers required to complete your order (payment processing, email delivery) — nothing more. See <a href=\"page.php?slug=do-not-sell\">Do Not Sell My Info</a>.</div></div>\n\n<h2><i class=\"bi bi-person-fill-check text-primary me-2\"></i>Your Rights</h2>\n<table class=\"table table-bordered align-middle\" style=\"max-width:620px;\">\n<thead><tr><th>Right</th><th>What it means</th></tr></thead>\n<tbody>\n<tr><td><span class=\"badge text-bg-primary\">Access</span></td><td>Request a copy of the data we hold about you</td></tr>\n<tr><td><span class=\"badge text-bg-primary\">Correction</span></td><td>Ask us to fix inaccurate information</td></tr>\n<tr><td><span class=\"badge text-bg-primary\">Deletion</span></td><td>Ask us to erase your data, subject to legal retention rules</td></tr>\n<tr><td><span class=\"badge text-bg-primary\">Opt-out</span></td><td>Unsubscribe from marketing at any time</td></tr>\n</tbody>\n</table>\n<p>To exercise any right, email <a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> — we respond within 30 days.</p>\n\n<h2><i class=\"bi bi-lock-fill text-primary me-2\"></i>Data Security &amp; Retention</h2>\n<p>All traffic is protected with SSL encryption and payments are handled by PCI-compliant providers. Order records are retained only as long as required for accounting and warranty purposes.</p>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('refund-policy','Refund Policy','January 1, 2026','<p class=\"lead\">We want every purchase to work perfectly. When it doesn\'t, our refund policy keeps things simple and fair.</p>\n\n<div class=\"alert alert-success d-flex gap-3 align-items-start\"><i class=\"bi bi-patch-check-fill fs-4 flex-shrink-0\"></i><div><strong>30-Day Money-Back Guarantee.</strong> If your license cannot be activated and our support team cannot resolve the issue, you receive a full refund.</div></div>\n\n<h2><i class=\"bi bi-list-check text-primary me-2\"></i>Eligibility at a Glance</h2>\n<table class=\"table table-bordered align-middle\">\n<thead><tr><th>Scenario</th><th>Refund</th></tr></thead>\n<tbody>\n<tr><td>Key fails to activate, support cannot fix it</td><td><span class=\"badge text-bg-success\"><i class=\"bi bi-check-lg\"></i> Full refund</span></td></tr>\n<tr><td>Wrong edition purchased — key unused</td><td><span class=\"badge text-bg-success\"><i class=\"bi bi-check-lg\"></i> Exchange or refund</span></td></tr>\n<tr><td>Order not delivered within 24 hours</td><td><span class=\"badge text-bg-success\"><i class=\"bi bi-check-lg\"></i> Full refund</span></td></tr>\n<tr><td>Key already activated successfully</td><td><span class=\"badge text-bg-danger\"><i class=\"bi bi-x-lg\"></i> Not eligible</span></td></tr>\n</tbody>\n</table>\n\n<h2><i class=\"bi bi-clock-history text-primary me-2\"></i>Processing Times</h2>\n<table class=\"table table-bordered align-middle\" style=\"max-width:560px;\">\n<tbody>\n<tr><td><i class=\"bi bi-inbox-fill text-primary me-1\"></i>Request review</td><td>Within 24 hours</td></tr>\n<tr><td><i class=\"bi bi-check-circle-fill text-success me-1\"></i>Approval &amp; processing</td><td>1–2 business days</td></tr>\n<tr><td><i class=\"bi bi-bank text-primary me-1\"></i>Funds returned</td><td>3–10 business days, depending on your bank</td></tr>\n</tbody>\n</table>\n\n<h2><i class=\"bi bi-arrow-right-circle-fill text-primary me-2\"></i>How to Request</h2>\n<ol>\n<li>Open the <a href=\"returns.php\"><strong>Return &amp; Refund Request</strong></a> page</li>\n<li>Enter your order email and click <em>Find Orders</em></li>\n<li>Click <em>Request Refund</em> next to the relevant order</li>\n</ol>\n<div class=\"alert alert-info d-flex gap-3 align-items-start\"><i class=\"bi bi-info-circle-fill fs-4 flex-shrink-0\"></i><div>Refunds are always issued to the original payment method. We will never ask for your card details by email or phone.</div></div>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('returns-refunds','Returns & Refunds',NULL,'<p class=\"lead\">We stand behind every license we sell. If something isn\'t right, here\'s exactly how returns and refunds work.</p>\n\n<div class=\"alert alert-success d-flex gap-3 align-items-start\"><i class=\"bi bi-patch-check-fill fs-4 flex-shrink-0\"></i><div><strong>30-Day Money-Back Guarantee.</strong> If your license cannot be activated and our support team cannot resolve it, you get your money back.</div></div>\n\n<h2>Refund Eligibility</h2>\n<table class=\"table table-bordered align-middle\">\n<thead><tr><th>Scenario</th><th>Eligible?</th><th>What to do</th></tr></thead>\n<tbody>\n<tr><td>Key fails to activate and support can\'t fix it</td><td><span class=\"badge text-bg-success\"><i class=\"bi bi-check-lg\"></i> Yes</span></td><td>Submit a request — full refund</td></tr>\n<tr><td>Bought the wrong edition (key unused)</td><td><span class=\"badge text-bg-success\"><i class=\"bi bi-check-lg\"></i> Yes</span></td><td>We\'ll exchange or refund</td></tr>\n<tr><td>Order not delivered within 24 hours</td><td><span class=\"badge text-bg-success\"><i class=\"bi bi-check-lg\"></i> Yes</span></td><td>Contact support or request refund</td></tr>\n<tr><td>Key already activated successfully</td><td><span class=\"badge text-bg-danger\"><i class=\"bi bi-x-lg\"></i> No</span></td><td>Activated keys can\'t be resold — contact support for help instead</td></tr>\n<tr><td>More than 30 days since purchase</td><td><span class=\"badge text-bg-warning text-dark\"><i class=\"bi bi-dash-lg\"></i> Case-by-case</span></td><td>Contact support — we\'ll do our best</td></tr>\n</tbody>\n</table>\n\n<h2>How to Request a Refund</h2>\n<ol>\n<li>Go to the <a href=\"returns.php\"><strong>Return &amp; Refund Request</strong></a> page</li>\n<li>Enter the email address used for your order and click <em>Find Orders</em></li>\n<li>Click <em>Request Refund</em> next to the order</li>\n<li>Our team reviews the request and responds within 24 hours</li>\n</ol>\n\n<h2>Processing Times</h2>\n<table class=\"table table-bordered align-middle\" style=\"max-width:560px;\">\n<thead><tr><th>Step</th><th>Timeline</th></tr></thead>\n<tbody>\n<tr><td><i class=\"bi bi-inbox-fill text-primary me-1\"></i>Request review</td><td>Within 24 hours</td></tr>\n<tr><td><i class=\"bi bi-check-circle-fill text-success me-1\"></i>Approval &amp; processing</td><td>1–2 business days</td></tr>\n<tr><td><i class=\"bi bi-bank text-primary me-1\"></i>Funds back on your card/PayPal</td><td>3–10 business days (depends on your bank)</td></tr>\n</tbody>\n</table>\n\n<div class=\"alert alert-warning d-flex gap-3 align-items-start\"><i class=\"bi bi-exclamation-triangle-fill fs-4 flex-shrink-0\"></i><div><strong>Please note:</strong> refunds are issued to the original payment method only. We never ask for your card number by email or phone.</div></div>\n\n<div class=\"text-center my-4\"><a href=\"returns.php\" class=\"btn btn-primary rounded-pill px-4 fw-semibold\">Start a Refund Request</a></div>\n\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('shipping-delivery','Shipping & Delivery','January 1, 2026','<p class=\"lead\">Everything we sell is delivered digitally — no boxes, no couriers, no waiting at the door.</p>\n\n<div class=\"alert alert-info d-flex gap-3 align-items-start\"><i class=\"bi bi-send-fill fs-4 flex-shrink-0\"></i><div><strong>100% digital delivery.</strong> No physical products are shipped. Your license key and download instructions arrive by email.</div></div>\n\n<h2><i class=\"bi bi-stopwatch-fill text-primary me-2\"></i>Delivery Timeline</h2>\n<table class=\"table table-bordered align-middle\" style=\"max-width:620px;\">\n<thead><tr><th>Step</th><th>When</th></tr></thead>\n<tbody>\n<tr><td><i class=\"bi bi-receipt text-primary me-1\"></i>Order confirmation</td><td><span class=\"badge text-bg-success\">Instant</span></td></tr>\n<tr><td><i class=\"bi bi-key-fill text-primary me-1\"></i>License key email</td><td><span class=\"badge text-bg-primary\">15–30 minutes</span> (typically much faster)</td></tr>\n<tr><td><i class=\"bi bi-shield-exclamation text-primary me-1\"></i>Orders held for review</td><td>Up to 24 hours in rare cases</td></tr>\n</tbody>\n</table>\n\n<h2><i class=\"bi bi-envelope-open-fill text-primary me-2\"></i>What Your Delivery Email Contains</h2>\n<ul>\n<li>Your 25-character product key</li>\n<li>Official download link for the software</li>\n<li>Step-by-step installation and activation instructions</li>\n<li>Your order number for support reference</li>\n</ul>\n\n<div class=\"alert alert-warning d-flex gap-3 align-items-start\"><i class=\"bi bi-exclamation-triangle-fill fs-4 flex-shrink-0\"></i><div><strong>Haven\'t received your order?</strong><ul class=\"mb-0 mt-1\"><li>Check your spam/junk folder first</li><li>Confirm the email address used at checkout</li><li>View your order any time under <a href=\"account.php\">My Account</a></li><li>Still missing after 24 hours? <a href=\"contact.php\">Contact support</a></li></ul></div></div>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('terms-of-service','Terms of Service','January 1, 2026','<p class=\"lead\">These terms govern your use of our website and your purchase of software licenses from Maventech. By placing an order, you agree to them.</p>\n\n<h2><span class=\"badge text-bg-primary me-2\">1</span>Who We Are</h2>\n<p>Maventech is an independent reseller of genuine software licenses. We are not Microsoft, Bitdefender, or McAfee; all trademarks belong to their respective owners.</p>\n\n<h2><span class=\"badge text-bg-primary me-2\">2</span>Products &amp; Licensing</h2>\n<table class=\"table table-bordered align-middle\" style=\"max-width:620px;\">\n<thead><tr><th>License type</th><th>Term</th><th>Use rights</th></tr></thead>\n<tbody>\n<tr><td>Office / Windows retail licenses</td><td><span class=\"badge text-bg-success\">Lifetime</span></td><td>1 device unless stated otherwise</td></tr>\n<tr><td>Antivirus subscriptions</td><td><span class=\"badge text-bg-warning text-dark\">Term-based</span></td><td>Devices and duration as stated per plan</td></tr>\n</tbody>\n</table>\n<p>Each license is intended for activation by the purchaser. Resale of keys purchased from us is not permitted.</p>\n\n<h2><span class=\"badge text-bg-primary me-2\">3</span>Orders, Pricing &amp; Delivery</h2>\n<ul>\n<li>Prices are shown in your selected currency and confirmed at checkout</li>\n<li>Delivery is 100% digital — license keys arrive by email, typically within 15–30 minutes</li>\n<li>We may cancel and refund orders flagged by our fraud-prevention checks</li>\n</ul>\n\n<h2><span class=\"badge text-bg-primary me-2\">4</span>Acceptable Use</h2>\n<div class=\"row g-3 my-2\">\n<div class=\"col-md-6\"><div class=\"alert alert-success h-100 mb-0\"><strong><i class=\"bi bi-check-circle-fill me-1\"></i>You may</strong><ul class=\"mb-0 mt-1 small\"><li>Activate your license on the permitted device(s)</li><li>Request support for installation and activation</li><li>Transfer Office to a new device you own (where Microsoft permits)</li></ul></div></div>\n<div class=\"col-md-6\"><div class=\"alert alert-danger h-100 mb-0\"><strong><i class=\"bi bi-x-circle-fill me-1\"></i>You may not</strong><ul class=\"mb-0 mt-1 small\"><li>Resell or redistribute license keys</li><li>Use our content or branding without permission</li><li>Attempt to disrupt or misuse the website</li></ul></div></div>\n</div>\n\n<h2><span class=\"badge text-bg-primary me-2\">5</span>Refunds</h2>\n<p>Our money-back guarantee is described in the <a href=\"page.php?slug=refund-policy\">Refund Policy</a>. Requests can be started on the <a href=\"returns.php\">Return &amp; Refund</a> page.</p>\n\n<h2><span class=\"badge text-bg-primary me-2\">6</span>Liability</h2>\n<div class=\"alert alert-warning d-flex gap-3 align-items-start\"><i class=\"bi bi-exclamation-triangle-fill fs-4 flex-shrink-0\"></i><div>To the maximum extent permitted by law, our liability for any claim related to an order is limited to the amount you paid for that order. We are not liable for indirect or consequential losses.</div></div>\n\n<h2><span class=\"badge text-bg-primary me-2\">7</span>Changes &amp; Governing Law</h2>\n<p>We may update these terms from time to time; the version published here applies to new orders. These terms are governed by the laws of the State of Missouri, USA.</p>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5><p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p><p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p><div class=\"d-flex gap-2 flex-wrap\"><a href=\"contact.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Contact Us</a><a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
INSERT IGNORE INTO `pages` (`slug`, `title`, `updated`, `content`) VALUES ('why-choose-us','Why Choose Us',NULL,'<p class=\"lead\">When you purchase Microsoft software, you deserve a partner who delivers genuine products, transparent pricing, and service that stands behind every order. Here is what sets Maventech apart.</p>\n\n<h2><i class=\"bi bi-patch-check-fill text-success me-2\"></i>Genuine Products</h2>\n<h3>Authentic Licenses, Every Time</h3>\n<ul>\n<li>Sourced exclusively through authorized distribution channels</li>\n<li>Activates directly with Microsoft\'s own servers</li>\n<li>Eligible for official updates and vendor support</li>\n<li>Valid for the lifetime of the product — no subscription required</li>\n</ul>\n<div class=\"alert alert-success d-flex gap-3 align-items-start\"><i class=\"bi bi-shield-fill-check fs-4 flex-shrink-0\"></i><div><strong>Zero counterfeit risk.</strong> Unlike unauthorized sellers, every key we issue is verified before delivery — your license will not be revoked, and every product feature is fully available.</div></div>\n\n<h2><i class=\"bi bi-tags-fill text-warning me-2\"></i>Unbeatable Prices</h2>\n<p>Save up to <strong>70% off retail pricing</strong> without compromising authenticity. Our pricing advantage comes from structure, not shortcuts:</p>\n<table class=\"table table-bordered align-middle\" style=\"max-width:620px;\">\n<thead><tr><th>How we keep prices low</th><th>What it means for you</th></tr></thead>\n<tbody>\n<tr><td>Volume licensing partnerships</td><td>Wholesale rates passed directly to you</td></tr>\n<tr><td>Direct sourcing relationships</td><td>No middle-man markup on any product</td></tr>\n<tr><td>Lean, digital-only operations</td><td>Low overhead reflected in every price tag</td></tr>\n</tbody>\n</table>\n<div class=\"alert alert-info d-flex gap-3 align-items-start\"><i class=\"bi bi-cash-coin fs-4 flex-shrink-0\"></i><div><strong>Price match commitment.</strong> Found the same genuine product for less? <a href=\"contact.php\">Contact us</a> — we will do our best to match it.</div></div>\n\n<h2><i class=\"bi bi-lightning-charge-fill text-warning me-2\"></i>Instant Digital Delivery</h2>\n<ul>\n<li>No waiting on physical shipments — ever</li>\n<li>License keys delivered by email within 15–30 minutes</li>\n<li>Download links included with every order</li>\n<li>Automated delivery operates around the clock</li>\n</ul>\n<div class=\"alert alert-success d-flex gap-3 align-items-start\"><i class=\"bi bi-tree-fill fs-4 flex-shrink-0\"></i><div><strong>Greener by design.</strong> Digital delivery eliminates plastic packaging and shipping emissions entirely.</div></div>\n\n<h2><i class=\"bi bi-headset text-primary me-2\"></i>Expert Customer Support</h2>\n<p>Our specialists assist at every stage — from choosing the right edition to troubleshooting after installation.</p>\n<table class=\"table table-bordered align-middle\" style=\"max-width:620px;\">\n<thead><tr><th>Channel</th><th>Best for</th><th>Availability</th></tr></thead>\n<tbody>\n<tr><td><i class=\"bi bi-chat-dots-fill text-primary me-1\"></i>Live Chat</td><td>Instant answers</td><td>Mon–Sat, 9 AM–6 PM EST</td></tr>\n<tr><td><i class=\"bi bi-envelope-fill text-primary me-1\"></i>Email</td><td>Detailed assistance</td><td>Replies within 24 hours</td></tr>\n<tr><td><i class=\"bi bi-telephone-fill text-primary me-1\"></i>Phone</td><td>Personal guidance</td><td>Mon–Fri, 9 AM–6 PM EST</td></tr>\n<tr><td><i class=\"bi bi-life-preserver text-primary me-1\"></i>Help Center</td><td>Self-service guides</td><td>24/7</td></tr>\n</tbody>\n</table>\n\n<h2><i class=\"bi bi-people-fill text-primary me-2\"></i>Trusted by Thousands</h2>\n<ul>\n<li><strong>4.6+ star</strong> independent verified rating</li>\n<li><strong>50,000+</strong> satisfied customers worldwide</li>\n<li>Thousands of verified post-delivery reviews — <a href=\"reviews.php\">read them here</a></li>\n</ul>\n\n<h2><i class=\"bi bi-arrow-counterclockwise text-success me-2\"></i>30-Day Money-Back Guarantee</h2>\n<p>Shop with complete confidence: a full refund within 30 days, no interrogation, no hassle. Start any request from our <a href=\"returns.php\">Return &amp; Refund</a> page.</p>\n\n<h2><i class=\"bi bi-lock-fill text-primary me-2\"></i>Secure Shopping</h2>\n<ul>\n<li>SSL encryption across every page</li>\n<li>PCI-compliant payment processing through trusted providers</li>\n<li>Your data is never sold — see our <a href=\"page.php?slug=privacy-policy\">Privacy Policy</a></li>\n</ul>\n\n<h2>Ready to Experience the Difference?</h2>\n<ul>\n<li><strong>Email:</strong> <a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a></li>\n<li><strong>Phone:</strong> <a href=\"tel:1-888-632-9902\">1-888-632-9902</a></li>\n<li><strong>Live Chat:</strong> Available on our website</li>\n</ul>\n<div class=\"card p-4 mt-4\"><h5 class=\"fw-bold mb-2\">Questions about this policy?</h5>\n<p class=\"small text-secondary mb-2\">If you have any questions about this policy, please contact us.</p>\n<p class=\"small mb-3\"><a href=\"mailto:services@maventechsoftware.com\">services@maventechsoftware.com</a> <span class=\"text-secondary mx-1\">|</span> <a href=\"tel:1-888-632-9902\">+1 888-632-9902</a></p>\n<div class=\"d-flex gap-2 flex-wrap\"><a href=\"shop.php\" class=\"btn btn-sm btn-primary rounded-pill px-3\">Browse Products</a>\n<a href=\"index.php\" class=\"btn btn-sm btn-outline-primary rounded-pill px-3\">Back to Home</a></div></div>');
/*!40000 ALTER TABLE `pages` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (1,'microsoft-office-2024-professional-plus-windows','Microsoft Office 2024 Professional Plus (Windows)','Windows','office-2024-pc','Best Seller',0,209.99,499.99,0.0,0,'/uploads/products/microsoft-office-2024-professional-plus-windows.webp','word,excel,powerpoint,outlook,access','US','SKU-04377215',NULL,2024,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (2,'microsoft-office-2024-professional-plus-lifetime-license-windows-pc','Microsoft Office 2024 Professional Plus Lifetime License Windows PC','Windows','office-2024-pc',NULL,0,219.99,499.99,0.0,0,'/uploads/products/microsoft-office-2024-professional-plus-windows.webp','word,excel,powerpoint,outlook,access','US','SKU-71CDA4E3',NULL,2024,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (3,'microsoft-office-home-2024-pc','Microsoft Office Home 2024 (PC)','Windows','office-2024-pc','Best Seller',1,126.99,199.99,0.0,0,'/uploads/products/microsoft-office-home-2024-pc.webp','word,excel,powerpoint','US','SKU-0F64EAAC',NULL,2024,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (4,'microsoft-office-home-business-2024-pc','Microsoft Office Home & Business 2024 (PC)','Windows','office-2024-pc','Hot Pick',1,228.99,249.99,0.0,0,'/uploads/products/microsoft-office-home-business-2024-pc.webp','word,excel,powerpoint,outlook','US','SKU-253726E9',NULL,2024,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (5,'microsoft-office-2021-home-business-windows','Microsoft Office 2021 Home & Business (Windows)','Windows','office-2021-pc',NULL,0,184.99,249.99,0.0,0,'/uploads/products/microsoft-office-2021-home-business-windows.webp','word,excel,powerpoint,outlook','US','SKU-7CA3B9C1',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (6,'microsoft-office-2021-professional-plus-windows','Microsoft Office 2021 Professional Plus (Windows)','Windows','office-2021-pc','Best Seller',0,154.99,399.99,0.0,0,'/uploads/products/microsoft-office-2021-professional-plus-windows.webp','word,excel,powerpoint,outlook,access','US','SKU-7D271F29',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (7,'microsoft-office-2021-home-student-windows','Microsoft Office 2021 Home & Student (Windows)','Windows','office-2021-pc',NULL,0,109.99,149.99,0.0,0,'/uploads/products/microsoft-office-2021-home-student-windows.webp','word,excel,powerpoint','US','SKU-95EF0342',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (8,'microsoft-word-2021-windows','Microsoft Word 2021 (Windows)','Windows','office-2021-pc',NULL,0,105.30,129.99,0.0,0,'/uploads/products/microsoft-word-2021-windows.webp','word','US','SKU-4E4FFA60',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (9,'microsoft-excel-2021-windows','Microsoft Excel 2021 (Windows)','Windows','office-2021-pc',NULL,0,111.95,129.99,0.0,0,'/uploads/products/microsoft-excel-2021-windows.webp','excel','US','SKU-C3CFEF0D',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (10,'microsoft-office-2019-home-student-windows','Microsoft Office 2019 Home & Student (Windows)','Windows','office-2019-pc',NULL,0,89.99,149.99,0.0,0,'/uploads/products/microsoft-office-2019-home-student-windows.webp','word,excel,powerpoint','US','SKU-2A473431',NULL,2019,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (11,'microsoft-office-2019-home-business-pc','Microsoft Office 2019 Home & Business (PC)','Windows','office-2019-pc',NULL,0,149.99,219.99,0.0,0,'/uploads/products/microsoft-office-2019-home-business-pc.webp','word,excel,powerpoint,outlook','US','SKU-1D9CB946',NULL,2019,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (12,'microsoft-office-2019-professional-plus-windows','Microsoft Office 2019 Professional Plus (Windows)','Windows','office-2019-pc','Best Seller',0,129.99,349.99,0.0,0,'/uploads/products/microsoft-office-2019-professional-plus-windows.webp','word,excel,powerpoint,outlook,access','US','SKU-D983C7E2',NULL,2019,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (13,'microsoft-office-home-business-2024-mac','Microsoft Office Home & Business 2024 (Mac)','Mac','office-2024-mac',NULL,0,194.99,249.99,0.0,0,'/uploads/products/microsoft-office-home-business-2024-mac.webp','word,excel,powerpoint,outlook','US','SKU-48D5F446',NULL,2024,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (14,'microsoft-office-home-2024-mac','Microsoft Office Home 2024 (Mac)','Mac','office-2024-mac','Hot Pick',1,126.99,199.99,0.0,0,'/uploads/products/microsoft-office-home-2024-mac.webp','word,excel,powerpoint','US','SKU-93BF0180',NULL,2024,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (15,'microsoft-office-2021-home-student-mac','Microsoft Office 2021 Home & Student (Mac)','Mac','office-2021-mac',NULL,0,114.99,149.99,0.0,0,'/uploads/products/microsoft-office-2021-home-student-mac.webp','word,excel,powerpoint','US','SKU-A65BC424',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (16,'microsoft-office-2021-home-business-mac','Microsoft Office 2021 Home & Business (Mac)','Mac','office-2021-mac',NULL,0,174.99,249.99,0.0,0,'/uploads/products/microsoft-office-2021-home-business-mac.webp','word,excel,powerpoint,outlook','US','SKU-2FE8BE6E',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (17,'microsoft-word-2021-mac-lifetime-license-no-subscription','Microsoft Word 2021 (Mac) Lifetime License No Subscription','Mac','office-2021-mac',NULL,0,117.99,149.99,0.0,0,'/uploads/products/microsoft-word-2021-mac-lifetime-license-no-subscription.webp','word','US','SKU-68E3855E',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (18,'microsoft-excel-2021-mac-lifetime-license-no-subscription','Microsoft Excel 2021 (Mac) Lifetime License No Subscription','Mac','office-2021-mac',NULL,0,119.99,149.99,0.0,0,'/uploads/products/microsoft-excel-2021-mac-lifetime-license-no-subscription.webp','excel','US','SKU-B7C9A47C',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (19,'microsoft-office-home-and-business-2019-mac','Microsoft Office Home And Business 2019 (Mac)','Mac','office-2019-mac',NULL,0,124.99,249.00,0.0,0,'/uploads/products/microsoft-office-home-and-business-2019-mac.webp','word,excel,powerpoint,outlook','US','SKU-72B817C3',NULL,2019,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (20,'microsoft-office-home-and-student-2019-mac','Microsoft Office Home And Student 2019 (Mac)','Mac','office-2019-mac',NULL,0,94.99,149.99,0.0,0,'/uploads/products/microsoft-office-home-and-student-2019-mac.webp','word,excel,powerpoint','US','SKU-AF38DA9F',NULL,2019,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (21,'windows-11-home','Windows 11 Home','Windows','windows-11',NULL,1,59.99,139.99,0.0,0,'/uploads/products/windows-11-home.webp','','US','SKU-58625CF0',NULL,NULL,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (22,'windows-11-pro','Windows 11 Pro','Windows','windows-11','Best Seller',1,79.99,199.99,0.0,0,'/uploads/products/windows-11-pro.webp','','US','SKU-DD7C7A56',NULL,NULL,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (23,'windows-10-home','Windows 10 Home','Windows','windows-10',NULL,0,49.99,139.99,0.0,0,'/uploads/products/windows-10-home.webp','','US','SKU-83CA6BCF',NULL,NULL,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (24,'windows-10-pro','Windows 10 Pro','Windows','windows-10','Best Seller',0,69.99,199.99,0.0,0,'/uploads/products/windows-10-pro.webp','','US','SKU-C807BFDF',NULL,NULL,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (25,'microsoft-project-2024-professional-pc','Microsoft Project 2024 Professional (PC)','Windows','microsoft-project','Best Seller',1,199.99,999.99,0.0,0,'/uploads/products/microsoft-project-2024-professional-pc.webp','','US','SKU-8DA49D6A',NULL,2024,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (26,'microsoft-project-professional-2021-pc','Microsoft Project Professional 2021 (PC)','Windows','microsoft-project',NULL,0,159.99,799.99,0.0,0,'/uploads/products/microsoft-project-professional-2021-pc.webp','','US','SKU-58BB62DF',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (27,'ms-project-professional-2019-pc','MS Project Professional 2019 (PC)','Windows','microsoft-project',NULL,0,129.99,699.99,0.0,0,'/uploads/products/ms-project-professional-2019-pc.webp','','US','SKU-4FAF9699',NULL,2019,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (28,'microsoft-visio-2024-professional-windows-pc','Microsoft Visio 2024 Professional (Windows PC)','Windows','microsoft-visio',NULL,1,199.99,249.99,0.0,0,'/uploads/products/microsoft-visio-2024-professional-windows-pc.webp','','US','SKU-0EF1E74E',NULL,2024,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (29,'microsoft-visio-2021-professional-windows-pc','Microsoft Visio 2021 Professional (Windows PC)','Windows','microsoft-visio',NULL,0,169.99,229.99,0.0,0,'/uploads/products/microsoft-visio-2021-professional-windows-pc.webp','','US','SKU-F96E899E',NULL,2021,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (30,'ms-visio-professional-2019-pc','MS Visio Professional 2019 (PC)','Windows','microsoft-visio',NULL,0,149.99,199.99,0.0,0,'/uploads/products/ms-visio-professional-2019-pc.webp','','US','SKU-4005073B',NULL,2019,'lifetime',NULL,'Microsoft',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (31,'bitdefender-premium-vpn-unlimited-devices-1-year','Bitdefender Premium VPN — Unlimited Devices, 1 Year','Windows','bitdefender',NULL,0,49.99,79.99,0.0,0,'/uploads/products/bitdefender-premium-vpn-unlimited-devices-1-year.webp','','US','SKU-9A2DB69A',NULL,NULL,'lifetime',NULL,'Bitdefender',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (32,'bitdefender-antivirus-for-mac-1-mac-1-year','Bitdefender Antivirus for Mac — 1 Mac, 1 Year','Mac','bitdefender',NULL,0,29.99,49.99,0.0,0,'/uploads/products/bitdefender-antivirus-for-mac-1-mac-1-year.webp','','US','SKU-2C0BD827',NULL,NULL,'lifetime',NULL,'Bitdefender',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (33,'bitdefender-antivirus-for-mac-1-mac-2-years','Bitdefender Antivirus for Mac — 1 Mac, 2 Years','Mac','bitdefender',NULL,0,49.99,79.99,0.0,0,'/uploads/products/bitdefender-antivirus-for-mac-1-mac-2-years.webp','','US','SKU-1E105BE9',NULL,NULL,'lifetime',NULL,'Bitdefender',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (34,'bitdefender-antivirus-for-mac-3-mac-1-year','Bitdefender Antivirus for Mac — 3 Mac, 1 Year','Mac','bitdefender',NULL,0,49.99,89.99,0.0,0,'/uploads/products/bitdefender-antivirus-for-mac-3-mac-1-year.webp','','US','SKU-484FB06F',NULL,NULL,'lifetime',NULL,'Bitdefender',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (35,'bitdefender-antivirus-for-mac-3-mac-2-years','Bitdefender Antivirus for Mac — 3 Mac, 2 Years','Mac','bitdefender',NULL,0,79.99,129.99,0.0,0,'/uploads/products/bitdefender-antivirus-for-mac-3-mac-2-years.webp','','US','SKU-73230F17',NULL,NULL,'lifetime',NULL,'Bitdefender',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (36,'bitdefender-small-office-security-5-devices-1-year','Bitdefender Small Office Security — 5 Devices, 1 Year','Windows','bitdefender',NULL,0,89.99,149.99,0.0,0,'/uploads/products/bitdefender-small-office-security-5-devices-1-year.webp','','US','SKU-3CDBADC9',NULL,NULL,'lifetime',NULL,'Bitdefender',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
INSERT IGNORE INTO `products` (`id`, `slug`, `name`, `platform`, `category`, `badge`, `is_new`, `price`, `original_price`, `rating`, `reviews`, `image`, `apps`, `region`, `sku`, `gtin`, `year`, `license_type`, `version`, `brand`, `description`, `meta_description`, `seo_refreshed_at`, `ai_summary`, `is_active`, `activation_url_mode`, `install_url_mode`, `activation_url`, `install_guide_url`, `installer_url`) VALUES (37,'mcafee-premium-individual-1-year-unlimited-devices-usa','McAfee+ Premium Individual - 1-Year / Unlimited Devices - USA','Windows','mcafee','Hot Pick',0,39.99,119.99,0.0,0,'/uploads/products/mcafee-premium-individual-1-year-unlimited-devices-usa.webp','','US','SKU-86C9E0D2',NULL,NULL,'lifetime',NULL,'McAfee',NULL,NULL,NULL,NULL,1,'ai','ai',NULL,NULL,NULL);
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `refund_requests` WRITE;
/*!40000 ALTER TABLE `refund_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `refund_requests` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `regional_pricing` WRITE;
/*!40000 ALTER TABLE `regional_pricing` DISABLE KEYS */;
/*!40000 ALTER TABLE `regional_pricing` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `regions` WRITE;
/*!40000 ALTER TABLE `regions` DISABLE KEYS */;
INSERT IGNORE INTO `regions` (`code`, `name`, `currency`, `currency_symbol`, `tax_rate`, `active`) VALUES ('AU','Australia','AUD','A$',0.1000,1);
INSERT IGNORE INTO `regions` (`code`, `name`, `currency`, `currency_symbol`, `tax_rate`, `active`) VALUES ('CA','Canada','CAD','C$',0.1300,1);
INSERT IGNORE INTO `regions` (`code`, `name`, `currency`, `currency_symbol`, `tax_rate`, `active`) VALUES ('EU','Europe','EUR','€',0.2000,1);
INSERT IGNORE INTO `regions` (`code`, `name`, `currency`, `currency_symbol`, `tax_rate`, `active`) VALUES ('UK','United Kingdom','GBP','£',0.2000,1);
INSERT IGNORE INTO `regions` (`code`, `name`, `currency`, `currency_symbol`, `tax_rate`, `active`) VALUES ('US','United States','USD','$',0.0000,1);
/*!40000 ALTER TABLE `regions` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `reviews` WRITE;
/*!40000 ALTER TABLE `reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `reviews` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('active_region','US','2026-06-12 11:24:38');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('ai_citations_last_run_at','2026-06-23 10:35:58','2026-06-23 10:35:58');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('api_token','76ccbedbe8bb1d9ff56dbcd863f1cb7728328c2896fd8732','2026-06-23 10:42:36');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('chat_schema_v3','1','2026-06-23 10:35:37');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('company_address','135 CAROLINA ST G2, VALLEJO, CA 94590','2026-06-12 15:00:47');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('company_email','services@maventechsoftware.com','2026-06-12 14:53:49');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('company_logo','/uploads/company/logo-mark.png','2026-06-12 14:53:58');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('company_name','Maventech','2026-06-12 14:58:17');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('company_phone','1-805-823-9961','2026-06-12 14:53:49');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('cron_token','0b8166da24a08c53bdd92d069f68b8a8bd643009','2026-06-23 10:35:43');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('email_template_html','','2026-06-12 10:10:17');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('email_template_subject','Your Microsoft product key — Order #{{order_number}}','2026-06-12 10:10:17');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_card_merchant_name','jskdfajkf','2026-06-12 13:06:59');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_card_provider','Stripe','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_card_public_key','','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_card_secret_key','','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_card_status','active','2026-06-23 10:44:06');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_card_webhook_secret','','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_card_webhook_url','/stripe-webhook.php','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_paypal_account_name','Five code','2026-06-12 13:02:04');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_paypal_client_id','','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_paypal_provider','PayPal','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_paypal_secret','','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_paypal_status','inactive','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_paypal_webhook_id','','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('gw_paypal_webhook_url','/paypal-webhook.php','2026-06-12 10:38:15');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('main_url','https://robot-rules.preview.emergentagent.com','2026-06-23 10:35:12');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('paypal_enabled','0','2026-06-12 10:10:17');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('schema_knows_about_cache','Bitdefender\nMcAfee\nMicrosoft','2026-06-23 10:35:25');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('schema_knows_about_cache_at','1782210925','2026-06-23 10:35:25');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('seo_bot_cron_token','17907e8807410116','2026-06-23 10:42:36');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('seo_bot_last_run_at','2026-06-23 10:35:58','2026-06-23 10:35:58');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('seo_health_probe_cache','{\"sitemap\":{\"ok\":true,\"detail\":\"HTTP 200 \\u00b7 application/xml; charset=UTF-8 \\u00b7 108,369 bytes\",\"code\":200,\"size\":108369,\"url\":\"http://127.0.0.1:3000/sitemap.xml\"},\"robots\":{\"ok\":true,\"detail\":\"HTTP 200 \\u00b7 text/plain; charset=UTF-8 \\u00b7 2,136 bytes\",\"code\":200,\"size\":2136,\"url\":\"http://127.0.0.1:3000/robots.txt\"},\"ai_txt\":{\"ok\":true,\"detail\":\"HTTP 200 \\u00b7 text/plain; charset=UTF-8 \\u00b7 1,875 bytes\",\"code\":200,\"size\":1875,\"url\":\"http://127.0.0.1:3000/ai.txt\"},\"llms_txt\":{\"ok\":true,\"detail\":\"HTTP 200 \\u00b7 text/plain; charset=UTF-8 \\u00b7 11,698 bytes\",\"code\":200,\"size\":11698,\"url\":\"http://127.0.0.1:3000/llms.txt\"},\"merchant\":{\"ok\":true,\"detail\":\"HTTP 200 \\u00b7 application/xml; charset=UTF-8 \\u00b7 56,418 bytes\",\"code\":200,\"size\":56418,\"url\":\"http://127.0.0.1:3000/merchant-feed.xml\"},\"indexnow\":{\"ok\":true,\"detail\":\"HTTP 200 \\u00b7 text/plain; charset=UTF-8 \\u00b7 32 bytes\",\"code\":200,\"size\":32,\"url\":\"http://127.0.0.1:3000/70637aac3d7f11fb25ebff658800f8ac.txt\"},\"schema\":{\"ok\":true,\"detail\":\"2 JSON-LD blocks on home page\",\"code\":200,\"size\":115169,\"url\":\"http://127.0.0.1:3000/\",\"blocks\":2},\"_ts\":\"2026-06-23 10:42:36\",\"_site\":\"http://127.0.0.1:3000\"}','2026-06-23 10:42:36');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('seo_indexnow_key','70637aac3d7f11fb25ebff658800f8ac','2026-06-23 10:35:58');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('site_domain_url','https://robot-rules.preview.emergentagent.com','2026-06-23 10:35:12');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('staff_schema_v1','1','2026-06-23 10:41:06');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('statement_name_card','MAVENTECH','2026-06-12 10:10:17');
INSERT IGNORE INTO `settings` (`k`, `v`, `updated_at`) VALUES ('statement_name_paypal','MAVENTECH LLC','2026-06-12 10:10:17');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `testimonials` WRITE;
/*!40000 ALTER TABLE `testimonials` DISABLE KEYS */;
INSERT IGNORE INTO `testimonials` (`id`, `name`, `initials`, `location`, `product`, `text`, `rating`) VALUES (1,'Brandon Koch','BK','Mn, USA','Microsoft Office','I ordered software from them and when it didn\'t work I called the customer service and I could not understand the guy, he spoke very poor English and I couldn\'t even understand what he said. So I just canceled it and then he didn\'t want to refund me. I would not deal with them again.',3);
INSERT IGNORE INTO `testimonials` (`id`, `name`, `initials`, `location`, `product`, `text`, `rating`) VALUES (2,'Alyssa Dickens','AD','USA','Microsoft Office','Overall the site was easy to use and had clear step-by-step instructions.',5);
INSERT IGNORE INTO `testimonials` (`id`, `name`, `initials`, `location`, `product`, `text`, `rating`) VALUES (3,'ALBERT CHAPMAN','AC','New York, USA','Microsoft Office','I like the price. The only problem was that Microsoft stopped it from working and I had to get the company to fix the problem which they did in a timely manner.',4);
INSERT IGNORE INTO `testimonials` (`id`, `name`, `initials`, `location`, `product`, `text`, `rating`) VALUES (4,'Mike H','MH','NH, USA','Microsoft Office','Easy to find the software I needed: Office & VISIO. The staff were amazing after I had some initial machine compatibility issues. They resolved everything so quickly. I\'ve got a full set of current software. Mike',5);
INSERT IGNORE INTO `testimonials` (`id`, `name`, `initials`, `location`, `product`, `text`, `rating`) VALUES (5,'bill delp','BD','USA','Microsoft Office','The software works. Had to load a new laptop.',5);
INSERT IGNORE INTO `testimonials` (`id`, `name`, `initials`, `location`, `product`, `text`, `rating`) VALUES (6,'Dana','D','Nebraska, USA','Microsoft Office','I never got help and never got my software installed.',2);
/*!40000 ALTER TABLE `testimonials` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `transaction_logs` WRITE;
/*!40000 ALTER TABLE `transaction_logs` DISABLE KEYS */;
INSERT IGNORE INTO `transaction_logs` (`id`, `gateway`, `transaction_id`, `order_id`, `amount`, `currency`, `status`, `raw_response`, `created_at`) VALUES (1,'card','ch_3PXXXyMaventechABC123',2,229.99,'USD','paid',NULL,'2026-06-12 10:46:40');
INSERT IGNORE INTO `transaction_logs` (`id`, `gateway`, `transaction_id`, `order_id`, `amount`, `currency`, `status`, `raw_response`, `created_at`) VALUES (2,'card','ch_3PXXXyMaventechDEF456',NULL,99.99,'USD','failed',NULL,'2026-06-12 10:46:40');
INSERT IGNORE INTO `transaction_logs` (`id`, `gateway`, `transaction_id`, `order_id`, `amount`, `currency`, `status`, `raw_response`, `created_at`) VALUES (3,'paypal','PAYID-XYZ123ABC',NULL,49.99,'USD','paid',NULL,'2026-06-12 10:46:40');
INSERT IGNORE INTO `transaction_logs` (`id`, `gateway`, `transaction_id`, `order_id`, `amount`, `currency`, `status`, `raw_response`, `created_at`) VALUES (6,'card','TEST_8772F1A4FE73',4,209.99,'USD','test',NULL,'2026-06-23 10:44:06');
/*!40000 ALTER TABLE `transaction_logs` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT IGNORE INTO `users` (`id`, `email`, `name`, `password_hash`, `role`, `created_at`, `username`, `department`, `permissions`, `active`) VALUES (1,'admin@maventechsoftware.com','Admin','$2y$10$SyFgzuu.aEUJH/QqX/m50uJTNrHT2CDuWkR3KKnpVuCJj0ZaTuVP.','admin','2026-06-12 09:43:27',NULL,'',NULL,1);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `subscription_plans` WRITE;
/*!40000 ALTER TABLE `subscription_plans` DISABLE KEYS */;
INSERT IGNORE INTO `subscription_plans` (`id`, `slug`, `name`, `tagline`, `tenure_label`, `duration_months`, `price`, `devices`, `features_json`, `sort_order`, `active`, `created_at`, `updated_at`, `icon_image`) VALUES (1,'quick-fix','Quick Fix','One-time service · single session','One-Time Service',0,0.00,'1 Device','[\"Immediate issue resolution\",\"Virus and malware removal\",\"PC performance optimization\",\"Software installation and setup\",\"Printer and peripheral configuration\",\"Email setup and troubleshooting\",\"Internet and Wi-Fi troubleshooting\",\"Operating system error fixes\",\"Driver updates\",\"Browser issues and cleanup\",\"Basic data backup assistance\",\"Microsoft Office troubleshooting\",\"One-time security health check\"]',10,1,'2026-06-23 10:35:25','2026-06-23 10:35:25','/assets/images/subscriptions/quick-fix.png');
INSERT IGNORE INTO `subscription_plans` (`id`, `slug`, `name`, `tagline`, `tenure_label`, `duration_months`, `price`, `devices`, `features_json`, `sort_order`, `active`, `created_at`, `updated_at`, `icon_image`) VALUES (2,'starter-care','Starter Care','Unlimited remote support for 1 year','1 Year',12,0.00,'1 Device','[\"Unlimited remote support for 1 year\",\"Unlimited software troubleshooting\",\"Operating system support\",\"Email and account assistance\",\"Security and antivirus support\",\"Device health checks\",\"Performance tune-ups\",\"Software updates assistance\",\"Printer and scanner support\",\"Browser and application support\",\"New software installation assistance\",\"Data backup guidance\",\"Monthly maintenance recommendations\"]',20,1,'2026-06-23 10:35:25','2026-06-23 10:35:25','/assets/images/subscriptions/starter-care.png');
INSERT IGNORE INTO `subscription_plans` (`id`, `slug`, `name`, `tagline`, `tenure_label`, `duration_months`, `price`, `devices`, `features_json`, `sort_order`, `active`, `created_at`, `updated_at`, `icon_image`) VALUES (3,'pro-shield','Pro Shield','Transferable protection · up to 3 devices','3 Years',36,0.00,'Up to 3 Devices','[\"Transferable device protection\",\"Device replacement enrollment\",\"Advanced malware and security support\",\"Network and Wi-Fi optimization\",\"Multi-device maintenance\",\"Priority support queue\",\"Annual security audits\",\"Cloud storage setup assistance\",\"Advanced software troubleshooting\",\"Device migration support\",\"Operating system upgrade assistance\",\"Productivity software support\"]',30,1,'2026-06-23 10:35:25','2026-06-23 10:35:25','/assets/images/subscriptions/pro-shield.png');
INSERT IGNORE INTO `subscription_plans` (`id`, `slug`, `name`, `tagline`, `tenure_label`, `duration_months`, `price`, `devices`, `features_json`, `sort_order`, `active`, `created_at`, `updated_at`, `icon_image`) VALUES (4,'lifetime-elite','Lifetime Elite','10 years support · unlimited devices','10 Years Support',120,0.00,'Unlimited Devices','[\"Unlimited device coverage\",\"Unlimited device transfers\",\"Premium priority support\",\"Dedicated support specialists\",\"Comprehensive security assistance\",\"Advanced malware and ransomware guidance\",\"New device onboarding assistance\",\"Device replacement support\",\"Remote setup for computers, printers, and peripherals\",\"Cloud account support\",\"Data migration assistance\",\"System optimization services\",\"Annual technology health reviews\",\"Personalized technical guidance\",\"Priority scheduling\",\"Family and business device support\"]',40,1,'2026-06-23 10:35:25','2026-06-23 10:35:25','/assets/images/subscriptions/lifetime-elite.png');
/*!40000 ALTER TABLE `subscription_plans` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


SET FOREIGN_KEY_CHECKS=1;

-- ============================================================
-- Per-product Activation / Installation-guide / Installer URLs.
-- install_guide_url points to our OWN native guide page
-- (/install-guide.php?slug=...), rendered by includes/install-guides.php.
-- Antivirus products (Bitdefender/McAfee) are intentionally omitted.
-- ============================================================
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-2024-professional-plus-windows', installer_url='https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-2024-professional-plus-windows';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-2024-professional-plus-lifetime-license-windows-pc', installer_url='https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-2024-professional-plus-lifetime-license-windows-pc';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-home-2024-pc', installer_url='https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_standard_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-home-2024-pc';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-home-business-2024-pc', installer_url='https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_standard_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-home-business-2024-pc';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-2021-home-business-windows', installer_url='https://download.winandoffice.com/Retail/Office/EN/HomeBusiness2021Retail.iso', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-2021-home-business-windows';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-2021-professional-plus-windows', installer_url='https://download.winandoffice.com/Volume/office/2021/EN/Office_2021_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-2021-professional-plus-windows';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-2021-home-student-windows', installer_url='https://download.winandoffice.com/Retail/Office/EN/HomeStudent2021Retail.iso', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-2021-home-student-windows';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-word-2021-windows', installer_url='https://download.winandoffice.com/Volume/office/2021/EN/Office_2021_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-word-2021-windows';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-excel-2021-windows', installer_url='https://download.winandoffice.com/Volume/office/2021/EN/Office_2021_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-excel-2021-windows';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-2019-home-student-windows', installer_url='https://download.winandoffice.com/Volume/office/2019/EN/Office_2019_EN_standard_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-2019-home-student-windows';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-2019-home-business-pc', installer_url='https://download.winandoffice.com/Volume/office/2019/EN/Office_2019_EN_standard_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-2019-home-business-pc';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-2019-professional-plus-windows', installer_url='https://download.winandoffice.com/Volume/office/2019/EN/Office_2019_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-2019-professional-plus-windows';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-home-business-2024-mac', installer_url=NULL, activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-home-business-2024-mac';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-home-2024-mac', installer_url=NULL, activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-home-2024-mac';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-2021-home-student-mac', installer_url=NULL, activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-2021-home-student-mac';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-2021-home-business-mac', installer_url=NULL, activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-2021-home-business-mac';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-word-2021-mac-lifetime-license-no-subscription', installer_url=NULL, activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-word-2021-mac-lifetime-license-no-subscription';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-excel-2021-mac-lifetime-license-no-subscription', installer_url=NULL, activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-excel-2021-mac-lifetime-license-no-subscription';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-home-and-business-2019-mac', installer_url=NULL, activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-home-and-business-2019-mac';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-office-home-and-student-2019-mac', installer_url=NULL, activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-office-home-and-student-2019-mac';
UPDATE products SET activation_url='https://account.microsoft.com/account', install_guide_url='/install-guide.php?slug=windows-11-home', installer_url='https://download.winandoffice.com/Retail/Desktop/MediaCreationTool.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='windows-11-home';
UPDATE products SET activation_url='https://account.microsoft.com/account', install_guide_url='/install-guide.php?slug=windows-11-pro', installer_url='https://download.winandoffice.com/Retail/Desktop/MediaCreationTool.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='windows-11-pro';
UPDATE products SET activation_url='https://account.microsoft.com/account', install_guide_url='/install-guide.php?slug=windows-10-home', installer_url='https://download.winandoffice.com/Retail/Desktop/MediaCreationTool22H2.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='windows-10-home';
UPDATE products SET activation_url='https://account.microsoft.com/account', install_guide_url='/install-guide.php?slug=windows-10-pro', installer_url='https://download.winandoffice.com/Retail/Desktop/MediaCreationTool22H2.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='windows-10-pro';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-project-2024-professional-pc', installer_url='https://download.winandoffice.com/Volume/project/2024/EN/project_2024_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-project-2024-professional-pc';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-project-professional-2021-pc', installer_url='https://download.winandoffice.com/Volume/project/2021/EN/project_2021_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-project-professional-2021-pc';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=ms-project-professional-2019-pc', installer_url='https://download.winandoffice.com/Volume/project/2019/EN/project_2019_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='ms-project-professional-2019-pc';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-visio-2024-professional-windows-pc', installer_url='https://download.winandoffice.com/Volume/visio/2024/EN/visio_2024_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-visio-2024-professional-windows-pc';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=microsoft-visio-2021-professional-windows-pc', installer_url='https://download.winandoffice.com/Volume/visio/2021/EN/visio_2021_EN_pro_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='microsoft-visio-2021-professional-windows-pc';
UPDATE products SET activation_url='https://setup.office.com', install_guide_url='/install-guide.php?slug=ms-visio-professional-2019-pc', installer_url='https://download.winandoffice.com/Volume/visio/2019/EN/visio_2019_EN_64Bits.exe', activation_url_mode='manual', install_url_mode='manual' WHERE slug='ms-visio-professional-2019-pc';
