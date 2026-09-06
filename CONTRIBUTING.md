# Contributing

Create a branch and submit a pull request with the problem, resulting behavior, and verification. Keep changes scoped and avoid credentials or generated installed drop-ins.

Run `make lint`, `make test`, and `make validate`. For native backend tests, install ext-redis and ext-memcached and run local Redis and Memcached servers. Tests generate isolated namespaces and must never flush a shared server. CI covers native backends and WordPress integration.

The drop-in must remain standalone. Never call get_option, home_url, or network_site_url during cache initialization. Preserve the WordPress Object Cache API contract, including falsy values, object cloning, per-key bulk results, global groups, and runtime-only groups.

Place development tools and tests outside the runtime folder. Run official Plugin Check without excluding the drop-in. Update changelog, upgrade notice, and all version fields for a release. Capture screenshots from the real UI when changing documented controls.
