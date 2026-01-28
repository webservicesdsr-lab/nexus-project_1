

--
-- Estructura de tabla para la tabla `Z7E_knx_tip_settings`
--

CREATE TABLE `Z7E_knx_tip_settings` (
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
-- Estructura de tabla para la tabla `Z7E_knx_users`
--

CREATE TABLE `Z7E_knx_users` (
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
-- Volcado de datos para la tabla `Z7E_knx_users`
--

INSERT INTO `Z7E_knx_users` (`id`, `username`, `email`, `name`, `phone`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'SuperAdmin', 'superadmin@email.com', 'Daniel SR', '+1 948 323 2923', '$2y$10$MvzK1eB3U43dpMejKSC5BuyUw0VGO9OKSiNfln5tashQ.xku2FUh6', 'super_admin', 'active', '2026-01-06 19:55:31', '2026-01-08 01:54:55'),
(5, 'colleen_olc100', 'colleen_olc100@outlook.com', 'Collen', '+708 567 3556', '$2y$10$iDQrX4pEcOflfYwabn5buuiEwr97sGlmtZu4VVQA12LnY8LT5ZQBS', 'driver', 'active', '2026-01-16 06:27:25', '2026-01-17 00:23:11'),
(6, 'jeremy_olc100', 'jeremy_olc100@gmail.com', 'Jeremy', '+1 815-386-3652', '$2y$10$C21H57jaaIhJ9V4KgJlaXO670xE78Np5vB13KwXusGtUXp2sk2oZG', 'driver', 'active', '2026-01-17 00:18:07', '2026-01-17 00:22:48'),
(7, 'keisha_olc100', 'keisha_olc100@hotmail.com', 'Keisha', '+1 708 004 2923', '$2y$10$./SDOzXmBU89FzS2p5KYsOmtaT8rvZhvv6EyevOnBUhQkQ0NfMmtm', 'driver', 'active', '2026-01-17 15:10:32', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Z7E_knx_webhook_events`
--

CREATE TABLE `Z7E_knx_webhook_events` (
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
-- Volcado de datos para la tabla `Z7E_knx_webhook_events`
--

INSERT INTO `Z7E_knx_webhook_events` (`id`, `provider`, `event_id`, `event_type`, `intent_id`, `order_id`, `processed_at`, `created_at`) VALUES
(1, 'stripe', 'evt_3SobEsHmf3DAvp5R0uzi3Urq', 'payment_intent.succeeded', 'pi_3SobEsHmf3DAvp5R0lH55Zqy', 15, '2026-01-12 02:52:19', '2026-01-12 02:52:19'),
(2, 'stripe', 'evt_3SobrhHmf3DAvp5R1LWQupcB', 'payment_intent.succeeded', 'pi_3SobrhHmf3DAvp5R12YYkRPm', 17, '2026-01-12 03:32:27', '2026-01-12 03:32:27'),
(3, 'stripe', 'evt_3Sob6ZHmf3DAvp5R16KGvGZh', 'payment_intent.succeeded', 'pi_3Sob6ZHmf3DAvp5R1GCRwfv7', 14, '2026-01-12 03:42:54', '2026-01-12 03:42:54'),
(4, 'stripe', 'evt_3SocrDHmf3DAvp5R1O5QiGzw', 'payment_intent.succeeded', 'pi_3SocrDHmf3DAvp5R1hwkCGcz', 18, '2026-01-12 04:36:00', '2026-01-12 04:36:00'),
(5, 'stripe', 'evt_3SpBWyHmf3DAvp5R1a2VjO8r', 'payment_intent.succeeded', 'pi_3SpBWyHmf3DAvp5R1RzeDbS1', 22, '2026-01-13 17:37:26', '2026-01-13 17:37:26'),
(6, 'stripe', 'evt_3SpDHeHmf3DAvp5R0S2b2SwD', 'payment_intent.succeeded', 'pi_3SpDHeHmf3DAvp5R0Dn3hi1s', 23, '2026-01-13 19:29:43', '2026-01-13 19:29:43'),
(7, 'stripe', 'evt_3SpXDlHmf3DAvp5R0VYvLybc', 'payment_intent.succeeded', 'pi_3SpXDlHmf3DAvp5R09te4XWb', 24, '2026-01-14 16:47:03', '2026-01-14 16:47:03'),
(8, 'stripe', 'evt_3SpeH0Hmf3DAvp5R1sWCUIJE', 'payment_intent.succeeded', 'pi_3SpeH0Hmf3DAvp5R1IPB6T5Z', 1, '2026-01-15 00:18:51', '2026-01-15 00:18:51'),
(9, 'stripe', 'evt_3SrOrvHmf3DAvp5R1LakqD4m', 'payment_intent.succeeded', 'pi_3SrOrvHmf3DAvp5R1unpaIqX', 2, '2026-01-19 20:16:13', '2026-01-19 20:16:13'),
(10, 'stripe', 'evt_3SrRZ9Hmf3DAvp5R1n51YNSB', 'payment_intent.succeeded', 'pi_3SrRZ9Hmf3DAvp5R1dKqmRCs', 3, '2026-01-19 23:09:01', '2026-01-19 23:09:01'),
(11, 'stripe', 'evt_3SrnzhHmf3DAvp5R1FG8iJYR', 'payment_intent.succeeded', 'pi_3SrnzhHmf3DAvp5R1ZSY3UDv', 4, '2026-01-20 23:05:54', '2026-01-20 23:05:54'),
(12, 'stripe', 'evt_3Sro1IHmf3DAvp5R1E3EOBya', 'payment_intent.succeeded', 'pi_3Sro1IHmf3DAvp5R12BR4ZsM', 5, '2026-01-20 23:07:34', '2026-01-20 23:07:34');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `Z7E_knx_addons`
--
ALTER TABLE `Z7E_knx_addons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `group_id_2` (`group_id`,`sort_order`),
  ADD KEY `status` (`status`);

--
-- Indices de la tabla `Z7E_knx_addon_groups`
--
ALTER TABLE `Z7E_knx_addon_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `hub_id_2` (`hub_id`,`sort_order`),
  ADD KEY `name` (`name`);

--
-- Indices de la tabla `Z7E_knx_addresses`
--
ALTER TABLE `Z7E_knx_addresses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_default_address_per_customer` (`default_customer_id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_deleted_at` (`deleted_at`),
  ADD KEY `idx_latlng` (`latitude`,`longitude`),
  ADD KEY `idx_customer_default_lookup` (`customer_id`,`is_default`,`status`,`deleted_at`);

--
-- Indices de la tabla `Z7E_knx_carts`
--
ALTER TABLE `Z7E_knx_carts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_active_session_hub` (`active_session_token`,`hub_id`),
  ADD UNIQUE KEY `uq_active_customer_hub` (`active_customer_id`,`hub_id`),
  ADD KEY `session_idx` (`session_token`),
  ADD KEY `customer_idx` (`customer_id`),
  ADD KEY `hub_idx` (`hub_id`),
  ADD KEY `status_idx` (`status`),
  ADD KEY `idx_session_status` (`session_token`,`status`);

--
-- Indices de la tabla `Z7E_knx_cart_items`
--
ALTER TABLE `Z7E_knx_cart_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cart_idx` (`cart_id`),
  ADD KEY `item_idx` (`item_id`);

--
-- Indices de la tabla `Z7E_knx_cities`
--
ALTER TABLE `Z7E_knx_cities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `name` (`name`),
  ADD KEY `idx_is_operational` (`is_operational`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- Indices de la tabla `Z7E_knx_coupons`
--
ALTER TABLE `Z7E_knx_coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `status` (`status`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indices de la tabla `Z7E_knx_coupon_redemptions`
--
ALTER TABLE `Z7E_knx_coupon_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_coupon_id` (`coupon_id`),
  ADD KEY `idx_coupon_code` (`coupon_code`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_customer_id` (`customer_id`);

--
-- Indices de la tabla `Z7E_knx_delivery_fee_rules`
--
ALTER TABLE `Z7E_knx_delivery_fee_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hub_active` (`hub_id`,`is_active`),
  ADD KEY `idx_city_active` (`city_id`,`is_active`),
  ADD KEY `idx_zone_active` (`zone_id`,`is_active`),
  ADD KEY `idx_priority` (`priority` DESC);

--
-- Indices de la tabla `Z7E_knx_delivery_rates`
--
ALTER TABLE `Z7E_knx_delivery_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_city` (`city_id`),
  ADD KEY `status` (`status`),
  ADD KEY `city_id` (`city_id`);

--
-- Indices de la tabla `Z7E_knx_delivery_zones`
--
ALTER TABLE `Z7E_knx_delivery_zones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `hub_active` (`hub_id`,`is_active`);

--
-- Indices de la tabla `Z7E_knx_drivers`
--
ALTER TABLE `Z7E_knx_drivers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_knx_drivers_user_id` (`user_id`);

--
-- Indices de la tabla `Z7E_knx_driver_availability`
--
ALTER TABLE `Z7E_knx_driver_availability`
  ADD PRIMARY KEY (`driver_user_id`),
  ADD KEY `idx_knx_driver_availability_status` (`status`);

--
-- Indices de la tabla `Z7E_knx_driver_hubs`
--
ALTER TABLE `Z7E_knx_driver_hubs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_driver_hub` (`driver_id`,`hub_id`),
  ADD KEY `idx_driver_id` (`driver_id`),
  ADD KEY `idx_hub_id` (`hub_id`);

--
-- Indices de la tabla `Z7E_knx_driver_ops`
--
ALTER TABLE `Z7E_knx_driver_ops`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_order` (`order_id`),
  ADD KEY `idx_driver` (`driver_user_id`),
  ADD KEY `idx_ops_status` (`ops_status`),
  ADD KEY `idx_updated` (`updated_at`);

--
-- Indices de la tabla `Z7E_knx_gift_cards`
--
ALTER TABLE `Z7E_knx_gift_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_code` (`code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indices de la tabla `Z7E_knx_gift_card_transactions`
--
ALTER TABLE `Z7E_knx_gift_card_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gift_card_id` (`gift_card_id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_type` (`type`);

--
-- Indices de la tabla `Z7E_knx_hubs`
--
ALTER TABLE `Z7E_knx_hubs`
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
-- Indices de la tabla `Z7E_knx_hub_categories`
--
ALTER TABLE `Z7E_knx_hub_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `sort_order` (`sort_order`),
  ADD KEY `name` (`name`);

--
-- Indices de la tabla `Z7E_knx_hub_items`
--
ALTER TABLE `Z7E_knx_hub_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `hub_id_2` (`hub_id`,`category_id`,`sort_order`),
  ADD KEY `status` (`status`);

--
-- Indices de la tabla `Z7E_knx_items_categories`
--
ALTER TABLE `Z7E_knx_items_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `hub_id_2` (`hub_id`,`sort_order`),
  ADD KEY `status` (`status`);

--
-- Indices de la tabla `Z7E_knx_item_addon_groups`
--
ALTER TABLE `Z7E_knx_item_addon_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_item_group` (`item_id`,`group_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indices de la tabla `Z7E_knx_item_global_modifiers`
--
ALTER TABLE `Z7E_knx_item_global_modifiers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_item_global` (`item_id`,`global_modifier_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `global_modifier_id` (`global_modifier_id`);

--
-- Indices de la tabla `Z7E_knx_item_modifiers`
--
ALTER TABLE `Z7E_knx_item_modifiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `hub_id` (`hub_id`),
  ADD KEY `is_global` (`is_global`),
  ADD KEY `item_id_2` (`item_id`,`sort_order`),
  ADD KEY `hub_id_2` (`hub_id`,`is_global`,`sort_order`);

--
-- Indices de la tabla `Z7E_knx_modifier_options`
--
ALTER TABLE `Z7E_knx_modifier_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `modifier_id` (`modifier_id`),
  ADD KEY `modifier_id_2` (`modifier_id`,`sort_order`);

--
-- Indices de la tabla `Z7E_knx_ops_settings`
--
ALTER TABLE `Z7E_knx_ops_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_setting_key` (`setting_key`);

--
-- Indices de la tabla `Z7E_knx_orders`
--
ALTER TABLE `Z7E_knx_orders`
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
-- Indices de la tabla `Z7E_knx_order_items`
--
ALTER TABLE `Z7E_knx_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_item_id` (`item_id`);

--
-- Indices de la tabla `Z7E_knx_order_status_history`
--
ALTER TABLE `Z7E_knx_order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indices de la tabla `Z7E_knx_payments`
--
ALTER TABLE `Z7E_knx_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_provider_intent` (`provider`,`provider_intent_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indices de la tabla `Z7E_knx_push_subscriptions`
--
ALTER TABLE `Z7E_knx_push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_role` (`user_id`,`role`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_revoked_at` (`revoked_at`);

--
-- Indices de la tabla `Z7E_knx_sessions`
--
ALTER TABLE `Z7E_knx_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `token_2` (`token`),
  ADD KEY `expires_at` (`expires_at`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `Z7E_knx_settings`
--
ALTER TABLE `Z7E_knx_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `setting_key_2` (`setting_key`);

--
-- Indices de la tabla `Z7E_knx_software_fees`
--
ALTER TABLE `Z7E_knx_software_fees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_scope_city_hub` (`scope`,`city_id`,`hub_id`),
  ADD KEY `idx_city` (`city_id`),
  ADD KEY `idx_hub` (`hub_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indices de la tabla `Z7E_knx_tax_rules`
--
ALTER TABLE `Z7E_knx_tax_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scope_status` (`scope`,`status`),
  ADD KEY `idx_scope_id` (`scope_id`),
  ADD KEY `idx_priority` (`priority`);

--
-- Indices de la tabla `Z7E_knx_tip_settings`
--
ALTER TABLE `Z7E_knx_tip_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_scope_city_hub` (`scope`,`city_id`,`hub_id`),
  ADD KEY `idx_city` (`city_id`),
  ADD KEY `idx_hub` (`hub_id`);

--
-- Indices de la tabla `Z7E_knx_users`
--
ALTER TABLE `Z7E_knx_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `email_2` (`email`),
  ADD KEY `role` (`role`),
  ADD KEY `status` (`status`),
  ADD KEY `idx_name` (`name`),
  ADD KEY `idx_phone` (`phone`);

--
-- Indices de la tabla `Z7E_knx_webhook_events`
--
ALTER TABLE `Z7E_knx_webhook_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `event_id` (`event_id`),
  ADD KEY `intent_id` (`intent_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `processed_at` (`processed_at`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_addons`
--
ALTER TABLE `Z7E_knx_addons`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_addon_groups`
--
ALTER TABLE `Z7E_knx_addon_groups`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_addresses`
--
ALTER TABLE `Z7E_knx_addresses`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_carts`
--
ALTER TABLE `Z7E_knx_carts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=167;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_cart_items`
--
ALTER TABLE `Z7E_knx_cart_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=971;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_cities`
--
ALTER TABLE `Z7E_knx_cities`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_coupons`
--
ALTER TABLE `Z7E_knx_coupons`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_coupon_redemptions`
--
ALTER TABLE `Z7E_knx_coupon_redemptions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_delivery_fee_rules`
--
ALTER TABLE `Z7E_knx_delivery_fee_rules`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_delivery_rates`
--
ALTER TABLE `Z7E_knx_delivery_rates`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_delivery_zones`
--
ALTER TABLE `Z7E_knx_delivery_zones`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_drivers`
--
ALTER TABLE `Z7E_knx_drivers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_driver_hubs`
--
ALTER TABLE `Z7E_knx_driver_hubs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_driver_ops`
--
ALTER TABLE `Z7E_knx_driver_ops`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_gift_cards`
--
ALTER TABLE `Z7E_knx_gift_cards`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_gift_card_transactions`
--
ALTER TABLE `Z7E_knx_gift_card_transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_hubs`
--
ALTER TABLE `Z7E_knx_hubs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_hub_categories`
--
ALTER TABLE `Z7E_knx_hub_categories`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_hub_items`
--
ALTER TABLE `Z7E_knx_hub_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_items_categories`
--
ALTER TABLE `Z7E_knx_items_categories`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_item_addon_groups`
--
ALTER TABLE `Z7E_knx_item_addon_groups`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_item_global_modifiers`
--
ALTER TABLE `Z7E_knx_item_global_modifiers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_item_modifiers`
--
ALTER TABLE `Z7E_knx_item_modifiers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_modifier_options`
--
ALTER TABLE `Z7E_knx_modifier_options`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_ops_settings`
--
ALTER TABLE `Z7E_knx_ops_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_orders`
--
ALTER TABLE `Z7E_knx_orders`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_order_items`
--
ALTER TABLE `Z7E_knx_order_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_order_status_history`
--
ALTER TABLE `Z7E_knx_order_status_history`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_payments`
--
ALTER TABLE `Z7E_knx_payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_push_subscriptions`
--
ALTER TABLE `Z7E_knx_push_subscriptions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_sessions`
--
ALTER TABLE `Z7E_knx_sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_settings`
--
ALTER TABLE `Z7E_knx_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_software_fees`
--
ALTER TABLE `Z7E_knx_software_fees`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_tax_rules`
--
ALTER TABLE `Z7E_knx_tax_rules`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_tip_settings`
--
ALTER TABLE `Z7E_knx_tip_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_users`
--
ALTER TABLE `Z7E_knx_users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `Z7E_knx_webhook_events`
--
ALTER TABLE `Z7E_knx_webhook_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `Z7E_knx_addons`
--
ALTER TABLE `Z7E_knx_addons`
  ADD CONSTRAINT `Z7E_knx_addons_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `Z7E_knx_addon_groups` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_addon_groups`
--
ALTER TABLE `Z7E_knx_addon_groups`
  ADD CONSTRAINT `Z7E_knx_addon_groups_ibfk_1` FOREIGN KEY (`hub_id`) REFERENCES `Z7E_knx_hubs` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_addresses`
--
ALTER TABLE `Z7E_knx_addresses`
  ADD CONSTRAINT `fk_addresses_customer` FOREIGN KEY (`customer_id`) REFERENCES `Z7E_knx_users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_carts`
--
ALTER TABLE `Z7E_knx_carts`
  ADD CONSTRAINT `fk_carts_customer` FOREIGN KEY (`customer_id`) REFERENCES `Z7E_knx_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_carts_hub` FOREIGN KEY (`hub_id`) REFERENCES `Z7E_knx_hubs` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_cart_items`
--
ALTER TABLE `Z7E_knx_cart_items`
  ADD CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`) REFERENCES `Z7E_knx_carts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cart_items_item` FOREIGN KEY (`item_id`) REFERENCES `Z7E_knx_hub_items` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `Z7E_knx_delivery_rates`
--
ALTER TABLE `Z7E_knx_delivery_rates`
  ADD CONSTRAINT `Z7E_knx_delivery_rates_ibfk_1` FOREIGN KEY (`city_id`) REFERENCES `Z7E_knx_cities` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_delivery_zones`
--
ALTER TABLE `Z7E_knx_delivery_zones`
  ADD CONSTRAINT `Z7E_knx_delivery_zones_ibfk_1` FOREIGN KEY (`hub_id`) REFERENCES `Z7E_knx_hubs` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_hubs`
--
ALTER TABLE `Z7E_knx_hubs`
  ADD CONSTRAINT `Z7E_knx_hubs_ibfk_1` FOREIGN KEY (`city_id`) REFERENCES `Z7E_knx_cities` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `Z7E_knx_hubs_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `Z7E_knx_hub_categories` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `Z7E_knx_hub_items`
--
ALTER TABLE `Z7E_knx_hub_items`
  ADD CONSTRAINT `Z7E_knx_hub_items_ibfk_1` FOREIGN KEY (`hub_id`) REFERENCES `Z7E_knx_hubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `Z7E_knx_hub_items_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `Z7E_knx_items_categories` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `Z7E_knx_items_categories`
--
ALTER TABLE `Z7E_knx_items_categories`
  ADD CONSTRAINT `Z7E_knx_items_categories_ibfk_1` FOREIGN KEY (`hub_id`) REFERENCES `Z7E_knx_hubs` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_item_addon_groups`
--
ALTER TABLE `Z7E_knx_item_addon_groups`
  ADD CONSTRAINT `Z7E_knx_item_addon_groups_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `Z7E_knx_hub_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `Z7E_knx_item_addon_groups_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `Z7E_knx_addon_groups` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_item_global_modifiers`
--
ALTER TABLE `Z7E_knx_item_global_modifiers`
  ADD CONSTRAINT `Z7E_knx_item_global_modifiers_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `Z7E_knx_hub_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `Z7E_knx_item_global_modifiers_ibfk_2` FOREIGN KEY (`global_modifier_id`) REFERENCES `Z7E_knx_item_modifiers` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_item_modifiers`
--
ALTER TABLE `Z7E_knx_item_modifiers`
  ADD CONSTRAINT `Z7E_knx_item_modifiers_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `Z7E_knx_hub_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `Z7E_knx_item_modifiers_ibfk_2` FOREIGN KEY (`hub_id`) REFERENCES `Z7E_knx_hubs` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_modifier_options`
--
ALTER TABLE `Z7E_knx_modifier_options`
  ADD CONSTRAINT `Z7E_knx_modifier_options_ibfk_1` FOREIGN KEY (`modifier_id`) REFERENCES `Z7E_knx_item_modifiers` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_orders`
--
ALTER TABLE `Z7E_knx_orders`
  ADD CONSTRAINT `fk_orders_delivery_address` FOREIGN KEY (`delivery_address_id`) REFERENCES `Z7E_knx_addresses` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `Z7E_knx_order_items`
--
ALTER TABLE `Z7E_knx_order_items`
  ADD CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `Z7E_knx_orders` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_order_status_history`
--
ALTER TABLE `Z7E_knx_order_status_history`
  ADD CONSTRAINT `fk_order_status_history_order` FOREIGN KEY (`order_id`) REFERENCES `Z7E_knx_orders` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `Z7E_knx_sessions`
--
ALTER TABLE `Z7E_knx_sessions`
  ADD CONSTRAINT `Z7E_knx_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Z7E_knx_users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
