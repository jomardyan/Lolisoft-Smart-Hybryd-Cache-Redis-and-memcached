=== Smart Hybrid Cache ===
Contributors: jomardyan
Tags: cache, object cache, redis, memcached, performance
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Persistent object caching with Redis or Memcached, isolated cache flushing, connection tests, diagnostics, and WP-CLI support.

== Description ==

Smart Hybrid Cache adds persistent object caching to WordPress through the Redis or Memcached PHP extension. Use the settings page to configure a server, test read/write access, install the object-cache.php drop-in, and inspect runtime status.

The plugin does not install server software and does not provide page caching. Your hosting provider must supply a Redis or Memcached server and the matching PHP extension. PHP needs write access to the local wp-content directory to install and update the drop-in.

Features include

* Redis, Memcached, Auto, and Disabled engine settings.
* Request-local caching when the selected backend cannot be reached.
* Cache invalidation limited to this WordPress installation, including on shared Memcached servers.
* Atomic add and replace operations, counter updates, expiry handling, and per-key bulk API results.
* Redis password authentication, database selection, TLS, and bounded connection timeouts.
* Configurable global and non-persistent groups with multisite blog isolation.
* Settings tabs with keyboard navigation and mobile layouts.
* Connection read/write/delete tests, scoped flushing, event logs, and redacted diagnostics export.
* WordPress Site Health information and WP-CLI administration commands.

Auto selects one backend when the drop-in is installed or settings are saved. It does not switch between Redis and Memcached during an outage, because a secondary backend can contain stale values. Save settings to select a backend again. Restoring a backend after an outage may require a cache flush if WordPress data changed while it was unavailable.

WordPress handles normal object invalidation. The optional full flush on post updates is disabled by default. Enabling it schedules one installation-wide flush at the end of a request that changes posts. Theme and plugin update flushes are configurable.

= Privacy and external connections =

This plugin has no telemetry, advertising, vendor API, or externally hosted scripts. It connects only to the cache server configured by the administrator. Cached values can contain personal data placed in the WordPress Object Cache API by WordPress, themes, and other plugins. The site operator controls the server, access permissions, retention, and any hosting provider agreement.

Redis credentials are stored in the WordPress options table with autoload disabled and in the generated, access-guarded PHP object-cache.php file. The installer requests owner-only file permissions. Protect database backups and wp-content backups. Use only trusted cache servers and private networks. Remote Redis connections can use TLS. This version does not support Memcached SASL or TLS, Redis ACL usernames, Redis Cluster, or Sentinel.

Diagnostics redact the Redis password. They can still contain hostnames, filesystem paths, configuration, and server-wide counters. Review the file before sharing it. Event logs retain up to 50 entries locally and can be disabled.

= Source and support =

Source code, build instructions, issue tracking, and contribution guidance are available at https://github.com/jomardyan/Lolisoft-Smart-Hybryd-Cache-Redis-and-memcached . All runtime PHP, JavaScript, and CSS is included as readable source. No paid service is required.

== Installation ==

1. Ask your host to configure Redis with ext-redis, or Memcached with ext-memcached.
2. Install the plugin ZIP through Plugins > Add New > Upload Plugin, or copy the smart-hybrid-cache folder into wp-content/plugins.
3. Activate the plugin. On multisite, network activate it and configure it as a super administrator on the main site.
4. Open Settings > Smart Hybrid Cache, choose an engine, and enter the server details.
5. Save settings and test the appropriate connection on the Actions tab.
6. Enable the persistent object cache drop-in in Behavior, or install it from Actions.
7. Reload the page and confirm that the selected backend is active. A file being installed does not itself mean the backend is connected.

Existing third-party drop-ins are left in place unless you explicitly confirm replacement. Back up the existing drop-in first if you need to restore its original provider later.

== Frequently Asked Questions ==

= Does this install Redis or Memcached? =

No. A cache server and the corresponding PHP extension must already be installed. Configure extensions for the PHP runtime serving WordPress, which can differ from command-line PHP.

= What happens when the server is unavailable? =

WordPress continues using this drop-in's request-local cache. Persistent values are unavailable until the configured backend recovers. Save settings to reselect Auto, or choose a specific engine. Flush after recovery if data changed during the outage.

= Does flushing affect other sites or applications? =

Flushing changes a random namespace token for this WordPress installation. It never sends Redis FLUSHDB/FLUSHALL or Memcached flush_all. On multisite, this operation invalidates the whole installation, including global groups. Other WordPress installations remain isolated by installation path and optional WP_CACHE_KEY_SALT.

Invalidated values become unreachable and expire or are evicted by the cache server. A default TTL of zero means no expiry, so use a suitable eviction policy or a finite TTL to reclaim old namespaces. Loss or eviction of the namespace token generates a fresh random token and does not revive old data.

= How does expiry work? =

A positive per-item expiry overrides the configured default TTL. An expiry of zero uses the configured default; set Default TTL to zero for entries without an explicit expiry to persist until invalidated or evicted. Counters retain their original expiry. Expiration values over 30 days are converted correctly for Memcached.

= Can I use multisite? =

Yes. Network activate the plugin and manage settings on the main site as a super administrator. The shared drop-in uses that configuration for all sites. Blog-specific groups remain separate; global groups are shared intentionally. A site administrator cannot change the shared cache configuration. Separate WordPress networks sharing the same wp-content use the same installation configuration.

= How can I disable caching or recover from a broken drop-in? =

Choose Disabled and save settings. To disable immediately during recovery, add `define( 'SMART_HYBRID_CACHE_DISABLED', true );` to wp-config.php before WordPress loads. If an older broken drop-in prevents WordPress from starting, rename wp-content/object-cache.php using your hosting file manager, then reinstall the current drop-in from settings. Do not rename another provider's drop-in without coordinating with that provider.

= What happens on deactivation and uninstall? =

By default, deactivation removes the owned drop-in. If deactivation cleanup is disabled, the plugin writes a disabled configuration into the owned drop-in. Uninstall removes plugin settings and logs from all sites and removes the owned drop-in when uninstall cleanup is enabled. Uninstall does not flush a shared cache server. Filesystem failures require manual removal through your host.

= Where are screenshots and directory images maintained? =

The source repository stores screenshots in .wordpress-org. They are deployed to the top-level assets directory of the WordPress.org SVN repository after approval and are not included in the runtime ZIP.

== Screenshots ==

1. Settings overview showing the actual runtime cache state and engine controls.
2. Cache behavior, expiry, cleanup, logging, and group configuration.

== Changelog ==

= 1.2.0 =
* Fixed early WordPress bootstrap by generating standalone drop-in configuration.
* Isolated cache flushing by installation and stopped whole-server Memcached flushes.
* Corrected object cloning, falsy hits, expiry, multisite runtime state, bulk API results, and counter semantics.
* Added atomic counter updates and protected runtime state after rejected backend writes.
* Preserved password bytes and group names, separated event logs, and restricted multisite administration.
* Added atomic drop-in updates, safe deactivation, accurate health reporting, and keyboard and mobile admin fixes.
* Added regression tests, WordPress integration checks, package validation, and submission documentation.

= 1.1.0 =
* Added versioned release packaging, diagnostics, Site Health, and cache group settings.

= 1.0.0 =
* Initial release with Redis and Memcached persistent object cache support.

== Upgrade Notice ==

= 1.2.0 =
Update the owned drop-in from the plugin settings. The cache namespace changes and warms again automatically. Back up an existing third-party drop-in before replacing it.
