/* global rfsdHealth */
jQuery(document).ready(function ($) {
  // Strings come from wp_localize_script (rfsdHealth.i18n); English is the fallback.
  var i18n = $.extend(
    {
      processing: "Processing…",
      processed: "Processed %d items.",
      error: "Error: %s",
      unknown: "unknown",
      requestFailed: "Request failed. See console.",
      requestFailedShort: "Request failed.",
      purging: "Purging…",
      purged: "Purged transients.",
      purgeError: "Error purging.",
      purgeConfirm:
        "Purge all FAQ transients? This will force pages to rebuild the JSON-LD on next view.",
      clearing: "Clearing…",
      cleared: "Cleared log. Reload to refresh the table.",
      clearError: "Error clearing log.",
      clearConfirm: "Clear invalidation log? This will remove recent run history.",
      notAvailable: "n/a"
    },
    (window.rfsdHealth && window.rfsdHealth.i18n) || {}
  );

  $("#rfsd-process-now").on("click", function (e) {
    e.preventDefault();
    var $status = $("#rfsd-action-status").text(i18n.processing);
    $.post(
      rfsdHealth.ajax_url,
      {
        action: "rfsd_process_queue_now",
        nonce: rfsdHealth.nonce
      },
      function (resp) {
        if (resp.success) {
          $("#rfsd-queue-count").text(resp.data.queue_len);
          $("#rfsd-last-run").text(resp.data.last_run_human || i18n.notAvailable);
          $status.text(i18n.processed.replace("%d", resp.data.processed));
        } else {
          $status.text(
            i18n.error.replace(
              "%s",
              resp.data && resp.data.message ? resp.data.message : i18n.unknown
            )
          );
        }
      }
    ).fail(function (xhr) {
      $status.text(i18n.requestFailed);
      console.error(xhr);
    });
  });

  $("#rfsd-purge-transients").on("click", function (e) {
    e.preventDefault();
    if (
      !confirm(i18n.purgeConfirm)
    )
      return;
    var $status = $("#rfsd-action-status").text(i18n.purging);
    $.post(
      rfsdHealth.ajax_url,
      {
        action: "rfsd_purge_transients",
        nonce: rfsdHealth.nonce
      },
      function (resp) {
        if (resp.success) {
          $status.text(i18n.purged);
        } else {
          $status.text(i18n.purgeError);
        }
      }
    ).fail(function () {
      $status.text(i18n.requestFailedShort);
    });
  });

  $("#rfsd-clear-log").on("click", function (e) {
    e.preventDefault();
    if (
      !confirm(i18n.clearConfirm)
    )
      return;
    var $status = $("#rfsd-action-status").text(i18n.clearing);
    $.post(
      rfsdHealth.ajax_url,
      {
        action: "rfsd_clear_invalidation_log",
        nonce: rfsdHealth.nonce
      },
      function (resp) {
        if (resp.success) {
          $status.text(i18n.cleared);
        } else {
          $status.text(i18n.clearError);
        }
      }
    ).fail(function () {
      $status.text(i18n.requestFailedShort);
    });
  });
});
