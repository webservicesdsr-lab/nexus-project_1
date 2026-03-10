-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 09-03-2026 a las 21:47:45
-- Versión del servidor: 8.0.45-36
-- Versión de PHP: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `oywwofte_WPJEE`
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

INSERT INTO `y05_knx_items_categories` (`id`, `hub_id`, `name`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
(3, 3, 'Kids Menu', 1, 'active', '2026-02-12 04:29:08', '2026-03-01 20:20:34'),
(5, 3, 'Appetizer', 2, 'active', '2026-03-01 04:12:15', '2026-03-01 14:20:25'),
(6, 3, 'Burgers', 3, 'active', '2026-03-01 04:12:24', '2026-03-01 14:20:25'),
(7, 3, 'Create Your Burger', 4, 'active', '2026-03-01 04:12:37', '2026-03-01 14:20:25'),
(8, 3, 'Sandwiches', 5, 'active', '2026-03-01 04:13:12', '2026-03-01 14:20:25'),
(9, 3, 'Salads', 6, 'active', '2026-03-01 04:13:29', '2026-03-01 14:20:25'),
(10, 3, 'What The Cluck?', 7, 'active', '2026-03-01 04:14:05', '2026-03-01 14:20:25'),
(11, 4, 'Famous Alfredo', 1, 'active', '2026-03-08 03:58:04', '2026-03-08 03:58:04');

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

--
-- Volcado de datos para la tabla `y05_knx_item_global_modifiers`
--

INSERT INTO `y05_knx_item_global_modifiers` (`id`, `item_id`, `global_modifier_id`, `created_at`) VALUES
(1, 13, 5, '2026-03-08 03:16:33');

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

INSERT INTO `y05_knx_item_modifiers` (`id`, `item_id`, `hub_id`, `name`, `type`, `required`, `min_selection`, `max_selection`, `is_global`, `sort_order`, `created_at`, `updated_at`) VALUES
(5, NULL, 3, 'Size', 'single', 1, 0, NULL, 1, 1, '2026-03-08 03:16:24', '2026-03-08 03:16:24'),
(6, 13, 3, 'Size', 'single', 1, 0, NULL, 0, 1, '2026-03-08 03:16:33', '2026-03-08 03:16:33'),
(7, 13, 3, 'Extras', 'multiple', 0, 0, 3, 0, 2, '2026-03-08 03:17:31', '2026-03-08 03:17:31'),
(8, 14, 4, 'Size', 'single', 1, 0, NULL, 0, 1, '2026-03-08 04:00:58', '2026-03-08 04:01:16'),
(9, 14, 4, 'Extras', 'multiple', 0, 0, NULL, 0, 2, '2026-03-08 04:02:46', '2026-03-08 04:02:46');

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

INSERT INTO `y05_knx_modifier_options` (`id`, `modifier_id`, `name`, `price_adjustment`, `is_default`, `sort_order`, `created_at`, `updated_at`) VALUES
(13, 5, 'Small', 0.00, 0, 1, '2026-03-08 03:16:24', '2026-03-08 03:16:24'),
(14, 5, 'Large', 3.99, 0, 2, '2026-03-08 03:16:24', '2026-03-08 03:16:24'),
(15, 6, 'Small', 0.00, 0, 1, '2026-03-08 03:16:33', '2026-03-08 03:16:33'),
(16, 6, 'Large', 3.99, 0, 2, '2026-03-08 03:16:33', '2026-03-08 03:16:33'),
(17, 7, 'Large French Fries', 3.99, 0, 1, '2026-03-08 03:17:32', '2026-03-08 03:17:32'),
(18, 7, 'Small French Fries', 3.99, 0, 2, '2026-03-08 03:17:32', '2026-03-08 03:17:32'),
(19, 7, 'Tater Tots', 3.99, 0, 3, '2026-03-08 03:17:32', '2026-03-08 03:17:32'),
(20, 8, 'Small', 0.00, 0, 1, '2026-03-08 04:00:58', '2026-03-08 04:01:16'),
(21, 8, 'Large', 6.79, 0, 2, '2026-03-08 04:00:58', '2026-03-08 04:01:16'),
(22, 8, 'Half Pan', 74.59, 0, 3, '2026-03-08 04:01:16', '2026-03-08 04:01:16'),
(23, 9, 'Add Shrimp', 7.89, 0, 1, '2026-03-08 04:02:47', '2026-03-08 04:02:47'),
(24, 9, 'Add Extra Chicken', 6.79, 0, 2, '2026-03-08 04:02:47', '2026-03-08 04:02:47'),
(25, 9, 'Add Extra Alfredo Sauce', 7.39, 0, 3, '2026-03-08 04:02:47', '2026-03-08 04:02:47'),
(26, 9, 'Add Cajun Style', 0.39, 0, 4, '2026-03-08 04:02:47', '2026-03-08 04:02:47');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `y05_knx_items_categories`
--
ALTER TABLE `y05_knx_items_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `hub_id_2` (`hub_id`,`sort_order`),
  ADD KEY `status` (`status`);

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
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `y05_knx_items_categories`
--
ALTER TABLE `y05_knx_items_categories`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT de la tabla `y05_knx_item_global_modifiers`
--
ALTER TABLE `y05_knx_item_global_modifiers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `y05_knx_item_modifiers`
--
ALTER TABLE `y05_knx_item_modifiers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `y05_knx_modifier_options`
--
ALTER TABLE `y05_knx_modifier_options`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `y05_knx_items_categories`
--
ALTER TABLE `y05_knx_items_categories`
  ADD CONSTRAINT `y05_knx_items_categories_ibfk_1` FOREIGN KEY (`hub_id`) REFERENCES `y05_knx_hubs` (`id`) ON DELETE CASCADE;

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
