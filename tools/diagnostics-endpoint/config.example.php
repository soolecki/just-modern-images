<?php

return array(
	// Generate with: php -r "echo password_hash('your password', PASSWORD_DEFAULT), PHP_EOL;"
	'viewer_password_hash' => '$2y$10$replace.this.with.a.real.password.hash',
	// Generate with: php -r "echo hash('sha256', 'a long random fleet key'), PHP_EOL;"
	'ingest_token_hash'    => 'replace.this.with.the.sha256.hash.of.the.fleet.key',
	// Prefer a directory outside the public web root when the host allows it.
	'storage_file'         => __DIR__ . '/storage/reports.json',
	'retention_days'       => 30,
	'max_events_per_site'  => 250,
	'max_sites'            => 250,
);
