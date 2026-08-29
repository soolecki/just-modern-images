<?php
/**
 * Plugin Name: Just Modern Images
 * Description: Automatically generates AVIF/WebP and serves them via <picture> without changing your content.
 * Version: 0.9.2.007
 * Author: clu
 * Author URI: https://clu.pl
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WAMI_PLUGIN_VERSION', '0.9.2.007');
define('WAMI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WAMI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WAMI_PLUGIN_DIR . 'includes/class-wami-settings.php';
require_once WAMI_PLUGIN_DIR . 'includes/class-wami-converter.php';
require_once WAMI_PLUGIN_DIR . 'includes/class-wami-html.php';
require_once WAMI_PLUGIN_DIR . 'includes/class-wami-plugin.php';
require_once WAMI_PLUGIN_DIR . 'includes/class-wami-tools.php';

function wami_plugin() {
    return WAMI_Plugin::instance();
}

add_action('plugins_loaded', 'wami_plugin');
