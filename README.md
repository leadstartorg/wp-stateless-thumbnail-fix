# wp-stateless-thumbnail-fix
Fixes broken WordPress thumbnails when using Google Cloud Storage with WP Stateless plugin. Regenerates thumbnails locally for URLs/streams, cleans temp files, and optionally purges Cloudflare. Handles last 30 days or specific attachments. See: https://scarff.id.au/blog/2020/wordpress-gcs-plugin-broken-thumbnails/

=== WP Stateless CLI Image Thumbnail Fix ===
Contributors: Jessica Kafor
Tags: mu-plugin, stateless, GCS, thumbnails, wp-cli
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fix broken thumbnails in WordPress when using GCS/Stateless Media.  
Regenerates thumbnails using CDN or GCS URLs without requiring local files. Optionally purges Cloudflare cache.  
Reference: [Scarff blog on broken thumbnails](https://scarff.id.au/blog/2020/wordpress-gcs-plugin-broken-thumbnails/)

== Description ==

The GCS/Stateless Media plugin allows storing WordPress media in Google Cloud Storage (GCS) for stateless environments like App Engine. By default, WordPress cannot generate thumbnails for URLs/streams.

This MU-plugin provides a WP-CLI command to safely regenerate thumbnails by:

1. Temporarily downloading the image from CDN/GCS.
2. Replacing the local file for regeneration.
3. Restoring the local file and optionally purging Cloudflare cache.
4. Supports fallback URLs in priority:
   - `sm_cloud['fileLink']` → Primary CDN URL (fastest, ephemeral).
   - `_wp_attached_file + CDN constant` → Reliable default for any attachment.
   - `sm_cloud['mediaLink']` → Last resort, direct GCS URL.

== Installation ==

1. Copy `wp-stateless-cli-image-thumbnail-fix.php` into `wp-content/mu-plugins/`.
2. Optional: define `WP_STATELESS_CDN_DOMAIN` in `wp-config.php`.
3. Optional: add Cloudflare API keys in `wp-config.php` if using `--purge-cf`.

== Usage ==

*Default: regenerate thumbnails from last 30 days:*
```bash
wp stateless-cli-image-thumbnail-fix

Specific attachment ID:
wp stateless-cli-image-thumbnail-fix --id=297355

Regnerate last 7 days:
wp stateless-cli-image-thumbnail-fix --days=7

Force Cloudflare purge:
wp stateless-cli-image-thumbnail-fix --purge-cf

Single attachment with Cloudflare purge:
wp stateless-cli-image-thumbnail-fix --id=297355 --purge-cf

Override CDN domain for this run:
wp stateless-cli-image-thumbnail-fix --cdn-domain=https://cdn.example.com

== Frequently Asked Questions ==

= Why are thumbnails missing? =
WordPress image editor functions like Imagick::readImage and realpath() do not work with URLs or PHP streams, which breaks thumbnail generation in stateless environments.

= Will this delete my local media? =
No. Temporary files are created for regeneration and automatically removed after processing.

== Changelog ==

= 1.0 =
Initial release
Supports CDN/GCS fallback URLs
Adds WP-CLI command for regenerating thumbnails
Optional Cloudflare cache purge
Supports attachments by date range or specific ID
