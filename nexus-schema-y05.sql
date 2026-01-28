--
-- Servidor: localhost:3306
-- Tiempo de generación: 21-01-2026 a las 08:35:24
-- Versión del servidor: 8.0.44-35
-- Versión de PHP: 8.3.26




--
-- Base de datos: `oywwofte_WPAYY`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_addons`
--

CREATE TABLE `y05_knx_addons` (
  `id` bigint UNSIGNED NOT NULL,
  `group_id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sort_order` int UNSIGNED DEFAULT '0',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_addon_groups`
--

CREATE TABLE `y05_knx_addon_groups` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_addresses`
--

CREATE TABLE `y05_knx_addresses` (
  `id` bigint UNSIGNED NOT NULL,
  `customer_id` bigint UNSIGNED NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `line2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'USA',
  `delivery_instructions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `default_customer_id` bigint UNSIGNED GENERATED ALWAYS AS (if(((`is_default` = 1) and (`status` = _utf8mb4'active') and (`deleted_at` is null)),`customer_id`,NULL)) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_addresses`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_carts`
--

CREATE TABLE `y05_knx_carts` (
  `id` bigint UNSIGNED NOT NULL,
  `session_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` bigint UNSIGNED DEFAULT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('active','abandoned','converted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `active_session_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (if((`status` = _utf8mb4'active'),`session_token`,NULL)) VIRTUAL,
  `active_customer_id` bigint UNSIGNED GENERATED ALWAYS AS (if((`status` = _utf8mb4'active'),`customer_id`,NULL)) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_carts`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_cart_items`
--

CREATE TABLE `y05_knx_cart_items` (
  `id` bigint UNSIGNED NOT NULL,
  `cart_id` bigint UNSIGNED NOT NULL,
  `item_id` bigint UNSIGNED DEFAULT NULL,
  `name_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_snapshot` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int UNSIGNED NOT NULL DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `modifiers_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_cart_items`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_cities`
--

CREATE TABLE `y05_knx_cities` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `state` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'USA',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'active',
  `is_operational` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `y05_knx_cities`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_coupons`
--

CREATE TABLE `y05_knx_coupons` (
  `id` bigint UNSIGNED NOT NULL,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percent',
  `value` decimal(10,2) NOT NULL DEFAULT '0.00',
  `min_subtotal` decimal(10,2) DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `usage_limit` int UNSIGNED DEFAULT NULL,
  `used_count` int UNSIGNED NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_coupons`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_coupon_redemptions`
--

CREATE TABLE `y05_knx_coupon_redemptions` (
  `id` bigint UNSIGNED NOT NULL,
  `coupon_id` bigint UNSIGNED DEFAULT NULL,
  `coupon_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` bigint UNSIGNED DEFAULT NULL,
  `customer_id` bigint UNSIGNED DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_delivery_fee_rules`
--

CREATE TABLE `y05_knx_delivery_fee_rules` (
  `id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED DEFAULT NULL COMMENT 'Specific hub (highest priority)',
  `city_id` bigint UNSIGNED DEFAULT NULL COMMENT 'City-wide rule',
  `zone_id` bigint UNSIGNED DEFAULT NULL COMMENT 'Specific delivery zone',
  `rule_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'User-friendly name',
  `is_active` tinyint(1) DEFAULT '1',
  `priority` int DEFAULT '0' COMMENT 'Higher priority rules applied first',
  `fee_type` enum('flat','distance_based','subtotal_based','tiered') COLLATE utf8mb4_unicode_ci DEFAULT 'distance_based',
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
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_delivery_fee_rules`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_delivery_rates`
--

CREATE TABLE `y05_knx_delivery_rates` (
  `id` bigint UNSIGNED NOT NULL,
  `city_id` bigint UNSIGNED NOT NULL,
  `flat_rate` decimal(10,2) NOT NULL DEFAULT '0.00',
  `rate_per_distance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `distance_unit` enum('mile','kilometer') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'mile',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `eta_base_minutes` int UNSIGNED DEFAULT NULL COMMENT 'Base prep/dispatch minutes added to ETA (nullable)',
  `eta_per_distance_minutes` decimal(6,2) DEFAULT NULL COMMENT 'Minutes per mile/km (depends on distance_unit) (nullable)',
  `eta_buffer_minutes` int UNSIGNED DEFAULT NULL COMMENT 'Extra buffer minutes (nullable)',
  `zone_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `base_rate` decimal(10,2) NOT NULL DEFAULT '0.00',
  `per_mile_rate` decimal(10,2) DEFAULT '0.00',
  `min_order` decimal(10,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `y05_knx_delivery_rates`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_delivery_zones`
--

CREATE TABLE `y05_knx_delivery_zones` (
  `id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `zone_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'Main Delivery Area',
  `polygon_points` json NOT NULL COMMENT 'Array of [lat, lng] coordinates',
  `fill_color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT '#0b793a' COMMENT 'Hex color for polygon fill',
  `fill_opacity` decimal(3,2) DEFAULT '0.35' COMMENT 'Opacity 0.00-1.00',
  `stroke_color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT '#0b793a' COMMENT 'Hex color for polygon border',
  `stroke_weight` int UNSIGNED DEFAULT '2' COMMENT 'Border width in pixels',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Enable/disable this zone',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Polygon-based delivery zones for hubs';

--
-- Volcado de datos para la tabla `y05_knx_delivery_zones`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_drivers`
--

CREATE TABLE `y05_knx_drivers` (
  `id` bigint UNSIGNED NOT NULL,
  `driver_user_id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `full_name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `vehicle_info` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_drivers`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_driver_availability`
--

CREATE TABLE `y05_knx_driver_availability` (
  `driver_user_id` int NOT NULL,
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_driver_availability`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_driver_hubs`
--

CREATE TABLE `y05_knx_driver_hubs` (
  `id` bigint UNSIGNED NOT NULL,
  `driver_id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_driver_hubs`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_driver_ops`
--

CREATE TABLE `y05_knx_driver_ops` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL,
  `driver_user_id` bigint UNSIGNED DEFAULT NULL,
  `assigned_by` bigint UNSIGNED DEFAULT NULL,
  `ops_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unassigned',
  `assigned_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_driver_ops`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_gift_cards`
--

CREATE TABLE `y05_knx_gift_cards` (
  `id` bigint UNSIGNED NOT NULL,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `initial_amount_cents` int NOT NULL DEFAULT '0',
  `balance_cents` int NOT NULL DEFAULT '0',
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usd',
  `status` enum('active','disabled','spent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `expires_at` datetime DEFAULT NULL,
  `created_by_user_id` bigint UNSIGNED DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_gift_card_transactions`
--

CREATE TABLE `y05_knx_gift_card_transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `gift_card_id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED DEFAULT NULL,
  `type` enum('debit','credit') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_cents` int NOT NULL,
  `meta_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_hubs`
--

CREATE TABLE `y05_knx_hubs` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `slug` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL COMMENT 'SEO-friendly URL slug',
  `tagline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `city_id` bigint UNSIGNED DEFAULT NULL,
  `category_id` bigint UNSIGNED DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `delivery_radius` decimal(5,2) DEFAULT '5.00' COMMENT 'Legacy: Delivery radius in miles (used when delivery_zone_type=radius)',
  `delivery_zone_type` enum('radius','polygon') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'radius' COMMENT 'Type of delivery zone: radius (legacy) or polygon (custom area)',
  `delivery_available` tinyint(1) DEFAULT '1',
  `pickup_available` tinyint(1) DEFAULT '1',
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `email` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `logo_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `hero_img` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `type` enum('Restaurant','Food Truck','Cottage Food') COLLATE utf8mb4_unicode_520_ci DEFAULT 'Restaurant',
  `rating` decimal(2,1) DEFAULT '4.5',
  `cuisines` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'active',
  `hours_monday` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `hours_tuesday` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `hours_wednesday` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `hours_thursday` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `hours_friday` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `hours_saturday` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `hours_sunday` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `closure_start` date DEFAULT NULL,
  `closure_until` datetime DEFAULT NULL,
  `closure_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `timezone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'America/Chicago' COMMENT 'IANA timezone identifier',
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'USD' COMMENT 'ISO 4217 currency code',
  `tax_rate` decimal(5,2) DEFAULT '0.00' COMMENT 'Tax percentage (e.g., 8.25 for 8.25%)',
  `min_order` decimal(10,2) DEFAULT '0.00' COMMENT 'Minimum order amount in local currency',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `is_featured` tinyint(1) DEFAULT '0' COMMENT 'Show in Locals Love These section',
  `closure_end` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `y05_knx_hubs`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_hub_categories`
--

CREATE TABLE `y05_knx_hub_categories` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'active',
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `y05_knx_hub_categories`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_hub_items`
--

CREATE TABLE `y05_knx_hub_items` (
  `id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `category_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `image_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'active',
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `y05_knx_hub_items`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_items_categories`
--

CREATE TABLE `y05_knx_items_categories` (
  `id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `sort_order` int UNSIGNED DEFAULT '0',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `y05_knx_items_categories`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_item_addon_groups`
--

CREATE TABLE `y05_knx_item_addon_groups` (
  `id` bigint UNSIGNED NOT NULL,
  `item_id` bigint UNSIGNED NOT NULL,
  `group_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_item_global_modifiers`
--

CREATE TABLE `y05_knx_item_global_modifiers` (
  `id` bigint UNSIGNED NOT NULL,
  `item_id` bigint UNSIGNED NOT NULL,
  `global_modifier_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_item_modifiers`
--

CREATE TABLE `y05_knx_item_modifiers` (
  `id` bigint UNSIGNED NOT NULL,
  `item_id` bigint UNSIGNED DEFAULT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'single',
  `required` tinyint(1) DEFAULT '0',
  `min_selection` int UNSIGNED DEFAULT '0',
  `max_selection` int UNSIGNED DEFAULT NULL,
  `is_global` tinyint(1) DEFAULT '0',
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `y05_knx_item_modifiers`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_modifier_options`
--

CREATE TABLE `y05_knx_modifier_options` (
  `id` bigint UNSIGNED NOT NULL,
  `modifier_id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `price_adjustment` decimal(10,2) DEFAULT '0.00',
  `is_default` tinyint(1) DEFAULT '0',
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `y05_knx_modifier_options`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_ops_settings`
--

CREATE TABLE `y05_knx_ops_settings` (
  `id` bigint UNSIGNED NOT NULL,
  `setting_key` varchar(80) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `setting_json` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_orders`
--

CREATE TABLE `y05_knx_orders` (
  `id` bigint UNSIGNED NOT NULL,
  `order_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `city_id` bigint UNSIGNED DEFAULT NULL,
  `session_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_id` bigint UNSIGNED DEFAULT NULL,
  `fulfillment_type` enum('delivery','pickup') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'delivery',
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
  `tip_source` enum('none','preset','custom') COLLATE utf8mb4_unicode_ci DEFAULT 'none',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `coupon_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `coupon_id` bigint UNSIGNED DEFAULT NULL,
  `gift_card_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `gift_card_code` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gift_card_id` bigint UNSIGNED DEFAULT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('placed','confirmed','preparing','ready','out_for_delivery','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'placed',
  `driver_id` bigint UNSIGNED DEFAULT NULL,
  `payment_method` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `payment_status` enum('pending','paid','failed','refunded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payment_transaction_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cart_snapshot` json DEFAULT NULL,
  `totals_snapshot` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_orders`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_order_items`
--

CREATE TABLE `y05_knx_order_items` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL,
  `item_id` bigint UNSIGNED DEFAULT NULL,
  `name_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_snapshot` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `quantity` int UNSIGNED NOT NULL DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `modifiers_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_order_items`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_order_status_history`
--

CREATE TABLE `y05_knx_order_status_history` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_order_status_history`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_payments`
--

CREATE TABLE `y05_knx_payments` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL COMMENT 'Reference to knx_orders.id',
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Payment provider: stripe, paypal, etc.',
  `provider_intent_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Provider payment intent ID',
  `amount` int NOT NULL COMMENT 'Amount in cents (e.g. 1234 = $12.34)',
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usd' COMMENT 'ISO 4217 currency code',
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'intent_created, authorized, paid, failed, cancelled',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment state authority for orders';

--
-- Volcado de datos para la tabla `y05_knx_payments`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_push_subscriptions`
--

CREATE TABLE `y05_knx_push_subscriptions` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `p256dh` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auth` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_push_subscriptions`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_sessions`
--

CREATE TABLE `y05_knx_sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `token` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `y05_knx_sessions`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_settings`
--

CREATE TABLE `y05_knx_settings` (
  `id` bigint UNSIGNED NOT NULL,
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `setting_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_software_fees`
--

CREATE TABLE `y05_knx_software_fees` (
  `id` bigint UNSIGNED NOT NULL,
  `scope` enum('city','hub') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'city',
  `city_id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `fee_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inactive',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_software_fees`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_tax_rules`
--

CREATE TABLE `y05_knx_tax_rules` (
  `id` bigint UNSIGNED NOT NULL,
  `scope` enum('city','hub') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'city',
  `scope_id` bigint UNSIGNED DEFAULT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `jurisdiction` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `priority` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------


--
-- Estructura de tabla para la tabla `y05_knx_tip_settings`
--

CREATE TABLE `y05_knx_tip_settings` (
  `id` bigint UNSIGNED NOT NULL,
  `scope` enum('city','hub') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'city',
  `city_id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `presets_json` json DEFAULT NULL COMMENT 'Example: [0,10,15,20] or fixed amounts in cents/decimal (your engine decides)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_users`
--

CREATE TABLE `y05_knx_users` (
  `id` bigint UNSIGNED NOT NULL,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `role` enum('super_admin','manager','menu_uploader','hub_management','driver','customer','user') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'user',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `y05_knx_users`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `y05_knx_webhook_events`
--

CREATE TABLE `y05_knx_webhook_events` (
  `id` bigint UNSIGNED NOT NULL,
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stripe',
  `event_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Stripe event ID (evt_xxx)',
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'payment_intent.succeeded, etc',
  `intent_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'provider_intent_id (pi_xxx)',
  `order_id` bigint UNSIGNED DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `y05_knx_webhook_events`
--

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `y05_knx_addons`
--
ALTER TABLE `y05_knx_addons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `group_id_2` (`group_id`,`sort_order`),
  ADD KEY `status` (`status`);

--
-- Indices de la tabla `y05_knx_addon_groups`
--
ALTER TABLE `y05_knx_addon_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `hub_id_2` (`hub_id`,`sort_order`),
  ADD KEY `name` (`name`);

--
-- Indices de la tabla `y05_knx_addresses`
--
ALTER TABLE `y05_knx_addresses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_default_address_per_customer` (`default_customer_id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_deleted_at` (`deleted_at`),
  ADD KEY `idx_latlng` (`latitude`,`longitude`),
  ADD KEY `idx_customer_default_lookup` (`customer_id`,`is_default`,`status`,`deleted_at`);

--
-- Indices de la tabla `y05_knx_carts`
--
ALTER TABLE `y05_knx_carts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_active_session_hub` (`active_session_token`,`hub_id`),
  ADD UNIQUE KEY `uq_active_customer_hub` (`active_customer_id`,`hub_id`),
  ADD KEY `session_idx` (`session_token`),
  ADD KEY `customer_idx` (`customer_id`),
  ADD KEY `hub_idx` (`hub_id`),
  ADD KEY `status_idx` (`status`),
  ADD KEY `idx_session_status` (`session_token`,`status`);

--
-- Indices de la tabla `y05_knx_cart_items`
--
ALTER TABLE `y05_knx_cart_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cart_idx` (`cart_id`),
  ADD KEY `item_idx` (`item_id`);

--
-- Indices de la tabla `y05_knx_cities`
--
ALTER TABLE `y05_knx_cities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `name` (`name`),
  ADD KEY `idx_is_operational` (`is_operational`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- Indices de la tabla `y05_knx_coupons`
--
ALTER TABLE `y05_knx_coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `status` (`status`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indices de la tabla `y05_knx_coupon_redemptions`
--
ALTER TABLE `y05_knx_coupon_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_coupon_id` (`coupon_id`),
  ADD KEY `idx_coupon_code` (`coupon_code`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_customer_id` (`customer_id`);

--
-- Indices de la tabla `y05_knx_delivery_fee_rules`
--
ALTER TABLE `y05_knx_delivery_fee_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hub_active` (`hub_id`,`is_active`),
  ADD KEY `idx_city_active` (`city_id`,`is_active`),
  ADD KEY `idx_zone_active` (`zone_id`,`is_active`),
  ADD KEY `idx_priority` (`priority` DESC);

--
-- Indices de la tabla `y05_knx_delivery_rates`
--
ALTER TABLE `y05_knx_delivery_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_city` (`city_id`),
  ADD KEY `status` (`status`),
  ADD KEY `city_id` (`city_id`);

--
-- Indices de la tabla `y05_knx_delivery_zones`
--
ALTER TABLE `y05_knx_delivery_zones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `hub_active` (`hub_id`,`is_active`);

--
-- Indices de la tabla `y05_knx_drivers`
--
ALTER TABLE `y05_knx_drivers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_knx_drivers_user_id` (`user_id`);

--
-- Indices de la tabla `y05_knx_driver_availability`
--
ALTER TABLE `y05_knx_driver_availability`
  ADD PRIMARY KEY (`driver_user_id`),
  ADD KEY `idx_knx_driver_availability_status` (`status`);

--
-- Indices de la tabla `y05_knx_driver_hubs`
--
ALTER TABLE `y05_knx_driver_hubs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_driver_hub` (`driver_id`,`hub_id`),
  ADD KEY `idx_driver_id` (`driver_id`),
  ADD KEY `idx_hub_id` (`hub_id`);

--
-- Indices de la tabla `y05_knx_driver_ops`
--
ALTER TABLE `y05_knx_driver_ops`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_order` (`order_id`),
  ADD KEY `idx_driver` (`driver_user_id`),
  ADD KEY `idx_ops_status` (`ops_status`),
  ADD KEY `idx_updated` (`updated_at`);

--
-- Indices de la tabla `y05_knx_gift_cards`
--
ALTER TABLE `y05_knx_gift_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_code` (`code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indices de la tabla `y05_knx_gift_card_transactions`
--
ALTER TABLE `y05_knx_gift_card_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gift_card_id` (`gift_card_id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_type` (`type`);

--
-- Indices de la tabla `y05_knx_hubs`
--
ALTER TABLE `y05_knx_hubs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `city_id` (`city_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `status` (`status`),
  ADD KEY `name` (`name`),
  ADD KEY `rating` (`rating`),
  ADD KEY `delivery_zone_type` (`delivery_zone_type`),
  ADD KEY `idx_hub_slug` (`slug`),
  ADD KEY `is_featured` (`is_featured`),
  ADD KEY `slug` (`slug`);

--
-- Indices de la tabla `y05_knx_hub_categories`
--
ALTER TABLE `y05_knx_hub_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `sort_order` (`sort_order`),
  ADD KEY `name` (`name`);

--
-- Indices de la tabla `y05_knx_hub_items`
--
ALTER TABLE `y05_knx_hub_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `hub_id_2` (`hub_id`,`category_id`,`sort_order`),
  ADD KEY `status` (`status`);

--
-- Indices de la tabla `y05_knx_items_categories`
--
ALTER TABLE `y05_knx_items_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `hub_id_2` (`hub_id`,`sort_order`),
  ADD KEY `status` (`status`);

--
-- Indices de la tabla `y05_knx_item_addon_groups`
--
ALTER TABLE `y05_knx_item_addon_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_item_group` (`item_id`,`group_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indices de la tabla `y05_knx_item_global_modifiers`
--
ALTER TABLE `y05_knx_item_global_modifiers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_item_global` (`item_id`,`global_modifier_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `global_modifier_id` (`global_modifier_id`);

--
-- Indices de la tabla `y05_knx_item_modifiers`
--
ALTER TABLE `y05_knx_item_modifiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `is_global` (`is_global`),
  ADD KEY `item_id_2` (`item_id`,`sort_order`),
  ADD KEY `hub_id_2` (`hub_id`,`is_global`,`sort_order`);

--
-- Indices de la tabla `y05_knx_modifier_options`
--
ALTER TABLE `y05_knx_modifier_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `modifier_id` (`modifier_id`),
  ADD KEY `modifier_id_2` (`modifier_id`,`sort_order`);

--
-- Indices de la tabla `y05_knx_ops_settings`
--
ALTER TABLE `y05_knx_ops_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_setting_key` (`setting_key`);

--
-- Indices de la tabla `y05_knx_orders`
--
ALTER TABLE `y05_knx_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_order_number` (`order_number`),
  ADD KEY `idx_hub_id` (`hub_id`),
  ADD KEY `idx_city_id` (`city_id`),
  ADD KEY `idx_session_token` (`session_token`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_delivery_address_id` (`delivery_address_id`),
  ADD KEY `idx_idempotency_probe` (`session_token`,`hub_id`,`customer_id`,`status`,`created_at`),
  ADD KEY `idx_coupon_code` (`coupon_code`),
  ADD KEY `idx_gift_card_code` (`gift_card_code`);

--
-- Indices de la tabla `y05_knx_order_items`
--
ALTER TABLE `y05_knx_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_item_id` (`item_id`);

--
-- Indices de la tabla `y05_knx_order_status_history`
--
ALTER TABLE `y05_knx_order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indices de la tabla `y05_knx_payments`
--
ALTER TABLE `y05_knx_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_provider_intent` (`provider`,`provider_intent_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indices de la tabla `y05_knx_push_subscriptions`
--
ALTER TABLE `y05_knx_push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_role` (`user_id`,`role`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_revoked_at` (`revoked_at`);

--
-- Indices de la tabla `y05_knx_sessions`
--
ALTER TABLE `y05_knx_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `token_2` (`token`),
  ADD KEY `expires_at` (`expires_at`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `y05_knx_settings`
--
ALTER TABLE `y05_knx_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `setting_key_2` (`setting_key`);

--
-- Indices de la tabla `y05_knx_software_fees`
--
ALTER TABLE `y05_knx_software_fees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_scope_city_hub` (`scope`,`city_id`,`hub_id`),
  ADD KEY `idx_city` (`city_id`),
  ADD KEY `idx_hub` (`hub_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indices de la tabla `y05_knx_tax_rules`
--
ALTER TABLE `y05_knx_tax_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scope_status` (`scope`,`status`),
  ADD KEY `idx_scope_id` (`scope_id`),
  ADD KEY `idx_priority` (`priority`);

--
-- Indices de la tabla `y05_knx_tip_settings`
--
ALTER TABLE `y05_knx_tip_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_scope_city_hub` (`scope`,`city_id`,`hub_id`),
  ADD KEY `idx_city` (`city_id`),
  ADD KEY `idx_hub` (`hub_id`);

--
-- Indices de la tabla `y05_knx_users`
--
ALTER TABLE `y05_knx_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `email_2` (`email`),
  ADD KEY `role` (`role`),
  ADD KEY `status` (`status`),
  ADD KEY `idx_name` (`name`),
  ADD KEY `idx_phone` (`phone`);

--
-- Indices de la tabla `y05_knx_webhook_events`
--
ALTER TABLE `y05_knx_webhook_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `event_id` (`event_id`),
  ADD KEY `intent_id` (`intent_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `processed_at` (`processed_at`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `y05_knx_addons`
--
ALTER TABLE `y05_knx_addons`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_addon_groups`
--
ALTER TABLE `y05_knx_addon_groups`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_addresses`
--
ALTER TABLE `y05_knx_addresses`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `y05_knx_carts`
--
ALTER TABLE `y05_knx_carts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=167;

--
-- AUTO_INCREMENT de la tabla `y05_knx_cart_items`
--
ALTER TABLE `y05_knx_cart_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=971;

--
-- AUTO_INCREMENT de la tabla `y05_knx_cities`
--
ALTER TABLE `y05_knx_cities`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `y05_knx_coupons`
--
ALTER TABLE `y05_knx_coupons`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `y05_knx_coupon_redemptions`
--
ALTER TABLE `y05_knx_coupon_redemptions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_delivery_fee_rules`
--
ALTER TABLE `y05_knx_delivery_fee_rules`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `y05_knx_delivery_rates`
--
ALTER TABLE `y05_knx_delivery_rates`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `y05_knx_delivery_zones`
--
ALTER TABLE `y05_knx_delivery_zones`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `y05_knx_drivers`
--
ALTER TABLE `y05_knx_drivers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `y05_knx_driver_hubs`
--
ALTER TABLE `y05_knx_driver_hubs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `y05_knx_driver_ops`
--
ALTER TABLE `y05_knx_driver_ops`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT de la tabla `y05_knx_gift_cards`
--
ALTER TABLE `y05_knx_gift_cards`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_gift_card_transactions`
--
ALTER TABLE `y05_knx_gift_card_transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_hubs`
--
ALTER TABLE `y05_knx_hubs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `y05_knx_hub_categories`
--
ALTER TABLE `y05_knx_hub_categories`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `y05_knx_hub_items`
--
ALTER TABLE `y05_knx_hub_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `y05_knx_items_categories`
--
ALTER TABLE `y05_knx_items_categories`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `y05_knx_item_addon_groups`
--
ALTER TABLE `y05_knx_item_addon_groups`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_item_global_modifiers`
--
ALTER TABLE `y05_knx_item_global_modifiers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_item_modifiers`
--
ALTER TABLE `y05_knx_item_modifiers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `y05_knx_modifier_options`
--
ALTER TABLE `y05_knx_modifier_options`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `y05_knx_ops_settings`
--
ALTER TABLE `y05_knx_ops_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_orders`
--
ALTER TABLE `y05_knx_orders`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `y05_knx_order_items`
--
ALTER TABLE `y05_knx_order_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `y05_knx_order_status_history`
--
ALTER TABLE `y05_knx_order_status_history`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `y05_knx_payments`
--
ALTER TABLE `y05_knx_payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT de la tabla `y05_knx_push_subscriptions`
--
ALTER TABLE `y05_knx_push_subscriptions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `y05_knx_sessions`
--
ALTER TABLE `y05_knx_sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT de la tabla `y05_knx_settings`
--
ALTER TABLE `y05_knx_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_software_fees`
--
ALTER TABLE `y05_knx_software_fees`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `y05_knx_tax_rules`
--
ALTER TABLE `y05_knx_tax_rules`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_tip_settings`
--
ALTER TABLE `y05_knx_tip_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `y05_knx_users`
--
ALTER TABLE `y05_knx_users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `y05_knx_webhook_events`
--
ALTER TABLE `y05_knx_webhook_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `y05_knx_addons`
--
ALTER TABLE `y05_knx_addons`
  ADD CONSTRAINT `y05_knx_addons_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `y05_knx_addon_groups` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_addon_groups`
--
ALTER TABLE `y05_knx_addon_groups`
  ADD CONSTRAINT `y05_knx_addon_groups_ibfk_1` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_addresses`
--
ALTER TABLE `y05_knx_addresses`
  ADD CONSTRAINT `fk_addresses_customer` FOREIGN KEY (`customer_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_carts`
--
ALTER TABLE `y05_knx_carts`
  ADD CONSTRAINT `fk_carts_customer` FOREIGN KEY (`customer_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_carts_hub` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_cart_items`
--
ALTER TABLE `y05_knx_cart_items`
  ADD CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`) REFERENCES `y05_knx_carts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cart_items_item` FOREIGN KEY (`item_id`) REFERENCES `y05_knx_hub_items` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `y05_knx_delivery_rates`
--
ALTER TABLE `y05_knx_delivery_rates`
  ADD CONSTRAINT `y05_knx_delivery_rates_ibfk_1` FOREIGN KEY (`city_id`) REFERENCES `y05_knx_cities` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_delivery_zones`
--
ALTER TABLE `y05_knx_delivery_zones`
  ADD CONSTRAINT `y05_knx_delivery_zones_ibfk_1` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_hubs`
--
ALTER TABLE `y05_knx_hubs`
  ADD CONSTRAINT `y05_knx_hubs_ibfk_1` FOREIGN KEY (`city_id`) REFERENCES `y05_knx_cities` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `y05_knx_hubs_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `y05_knx_hub_categories` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `y05_knx_hub_items`
--
ALTER TABLE `y05_knx_hub_items`
  ADD CONSTRAINT `y05_knx_hub_items_ibfk_1` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `y05_knx_hub_items_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `y05_knx_items_categories` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `y05_knx_items_categories`
--
ALTER TABLE `y05_knx_items_categories`
  ADD CONSTRAINT `y05_knx_items_categories_ibfk_1` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_item_addon_groups`
--
ALTER TABLE `y05_knx_item_addon_groups`
  ADD CONSTRAINT `y05_knx_item_addon_groups_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `y05_knx_hub_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `y05_knx_item_addon_groups_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `y05_knx_addon_groups` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_item_global_modifiers`
--
ALTER TABLE `y05_knx_item_global_modifiers`
  ADD CONSTRAINT `y05_knx_item_global_modifiers_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `y05_knx_hub_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `y05_knx_item_global_modifiers_ibfk_2` FOREIGN KEY (`global_modifier_id`) REFERENCES `y05_knx_item_modifiers` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_item_modifiers`
--
ALTER TABLE `y05_knx_item_modifiers`
  ADD CONSTRAINT `y05_knx_item_modifiers_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `y05_knx_hub_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `y05_knx_item_modifiers_ibfk_2` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_modifier_options`
--
ALTER TABLE `y05_knx_modifier_options`
  ADD CONSTRAINT `y05_knx_modifier_options_ibfk_1` FOREIGN KEY (`modifier_id`) REFERENCES `y05_knx_item_modifiers` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_orders`
--
ALTER TABLE `y05_knx_orders`
  ADD CONSTRAINT `fk_orders_delivery_address` FOREIGN KEY (`delivery_address_id`) REFERENCES `y05_knx_addresses` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `y05_knx_order_items`
--
ALTER TABLE `y05_knx_order_items`
  ADD CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `y05_knx_orders` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_order_status_history`
--
ALTER TABLE `y05_knx_order_status_history`
  ADD CONSTRAINT `fk_order_status_history_order` FOREIGN KEY (`order_id`) REFERENCES `y05_knx_orders` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `y05_knx_sessions`
--
ALTER TABLE `y05_knx_sessions`
  ADD CONSTRAINT `y05_knx_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `y05_knx_users` (`id`) ON DELETE CASCADE;
