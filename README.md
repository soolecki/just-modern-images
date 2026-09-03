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
Version 0.12 focuses on operating reliably across different hosting environments.
It recovers missing work directly from a live cron request, adapts its runtime to
the available server budget, treats encoder health per attachment instead of per
thumbnail, and avoids consuming a library scan while every encoder is paused.
Network activation initializes each multisite site in its own WordPress context,
and new sites are handled automatically. Short immutable filenames, verified
race-safe publishing, and bounded retries improve behavior on Windows, IIS, and
SMB-backed uploads. The settings screen now clearly shows when background work
is active. The project is still in pre-release development and needs broader
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
