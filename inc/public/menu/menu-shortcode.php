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

	$menu_items_raw = $wpdb->get_results($wpdb->prepare(
		"SELECT i.*, c.name as category_name
		 FROM {$table_items} i
		 LEFT JOIN {$table_categories} c ON i.category_id = c.id
		 WHERE i.hub_id = %d AND i.status = 'active'
		 ORDER BY i.sort_order ASC, i.name ASC",
		$restaurant->id
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
			'id'          => (int) $item->id,
			'name'        => $item->name,
			'description' => $item->description ?: '',
			'price'       => (float) $item->price,
			'image'       => $item->image_url ?: '',
			'category'    => $item->category_name ?: 'Uncategorized',
			'category_id' => (int) $item->category_id,
			'modifiers'   => $modifiers,
		];
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

	$now_ts      = current_time('timestamp');
	$status_text = 'Closed';

	if (!$can_order) {
		// IMPORTANT: Any block (temp closed, indefinite, city paused, closing soon, etc.) => CLOSED only.
		$status_text = 'CLOSED';
	} else {
		// Preserve your existing "Open until X" behavior when within hours.
		$weekday = strtolower(date_i18n('l', $now_ts));
		$hours_field = 'hours_' . $weekday;
		$hours_json = $restaurant->$hours_field ?? '';

		if ($hours_json) {
			$slots = json_decode($hours_json, true);
			if (is_array($slots)) {
				$is_open_now = false;
				$close_ts_for_text = null;

				foreach ($slots as $slot) {

					$open  = $slot['open'] ?? null;
					$close = $slot['close'] ?? null;

					if (!$open || !$close || $open === 'closed') continue;

					$base_date = date_i18n('Y-m-d', $now_ts);
					$open_ts   = strtotime("$base_date $open");
					$close_ts  = strtotime("$base_date $close");

					if ($close_ts <= $open_ts) {
						$close_ts += DAY_IN_SECONDS;
					}

					if ($now_ts >= $open_ts && $now_ts <= $close_ts) {
						$is_open_now = true;
						$close_ts_for_text = $close_ts;
						break;
					}
				}

				if ($is_open_now && $close_ts_for_text) {
					$status_text = 'Open until ' . date_i18n('g:i A', $close_ts_for_text);
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
		<div class="knx-menu__items" id="knxMenuItems">

			<?php if (empty($menu_items)): ?>
				<div class="knx-menu__empty">
					<i class="fas fa-utensils"></i>
					<h3>No menu items yet</h3>
					<p>This restaurant is still setting up their menu.</p>
				</div>

			<?php else: ?>

				<?php foreach ($menu_items as $item): ?>
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
					>

						<div class="knx-menu__card-img-wrap">
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
