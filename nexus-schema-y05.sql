/* =========================================================
   KINGDOM NEXUS — y05 schema (CANÓNICO / FRESH INSTALL)

   Reglas:
   - No data dumps
   - No AUTO_INCREMENT=seed values
   - PK + UNIQUE + INDEX + FK preservados
   - InnoDB + utf8mb4_unicode_ci consistente
   - Atomic Payments Ready (orders/payments/webhook_events)
   ========================================================= */

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;

/* =========================================================
   CORE: USERS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_users`;
CREATE TABLE `y05_knx_users` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','manager','menu_uploader','hub_management','driver','customer','user') DEFAULT 'user',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `idx_name` (`name`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   GEO: CITIES
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_cities`;
CREATE TABLE `y05_knx_cities` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'USA',
  `status` enum('active','inactive') DEFAULT 'active',
  `is_operational` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_name` (`name`),
  KEY `idx_is_operational` (`is_operational`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   HUBS: CATEGORIES
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_hub_categories`;
CREATE TABLE `y05_knx_hub_categories` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort_order` (`sort_order`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   HUBS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_hubs`;
CREATE TABLE `y05_knx_hubs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `slug` varchar(191) DEFAULT NULL COMMENT 'SEO-friendly URL slug',
  `tagline` varchar(255) DEFAULT NULL,
  `city_id` bigint UNSIGNED DEFAULT NULL,
  `category_id` bigint UNSIGNED DEFAULT NULL,
  `address` text,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `delivery_radius` decimal(5,2) DEFAULT '5.00',
  `delivery_zone_type` enum('radius','polygon') DEFAULT 'radius',
  `delivery_available` tinyint(1) DEFAULT '1',
  `pickup_available` tinyint(1) DEFAULT '1',
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `hero_img` varchar(500) DEFAULT NULL,
  `type` enum('Restaurant','Food Truck','Cottage Food') DEFAULT 'Restaurant',
  `rating` decimal(2,1) DEFAULT '4.5',
  `cuisines` text,
  `status` enum('active','inactive') DEFAULT 'active',
  `hours_monday` varchar(50) DEFAULT NULL,
  `hours_tuesday` varchar(50) DEFAULT NULL,
  `hours_wednesday` varchar(50) DEFAULT NULL,
  `hours_thursday` varchar(50) DEFAULT NULL,
  `hours_friday` varchar(50) DEFAULT NULL,
  `hours_saturday` varchar(50) DEFAULT NULL,
  `hours_sunday` varchar(50) DEFAULT NULL,
  `closure_start` date DEFAULT NULL,
  `closure_until` datetime DEFAULT NULL,
  `closure_reason` text,
  `timezone` varchar(50) DEFAULT 'America/Chicago',
  `currency` varchar(3) DEFAULT 'USD',
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `min_order` decimal(10,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `is_featured` tinyint(1) DEFAULT '0',
  `closure_end` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_city_id` (`city_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_name` (`name`),
  KEY `idx_rating` (`rating`),
  KEY `idx_delivery_zone_type` (`delivery_zone_type`),
  KEY `idx_hub_slug` (`slug`),
  KEY `idx_is_featured` (`is_featured`),
  CONSTRAINT `fk_hubs_city` FOREIGN KEY (`city_id`) REFERENCES `y05_knx_cities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hubs_category` FOREIGN KEY (`category_id`) REFERENCES `y05_knx_hub_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   ADDONS: GROUPS + ADDONS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_addons`;
DROP TABLE IF EXISTS `y05_knx_addon_groups`;

CREATE TABLE `y05_knx_addon_groups` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `description` text,
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hub_id` (`hub_id`),
  KEY `idx_hub_sort` (`hub_id`,`sort_order`),
  KEY `idx_name` (`name`),
  CONSTRAINT `fk_addon_groups_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_addons` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sort_order` int UNSIGNED DEFAULT '0',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_group_sort` (`group_id`,`sort_order`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_addons_group` FOREIGN KEY (`group_id`) REFERENCES `y05_knx_addon_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   ITEMS: CATEGORIES + ITEMS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_hub_items`;
DROP TABLE IF EXISTS `y05_knx_items_categories`;

CREATE TABLE `y05_knx_items_categories` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `hub_id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `sort_order` int UNSIGNED DEFAULT '0',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hub_id` (`hub_id`),
  KEY `idx_hub_sort` (`hub_id`,`sort_order`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_items_categories_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_hub_items` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `hub_id` bigint UNSIGNED NOT NULL,
  `category_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(191) NOT NULL,
  `description` text,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `image_url` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hub_id` (`hub_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_hub_category_sort` (`hub_id`,`category_id`,`sort_order`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_hub_items_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hub_items_category` FOREIGN KEY (`category_id`) REFERENCES `y05_knx_items_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   MODIFIERS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_modifier_options`;
DROP TABLE IF EXISTS `y05_knx_item_modifiers`;
DROP TABLE IF EXISTS `y05_knx_item_global_modifiers`;
DROP TABLE IF EXISTS `y05_knx_item_addon_groups`;

CREATE TABLE `y05_knx_item_modifiers` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` bigint UNSIGNED DEFAULT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `type` varchar(20) DEFAULT 'single',
  `required` tinyint(1) DEFAULT '0',
  `min_selection` int UNSIGNED DEFAULT '0',
  `max_selection` int UNSIGNED DEFAULT NULL,
  `is_global` tinyint(1) DEFAULT '0',
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_hub_id` (`hub_id`),
  KEY `idx_is_global` (`is_global`),
  KEY `idx_item_sort` (`item_id`,`sort_order`),
  KEY `idx_hub_global_sort` (`hub_id`,`is_global`,`sort_order`),
  CONSTRAINT `fk_item_modifiers_item` FOREIGN KEY (`item_id`) REFERENCES `y05_knx_hub_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_modifiers_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_modifier_options` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `modifier_id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `price_adjustment` decimal(10,2) DEFAULT '0.00',
  `is_default` tinyint(1) DEFAULT '0',
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_modifier_id` (`modifier_id`),
  KEY `idx_modifier_sort` (`modifier_id`,`sort_order`),
  CONSTRAINT `fk_modifier_options_modifier` FOREIGN KEY (`modifier_id`) REFERENCES `y05_knx_item_modifiers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_item_addon_groups` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` bigint UNSIGNED NOT NULL,
  `group_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_group` (`item_id`,`group_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_group_id` (`group_id`),
  CONSTRAINT `fk_item_addon_groups_item` FOREIGN KEY (`item_id`) REFERENCES `y05_knx_hub_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_addon_groups_group` FOREIGN KEY (`group_id`) REFERENCES `y05_knx_addon_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_item_global_modifiers` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` bigint UNSIGNED NOT NULL,
  `global_modifier_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_global` (`item_id`,`global_modifier_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_global_modifier_id` (`global_modifier_id`),
  CONSTRAINT `fk_item_global_modifiers_item` FOREIGN KEY (`item_id`) REFERENCES `y05_knx_hub_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_global_modifiers_modifier` FOREIGN KEY (`global_modifier_id`) REFERENCES `y05_knx_item_modifiers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   ADDRESSES
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_addresses`;
CREATE TABLE `y05_knx_addresses` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` bigint UNSIGNED NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `line1` varchar(255) NOT NULL,
  `line2` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `postal_code` varchar(30) DEFAULT NULL,
  `country` varchar(80) DEFAULT 'USA',
  `delivery_instructions` text,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `default_customer_id` bigint UNSIGNED GENERATED ALWAYS AS (
    IF(((`is_default` = 1) AND (`status` = _utf8mb4'active') AND (`deleted_at` IS NULL)), `customer_id`, NULL)
  ) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_default_address_per_customer` (`default_customer_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_latlng` (`latitude`,`longitude`),
  KEY `idx_customer_default_lookup` (`customer_id`,`is_default`,`status`,`deleted_at`),
  CONSTRAINT `fk_addresses_customer` FOREIGN KEY (`customer_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   CARTS + CART ITEMS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_cart_items`;
DROP TABLE IF EXISTS `y05_knx_carts`;

CREATE TABLE `y05_knx_carts` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token` varchar(64) NOT NULL,
  `customer_id` bigint UNSIGNED DEFAULT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('active','abandoned','converted') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `active_session_token` varchar(64) GENERATED ALWAYS AS (IF((`status` = _utf8mb4'active'), `session_token`, NULL)) VIRTUAL,
  `active_customer_id` bigint UNSIGNED GENERATED ALWAYS AS (IF((`status` = _utf8mb4'active'), `customer_id`, NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_active_session_hub` (`active_session_token`,`hub_id`),
  UNIQUE KEY `uq_active_customer_hub` (`active_customer_id`,`hub_id`),
  KEY `idx_session_token` (`session_token`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_hub_id` (`hub_id`),
  KEY `idx_status` (`status`),
  KEY `idx_session_status` (`session_token`,`status`),
  CONSTRAINT `fk_carts_customer` FOREIGN KEY (`customer_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_carts_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_cart_items` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_id` bigint UNSIGNED NOT NULL,
  `item_id` bigint UNSIGNED DEFAULT NULL,
  `name_snapshot` varchar(255) NOT NULL,
  `image_snapshot` varchar(500) DEFAULT NULL,
  `quantity` int UNSIGNED NOT NULL DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `modifiers_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cart_id` (`cart_id`),
  KEY `idx_item_id` (`item_id`),
  CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`) REFERENCES `y05_knx_carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cart_items_item` FOREIGN KEY (`item_id`) REFERENCES `y05_knx_hub_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   COUPONS + REDEMPTIONS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_coupon_redemptions`;
DROP TABLE IF EXISTS `y05_knx_coupons`;

CREATE TABLE `y05_knx_coupons` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `value` decimal(10,2) NOT NULL DEFAULT '0.00',
  `min_subtotal` decimal(10,2) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `usage_limit` int UNSIGNED DEFAULT NULL,
  `used_count` int UNSIGNED NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_coupon_redemptions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` bigint UNSIGNED DEFAULT NULL,
  `coupon_code` varchar(50) NOT NULL,
  `order_id` bigint UNSIGNED DEFAULT NULL,
  `customer_id` bigint UNSIGNED DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_coupon_id` (`coupon_id`),
  KEY `idx_coupon_code` (`coupon_code`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_customer_id` (`customer_id`),
  CONSTRAINT `fk_coupon_redemptions_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `y05_knx_coupons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coupon_redemptions_order` FOREIGN KEY (`order_id`) REFERENCES `y05_knx_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coupon_redemptions_customer` FOREIGN KEY (`customer_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   DELIVERY: RATES + ZONES + FEE RULES
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_delivery_fee_rules`;
DROP TABLE IF EXISTS `y05_knx_delivery_zones`;
DROP TABLE IF EXISTS `y05_knx_delivery_rates`;

CREATE TABLE `y05_knx_delivery_rates` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `city_id` bigint UNSIGNED NOT NULL,
  `flat_rate` decimal(10,2) NOT NULL DEFAULT '0.00',
  `rate_per_distance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `distance_unit` enum('mile','kilometer') NOT NULL DEFAULT 'mile',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `eta_base_minutes` int UNSIGNED DEFAULT NULL,
  `eta_per_distance_minutes` decimal(6,2) DEFAULT NULL,
  `eta_buffer_minutes` int UNSIGNED DEFAULT NULL,
  `zone_name` varchar(100) DEFAULT NULL,
  `base_rate` decimal(10,2) NOT NULL DEFAULT '0.00',
  `per_mile_rate` decimal(10,2) DEFAULT '0.00',
  `min_order` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_city` (`city_id`),
  KEY `idx_status` (`status`),
  KEY `idx_city_id` (`city_id`),
  CONSTRAINT `fk_delivery_rates_city` FOREIGN KEY (`city_id`) REFERENCES `y05_knx_cities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_delivery_zones` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `hub_id` bigint UNSIGNED NOT NULL,
  `zone_name` varchar(100) DEFAULT 'Main Delivery Area',
  `polygon_points` json NOT NULL COMMENT 'Array of [lat, lng] coordinates',
  `fill_color` varchar(7) DEFAULT '#0b793a' COMMENT 'Hex color for polygon fill',
  `fill_opacity` decimal(3,2) DEFAULT '0.35' COMMENT 'Opacity 0.00-1.00',
  `stroke_color` varchar(7) DEFAULT '#0b793a' COMMENT 'Hex color for polygon border',
  `stroke_weight` int UNSIGNED DEFAULT '2' COMMENT 'Border width in pixels',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Enable/disable this zone',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hub_id` (`hub_id`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_hub_active` (`hub_id`,`is_active`),
  CONSTRAINT `fk_delivery_zones_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Polygon-based delivery zones for hubs';

CREATE TABLE `y05_knx_delivery_fee_rules` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `hub_id` bigint UNSIGNED DEFAULT NULL COMMENT 'Specific hub (highest priority)',
  `city_id` bigint UNSIGNED DEFAULT NULL COMMENT 'City-wide rule',
  `zone_id` bigint UNSIGNED DEFAULT NULL COMMENT 'Specific delivery zone',
  `rule_name` varchar(100) NOT NULL COMMENT 'User-friendly name',
  `is_active` tinyint(1) DEFAULT '1',
  `priority` int DEFAULT '0' COMMENT 'Higher priority rules applied first',
  `fee_type` enum('flat','distance_based','subtotal_based','tiered') DEFAULT 'distance_based',
  `flat_fee` decimal(10,2) DEFAULT NULL COMMENT 'Fixed delivery fee (fee_type=flat)',
  `base_fee` decimal(10,2) DEFAULT NULL COMMENT 'Base fee + distance calculation',
  `per_km_rate` decimal(10,2) DEFAULT NULL COMMENT 'Fee per kilometer',
  `per_mile_rate` decimal(10,2) DEFAULT NULL COMMENT 'Fee per mile',
  `free_delivery_distance` decimal(6,2) DEFAULT NULL COMMENT 'Free delivery under this distance',
  `min_subtotal_free_delivery` decimal(10,2) DEFAULT NULL COMMENT 'Free delivery above this subtotal',
  `subtotal_percentage` decimal(5,2) DEFAULT NULL COMMENT 'Fee as % of subtotal',
  `min_fee` decimal(10,2) DEFAULT NULL COMMENT 'Minimum delivery fee',
  `max_fee` decimal(10,2) DEFAULT NULL COMMENT 'Maximum delivery fee cap',
  `max_distance_km` decimal(6,2) DEFAULT NULL COMMENT 'Max deliverable distance in km',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hub_active` (`hub_id`,`is_active`),
  KEY `idx_city_active` (`city_id`,`is_active`),
  KEY `idx_zone_active` (`zone_id`,`is_active`),
  KEY `idx_priority` (`priority`),
  CONSTRAINT `fk_fee_rules_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fee_rules_city` FOREIGN KEY (`city_id`) REFERENCES `y05_knx_cities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fee_rules_zone` FOREIGN KEY (`zone_id`) REFERENCES `y05_knx_delivery_zones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   OPS SETTINGS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_ops_settings`;
CREATE TABLE `y05_knx_ops_settings` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(80) NOT NULL,
  `setting_json` longtext NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   ORDERS (ATOMIC READY)
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_order_status_history`;
DROP TABLE IF EXISTS `y05_knx_order_items`;
DROP TABLE IF EXISTS `y05_knx_orders`;

CREATE TABLE `y05_knx_orders` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `city_id` bigint UNSIGNED DEFAULT NULL,
  `session_token` varchar(64) DEFAULT NULL,
  `customer_id` bigint UNSIGNED DEFAULT NULL,
  `fulfillment_type` enum('delivery','pickup') NOT NULL DEFAULT 'delivery',
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `delivery_address` text,
  `delivery_address_id` bigint UNSIGNED DEFAULT NULL,
  `delivery_lat` decimal(10,7) DEFAULT NULL,
  `delivery_lng` decimal(10,7) DEFAULT NULL,
  `delivery_distance` decimal(10,3) DEFAULT NULL,
  `delivery_duration_minutes` int UNSIGNED DEFAULT NULL,
  `estimated_delivery_at` datetime DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(6,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `delivery_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `software_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tip_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tip_percent` decimal(5,2) DEFAULT NULL,
  `tip_source` enum('none','preset','custom') DEFAULT 'none',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `coupon_code` varchar(50) DEFAULT NULL,
  `coupon_id` bigint UNSIGNED DEFAULT NULL,
  `gift_card_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `gift_card_code` varchar(64) DEFAULT NULL,
  `gift_card_id` bigint UNSIGNED DEFAULT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending_payment','placed','confirmed','preparing','ready','out_for_delivery','completed','cancelled') NOT NULL,
  `driver_id` bigint UNSIGNED DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL,
  `payment_status` enum('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `payment_transaction_id` varchar(255) DEFAULT NULL,
  `notes` text,
  `cart_snapshot` json DEFAULT NULL,
  `totals_snapshot` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_number` (`order_number`),
  KEY `idx_hub_id` (`hub_id`),
  KEY `idx_city_id` (`city_id`),
  KEY `idx_session_token` (`session_token`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_delivery_address_id` (`delivery_address_id`),
  KEY `idx_idempotency_probe` (`session_token`,`hub_id`,`customer_id`,`status`,`created_at`),
  KEY `idx_coupon_code` (`coupon_code`),
  KEY `idx_gift_card_code` (`gift_card_code`),
  CONSTRAINT `fk_orders_delivery_address` FOREIGN KEY (`delivery_address_id`) REFERENCES `y05_knx_addresses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_order_items` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` bigint UNSIGNED NOT NULL,
  `item_id` bigint UNSIGNED DEFAULT NULL,
  `name_snapshot` varchar(255) NOT NULL,
  `image_snapshot` text,
  `quantity` int UNSIGNED NOT NULL DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `modifiers_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_item_id` (`item_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `y05_knx_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_item` FOREIGN KEY (`item_id`) REFERENCES `y05_knx_hub_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_order_status_history` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` bigint UNSIGNED NOT NULL,
  `status` varchar(50) NOT NULL,
  `changed_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_order_status_history_order` FOREIGN KEY (`order_id`) REFERENCES `y05_knx_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   PAYMENTS (ATOMIC READY)
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_payments`;
CREATE TABLE `y05_knx_payments` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` bigint UNSIGNED NULL COMMENT 'Reference to knx_orders.id (nullable until atomic link)',
  `provider` varchar(50) NOT NULL COMMENT 'Payment provider: stripe, paypal, etc.',
  `provider_intent_id` varchar(255) NOT NULL COMMENT 'Provider payment intent ID',
  `checkout_attempt_key` varchar(128) NOT NULL COMMENT 'Idempotency key for atomic checkout attempt',
  `amount` int NOT NULL COMMENT 'Amount in cents',
  `currency` varchar(3) NOT NULL DEFAULT 'usd' COMMENT 'ISO 4217 currency code',
  `status` varchar(50) NOT NULL COMMENT 'intent_created, authorized, paid, failed, cancelled',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_intent` (`provider`,`provider_intent_id`),
  UNIQUE KEY `uniq_attempt_key` (`checkout_attempt_key`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Payment state authority for orders';

/* =========================================================
   WEBHOOK EVENTS (DEDUP)
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_webhook_events`;
CREATE TABLE `y05_knx_webhook_events` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL DEFAULT 'stripe',
  `event_id` varchar(255) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `intent_id` varchar(255) DEFAULT NULL,
  `order_id` bigint UNSIGNED DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_event_id` (`event_id`),
  KEY `idx_intent_id` (`intent_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_processed_at` (`processed_at`),
  CONSTRAINT `fk_webhook_events_order` FOREIGN KEY (`order_id`) REFERENCES `y05_knx_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   SESSIONS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_sessions`;
CREATE TABLE `y05_knx_sessions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `token` char(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   SETTINGS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_settings`;
CREATE TABLE `y05_knx_settings` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` longtext,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`),
  KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   TIP SETTINGS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_tip_settings`;
CREATE TABLE `y05_knx_tip_settings` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` enum('city','hub') NOT NULL DEFAULT 'city',
  `city_id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `presets_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scope_city_hub` (`scope`,`city_id`,`hub_id`),
  KEY `idx_city` (`city_id`),
  KEY `idx_hub` (`hub_id`),
  CONSTRAINT `fk_tip_settings_city` FOREIGN KEY (`city_id`) REFERENCES `y05_knx_cities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tip_settings_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   TAX RULES
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_tax_rules`;
CREATE TABLE `y05_knx_tax_rules` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` enum('city','hub') NOT NULL DEFAULT 'city',
  `scope_id` bigint UNSIGNED DEFAULT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `jurisdiction` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `priority` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scope_status` (`scope`,`status`),
  KEY `idx_scope_id` (`scope_id`),
  KEY `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   SOFTWARE FEES
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_software_fees`;
CREATE TABLE `y05_knx_software_fees` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` enum('city','hub') NOT NULL DEFAULT 'city',
  `city_id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `fee_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('active','inactive') NOT NULL DEFAULT 'inactive',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scope_city_hub` (`scope`,`city_id`,`hub_id`),
  KEY `idx_city` (`city_id`),
  KEY `idx_hub` (`hub_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_software_fees_city` FOREIGN KEY (`city_id`) REFERENCES `y05_knx_cities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_software_fees_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   GIFT CARDS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_gift_card_transactions`;
DROP TABLE IF EXISTS `y05_knx_gift_cards`;

CREATE TABLE `y05_knx_gift_cards` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `initial_amount_cents` int NOT NULL DEFAULT '0',
  `balance_cents` int NOT NULL DEFAULT '0',
  `currency` varchar(3) NOT NULL DEFAULT 'usd',
  `status` enum('active','disabled','spent') NOT NULL DEFAULT 'active',
  `expires_at` datetime DEFAULT NULL,
  `created_by_user_id` bigint UNSIGNED DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_created_by` (`created_by_user_id`),
  CONSTRAINT `fk_gift_cards_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_gift_card_transactions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `gift_card_id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED DEFAULT NULL,
  `type` enum('debit','credit') NOT NULL,
  `amount_cents` int NOT NULL,
  `meta_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gift_card_id` (`gift_card_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_type` (`type`),
  CONSTRAINT `fk_gift_card_tx_gift_card` FOREIGN KEY (`gift_card_id`) REFERENCES `y05_knx_gift_cards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gift_card_tx_order` FOREIGN KEY (`order_id`) REFERENCES `y05_knx_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   PUSH SUBSCRIPTIONS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_push_subscriptions`;
CREATE TABLE `y05_knx_push_subscriptions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `role` varchar(32) NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` varchar(255) NOT NULL,
  `auth` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_role` (`user_id`,`role`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_role` (`role`),
  KEY `idx_revoked_at` (`revoked_at`),
  CONSTRAINT `fk_push_subscriptions_user` FOREIGN KEY (`user_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   PASSWORD RESETS + EMAIL VERIFICATIONS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_password_resets`;
CREATE TABLE `y05_knx_password_resets` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `y05_knx_email_verifications`;
CREATE TABLE `y05_knx_email_verifications` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `token_hash` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   OPS / STAFF SCOPING
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_manager_cities`;
CREATE TABLE `y05_knx_manager_cities` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `manager_user_id` bigint UNSIGNED NOT NULL,
  `city_id` bigint UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_manager_city` (`manager_user_id`,`city_id`),
  KEY `idx_manager_user_id` (`manager_user_id`),
  KEY `idx_city_id` (`city_id`),
  CONSTRAINT `fk_manager_cities_manager_user` FOREIGN KEY (`manager_user_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_manager_cities_city` FOREIGN KEY (`city_id`) REFERENCES `y05_knx_cities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   DRIVERS + MAPPINGS + AVAILABILITY + OPS
   ========================================================= */
DROP TABLE IF EXISTS `y05_knx_driver_ops`;
DROP TABLE IF EXISTS `y05_knx_driver_hubs`;
DROP TABLE IF EXISTS `y05_knx_driver_availability`;
DROP TABLE IF EXISTS `y05_knx_driver_cities`;
DROP TABLE IF EXISTS `y05_knx_drivers`;

CREATE TABLE `y05_knx_drivers` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NULL,
  `full_name` varchar(190) NOT NULL,
  `phone` varchar(50) NULL,
  `email` varchar(190) NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` bigint UNSIGNED NULL DEFAULT NULL,
  `deleted_reason` varchar(255) NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_drivers_user` FOREIGN KEY (`user_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_drivers_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `y05_knx_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_driver_cities` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `driver_id` bigint UNSIGNED NOT NULL,
  `city_id` bigint UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_driver_city` (`driver_id`,`city_id`),
  KEY `idx_driver_id` (`driver_id`),
  KEY `idx_city_id` (`city_id`),
  CONSTRAINT `fk_driver_cities_driver` FOREIGN KEY (`driver_id`) REFERENCES `y05_knx_drivers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_driver_cities_city` FOREIGN KEY (`city_id`) REFERENCES `y05_knx_cities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_driver_availability` (
  `driver_user_id` bigint UNSIGNED NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'off',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`driver_user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_driver_availability_user` FOREIGN KEY (`driver_user_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_driver_hubs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `driver_id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_driver_hub` (`driver_id`,`hub_id`),
  KEY `idx_driver_id` (`driver_id`),
  KEY `idx_hub_id` (`hub_id`),
  CONSTRAINT `fk_driver_hubs_driver` FOREIGN KEY (`driver_id`) REFERENCES `y05_knx_drivers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_driver_hubs_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `y05_knx_driver_ops` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` bigint UNSIGNED NOT NULL,
  `driver_user_id` bigint UNSIGNED DEFAULT NULL,
  `assigned_by` bigint UNSIGNED DEFAULT NULL,
  `ops_status` varchar(30) NOT NULL DEFAULT 'unassigned',
  `assigned_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order` (`order_id`),
  KEY `idx_driver_user_id` (`driver_user_id`),
  KEY `idx_ops_status` (`ops_status`),
  KEY `idx_updated_at` (`updated_at`),
  CONSTRAINT `fk_driver_ops_order` FOREIGN KEY (`order_id`) REFERENCES `y05_knx_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_driver_ops_driver_user` FOREIGN KEY (`driver_user_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_driver_ops_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `y05_knx_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* =========================================================
   DONE
   ========================================================= */
SET FOREIGN_KEY_CHECKS = 1;
