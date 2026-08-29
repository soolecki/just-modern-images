=== Just Modern Images ===
Tags: webp, avif, images, performance, optimization
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.10.0
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
* keep the original image as the permanent fallback.

The plugin never replaces WordPress attachment filenames, MIME types, or core image metadata. Deactivation therefore restores normal WordPress image markup immediately.

The only preference is image quality: Economy, Standard, High, or Ultra. Server capability, conversion progress, and failure handling are automatic.

= Designed to fail safely =

Image encoding varies widely between hosting providers. Just Modern Images performs a real capability probe and validates every generated file before it becomes eligible for use. Empty, corrupt, oversized, incomplete, or undecodable output is discarded.

WebP and AVIF are independent. If one encoder is missing or unstable, the other format continues to work. Repeated encoder failures temporarily pause only the affected format.

= No frontend conversion =

Conversion never runs while a visitor is waiting for a page. Frontend filters only read a small attachment manifest and leave the original HTML unchanged when a complete modern source set is not ready.

= Privacy =

The plugin processes images on your own server. It does not contact an external service, create an account, track visitors, or collect usage data.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install its ZIP through Plugins > Add Plugin.
2. Activate Just Modern Images.
3. Leave the default Standard quality selected, or choose another profile under Settings > Just Modern Images.

Existing images are queued automatically. WordPress processes the queue through WP-Cron in small, retry-safe jobs.

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

The source may be unavailable locally, the server may not support the format reliably, the image may exceed the safe memory budget, or the generated file may be no smaller than the original. These are safe skip conditions.

= Does it work with a CDN? =

The plugin uses normal WordPress upload URLs and provides a filter for CDN integrations. A CDN or offload plugin must make generated companion files available just like other files in the uploads directory.

= Does it optimize CSS background images? =

Not in this release. The first release intentionally covers attachment images rendered through standard WordPress APIs without buffering or rewriting the entire page.

== Changelog ==

= 0.10.0 =

* Replaced the prototype with an original, fail-safe conversion pipeline.
* Added verified WebP and AVIF capability checks.
* Added atomic file finalization, validation, memory limits, and larger-file rejection.
* Added resumable background processing for new and existing attachments.
* Added complete responsive `picture` rendering with an unchanged original fallback.
* Added four quality profiles and a compact status screen.
* Added cleanup on attachment deletion and plugin uninstall.
