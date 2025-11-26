jQuery(document).ready(function ($) {
  $("#fqj-process-now").on("click", function (e) {
    e.preventDefault();
    var $status = $("#fqj-action-status").text("Processing…");
    $.post(
      fqjHealth.ajax_url,
      {
        action: "fqj_process_queue_now",
        nonce: fqjHealth.nonce
      },
      function (resp) {
        if (resp.success) {
          $("#fqj-queue-count").text(resp.data.queue_len);
          var lastrun = resp.data.last_run
            ? new Date(resp.data.last_run * 1000)
            : null;
          $("#fqj-last-run").text(lastrun ? lastrun.toLocaleString() : "n/a");
          $status.text("Processed " + resp.data.processed + " items.");
        } else {
          $status.text(
            "Error: " +
              (resp.data && resp.data.message ? resp.data.message : "unknown")
          );
        }
      }
    ).fail(function (xhr) {
      $status.text("Request failed. See console.");
      console.error(xhr);
    });
  });

  $("#fqj-purge-transients").on("click", function (e) {
    e.preventDefault();
    if (
      !confirm(
        "Purge all FAQ transients? This will force pages to rebuild the JSON-LD on next view."
      )
    )
      return;
    var $status = $("#fqj-action-status").text("Purging…");
    $.post(
      fqjHealth.ajax_url,
      {
        action: "fqj_purge_transients",
        nonce: fqjHealth.nonce
      },
      function (resp) {
        if (resp.success) {
          $status.text("Purged transients.");
        } else {
          $status.text("Error purging.");
        }
      }
    ).fail(function () {
      $status.text("Request failed.");
    });
  });

  $("#fqj-clear-log").on("click", function (e) {
    e.preventDefault();
    if (
      !confirm("Clear invalidation log? This will remove recent run history.")
    )
      return;
    var $status = $("#fqj-action-status").text("Clearing…");
    $.post(
      fqjHealth.ajax_url,
      {
        action: "fqj_clear_invalidation_log",
        nonce: fqjHealth.nonce
      },
      function (resp) {
        if (resp.success) {
          $status.text("Cleared log. Reload to refresh the table.");
        } else {
          $status.text("Error clearing log.");
        }
      }
    ).fail(function () {
      $status.text("Request failed.");
    });
  });
});
