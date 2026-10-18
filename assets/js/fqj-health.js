jQuery(document).ready(function ($) {
  // Strings come from wp_localize_script (fqjHealth.i18n); English is the fallback.
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
    (window.fqjHealth && window.fqjHealth.i18n) || {}
  );

  $("#fqj-process-now").on("click", function (e) {
    e.preventDefault();
    var $status = $("#fqj-action-status").text(i18n.processing);
    $.post(
      fqjHealth.ajax_url,
      {
        action: "fqj_process_queue_now",
        nonce: fqjHealth.nonce
      },
      function (resp) {
        if (resp.success) {
          $("#fqj-queue-count").text(resp.data.queue_len);
          $("#fqj-last-run").text(resp.data.last_run_human || i18n.notAvailable);
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

  $("#fqj-purge-transients").on("click", function (e) {
    e.preventDefault();
    if (
      !confirm(i18n.purgeConfirm)
    )
      return;
    var $status = $("#fqj-action-status").text(i18n.purging);
    $.post(
      fqjHealth.ajax_url,
      {
        action: "fqj_purge_transients",
        nonce: fqjHealth.nonce
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

  $("#fqj-clear-log").on("click", function (e) {
    e.preventDefault();
    if (
      !confirm(i18n.clearConfirm)
    )
      return;
    var $status = $("#fqj-action-status").text(i18n.clearing);
    $.post(
      fqjHealth.ajax_url,
      {
        action: "fqj_clear_invalidation_log",
        nonce: fqjHealth.nonce
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
