=== Renderbit FAQ Structured Data ===
Contributors: renderbit
Tags: faq, schema, json-ld, structured data, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 4.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Manage FAQ items as a custom post type and output FAQPage JSON-LD on the posts and pages they apply to.

== Description ==

Renderbit FAQ Structured Data keeps your FAQs in one place and adds schema.org `FAQPage` structured data to the content they belong to. Write each question and answer once as an FAQ item, then attach it to the posts, post types, taxonomy terms or URLs where it should appear, or make it global.

Built for large sites:

* **Fast lookups.** Associations live in a dedicated, indexed database table rather than post meta.
* **Per-post caching.** Generated JSON-LD is cached per post and invalidated automatically when FAQs or mappings change.
* **Background invalidation.** Cache purges are queued and processed in batches by WP-Cron, so saving an FAQ mapped to thousands of posts stays quick.
* **Health page.** See the queue, recent log entries and run maintenance actions from FAQ Items > Health.
* **WP-CLI.** Purge caches and process the queue from the command line.
* **Multisite ready.** Activation, upgrades and uninstall run on every site of a network.

= How output works =

* JSON-LD is printed on singular views only (posts, pages and custom post types). Archives, search results and the blog home never output FAQ markup.
* Question and answer text is output as plain text: tags stripped, entities decoded and whitespace collapsed. Answers run through blocks and shortcodes.

= WP-CLI commands =

* `wp rfsd purge-transients` purges all cached FAQ JSON-LD.
* `wp rfsd process-queue [--limit=N]` processes the invalidation queue.
* `wp rfsd queue-info` shows the number of pending queue items.

= Developer filters =

* `rfsd_question_text( $text, $faq_post )` filters the plain-text question.
* `rfsd_answer_text( $text, $faq_post )` filters the plain-text answer.
* `rfsd_uninstall_delete_faqs` (default `false`): return `true` to delete FAQ posts when the plugin is deleted.

Development happens on [GitHub](https://github.com/renderbit-technologies/renderbit-faq-structured-data-wordpress-plugin).

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the `renderbit-faq-structured-data` folder to `wp-content/plugins/`.
2. Activate it. The mapping table is created automatically.
3. Add questions under FAQ Items and use the association box on each item to choose where it appears.
4. Adjust cache lifetime and batch size under FAQ Items > Settings.

== Frequently Asked Questions ==

= Where does the JSON-LD appear? =

In the `<head>` of singular posts, pages and custom post types that have at least one FAQ item mapped to them, directly or through their post type, terms, URL or a global mapping.

= Can I use HTML in answers? =

Yes. Write answers in the editor as usual; blocks and shortcodes are rendered and the structured data contains the resulting plain text. The plugin only outputs structured data, so show the FAQs on the page with your theme or a block as you normally would.

= Does it work with object caching? =

Yes. "Purge all" bumps a cache generation number instead of deleting transients one by one, so it works with persistent object caches.

= I used FAQ JSON-LD Manager. How do I switch? =

Install and activate Renderbit FAQ Structured Data while the old plugin is still installed. On activation (and on each site of a network) it moves the FAQ items and their associations over and copies the settings. Then deactivate and delete FAQ JSON-LD Manager. Do not delete the old plugin first: its uninstall removes the FAQ associations.

= What happens when I delete the plugin? =

Its options, transients, scheduled events, post meta and mapping table are removed. FAQ item posts are kept unless you enable the `rfsd_uninstall_delete_faqs` filter.

== Changelog ==

= 4.0.1 =
* The WP-CLI commands work under their documented names (`wp rfsd purge-transients`, `wp rfsd process-queue`, `wp rfsd queue-info`). The underscore forms keep working.
* The plugin's GitHub repository moved to renderbit-technologies/renderbit-faq-structured-data-wordpress-plugin.

= 4.0.0 =
* Renamed from FAQ JSON-LD Manager (`faq-jsonld`) to Renderbit FAQ Structured Data (`renderbit-faq-structured-data`). Install and activate this plugin first: it imports the FAQs, their associations and the settings of FAQ JSON-LD Manager on every site. Then deactivate and delete the old plugin. Deleting the old plugin first removes the FAQ associations.
* Every name now uses the `rfsd_` prefix. The FAQ post type is `rfsd_faq` (was `faq_item`), the filters are `rfsd_question_text`, `rfsd_answer_text` and `rfsd_uninstall_delete_faqs` (were `fqj_*`), and the WP-CLI command is `wp rfsd` (was `wp fqj`). Update any custom code, bookmarks or scripts that use the old names.
* The invalidation queue notice only appears on the plugin's own screens (FAQ list and editor, Health, Settings) instead of on every admin page, and can be translated.
* Updated the bundled Select2 to 4.1.0.

= 3.1.0 =
* URL associations now mean the URL itself: a FAQ shows on whichever post has that permalink now. Before, saving a URL also tied the FAQ to the post found there, so it followed that post when it was renamed and appeared on shortlinks and the front page's slug URL.
* URLs on your own site are stored relative to the site root, so moving between `http` and `https`, adding or dropping `www.`, or changing domain keeps them working.
* Upgrading rewrites URL associations that only matched through the old behaviour (shortlinks, the front page's slug URL, renamed posts, URLs from an earlier permalink structure) to the post's current permalink. The FAQ list screen lists every change until an editor dismisses it.
* Saving a URL that is not the permalink of a post on this site, including any external URL, shows a warning, with the right permalink when the URL leads to a post.
* Changing the permalink structure, category or tag base, or the site address clears all cached output.
* Private posts and previews no longer get cached output.
* With plain permalinks, URL associations to custom post types now clear their cached output on change.

= 3.0.1 =
* First release on WordPress.org.
* Database queries pass table names through `$wpdb->prepare()` identifier placeholders.
* The unminified Select2 sources are bundled and loaded when `SCRIPT_DEBUG` is on.

= 3.0.0 =
* The invalidation queue moved to its own database table, and post type and term mappings are expanded in the background worker.
* Output is `FAQPage` only and printed on singular views only.
* Cache purges work with persistent object caches.
* Select2 is bundled locally instead of loaded from a CDN.
* Uninstall, deactivation and multisite handling are complete.

== Upgrade Notice ==

= 4.0.1 =
Fixes the documented WP-CLI command names.

= 4.0.0 =
Replaces FAQ JSON-LD Manager. Activate this plugin before deleting the old one so your FAQs and settings are imported. Filters, the post type and the WP-CLI command now use the `rfsd` prefix.

= 3.1.0 =
URL associations now match the URL itself. The upgrade rewrites URLs that only matched the old way and lists every change on the FAQ list screen.

= 3.0.1 =
First release on WordPress.org. No changes to stored data.
