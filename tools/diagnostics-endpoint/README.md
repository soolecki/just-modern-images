# Just Modern Images diagnostics endpoint

This small PHP application receives reports from explicitly opted-in test
installations and presents them in one password-protected dashboard. It does not
need WordPress or a database.

## Requirements

- PHP 7.4 or newer
- HTTPS
- a writable private storage directory

## Installation

1. Copy this directory to the server.
2. Point the site's document root at the `public` directory.
3. Copy `config.example.php` to `config.php`.
4. Generate a dashboard password hash:

   ```bash
   php -r "echo password_hash('choose a long password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

5. Put the hash in `config.php` and make the configured storage directory
   writable by PHP. Generate a separate long random fleet key and store only
   its SHA-256 hash as `ingest_token_hash`:

   ```bash
   php -r "echo hash('sha256', 'your long random fleet key'), PHP_EOL;"
   ```
6. Open the endpoint URL in a browser and sign in.

The same URL accepts `POST` requests from the plugin and displays the dashboard
for browser requests. Each installation creates its own random ID and secret.
The secret is sent only over HTTPS and is stored by the receiver as a SHA-256
hash. A report cannot replace data belonging to another installation without
knowing that installation's secret. The shared fleet key prevents unknown
installations from registering with the receiver.

After deployment, set `JMI_DIAGNOSTICS_ENDPOINT` to the final HTTPS URL and
`JMI_DIAGNOSTICS_FLEET_KEY` to the same raw fleet key while running the plugin
build. The build script writes both values only into that ZIP;
the source keeps an empty default. Install the connected build on the test
sites. Administrators only see the reporting checkbox; they do not configure
endpoints or credentials.

## Data handling

The endpoint stores the public site name and homepage URL, runtime versions,
format capabilities, queue snapshots, aggregated processing results, real cron
intervals, scheduling delay, worker throughput, image timing, memory use, and
redacted fatal errors from the plugin directory. It rejects oversized payloads,
keeps a bounded event history, and removes events older than the configured
retention period.
