# Architecture

## Supported baseline

- WordPress 6.5 or newer.
- PHP 7.4 or newer.
- JPEG and PNG attachment sources.
- WebP and AVIF companions when a verified WordPress image editor supports them.

WordPress 6.5 is the baseline because it introduced core AVIF support while
remaining old enough to cover a meaningful share of maintained sites. The
plugin will be tested against the current WordPress release and the oldest
supported PHP/WordPress combination.

## Safety boundary

WordPress owns original uploads and `_wp_attachment_metadata`. Just Modern
Images reads them but does not replace their filenames, MIME types, dimensions,
or size records.

The plugin owns:

- companion files ending in `.webp` and `.avif`;
- attachment meta under `_jmi_manifest`;
- queue, capability, lock, and diagnostic options prefixed with `jmi_`;
- HTML added around eligible attachment images.

This boundary provides rollback by deactivation and prevents plugin state from
becoming a dependency of the Media Library.

## Components

### Bootstrap

Loads the plugin, registers lifecycle hooks, and refuses to initialize when the
runtime does not meet the minimum requirements. It must not perform expensive
work during plugin loading.

### Quality profiles

One stored value selects a named profile:

| Profile | Intended use | WebP | AVIF |
| --- | --- | ---: | ---: |
| Economy | Smallest practical files | 68 | 38 |
| Standard | Default balance | 78 | 48 |
| High | Image-led sites | 86 | 58 |
| Ultra | Maximum fidelity | 94 | 72 |

These are initial values. They must be validated on photographic, illustrated,
transparent, and text-heavy fixtures before the first stable release.

Changing a profile invalidates the processing status, not the original files or
the last verified companions. The background queue refreshes companions in
place and the renderer keeps using the last known good generation until its
replacement has passed validation.

### Capability probe

The probe first asks WordPress whether an eligible editor supports a MIME type,
then performs a small real conversion. Results are cached against the editor,
PHP version, and relevant library version.

WebP and AVIF have independent states:

- `available`
- `unavailable`
- `temporarily_disabled`
- `unknown`

AVIF alpha support is probed separately. Failure in one format never prevents
the other format from being generated or served.

### Source inventory

For an attachment, the inventory contains the full-size source and every valid
entry from core attachment metadata. A source record includes:

- absolute source path;
- relative upload path;
- original filename and MIME type;
- width and height;
- file size and modification time;
- logical size name such as `full`, `thumbnail`, or `1536x1536`.

Paths must resolve inside the current uploads directory. URLs are derived from
upload paths; URLs are never converted back into local paths.

### Manifest

`_jmi_manifest` uses an explicitly versioned schema. Each attachment record
contains a source signature, active quality profile, completion time, and a
variant map keyed by source size and MIME type.

Each variant includes its relative path, MIME type, dimensions, byte size,
status, and generation timestamp. Expected non-file outcomes such as
`unsupported`, `not_smaller`, and `transparent_alpha_unsafe` are retained for
diagnostics and to prevent repeated work.

The manifest is written only after files have been finalized.

### Converter

For every source and supported target format:

1. Confirm the source is a readable JPEG or PNG within uploads.
2. Confirm dimensions fit the current memory budget.
3. Acquire an attachment lock.
4. Create the editor and set the selected quality.
5. Save to a unique temporary path in the destination directory.
6. Validate bytes, MIME type, dimensions, decoding, and alpha safety.
7. Discard the file when it is not smaller than the exact source.
8. Atomically replace the deterministic companion path.
9. Update the manifest and emit a cache-integration action.
10. Release memory and the lock in a `finally` path.

A previous valid companion remains in use until its replacement has passed all
validation.

### Queue

New attachments are scheduled after WordPress has completed core metadata.
Activation and quality changes schedule a cursor-based scan of existing image
attachments.

Jobs are:

- idempotent;
- bounded by attachment count and wall time;
- guarded by expiring locks;
- safe to retry after a fatal request or missed cron event;
- observable from one small status panel.

The queue has four lanes, in descending order: a manual Media Library request,
a new upload, an attachment needed by a frontend response, and the background
library scan. A higher-priority request may move an already scheduled job
forward. Failed jobs use attachment-level exponential backoff, while repeated
encoder failures pause only the affected output format.

Frontend rendering may enqueue stale work but never performs it synchronously.
The signal is deliberately ephemeral: one request collects only attachment IDs
and stores no visitor, page URL, counter, cookie, or analytics record. A small
per-response limit prevents a large page from flooding WP-Cron.

### Renderer

The renderer accepts an attachment ID and existing image HTML. It returns the
input unchanged unless a validated manifest contains at least one usable
companion. A manifest from the preceding quality profile remains usable while
its replacement is generated.

Eligible images become:

```html
<picture>
  <source type="image/avif" srcset="...">
  <source type="image/webp" srcset="...">
  <img ...>
</picture>
```

AVIF is offered before WebP. The original `<img>` remains the fallback. Existing
`srcset`, `sizes`, loading behavior, accessibility attributes, classes, and data
attributes are preserved.

The first integration points are `wp_get_attachment_image` and
`wp_content_img_tag`. The renderer fails open for invalid IDs, unexpected hook
arguments, existing `<picture>` markup, admin requests, feeds, REST responses,
emails, and head metadata.

### Admin experience

The settings screen has one preference: a select containing Economy, Standard,
High, and Ultra. Standard is the default.

The same screen can show operational information without adding configuration:

- WebP and AVIF availability;
- separate ready and reviewed progress;
- ready, partial, waiting, stale, and failed attachment counts;
- latest queue activity;
- a resumable library scan action.

The Media Library list has a compact status column, status filters, a signed
single-image priority action, and a bulk priority action. Attachment details
show the overall state plus separate WebP and AVIF results.

Actions use capability checks, nonces, bounded background jobs, and explicit
confirmation for deletion.

## Compatibility policy

| Scenario | Initial behavior |
| --- | --- |
| Unsupported WebP or AVIF encoder | Skip only that format and explain why |
| Invalid or larger generated file | Delete temporary file and use original |
| Existing `<picture>` | Leave unchanged |
| CDN rewrites attachment URLs | Use WordPress-generated base URLs and expose integration hooks |
| Offloaded source missing locally | Skip conversion and keep remote original |
| WPML/translated attachment | Process each attachment independently |
| Avatar or profile crop | Ignore until it becomes a stable attachment |
| CSS background | Original in first release; add narrow block support after tests |
| Hardcoded image URL | Original; no full-page output rewriting |
| Attachment deletion | Remove companions recorded in that attachment's manifest |
| Plugin deactivation | Stop rendering and processing; originals work immediately |
| Plugin uninstall | Remove settings and metadata; file cleanup requires a deliberate tool action |

## Release gates

Before a public beta:

- PHPUnit tests cover naming, manifests, normalization, rendering, and failures.
- Integration tests run against GD and Imagick where available.
- Fixture tests cover JPEG, RGB PNG, palette PNG, transparent PNG, animated
  input, very large dimensions, EXIF orientation, corrupt input, and filenames
  with spaces and non-ASCII characters.
- Browser checks cover classic themes, block themes, responsive `srcset`, lazy
  loading, LCP/fetch priority, galleries, WooCommerce, and common page builders.
- Plugin Check and WordPress Coding Standards pass.
- Activation, deactivation, rebuild, deletion, timeout, and interrupted-job
  recovery are tested on a copy of a real media library.
