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
The project is still in pre-release development and needs broader integration
testing before it is installed on production sites.

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

## License

GPL-2.0-or-later.
