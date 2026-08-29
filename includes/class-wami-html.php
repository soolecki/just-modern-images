<?php

if (!defined('ABSPATH')) {
    exit;
}

class WAMI_Html {

    protected $converter;
    protected $settings;

    /**
     * Output buffering is only used for high-priority images that are printed
     * directly in templates (i.e. outside the_content / wp_get_attachment_image).
     */
    protected $buffering_started = false;

    public function __construct(WAMI_Converter $converter, WAMI_Settings $settings) {
        $this->converter = $converter;
        $this->settings  = $settings;
    }

    public function register_frontend_hooks() {
        add_filter('the_content', array($this, 'filter_content'), 20);
        add_filter('post_thumbnail_html', array($this, 'filter_image_html'), 20, 5);
        add_filter('wp_get_attachment_image', array($this, 'filter_image_html'), 20, 5);

        // If enabled, also process high-priority images printed directly in templates.
        if ($this->settings->get_bool('process_high_priority')) {
            // Start buffering as early as possible on the frontend so template-printed hero images are included.
            add_action('wp_loaded', array($this, 'maybe_start_output_buffer'), 0);
            add_action('template_redirect', array($this, 'maybe_start_output_buffer'), 0);
        }
    }

    public function maybe_start_output_buffer() {
        if ($this->buffering_started) {
            return;
        }

        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $this->buffering_started = true;
        ob_start(array($this, 'output_buffer_callback'));
    }

    public function output_buffer_callback($html) {
        // Output-buffer pass is used only for template HTML (outside the_content / attachment helpers).
        // By default we keep it OFF to avoid processing entire pages. When the user enables
        // "Process high-priority images", we process the buffered HTML so hero images can be upgraded.

        if (empty($html) || stripos($html, '<img') === false) {
            return $html;
        }

        if (!$this->settings->get_bool('process_high_priority')) {
            return $html;
        }

        return $this->process_html_fragment($html);
    }

    public function filter_content($content) {
        // JMI fail-open guard (filter_content)
        try {

        if (empty($content) || stripos($content, '<img') === false) {
            return $content;
        }

        return $this->process_html_fragment($content);
        } catch (\Throwable $t) {
            return $content;
        }

    }

    public function filter_image_html($html, $post_id = null, $post_thumbnail_id = null, $size = null, $attr = null) {
        if (empty($html) || stripos($html, '<img') === false) {
            return $html;
        }

        return $this->process_html_fragment($html);
    }

    protected function get_exclude_classes() {
        // Hard-coded escape hatch: always skip any <img> with this class.
        $hard = array('jmi-skip');

        $val = $this->settings->get('exclude_classes', '');
        if (!is_string($val) || trim($val) === '') {
            return $hard;
        }

        $parts = explode(',', $val);

        // Normalize exclude tokens:
        // - allow either "no-modern" or ".no-modern"
        // - trim whitespace/newlines
        // - compare case-insensitively
        $normalize = static function($c) {
            $c = trim((string)$c);
            $c = ltrim($c, '.');
            $c = strtolower($c);
            return $c;
        };

        $out = array();

        foreach ($hard as $h) {
            $h = $normalize($h);
            if ($h !== '' && !in_array($h, $out, true)) {
                $out[] = $h;
            }
        }

        foreach ($parts as $p) {
            $p = $normalize($p);
            if ($p !== '' && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    protected function process_html_fragment($html) {
        // Do NOT bail out just because a marker exists somewhere in the fragment.
        // Output-buffer mode may receive the entire document; returning early would
        // prevent processing later high-priority images.

        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');

        // Prefix XML encoding so libxml reliably treats input as UTF-8.
        // This avoids edge cases where non-ASCII characters can lead to malformed output
        // (including apparent truncation) on some environments.
        $wrapped = '<?xml encoding="UTF-8" ?>' . '<!DOCTYPE html><html><body>' . $html . '</body></html>';

        if (!$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            return $html;
        }

        $exclude_classes = $this->get_exclude_classes();
        $changed = false;

        // Inserts the debug marker right after the element we actually changed.
        // This keeps the marker precise and avoids adding a single marker at the end.
        $insert_marker_after = function(DOMNode $node) use ($dom): void {
            if (!$node->parentNode) {
                return;
            }
            $marker = $dom->createComment(' jmi-processed ');
            // Insert after the node (preserve order)
            if ($node->nextSibling) {
                $node->parentNode->insertBefore($marker, $node->nextSibling);
            } else {
                $node->parentNode->appendChild($marker);
            }
        };
        // Augment existing <picture> elements with AVIF/WebP sources if missing
        $pictures = $dom->getElementsByTagName('picture');
        foreach ($pictures as $picture) {
            // Find first <img> inside this <picture>
            $imgInPicture = null;
            $imgsInPicture = $picture->getElementsByTagName('img');
            if ($imgsInPicture->length > 0) {
                $imgInPicture = $imgsInPicture->item(0);
            }
            if (!$imgInPicture) {
                continue;
            }

            // Respect excluded classes for the inner <img>
            if (!empty($exclude_classes)) {
                $classAttr = $imgInPicture->getAttribute('class');
                if ($classAttr !== '') {
                    $classes = preg_split('/\s+/', $classAttr);
                    if (is_array($classes)) {
                        $skip = false;
                        foreach ($classes as $cls) {
                            $cls = strtolower(ltrim(trim($cls), '.'));
                            if ($cls !== '' && in_array($cls, $exclude_classes, true)) {
                                $skip = true;
                                break;
                            }
                        }
                        if ($skip) {
                            continue;
                        }
                    }
                }
            }

            // Check which formats are already present
            $hasAvif = false;
            $hasWebp = false;
            $sources = $picture->getElementsByTagName('source');
            foreach ($sources as $s) {
                $type = $s->getAttribute('type');
                if ($type === 'image/avif') {
                    $hasAvif = true;
                } elseif ($type === 'image/webp') {
                    $hasWebp = true;
                }
            }

            if ($hasAvif && $hasWebp) {
                continue;
            }

            // Resolve effective src similarly to <img> handling (simplified)
            $src = $imgInPicture->getAttribute('src');
            if ($src === '' || strpos($src, 'data:') === 0) {
                $candidates = array(
                    $imgInPicture->getAttribute('data-lazy-src'),
                    $imgInPicture->getAttribute('data-src'),
                    $imgInPicture->getAttribute('data-src-large'),
                    $imgInPicture->getAttribute('data-src-medium'),
                    $imgInPicture->getAttribute('data-src-small'),
                    $imgInPicture->getAttribute('data-litespeed-original'),
                    $imgInPicture->getAttribute('data-original'),
                    $imgInPicture->getAttribute('data-o_src'),
                    $imgInPicture->getAttribute('data-thumb'),
                    $imgInPicture->getAttribute('data-large_image'),
                    $imgInPicture->getAttribute('data-src-main'),
                );
                foreach ($candidates as $cand) {
                    if ($cand !== '') {
                        $src = $cand;
                        break;
                    }
                }
            }

            // High-priority images are skipped unless the user explicitly enabled processing them.
            if ($this->is_high_priority_image($imgInPicture) && !$this->settings->get_bool('process_high_priority')) {
                continue;
            }

            if ($src === '' || $this->is_unhandled_src($src) || !$this->converter->is_convertible_url($src)) {
                continue;
            }

            // Build modern srcsets if the inner <img> has srcset. This enables "self-healing" for existing <picture> elements.
            $srcForConversion = $src;
            $srcsetAttr = trim((string) $imgInPicture->getAttribute('srcset'));
            // Pick a canonical convertible URL for conversion.
            if (!$this->converter->is_convertible_url($srcForConversion)) {
                $srcForConversion = '';
                if ($srcsetAttr !== '') {
                    $candidatesTmp = $this->parse_srcset_candidates($srcsetAttr);
                    foreach ($candidatesTmp as $cTmp) {
                        if (!empty($cTmp['url']) && $this->converter->is_convertible_url($cTmp['url'])) {
                            $srcForConversion = $cTmp['url'];
                            break;
                        }
                    }
                }
                if ($srcForConversion === '') {
                    continue;
                }
            }
            $srcsetAttr = $imgInPicture->getAttribute('srcset');
            $avifSrcset = '';
            $webpSrcset = '';
            if ($srcsetAttr !== '') {
                $candidates = $this->parse_srcset_candidates($srcsetAttr);
                if (!empty($candidates)) {
                    // Prefer the largest candidate as a single-url fallback.
                    $maxW = 0;
                    foreach ($candidates as $c) {
                        $w = 0;
                        if (!empty($c['descriptor']) && substr($c['descriptor'], -1) === 'w') {
                            $w = (int)trim(substr($c['descriptor'], 0, -1));
                        }
                        if ($w > $maxW) {
                            $maxW = $w;
                            $srcForConversion = $c['url'];
                        }
                    }

                    // Attempt to generate full modern srcsets (time-budgeted in converter).
                    $avifSrcset = $this->build_srcset_for_format($candidates, 'avif', 160);
                    $webpSrcset = $this->build_srcset_for_format($candidates, 'webp', 160);
                }
            }

            // Always attempt a single conversion as a fallback (e.g., when no srcset or budget is exhausted).
            $baseFormats = $this->converter->ensure_converted_for_url($srcForConversion);
            if (empty($avifSrcset) && empty($webpSrcset) && empty($baseFormats['avif']) && empty($baseFormats['webp'])) {
                continue;
            }

            // Insert missing sources into <picture>.
            // Preferred order: AVIF first, then WebP, then <img> fallback.
            $picture_changed = false;

            // Find an insertion anchor before the <img> fallback (or the end if no <img>).
            $imgFallbackNode = null;
            foreach ($picture->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->tagName) === 'img') {
                    $imgFallbackNode = $child;
                    break;
                }
            }
            $insert_before = $imgFallbackNode ?: null;

            // Helper to find the last AVIF <source> within this <picture>.
            $lastAvifSource = null;
            foreach ($picture->childNodes as $child) {
                if (!($child instanceof DOMElement) || strtolower($child->tagName) !== 'source') {
                    continue;
                }
                $type = strtolower(trim($child->getAttribute('type')));
                if ($type === 'image/avif') {
                    $lastAvifSource = $child;
                }
            }

            if (!$hasAvif && (!empty($avifSrcset) || !empty($baseFormats['avif']))) {
                $sourceAvif = $dom->createElement('source');
                $sourceAvif->setAttribute('srcset', $avifSrcset !== '' ? $avifSrcset : esc_url($baseFormats['avif']));
                $sourceAvif->setAttribute('type', 'image/avif');
                if ($insert_before) {
                    $picture->insertBefore($sourceAvif, $insert_before);
                } else {
                    $picture->appendChild($sourceAvif);
                }
                $changed = true;
                $picture_changed = true;
                $lastAvifSource = $sourceAvif;
            }

            if (!$hasWebp && (!empty($webpSrcset) || !empty($baseFormats['webp']))) {
                $sourceWebp = $dom->createElement('source');
                $sourceWebp->setAttribute('srcset', $webpSrcset !== '' ? $webpSrcset : esc_url($baseFormats['webp']));
                $sourceWebp->setAttribute('type', 'image/webp');

                // If we have an AVIF source (existing or inserted), put WebP after the last AVIF source.
                if ($lastAvifSource && $lastAvifSource->parentNode === $picture) {
                    $next = $lastAvifSource->nextSibling;
                    if ($next) {
                        $picture->insertBefore($sourceWebp, $next);
                    } else {
                        $picture->appendChild($sourceWebp);
                    }
                } elseif ($insert_before) {
                    $picture->insertBefore($sourceWebp, $insert_before);
                } else {
                    $picture->appendChild($sourceWebp);
                }

                $changed = true;
                $picture_changed = true;
            }

            if ($picture_changed) {
                $insert_marker_after($picture);
            }
        }
        $min_width = 160; // auto: skip tiny images below this width

        $imgs = $dom->getElementsByTagName('img');

        for ($i = $imgs->length - 1; $i >= 0; $i--) {
            $img = $imgs->item($i);

            if (!$img instanceof DOMElement) {
                continue;
            }

            // Never process <img> that is already inside a <picture>
            if ($this->is_inside_picture($img)) {
                continue;
            }

            // Skip high-priority images (hero/LCP) unless explicitly enabled.
            if ($this->is_high_priority_image($img) && !$this->settings->get_bool('process_high_priority')) {
                continue;
            }

            // Skip tiny images (based on rendered dimensions)
            if ($this->is_tiny_image($img, 160)) {
                continue;
            }


            if (!empty($exclude_classes)) {
                $classAttr = $img->getAttribute('class');
                if ($classAttr !== '') {
                    $classes = preg_split('/\s+/', $classAttr);
                    if (is_array($classes)) {
                        foreach ($classes as $cls) {
                            $cls = strtolower(ltrim(trim($cls), '.'));
                            if ($cls !== '' && in_array($cls, $exclude_classes, true)) {
                                continue 2; // skip this <img>
                            }
                        }
                    }
                }
            }

            $src = $img->getAttribute('src');
            $srcsetAttr = $img->getAttribute('srcset');

            // Resolve effective src/srcset with support for various lazyload attributes
            $dataSrc       = $img->getAttribute('data-src');
            $dataSrcset    = $img->getAttribute('data-srcset');
            $dataLazySrc   = $img->getAttribute('data-lazy-src');
            $dataLazySrcset= $img->getAttribute('data-lazy-srcset');
            $dataSrcLarge  = $img->getAttribute('data-src-large');
            $dataSrcMedium = $img->getAttribute('data-src-medium');
            $dataSrcSmall  = $img->getAttribute('data-src-small');
            $dataLsOrig    = $img->getAttribute('data-litespeed-original');
            $dataOriginal  = $img->getAttribute('data-original');
            $dataOSrc      = $img->getAttribute('data-o_src');
            $dataThumb     = $img->getAttribute('data-thumb');
            $dataLargeImg  = $img->getAttribute('data-large_image');
            $dataSrcMain   = $img->getAttribute('data-src-main');

            // src resolution: if src is empty or a data: placeholder, pick first non-empty real URL
            if ($src === '' || strpos($src, 'data:') === 0) {
                $candidatesSrc = array(
                    $dataLazySrc,
                    $dataSrc,
                    $dataSrcLarge,
                    $dataSrcMedium,
                    $dataSrcSmall,
                    $dataLsOrig,
                    $dataOriginal,
                    $dataOSrc,
                    $dataThumb,
                    $dataLargeImg,
                    $dataSrcMain,
                );
                foreach ($candidatesSrc as $cand) {
                    if ($cand !== '') {
                        $src = $cand;
                        break;
                    }
                }
            }

            // srcset resolution: prefer lazy variants if present
            if ($srcsetAttr === '') {
                if ($dataLazySrcset !== '') {
                    $srcsetAttr = $dataLazySrcset;
                } elseif ($dataSrcset !== '') {
                    $srcsetAttr = $dataSrcset;
                }
            }

            if ($src === '' && $srcsetAttr === '') {
                continue;
            }

            $hasConvertible = $this->converter->is_convertible_url($src);
            $candidates = array();

            if ($srcsetAttr !== '') {
                $candidates = $this->parse_srcset($srcsetAttr);
                foreach ($candidates as $c) {
                    if ($this->converter->is_convertible_url($c['url'])) {
                        $hasConvertible = true;
                        break;
                    }
                }
            }

            if (!$hasConvertible) {
                continue;
            }

            $baseFormats = $this->converter->ensure_converted_for_url($src);

            $avifSrcset = '';
            $webpSrcset = '';

            if (!empty($candidates)) {
                $avifSrcset = $this->build_srcset_for_format($candidates, 'avif', $min_width);
                $webpSrcset = $this->build_srcset_for_format($candidates, 'webp', $min_width);
            }

            if ($avifSrcset === '' && !empty($baseFormats['avif'])) {
                $avifSrcset = esc_url($baseFormats['avif']);
            }
            if ($webpSrcset === '' && !empty($baseFormats['webp'])) {
                $webpSrcset = esc_url($baseFormats['webp']);
            }

            if ($avifSrcset === '' && $webpSrcset === '') {
                continue;
            }

            $picture = $dom->createElement('picture');

            if ($avifSrcset !== '') {
                $sourceAvif = $dom->createElement('source');
                $sourceAvif->setAttribute('srcset', $avifSrcset);
                $sourceAvif->setAttribute('type', 'image/avif');
                $picture->appendChild($sourceAvif);
            }

            if ($webpSrcset !== '') {
                $sourceWebp = $dom->createElement('source');
                $sourceWebp->setAttribute('srcset', $webpSrcset);
                $sourceWebp->setAttribute('type', 'image/webp');
                $picture->appendChild($sourceWebp);
            }

            $clonedImg = $img->cloneNode(true);
            $picture->appendChild($clonedImg);

            $img->parentNode->replaceChild($picture, $img);
            $insert_marker_after($picture);
            $changed = true;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $newHtml = '';
        foreach ($body->childNodes as $child) {
            $newHtml .= $dom->saveHTML($child);
        }

        libxml_clear_errors();

        // If nothing changed or libxml produced an empty output, fall back safely.
        if (!$changed || $newHtml === null || $newHtml === '') {
            return $html;
        }

        // DOMDocument may serialize void elements like <source> with closing tags.
        // Normalize to HTML5 void syntax to avoid odd markup.
        $newHtml = preg_replace('~</source\s*>~i', '', $newHtml);

        return $newHtml;
    }

    protected function parse_srcset($srcset) {
        $result = array();
        $parts = explode(',', $srcset);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $spacePos = strrpos($part, ' ');
            if ($spacePos === false) {
                $url = $part;
                $descriptor = '';
            } else {
                $url = substr($part, 0, $spacePos);
                $descriptor = trim(substr($part, $spacePos + 1));
            }

            $result[] = array(
                'url'        => $url,
                'descriptor' => $descriptor,
            );
        }

        return $result;
    }

    protected function build_srcset_for_format(array $candidates, $format, $min_width_px) {
        $out = array();

        foreach ($candidates as $c) {
            $url = $c['url'];
            if (!$this->converter->is_convertible_url($url)) {
                continue;
            }

            $desc = $c['descriptor'];
            if ($desc !== '' && substr($desc, -1) === 'w') {
                $w = (int)trim(substr($desc, 0, -1));
                if ($w > 0 && $w < $min_width_px) {
                    continue;
                }
            }

            $formats = $this->converter->ensure_converted_for_url($url, $format);
            $fmtUrl = isset($formats[$format]) ? $formats[$format] : null;
            if (!$fmtUrl) {
                continue;
            }

            $part = esc_url($fmtUrl);
            if ($desc !== '') {
                $part .= ' ' . $desc;
            }
            $out[] = $part;
        }

        if (empty($out)) {
            return '';
        }

        return implode(', ', $out);
    }

    protected function is_tiny_image(DOMElement $img, $minPx = 160) {
        $w = (int) $img->getAttribute('width');
        $h = (int) $img->getAttribute('height');

        if ($w > 0 && $w < $minPx) {
            return true;
        }
        if ($h > 0 && $h < $minPx) {
            return true;
        }

        return false;
    }

    protected function is_high_priority_image(DOMElement $img) {
        $loading = strtolower(trim($img->getAttribute('loading')));
        $fetch   = strtolower(trim($img->getAttribute('fetchpriority')));
        return ($loading === 'eager' && $fetch === 'high');
    }

    protected function is_unhandled_src($src) {
        if (!is_string($src) || $src === '') {
            return true;
        }
        $src = trim($src);
        return (strpos($src, 'data:') === 0 || strpos($src, 'blob:') === 0);
    }

    protected function is_inside_picture(DOMElement $img) {
        $p = $img->parentNode;
        while ($p && $p instanceof DOMElement) {
            if (strtolower($p->tagName) === 'picture') {
                return true;
            }
            $p = $p->parentNode;
        }
        return false;
    }

}