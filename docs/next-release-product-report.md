# Just Modern Images: next release product report

This report is based on the fleet export generated on 4 September 2026 at
10:52 CEST, the current 0.12.0 code and earlier compatibility findings from
the test sites. It is a release plan, not an implementation specification.

## Executive recommendation

The conversion engine is doing useful work on every installation where the
worker is running. The fleet does not show a reason to broaden the plugin with
universal CSS rewriting yet. It does show three areas that should be fixed
before adding features:

1. stop redundant recovery scans and make a single delayed item independently
   retryable;
2. make worker stalls and normal completion unambiguous;
3. harden frontend delivery around lazy-loading and caching plugins.

The next public-facing milestone should therefore stay focused on reliability
and clarity. Automatic support for arbitrary `background-image` declarations
should not be enabled in the core plugin. A narrow, attachment-ID-based API can
be developed separately and used as the foundation for future block and page
builder integrations.

## What the current fleet says

| Site | State in the latest report | Interpretation |
| --- | --- | --- |
| Univio | 2,700 ready, 64 partial, 2,951 waiting; 305 items became ready in one hour | Healthy and actively converting a large library. One publish conflict was isolated rather than stopping the queue. |
| j-labs | 1,710 ready, 119 partial, 1 waiting | Conversion completed, but recovery repeatedly restarts the full library for one unsettled item. After the first complete pass, 85 runs made 4,216 attempts and generated no files. |
| Commplace | 1,230 ready, 508 partial, 0 waiting | The refresh is active. Many outputs are deliberately rejected as `not_smaller`, so a falling `ready` count does not mean the worker stopped. The current label makes a valid optimization decision look like a regression. |
| Defence24 Days | 251 ready, 55 partial, 6 waiting, 1,051 skipped | It was progressing, but stopped reporting at 10:09. Several GD encode warnings occurred. The export cannot determine whether cron stopped, a worker lock remained, or reporting stopped. |
| Cyber24 Days | 164 ready, 21 partial, 10 waiting, 4 attention | Progress is real, but scan gaps reached about 21 minutes despite a roughly 27-second observed cron interval. This strongly suggests unreported lock contention or interrupted workers. Encode warnings affected individual images. |
| Space24 Day | 67 ready, 4 partial, 0 waiting | Complete and healthy. The four partial items are `not_smaller`, not unfinished work. |
| ZOO Wrocław | 680 ready, 423 partial, 1,520 waiting | 0.12.0 did start and moved the cursor to 7,559. Frequent per-image demand events displaced scan events from the bounded history, so the report can show current progress without preserving the run that produced it. |

The fleet also confirms that the same Multisite network can have different cron
health per site. Defence stopped reporting while Cyber continued. Network-level
health must never be inferred from the main site or another subsite.

## Confirmed defects and design weaknesses

### 1. Recovery can create an endless full-scan loop

`ensure_dormant_scan()` is intended to check at most hourly. However,
`release_worker_lock()` deletes the health-check timestamp after every worker
run. If one item remains pending, the next cron request starts another complete
scan immediately. j-labs demonstrates the result clearly.

The health timestamp and worker lock have separate responsibilities and must
not be cleared together. More importantly, a complete inventory scan must not
be the retry mechanism for one image.

### 2. A lock can hide the actual reason for a pause

Lock contention updates internal status and schedules another event, but does
not create an activity event. A PHP process killed during an image editor call
can leave the worker lock present for up to five minutes. Repeated contention
or hard timeouts can therefore look like an unexplained gap even when cron is
healthy.

The lock needs an owner token, acquisition time and renewable lease. A worker
must only release its own lock. Contention, stale-lock takeover and a worker
that starts but never reaches `finally` need separate, reportable outcomes.

### 3. The status model confuses completion with format coverage

`not_smaller` is a successful optimization decision: serving the original is
better than creating a larger modern companion. It should count as processed,
not as unfinished. Similarly, `no_local_sources` is a completed inspection but
requires a different explanation from `not_smaller`.

The main progress indicator should answer one question: "Has Just Modern Images
finished checking this library?" A second, quieter metric can show modern-format
coverage. The main value reaches 100% only when the current run has examined all
eligible items, never because of rounding.

Suggested final language:

- **Finished — everything has been checked.**
- **1,710 images use modern files.**
- **119 originals were already the better choice.**
- **3 items could not be read locally.**

### 4. Report history loses the events needed to diagnose a stall

The local activity log keeps 50 mixed entries. A site with demand processing or
uploads can replace all scan history with attachment events. The receiver also
stores raw events rather than durable run summaries. ZOO is the current example.

Keep small separate ring buffers for worker runs, attachment exceptions and
heartbeats. In addition, report cumulative counters and the current run ID so a
central report remains useful even after detailed events expire.

### 5. Frontend compatibility is not yet sufficiently defensive

The previously observed Perfmatters output proves that another plugin can treat
the JMI `<picture>` wrapper as though it were still a bare `<img>` and place the
markup inside `data-src`. This is more important than adding a new image source
type because it can break visible page output.

JMI should recognise lazy-managed attributes (`data-src`, `data-srcset` and
their common equivalents), preserve the owning plugin's convention and add
matching `<source>` attributes only when it can do so safely. If the markup is
ambiguous, leave the original image unchanged. Add fixtures for Perfmatters and
representative cache/lazy-load plugins; do not use whole-page output buffering.

## Recommended scope

### Immediate reliability release

1. Separate the dormant-health throttle from worker-lock cleanup.
2. After a complete scan, schedule only unsettled attachment IDs with bounded
   exponential backoff. Do not restart the library.
3. Give each scan a stable run ID and record `inventory_started`,
   `inventory_completed`, `retry_scheduled` and `settled` timestamps.
4. Replace the timestamp-only worker lock with an owner-aware renewable lease.
5. Record lock contention, stale takeover and abandoned-run detection in both
   the local log and opt-in fleet report.
6. Re-probe an encoder after its cooldown before retrying affected media.
7. Aggregate repeated `encode_warning` outcomes by format, editor and safe error
   class. Keep image paths and visitor data out of telemetry.
8. Change the headline progress to processing completion and explain
   `not_smaller`, `no_local_sources` and unsupported formats as finished
   outcomes.
9. Update the numbers and the collapsed Background processing summary over a
   small authenticated REST/AJAX endpoint while the settings page is open.
10. Change plugin author metadata to **CLU Level Up**.

### Following compatibility release

1. Add a conservative lazy-loader compatibility layer and regression fixtures.
2. Requeue an attachment when WordPress regenerates or edits its metadata. The
   official `wp_generate_attachment_metadata` hook provides both attachment ID
   and create/update context, so this can be done without scanning the library.
3. Detect changes in the set of registered image sizes and schedule a bounded
   reconciliation instead of invalidating every manifest blindly.
4. Test URL matching with mapped domains, CDN host rewrites, query strings and
   subdirectory Multisite.
5. Add an explicit storage adapter boundary. Local, IIS and SMB/mapped storage
   remain built in; remote offload should only be supported through a verified
   adapter that can publish the generated file and return its public URL.
6. Make network activation and uninstall incremental for large Multisite
   networks so one request does not need to visit every site.
7. Provide a per-site Multisite health overview, while keeping queue state,
   quality and media manifests isolated per site.

## Background images

### Decision: do not rewrite arbitrary CSS automatically

A URL in `background-image` may come from a stylesheet, inline style, theme
option, custom field, page builder, generated CSS file, CDN or external host.
There is often no WordPress attachment ID, no reliable way to select the right
registered size and no safe place to invalidate the owner's cache. Parsing and
rewriting all those forms would materially increase the chance of visual
regressions and would conflict with the product promise.

CSS does provide the right browser-side primitive: `image-set()` can list AVIF,
WebP and the original with MIME type hints. The difficult part is not the CSS
syntax; it is proving which attachment and which size the original URL means.
The [CSS Images Level 4 specification](https://www.w3.org/TR/css-images-4/#image-set-notation)
defines this negotiation.

### Safe path forward

1. Add a documented helper/filter that accepts an attachment ID and size and
   returns a validated `image-set(...)` value. If no complete safe set exists,
   return the original URL.
2. First integrate only with WordPress block background support, where core
   stores both URL and attachment ID in
   `style.background.backgroundImage`. WordPress exposes the full block and its
   attributes through the [`render_block` filter](https://developer.wordpress.org/reference/hooks/render_block/),
   allowing a narrow implementation without scanning arbitrary CSS.
3. Keep the integration opt-in or experimental until it passes block themes,
   cached block output, inline-style escaping and browser fallback tests.
4. Implement page-builder support as named adapters, never as heuristics over
   the entire HTML response.

WordPress documents the attachment ID and URL structure in its
[block background support](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/#background).
That makes core block backgrounds a reasonable future candidate. Generic theme
CSS is not.

## Other areas worth protecting

Priority should be based on the likelihood of breaking the promise, not on the
number of features:

- **Media lifecycle:** upload, crop, rotate, replace, regenerate thumbnails,
  deletion and newly registered sizes.
- **Delivery ownership:** existing `<picture>` markup, lazy loading, CDN URL
  rewriting, HTML caches and block caches.
- **Remote media:** distinguish temporarily unavailable local files from
  permanently offloaded files; never loop forever on either.
- **Resource safety:** large dimensions, decompression memory estimates,
  transparent PNG, EXIF orientation and colour profiles.
- **Cleanup:** remove obsolete immutable variants incrementally, never during a
  frontend request and never by broad filename guesses.
- **Privacy:** fleet reporting remains explicit opt-in, documented and easy to
  disable. Public directory builds must not contain the private fleet endpoint
  or authentication secret.

WP-Cron is request-driven and does not run continuously, as the
[WordPress Plugin Handbook](https://developer.wordpress.org/plugins/cron/)
explains. JMI should tolerate irregular execution, but its panel must distinguish
"waiting for the next cron visit" from "cron is arriving, but this worker has
not completed a run."

## Product line without an incomplete free edition

The free plugin should remain the complete answer for ordinary site owners:
automatic AVIF/WebP generation, safe original fallback, queue recovery,
Multisite correctness, clear status and compatibility with common delivery
plugins. Reliability must never be a paid feature.

`DEV MODE` sounds like a switch inside the plugin rather than a product. A
clearer commercial name would be **Just Modern Images Studio** or **Just Modern
Images Toolkit**. "Studio" is easier to extend beyond developers; "Toolkit" is
more precise. The recommended structure is:

- **Just Modern Images** — complete automatic product;
- **Just Modern Images Studio** — developer, agency and fleet workflow.

Studio can add leverage without withholding core functionality:

- WP-CLI commands for status, validate, retry, rebuild and benchmark;
- a manifest inspector and explanation of why a specific image was or was not
  modernised;
- documented PHP helpers and filters, including the background-image helper;
- staging dry runs and compatibility diagnostics;
- named adapters for ACF fields, builders, CDN/offload providers and deployment
  workflows;
- central fleet health, alerts, version rollout and cross-site comparisons;
- performance and storage-savings reports suitable for client handover;
- exportable support bundles with secrets and personal data removed.

The advanced controls should be hidden until explicitly enabled. A developer
can inspect and override; an ordinary administrator should continue to see one
quality selector and a plain answer about whether the plugin is working.

## Acceptance criteria before the next fleet rollout

- A library with one delayed or permanently skipped item completes one full
  inventory pass and does not start another one automatically.
- `not_smaller` items allow processing completion to reach 100%.
- A killed worker becomes visibly abandoned, releases or loses its lease safely
  and resumes without duplicate publishing.
- Every Multisite subsite reports its own cron and worker freshness.
- No five-minute worker gap can occur without a recorded reason.
- Perfmatters fixtures never produce markup inside `data-src` and the original
  fallback always remains valid.
- Refreshing or leaving the settings page does not affect processing.
- Tests cover PHP 7.4 through the current supported PHP release, WordPress
  6.5 through 7.1, GD-only, Imagick, IIS/FastCGI, SMB/mapped storage, LiteSpeed
  and Multisite with mapped domains.

Only after these conditions hold should the fleet trial add block background
support. Generic CSS rewriting should remain out of scope unless real usage data
shows that the narrow API and adapters are insufficient.
