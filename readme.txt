=== WarmPilot ===
Contributors: yota-x
Tags: cache, cache warmer, cache preload, crawler, performance
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Warm WordPress pages concurrently, crawl internal links and sitemaps, and run independent scheduled warming tasks.

== Description ==

WarmPilot is a cache warmer and internal crawler for WordPress. It sends controlled requests to configured pages so page caches, reverse proxies, CDNs, and server-side caches can be populated before visitors arrive.

The plugin can start from one or more entry URLs and XML sitemaps, discover internal links and pagination, preload selected asset types, and display live job reports.

= Main features =

* Configurable parallel workers using the HTTP library bundled with WordPress.
* Manual warming with live progress and per-URL results.
* Independent cron tasks with their own complete settings.
* Entry URLs and XML sitemap discovery.
* Recursive internal-link crawling with configurable depth.
* Wildcard include and exclude rules.
* URL deduplication within each warming job.
* Custom request headers, timeout, retries, and retry delay.
* Optional second request to measure the warmed response.
* Collection of every response header whose name contains "cache".
* Optional preloading of scripts, styles, fonts, and images.
* Job logs, error-only views, CSV export, and log retention.
* WordPress traffic cron and system cron modes.

= Crawl depth =

* `-1` disables HTML link discovery. Entry URLs and sitemap URLs are still processed.
* `0` enables unlimited recursive crawl depth.
* A positive number limits recursive discovery to that depth.

= Cron execution modes =

WarmPilot supports two ways to trigger scheduled WordPress events:

1. **WordPress traffic cron**: no server configuration is required, but due tasks are checked when the site receives requests. Start times may be delayed on low-traffic sites.
2. **System cron**: recommended for predictable schedules. Configure the server to run `wp-cron.php` regularly and set `DISABLE_WP_CRON` to `true` in `wp-config.php`.

Generic PHP CLI example:

`* * * * * cd /path/to/wordpress && /usr/bin/php wp-cron.php >/dev/null 2>&1`

Calling `wp-cron.php` every minute does not run every WarmPilot task every minute. WordPress only runs tasks whose scheduled time is due.

= Cache compatibility =

WarmPilot is not tied to one cache vendor. It records all response headers whose names contain the word `cache`, case-insensitively. This can include headers such as `CF-Cache-Status`, `X-Cache`, `X-FastCGI-Cache`, and `Cache-Control`.

= Network requests and privacy =

WarmPilot does not include telemetry, advertising, or user tracking. It does not send data to Yota-X or another external service.

The plugin makes HTTP requests only as part of user-configured warming jobs. By default, crawling is restricted to the configured site domain and its subdomains. Request URLs, response metadata, timings, statuses, and errors are stored in the WordPress database for job reporting and are removed according to the configured log-retention settings.

= System requirements =

WarmPilot requires PHP 8.1 or newer with the DOM, libxml, and SimpleXML extensions.

Workers are limited to 1–30, request timeout to 1–300 seconds, and retries to 0–10. The default URL limit is 5,000. Setting the URL limit to `0` disables that limit.

= Development and testing =

The automated test suite is organized under the `test` directory:

* `test/Unit` contains isolated PHP tests for settings, URL normalization, HTTP helpers, retry rules, and cron expressions.
* `test/Integration` contains crawler integration tests.
* `test/Js` contains Node.js tests for the admin script.
* `test/Support` and `test/bootstrap.php` provide reusable test infrastructure and WordPress function stubs.

Install development dependencies with `composer install`, then run all PHP and JavaScript tests with `composer test`. Run syntax checks with `composer test:syntax`.

Create a verified WordPress release archive with `composer build`. Composer runs syntax checks and all tests first, then a PHP builder writes `dist/warmpilot-{version}.zip`. The ZIP contains only the runtime plugin files under a single `warmpilot/` directory; development dependencies, tests, and build configuration are excluded.

== Installation ==

1. Upload the plugin ZIP through **Plugins > Add New > Upload Plugin**.
2. Activate **WarmPilot – Cache Warmer & Cron Crawler**.
3. Open **Tools > WarmPilot**.
4. Configure entry URLs, sitemap URLs, workers, timeout, and URL rules.
5. Run a manual warming job or create independent cron tasks.

For reliable scheduled execution, configure a system cron to call `wp-cron.php` and follow the instructions shown in the **Cron tasks** tab.

== Frequently Asked Questions ==

= Does WarmPilot require a specific caching system? =

No. WarmPilot works with WordPress cache plugins, reverse proxies, FastCGI cache, hosting caches, CDNs, and other cache layers. It does not require a specific provider or cache header.

= Does the plugin visit the same URL repeatedly during one job? =

No. Each job stores a normalized URL key and prevents the same URL from being queued more than once. A second request may still be made when the optional warmed-response verification setting is enabled.

= Are workers separate PHP processes? =

No. Workers are concurrent HTTP connections within one PHP process using the HTTP library bundled with WordPress. Different cron profiles may run independent jobs, while duplicate simultaneous runs of the same profile are prevented.

= Why can a scheduled job start late? =

In WordPress traffic-cron mode, WordPress checks due events when requests reach the site. Use system cron mode for more predictable execution.

= What happens when a cron task is still running at its next scheduled time? =

WarmPilot does not start another simultaneous job for the same cron profile. Other profiles can continue independently.

= What data is removed by log retention? =

When a completed job is removed, its normal report and its error-only view are removed together because both use the same stored job items. Running jobs are not deleted by retention.

= Does deleting the plugin remove its data? =

By default, WarmPilot preserves its settings, cron profiles, jobs, and logs when the plugin is deleted. To remove everything, enable **Permanently delete all WarmPilot data when the plugin is deleted** under **Tools > WarmPilot > Data & Uninstall** before deleting the plugin. This option affects plugin deletion, not ordinary deactivation, and cannot be undone.

== Changelog ==

= 1.0.0 =

* Prepared the initial WordPress.org release package.
* Completed WarmPilot branding across request headers, exported reports, storage identifiers, AJAX actions, cron hooks, and admin assets.
* Added class-based plugin structure and automated PHP and JavaScript tests.
* Added manual and scheduled cache warming with parallel workers.
* Added sitemap and internal-link discovery, URL deduplication, retries, asset preloading, and cache-header reporting.
* Added job logs, error-only views, CSV export, log retention, and cron environment diagnostics.

== Upgrade Notice ==

= 1.0.0 =

Initial public release of WarmPilot by Yota-X.
