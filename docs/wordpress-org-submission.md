# WordPress.org submission

## Prepared repository content

The runtime folder contains the plugin entry point, matching version and stable tag, minimum WordPress and PHP headers, GPL license, readable PHP/JavaScript/CSS, installation guidance, privacy and cache-server disclosures, FAQs, changelog, upgrade notice, and uninstall cleanup.

`.wordpress-org/screenshot-1.png` and `screenshot-2.png` are captures of the actual settings page. They show a test environment without native cache extensions. They do not imply that a backend is connected. Screenshots belong in SVN `assets`, outside the runtime ZIP.

The proposed slug and text domain are `smart-hybrid-cache`. The contributor is carried over from the existing repository as `jomardyan`. Confirm that this is the publisher's WordPress.org username. GitHub ownership does not establish WordPress.org account ownership or guarantee slug availability.

## Verify the exact submission ZIP

1. Run `make ci` and `make release`.
2. Install `build/smart-hybrid-cache.zip` on a clean WordPress site.
3. Run the official Plugin Check against the built ZIP. Include the drop-in in the scan. With WP-CLI, use `wp plugin check /absolute/path/smart-hybrid-cache.zip`. For runtime checks, also load Plugin Check's `cli.php` using WP-CLI `--require` as described in its documentation.
4. Review all findings. The narrow naming exception in the drop-in is required by the WordPress Object Cache API. The `var_export` annotation documents locally generated configuration, not debug output. No whole directory is excluded from checking.
5. Test Redis and Memcached, unavailable servers, install and removal, settings updates, deactivation, and multisite on the supported environment. CI includes real backends and MySQL, while local checks can use backend doubles and SQLite.
6. Confirm the Tested up to header against the latest stable WordPress version actually tested. Check contributor identity and the license rights for every included asset.

## Submit for review

Sign in to the publisher's WordPress.org account, open the plugin submission page, and upload `build/smart-hybrid-cache.zip`. Confirm the requested declarations personally. This work prepares the repository and ZIP. It does not submit a plugin, accept account declarations, reserve a slug, or guarantee approval.

After approval, WordPress provides an SVN repository. Copy runtime files into `trunk`, copy screenshots into top-level `assets`, and copy the same runtime files into `tags/1.2.1`. Keep the stable tag aligned with that version. Inspect the SVN diff and commit a complete release. Do not upload this GitHub repository wholesale.

If the approved slug differs, align the directory name, main filename where appropriate, text domain, build scripts, readme, documentation, and workflows before release. Brand banners and icons are optional and may be added later with confirmed license rights.

## Configuration and upgrade notes for reviewers

The drop-in starts before WordPress can read options. The installer embeds an allowlisted configuration into a standalone PHP template. The resulting local file contains secrets, starts with an ABSPATH access guard, requests mode 0600, and is swapped atomically in the same directory. Source control and release ZIPs contain only the unconfigured template. Missing source plugin files disable persistence.

This plugin uses native PHP extensions rather than a remote vendor service. Cache values can include personal data supplied by WordPress or other plugins. It sends no telemetry. See the directory readme for storage, trusted-server, TTL, recovery, and diagnostics disclosures.

The 1.2.0 namespace intentionally differs from older releases. Flush rotates a namespace token instead of deleting unrelated cache-server data. Old values remain until expiry or eviction. Auto resolves a backend when configuration is written and does not switch stores during a backend outage.

## Official references

- [Detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Plugin readme specification](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [Plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)
- [Plugin Check](https://wordpress.org/plugins/plugin-check/)
- [Submit a plugin](https://wordpress.org/plugins/developers/add/)
- [WordPress release archive](https://wordpress.org/download/releases/)
