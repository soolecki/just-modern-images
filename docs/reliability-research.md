# Reliability research

This document records the failure modes that must be addressed before the first
public release. It is based on public reviews, support topics, and issue reports
for Modern Image Formats, together with the current WordPress image APIs.

The purpose is not to reproduce another plugin's implementation. It is to learn
from real sites where image conversion failed, became destructive, or was too
difficult to understand.

## Product promise

Just Modern Images must be safe to activate on an unfamiliar WordPress site.
It should create useful modern files when the server can do so, leave the site
unchanged when it cannot, and make deactivation an immediate rollback.

The only preference is image quality. Conversion status, diagnostics, rebuild,
and cleanup are operational tools rather than configuration choices.

## Findings

### A silent no-op looks like a broken plugin

Users regularly report that conversion "doesn't work" when the server cannot
encode the requested format, when only new uploads are processed, or when a
modern file is correctly discarded for being larger than its source.

Requirements:

- Run an actual encode probe, not only an extension or function check.
- Explain support separately for WebP and AVIF.
- Record a short result for every skipped or failed attachment.
- Start a resumable scan of existing attachments after activation.
- Never make the user inspect files on disk to learn what happened.

References:

- [Doesn't work](https://wordpress.org/support/topic/doesnt-work-3156/)
- [Clarify whether images are converted](https://github.com/WordPress/performance/issues/2442)
- [No option to convert old images](https://wordpress.org/support/topic/works-like-a-charm-but-theres-no-option-to-convert-old-images/)

### Canonical WordPress media must remain untouched

Changing the primary attachment filename or core size metadata can leave post
content, builders, translated attachments, CDNs, and third-party databases
pointing to files that no longer exist. Regenerating thumbnails can then make
the damage harder to reverse.

Requirements:

- Keep the uploaded JPEG or PNG and every WordPress-generated size unchanged.
- Do not replace the core `file`, `sizes`, MIME type, or attachment URL.
- Store modern variants in plugin-owned attachment metadata.
- Deactivation must make the original markup take effect without a migration.
- Rebuild and cleanup operations must be idempotent and resumable.

References:

- [Irreversible media library damage report](https://wordpress.org/support/topic/causes-an-irreversible-damage-to-your-media-library-needs-and-explicit-warning/)
- [Broken images after regenerating thumbnails](https://wordpress.org/support/topic/dont-regenerate-thumbnails/)
- [Filename mismatch after regeneration](https://github.com/WordPress/performance/issues/1396)
- [Revert to the original extension](https://wordpress.org/support/topic/revert-back-to-original-uploaded-image-extension-from-avif/)

### Generated names must be deterministic

Repeated regeneration has produced chains such as `image-jpg-webp.webp` on
real sites. Different original files can also share a basename.

Requirements:

- Only JPEG and PNG files owned by an attachment are accepted as sources.
- A companion includes the original extension, for example
  `photo.jpg.webp` and `photo.jpg.avif`.
- A generated WebP or AVIF is never accepted as a source.
- The source signature and generation profile decide whether a file is current.
- A rebuild replaces the same companion instead of inventing another name.

References:

- [Repeated regeneration creates duplicate files](https://wordpress.org/support/topic/regenerate-thumbnails-creates-duplicate-files-every-time-it-is-run/)
- [Regenerate thumbnail duplication issue](https://github.com/WordPress/performance/issues/575)

### A successful encoder call does not prove a valid image exists

Reports include zero-byte AVIF files, palette PNG failures, missing full-size
variants, and transparency loss. Library availability alone is insufficient.

Requirements:

- Convert palette PNG input to truecolor in memory without changing the source.
- Write to a temporary file in the destination directory.
- Validate non-zero size, MIME type, dimensions, and decodability.
- Preserve alpha or skip that format for the affected source.
- Atomically rename a validated temporary file into place.
- Keep serving the original when any validation step fails.

References:

- [AVIF images generated as zero-byte files](https://wordpress.org/support/topic/avif-images-generated-as-0kb-file/)
- [Inconsistent PNG conversion](https://github.com/WordPress/performance/issues/1798)
- [Palette PNG WebP failures](https://wordpress.org/support/topic/png-files-not-set-to-true-color-palette-arent-rendering-properly/)
- [AVIF transparency loss](https://github.com/WordPress/performance/issues/1576)

### A modern image can be worse than its source

Some conversions produce files several times larger than the original. Serving
them would break the product's core purpose even if they are technically valid.

Requirements:

- Compare every generated size with its exact source size.
- Discard a companion that is not smaller.
- Record `not_smaller` as a normal outcome, not an error.
- Do not compare a thumbnail against the full-size original.

Reference:

- [Generated WebP files larger than JPEG](https://wordpress.org/support/topic/uploaded-jpg-are-under-1mb-generated-webp-3mb/)

### Public hooks receive imperfect data

Themes and plugins sometimes pass numeric strings, unexpected attribute types,
or incomplete image metadata through otherwise standard WordPress filters.
Strict callback signatures have caused fatal errors on production pages.

Requirements:

- Treat every public hook argument as untrusted input.
- Normalize scalar attachment IDs and attribute arrays before use.
- Return the original value for every unsupported shape or context.
- Catch conversion and rendering failures at the plugin boundary.
- Never turn an optimization failure into a frontend error.

References:

- [Fatal TypeError on some pages](https://wordpress.org/support/topic/fatal-typeerror-on-some-pages-in-modern-image-formats-2-7-0/)
- [Fatal error after version 2.7.0](https://wordpress.org/support/topic/fatal-error-after-2-7-0/)

### Rendering coverage and compatibility are different goals

Images rendered by core APIs are tractable. Raw theme markup, CSS backgrounds,
page-builder data, translated media, CDN rewrites, and remote object storage may
not be. A full-page output buffer can increase response time and alter markup.

Requirements:

- Do not convert during a frontend request.
- Do not buffer and parse the full response.
- Start with `wp_get_attachment_image` and `wp_content_img_tag`.
- Use WordPress HTML APIs and preserve the original `<img>` byte-for-byte where
  possible.
- Skip an existing `<picture>` to avoid competing with another optimizer.
- Keep URL-returning APIs and Open Graph metadata on the original format.
- Add block backgrounds, CDN adapters, and cache purges only behind tested,
  narrow integrations.

References:

- [Converted files are not displayed](https://wordpress.org/support/topic/the-converted-files-are-not-displayed/)
- [WPML Media compatibility report](https://wordpress.org/support/topic/not-working-with-wpml-media-translation/)
- [Open Graph does not support WebP](https://wordpress.org/support/topic/open-graph-protocol-does-not-support-webp/)
- [Picture elements and LCP preloading](https://github.com/WordPress/performance/issues/1312)

### Conversion must respect finite hosting resources

AVIF encoding has caused multi-gigabyte memory usage and upload failures in
profile-photo flows. Shared hosting cannot be treated like a worker cluster.

Requirements:

- Estimate decoded pixel memory before loading an image.
- Process one attachment in a bounded job and release editor instances early.
- Enforce a time budget and use a lock with stale-lock recovery.
- Back off AVIF independently while allowing WebP to continue.
- Do not intercept temporary crop and avatar flows that do not yet represent a
  stable media-library attachment.

References:

- [AVIF memory usage report](https://wordpress.org/support/topic/memory-leak-14/)
- [BuddyPress profile photo conflict](https://wordpress.org/support/topic/conflict-with-buddypress-18/)
- [PeepSo avatar upload error](https://wordpress.org/support/topic/causes-error-500-on-peepso-avatar-upload/)

### Filesystem support and web serving are separate capabilities

A server can encode a file correctly and still serve it with the wrong MIME
type. Cache and CDN layers can also delay or rewrite a newly-created companion.

Requirements:

- Report encoder support separately from observed serving problems.
- Use explicit variant URLs in `<picture>` instead of same-URL negotiation.
- Do not modify server configuration automatically.
- Expose actions after generation and deletion for cache integrations.
- Keep an original fallback even when a CDN has stale state.

References:

- [Incorrect MIME types from the server](https://wordpress.org/support/topic/image-type-3/)
- [CDN cache after manual conversion](https://wordpress.org/support/topic/clear-update-server-as-well-as-cdn-cache-on-manual-convert-from-media-library/)

## Acceptance rules

The following invariants apply to every release:

1. Activating, deactivating, rebuilding, or changing quality cannot break an
   original media URL.
2. A failed or unsupported conversion returns the original image and does not
   emit a PHP warning to the visitor.
3. Every generated file is traceable to one attachment, one source size, one
   format, and one generation profile.
4. Repeating the same operation produces the same paths and metadata.
5. Frontend requests never perform image encoding.
6. A modern source is served only after its file has been validated.
7. A modern source is never served when it is not smaller than its original.
8. Plugin settings contain one product choice: the quality preset.

