<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Background invalidation queue using transients + WP-Cron
 *
 * Queue design:
 * - A transient 'fqj_invalidate_queue' holds a JSON-serialized array of post IDs (FIFO).
 * - Producer: indexer code calls fqj_queue_add_posts( array_of_post_ids ).
 * - Worker: WP-Cron hook 'fqj_process_invalidation_queue' processes N IDs per run (batch_size from settings).
 * - Worker deletes transients 'fqj_faq_json_{post_id}' for each ID processed.
 * - Provides helper to force-process via WP-CLI or ad-hoc admin action.
 *
 * This approach avoids heavy DB writes and uses WP core features.
 */

/**
 * Register a custom cron interval (5 minutes) if not present.
 */
function fqj_add_cron_interval($schedules)
{
    if (! isset($schedules['fqj_five_minutes'])) {
        $schedules['fqj_five_minutes'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 Minutes'];
    }

    return $schedules;
}
add_filter('cron_schedules', 'fqj_add_cron_interval');

/**
 * Enqueue posts to the invalidation queue.
 * Accepts array of integer post IDs.
 */
function fqj_queue_add_posts($post_ids)
{
    if (empty($post_ids)) {
        return false;
    }

    $post_ids = array_map('intval', $post_ids);
    $post_ids = array_filter($post_ids);

    if (empty($post_ids)) {
        return false;
    }

    $key = 'fqj_invalidate_queue';
    $existing = get_transient($key);
    $queue = [];
    if ($existing !== false) {
        $queue = maybe_unserialize($existing);
        if (! is_array($queue)) {
            $queue = [];
        }
    }

    // append while avoiding duplicates (we keep uniqueness to avoid reprocessing same post many times)
    $queue_map = array_flip($queue);
    foreach ($post_ids as $pid) {
        if (! isset($queue_map[$pid])) {
            $queue[] = $pid;
        }
    }

    // store back as serialized with a reasonably long TTL (one day). The queue persists across restarts.
    set_transient($key, $queue, DAY_IN_SECONDS);

    return true;
}

/**
 * Pop up to $limit posts from queue (returns array of post IDs popped). FIFO.
 */
function fqj_queue_pop_posts($limit = 100)
{
    $key = 'fqj_invalidate_queue';
    $existing = get_transient($key);
    if ($existing === false) {
        return [];
    }

    $queue = maybe_unserialize($existing);
    if (! is_array($queue) || empty($queue)) {
        return [];
    }

    $pop = array_splice($queue, 0, intval($limit));
    // write back remaining queue (or delete transient if empty)
    if (empty($queue)) {
        delete_transient($key);
    } else {
        set_transient($key, $queue, DAY_IN_SECONDS);
    }

    // ensure integers
    return array_map('intval', $pop);
}

/**
 * Get the approximate queue length
 */
function fqj_queue_length()
{
    $key = 'fqj_invalidate_queue';
    $existing = get_transient($key);
    if ($existing === false) {
        return 0;
    }
    $queue = maybe_unserialize($existing);
    if (! is_array($queue)) {
        return 0;
    }

    return count($queue);
}

/**
 * Cron worker: processes up to batch_size posts from the queue
 */
function fqj_process_invalidation_queue_cron()
{
    // get settings
    $opts = get_option(FQJ_OPTION_KEY);
    $batch_size = isset($opts['batch_size']) ? intval($opts['batch_size']) : 500;

    // pop posts
    $to_process = fqj_queue_pop_posts($batch_size);
    if (empty($to_process)) {
        return;
    }

    foreach ($to_process as $pid) {
        // safety: skip non-numeric
        $pid = intval($pid);
        if ($pid <= 0) {
            continue;
        }
        delete_transient('fqj_faq_json_'.$pid);
    }

    // If queue still has items, leave cron scheduled (it is recurring). Worker will run next time.
}
add_action('fqj_process_invalidation_queue', 'fqj_process_invalidation_queue_cron');

/**
 * Immediate queue processor (used by WP-CLI or admin actions)
 * Returns number processed.
 */
function fqj_process_invalidation_queue_now($limit = null)
{
    $opts = get_option(FQJ_OPTION_KEY);
    $batch_size = isset($opts['batch_size']) ? intval($opts['batch_size']) : 500;
    $limit = $limit ? intval($limit) : $batch_size;

    $processed = 0;
    while (true) {
        $pop = fqj_queue_pop_posts($limit);
        if (empty($pop)) {
            break;
        }
        foreach ($pop as $pid) {
            delete_transient('fqj_faq_json_'.intval($pid));
            $processed++;
        }
        // break to avoid long-running loops if caller did not want to process entire queue
        if ($limit <= 0) {
            break;
        }
        // continue loop to process next batch if queue still has items
    }

    return $processed;
}

/**
 * Admin notice helper (optional): show queue length on admin screens for admins.
 * Minimal and non-intrusive.
 */
function fqj_admin_queue_notice()
{
    if (! current_user_can('manage_options')) {
        return;
    }
    $len = fqj_queue_length();
    if ($len > 0) {
        printf('<div class="notice notice-info"><p>FAQ JSON-LD queue: <strong>%d</strong> posts pending invalidation. The background worker (WP-Cron) will process them in batches.</p></div>', intval($len));
    }
}
add_action('admin_notices', 'fqj_admin_queue_notice');
