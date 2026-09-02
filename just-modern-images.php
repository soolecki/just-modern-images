<?php
/**
 * Plugin Name: Just Modern Images
 * Plugin URI: https://github.com/soolecki/just-modern-images
 * Description: Generates smaller WebP and AVIF companions while keeping every original image intact.
 * Version: 0.11.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Sebastian Sołecki
 * Author URI: https://clu.pl
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: just-modern-images
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JMI_VERSION', '0.11.0' );
define( 'JMI_PLUGIN_FILE', __FILE__ );
define( 'JMI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once JMI_PLUGIN_DIR . 'includes/class-jmi-quality-profiles.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-error-trap.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-capabilities.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-diagnostics.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-manifest.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-media-status.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-source-inventory.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-converter.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-queue.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-renderer.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-settings.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-media-admin.php';
require_once JMI_PLUGIN_DIR . 'includes/class-jmi-plugin.php';

/**
 * Return the initialized plugin.
 *
 * @return JMI_Plugin
 */
function jmi_plugin() {
	return JMI_Plugin::instance();
}

register_activation_hook( __FILE__, array( 'JMI_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JMI_Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', 'jmi_plugin' );
