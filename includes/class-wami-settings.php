<?php

if (!defined('ABSPATH')) {
    exit;
}

class WAMI_Settings {

    const OPTION_KEY = 'wami_settings';

    protected $settings = array();

    public function __construct() {
        $this->settings = $this->get_settings();
        add_action('admin_init', array($this, 'maybe_handle_post'));
        add_action('admin_menu', array($this, 'register_settings_page'));
    }

    public function get($key, $default = null) {
        // Some settings are frequently changed in the admin and should always reflect the latest saved value.
        // Read them live from the DB to avoid any stale in-memory cache in edge cases.
        if ($key === 'exclude_classes' || $key === 'process_high_priority') {
            $saved = get_option(self::OPTION_KEY, array());
            if (is_array($saved) && array_key_exists($key, $saved)) {
                return $saved[$key];
            }
        }

        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }

    /**
     * Convenience boolean getter.
     * Treats any non-empty value as true.
     */
    public function get_bool($key, $default = false) {
        $fallback = $default ? 1 : 0;
        return !empty($this->get($key, $fallback));
    }

    public function get_settings() {
        $defaults = array(
            'quality_preset'       => 'balanced',
            'quality_webp'         => 82,
            'quality_avif'         => 55,
            'generate_on_upload'   => 1,
            'exclude_classes'      => '',
            'process_high_priority' => 0,
        );

        $saved = get_option(self::OPTION_KEY, array());

        // Backward compatibility: map older preset names to the new simplified ones.
        if (isset($saved['quality_preset'])) {
            $old = (string)$saved['quality_preset'];
            $map = array(
                'smallest'       => 'small',
                'high-compression' => 'small',
                'balanced'       => 'balanced',
                'high-quality'   => 'high',
                'near-lossless'  => 'max',
            );
            if (isset($map[$old])) {
                $saved['quality_preset'] = $map[$old];
            }
        }

        if (!is_array($saved)) {
            $saved = array();
        }

        $settings = array_merge($defaults, $saved);

        // In case older installs have only numeric quality without preset
        if (empty($settings['quality_preset'])) {
            $settings['quality_preset'] = 'balanced';
        }

        return $settings;
    }

    
    protected function get_quality_map() {
        return array(
            // Values are chosen to keep file sizes reasonable while avoiding visible artifacts in typical photography.
            // AVIF generally achieves similar visual quality at lower numeric values than WebP.
            'small' => array('webp' => 72, 'avif' => 40),
            'balanced' => array('webp' => 82, 'avif' => 55),
            'high' => array('webp' => 90, 'avif' => 65),
            'max' => array('webp' => 95, 'avif' => 80),
        );
    }


    
    public function save_settings(array $data) {
        $clean = array();

        // Quality preset -> numeric values
        $allowed_presets = array('small', 'balanced', 'high', 'max');
        $preset = isset($data['quality_preset']) ? $data['quality_preset'] : 'balanced';
        if (!in_array($preset, $allowed_presets, true)) {
            $preset = 'balanced';
        }

        $quality_map = $this->get_quality_map();
        $qp = isset($quality_map[$preset]) ? $quality_map[$preset] : $quality_map['balanced'];

        $clean['quality_preset'] = $preset;
        $clean['quality_webp']   = (int)$qp['webp'];
        $clean['quality_avif']   = (int)$qp['avif'];

        $clean['generate_on_upload'] = empty($data['generate_on_upload']) ? 0 : 1;

        $clean['exclude_classes'] = isset($data['exclude_classes']) ? sanitize_text_field($data['exclude_classes']) : '';

        $clean['process_high_priority'] = empty($data['process_high_priority']) ? 0 : 1;

        $this->settings = $clean;
        update_option(self::OPTION_KEY, $clean);
    }


    public function register_settings_page() {
        add_options_page(
            __('Just Modern Images', 'wami'),
            __('Just Modern Images', 'wami'),
            'manage_options',
            'wami-settings',
            array($this, 'render_settings_page')
        );
    }

    public function maybe_handle_post() {
        if (!is_admin()) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!isset($_POST['wami_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('wami_save_settings', 'wami_nonce');

        $data = isset($_POST['wami_settings']) ? (array)$_POST['wami_settings'] : array();
        $this->save_settings($data);

        wp_safe_redirect( add_query_arg( 'wami_saved', '1', admin_url( 'options-general.php?page=wami-settings' ) ) );
        exit;
}


    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        

        if (isset($_GET['wami_saved']) && $_GET['wami_saved'] === '1') {
            add_settings_error('wami_messages', 'wami_message', __('Settings saved.', 'wami'), 'updated');
        }

        settings_errors('wami_messages');
$settings = $this->settings;

        $converter = null;
        if (class_exists('WAMI_Converter')) {
            $converter = WAMI_Plugin::instance()->converter;
        }

        $support_webp = $converter ? $converter->supports_webp() : false;
        $support_avif = $converter ? $converter->supports_avif() : false;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Just Modern Images', 'wami'); ?></h1>

            <form method="post">
                <?php wp_nonce_field('wami_save_settings', 'wami_nonce'); ?>

                <h2><?php esc_html_e('Settings', 'wami'); ?></h2>
                <table class="form-table" role="presentation">
                    
                    
                    
                    <tr>
                        <th scope="row">
                            <label for="wami_quality_preset"><?php esc_html_e('Quality preset', 'wami'); ?></label>
                        </th>
                        <td>
                            <select name="wami_settings[quality_preset]" id="wami_quality_preset">
                                <?php
                                $presets = array(
                                    'small'    => __('Small', 'wami'),
                                    'balanced' => __('Balanced', 'wami'),
                                    'high'     => __('High', 'wami'),
                                    'max'      => __('Max', 'wami'),
                                );
                                foreach ($presets as $key => $label) :
                                    $selected = selected($settings['quality_preset'], $key, false);
                                    echo '<option value="' . esc_attr($key) . '" ' . $selected . '>' . esc_html($label) . '</option>';
                                endforeach;
                                ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Preset controls both WebP and AVIF quality. WebP + AVIF are always generated when supported.', 'wami'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wami_generate_on_upload"><?php esc_html_e('Generate on upload', 'wami'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="wami_generate_on_upload"
                                       name="wami_settings[generate_on_upload]"
                                       value="1" <?php checked(!empty($settings['generate_on_upload'])); ?> />
                                <?php esc_html_e('Generate modern formats when media is uploaded (recommended).', 'wami'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Auto protections', 'wami'); ?></th>
                        <td>
                            <p class="description" style="margin-top:0;">
                                <?php esc_html_e('Tiny images are skipped automatically (by width and file size). On-demand generation is also time-budgeted per request to avoid slow pages.', 'wami'); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <details style="margin: 8px 0 0 0;">
                    <summary style="cursor:pointer; font-weight: 600;"><?php esc_html_e('Advanced', 'wami'); ?></summary>

                    <table class="form-table" role="presentation" style="margin-top: 12px;">
                        
                        <tr>
                            <th scope="row">
                                <label for="wami_process_high_priority"><?php esc_html_e('Process high-priority images', 'wami'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           id="wami_process_high_priority"
                                           name="wami_settings[process_high_priority]"
                                           value="1" <?php checked(!empty($settings['process_high_priority'])); ?> />
                                    <?php esc_html_e('Also process images marked as loading="eager" and fetchpriority="high" (hero/LCP).', 'wami'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Default is OFF for safety. Turn ON only if your theme does not already handle modern formats for hero images.', 'wami'); ?>
                                </p>
                            </td>
                        </tr>
<tr>
                            <th scope="row">
                                <label for="wami_exclude_classes"><?php esc_html_e('Exclude classes', 'wami'); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       class="regular-text"
                                       id="wami_exclude_classes"
                                       name="wami_settings[exclude_classes]"
                                       value="<?php echo esc_attr($settings['exclude_classes']); ?>"
                                       placeholder="e.g. no-modern, skip-webp" />
                                <p class="description">
                                    <?php esc_html_e('Comma-separated class names. If an <img> or <picture> has any of these classes, it will not be modified.', 'wami'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </details>

                <p class="submit">
                    <button type="submit" name="wami_settings_submit" class="button button-primary">
                        <?php esc_html_e('Save changes', 'wami'); ?>
                    </button>
                </p>
            </form>

            <hr />

            <h2><?php esc_html_e('Server capabilities', 'wami'); ?></h2>
            <ul>
                <li><?php echo esc_html__('Imagick available:', 'wami') . ' ' . (class_exists('Imagick') ? 'YES' : 'NO'); ?></li>
                <li><?php echo esc_html__('GD imagewebp available:', 'wami') . ' ' . (function_exists('imagewebp') ? 'YES' : 'NO'); ?></li>
                <li><?php echo esc_html__('WebP support detected:', 'wami') . ' ' . ($support_webp ? 'YES' : 'NO'); ?></li>
                <li><?php echo esc_html__('AVIF support detected:', 'wami') . ' ' . ($support_avif ? 'YES' : 'NO'); ?></li>
            </ul>
        </div>
        <?php
    }
}