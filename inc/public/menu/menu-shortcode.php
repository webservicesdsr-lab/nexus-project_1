<?php
if (!defined('ABSPATH')) exit;

add_shortcode('knx_menu', 'knx_render_menu_page');

function knx_render_menu_page() {
	global $wpdb;

	$hub_slug   = get_query_var('hub_slug');
	$restaurant = null;

	if ($hub_slug && function_exists('knx_get_hub_by_slug')) {
		$restaurant = knx_get_hub_by_slug($hub_slug);
	}

	if (!$restaurant) {
		return '<div class="knx-menu-error" style="padding:40px;text-align:center;">
			<h2>Restaurant not found</h2>
			<p>The restaurant you are looking for does not exist or is not available.</p>
		</div>';
	}

	/* ==========================================================
	   FETCH CATEGORIES
	========================================================== */
	$table_categories = $wpdb->prefix . 'knx_items_categories';
	$categories_raw = $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM {$table_categories}
		 WHERE hub_id = %d AND status = 'active'
		 ORDER BY sort_order ASC, name ASC",
		$restaurant->id
	));

	$categories = [['id' => 0, 'name' => 'All']];
	foreach ($categories_raw as $cat) {
		$categories[] = [
			'id'   => $cat->id,
			'name' => $cat->name,
		];
	}

	/* ==========================================================
	   FETCH ITEMS + MODIFIERS + OPTIONS
	========================================================== */
	$table_items = $wpdb->prefix . 'knx_hub_items';

	/*
	 * Availability-aware query:
	 * - regular  → always visible
	 * - daily    → only on matching day-of-week (1=Mon..7=Sun) and within time range
	 * - seasonal → only within the date range (starts_at ≤ NOW ≤ ends_at)
	 */
	$now_mysql    = current_time('mysql');
	$current_dow  = (int) current_time('N'); // 1=Mon … 7=Sun (ISO-8601)
	$current_time = current_time('H:i:s');

	$menu_items_raw = $wpdb->get_results($wpdb->prepare(
		"SELECT i.*, c.name as category_name
		 FROM {$table_items} i
		 LEFT JOIN {$table_categories} c ON i.category_id = c.id
		 WHERE i.hub_id = %d AND i.status = 'active'
		   AND (
		       i.availability_type = 'regular'
		    OR i.availability_type IS NULL
		    OR (
		       i.availability_type = 'daily'
		       AND FIND_IN_SET(%s, i.daily_day_of_week)
		       AND (
		           (i.daily_start_time IS NULL AND i.daily_end_time IS NULL)
		        OR (i.daily_start_time IS NOT NULL AND i.daily_end_time IS NOT NULL
		            AND %s BETWEEN i.daily_start_time AND i.daily_end_time)
		        OR (i.daily_start_time IS NOT NULL AND i.daily_end_time IS NULL
		            AND %s >= i.daily_start_time)
		        OR (i.daily_start_time IS NULL AND i.daily_end_time IS NOT NULL
		            AND %s <= i.daily_end_time)
		       )
		    )
		    OR (
		       i.availability_type = 'seasonal'
		       AND (i.seasonal_starts_at IS NULL OR i.seasonal_starts_at <= %s)
		       AND (i.seasonal_ends_at   IS NULL OR i.seasonal_ends_at   >= %s)
		    )
		   )
		 ORDER BY i.sort_order ASC, i.name ASC",
		$restaurant->id,
		$current_dow,
		$current_time,
		$current_time,
		$current_time,
		$now_mysql,
		$now_mysql
	));

	$menu_items = [];

	foreach ($menu_items_raw as $item) {

		$table_modifiers = $wpdb->prefix . 'knx_item_modifiers';
		$mods_raw = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$table_modifiers}
			 WHERE item_id = %d AND hub_id = %d
			 ORDER BY sort_order ASC",
			$item->id,
			$restaurant->id
		));

		$modifiers = [];

		foreach ($mods_raw as $mod) {

			$table_options = $wpdb->prefix . 'knx_modifier_options';
			$options_raw = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$table_options}
				 WHERE modifier_id = %d
				 ORDER BY sort_order ASC",
				$mod->id
			));

			$options = [];
			foreach ($options_raw as $opt) {
				$options[] = [
					'id'               => (int) $opt->id,
					'name'             => $opt->name,
					'price_adjustment' => (float) $opt->price_adjustment,
					'is_default'       => (bool) $opt->is_default,
				];
			}

			$modifiers[] = [
				'id'       => (int) $mod->id,
				'name'     => $mod->name,
				'type'     => $mod->type ?: 'single',
				'required' => (bool) $mod->required,
				'options'  => $options,
			];
		}

		$menu_items[] = [
			'id'                => (int) $item->id,
			'name'              => $item->name,
			'description'       => $item->description ?: '',
			'price'             => (float) $item->price,
			'image'             => $item->image_url ?: '',
			'category'          => $item->category_name ?: 'Uncategorized',
			'category_id'       => (int) $item->category_id,
			'availability_type' => $item->availability_type ?: 'regular',
			'modifiers'         => $modifiers,
		];
	}

	// Group items by category name for rendering into separate grids
	$menu_items_by_category = [];
	foreach ($menu_items as $mi) {
		$cat = $mi['category'] ?: 'Uncategorized';
		if (!isset($menu_items_by_category[$cat])) $menu_items_by_category[$cat] = [];
		$menu_items_by_category[$cat][] = $mi;
	}

	/* ==========================================================
	   AVAILABILITY (SINGLE SOURCE OF TRUTH)
	   - If availability blocks ordering for ANY reason => header shows "CLOSED"
	   - If available => keep existing "Open until X" behavior
	========================================================== */
	$availability = null;
	if (function_exists('knx_availability_decision')) {
		$availability = knx_availability_decision((int) $restaurant->id);
	}

	$can_order = is_array($availability) && isset($availability['can_order'])
		? (bool) $availability['can_order']
		: true; // Fail-open for header display if engine missing; Add-to-cart will still guard in JS if availability is present.

	$is_preorder = is_array($availability) && !empty($availability['is_preorder']);

	$status_text = 'Closed';

	/*
	 * Use the HUB timezone for the "Open until …" text — must match
	 * the same clock knx_availability_decision() uses. WordPress'
	 * current_time() uses the WP Settings timezone, which may differ.
	 */
	$hub_tz_name = !empty($restaurant->timezone) ? trim((string) $restaurant->timezone) : 'America/Chicago';
	try {
		$hub_tz  = new DateTimeZone($hub_tz_name);
		$hub_now = new DateTime('now', $hub_tz);
	} catch (Exception $e) {
		$hub_tz  = new DateTimeZone('UTC');
		$hub_now = new DateTime('now', $hub_tz);
	}

	if (!$can_order) {
		// IMPORTANT: Any block (temp closed, indefinite, city paused, closing soon, etc.) => CLOSED only.
		$status_text = 'CLOSED';
	} else if ($is_preorder) {
		// Pre-order mode (v2.1): Hub is not yet open but accepts advance orders for today.
		$opens_at_display = '';
		if (!empty($availability['opens_at'])) {
			try {
				$opens_dt = new DateTime($availability['opens_at']);
				$opens_at_display = ' · Opens at ' . $opens_dt->format('g:i A');
			} catch (Exception $e) {
				$opens_at_display = '';
			}
		}
		$status_text = 'Pre-order for today' . $opens_at_display;
	} else {
		// Calculate "Open until X" using the HUB timezone (same clock as availability engine).
		$weekday     = strtolower($hub_now->format('l'));
		$hours_field = 'hours_' . $weekday;
		$hours_json  = $restaurant->$hours_field ?? '';

		if ($hours_json) {
			$slots = json_decode($hours_json, true);
			if (is_array($slots)) {
				$is_open_now       = false;
				$close_display     = null;
				$hub_current_time  = $hub_now->format('H:i');

				foreach ($slots as $slot) {

					$open  = $slot['open'] ?? null;
					$close = $slot['close'] ?? null;

					if (!$open || !$close || $open === 'closed') continue;

					$is_overnight = ($close < $open);

					if ($is_overnight) {
						// Overnight: open if current >= open OR current <= close
						if ($hub_current_time >= $open || $hub_current_time <= $close) {
							$is_open_now   = true;
							$close_display = $close;
							break;
						}
					} else {
						if ($hub_current_time >= $open && $hub_current_time <= $close) {
							$is_open_now   = true;
							$close_display = $close;
							break;
						}
					}
				}

				if ($is_open_now && $close_display) {
					try {
						$close_dt = DateTime::createFromFormat('H:i', $close_display);
						if ($close_dt) {
							$status_text = 'Open until ' . $close_dt->format('g:i A');
						}
					} catch (Exception $e) {
						$status_text = 'Open now';
					}
				}
			}
		}
	}

	/* ==========================================================
	   HERO IMAGE
	========================================================== */
	$hero_image =
		$restaurant->image ??
		$restaurant->hero_img ??
		$restaurant->logo_url ??
		'https://via.placeholder.com/800x600?text=Restaurant';

	$availability_json = $availability ? wp_json_encode($availability) : '';

	ob_start();
	?>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/public/menu/menu-style.css?v=' . KNX_VERSION); ?>">
<script src="<?php echo esc_url(KNX_URL . 'inc/public/menu/menu-script.js?v=' . KNX_VERSION); ?>" defer></script>

<div id="olc-menu"
     class="knx-menu"
     data-hub-id="<?php echo esc_attr($restaurant->id); ?>"
     data-hub-name="<?php echo esc_attr($restaurant->name); ?>"
     data-closure-reason="<?php echo esc_attr($restaurant->closure_reason ?? ''); ?>"
     data-availability='<?php echo esc_attr($availability_json); ?>'>

	<header class="knx-header">

		<button class="knx-header-back" type="button" onclick="history.back()">
			<i class="fas fa-chevron-left"></i>
			<span>Back</span>
		</button>

		<!-- MOBILE HERO -->
		<div class="knx-header-mobile">
			<div class="knx-header-mobile-banner">
				<img src="<?php echo esc_url($hero_image); ?>" alt="">
			</div>

			<div class="knx-header-mobile-info">
				<h1 class="knx-header-title"><?php echo esc_html($restaurant->name); ?></h1>
				<div class="knx-header-status-row"><?php echo esc_html($status_text); ?></div>

				<!-- MOBILE SEARCH -->
				<div class="knx-mobile-search">
					<div class="knx-mobile-search-wrapper">
						<i class="fas fa-search knx-mobile-search-icon"></i>
						<input
							type="text"
							id="knxMenuSearchMobile"
							class="knx-mobile-search-input"
							placeholder="Search a meal..."
						>
						<button class="knx-mobile-search-btn" type="button">
							Search
						</button>
					</div>
				</div>

			</div>
		</div>

		<!-- DESKTOP HERO -->
		<div class="knx-header-desktop knx-desktop-hero">
			<div class="knx-desktop-hero-bg">
				<img src="<?php echo esc_url($hero_image); ?>">
			</div>

			<div class="knx-desktop-hero-content">
				<h1 class="knx-desktop-hero-title"><?php echo esc_html($restaurant->name); ?></h1>
				<p class="knx-desktop-hero-subtitle"><?php echo esc_html($status_text); ?></p>

				<div class="knx-desktop-hero-search">
					<i class="fas fa-search"></i>
					<input type="text" id="knxMenuSearchDesktop" class="knx-desktop-hero-search-input" placeholder="Search a meal...">
					<button class="knx-desktop-hero-search-btn">Search</button>
				</div>
			</div>
		</div>

	</header>

	<!-- CATEGORY CHIPS -->
	<div class="knx-menu__categories">
		<?php foreach ($categories as $index => $cat): ?>
			<button class="knx-menu__category-chip <?php echo $index === 0 ? 'active' : ''; ?>"
			        data-category="<?php echo esc_attr($cat['name']); ?>">
				<?php echo esc_html($cat['name']); ?>
			</button>
		<?php endforeach; ?>
	</div>

	<!-- MENU ITEMS -->
	<section class="knx-menu__tab-content active" data-content="menu">
		<div id="knxMenuItems">

			<?php if (empty($menu_items)): ?>
				<div class="knx-menu__empty">
					<i class="fas fa-utensils"></i>
					<h3>No menu items yet</h3>
					<p>This restaurant is still setting up their menu.</p>
				</div>

			<?php else: ?>

				<?php
				// Render groups in the order of $categories (skip the 'All' fake category)
				foreach ($categories as $index => $cat):
					if ($index === 0) continue; // skip 'All'
					$cat_name = $cat['name'];
					$items_for_cat = $menu_items_by_category[$cat_name] ?? [];
					if (empty($items_for_cat)) continue;
				?>
					<div class="knx-menu__category-group" data-category="<?php echo esc_attr($cat_name); ?>">
						<h2 class="knx-menu__category-title"><?php echo esc_html($cat_name); ?></h2>
						<div class="knx-menu__items">
							<?php foreach ($items_for_cat as $item): ?>
								<?php
								$price_raw  = number_format($item['price'], 2, '.', '');
								$price_disp = number_format($item['price'], 2);
								$mods_json  = wp_json_encode($item['modifiers']);
								?>
								<article
									class="knx-menu__card"
									data-category="<?php echo esc_attr($item['category']); ?>"
									data-name="<?php echo esc_attr(strtolower($item['name'])); ?>"
									data-description="<?php echo esc_attr(strtolower($item['description'])); ?>"
									data-item-id="<?php echo esc_attr($item['id']); ?>"
									data-item-name="<?php echo esc_attr($item['name']); ?>"
									data-item-price="<?php echo esc_attr($price_raw); ?>"
									data-item-image="<?php echo esc_url($item['image']); ?>"
									data-item-desc="<?php echo esc_attr($item['description']); ?>"
									data-item-modifiers='<?php echo esc_attr($mods_json); ?>'
									data-availability="<?php echo esc_attr($item['availability_type']); ?>"
								>

									<div class="knx-menu__card-img-wrap">
										<?php if ($item['availability_type'] === 'daily'): ?>
											<span class="knx-menu__avail-badge knx-menu__avail-badge--daily">Daily</span>
										<?php elseif ($item['availability_type'] === 'seasonal'): ?>
											<span class="knx-menu__avail-badge knx-menu__avail-badge--seasonal">Seasonal</span>
										<?php endif; ?>
										<?php if ($item['image']): ?>
											<img src="<?php echo esc_url($item['image']); ?>" class="knx-menu__card-image" loading="lazy">
										<?php else: ?>
											<div class="knx-menu__card-image knx-menu__card-image--placeholder"></div>
										<?php endif; ?>
									</div>

									<div class="knx-menu__card-body">
										<div class="knx-menu__price-pill">$<?php echo esc_html($price_disp); ?></div>
										<h3 class="knx-menu__card-title"><?php echo esc_html($item['name']); ?></h3>
										<p class="knx-menu__card-desc"><?php echo esc_html($item['description']); ?></p>
									</div>

								</article>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>

			<?php endif; ?>

		</div>

		<div class="knx-menu__empty" id="knxMenuEmpty" style="display:none;">
			<i class="fas fa-search"></i>
			<h3>No items found</h3>
			<p>Try adjusting your search</p>
		</div>

	</section>

	<!-- ==========================================================
	     ITEM MODAL (existing)
	     ========================================================== -->
	<div class="knx-menu__modal" id="knxMenuModal" style="display:none;">

		<div class="knx-menu__modal-backdrop"></div>

		<div class="knx-menu__modal-dialog">

			<div class="knx-modal-close-wrapper">
				<button class="knx-menu__modal-close" id="knxMenuModalClose">&times;</button>
			</div>

			<div id="knxMenuModalBody"></div>

		</div>
	</div>

	<!-- ==========================================================
	     NEXUS AVAILABILITY MODAL (Menu)
	     - Triggered ONLY on Add To Cart when unavailable
	     ========================================================== -->
	<div id="knx-availability-modal" class="knx-avail hidden" aria-hidden="true">
		<div class="knx-avail-backdrop" aria-hidden="true"></div>

		<div class="knx-avail-card" role="dialog" aria-modal="true">
			<button type="button" class="knx-avail-x" aria-label="Close availability modal">&times;</button>

			<div class="knx-avail-icon" id="knxAvailIcon">⏰</div>

			<h3 class="knx-avail-title" id="knxAvailTitle">This restaurant is unavailable</h3>

			<p class="knx-avail-message" id="knxAvailMessage">
				Please check back later or explore other local spots.
			</p>

			<div class="knx-avail-countdown" id="knxAvailCountdown" style="display:none;"></div>

			<div class="knx-avail-actions">
				<button type="button" class="btn btn-amber knx-avail-close">
					Explore other restaurants
				</button>
			</div>
		</div>
	</div>

</div>

<?php
	return ob_get_clean();
}
