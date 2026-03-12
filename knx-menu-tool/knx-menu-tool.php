<?php
/**
 * Plugin Name: KNX Menu Tool
 * Description: URL-only standalone menu tool. Access ONLY via /knx-menu-tool
 * Version: 3.2.2
 * Author: Kingdom Nexus
 */

if (!defined('ABSPATH')) exit;

define('KNX_MENU_TOOL_PATH', plugin_dir_path(__FILE__));
define('KNX_MENU_TOOL_URL', plugin_dir_url(__FILE__));

/**
 * KNX login URL.
 * Override in wp-config.php if needed:
 * define('KNX_MENU_TOOL_LOGIN_URL', home_url('/login'));
 */
if (!defined('KNX_MENU_TOOL_LOGIN_URL')) {
    define('KNX_MENU_TOOL_LOGIN_URL', home_url('/login'));
}

require_once KNX_MENU_TOOL_PATH . 'inc/core/security.php';
require_once KNX_MENU_TOOL_PATH . 'inc/core/render.php';
require_once KNX_MENU_TOOL_PATH . 'inc/core/ajax.php';
require_once KNX_MENU_TOOL_PATH . 'inc/core/text-cleaner.php';
require_once KNX_MENU_TOOL_PATH . 'inc/core/structure-engine.php';
require_once KNX_MENU_TOOL_PATH . 'inc/core/csv-generator.php';

final class KNX_Menu_Tool_Bootstrap {

    public function __construct() {
        add_action('init', [$this, 'register_rewrite_rule']);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'handle_template_redirect']);

        /**
         * Logged-in WP users
         */
        add_action('wp_ajax_knx_menu_tool_structure', 'knx_mt_ajax_structure');
        add_action('wp_ajax_knx_menu_tool_get_modifiers', 'knx_mt_ajax_get_modifiers');
        add_action('wp_ajax_knx_menu_tool_download_csv', 'knx_mt_ajax_download_csv');

        /**
         * Non-WP-authenticated visitors using KNX session
         */
        add_action('wp_ajax_nopriv_knx_menu_tool_structure', 'knx_mt_ajax_structure');
        add_action('wp_ajax_nopriv_knx_menu_tool_get_modifiers', 'knx_mt_ajax_get_modifiers');
        add_action('wp_ajax_nopriv_knx_menu_tool_download_csv', 'knx_mt_ajax_download_csv');
    }

    public function register_rewrite_rule() {
        add_rewrite_rule('^knx-menu-tool/?$', 'index.php?knx_menu_tool=1', 'top');
    }

    public function register_query_var($vars) {
        $vars[] = 'knx_menu_tool';
        return $vars;
    }

    public function handle_template_redirect() {
        if ((int) get_query_var('knx_menu_tool') !== 1) {
            return;
        }

        $gate = KNX_Menu_Tool_Security::check_page_access();

        if ($gate['mode'] === 'nexus_required') {
            status_header(403);
            KNX_Menu_Tool_Render::render_nexus_required_page();
            exit;
        }

        if ($gate['mode'] === 'redirect_login') {
            wp_safe_redirect($gate['login_url'], 302);
            exit;
        }

        KNX_Menu_Tool_Render::render_app_page($gate['user_id'], $gate['role']);
        exit;
    }

    public static function on_activation() {
        add_rewrite_rule('^knx-menu-tool/?$', 'index.php?knx_menu_tool=1', 'top');
        flush_rewrite_rules();
    }

    public static function on_deactivation() {
        flush_rewrite_rules();
    }
}

new KNX_Menu_Tool_Bootstrap();

register_activation_hook(__FILE__, ['KNX_Menu_Tool_Bootstrap', 'on_activation']);
register_deactivation_hook(__FILE__, ['KNX_Menu_Tool_Bootstrap', 'on_deactivation']);