<?php

if (!defined('ABSPATH')) {
    exit;
}

class WAMI_Plugin {

    protected static $instance;

    public $settings;
    public $converter;
    public $html;
    public $tools;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings  = new WAMI_Settings();
        $this->converter = new WAMI_Converter($this->settings);
        $this->html      = new WAMI_Html($this->converter, $this->settings);
        $this->tools     = new WAMI_Tools($this->converter, $this->settings);

        if (!is_admin()) {
            $this->html->register_frontend_hooks();
        } else {
	        // WAMI_Tools registers its admin hooks in its constructor.
            add_action('admin_notices', array($this, 'maybe_admin_notice_support'));
        }

        add_filter('wp_generate_attachment_metadata', array($this, 'handle_upload_metadata'), 20, 2);
    }

    public function handle_upload_metadata($metadata, $attachment_id) {
        if (!(bool)$this->settings->get('generate_on_upload', 1)) {
            return $metadata;
        }

        $base_url = wp_get_attachment_url($attachment_id);
        if ($base_url) {
            $this->converter->ensure_converted_for_url($base_url);
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes']) && $base_url) {
            $dir_url = trailingslashit(dirname($base_url));
            foreach ($metadata['sizes'] as $size_data) {
                if (!empty($size_data['file'])) {
                    $url = $dir_url . $size_data['file'];
                    $this->converter->ensure_converted_for_url($url);
                }
            }
        }

        return $metadata;
    }

    /**
     * Informational admin notice about server support for modern formats.
     * Must never block plugin usage.
     */
    public function maybe_admin_notice_support() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        // Only show on plugin settings/tools pages to avoid noise.
        $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
        if ($page !== 'wami-settings' && $page !== 'wami-regenerate') {
            return;
        }

        $support_webp = $this->converter->supports_webp();
        $support_avif = $this->converter->supports_avif();

        if (!$support_webp && !$support_avif) {
            echo '<div class="notice notice-warning"><p><strong>Just Modern Images:</strong> This server cannot generate WebP or AVIF (Imagick/GD). The plugin will only rewrite HTML when files already exist.</p></div>';
            return;
        }

        if (!$support_webp) {
            echo '<div class="notice notice-info"><p><strong>Just Modern Images:</strong> WebP generation is unavailable. Only AVIF (if supported) will be generated.</p></div>';
            return;
        }

        if (!$support_avif) {
            echo '<div class="notice notice-info"><p><strong>Just Modern Images:</strong> AVIF generation is unavailable. WebP will be generated.</p></div>';
        }
    }
}
