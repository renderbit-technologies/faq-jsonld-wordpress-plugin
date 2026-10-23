# AGENTS.md

WordPress plugin `renderbit-faq-structured-data`: FAQs as a `rfsd_faq` CPT (`RFSD_POST_TYPE`; renamed from `faq_item` on upgrade), schema.org `FAQPage` JSON-LD printed on singular posts/pages. Procedural PHP, every function prefixed `rfsd_`, no build step, no runtime Composer deps.

**PHP ceiling is 7.2 for shipped code** — `composer.json` pins platform 7.4, so syntax up to 7.4 passes locally while CI's `php -l` matrix (7.2–8.4) fails. Write 7.2-compatible syntax in `renderbit-faq-structured-data.php`, `uninstall.php`, `includes/`.

Full architecture, data flow (save → index → render → invalidate), and versioning rules live in `CLAUDE.md` — read its Architecture section before touching `includes/indexer.php`, `includes/queue.php`, `includes/frontend.php`, or cache keys. Keep `CLAUDE.md` in sync if you change behavior it describes.

## Commands

```bash
composer install && npm install   # setup; .env.testing must exist (copy .env.testing.example)

composer lint                     # phpcs, WordPress Coding Standards, text domain renderbit-faq-structured-data
composer test                     # PHPUnit, single-site
composer test:multisite           # same suite as a multisite network
vendor/bin/phpunit --filter test_name   # one test
vendor/bin/phpunit tests/php/test-queue.php   # one file
npm test                          # Jest (jsdom) for assets/js
npx jest tests/js/rfsd-admin.test.js -t "name"  # one JS test
```

CI (`.github/workflows/tests.yml`) runs all of the above plus `WordPress/plugin-check` on every PR. Verify with `composer lint && composer test && npm test` before claiming done.

## Traps

- **PHPUnit wipes the database** it's pointed at, on every run. Settings come from `.env.testing` (gitignored); shell env vars win; defaults are `wordpress_test`/`root`/no password at `127.0.0.1`. Point it at a disposable DB.
- Notices, warnings and deprecations are converted to test failures — "passes with warnings" does not exist here.
- Test groups: network-only tests are tagged `@group ms-required`, single-site-only `@group ms-excluded`; each phpunit config excludes the other group, so a test in neither group runs twice and one in both runs never.
- `composer build-zip` runs `git archive HEAD` — **uncommitted changes are not in the zip**. `composer check-zip` verifies contents against `.gitattributes` `export-ignore`, which also decides what ships to WordPress.org SVN. Add new dev-only files there.
- Raw `$wpdb` queries use `%i` for table identifiers and carry targeted `phpcs:ignore`/`phpcs:disable … phpcs:enable` comments naming the specific sniffs — keep this pattern so `composer lint` stays clean.
- Two independent version constants: bump `RFSD_DB_VERSION` only when `CREATE TABLE` SQL in `rfsd_create_table()` changes (dbDelta rules: no `IF NOT EXISTS`, two spaces after `PRIMARY KEY`, `KEY` not `INDEX`, no inline comments); bump `RFSD_DATA_VERSION` only to force a one-time reindex + cache purge.
- Transient keys are always built with `rfsd_cache_key( $post_id )` (embeds `rfsd_cache_gen`) — never hand-build `rfsd_faq_json_*` keys, or purge-all breaks with a persistent object cache.
- Releasing: `composer bump-patch|minor|major` keeps four version strings in sync (`composer.json`, plugin header, `RFSD_VERSION`, `readme.txt` Stable tag); add the `readme.txt` changelog entry by hand. Pushing a `vX.Y.Z` tag to `main` creates a draft release; publishing it deploys to WordPress.org SVN.
