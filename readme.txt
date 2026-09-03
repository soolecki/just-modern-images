=== Just Modern Images ===
Tags: webp, avif, images, performance, optimization
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.11.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate and serve smaller WebP and AVIF images automatically while keeping every original file intact.

== Description ==

Just Modern Images is built for people who want modern image formats without turning image optimization into another project.

Activate the plugin and it will:

* verify what the server can really encode;
* process new JPEG and PNG Media Library uploads in the background;
* scan existing Media Library images automatically;
* create WebP and AVIF companions only when they are valid and smaller;
* serve complete responsive image sets through the `picture` element;
* keep the original image as the permanent fallback;
* prioritize new, manually selected, and currently needed images.

The plugin never replaces WordPress attachment filenames, MIME types, or core image metadata. Deactivation therefore restores normal WordPress image markup immediately.

The only preference is image quality: Economy, Standard, High, or Ultra. Server capability, conversion progress, and failure handling are automatic.

The settings screen shows separate library-review and ready-image progress. Media Library rows and attachment details show whether an image is ready, queued, partially available, or needs attention. Administrators can move individual images or a bulk selection to the front of the queue.

When an image needs attention, the settings screen and Media Library show a plain-language explanation together with a stable diagnostic code. A server check can also be run directly from the settings screen. The Activity log keeps the latest 50 processing events with before-and-after library counts, server identifiers, stop reasons, and per-image results. Administrators can download the same privacy-safe history as JSON for troubleshooting.

= Designed to fail safely =

Image encoding varies widely between hosting providers. Just Modern Images performs a real capability probe and validates every generated file before it becomes eligible for use. Empty, corrupt, oversized, incomplete, or undecodable output is discarded.

WebP and AVIF are independent. If one encoder is missing or unstable, the other format continues to work. Repeated encoder failures temporarily pause only the affected format.

On installations served by more than one application server, capability results and encoder health are kept separately for each server environment. Generated companions use immutable names, so a verified active file is never overwritten during a quality refresh. Replaced files remain available for seven days to protect cached HTML before they are cleaned up.

= No frontend conversion =

Conversion never runs while a visitor is waiting for a page. Frontend filters only read a small attachment manifest and leave the original HTML unchanged when a complete modern source set is not ready.

When WordPress renders a page, attachments used by that response can move ahead of the background library scan. The plugin records only the attachment ID needed for processing. It does not store visitors, page URLs, view counts, cookies, or analytics data. Fully cached responses may bypass WordPress and therefore do not provide this priority signal.

= Privacy =

The plugin processes images on your own server and does not send diagnostic data by default. Private test builds may contain a diagnostic receiver. Sending starts only after an administrator selects Send diagnostic data on the Activity log screen.

An enabled test report contains the WordPress site name and public homepage address, WordPress, PHP, and plugin versions, image-library availability, format capability states, queue snapshots, aggregated processing results, and redacted fatal errors originating in the plugin directory. It does not contain image files, Media Library filenames or attachment IDs, visited page addresses, user details, email addresses, cookies, or administrator credentials. Reporting can be disabled at any time; disabling it removes reports waiting locally.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install its ZIP through Plugins > Add Plugin.
2. Activate Just Modern Images.
3. Leave the default Standard quality selected, or choose another profile under Settings > Just Modern Images.

Existing images are queued automatically. WordPress processes the queue through WP-Cron in small, retry-safe jobs.
Each cron request uses an adaptive worker budget. Fast servers continue with more images, while the worker yields before the safe time, memory, or per-run image limit is exhausted.
If a one-time worker event disappears after an interrupted PHP request, the plugin restores it automatically during the next WordPress initialization.

== Frequently Asked Questions ==

= Does the plugin delete or replace my original images? =

No. Original JPEG and PNG files and WordPress attachment metadata remain unchanged. Modern files are separate companions.

= What happens when I deactivate the plugin? =

WordPress immediately returns to its original image markup and URLs. No database migration or thumbnail regeneration is required.

= What if my server cannot create AVIF? =

AVIF is skipped. WebP continues when available, and the original image always remains the fallback.

= Are existing Media Library images converted? =

Yes. Activation starts a cursor-based background scan that can resume after timeouts or missed cron runs. You can queue the scan again from the status screen.

= Why was a modern file not generated? =

The source may be unavailable locally, the server may not support the format reliably, the image may exceed the safe memory budget, or the generated file may be no smaller than the original. The settings screen and Media Library show the most relevant explanation and diagnostic code.

= What happens when I change image quality? =

The Media Library is refreshed in the background. Existing valid modern files remain active until verified replacements are ready. Images selected manually, newly uploaded, or needed by a frontend response are processed before the remaining library backfill.

= How much work happens during one cron request? =

The worker measures how long completed images take and starts another only when a safe reserve remains. By default it may use up to 20 seconds and 50 images across all Just Modern Images events in one request. It also yields at 80% of the PHP memory limit and leaves five seconds before the PHP execution limit. These are internal safety limits, not settings that site owners need to manage.

= Does it work with a CDN? =

The plugin uses normal WordPress upload URLs and provides a filter for CDN integrations. A CDN or offload plugin must make generated companion files available just like other files in the uploads directory.

= Does it optimize CSS background images? =

Not in this release. The first release intentionally covers attachment images rendered through standard WordPress APIs without buffering or rewriting the entire page.

== Changelog ==

= 0.11.6 =

* Added explicit administrator opt-in for diagnostic reports from private test installations.
* Included the site name and public homepage address so reported problems can be verified on the correct site.
* Added a bounded, retry-safe reporting outbox with short timeouts, sender locking, and exponential backoff.
* Added real cron-frequency, scheduling-delay, worker-throughput, per-image timing, and memory measurements.
* Removed attachment IDs, filenames, visitor information, and page addresses from remote reports.
* Added redacted fatal-error capture limited to errors originating in the plugin directory.
* Added a separate password-protected PHP receiver and fleet dashboard for private testing; it is not included in the plugin ZIP.

= 0.11.5 =

* Prevented delayed attachment events from moving an already settled image back to the waiting state.
* Preserved the last settled state and queue source while an image is intentionally reprocessed.
* Added a bounded Activity log with before-and-after library and queue snapshots for the latest 50 processing events.
* Added per-image state transitions, processing results, server identifiers, format capability states, and worker stop reasons to the history.
* Added a privacy-safe JSON diagnostic report that administrators can download from the Activity log.
* Kept diagnostic collection isolated so a logging problem cannot interrupt image processing or retain a worker lock.

= 0.11.4 =

* Restored missing or overdue library worker events automatically.
* Recovered abandoned worker locks, including invalid future timestamps caused by server clock differences.
* Separated monotonic data migrations from release numbers so mixed OPcache revisions cannot repeatedly reset current queue state.
* Kept capability profiles intact during ordinary plugin updates.
* Preserved the most recent worker metrics when a new scan is requested.
* Added the next worker event, lock state, and last observed worker code version to diagnostics.
* Added a clear warning when cron activity is visible but no current worker run is reported.

= 0.11.3 =

* Added an adaptive cron worker that continues processing while safe time and memory remain.
* Applied one shared workload budget across all image events dispatched in the same cron request.
* Added a shared worker lock so overlapping cron calls on clustered sites do not multiply image load.
* Added the most recent worker workload and stop reason to the settings screen.
* Preserved manual, upload, and request-based priority when a worker yields and requeues an image.
* Fixed early translation loading notices introduced by WordPress 6.7.

= 0.11.2 =

* Prevented critical errors when different application servers temporarily use cached plugin components from adjacent releases.
* Added compatibility between old and new manifest cleanup APIs during rolling updates.
* Deferred an on-demand capability probe safely when the current request still uses the older probe component.
* Added a visible notice when a server is waiting to reload the current plugin version.

= 0.11.1 =

* Added plain-language processing diagnostics and stable reason codes to Settings and Media Library views.
* Added an on-demand real AVIF and WebP server check.
* Fixed capability checks in cron, CLI, and activation requests where the WordPress temporary-file API is not loaded by default.
* Kept capability and encoder-health results separate for every server environment in a cluster.
* Switched generated companions to immutable names that do not replace an active file on shared storage.
* Added recovery of complete files left by an interrupted run and explicit database-publication failure handling.
* Retained replaced variants for seven days so cached pages can continue loading their previous URLs.

= 0.11.0 =

* Replaced quality radio buttons with a compact profile selector.
* Added graphical library progress and clearer server and queue status.
* Added attachment status, Media Library filters, and single and bulk priority actions.
* Added privacy-friendly request-based prioritization without visitor tracking.
* Kept last known good variants active during unsuccessful quality refreshes.
* Added per-image retry backoff and clearer pending, partial, stale, and failed states.

= 0.10.0 =

* Replaced the prototype with an original, fail-safe conversion pipeline.
* Added verified WebP and AVIF capability checks.
* Added atomic file finalization, validation, memory limits, and larger-file rejection.
* Added resumable background processing for new and existing attachments.
* Added complete responsive `picture` rendering with an unchanged original fallback.
* Added four quality profiles and a compact status screen.
* Added cleanup on attachment deletion and plugin uninstall.
