# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

WordPress plugin (slug `faq-jsonld`, published on WordPress.org) that manages FAQs as a `faq_item` custom post type and prints schema.org `FAQPage` JSON-LD in `wp_head` on the singular posts/pages each FAQ is mapped to. Procedural PHP, every function prefixed `fqj_`; no build step and no runtime Composer dependencies. Supports WordPress 6.5+ and PHP 7.2+, so don't use syntax newer than PHP 7.2 in shipped code.

## Commands

```bash
composer install && npm install

composer lint                      # phpcs with WordPress Coding Standards (phpcs.xml), text domain faq-jsonld
composer test                      # PHPUnit integration suite, single-site
composer test:multisite            # same suite as a multisite network
vendor/bin/phpunit --filter test_name_here            # one test
vendor/bin/phpunit tests/php/test-queue.php           # one file
npm test                           # Jest (jsdom) tests for assets/js
npx jest tests/js/fqj-admin.test.js -t "name"         # one JS test

composer build-zip && composer check-zip              # release zip from `git archive HEAD`, then verify contents
composer bump-patch                # or bump-minor / bump-major
```

PHPUnit runs against a real MySQL/MariaDB database that the suite **wipes on every run**. Settings come from `.env.testing` (copy `.env.testing.example`); shell env vars win. Tests are loaded by `tests/php/bootstrap.php`, which loads the plugin on `muplugins_loaded` and calls `fqj_activate()` on `init`. Extend `FQJ_TestCase` (`tests/php/class-fqj-testcase.php`, has `create_faq()` helpers) or `FQJ_Multisite_TestCase`. Tag network-only tests `@group ms-required` and single-site-only tests `@group ms-excluded`; each config excludes the other group. PHPUnit converts notices, warnings and deprecations to failures. WP-CLI is stubbed in `tests/php/stubs/class-wp-cli.php`.

## Architecture

`faq-jsonld.php` defines constants, requires every file in `includes/`, registers the CPT, and handles activation/deactivation (looping over all sites via `fqj_for_each_site()` on network activation and setting up new sites on `wp_initialize_site`).

Data flow:

1. **Save (`includes/admin.php`)**: the meta box stores the FAQ's associations as JSON in post meta `fqj_assoc_data_json` (keys `urls`, `posts`, `post_types`, `terms`, `global`), then the index is rebuilt.
2. **Index (`includes/indexer.php`)**: `fqj_rebuild_index_for_faq()` replaces the FAQ's rows in the `{prefix}fqj_mappings` table (`faq_id`, `mapping_type` ∈ post/post_type/term/url/global, `mapping_value`). URLs go through `fqj_normalize_url()`, and internal URLs also get a `post` row. Old and new mappings are both passed to `fqj_enqueue_invalidation_for_mappings()`. Hooks on status transitions, post updates, deletes, term changes and permalink changes keep the index and cache in sync.
3. **Render (`includes/frontend.php`)**: on `is_singular()` only, builds candidate (type, value) pairs for the current post (its ID, post type, terms in all its taxonomies, normalized permalink with and without a trailing slash, global), runs one query against the mapping table, and caches the `<script>` in a transient. Empty results are cached as the sentinel `__FQJ_EMPTY__`.
4. **Invalidate (`includes/queue.php`)**: `{prefix}fqj_queue` table. `post` items are single cache deletes. `post_type`/`term` items are *jobs* that the worker expands incrementally using `job_cursor`, so saving a FAQ never lists every matching post. Re-adding a job resets its cursor to 0. A WP-Cron event (`fqj_process_invalidation_queue`, custom `fqj_five_minutes` interval) processes up to `batch_size` posts per run. It can also be run from the Health page (`includes/health.php`) or `wp fqj process-queue` (`includes/class-fqjcli.php`).

Cache keys: always use `fqj_cache_key( $post_id )` → `fqj_faq_json_{generation}_{post_id}`. "Purge all" (`fqj_purge_all_faq_transients()` in `includes/settings.php`) bumps the `fqj_cache_gen` option so it also works with persistent object caches. A `global` mapping change and any settings change trigger a full purge.

Schema and data versioning (constants in `faq-jsonld.php`):
- `FQJ_DB_VERSION`: bump whenever the `CREATE TABLE` SQL in `fqj_create_table()` changes. `fqj_maybe_upgrade_db()` reruns `dbDelta` on `plugins_loaded`. dbDelta needs a strict format (no `IF NOT EXISTS`, two spaces after `PRIMARY KEY`, `KEY` not `INDEX`, no inline comments).
- `FQJ_DATA_VERSION`: bump to force a one-time reindex of every FAQ plus a cache purge on existing sites. `fqj_run_data_migration()` processes FAQs in batches through a cron event, keeping its place in a cursor option.

`uninstall.php` removes options, transients, cron events, post meta and both tables on every site. `faq_item` posts are kept unless the `fqj_uninstall_delete_faqs` filter returns true.

Admin JS (`assets/js/fqj-admin.js`, `fqj-health.js`) uses jQuery and a bundled Select2 (`assets/vendor/select2`, not a CDN). It talks to `wp_ajax_fqj_*` handlers protected by the `fqj_admin_nonce` nonce, and gets its strings through `wp_localize_script`.

## Conventions

- Direct `$wpdb` queries use `%i` for table identifiers and carry targeted `phpcs:ignore`/`phpcs:disable … phpcs:enable` comments naming the specific sniffs; keep this pattern so `composer lint` stays clean.
- Options that are rarely read (`fqj_invalidation_log`, `fqj_last_queue_run`) are written with `fqj_update_option_no_autoload()` so they stay out of autoload.
- CI runs `php -l` on PHP 7.2–8.4, and PHPUnit on WP 6.5 plus the locked version.
- Public filters: `fqj_question_text`, `fqj_answer_text` (both must return plain text), `fqj_uninstall_delete_faqs`.
- `.gitattributes` `export-ignore` decides what ships (`git archive` builds both the zip and the WordPress.org SVN deploy). Add new dev-only files there.

## Releasing

`composer bump-*` runs `bin/bump-version.php`, which keeps four version strings in sync: `composer.json` `version`, the plugin `Version` header and `FQJ_VERSION` in `faq-jsonld.php`, and `Stable tag` in `readme.txt`. It then refreshes `composer.lock`. Add a `readme.txt` changelog entry by hand. After merging to `main`, push a `vX.Y.Z` tag: `draft-release.yml` creates a draft GitHub release, and publishing it runs `deploy-wporg.yml`, which checks that the versions match and deploys to WordPress.org SVN.

## Agent skills

### Issue tracker

Issues live in GitHub Issues for `renderbit-technologies/faq-jsonld-wordpress-plugin` (via the `gh` CLI). See `docs/agents/issue-tracker.md`.

### Triage labels

Default vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
