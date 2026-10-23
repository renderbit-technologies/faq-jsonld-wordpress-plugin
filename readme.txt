=== FAQ JSON-LD Manager ===
Contributors: renderbit
Tags: faq, schema, json-ld, structured data, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 3.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Manage FAQ items as a custom post type and output FAQPage JSON-LD on the posts and pages they apply to.

== Description ==

FAQ JSON-LD Manager keeps your FAQs in one place and adds schema.org `FAQPage` structured data to the content they belong to. Write each question and answer once as an FAQ item, then attach it to the posts, post types, taxonomy terms or URLs where it should appear, or make it global.

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

* `wp fqj purge-transients` purges all cached FAQ JSON-LD.
* `wp fqj process-queue [--limit=N]` processes the invalidation queue.
* `wp fqj queue-info` shows the number of pending queue items.

= Developer filters =

* `fqj_question_text( $text, $faq_post )` filters the plain-text question.
* `fqj_answer_text( $text, $faq_post )` filters the plain-text answer.
* `fqj_uninstall_delete_faqs` (default `false`): return `true` to delete FAQ posts when the plugin is deleted.

Development happens on [GitHub](https://github.com/renderbit-technologies/faq-jsonld-wordpress-plugin).

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the `faq-jsonld` folder to `wp-content/plugins/`.
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

= What happens when I delete the plugin? =

Its options, transients, scheduled events, post meta and mapping table are removed. FAQ item posts are kept unless you enable the `fqj_uninstall_delete_faqs` filter.

== Changelog ==

= 3.1.1 =
* FAQ items now use the prefixed post type `fqj_faq` instead of `faq_item`, so they cannot collide with another plugin's FAQ post type. Existing FAQs are moved over automatically on upgrade; links or code that use `post_type=faq_item` need updating.
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

= 3.1.1 =
FAQ items move to the prefixed post type `fqj_faq` automatically. Update any bookmarks or custom code that use `post_type=faq_item`.

= 3.1.0 =
URL associations now match the URL itself. The upgrade rewrites URLs that only matched the old way and lists every change on the FAQ list screen.

= 3.0.1 =
First release on WordPress.org. No changes to stored data.
