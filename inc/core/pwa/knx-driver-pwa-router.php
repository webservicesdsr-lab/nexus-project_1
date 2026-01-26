<?php
/**
 * Kingdom Nexus — Driver PWA Router (Manifest + Optional SW) (v1.0)
 *
 * Serves:
 * - /knx-driver-manifest.json
 * - /knx-driver-sw.js (optional no-op SW)
 *
 * Notes:
 * - No wp_footer, no enqueues.
 * - No rewrite rules required (no flush needed).
 * - Fail-closed: does nothing unless the exact path is requested.
 *
 * @package KingdomNexus
 * @since 2.8.6
 */

if (!defined('ABSPATH')) exit;

add_action('init', function () {

    // Avoid touching admin or CLI contexts.
    if (is_admin()) return;
    if (defined('WP_CLI') && WP_CLI) return;

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (!$uri) return;

    $req_path = (string) parse_url($uri, PHP_URL_PATH);

    // Support WP installed in a subdirectory by comparing against home_url() paths.
    $manifest_path = (string) parse_url(home_url('/knx-driver-manifest.json'), PHP_URL_PATH);
    $sw_path       = (string) parse_url(home_url('/knx-driver-sw.js'), PHP_URL_PATH);

    // ─────────────────────────────────────────────────────────────
    // /knx-driver-manifest.json
    // ─────────────────────────────────────────────────────────────
    if ($req_path === $manifest_path) {

        $site_name = (string) get_bloginfo('name');
        $name = $site_name ? ($site_name . ' — Driver') : 'Kingdom Nexus — Driver';

        $start_url = home_url('/driver-dashboard/');
        $scope     = home_url('/');

        $manifest = [
            'name' => $name,
            'short_name' => 'Driver',
            'start_url' => $start_url,
            'scope' => $scope,
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#0B793A',
        ];

        // Add icons ONLY if they exist (avoid broken URLs / console noise).
        $icons = [];

        if (defined('KNX_PATH') && defined('KNX_URL') && KNX_PATH && KNX_URL) {
            $icon_192_fs = KNX_PATH . 'assets/pwa/driver-192.png';
            $icon_512_fs = KNX_PATH . 'assets/pwa/driver-512.png';

            if (file_exists($icon_192_fs)) {
                $icons[] = [
                    'src' => KNX_URL . 'assets/pwa/driver-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ];
            }

            if (file_exists($icon_512_fs)) {
                $icons[] = [
                    'src' => KNX_URL . 'assets/pwa/driver-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ];
            }
        }

        if (!empty($icons)) {
            $manifest['icons'] = $icons;
        }

        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo function_exists('wp_json_encode') ? wp_json_encode($manifest) : json_encode($manifest);
        exit;
    }

    // ─────────────────────────────────────────────────────────────
    // /knx-driver-sw.js (OPTIONAL)
    // ─────────────────────────────────────────────────────────────
    if ($req_path === $sw_path) {

        // Minimal "no-op" SW. Safe to serve even if you later decide not to register it.
        $js = <<<JS
/* Kingdom Nexus — Driver SW (no-op) */
self.addEventListener('install', (event) => {
  self.skipWaiting();
});
self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});
JS;

        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        echo $js;
        exit;
    }

}, 1);
