-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 21-01-2026 a las 08:35:24
-- Versión del servidor: 8.0.44-35
-- Versión de PHP: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `oywwofte_WPAYY`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_addons`
--

CREATE TABLE `Z7E_knx_addons` (
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
-- Estructura de tabla para la tabla `Z7E_knx_addon_groups`
--

CREATE TABLE `Z7E_knx_addon_groups` (
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
-- Estructura de tabla para la tabla `Z7E_knx_addresses`
--

CREATE TABLE `Z7E_knx_addresses` (
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
-- Volcado de datos para la tabla `Z7E_knx_addresses`
--

INSERT INTO `Z7E_knx_addresses` (`id`, `customer_id`, `label`, `recipient_name`, `phone`, `line1`, `line2`, `city`, `state`, `postal_code`, `country`, `delivery_instructions`, `latitude`, `longitude`, `is_default`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(3, 1, 'Home', NULL, NULL, '16 Stratford Dr E', NULL, 'Bourbonnais', 'IL', '60914', 'USA', NULL, 41.1586000, -87.8752000, 0, 'active', '2026-01-07 20:29:30', '2026-01-10 13:04:12', NULL),
(6, 1, 'My Address Manteno', NULL, NULL, '10000 N', 'Red Wall House', 'Manteno', 'IL', '60950', 'USA', NULL, 41.2650530, -87.8587190, 1, 'active', '2026-01-10 06:05:58', '2026-01-10 13:04:12', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_carts`
--

CREATE TABLE `Z7E_knx_carts` (
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
-- Volcado de datos para la tabla `Z7E_knx_carts`
--

INSERT INTO `Z7E_knx_carts` (`id`, `session_token`, `customer_id`, `hub_id`, `subtotal`, `status`, `created_at`, `updated_at`) VALUES
(53, 'knx_df726c95cdf3e19ba6305e04', NULL, 1, 18.09, 'converted', '2026-01-10 04:35:46', '2026-01-10 17:01:56'),
(64, 'knx_626efa19964fd19baddd79a6', NULL, 1, 24.88, 'converted', '2026-01-11 16:22:13', '2026-01-11 16:47:00'),
(67, 'knx_3563006571c78819bae0284e9', NULL, 1, 24.88, 'converted', '2026-01-11 17:02:41', '2026-01-11 17:03:00'),
(69, 'knx_80223bca4a286819bae040182', 1, 1, 18.09, 'converted', '2026-01-11 17:04:18', '2026-01-11 17:04:52'),
(73, 'knx_374ad84276081819bae088fbc', NULL, 1, 24.88, 'converted', '2026-01-11 17:09:17', '2026-01-11 17:09:39'),
(76, 'knx_986480663f7c519bae0a7b38', NULL, 1, 24.88, 'converted', '2026-01-11 17:11:23', '2026-01-11 17:11:40'),
(78, 'knx_4c8ae6ef955af19bae12ffb5', 1, 1, 24.88, 'converted', '2026-01-11 17:20:39', '2026-01-11 17:21:07'),
(79, 'knx_a197718b182ef19bae6ed900', 1, 1, 74.64, 'converted', '2026-01-11 19:00:58', '2026-01-11 19:01:27'),
(81, 'knx_974cb1ba8126f819bae840330', 1, 1, 24.88, 'converted', '2026-01-11 19:24:06', '2026-01-11 19:24:28'),
(84, 'knx_bf441e01bc8f8819bae854337', NULL, 1, 199.04, 'converted', '2026-01-11 19:25:27', '2026-01-11 19:25:45'),
(104, 'knx_b342452ef2ed9819baf65deb2', NULL, 1, 24.88, 'converted', '2026-01-11 23:30:47', '2026-01-11 23:31:04'),
(107, 'knx_350916b8346e5819baf69b955', NULL, 1, 97.24, 'converted', '2026-01-11 23:35:00', '2026-01-11 23:35:33'),
(110, 'knx_154011575ce3c819baf8e432e', NULL, 1, 223.92, 'converted', '2026-01-12 00:14:55', '2026-01-12 00:15:28'),
(113, 'knx_baf88cded35df819bb015fed4', NULL, 1, 24.88, 'converted', '2026-01-12 02:43:10', '2026-01-12 02:43:42'),
(116, 'knx_3166167280a9219bb01e019c', NULL, 1, 24.88, 'converted', '2026-01-12 02:51:55', '2026-01-12 02:52:17'),
(119, 'knx_0ad1211559ce8819bb03f7871', NULL, 1, 273.68, 'converted', '2026-01-12 03:28:28', '2026-01-12 03:29:05'),
(122, 'knx_9279c5e74bdac819bb0414a7e', NULL, 1, 18.09, 'converted', '2026-01-12 03:30:27', '2026-01-12 03:32:25'),
(127, 'knx_93faac761be9519bb07cd86d', NULL, 1, 24.88, 'converted', '2026-01-12 04:35:31', '2026-01-12 04:35:58'),
(136, 'knx_ef4df9fe51ada819bb5776a3f', NULL, 1, 24.88, 'converted', '2026-01-13 03:47:40', '2026-01-13 03:48:07'),
(139, 'knx_22007f18dd760819bb57cd50d', NULL, 1, 24.88, 'converted', '2026-01-13 03:53:35', '2026-01-13 03:53:59'),
(142, 'knx_4626f6e3f30ff819bb8648a84', NULL, 1, 90.45, 'converted', '2026-01-13 17:25:57', '2026-01-13 17:27:14'),
(144, 'knx_dce6d38d63b1719bb86e67a3', NULL, 1, 24.88, 'converted', '2026-01-13 17:36:44', '2026-01-13 17:37:23'),
(147, 'knx_bfec5cade22d619bb8d57d90', NULL, 1, 24.88, 'converted', '2026-01-13 19:29:17', '2026-01-13 19:29:41'),
(149, 'knx_a7765d097e88d298f0c201cb6e46bc98', NULL, 1, 0.00, 'abandoned', '2026-01-14 16:46:39', '2026-01-14 16:46:39'),
(150, 'knx_6c8f8bc44c175819bbd66ffbc', NULL, 1, 24.88, 'converted', '2026-01-14 16:46:42', '2026-01-14 16:47:01'),
(151, 'knx_6c8f8bc44c175819bbd66ffbc', NULL, 1, 0.00, 'abandoned', '2026-01-14 16:47:07', '2026-01-14 16:47:14'),
(152, 'knx_d938a90d8b5aa819bbf04328b', 1, 1, 24.88, 'converted', '2026-01-15 00:18:02', '2026-01-15 00:18:49'),
(153, 'knx_d938a90d8b5aa819bbf04328b', NULL, 1, 24.88, 'abandoned', '2026-01-15 00:18:56', '2026-01-15 12:46:29'),
(154, 'knx_8d731b26cb07994f06e8b3b6154417bb', NULL, 1, 0.00, 'abandoned', '2026-01-19 20:15:44', '2026-01-19 20:15:44'),
(155, 'knx_6170c2c0edbbd819bd7e62f1b', NULL, 1, 24.88, 'converted', '2026-01-19 20:15:47', '2026-01-19 20:16:10'),
(156, 'knx_6170c2c0edbbd819bd7e62f1b', 7, 1, 0.00, 'abandoned', '2026-01-19 20:16:17', '2026-01-19 22:48:57'),
(157, 'knx_d938a90d8b5aa819bbf04328b', NULL, 1, 0.00, 'abandoned', '2026-01-19 22:46:24', '2026-01-20 00:56:31'),
(158, 'knx_7eb4f9c919bbe263954fcb774748ce09', NULL, 1, 0.00, 'abandoned', '2026-01-19 23:08:34', '2026-01-19 23:08:34'),
(159, 'knx_54c44bb16033319bd8847cd7', NULL, 1, 223.92, 'converted', '2026-01-19 23:08:40', '2026-01-19 23:08:59'),
(160, 'knx_54c44bb16033319bd8847cd7', 7, 1, 223.92, 'abandoned', '2026-01-19 23:09:06', '2026-01-21 05:30:30'),
(161, 'knx_ac4f9f9bd3eb579fb42d9926b6c6c549', NULL, 1, 0.00, 'abandoned', '2026-01-20 23:04:46', '2026-01-20 23:04:46'),
(162, 'knx_f57674a63c48219bdda7634e', 1, 1, 373.20, 'converted', '2026-01-20 23:04:53', '2026-01-20 23:05:52'),
(163, 'knx_f57674a63c48219bdda7634e', NULL, 1, 0.00, 'abandoned', '2026-01-20 23:05:59', '2026-01-20 23:07:04'),
(164, 'knx_14614487aedd257bb965027cffea4b62', NULL, 1, 0.00, 'abandoned', '2026-01-20 23:07:06', '2026-01-20 23:07:06'),
(165, 'knx_e5fd1a08aab38819bdda97df6', NULL, 1, 174.16, 'converted', '2026-01-20 23:07:11', '2026-01-20 23:07:32'),
(166, 'knx_e5fd1a08aab38819bdda97df6', NULL, 1, 174.16, 'active', '2026-01-20 23:07:38', '2026-01-20 23:44:54');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_cart_items`
--

CREATE TABLE `Z7E_knx_cart_items` (
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
-- Volcado de datos para la tabla `Z7E_knx_cart_items`
--

INSERT INTO `Z7E_knx_cart_items` (`id`, `cart_id`, `item_id`, `name_snapshot`, `image_snapshot`, `quantity`, `unit_price`, `line_total`, `modifiers_json`, `created_at`) VALUES
(542, 53, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 18.09, 18.09, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 1, \"name\": \"Small\", \"price_adjustment\": 0}], \"required\": false}]', '2026-01-10 17:01:41'),
(633, 64, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-11 16:46:49'),
(639, 67, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-11 17:02:46'),
(642, 69, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 18.09, 18.09, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 1, \"name\": \"Small\", \"price_adjustment\": 0}], \"required\": false}]', '2026-01-11 17:04:42'),
(648, 73, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-11 17:09:26'),
(652, 76, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-11 17:11:28'),
(659, 78, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-11 17:20:48'),
(662, 79, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 3, 24.88, 74.64, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-11 19:01:15'),
(668, 81, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-11 19:24:18'),
(678, 84, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 8, 24.88, 199.04, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-11 19:25:32'),
(745, 104, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-11 23:30:52'),
(756, 107, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-11 23:35:24'),
(757, 107, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 4, 18.09, 72.36, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 1, \"name\": \"Small\", \"price_adjustment\": 0}], \"required\": false}]', '2026-01-11 23:35:24'),
(782, 110, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 9, 24.88, 223.92, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-12 00:15:00'),
(787, 113, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-12 02:43:15'),
(790, 116, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-12 02:52:01'),
(796, 119, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 11, 24.88, 273.68, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-12 03:28:38'),
(807, 122, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 18.09, 18.09, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 1, \"name\": \"Small\", \"price_adjustment\": 0}], \"required\": false}]', '2026-01-12 03:30:40'),
(822, 127, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-12 04:35:38'),
(877, 136, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-13 03:47:46'),
(881, 139, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-13 03:53:41'),
(885, 142, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 5, 18.09, 90.45, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 1, \"name\": \"Small\", \"price_adjustment\": 0}], \"required\": false}]', '2026-01-13 17:26:03'),
(888, 144, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-13 17:36:57'),
(903, 147, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-13 19:29:28'),
(917, 150, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-14 16:46:48'),
(921, 152, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-15 00:18:27'),
(922, 153, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-15 00:18:56'),
(925, 155, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-19 20:15:53'),
(947, 159, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 9, 24.88, 223.92, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-19 23:08:46'),
(952, 160, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 9, 24.88, 223.92, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-20 16:37:32'),
(957, 162, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 15, 24.88, 373.20, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-20 23:05:28'),
(961, 165, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 7, 24.88, 174.16, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-20 23:07:15'),
(970, 166, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 7, 24.88, 174.16, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-20 23:44:54');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_cities`
--

CREATE TABLE `Z7E_knx_cities` (
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
-- Volcado de datos para la tabla `Z7E_knx_cities`
--

INSERT INTO `Z7E_knx_cities` (`id`, `name`, `state`, `country`, `status`, `is_operational`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Kankakee County', NULL, 'USA', 'active', 1, '2026-01-07 01:57:57', NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_coupons`
--

CREATE TABLE `Z7E_knx_coupons` (
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
-- Volcado de datos para la tabla `Z7E_knx_coupons`
--

INSERT INTO `Z7E_knx_coupons` (`id`, `code`, `type`, `value`, `min_subtotal`, `status`, `starts_at`, `expires_at`, `usage_limit`, `used_count`, `created_at`, `updated_at`) VALUES
(1, 'SAVE5', 'fixed', 5.00, NULL, 'active', '2026-01-08 04:51:00', '2026-01-13 04:51:00', NULL, 0, '2026-01-08 09:51:44', '2026-01-08 09:51:44');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_coupon_redemptions`
--

CREATE TABLE `Z7E_knx_coupon_redemptions` (
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
-- Estructura de tabla para la tabla `Z7E_knx_delivery_fee_rules`
--

CREATE TABLE `Z7E_knx_delivery_fee_rules` (
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
-- Volcado de datos para la tabla `Z7E_knx_delivery_fee_rules`
--

INSERT INTO `Z7E_knx_delivery_fee_rules` (`id`, `hub_id`, `city_id`, `zone_id`, `rule_name`, `is_active`, `priority`, `fee_type`, `flat_fee`, `base_fee`, `per_km_rate`, `per_mile_rate`, `free_delivery_distance`, `min_subtotal_free_delivery`, `subtotal_percentage`, `min_fee`, `max_fee`, `max_distance_km`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, NULL, 'Standard Delivery Fee', 1, 10, 'distance_based', NULL, 2.00, 0.50, NULL, NULL, 50.00, NULL, 2.00, 15.00, 20.00, '2026-01-08 03:42:08', '2026-01-08 03:42:08');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_delivery_rates`
--

CREATE TABLE `Z7E_knx_delivery_rates` (
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
-- Volcado de datos para la tabla `Z7E_knx_delivery_rates`
--

INSERT INTO `Z7E_knx_delivery_rates` (`id`, `city_id`, `flat_rate`, `rate_per_distance`, `distance_unit`, `status`, `created_at`, `updated_at`, `eta_base_minutes`, `eta_per_distance_minutes`, `eta_buffer_minutes`, `zone_name`, `base_rate`, `per_mile_rate`, `min_order`) VALUES
(1, 1, 4.25, 0.80, 'mile', 'active', '2026-01-06 19:59:53', '2026-01-06 19:59:53', NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_delivery_zones`
--

CREATE TABLE `Z7E_knx_delivery_zones` (
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
-- Volcado de datos para la tabla `Z7E_knx_delivery_zones`
--

INSERT INTO `Z7E_knx_delivery_zones` (`id`, `hub_id`, `zone_name`, `polygon_points`, `fill_color`, `fill_opacity`, `stroke_color`, `stroke_weight`, `is_active`, `created_at`, `updated_at`) VALUES
(7, 1, 'Main Delivery Area', '[{\"lat\": 41.46942206877992, \"lng\": -87.7752685546875}, {\"lat\": 41.14343677535151, \"lng\": -88.37951660156251}, {\"lat\": 40.79295382981517, \"lng\": -87.45117187500001}]', '#0b793a', 0.35, '#0b793a', 2, 1, '2026-01-11 06:05:09', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_drivers`
--

CREATE TABLE `Z7E_knx_drivers` (
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
-- Volcado de datos para la tabla `Z7E_knx_drivers`
--

INSERT INTO `Z7E_knx_drivers` (`id`, `driver_user_id`, `user_id`, `status`, `full_name`, `email`, `vehicle_info`, `phone`, `created_at`, `updated_at`) VALUES
(5, 5, 5, 'active', 'Collen', 'colleen_olc100@outlook.com', 'Honda Civic', '+708 567 3556', '2026-01-16 00:27:25', '2026-01-17 03:53:52'),
(6, 6, 6, 'active', 'Jeremy', 'jeremy_olc100@gmail.com', 'Honda Civic', '+1 815-386-3652', '2026-01-16 18:18:07', '2026-01-17 03:53:52'),
(7, 7, 7, 'active', 'Keisha', 'keisha_olc100@hotmail.com', 'Honda Civic', '+1 708 004 2923', '2026-01-17 09:10:32', '2026-01-17 03:53:52');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_driver_availability`
--

CREATE TABLE `Z7E_knx_driver_availability` (
  `driver_user_id` int NOT NULL,
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `Z7E_knx_driver_availability`
--

INSERT INTO `Z7E_knx_driver_availability` (`driver_user_id`, `status`, `updated_at`) VALUES
(5, 'on', '2026-01-19 20:10:56'),
(6, 'off', '2026-01-16 13:21:33'),
(7, 'on', '2026-01-20 23:53:11');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_driver_hubs`
--

CREATE TABLE `Z7E_knx_driver_hubs` (
  `id` bigint UNSIGNED NOT NULL,
  `driver_id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `Z7E_knx_driver_hubs`
--

INSERT INTO `Z7E_knx_driver_hubs` (`id`, `driver_id`, `hub_id`, `created_at`) VALUES
(1, 5, 1, '2026-01-16 19:21:33'),
(2, 6, 1, '2026-01-16 19:21:33');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_driver_ops`
--

CREATE TABLE `Z7E_knx_driver_ops` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL,
  `driver_user_id` bigint UNSIGNED DEFAULT NULL,
  `assigned_by` bigint UNSIGNED DEFAULT NULL,
  `ops_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unassigned',
  `assigned_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `Z7E_knx_driver_ops`
--

INSERT INTO `Z7E_knx_driver_ops` (`id`, `order_id`, `driver_user_id`, `assigned_by`, `ops_status`, `assigned_at`, `updated_at`) VALUES
(1, 1, 1, 1, 'assigned', '2026-01-20 23:13:00', '2026-01-20 23:13:00'),
(8, 2, 7, 1, 'delivered', '2026-01-19 22:46:46', '2026-01-20 01:01:29'),
(11, 5, NULL, NULL, 'unassigned', NULL, '2026-01-20 23:10:45'),
(12, 4, NULL, NULL, 'unassigned', NULL, '2026-01-20 23:10:45'),
(13, 3, NULL, NULL, 'unassigned', NULL, '2026-01-20 23:10:45');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_gift_cards`
--

CREATE TABLE `Z7E_knx_gift_cards` (
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
-- Estructura de tabla para la tabla `Z7E_knx_gift_card_transactions`
--

CREATE TABLE `Z7E_knx_gift_card_transactions` (
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
-- Estructura de tabla para la tabla `Z7E_knx_hubs`
--

CREATE TABLE `Z7E_knx_hubs` (
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
-- Volcado de datos para la tabla `Z7E_knx_hubs`
--

INSERT INTO `Z7E_knx_hubs` (`id`, `name`, `slug`, `tagline`, `city_id`, `category_id`, `address`, `latitude`, `longitude`, `delivery_radius`, `delivery_zone_type`, `delivery_available`, `pickup_available`, `phone`, `email`, `logo_url`, `hero_img`, `type`, `rating`, `cuisines`, `status`, `hours_monday`, `hours_tuesday`, `hours_wednesday`, `hours_thursday`, `hours_friday`, `hours_saturday`, `hours_sunday`, `closure_start`, `closure_until`, `closure_reason`, `timezone`, `currency`, `tax_rate`, `min_order`, `created_at`, `updated_at`, `is_featured`, `closure_end`) VALUES
(1, 'Chef Vaughn\'s Kitchen', 'chef-vaughns-kitchen', NULL, 1, 1, '670 West Station Street, Kankakee, IL United States, Illinois, 60901', 41.1179010, -87.8655620, 30.00, 'polygon', 1, 1, '+1 815-386-3652', 'chefvaughnskitchen_nexus@outlook.com', 'https://ourlocalcollective.org/wp-content/uploads/knx-uploads/1/20260110-035516-83af1.jpg', NULL, 'Restaurant', 4.5, NULL, 'active', '[{\"open\":\"00:00\",\"close\":\"23:45\"}]', '[{\"open\":\"00:00\",\"close\":\"23:45\"}]', '[{\"open\":\"00:00\",\"close\":\"23:45\"}]', '[{\"open\":\"00:00\",\"close\":\"23:45\"}]', '[{\"open\":\"00:00\",\"close\":\"23:45\"}]', '[{\"open\":\"00:00\",\"close\":\"23:45\"}]', '', NULL, NULL, NULL, 'America/Chicago', 'USD', 20.00, 0.00, '2026-01-07 01:56:39', '2026-01-12 09:06:34', 1, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_hub_categories`
--

CREATE TABLE `Z7E_knx_hub_categories` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'active',
  `sort_order` int UNSIGNED DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `Z7E_knx_hub_categories`
--

INSERT INTO `Z7E_knx_hub_categories` (`id`, `name`, `status`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'Soul Food', 'active', 1, '2026-01-07 02:00:40', '2026-01-07 04:59:05'),
(2, 'Chickens', 'active', 2, '2026-01-07 04:17:56', '2026-01-07 05:09:56'),
(3, 'Burgers', 'active', 3, '2026-01-07 05:09:45', '2026-01-07 05:09:45');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_hub_items`
--

CREATE TABLE `Z7E_knx_hub_items` (
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
-- Volcado de datos para la tabla `Z7E_knx_hub_items`
--

INSERT INTO `Z7E_knx_hub_items` (`id`, `hub_id`, `category_id`, `name`, `description`, `price`, `image_url`, `status`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Shrimp Alfredo Pasta', 'Fettuccine pasta smothered in a homemade Alfredo sauce.', 18.09, 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 'active', 1767730140, '2026-01-07 02:09:00', '2026-01-07 02:10:15');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_items_categories`
--

CREATE TABLE `Z7E_knx_items_categories` (
  `id` bigint UNSIGNED NOT NULL,
  `hub_id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `sort_order` int UNSIGNED DEFAULT '0',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `Z7E_knx_items_categories`
--

INSERT INTO `Z7E_knx_items_categories` (`id`, `hub_id`, `name`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'Famous Alfredo', 1, 'active', '2026-01-07 02:07:56', '2026-01-07 02:07:56');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_item_addon_groups`
--

CREATE TABLE `Z7E_knx_item_addon_groups` (
  `id` bigint UNSIGNED NOT NULL,
  `item_id` bigint UNSIGNED NOT NULL,
  `group_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_item_global_modifiers`
--

CREATE TABLE `Z7E_knx_item_global_modifiers` (
  `id` bigint UNSIGNED NOT NULL,
  `item_id` bigint UNSIGNED NOT NULL,
  `global_modifier_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_item_modifiers`
--

CREATE TABLE `Z7E_knx_item_modifiers` (
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
-- Volcado de datos para la tabla `Z7E_knx_item_modifiers`
--

INSERT INTO `Z7E_knx_item_modifiers` (`id`, `item_id`, `hub_id`, `name`, `type`, `required`, `min_selection`, `max_selection`, `is_global`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Size', 'multiple', 0, 0, NULL, 0, 1, '2026-01-07 02:11:04', '2026-01-07 02:11:04');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_modifier_options`
--

CREATE TABLE `Z7E_knx_modifier_options` (
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
-- Volcado de datos para la tabla `Z7E_knx_modifier_options`
--

INSERT INTO `Z7E_knx_modifier_options` (`id`, `modifier_id`, `name`, `price_adjustment`, `is_default`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 'Small', 0.00, 0, 1, '2026-01-07 02:11:04', '2026-01-07 02:11:04'),
(2, 1, 'Large', 6.79, 0, 2, '2026-01-07 02:11:04', '2026-01-07 02:11:04');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_ops_settings`
--

CREATE TABLE `Z7E_knx_ops_settings` (
  `id` bigint UNSIGNED NOT NULL,
  `setting_key` varchar(80) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `setting_json` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_orders`
--

CREATE TABLE `Z7E_knx_orders` (
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
-- Volcado de datos para la tabla `Z7E_knx_orders`
--

INSERT INTO `Z7E_knx_orders` (`id`, `order_number`, `hub_id`, `city_id`, `session_token`, `customer_id`, `fulfillment_type`, `customer_name`, `customer_phone`, `customer_email`, `delivery_address`, `delivery_address_id`, `delivery_lat`, `delivery_lng`, `delivery_distance`, `delivery_duration_minutes`, `estimated_delivery_at`, `subtotal`, `tax_rate`, `tax_amount`, `delivery_fee`, `software_fee`, `tip_amount`, `tip_percent`, `tip_source`, `discount_amount`, `coupon_code`, `coupon_id`, `gift_card_amount`, `gift_card_code`, `gift_card_id`, `total`, `status`, `driver_id`, `payment_method`, `payment_status`, `payment_transaction_id`, `notes`, `cart_snapshot`, `totals_snapshot`, `created_at`, `updated_at`) VALUES
(1, 'ORD-A2D58AB4A6', 1, 1, 'knx_d938a90d8b5aa819bbf04328b', 1, 'delivery', NULL, NULL, NULL, '10000 N • Red Wall House • Manteno, IL 60950 • USA', NULL, 41.2650530, -87.8587190, NULL, NULL, NULL, 24.88, 20.00, 4.98, 10.19, 0.99, 0.00, NULL, 'none', 0.00, NULL, NULL, 0.00, NULL, NULL, 41.04, 'placed', NULL, 'stripe', 'paid', 'pi_3SpeH0Hmf3DAvp5R1IPB6T5Z', NULL, '{\"items\": [{\"item_id\": 1, \"quantity\": 1, \"modifiers\": [{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}], \"line_total\": 24.88, \"unit_price\": 24.88, \"name_snapshot\": \"Shrimp Alfredo Pasta\", \"image_snapshot\": \"https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg\"}], \"hub_id\": 1, \"hub_name\": \"Chef Vaughn\'s Kitchen\", \"subtotal\": 24.88, \"created_at\": \"2026-01-15 00:18:49\", \"item_count\": 1, \"session_token\": \"knx_d938a90d8b5aa819bbf04328b\"}', '{\"total\": 41.04, \"source\": \"checkout_quote\", \"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"version\": \"v1\", \"frozen_at\": \"2026-01-15 00:18:49\", \"address_id\": 6}, \"version\": \"v5_snapshot_locked\", \"currency\": \"USD\", \"delivery\": {\"coverage_ok\": true, \"distance_km\": 16.37, \"distance_mi\": 10.170000000000002, \"eta_minutes\": 55, \"delivery_fee\": 10.19, \"fee_rule_name\": \"Standard Delivery Fee\", \"is_free_delivery\": false, \"delivery_snapshot_v46\": {\"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"address_id\": 6}, \"version\": \"v4.6_sealed\", \"coverage\": {\"reason\": \"DELIVERABLE\", \"zone_id\": 7, \"zone_name\": \"Main Delivery Area\", \"checked_at\": \"2026-01-15 00:18:49\"}, \"distance\": {\"km\": 16.37, \"miles\": 10.170000000000002, \"eta_minutes\": 55, \"calculated_at\": \"2026-01-15 00:18:49\"}, \"delivery_fee\": {\"amount\": 10.19, \"rule_name\": \"Standard Delivery Fee\", \"calculated_at\": \"2026-01-15 00:18:49\", \"is_free_delivery\": false}, \"snapshot_created_at\": \"2026-01-15 00:18:49\"}}, \"subtotal\": 24.88, \"tax_rate\": 20, \"tax_amount\": 4.98, \"tip_amount\": 0, \"delivery_fee\": 10.19, \"finalized_at\": \"2026-01-15 00:18:49\", \"software_fee\": 0.9900000000000002, \"calculated_at\": \"2026-01-15 00:18:49\", \"discount_amount\": 0, \"is_cart_detached\": true, \"is_snapshot_locked\": true, \"delivery_snapshot_v46\": {\"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"address_id\": 6}, \"version\": \"v4.6_sealed\", \"coverage\": {\"reason\": \"DELIVERABLE\", \"zone_id\": 7, \"zone_name\": \"Main Delivery Area\", \"checked_at\": \"2026-01-15 00:18:49\"}, \"distance\": {\"km\": 16.37, \"miles\": 10.170000000000002, \"eta_minutes\": 55, \"calculated_at\": \"2026-01-15 00:18:49\"}, \"delivery_fee\": {\"amount\": 10.19, \"rule_name\": \"Standard Delivery Fee\", \"calculated_at\": \"2026-01-15 00:18:49\", \"is_free_delivery\": false}, \"snapshot_created_at\": \"2026-01-15 00:18:49\"}}', '2026-01-15 06:18:49', '2026-01-15 01:07:05'),
(2, 'ORD-16B9EEFDF6', 1, 1, 'knx_6170c2c0edbbd819bd7e62f1b', 1, 'delivery', NULL, NULL, NULL, '10000 N • Red Wall House • Manteno, IL 60950 • USA', NULL, 41.2650530, -87.8587190, NULL, NULL, NULL, 24.88, 20.00, 4.98, 10.19, 0.99, 0.00, NULL, 'none', 0.00, NULL, NULL, 0.00, NULL, NULL, 41.04, 'confirmed', NULL, 'stripe', 'paid', 'pi_3SrOrvHmf3DAvp5R1unpaIqX', NULL, '{\"items\": [{\"item_id\": 1, \"quantity\": 1, \"modifiers\": [{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}], \"line_total\": 24.88, \"unit_price\": 24.88, \"name_snapshot\": \"Shrimp Alfredo Pasta\", \"image_snapshot\": \"https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg\"}], \"hub_id\": 1, \"hub_name\": \"Chef Vaughn\'s Kitchen\", \"subtotal\": 24.88, \"created_at\": \"2026-01-19 20:16:10\", \"item_count\": 1, \"session_token\": \"knx_6170c2c0edbbd819bd7e62f1b\"}', '{\"total\": 41.04, \"source\": \"checkout_quote\", \"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"version\": \"v1\", \"frozen_at\": \"2026-01-19 20:16:10\", \"address_id\": 6}, \"version\": \"v5_snapshot_locked\", \"currency\": \"USD\", \"delivery\": {\"coverage_ok\": true, \"distance_km\": 16.37, \"distance_mi\": 10.170000000000002, \"eta_minutes\": 55, \"delivery_fee\": 10.19, \"fee_rule_name\": \"Standard Delivery Fee\", \"is_free_delivery\": false, \"delivery_snapshot_v46\": {\"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"address_id\": 6}, \"version\": \"v4.6_sealed\", \"coverage\": {\"reason\": \"DELIVERABLE\", \"zone_id\": 7, \"zone_name\": \"Main Delivery Area\", \"checked_at\": \"2026-01-19 20:16:10\"}, \"distance\": {\"km\": 16.37, \"miles\": 10.170000000000002, \"eta_minutes\": 55, \"calculated_at\": \"2026-01-19 20:16:10\"}, \"delivery_fee\": {\"amount\": 10.19, \"rule_name\": \"Standard Delivery Fee\", \"calculated_at\": \"2026-01-19 20:16:10\", \"is_free_delivery\": false}, \"snapshot_created_at\": \"2026-01-19 20:16:10\"}}, \"subtotal\": 24.88, \"tax_rate\": 20, \"tax_amount\": 4.98, \"tip_amount\": 0, \"delivery_fee\": 10.19, \"finalized_at\": \"2026-01-19 20:16:10\", \"software_fee\": 0.9900000000000002, \"calculated_at\": \"2026-01-19 20:16:10\", \"discount_amount\": 0, \"is_cart_detached\": true, \"is_snapshot_locked\": true, \"delivery_snapshot_v46\": {\"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"address_id\": 6}, \"version\": \"v4.6_sealed\", \"coverage\": {\"reason\": \"DELIVERABLE\", \"zone_id\": 7, \"zone_name\": \"Main Delivery Area\", \"checked_at\": \"2026-01-19 20:16:10\"}, \"distance\": {\"km\": 16.37, \"miles\": 10.170000000000002, \"eta_minutes\": 55, \"calculated_at\": \"2026-01-19 20:16:10\"}, \"delivery_fee\": {\"amount\": 10.19, \"rule_name\": \"Standard Delivery Fee\", \"calculated_at\": \"2026-01-19 20:16:10\", \"is_free_delivery\": false}, \"snapshot_created_at\": \"2026-01-19 20:16:10\"}}', '2026-01-20 02:16:10', '2026-01-20 02:16:13'),
(3, 'ORD-7A585A279C', 1, 1, 'knx_54c44bb16033319bd8847cd7', 1, 'delivery', NULL, NULL, NULL, '10000 N • Red Wall House • Manteno, IL 60950 • USA', NULL, 41.2650530, -87.8587190, NULL, NULL, NULL, 223.92, 20.00, 44.78, 0.00, 0.99, 0.00, NULL, 'none', 0.00, NULL, NULL, 0.00, NULL, NULL, 269.69, 'confirmed', NULL, 'stripe', 'paid', 'pi_3SrRZ9Hmf3DAvp5R1dKqmRCs', NULL, '{\"items\": [{\"item_id\": 1, \"quantity\": 9, \"modifiers\": [{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}], \"line_total\": 223.92, \"unit_price\": 24.88, \"name_snapshot\": \"Shrimp Alfredo Pasta\", \"image_snapshot\": \"https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg\"}], \"hub_id\": 1, \"hub_name\": \"Chef Vaughn\'s Kitchen\", \"subtotal\": 223.92, \"created_at\": \"2026-01-19 23:08:59\", \"item_count\": 9, \"session_token\": \"knx_54c44bb16033319bd8847cd7\"}', '{\"total\": 269.69, \"source\": \"checkout_quote\", \"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"version\": \"v1\", \"frozen_at\": \"2026-01-19 23:08:59\", \"address_id\": 6}, \"version\": \"v5_snapshot_locked\", \"currency\": \"USD\", \"delivery\": {\"coverage_ok\": true, \"distance_km\": 16.37, \"distance_mi\": 10.170000000000002, \"eta_minutes\": 55, \"delivery_fee\": 0, \"fee_rule_name\": \"Standard Delivery Fee\", \"is_free_delivery\": true, \"delivery_snapshot_v46\": {\"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"address_id\": 6}, \"version\": \"v4.6_sealed\", \"coverage\": {\"reason\": \"DELIVERABLE\", \"zone_id\": 7, \"zone_name\": \"Main Delivery Area\", \"checked_at\": \"2026-01-19 23:08:59\"}, \"distance\": {\"km\": 16.37, \"miles\": 10.170000000000002, \"eta_minutes\": 55, \"calculated_at\": \"2026-01-19 23:08:59\"}, \"delivery_fee\": {\"amount\": 0, \"rule_name\": \"Standard Delivery Fee\", \"calculated_at\": \"2026-01-19 23:08:59\", \"is_free_delivery\": true}, \"snapshot_created_at\": \"2026-01-19 23:08:59\"}}, \"subtotal\": 223.92, \"tax_rate\": 20, \"tax_amount\": 44.78, \"tip_amount\": 0, \"delivery_fee\": 0, \"finalized_at\": \"2026-01-19 23:08:59\", \"software_fee\": 0.9900000000000002, \"calculated_at\": \"2026-01-19 23:08:59\", \"discount_amount\": 0, \"is_cart_detached\": true, \"is_snapshot_locked\": true, \"delivery_snapshot_v46\": {\"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"address_id\": 6}, \"version\": \"v4.6_sealed\", \"coverage\": {\"reason\": \"DELIVERABLE\", \"zone_id\": 7, \"zone_name\": \"Main Delivery Area\", \"checked_at\": \"2026-01-19 23:08:59\"}, \"distance\": {\"km\": 16.37, \"miles\": 10.170000000000002, \"eta_minutes\": 55, \"calculated_at\": \"2026-01-19 23:08:59\"}, \"delivery_fee\": {\"amount\": 0, \"rule_name\": \"Standard Delivery Fee\", \"calculated_at\": \"2026-01-19 23:08:59\", \"is_free_delivery\": true}, \"snapshot_created_at\": \"2026-01-19 23:08:59\"}}', '2026-01-20 05:08:59', '2026-01-20 05:09:01'),
(4, 'ORD-335EF0395F', 1, 1, 'knx_f57674a63c48219bdda7634e', 1, 'delivery', NULL, NULL, NULL, '10000 N • Red Wall House • Manteno, IL 60950 • USA', NULL, 41.2650530, -87.8587190, NULL, NULL, NULL, 373.20, 20.00, 74.64, 0.00, 0.99, 10.00, NULL, 'none', 0.00, NULL, NULL, 0.00, NULL, NULL, 458.83, 'confirmed', NULL, 'stripe', 'paid', 'pi_3SrnzhHmf3DAvp5R1ZSY3UDv', NULL, '{\"items\": [{\"item_id\": 1, \"quantity\": 15, \"modifiers\": [{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}], \"line_total\": 373.2, \"unit_price\": 24.88, \"name_snapshot\": \"Shrimp Alfredo Pasta\", \"image_snapshot\": \"https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg\"}], \"hub_id\": 1, \"hub_name\": \"Chef Vaughn\'s Kitchen\", \"subtotal\": 373.2, \"created_at\": \"2026-01-20 23:05:52\", \"item_count\": 15, \"session_token\": \"knx_f57674a63c48219bdda7634e\"}', '{\"total\": 458.83, \"source\": \"checkout_quote\", \"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"version\": \"v1\", \"frozen_at\": \"2026-01-20 23:05:52\", \"address_id\": 6}, \"version\": \"v5_snapshot_locked\", \"currency\": \"USD\", \"delivery\": {\"coverage_ok\": true, \"distance_km\": 16.37, \"distance_mi\": 10.170000000000002, \"eta_minutes\": 55, \"delivery_fee\": 0, \"fee_rule_name\": \"Standard Delivery Fee\", \"is_free_delivery\": true, \"delivery_snapshot_v46\": {\"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"address_id\": 6}, \"version\": \"v4.6_sealed\", \"coverage\": {\"reason\": \"DELIVERABLE\", \"zone_id\": 7, \"zone_name\": \"Main Delivery Area\", \"checked_at\": \"2026-01-20 23:05:52\"}, \"distance\": {\"km\": 16.37, \"miles\": 10.170000000000002, \"eta_minutes\": 55, \"calculated_at\": \"2026-01-20 23:05:52\"}, \"delivery_fee\": {\"amount\": 0, \"rule_name\": \"Standard Delivery Fee\", \"calculated_at\": \"2026-01-20 23:05:52\", \"is_free_delivery\": true}, \"snapshot_created_at\": \"2026-01-20 23:05:52\"}}, \"subtotal\": 373.2, \"tax_rate\": 20, \"tax_amount\": 74.64, \"tip_amount\": 10, \"delivery_fee\": 0, \"finalized_at\": \"2026-01-20 23:05:52\", \"software_fee\": 0.9900000000000002, \"calculated_at\": \"2026-01-20 23:05:52\", \"discount_amount\": 0, \"is_cart_detached\": true, \"is_snapshot_locked\": true, \"delivery_snapshot_v46\": {\"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"address_id\": 6}, \"version\": \"v4.6_sealed\", \"coverage\": {\"reason\": \"DELIVERABLE\", \"zone_id\": 7, \"zone_name\": \"Main Delivery Area\", \"checked_at\": \"2026-01-20 23:05:52\"}, \"distance\": {\"km\": 16.37, \"miles\": 10.170000000000002, \"eta_minutes\": 55, \"calculated_at\": \"2026-01-20 23:05:52\"}, \"delivery_fee\": {\"amount\": 0, \"rule_name\": \"Standard Delivery Fee\", \"calculated_at\": \"2026-01-20 23:05:52\", \"is_free_delivery\": true}, \"snapshot_created_at\": \"2026-01-20 23:05:52\"}}', '2026-01-21 05:05:52', '2026-01-21 05:05:54'),
(5, 'ORD-720D7252D7', 1, 1, 'knx_e5fd1a08aab38819bdda97df6', 1, 'delivery', NULL, NULL, NULL, '10000 N • Red Wall House • Manteno, IL 60950 • USA', NULL, 41.2650530, -87.8587190, NULL, NULL, NULL, 174.16, 20.00, 34.83, 0.00, 0.99, 3.00, NULL, 'none', 0.00, NULL, NULL, 0.00, NULL, NULL, 212.98, 'confirmed', NULL, 'stripe', 'paid', 'pi_3Sro1IHmf3DAvp5R12BR4ZsM', NULL, '{\"items\": [{\"item_id\": 1, \"quantity\": 7, \"modifiers\": [{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}], \"line_total\": 174.16, \"unit_price\": 24.88, \"name_snapshot\": \"Shrimp Alfredo Pasta\", \"image_snapshot\": \"https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg\"}], \"hub_id\": 1, \"hub_name\": \"Chef Vaughn\'s Kitchen\", \"subtotal\": 174.16, \"created_at\": \"2026-01-20 23:07:32\", \"item_count\": 7, \"session_token\": \"knx_e5fd1a08aab38819bdda97df6\"}', '{\"total\": 212.98, \"source\": \"checkout_quote\", \"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"version\": \"v1\", \"frozen_at\": \"2026-01-20 23:07:32\", \"address_id\": 6}, \"version\": \"v5_snapshot_locked\", \"currency\": \"USD\", \"delivery\": {\"coverage_ok\": true, \"distance_km\": 16.37, \"distance_mi\": 10.170000000000002, \"eta_minutes\": 55, \"delivery_fee\": 0, \"fee_rule_name\": \"Standard Delivery Fee\", \"is_free_delivery\": true, \"delivery_snapshot_v46\": {\"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"address_id\": 6}, \"version\": \"v4.6_sealed\", \"coverage\": {\"reason\": \"DELIVERABLE\", \"zone_id\": 7, \"zone_name\": \"Main Delivery Area\", \"checked_at\": \"2026-01-20 23:07:32\"}, \"distance\": {\"km\": 16.37, \"miles\": 10.170000000000002, \"eta_minutes\": 55, \"calculated_at\": \"2026-01-20 23:07:32\"}, \"delivery_fee\": {\"amount\": 0, \"rule_name\": \"Standard Delivery Fee\", \"calculated_at\": \"2026-01-20 23:07:32\", \"is_free_delivery\": true}, \"snapshot_created_at\": \"2026-01-20 23:07:32\"}}, \"subtotal\": 174.16, \"tax_rate\": 20, \"tax_amount\": 34.83, \"tip_amount\": 3, \"delivery_fee\": 0, \"finalized_at\": \"2026-01-20 23:07:32\", \"software_fee\": 0.9900000000000002, \"calculated_at\": \"2026-01-20 23:07:32\", \"discount_amount\": 0, \"is_cart_detached\": true, \"is_snapshot_locked\": true, \"delivery_snapshot_v46\": {\"address\": {\"lat\": 41.265053, \"lng\": -87.858719, \"label\": \"10000 N • Red Wall House • Manteno, IL 60950 • USA\", \"address_id\": 6}, \"version\": \"v4.6_sealed\", \"coverage\": {\"reason\": \"DELIVERABLE\", \"zone_id\": 7, \"zone_name\": \"Main Delivery Area\", \"checked_at\": \"2026-01-20 23:07:32\"}, \"distance\": {\"km\": 16.37, \"miles\": 10.170000000000002, \"eta_minutes\": 55, \"calculated_at\": \"2026-01-20 23:07:32\"}, \"delivery_fee\": {\"amount\": 0, \"rule_name\": \"Standard Delivery Fee\", \"calculated_at\": \"2026-01-20 23:07:32\", \"is_free_delivery\": true}, \"snapshot_created_at\": \"2026-01-20 23:07:32\"}}', '2026-01-21 05:07:32', '2026-01-21 05:07:34');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_order_items`
--

CREATE TABLE `Z7E_knx_order_items` (
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
-- Volcado de datos para la tabla `Z7E_knx_order_items`
--

INSERT INTO `Z7E_knx_order_items` (`id`, `order_id`, `item_id`, `name_snapshot`, `image_snapshot`, `quantity`, `unit_price`, `line_total`, `modifiers_json`, `created_at`) VALUES
(1, 1, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-15 06:18:49'),
(2, 2, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 1, 24.88, 24.88, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-20 02:16:10'),
(3, 3, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 9, 24.88, 223.92, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-20 05:08:59'),
(4, 4, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 15, 24.88, 373.20, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-21 05:05:52'),
(5, 5, 1, 'Shrimp Alfredo Pasta', 'https://ourlocalcollective.org/wp-content/uploads/knx-items/1/item_695d6bdc904792.53985123.jpg', 7, 24.88, 174.16, '[{\"id\": 1, \"name\": \"Size\", \"type\": \"multiple\", \"options\": [{\"id\": 2, \"name\": \"Large\", \"price_adjustment\": 6.79}], \"required\": false}]', '2026-01-21 05:07:32');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_order_status_history`
--

CREATE TABLE `Z7E_knx_order_status_history` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` bigint UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `Z7E_knx_order_status_history`
--

INSERT INTO `Z7E_knx_order_status_history` (`id`, `order_id`, `status`, `changed_by`, `created_at`) VALUES
(1, 1, 'placed', 1, '2026-01-15 06:18:49'),
(2, 1, 'confirmed', NULL, '2026-01-15 06:18:51'),
(3, 2, 'placed', 1, '2026-01-20 02:16:10'),
(4, 2, 'confirmed', NULL, '2026-01-20 02:16:13'),
(5, 3, 'placed', 1, '2026-01-20 05:08:59'),
(6, 3, 'confirmed', NULL, '2026-01-20 05:09:01'),
(7, 4, 'placed', 1, '2026-01-21 05:05:52'),
(8, 4, 'confirmed', NULL, '2026-01-21 05:05:54'),
(9, 5, 'placed', 1, '2026-01-21 05:07:32'),
(10, 5, 'confirmed', NULL, '2026-01-21 05:07:34');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_payments`
--

CREATE TABLE `Z7E_knx_payments` (
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
-- Volcado de datos para la tabla `Z7E_knx_payments`
--

INSERT INTO `Z7E_knx_payments` (`id`, `order_id`, `provider`, `provider_intent_id`, `amount`, `currency`, `status`, `created_at`, `updated_at`) VALUES
(1, 10, 'stripe', 'pi_3SoUKRHmf3DAvp5R16mc4ZCw', 23984, 'usd', 'intent_created', '2026-01-11 19:29:35', '2026-01-11 19:29:35'),
(2, 12, 'stripe', 'pi_3SoYATHmf3DAvp5R0ujQYol9', 11768, 'usd', 'intent_created', '2026-01-11 23:59:11', '2026-01-11 23:59:11'),
(3, 13, 'stripe', 'pi_3SoYn7Hmf3DAvp5R19WuJQ0G', 26969, 'usd', 'intent_created', '2026-01-12 00:15:29', '2026-01-12 00:15:29'),
(4, 14, 'stripe', 'pi_3Sob6ZHmf3DAvp5R1GCRwfv7', 4104, 'usd', 'paid', '2026-01-12 02:43:43', '2026-01-12 03:42:54'),
(5, 15, 'stripe', 'pi_3SobEsHmf3DAvp5R0lH55Zqy', 4104, 'usd', 'paid', '2026-01-12 02:52:18', '2026-01-12 02:52:19'),
(6, 16, 'stripe', 'pi_3SoboTHmf3DAvp5R0P9ReUeX', 32941, 'usd', 'intent_created', '2026-01-12 03:29:05', '2026-01-12 03:29:05'),
(7, 17, 'stripe', 'pi_3SobrhHmf3DAvp5R12YYkRPm', 3589, 'usd', 'paid', '2026-01-12 03:32:25', '2026-01-12 03:32:27'),
(8, 18, 'stripe', 'pi_3SocrDHmf3DAvp5R1hwkCGcz', 4104, 'usd', 'paid', '2026-01-12 04:35:59', '2026-01-12 04:36:00'),
(9, 22, 'stripe', 'pi_3SpBWyHmf3DAvp5R1RzeDbS1', 4104, 'usd', 'paid', '2026-01-13 17:37:24', '2026-01-13 17:37:26'),
(10, 23, 'stripe', 'pi_3SpDHeHmf3DAvp5R0Dn3hi1s', 4104, 'usd', 'paid', '2026-01-13 19:29:42', '2026-01-13 19:29:43'),
(11, 24, 'stripe', 'pi_3SpXDlHmf3DAvp5R09te4XWb', 4104, 'usd', 'paid', '2026-01-14 16:47:01', '2026-01-14 16:47:03'),
(12, 1, 'stripe', 'pi_3SpeH0Hmf3DAvp5R1IPB6T5Z', 4104, 'usd', 'paid', '2026-01-15 00:18:50', '2026-01-15 00:18:51'),
(13, 2, 'stripe', 'pi_3SrOrvHmf3DAvp5R1unpaIqX', 4104, 'usd', 'paid', '2026-01-19 20:16:11', '2026-01-19 20:16:13'),
(14, 3, 'stripe', 'pi_3SrRZ9Hmf3DAvp5R1dKqmRCs', 26969, 'usd', 'paid', '2026-01-19 23:09:00', '2026-01-19 23:09:01'),
(15, 4, 'stripe', 'pi_3SrnzhHmf3DAvp5R1ZSY3UDv', 45883, 'usd', 'paid', '2026-01-20 23:05:53', '2026-01-20 23:05:54'),
(16, 5, 'stripe', 'pi_3Sro1IHmf3DAvp5R12BR4ZsM', 21298, 'usd', 'paid', '2026-01-20 23:07:33', '2026-01-20 23:07:34');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_push_subscriptions`
--

CREATE TABLE `Z7E_knx_push_subscriptions` (
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
-- Volcado de datos para la tabla `Z7E_knx_push_subscriptions`
--

INSERT INTO `Z7E_knx_push_subscriptions` (`id`, `user_id`, `role`, `endpoint`, `p256dh`, `auth`, `created_at`, `revoked_at`) VALUES
(1, 1, 'super_admin', 'https://example.com/fake-endpoint', 'fake_p256dh_key', 'fake_auth_key', '2026-01-21 07:22:19', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_sessions`
--

CREATE TABLE `Z7E_knx_sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `token` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Volcado de datos para la tabla `Z7E_knx_sessions`
--

INSERT INTO `Z7E_knx_sessions` (`id`, `user_id`, `token`, `ip_address`, `user_agent`, `expires_at`, `created_at`) VALUES
(78, 7, '29d8fb6000f31b5f8913aff5ea468c55f8469badfad5ab831c269a37b2898633', '45.235.255.211', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2026-01-21 23:45:20', '2026-01-20 23:45:20');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_settings`
--

CREATE TABLE `Z7E_knx_settings` (
  `id` bigint UNSIGNED NOT NULL,
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `setting_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_software_fees`
--

CREATE TABLE `Z7E_knx_software_fees` (
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
-- Volcado de datos para la tabla `Z7E_knx_software_fees`
--

INSERT INTO `Z7E_knx_software_fees` (`id`, `scope`, `city_id`, `hub_id`, `fee_amount`, `status`, `created_at`, `updated_at`) VALUES
(1, 'hub', 1, 1, 0.99, 'active', '2026-01-08 09:45:39', '2026-01-08 09:46:38'),
(2, 'city', 1, 0, 0.99, 'active', '2026-01-08 09:45:39', '2026-01-08 09:46:13');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_tax_rules`
--

CREATE TABLE `Z7E_knx_tax_rules` (
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