# Just Modern Images

Just Modern Images is a WordPress plugin that generates WebP and AVIF alternatives for JPEG and PNG uploads and serves the best format supported by the browser.

The project is currently under active development and is not ready for production use.

## Product principles

- Works immediately after activation.
- Keeps the original image as a reliable fallback.
- Uses the formats supported by the server.
- Adds no configuration burden beyond a small set of quality presets.
- Fails safely when an image, server capability, theme, or integration is unusual.

## Current status

The original prototype has been replaced with a fail-safe conversion pipeline.
Version 0.11 adds observable Media Library state, explicit manual priority,
request-based processing priority without visitor tracking, and a graphical
progress screen. Version 0.11.1 adds actionable diagnostics, per-server capability
profiles for clustered installations, and immutable output publishing designed
for shared filesystems. Version 0.11.2 keeps adjacent component revisions
compatible while independent application servers refresh their opcode caches.
Version 0.11.3 adds request-wide adaptive cron budgets and one shared worker lock
for overlapping clustered cron calls. Version 0.11.4 makes the queue
self-healing after interrupted single events and replaces release-number
migrations with a monotonic data revision that remains stable during rolling
OPcache refreshes. Version 0.11.5 prevents delayed events from reopening settled
images and adds a bounded, privacy-safe history of worker runs with before and
after state. Version 0.11.6 adds explicit opt-in reporting for privately tested
installations and a standalone, password-protected fleet dashboard. The project
is still in pre-release development and needs broader
integration testing before a public WordPress.org release.

## Development

Install the development tools with Composer, then run:

```bash
composer test
composer lint
```

The production plugin has no Composer or JavaScript runtime dependencies.

Create an installable archive with:

```bash
composer build
```

The temporary test-fleet receiver lives in `tools/diagnostics-endpoint`. Build
its separate deployment archive with:

```bash
composer build-endpoint
```

Reporting is disabled by default and requires an administrator to select
**Send diagnostic data** on the Activity log screen. The receiver URL is compiled
into private test builds, so site administrators never have to configure an
endpoint or credential.

Build a private test ZIP connected to a deployed receiver with:

```bash
JMI_DIAGNOSTICS_ENDPOINT=https://diagnostics.example.com/ \
JMI_DIAGNOSTICS_FLEET_KEY=replace-with-a-long-random-key composer build
```

The environment values are written only to the generated ZIP. The source tree
continues to produce a normal build with no connected receiver.

## License

GPL-2.0-or-later.
