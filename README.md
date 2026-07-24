# WarmPilot

<p align="center">
  <img src="warmpilot-preview-1280x640-full.png" alt="WarmPilot — Cache Warmer & Cron Crawler" width="960">
</p>

WarmPilot is a WordPress cache warmer and internal crawler. It sends controlled concurrent requests to configured pages so page caches, reverse proxies, CDNs, hosting caches, and server-side caches can be populated before visitors arrive.

## Features

- Concurrent cache warming with configurable workers
- Manual runs with live progress and per-URL results
- Independent scheduled tasks
- Entry URL and XML sitemap discovery
- Recursive internal-link crawling
- Configurable crawl depth and URL limits
- Wildcard include and exclude rules
- Per-job URL normalization and deduplication
- Custom request headers, timeouts, retries, and delays
- Optional second request to measure the warmed response
- Collection of response headers containing `cache`
- Optional script, stylesheet, font, and image preloading
- Job logs with success and error filters
- CSV report export
- Configurable log retention
- WordPress traffic cron and system cron support
- Optional complete data removal when uninstalling

## Requirements

- WordPress 6.2 or newer
- PHP 8.1 or newer
- PHP extensions:
  - DOM
  - libxml
  - SimpleXML

For development and release builds:

- Composer
- Node.js
- PHP ZIP extension, or a PHP installation where the bundled ZIP extension can be loaded

## Installation

1. Download or build `warmpilot-<version>.zip`.
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and activate WarmPilot.
4. Open **Tools → WarmPilot**.
5. Configure the entry URLs, sitemap URLs, crawl rules, and request settings.

## Scheduled runs

WarmPilot supports two WordPress cron modes:

- **WordPress traffic cron** requires no server configuration, but scheduled tasks may start late on low-traffic sites.
- **System cron** provides more predictable execution by calling `wp-cron.php` regularly.

Example system cron entry:

```cron
* * * * * cd /path/to/wordpress && /usr/bin/php wp-cron.php >/dev/null 2>&1
```

When using system cron, add the following to `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

Calling `wp-cron.php` every minute does not run every WarmPilot task every minute. WordPress starts only events whose scheduled time is due.

## Development

Install dependencies:

```bash
composer install
```

Run all PHP and JavaScript tests:

```bash
composer test
```

Run syntax checks:

```bash
composer test:syntax
```

Run individual suites:

```bash
composer test:php
composer test:js
```

The test suite is organized under [`test`](test):

- `test/Unit` — settings, URL normalization, HTTP helpers, retries, cron expressions, uninstall behavior, and build manifest
- `test/Integration` — sitemap and HTML crawler behavior
- `test/Js` — admin JavaScript checks
- `test/Support` — shared test helpers

## Release build

Create a validated WordPress installation package:

```bash
composer build
```

The build process:

1. Runs PHP and JavaScript syntax checks.
2. Runs all automated tests.
3. Reads the plugin version from `warmpilot.php`.
4. Packages only runtime files.
5. Verifies the generated archive contents.
6. Prints the file size and SHA-256 checksum.

The resulting archive is written to:

```text
dist/warmpilot-<version>.zip
```

The release includes only the Composer runtime autoloader, the domain parser, its
IDN polyfills, and the bundled Public Suffix List. Tests, build scripts, Composer
lock file, PHPUnit configuration, and development-only dependencies are
excluded from the WordPress package. `composer.json` is included so the
bundled runtime dependencies remain transparent and reproducible.

The bundled Public Suffix List is used locally to determine registrable domains
without making network requests from WordPress. Refresh it before preparing a
release:

```bash
composer update:psl
composer build
```

## Third-party components

The installable ZIP bundles PHP Domain Parser, Symfony Intl polyfills, the
Composer runtime autoloader, and the Public Suffix List. Their versions,
upstream sources, and license locations are documented in
[`THIRD-PARTY-NOTICES.txt`](THIRD-PARTY-NOTICES.txt). End users do not need
Composer or any build tools.

## Data and uninstall

By default, deleting WarmPilot preserves its settings, scheduled tasks, jobs, and logs.

To remove all plugin data:

1. Open **Tools → WarmPilot → Data & Uninstall**.
2. Enable the permanent data removal option.
3. Save the setting.
4. Delete the plugin from the WordPress Plugins screen.

Ordinary plugin deactivation never removes data.

## WordPress.org readme

[`readme.txt`](readme.txt) contains the WordPress Plugin Directory metadata and documentation. This `README.md` is intended for GitHub.

## License

WarmPilot is licensed under the [GNU General Public License v2.0 or later](LICENSE.txt).

Copyright © 2026 Yota-X.
