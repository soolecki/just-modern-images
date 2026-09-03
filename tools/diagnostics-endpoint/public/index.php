<?php
/**
 * Temporary diagnostics receiver and dashboard for Just Modern Images.
 */

declare(strict_types=1);

const JMI_RECEIVER_SCHEMA = 1;
const JMI_MAX_BODY_BYTES  = 262144;

$config_file = dirname(__DIR__) . '/config.php';
if (!is_readable($config_file)) {
	http_response_code(503);
	header('Content-Type: text/plain; charset=utf-8');
	echo "Receiver configuration is missing.\n";
	exit;
}

$config = require $config_file;
if (!is_array($config)) {
	http_response_code(500);
	exit;
}

if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? 'GET') && 'application/json' === request_media_type()) {
	receive_report($config);
}

show_dashboard($config);

/**
 * Store a validated diagnostic batch.
 *
 * @param array<string, mixed> $config Receiver configuration.
 */
function receive_report(array $config): void
{
	$content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
	if ($content_length < 1 || $content_length > JMI_MAX_BODY_BYTES) {
		json_response(413, array('error' => 'invalid_size'));
	}

	$raw = file_get_contents('php://input', false, null, 0, JMI_MAX_BODY_BYTES + 1);
	if (!is_string($raw) || '' === $raw || strlen($raw) > JMI_MAX_BODY_BYTES) {
		json_response(413, array('error' => 'invalid_size'));
	}

	$payload = json_decode($raw, true);
	if (!is_array($payload) || JMI_RECEIVER_SCHEMA !== (int) ($payload['schema'] ?? 0)) {
		json_response(400, array('error' => 'invalid_schema'));
	}

	$installation = is_array($payload['installation'] ?? null) ? $payload['installation'] : array();
	$install_id   = token($installation['id'] ?? '', 64);
	$install_key  = trim((string) ($_SERVER['HTTP_X_JMI_KEY'] ?? ''));
	$fleet_key    = trim((string) ($_SERVER['HTTP_X_JMI_FLEET_KEY'] ?? ''));
	$events       = is_array($payload['events'] ?? null) ? array_slice($payload['events'], 0, 20) : array();
	$expected_fleet_hash = strtolower(trim((string) ($config['ingest_token_hash'] ?? '')));

	if (!preg_match('/^[a-f0-9]{64}$/', $expected_fleet_hash) || !hash_equals($expected_fleet_hash, hash('sha256', $fleet_key))) {
		json_response(401, array('error' => 'invalid_fleet_key'));
	}

	if (strlen($install_id) < 20 || !preg_match('/^[a-zA-Z0-9_-]+$/', $install_id)) {
		json_response(400, array('error' => 'invalid_installation'));
	}
	if (!preg_match('/^[a-zA-Z0-9]{32,100}$/', $install_key)) {
		json_response(401, array('error' => 'invalid_key'));
	}
	if (empty($events)) {
		json_response(400, array('error' => 'empty_batch'));
	}

	$site_name = clean_text($installation['site_name'] ?? '', 80);
	$site_url  = public_site_url($installation['site_url'] ?? '');
	$runtime   = bounded_value($payload['runtime'] ?? array(), 0);
	$now       = time();
	$retention = max(1, min(365, (int) ($config['retention_days'] ?? 30))) * 86400;
	$maximum   = max(25, min(2000, (int) ($config['max_events_per_site'] ?? 250)));
	$max_sites = max(1, min(2000, (int) ($config['max_sites'] ?? 250)));
	$accepted  = 0;

	try {
		update_store(
			storage_file($config),
			static function (array $store) use ($install_id, $install_key, $site_name, $site_url, $runtime, $events, $now, $retention, $maximum, $max_sites, &$accepted): array {
				$sites = is_array($store['sites'] ?? null) ? $store['sites'] : array();
				$site  = is_array($sites[$install_id] ?? null) ? $sites[$install_id] : array();
				$hash  = hash('sha256', $install_key);

				if (!empty($site['key_hash']) && !hash_equals((string) $site['key_hash'], $hash)) {
					throw new RuntimeException('installation_key_mismatch');
				}

				$known  = array();
				$stored = is_array($site['events'] ?? null) ? $site['events'] : array();
				foreach ($stored as $event) {
					if (is_array($event) && !empty($event['id'])) {
						$known[(string) $event['id']] = true;
					}
				}

				foreach ($events as $event) {
					$event = normalize_event($event);
					if (empty($event) || isset($known[$event['id']])) {
						continue;
					}
					$stored[]           = $event;
					$known[$event['id']] = true;
					++$accepted;
				}

				$stored = array_values(
					array_filter(
						$stored,
						static fn($event): bool => is_array($event) && (int) ($event['started_at'] ?? 0) >= $now - $retention
					)
				);
				usort($stored, static fn($left, $right): int => (int) ($left['started_at'] ?? 0) <=> (int) ($right['started_at'] ?? 0));
				$stored = array_slice($stored, -$maximum);

				$sites[$install_id] = array(
					'key_hash'  => $hash,
					'site_name' => $site_name,
					'site_url'  => $site_url,
					'runtime'   => $runtime,
					'first_seen'=> (int) ($site['first_seen'] ?? $now),
					'last_seen' => $now,
					'events'    => $stored,
				);

				if (count($sites) > $max_sites) {
					uasort($sites, static fn($left, $right): int => (int) ($right['last_seen'] ?? 0) <=> (int) ($left['last_seen'] ?? 0));
					$sites = array_slice($sites, 0, $max_sites, true);
				}

				return array(
					'schema'     => JMI_RECEIVER_SCHEMA,
					'updated_at' => $now,
					'sites'      => $sites,
				);
			}
		);
	} catch (RuntimeException $error) {
		if ('installation_key_mismatch' === $error->getMessage()) {
			json_response(403, array('error' => 'installation_key_mismatch'));
		}
		json_response(500, array('error' => 'storage_failure'));
	} catch (Throwable $error) {
		json_response(500, array('error' => 'storage_failure'));
	}

	json_response(202, array('accepted' => $accepted));
}

/**
 * Render the password-protected fleet view.
 *
 * @param array<string, mixed> $config Receiver configuration.
 */
function show_dashboard(array $config): void
{
	secure_session();
	header('Cache-Control: no-store, private');
	header('Content-Type: text/html; charset=utf-8');
	header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
	header('Referrer-Policy: no-referrer');
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: DENY');

	$password_hash = (string) ($config['viewer_password_hash'] ?? '');
	$configured    = 0 !== (int) (password_get_info($password_hash)['algo'] ?? 0);

	if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? 'GET') && 'login' === ($_POST['action'] ?? '')) {
		$password = (string) ($_POST['password'] ?? '');
		if ($configured && password_verify($password, $password_hash)) {
			session_regenerate_id(true);
			$_SESSION['jmi_receiver_authenticated'] = true;
			header('Location: ' . dashboard_path());
			exit;
		}
		$_SESSION['jmi_receiver_login_failed'] = true;
	}

	if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? 'GET') && 'logout' === ($_POST['action'] ?? '')) {
		$_SESSION = array();
		session_destroy();
		header('Location: ' . dashboard_path());
		exit;
	}

	if (empty($_SESSION['jmi_receiver_authenticated'])) {
		$failed = !empty($_SESSION['jmi_receiver_login_failed']);
		unset($_SESSION['jmi_receiver_login_failed']);
		render_login($configured, $failed);
		return;
	}

	try {
		$store = read_store(storage_file($config));
	} catch (Throwable $error) {
		$store = array('sites' => array());
	}

	render_dashboard(is_array($store['sites'] ?? null) ? $store['sites'] : array());
}

function render_login(bool $configured, bool $failed): void
{
	?><!doctype html>
	<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Just Modern Images diagnostics</title><?php dashboard_styles(); ?></head>
	<body class="login"><main class="login-card"><div class="mark">JMI</div><h1>Diagnostics</h1><p>Private test-installation reports.</p>
	<?php if (!$configured): ?><div class="alert">Set a real <code>viewer_password_hash</code> in <code>config.php</code>.</div>
	<?php elseif ($failed): ?><div class="alert">Incorrect password.</div><?php endif; ?>
	<form method="post"><input type="hidden" name="action" value="login"><label>Password<input type="password" name="password" required autofocus></label><button type="submit">Open dashboard</button></form>
	</main></body></html><?php
}

/**
 * @param array<string, array<string, mixed>> $sites Sites indexed by installation ID.
 */
function render_dashboard(array $sites): void
{
	$rows        = array();
	$all_events  = array();
	$attention   = 0;
	$waiting     = 0;
	$problematic = 0;
	$cron_stale  = 0;

	foreach ($sites as $install_id => $site) {
		if (!is_array($site)) {
			continue;
		}
		$events = is_array($site['events'] ?? null) ? $site['events'] : array();
		$latest = latest_state_event($events);
		$after  = is_array($latest['after']['library'] ?? null) ? $latest['after']['library'] : array();
		$issues = site_issue_count($events);
		$runtime = is_array($site['runtime'] ?? null) ? $site['runtime'] : array();
		$cron    = is_array($runtime['cron'] ?? null) ? $runtime['cron'] : array();
		$is_cron_stale = !empty($cron['last_observed_at']) && (int) $cron['last_observed_at'] < time() - 7200;
		$attention += (int) ($after['attention'] ?? 0);
		$waiting   += (int) ($after['waiting'] ?? 0);
		$cron_stale += $is_cron_stale ? 1 : 0;
		$problematic += $issues > 0 || $is_cron_stale ? 1 : 0;
		$rows[] = array('id' => $install_id, 'site' => $site, 'latest' => $latest, 'library' => $after, 'issues' => $issues, 'cron_stale' => $is_cron_stale);

		foreach ($events as $event) {
			if (is_array($event)) {
				$event['_site_id']   = $install_id;
				$event['_site_name'] = (string) ($site['site_name'] ?? '');
				$all_events[] = $event;
			}
		}
	}

	usort($rows, static fn($left, $right): int => (int) ($right['site']['last_seen'] ?? 0) <=> (int) ($left['site']['last_seen'] ?? 0));
	usort($all_events, static fn($left, $right): int => (int) ($right['started_at'] ?? 0) <=> (int) ($left['started_at'] ?? 0));
	$all_events = array_slice($all_events, 0, 100);
	?><!doctype html>
	<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Just Modern Images diagnostics</title><?php dashboard_styles(); ?></head><body>
	<header><div><h1>Just Modern Images diagnostics</h1><p>Current health and recent processing activity across opted-in sites.</p></div><form method="post"><input type="hidden" name="action" value="logout"><button class="secondary" type="submit">Sign out</button></form></header>
	<main>
	<section class="stats"><article><span>Reporting sites</span><strong><?= count($rows) ?></strong></article><article><span>Sites with signals</span><strong class="<?= $problematic ? 'danger' : '' ?>"><?= $problematic ?></strong><small><?= $cron_stale ?> with cron silent for 2+ hours</small></article><article><span>Images waiting</span><strong><?= $waiting ?></strong></article><article><span>Need attention</span><strong class="<?= $attention ? 'danger' : '' ?>"><?= $attention ?></strong></article></section>
	<section class="panel"><div class="panel-title"><div><h2>Sites</h2><p>Click a homepage to verify a reported problem.</p></div></div>
	<?php if (empty($rows)): ?><div class="empty">No diagnostic reports have arrived yet.</div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Site</th><th>Last report</th><th>Library</th><th>Formats</th><th>Cron</th><th>Runtime</th><th>Signals</th></tr></thead><tbody>
	<?php foreach ($rows as $row): $site = $row['site']; $latest = $row['latest']; $library = $row['library']; $runtime = is_array($site['runtime'] ?? null) ? $site['runtime'] : array(); $cron = is_array($runtime['cron'] ?? null) ? $runtime['cron'] : array(); ?>
	<tr><td><strong><?= h(site_label($site, (string) $row['id'])) ?></strong><?php if (!empty($site['site_url'])): ?><a class="site-link" href="<?= h((string) $site['site_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h((string) $site['site_url']) ?> ↗</a><?php endif; ?><code><?= h(substr((string) $row['id'], 0, 12)) ?></code></td>
	<td><?= h(relative_time((int) ($site['last_seen'] ?? 0))) ?><small><?= h(date('Y-m-d H:i:s', (int) ($site['last_seen'] ?? 0))) ?></small></td>
	<td><strong><?= (int) ($library['ready'] ?? 0) ?>/<?= (int) ($library['total'] ?? 0) ?> ready</strong><small><?= (int) ($library['waiting'] ?? 0) ?> waiting · <?= (int) ($library['attention'] ?? 0) ?> attention</small></td>
	<td><?= format_badge($latest, 'image/avif', 'AVIF') ?> <?= format_badge($latest, 'image/webp', 'WebP') ?></td>
	<td><strong><?= h(interval_label((int) ($cron['average_ms'] ?? 0))) ?> average</strong><small><?= h(interval_label((int) ($cron['minimum_ms'] ?? 0))) ?> min · <?= h(interval_label((int) ($cron['maximum_ms'] ?? 0))) ?> max</small><small><?= h(relative_time((int) ($cron['last_observed_at'] ?? 0))) ?><?php if (!empty($cron['built_in_disabled'])): ?> · external<?php endif; ?></small></td>
	<td>JMI <?= h((string) ($runtime['plugin'] ?? '—')) ?><small>WP <?= h((string) ($runtime['wordpress'] ?? '—')) ?> · PHP <?= h((string) ($runtime['php'] ?? '—')) ?></small></td>
	<td><?php if ($row['issues']): ?><span class="badge bad"><?= (int) $row['issues'] ?> recent</span><?php elseif ($row['cron_stale']): ?><span class="badge bad">cron stale</span><?php else: ?><span class="badge good">clear</span><?php endif; ?><small><?= h((string) ($latest['stop_reason'] ?? 'waiting')) ?></small></td></tr>
	<?php endforeach; ?></tbody></table></div><?php endif; ?></section>

	<section class="panel"><div class="panel-title"><div><h2>Recent reports</h2><p>The newest aggregated worker and error events.</p></div></div>
	<?php if (empty($all_events)): ?><div class="empty">No activity has been reported.</div><?php else: ?><div class="table-wrap"><table class="reports-table"><thead><tr><th>Site and event</th><th>Library after run</th><th>Performance</th><th>Reported</th></tr></thead><tbody>
	<?php foreach ($all_events as $event): $after = is_array($event['after']['library'] ?? null) ? $event['after']['library'] : array(); $results = is_array($event['item_results'] ?? null) ? $event['item_results'] : array(); ?>
	<tr class="<?= event_is_problem($event) ? 'report-problem' : '' ?>"><td><strong><?= h((string) ($event['_site_name'] ?: substr((string) $event['_site_id'], 0, 12))) ?></strong><small><?= h((string) ($event['type'] ?? 'event')) ?> · <?= h((string) ($event['stop_reason'] ?? '')) ?></small><?php if (!empty($event['problem']['message'])): ?><code class="problem-message"><?= h((string) $event['problem']['message']) ?></code><?php endif; ?></td><td><strong><?= (int) ($after['ready'] ?? 0) ?>/<?= (int) ($after['total'] ?? 0) ?> ready</strong><small><?= (int) ($after['waiting'] ?? 0) ?> waiting · <?= (int) ($after['attention'] ?? 0) ?> attention</small></td><td><strong><?= h(worker_rate_label($event)) ?></strong><small><?= number_format(((int) ($event['duration_ms'] ?? 0)) / 1000, 1) ?> s · <?= (int) ($results['failed'] ?? 0) ?> failed · <?= h(delay_label($event)) ?> delay</small><small><?= h(performance_label($event, $results)) ?></small></td><td><time><?= h(relative_time((int) ($event['started_at'] ?? 0))) ?></time></td></tr>
	<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
	</main></body></html><?php
}

/**
 * Atomically update the JSON store under an exclusive file lock.
 *
 * @param callable(array<string, mixed>): array<string, mixed> $callback Update callback.
 */
function update_store(string $path, callable $callback): void
{
	$directory = dirname($path);
	if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
		throw new RuntimeException('storage_directory_unavailable');
	}

	$handle = fopen($path, 'c+');
	if (false === $handle || !flock($handle, LOCK_EX)) {
		throw new RuntimeException('storage_lock_failed');
	}

	try {
		rewind($handle);
		$raw   = stream_get_contents($handle);
		$store = '' === trim((string) $raw) ? array() : json_decode((string) $raw, true);
		if (!is_array($store)) {
			throw new RuntimeException('storage_corrupt');
		}
		$updated = $callback($store);
		$json    = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if (!is_string($json)) {
			throw new RuntimeException('storage_encode_failed');
		}
		rewind($handle);
		if (!ftruncate($handle, 0) || false === fwrite($handle, $json) || !fflush($handle)) {
			throw new RuntimeException('storage_write_failed');
		}
	} finally {
		flock($handle, LOCK_UN);
		fclose($handle);
	}
}

/** @return array<string, mixed> */
function read_store(string $path): array
{
	if (!is_readable($path)) {
		return array();
	}
	$raw = file_get_contents($path);
	$data = is_string($raw) ? json_decode($raw, true) : null;
	if (!is_array($data)) {
		throw new RuntimeException('storage_corrupt');
	}
	return $data;
}

/** @return array<string, mixed> */
function normalize_event($event): array
{
	if (!is_array($event)) {
		return array();
	}
	$id         = token($event['id'] ?? '', 64);
	$started_at = max(0, (int) ($event['started_at'] ?? 0));
	if ('' === $id || $started_at < 1) {
		return array();
	}
	$event               = bounded_value($event, 0);
	$event['id']         = $id;
	$event['started_at'] = $started_at;
	return $event;
}

function bounded_value($value, int $depth)
{
	if ($depth > 7) {
		return null;
	}
	if (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
		return $value;
	}
	if (is_string($value)) {
		return clean_text($value, 500);
	}
	if (!is_array($value)) {
		return null;
	}
	$result = array();
	foreach (array_slice($value, 0, 100, true) as $key => $item) {
		$safe_key = is_int($key) ? $key : preg_replace('/[^a-zA-Z0-9_.\/-]/', '', substr((string) $key, 0, 64));
		$result[$safe_key] = bounded_value($item, $depth + 1);
	}
	return $result;
}

function storage_file(array $config): string
{
	return (string) ($config['storage_file'] ?? dirname(__DIR__) . '/storage/reports.json');
}

function request_media_type(): string
{
	$value = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
	return trim(explode(';', $value, 2)[0]);
}

function public_site_url($value): string
{
	$url = filter_var((string) $value, FILTER_VALIDATE_URL);
	if (false === $url) {
		return '';
	}
	$parts = parse_url($url);
	if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), array('http', 'https'), true) || empty($parts['host'])) {
		return '';
	}
	$path = preg_replace('/[^a-zA-Z0-9._~!$&\'()*+,;=:@%\/-]/', '', (string) ($parts['path'] ?? '/'));
	$path = trim((string) $path, '/');
	return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '') . ('' === $path ? '/' : '/' . $path . '/');
}

function clean_text($value, int $length): string
{
	$value = is_scalar($value) ? strip_tags((string) $value) : '';
	$value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
	$value = preg_replace('/\s+/u', ' ', (string) $value);
	return substr(trim((string) $value), 0, $length);
}

function token($value, int $length): string
{
	return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', is_scalar($value) ? (string) $value : ''), 0, $length);
}

function json_response(int $status, array $body): void
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	echo json_encode($body, JSON_UNESCAPED_SLASHES);
	exit;
}

function secure_session(): void
{
	$secure = 'https' === strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) || 'on' === strtolower((string) ($_SERVER['HTTPS'] ?? ''));
	session_name('jmi_receiver');
	session_set_cookie_params(array('httponly' => true, 'secure' => $secure, 'samesite' => 'Strict', 'path' => '/'));
	session_start();
}

function dashboard_path(): string
{
	$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
	return is_string($path) && '' !== $path ? $path : '/';
}

function site_label(array $site, string $id): string
{
	if (!empty($site['site_name'])) {
		return (string) $site['site_name'];
	}
	if (!empty($site['site_url'])) {
		return (string) (parse_url((string) $site['site_url'], PHP_URL_HOST) ?: substr($id, 0, 12));
	}
	return substr($id, 0, 12);
}

/** @return array<string, mixed> */
function latest_state_event(array $events): array
{
	foreach (array_reverse($events) as $event) {
		if (!is_array($event) || in_array((string) ($event['type'] ?? ''), array('cron_heartbeat', 'runtime_problem'), true)) {
			continue;
		}
		return $event;
	}
	return !empty($events) && is_array(end($events)) ? end($events) : array();
}

function site_issue_count(array $events): int
{
	$count = 0;
	foreach (array_slice($events, -20) as $event) {
		if (is_array($event) && event_is_problem($event)) {
			++$count;
		}
	}
	return $count;
}

function event_is_problem(array $event): bool
{
	if ('runtime_problem' === ($event['type'] ?? '')) {
		return true;
	}
	$stop = (string) ($event['stop_reason'] ?? '');
	if (in_array($stop, array('unexpected_worker_failure', 'memory_pressure'), true)) {
		return true;
	}
	$after   = is_array($event['after']['library'] ?? null) ? $event['after']['library'] : array();
	$results = is_array($event['item_results'] ?? null) ? $event['item_results'] : array();
	return (int) ($after['attention'] ?? 0) > 0 || (int) ($results['failed'] ?? 0) > 0;
}

function worker_rate_label(array $event): string
{
	$processed   = max(0, (int) ($event['processed'] ?? 0));
	$duration_ms = max(0, (int) ($event['duration_ms'] ?? 0));
	if ($processed < 1 || $duration_ms < 1) {
		return $processed . ' images';
	}
	return number_format($processed / ($duration_ms / 1000), 2) . ' images/sec';
}

function delay_label(array $event): string
{
	$performance = is_array($event['performance'] ?? null) ? $event['performance'] : array();
	return interval_label((int) ($performance['start_delay_ms'] ?? 0));
}

function performance_label(array $event, array $results): string
{
	$performance = is_array($event['performance'] ?? null) ? $event['performance'] : array();
	$total_ms    = max(0, (int) ($results['total_duration_ms'] ?? 0));
	$processed   = max(0, (int) ($event['processed'] ?? 0));
	$average_ms  = $processed > 0 ? (int) round($total_ms / $processed) : 0;
	$peak        = max(0, (int) ($performance['memory_peak'] ?? 0));
	$limit       = max(0, (int) ($performance['memory_limit'] ?? 0));

	return 'image avg ' . interval_label($average_ms) .
		' · slowest ' . interval_label((int) ($results['max_duration_ms'] ?? 0)) .
		' · memory ' . bytes_label($peak) . ($limit > 0 ? '/' . bytes_label($limit) : '');
}

function bytes_label(int $bytes): string
{
	if ($bytes < 1) {
		return 'not measured';
	}
	if ($bytes < 1048576) {
		return number_format($bytes / 1024, 1) . ' KB';
	}
	return number_format($bytes / 1048576, 1) . ' MB';
}

function interval_label(int $milliseconds): string
{
	if ($milliseconds < 1) {
		return 'not measured';
	}
	if ($milliseconds < 1000) {
		return $milliseconds . ' ms';
	}
	$seconds = $milliseconds / 1000;
	if ($seconds < 120) {
		return number_format($seconds, 1) . ' sec';
	}
	return number_format($seconds / 60, 1) . ' min';
}

function format_badge(array $event, string $mime, string $label): string
{
	$format = is_array($event['formats'][$mime] ?? null) ? $event['formats'][$mime] : array();
	$state  = (string) ($format['state'] ?? 'unknown');
	$class  = 'available' === $state ? 'good' : ('unknown' === $state ? '' : 'bad');
	return '<span class="badge ' . h($class) . '" title="' . h((string) ($format['reason'] ?? '')) . '">' . h($label) . '</span>';
}

function relative_time(int $timestamp): string
{
	if ($timestamp < 1) {
		return 'never';
	}
	$seconds = max(0, time() - $timestamp);
	if ($seconds < 60) return $seconds . ' sec ago';
	if ($seconds < 3600) return (int) floor($seconds / 60) . ' min ago';
	if ($seconds < 86400) return (int) floor($seconds / 3600) . ' hr ago';
	return (int) floor($seconds / 86400) . ' days ago';
}

function h(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dashboard_styles(): void
{
	?><style>
	:root{color-scheme:light;--ink:#182230;--muted:#667085;--line:#e4e7ec;--surface:#fff;--bg:#f5f7fa;--blue:#155eef;--red:#b42318;--green:#067647}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}header{display:flex;justify-content:space-between;align-items:flex-start;gap:30px;max-width:1440px;margin:auto;padding:38px 30px 24px}h1{margin:0 0 4px;font-size:30px;letter-spacing:-.035em}h2{margin:0;font-size:18px}p{margin:4px 0;color:var(--muted)}main{max-width:1440px;margin:auto;padding:0 30px 50px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}.stats article,.panel,.login-card{border:1px solid var(--line);border-radius:10px;background:var(--surface);box-shadow:0 1px 2px rgba(16,24,40,.04)}.stats article{padding:18px}.stats span{display:block;color:var(--muted);font-size:12px}.stats strong{display:block;margin-top:7px;font-size:27px}.stats small{display:block;margin-top:2px;color:var(--muted);font-size:11px}.danger{color:var(--red)!important}.panel{margin-bottom:18px;overflow:hidden}.panel-title{padding:19px 22px;border-bottom:1px solid var(--line)}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left}th{padding:10px 16px;background:#f9fafb;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.05em}td{padding:14px 16px;border-top:1px solid var(--line);vertical-align:top;white-space:nowrap}td small,td code{display:block;margin-top:3px;color:var(--muted);font-size:12px}.reports-table td:first-child{white-space:normal}.reports-table time{color:var(--muted);font-size:12px}.report-problem{box-shadow:inset 3px 0 var(--red)}.problem-message{max-width:560px;white-space:normal}.site-link{display:block;margin:2px 0;color:var(--blue);text-decoration:none}.badge{display:inline-flex!important;width:max-content;margin:0 3px 3px 0!important;padding:3px 7px;border-radius:99px;background:#f2f4f7;color:#475467!important;font-size:11px!important;font-weight:700}.badge.good{background:#ecfdf3;color:var(--green)!important}.badge.bad{background:#fef3f2;color:var(--red)!important}.empty{padding:35px 22px;color:var(--muted);text-align:center}button{padding:9px 14px;border:0;border-radius:7px;background:var(--blue);color:#fff;font-weight:650;cursor:pointer}button.secondary{border:1px solid var(--line);background:#fff;color:var(--ink)}body.login{display:grid;min-height:100vh;place-items:center;padding:24px}.login-card{width:min(420px,100%);padding:32px}.mark{display:grid;width:44px;height:44px;place-items:center;border-radius:10px;background:var(--blue);color:#fff;font-weight:800}.login-card h1{margin-top:18px}.login-card form{display:grid;gap:15px;margin-top:24px}.login-card label{color:var(--muted);font-size:12px;font-weight:600}.login-card input{display:block;width:100%;margin-top:6px;padding:10px 12px;border:1px solid #98a2b3;border-radius:7px;font:inherit}.alert{margin-top:18px;padding:11px;border-radius:7px;background:#fef3f2;color:var(--red)}@media(max-width:900px){.stats{grid-template-columns:repeat(2,1fr)}}@media(max-width:560px){header{padding:24px 16px 18px}main{padding:0 16px 35px}.stats{grid-template-columns:1fr 1fr}}
	</style><?php
}
