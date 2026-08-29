<?php

if (!defined('ABSPATH')) { exit; }

/**
 * Admin Tools: bulk regenerate modern formats.
 *
 * 0.9.1: Adds an AJAX-driven loop that can run until 0 items remain,
 * with progress + summary persisted across refreshes.
 */
class WAMI_Tools {

    /**
     * Stored state schema version.
     * Bump when cursoring/state structure changes.
     */
    const STATE_SCHEMA = 2;

    /** @var WAMI_Converter */
    private $converter;

    /** @var string */
    private $option_key = 'wami_regen_state';

    /** @var string */
    private $ajax_action = 'wami_regen_batch';

    /**
     * SQL WHERE fragment used to locate image attachments that are eligible for checking.
     *
     * Why not only post_mime_type?
     * Some setups/plugins can leave post_mime_type empty/incorrect, while the file is still a normal JPG/PNG.
     * We therefore accept either:
     *  - post_mime_type LIKE 'image/%' (excluding svg/webp/avif)
     *  - OR GUID ends with a known raster extension (jpg/jpeg/png/gif)
     */
    private function sql_candidate_where() {
        global $wpdb;

        $raster_ext_regex = "\\.(jpe?g|png|gif)(\\?.*)?$";

        return "post_type = 'attachment'
                AND post_status = 'inherit'
                AND (
                    (post_mime_type LIKE 'image/%' AND post_mime_type NOT IN ('image/webp','image/avif','image/svg+xml'))
                    OR (guid REGEXP '" . $raster_ext_regex . "')
                )
                AND guid NOT LIKE '%\\.webp%'
                AND guid NOT LIKE '%\\.avif%'
                AND guid NOT LIKE '%\\.svg%'";
    }

    public function __construct($converter) {
        $this->converter = $converter;

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_' . $this->ajax_action, [$this, 'ajax_regen_batch']);
        add_action('wp_ajax_wami_regen_reset', [$this, 'ajax_regen_reset']);
        add_action('wp_ajax_wami_regen_start', [$this, 'ajax_regen_start']);
        add_action('wp_ajax_wami_regen_stop', [$this, 'ajax_regen_stop']);
    }

    public function register_admin_menu() {
        add_management_page(
            'Regenerate Modern Formats',
            'Regenerate Modern Formats',
            'manage_options',
            'wami-tools',
            [$this, 'render_tools_page']
        );
    }

    public function enqueue_admin_assets($hook) {
        // Tools page: tools.php?page=wami-tools
        if ($hook !== 'tools_page_wami-tools') {
            return;
        }

        $handle = 'wami-tools';
        wp_register_script($handle, false, [], WAMI_PLUGIN_VERSION, true);

        $state = $this->get_state();
        $payload = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wami_tools'),
            'state'   => $state,
            'defaults' => [
                'batchSize' => 10,
                'batchMin'  => 1,
                'batchMax'  => 25,
            ],
        ];

        wp_add_inline_script($handle, 'window.WAMI_TOOLS = ' . wp_json_encode($payload) . ';', 'before');
        wp_add_inline_script($handle, $this->get_inline_js(), 'after');
        wp_enqueue_script($handle);
    }

    public function render_tools_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }

        $state = $this->get_state();
        $total = (int) ($state['total'] ?? 0);
        $checked = (int) ($state['files_checked'] ?? 0);
        $created = (int) ($state['created'] ?? 0);
        $skipped = (int) ($state['skipped'] ?? 0);
        $errors  = (int) ($state['errors'] ?? 0);
        $after_id = (int) ($state['after_id'] ?? 0);
        $is_running = !empty($state['is_running']);
		$total_images = (int) ($state['total_images'] ?? 0);
		$error_log = (isset($state['error_log']) && is_array($state['error_log'])) ? $state['error_log'] : array();

        $progress = ($total > 0) ? min(100, round(($checked / $total) * 100, 1)) : 0;

        echo '<div class="wrap">';
        echo '<h1>Regenerate Modern Formats</h1>';

        echo '<p>This tool processes attachments in batches and generates modern formats (WebP/AVIF) when missing. Use <strong>Start</strong> once and (optionally) enable <strong>Continue until done</strong>.</p>';

        echo '<div id="wami-tools-status" style="border:1px solid #ccd0d4;background:#fff;padding:12px;max-width:860px">';
        echo '<p><strong>Status:</strong> <span id="wami-tools-state">' . esc_html($is_running ? 'Running' : 'Idle') . '</span></p>';
        echo '<p><strong>Media images:</strong> <span id="wami-tools-total-images">' . esc_html($total_images) . '</span></p>';
        echo '<p><strong>Progress:</strong> <span id="wami-tools-progress">' . esc_html($checked) . '</span> / <span id="wami-tools-total">' . esc_html($total) . '</span> checked (' . esc_html($progress) . '%)</p>';
        echo '<p><strong>Created:</strong> <span id="wami-tools-created">' . esc_html($created) . '</span> &nbsp; <strong>Skipped:</strong> <span id="wami-tools-skipped">' . esc_html($skipped) . '</span> &nbsp; <strong>Errors:</strong> <span id="wami-tools-errors">' . esc_html($errors) . '</span></p>';
        echo '<p><strong>Cursor:</strong> after_id=<span id="wami-tools-after">' . esc_html($after_id) . '</span></p>';
        echo '<p><strong>Elapsed:</strong> <span id="wami-tools-elapsed">' . esc_html($this->format_elapsed($state)) . '</span></p>';
        echo '<div id="wami-tools-last" style="color:#646970"></div>';

		// Recent error log (last entries only).
		$err_count = count($error_log);
		$last_errs = array_slice($error_log, -20);
		echo '<details id="wami-tools-errors-box" style="margin-top:10px">';
		echo '<summary style="cursor:pointer">Recent errors (' . esc_html($err_count) . ')</summary>';
		echo '<pre id="wami-tools-errorlog" style="white-space:pre-wrap;margin-top:8px;max-height:220px;overflow:auto;">' . esc_html($this->format_error_log_lines($last_errs)) . '</pre>';
		echo '</details>';
        echo '</div>';

        echo '<div style="margin-top:12px;max-width:860px">';
        echo '<label style="display:inline-flex;align-items:center;gap:8px;margin-right:16px;">'
            . '<input type="checkbox" id="wami-tools-continue" />'
            . '<span>Continue until done</span>'
            . '</label>';
        echo '<label style="display:inline-flex;align-items:center;gap:8px;margin-right:16px;">'
            . '<span>Batch size</span>'
            . '<input type="number" min="1" max="25" step="1" id="wami-tools-batch" value="10" style="width:80px" />'
            . '</label>';
        echo '<button class="button button-primary" id="wami-tools-start">Start</button> ';
        echo '<button class="button" id="wami-tools-stop">Stop</button> ';
        echo '<button class="button" id="wami-tools-reset">Reset</button>';
        echo '</div>';

        // Non-JS fallback.
        echo '<noscript><p><em>JavaScript is required for the one-click auto-continue mode. Without JS, you can still run single batches.</em></p></noscript>';

        echo '</div>';
    }

    /**
     * AJAX: reset stored progress.
     */
    public function ajax_regen_reset() {
        $this->guard_ajax();
        $this->reset_state();
        wp_send_json_success(['state' => $this->get_state()]);
    }

    /**
     * AJAX: initialize a new run (reset cursor/counters, compute total, set start time).
     */
    public function ajax_regen_start() {
        $this->guard_ajax();

        $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 10;
        $batch_size = max(1, min(25, $batch_size));
        $continue = !empty($_POST['continue_until_done']);

        $state = $this->default_state();
        $state['batch_size'] = $batch_size;
        $state['continue_until_done'] = $continue;
        $state['start_ts'] = time();
        $state['end_ts'] = 0;
        $state['is_running'] = true;
        $state['last_tick'] = time();
        // Diagnostic baseline (all image-like attachments) and actual candidates to process.
        $state['total_images'] = $this->count_all_image_attachments();
        $state['total'] = $this->count_candidate_attachments();
        if ($state['total'] === 0 && $state['total_images'] > 0) {
            $this->push_error($state, 0, 'No candidate attachments found. Images exist in Media Library, but none match the candidate query. Check mime types and GUIDs.');
        }
        $this->update_state($state);

        // Same as on Start: allow converter a bit more time for file generation.
        if (is_object($this->converter) && method_exists($this->converter, 'set_time_budget_seconds')) {
            $this->converter->set_time_budget_seconds(8.0);
        }

        // Tools requests are short-running by design (batch runner). Give the converter
        // a bit more time than default to actually generate WebP/AVIF inside one request.
        if (is_object($this->converter) && method_exists($this->converter, 'set_time_budget_seconds')) {
            $this->converter->set_time_budget_seconds(8.0);
        }

        wp_send_json_success(['state' => $state]);
    }

    /**
     * AJAX: stop a running run (keeps progress, sets end_ts).
     */
    public function ajax_regen_stop() {
        $this->guard_ajax();
        $state = $this->get_state();
        $state['is_running'] = false;
        if (empty($state['end_ts'])) {
            $state['end_ts'] = time();
        }
        $this->update_state($state);
        wp_send_json_success(['state' => $state]);
    }

    /**
     * AJAX: process one batch and return updated progress.
     */
    public function ajax_regen_batch() {
        $this->guard_ajax();

        $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 10;
        $batch_size = max(1, min(25, $batch_size));

        $state = $this->get_state();
        // Safety: if someone calls batch without calling Start first.
        if (empty($state['start_ts'])) {
            $state['start_ts'] = time();
        }
        $state['is_running'] = true;
        $state['end_ts'] = 0;
        // Recompute total each request so UI doesn't get stuck on stale totals.
        $state['total'] = $this->count_candidate_attachments();
        $this->update_state($state);

        // Tools runs short AJAX batches; give the converter a bit more budget than the default.
        if (is_object($this->converter) && method_exists($this->converter, 'set_time_budget_seconds')) {
            $this->converter->set_time_budget_seconds(8.0);
        }

        $after_id = (int) ($state['after_id'] ?? 0);

        $t0 = microtime(true);
        $batch = $this->process_batch($after_id, $batch_size);
        $duration_ms = (int) round((microtime(true) - $t0) * 1000);

        // Merge counters.
        $state = $this->get_state();
        $state['after_id'] = (int) $batch['after_id'];
        $state['files_checked'] = (int) ($state['files_checked'] ?? 0) + (int) $batch['files_checked'];
        $state['created'] = (int) ($state['created'] ?? 0) + (int) $batch['created'];
        $state['skipped'] = (int) ($state['skipped'] ?? 0) + (int) $batch['skipped'];
        $state['errors']  = (int) ($state['errors']  ?? 0) + (int) $batch['errors'];

		// Append per-item error log (kept small to avoid bloating options).
		if (!empty($batch['error_log']) && is_array($batch['error_log'])) {
			$existing = isset($state['error_log']) && is_array($state['error_log']) ? $state['error_log'] : array();
			$existing = array_merge($existing, $batch['error_log']);
			// Keep last 200 entries.
			if (count($existing) > 200) {
				$existing = array_slice($existing, -200);
			}
			$state['error_log'] = $existing;
			$state['last_error'] = end($existing);
		}

        $state['last_duration_ms'] = $duration_ms;
        $state['last_ts'] = time();
        $state['last_tick'] = time();

        $done = empty($batch['has_more']);
        if ($done) {
            $state['is_running'] = false;
            $state['end_ts'] = time();
            $state['last_tick'] = 0;
        }

        $this->update_state($state);

        wp_send_json_success([
            'done' => $done,
            'state' => $state,
            'batch' => $batch,
            'error_log_text' => $this->format_error_log_lines(
                (isset($state['error_log']) && is_array($state['error_log'])) ? $state['error_log'] : array()
            ),
        ]);
    }

    /**
     * Process a single batch of attachments after a given ID.
     */
    private function process_batch($after_id, $count) {
        $files_checked = 0;
        $created = 0;
        $skipped = 0;
        $errors = 0;
        $error_log = array();

        // Correct cursoring: fetch candidates with ID > after_id directly.
        $ids = $this->fetch_candidate_ids((int) $after_id, (int) $count);

        $last_id = (int) $after_id;
        foreach ($ids as $id) {
            $id = (int) $id;
            $last_id = max($last_id, $id);
            $files_checked++;

            try {
                $created_before = $this->converter->get_created_counter();

                // Force conversion by wrapping a synthetic img tag (converter decides if it can/should).
                $img = wp_get_attachment_image($id, 'full');
                if (!$img) {
                    $skipped++;
                    $errors++;
                    $error_log[] = array(
                        'id' => $id,
                        'msg' => 'wp_get_attachment_image() returned empty HTML',
                    );
                    $this->debug_log('ERROR', $id, 'wp_get_attachment_image() returned empty HTML');
                    continue;
                }
                $result = $this->converter->wrap_img_html($img);

                $created_after = $this->converter->get_created_counter();
                $created_delta = max(0, $created_after - $created_before);
                if ($result !== $img || $created_delta > 0) {
                    $created += $created_delta;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors++;
                $error_log[] = array(
                    'id' => $id,
                    'msg' => $e->getMessage(),
                );
                $this->debug_log('ERROR', $id, $e->getMessage());
            }
        }

        // Determine if there might be more: if we filled the batch, assume yes.
        $has_more = (count($ids) === $count);

        return [
            'after_id'       => $last_id,
            'files_checked'  => $files_checked,
            'created'        => $created,
            'skipped'        => $skipped,
            'errors'         => $errors,
            'error_log'      => $error_log,
            'has_more'       => $has_more,
        ];
    }

    private function count_candidate_attachments() {
        global $wpdb;
        $where = $this->sql_candidate_where();
        $sql = "SELECT COUNT(1)
                FROM {$wpdb->posts}
                WHERE {$where}";
        return max(0, (int) $wpdb->get_var($sql));
    }

    /**
     * Count all image-like attachments (including ones already in WebP/AVIF) for diagnostics.
     */
    private function count_all_image_attachments() {
        global $wpdb;

        $sql = "SELECT COUNT(1)
                FROM {$wpdb->posts}
                WHERE post_type = 'attachment'
                  AND post_status = 'inherit'
                  AND (
                        post_mime_type LIKE 'image/%'
                        OR guid REGEXP '\\\\.(jpe?g|png|gif|webp|avif)$'
                      )
                  AND post_mime_type NOT IN ('image/svg+xml')";

        return max(0, (int) $wpdb->get_var($sql));
    }

    /**
     * Fetch next candidate IDs for processing using a stable cursor (ID > after_id).
     */
    private function fetch_candidate_ids($after_id, $limit) {
        global $wpdb;
        $after_id = (int) $after_id;
        $limit = max(1, (int) $limit);

        $where = $this->sql_candidate_where();

        $sql = $wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE {$where}
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $after_id,
            $limit
        );
        $ids = $wpdb->get_col($sql);
        return array_map('intval', is_array($ids) ? $ids : []);
    }

    private function debug_log($level, $id, $message) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        if (!defined('JMI_DEBUG') || JMI_DEBUG !== true) {
            return;
        }
        error_log(sprintf('[JMI Tools] %s id=%s %s', $level, (string) $id, (string) $message));
    }

    private function get_state() {
        $raw = get_option($this->option_key, []);
        $state = is_array($raw) ? $raw : [];

        $defaults = [
            'schema' => self::STATE_SCHEMA,
            'plugin_version' => defined('WAMI_PLUGIN_VERSION') ? WAMI_PLUGIN_VERSION : '',
            'is_running' => false,
            'continue_until_done' => false,
            'batch_size' => 12,
            'cursor_after_id' => 0,
            'total_candidates' => 0,
            'total_images' => 0,
            'files_checked' => 0,
            'created' => 0,
            'skipped' => 0,
            'errors' => 0,
            'start_ts' => 0,
            'end_ts' => 0,
            'last_tick' => 0,
            'recent_errors' => [],
        ];

        $state = array_merge($defaults, $state);

        // Invalidate persisted state when we change schema/version.
        if ((int) $state['schema'] !== self::STATE_SCHEMA || (string) $state['plugin_version'] !== $defaults['plugin_version']) {
            $state = $defaults;
            $this->update_state($state);
            return $state;
        }

        // Sanitize basic types.
        $state['is_running'] = !empty($state['is_running']);
        $state['continue_until_done'] = !empty($state['continue_until_done']);
        $state['batch_size'] = max(1, min(50, (int) $state['batch_size']));
        $state['cursor_after_id'] = max(0, (int) $state['cursor_after_id']);
        $state['total_candidates'] = max(0, (int) $state['total_candidates']);
        $state['total_images'] = max(0, (int) $state['total_images']);
        $state['files_checked'] = max(0, (int) $state['files_checked']);
        $state['created'] = max(0, (int) $state['created']);
        $state['skipped'] = max(0, (int) $state['skipped']);
        $state['errors'] = max(0, (int) $state['errors']);
        $state['start_ts'] = max(0, (int) $state['start_ts']);
        $state['end_ts'] = max(0, (int) $state['end_ts']);
        $state['last_tick'] = max(0, (int) $state['last_tick']);

        // Watchdog: if the process was marked as running but we haven't seen a tick in a while,
        // treat it as stopped (timeout/JS interruption) so the UI doesn't show huge elapsed.
        if ($state['is_running'] && $state['last_tick'] > 0) {
            $age = time() - $state['last_tick'];
            if ($age > 180) {
                $state['is_running'] = false;
                $state['continue_until_done'] = false;
                $state['end_ts'] = $state['end_ts'] ?: time();
                $this->update_state($state);
            }
        }
        if (!is_array($state['recent_errors'])) { $state['recent_errors'] = []; }

        // If we're idle, make elapsed stable (don't grow forever).
        if (!$state['is_running'] && $state['start_ts'] && !$state['end_ts']) {
            $state['end_ts'] = $state['start_ts'];
            $this->update_state($state);
        }

        return $state;
    }

    private function update_state(array $state) {
        update_option($this->option_key, $state, false);
    }

    private function reset_state() {
        delete_option($this->option_key);
    }

    private function format_elapsed(array $state) {
        $start = isset($state['start_ts']) ? (int) $state['start_ts'] : 0;
        if (!$start) {
            return '0s';
        }
		$is_running = !empty($state['is_running']);
		// If the job is not running, but end_ts was not persisted for some reason,
		// show a stable elapsed time instead of it "growing" on every refresh.
		if (!$is_running && empty($state['end_ts'])) {
			$end = $start;
		} else {
			$end = !empty($state['end_ts']) ? (int) $state['end_ts'] : time();
		}
        $sec = max(0, $end - $start);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        if ($h > 0) return sprintf('%dh %dm %ds', $h, $m, $s);
        if ($m > 0) return sprintf('%dm %ds', $m, $s);
        return sprintf('%ds', $s);
    }

	private function format_error_log_lines(array $entries, $limit = 30) {
		$lines = array();
		$entries = array_values($entries);
		$entries = array_slice($entries, -abs((int) $limit));
		foreach ($entries as $e) {
			$id = isset($e['id']) ? (int) $e['id'] : 0;
			$msg = isset($e['msg']) ? (string) $e['msg'] : (isset($e['message']) ? (string) $e['message'] : '');
			$msg = trim(preg_replace('/\s+/', ' ', $msg));
			if ($id > 0) {
				$lines[] = 'ID ' . $id . ': ' . $msg;
			} else {
				$lines[] = $msg;
			}
		}
		return implode("\n", array_filter($lines));
	}

    private function guard_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        check_ajax_referer('wami_tools', 'nonce');
    }

    private function get_inline_js() {
        return <<<JS
(function(){
  const cfg = window.WAMI_TOOLS || {};
  let state = cfg.state || {};
  const el = (id) => document.getElementById(id);

  const startBtn = el('wami-tools-start');
  const stopBtn  = el('wami-tools-stop');
  const resetBtn = el('wami-tools-reset');
  const contCb   = el('wami-tools-continue');
  const batchInp = el('wami-tools-batch');

  let running = false;
  let batchSize = (cfg.defaults && cfg.defaults.batchSize) || 10;
  let afterId = (cfg.state && cfg.state.after_id) || 0;
  let total = (cfg.state && cfg.state.total) || 0;
  let checked = (cfg.state && cfg.state.files_checked) || 0;
  let created = (cfg.state && cfg.state.created) || 0;
  let skipped = (cfg.state && cfg.state.skipped) || 0;
  let errors  = (cfg.state && cfg.state.errors) || 0;
  let lastTick = Date.now();

  function setText(id, value){ const n = el(id); if(n) n.textContent = String(value); }

  function render(){
    setText('wami-tools-after', afterId);
    setText('wami-tools-total-images', String(state.total_images ?? 0));
    setText('wami-tools-total', total);
    setText('wami-tools-progress', checked);
    setText('wami-tools-created', created);
    setText('wami-tools-skipped', skipped);
    setText('wami-tools-errors', errors);
    setText('wami-tools-state', running ? 'Running' : 'Idle');
    const pct = (total > 0) ? Math.min(100, Math.round((checked/total)*1000)/10) : 0;
    const last = el('wami-tools-last');
    if (last) last.textContent = running ? ('Batch size: ' + batchSize + ' | ' + pct + '%') : '';
  }

  function adjustBatch(durationMs){
    const min = (cfg.defaults && cfg.defaults.batchMin) || 1;
    const max = (cfg.defaults && cfg.defaults.batchMax) || 25;
    // Conservative targets: 2-6s per request. Slow -> reduce; fast -> increase.
    if (durationMs > 9000) batchSize = Math.max(min, Math.floor(batchSize / 2));
    else if (durationMs > 6500) batchSize = Math.max(min, batchSize - 2);
    else if (durationMs < 1800) batchSize = Math.min(max, batchSize + 2);
    else if (durationMs < 2800) batchSize = Math.min(max, batchSize + 1);
    batchInp.value = String(batchSize);
  }

  async function post(action, body){
    const form = new FormData();
    form.append('action', action);
    form.append('nonce', cfg.nonce || '');
    Object.keys(body || {}).forEach(k => form.append(k, body[k]));
    const res = await fetch(cfg.ajaxUrl, { method:'POST', credentials:'same-origin', body: form });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const json = await res.json();
    if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'AJAX failed');
    return json.data;
  }

  async function tick(){
    if (!running) return;
    lastTick = Date.now();
    try {
      const data = await post(cfg && cfg.state ? cfg.state.ajax_action || 'wami_regen_batch' : 'wami_regen_batch', {
        batch_size: batchSize,
        after_id: afterId
      });
      const st = data.state || {};
      if (st) state = st;
      afterId = st.after_id || afterId;
      total   = st.total || total;
      checked = st.files_checked || checked;
      created = st.created || created;
      skipped = st.skipped || skipped;
      errors  = st.errors || errors;
      if (st.last_duration_ms) adjustBatch(st.last_duration_ms);
      // If the server reports errors in the last batch, be more gentle.
      if (data.batch && data.batch.errors && data.batch.errors > 0) {
        batchSize = Math.max(1, batchSize - 2);
        batchInp.value = String(batchSize);
      }
      if (typeof data.error_log_text === 'string') {
        const el = document.getElementById('wami-tools-errorlog');
        if (el) el.textContent = data.error_log_text;
      }
      render();
      if (data.done) {
        running = false;
        render();
        return;
      }
      if (contCb && contCb.checked) {
        setTimeout(tick, 120);
      } else {
        running = false;
        render();
      }
    } catch (e) {
      errors += 1;
      batchSize = Math.max(1, Math.floor(batchSize / 2));
      batchInp.value = String(batchSize);
      render();
      if (contCb && contCb.checked) {
        setTimeout(tick, 1500);
      } else {
        running = false;
        render();
      }
    }
  }

  if (batchInp) {
    batchInp.addEventListener('change', function(){
      const v = parseInt(batchInp.value, 10);
      if (!isNaN(v) && v >= 1 && v <= 25) batchSize = v;
      batchInp.value = String(batchSize);
      render();
    });
    batchInp.value = String(batchSize);
  }

  if (startBtn) startBtn.addEventListener('click', async function(e){
    e.preventDefault();
    if (running) return;
    running = true;
    render();
    try {
      const data = await post('wami_regen_start', {
        continue_until_done: contCb ? (contCb.checked ? 1 : 0) : 0,
        batch_size: batchInp ? batchInp.value : batchSize,
      });
      const st = data.state || {};
      afterId = st.after_id || 0;
      total   = st.total || 0;
      checked = st.files_checked || 0;
      created = st.created || 0;
      skipped = st.skipped || 0;
      errors  = st.errors || 0;
      render();
    } catch (err) {
      console.error(err);
      running = false;
      render();
      return;
    }
    tick();
  });

  if (stopBtn) stopBtn.addEventListener('click', async function(e){
    e.preventDefault();
    running = false;
    render();
    try { await post('wami_regen_stop', {}); } catch (err) { console.error(err); }
  });

  if (resetBtn) resetBtn.addEventListener('click', async function(e){
    e.preventDefault();
    running = false;
    render();
    try {
      const data = await post('wami_regen_reset', {});
      const st = data.state || {};
      afterId = st.after_id || 0;
      total   = st.total || 0;
      checked = st.files_checked || 0;
      created = st.created || 0;
      skipped = st.skipped || 0;
      errors  = st.errors || 0;
    } catch (e2) {}
    render();
  });

  render();
})();
JS;
    }
}
