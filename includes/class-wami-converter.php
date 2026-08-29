<?php

if (!defined('ABSPATH')) {
    exit;
}

class WAMI_Converter {

    protected $uploads_basedir;
    protected $uploads_baseurl;

    protected $support_webp = false;
    protected $support_avif = false;

    protected $settings;

    protected $new_conversions = 0;

    /**
     * Returns the number of newly created modern files during the current PHP request.
     * Tools (Regenerate Modern Formats) uses it to aggregate per-batch progress.
     */
    public function get_created_counter() {
        return (int) $this->new_conversions;
    }

    // Safety limits (out of the box)
    protected $time_start = 0.0;
    protected $time_budget_seconds = 1.2; // stop conversions on this request after ~1.2s

    // Automatically skip creating modern formats for very small images
    const SKIP_BELOW_WIDTH_PX = 160;
    const SKIP_BELOW_FILESIZE_BYTES = 12288; // 12 KB

    public function __construct(WAMI_Settings $settings) {
        $uploads = wp_upload_dir();
        $this->uploads_basedir = rtrim($uploads['basedir'], DIRECTORY_SEPARATOR);
        $this->uploads_baseurl = rtrim($uploads['baseurl'], '/');
        $this->settings        = $settings;

        $this->time_budget_seconds = (float) apply_filters('wami_time_budget_seconds', $this->time_budget_seconds);

        $this->time_start = function_exists('microtime') ? (float) microtime(true) : 0.0;
        $this->detect_support();
    }

    protected function detect_support() {
        // Imagick is the most capable path, but its constructor/queryFormats can throw
        // exceptions or even fatal errors depending on the server build. Never let it
        // break page rendering (including wp-admin).
        if (class_exists('Imagick')) {
            try {
                $i = new Imagick();
                $formats = $i->queryFormats();

                if (is_array($formats)) {
                    if (in_array('WEBP', $formats, true)) {
                        $this->support_webp = true;
                    }
                    if (in_array('AVIF', $formats, true)) {
                        $this->support_avif = true;
                    }
                }
            } catch (Throwable $e) {
                // ignore – treat as unsupported
            }
        }

        if (!$this->support_webp && function_exists('imagewebp')) {
            $this->support_webp = true;
        }
    }

    public function supports_webp() {
        return $this->support_webp;
    }

    public function set_time_budget_seconds($seconds) {
        $seconds = (float) $seconds;
        if ($seconds < 0.05) { $seconds = 0.05; }
        $this->time_budget_seconds = $seconds;
    }

    public function supports_avif() {
        return $this->support_avif;
    }

    public function is_convertible_url($url) {
        if (empty($url)) {
            return false;
        }

        $parsed = wp_parse_url($url);
        if (empty($parsed['path'])) {
            return false;
        }

        if (strpos($url, $this->uploads_baseurl) !== 0) {
            return false;
        }

        $ext = strtolower(pathinfo($parsed['path'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('jpg', 'jpeg', 'png'), true)) {
            return false;
        }

        return true;
    }

    
    protected function can_convert_more() {
        // Hard cap to avoid runaway loops even if microtime isn't available
        if ($this->new_conversions >= 50) {
            return false;
        }
        if ($this->time_start > 0) {
            $elapsed = (float) microtime(true) - $this->time_start;
            if ($elapsed >= $this->time_budget_seconds) {
                return false;
            }
        }
        return true;
    }

    protected function should_skip_tiny($path) {
        $w = 0;
        $bytes = 0;

        if (is_string($path) && file_exists($path)) {
            $bytes = (int) @filesize($path);
            $size = @getimagesize($path);
            if (is_array($size) && !empty($size[0])) {
                $w = (int) $size[0];
            }
        }

        // Skip if either width OR filesize is tiny (keeps thumbnail explosion down)
        if ($w > 0 && $w < self::SKIP_BELOW_WIDTH_PX) {
            return true;
        }
        if ($bytes > 0 && $bytes < self::SKIP_BELOW_FILESIZE_BYTES) {
            return true;
        }
        return false;
    }

public function ensure_converted_for_url($url, $only_format = null) {
        $result = array(
            'webp' => null,
            'avif' => null,
        );

        if (!$this->is_convertible_url($url)) {
            return $result;
        }

        $path = $this->url_to_path($url);
        if (!$path || !file_exists($path)) {
            return $result;
        }

        $info = pathinfo($path);
        $basePath = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'];
        $baseUrl  = trailingslashit(dirname($url)) . $info['filename'];

        
        if ($only_format !== null) {
            $only_format = strtolower((string) $only_format);
            if ($only_format !== 'webp' && $only_format !== 'avif') {
                $only_format = null;
            }
        }
$skip_tiny = $this->should_skip_tiny($path);

        if ($this->supports_webp() && ($only_format === null || $only_format === 'webp')) {
            $webpPath = $basePath . '.webp';
            $webpUrl  = $baseUrl . '.webp';

            if (file_exists($webpPath)) {
                $result['webp'] = $webpUrl;
            } elseif (!$skip_tiny && $this->can_convert_more()) {
                $this->convert_to_webp($path, $webpPath);
                if (file_exists($webpPath)) {
                    $result['webp'] = $webpUrl;
                    $this->new_conversions++;
                }
            }
        }

        if ($this->supports_avif() && ($only_format === null || $only_format === 'avif')) {
            $avifPath = $basePath . '.avif';
            $avifUrl  = $baseUrl . '.avif';

            if (file_exists($avifPath)) {
                $result['avif'] = $avifUrl;
            } elseif (!$skip_tiny && $this->can_convert_more()) {
                $this->convert_to_avif($path, $avifPath);
                if (file_exists($avifPath)) {
                    $result['avif'] = $avifUrl;
                    $this->new_conversions++;
                }
            }
        }

        return $result;
    }

    protected function convert_to_webp($sourcePath, $targetPath) {
        if (class_exists('Imagick') && $this->support_webp) {
            try {
                $image = new Imagick($sourcePath);
                $image->setImageFormat('webp');
                $quality = (int)$this->settings->get('quality_webp', 82);
                $image->setImageCompressionQuality($quality);
                $image->writeImage($targetPath);
                $image->clear();
                $image->destroy();
                return;
            } catch (Exception $e) {
                // fall back to GD
            }
        }

        if (!function_exists('imagewebp')) {
            return;
        }

        $info = getimagesize($sourcePath);
        if (!$info) {
            return;
        }

        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($sourcePath);
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
                break;
            default:
                return;
        }

        $quality = (int)$this->settings->get('quality_webp', 82);
        imagewebp($image, $targetPath, $quality);
        imagedestroy($image);
    }

    protected function convert_to_avif($sourcePath, $targetPath) {
        if (!class_exists('Imagick') || !$this->support_avif) {
            return;
        }

        try {
            $image = new Imagick($sourcePath);
            $image->setImageFormat('avif');
            $quality = (int)$this->settings->get('quality_avif', 55);
            $image->setImageCompressionQuality($quality);
            $image->writeImage($targetPath);
            $image->clear();
            $image->destroy();
        } catch (Exception $e) {
            // ignore
        }
    }

    public function url_to_path($url) {
        $parsed = wp_parse_url($url);
        if (empty($parsed['path'])) {
            return null;
        }

        $relative = str_replace($this->uploads_baseurl, '', $url);
        $relative = ltrim($relative, '/');

        return $this->uploads_basedir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
