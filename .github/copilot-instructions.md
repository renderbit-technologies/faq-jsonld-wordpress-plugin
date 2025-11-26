# Copilot Instructions for FAQ JSON-LD WordPress Plugin

## Overview

This plugin manages FAQ items as a custom post type (CPT) and injects FAQ JSON-LD into WordPress posts/pages. It uses a custom mapping table for fast lookups, per-post transient caching, a background invalidation queue (via WP-Cron), a settings UI, and WP-CLI tools for advanced management.

## Architecture & Key Components

- **Custom Post Type:** `faq_item` is registered for FAQ entries.
- **Mapping Table:** MySQL table (`fqj_mappings`) links FAQ items to posts, post types, terms, URLs, or global context. See `includes/indexer.php`.
- **JSON-LD Output:** Injected on the frontend using `includes/frontend.php`, with per-post transient caching for performance.
- **Cache Invalidation:** Uses a background queue (`includes/queue.php`) processed by WP-Cron or WP-CLI. Queue state and logs are visible in the Health admin page.
- **Admin UI:** Custom meta boxes and settings in `includes/admin.php` and `includes/settings.php`. Select2 is used for post selectors (see `assets/js/fqj-admin.js`).
- **Health & Diagnostics:** Admin page (`includes/health.php`) shows queue status, logs, and provides manual actions.
- **WP-CLI Integration:** `includes/wpcli.php` provides commands for purging transients and processing the invalidation queue.

## Developer Workflows

- **Activate/Upgrade:** On plugin activation, the mapping table is created/updated automatically.
- **Settings:** Accessible under the FAQ Items menu. Options include cache TTL, batch size, and output type.
- **Cache Management:**
  - Manual: Use Health admin page or WP-CLI (`wp fqj purge-transients`, `wp fqj process-queue`).
  - Automatic: Invalidation queue is processed every 5 minutes by WP-Cron.
- **Debugging:**
  - Check the Health admin page for queue/logs.
  - Use WP-CLI for direct cache/queue operations.

## Project-Specific Patterns

- **Mapping Structure:** All associations (post, post_type, term, url, global) are stored in a single table for fast lookups.
- **Transient Keys:** Format is `fqj_faq_json_{post_id}`.
- **Admin JS:** Uses Select2 for post selectors, AJAX for search (`fqj_search_posts` action).
- **Settings/Health Pages:** Registered as submenus under the FAQ Items CPT.

## Integration Points

- **WP-Cron:** For background queue processing.
- **WP-CLI:** For advanced/automated management.
- **Select2 CDN:** For enhanced admin selectors.

## Key Files

- `faq-jsonld.php`: Main plugin loader and CPT registration.
- `includes/indexer.php`: Mapping table logic.
- `includes/queue.php`: Invalidation queue logic.
- `includes/frontend.php`: JSON-LD output and caching.
- `includes/admin.php`: Admin UI/meta boxes.
- `includes/settings.php`: Settings page.
- `includes/health.php`: Health/diagnostics UI.
- `includes/wpcli.php`: WP-CLI commands.
- `assets/js/fqj-admin.js`: Admin JS (Select2, AJAX).

## Example: Adding a New Mapping Type

1. Update the mapping table schema in `includes/indexer.php` if needed.
2. Add logic for the new type in both mapping and lookup functions.
3. Update admin UI and frontend output as needed.

---

For further details, see inline comments in each file. When in doubt, check the Health admin page or use WP-CLI for diagnostics.
