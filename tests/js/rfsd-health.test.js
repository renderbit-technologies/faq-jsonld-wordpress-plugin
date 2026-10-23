const { loadScript, fakeRequest } = require("./helpers");

const loadHealth = () => require("../../assets/js/rfsd-health.js");

// 2023-11-14 22:13:20 UTC, in seconds like the PHP side sends.
const LAST_RUN = Date.UTC(2023, 10, 14, 22, 13, 20) / 1000;

function renderPage() {
  document.body.innerHTML = `
    <strong id="rfsd-queue-count">7</strong>
    <strong id="rfsd-last-run">Never</strong>
    <button id="rfsd-process-now">Process</button>
    <button id="rfsd-purge-transients">Purge</button>
    <button id="rfsd-clear-log">Clear</button>
    <span id="rfsd-action-status"></span>`;
}

/** Load the script with $.post answering per the given options. */
async function boot(requestOptions) {
  renderPage();
  window.rfsdHealth = { ajax_url: "/wp-admin/admin-ajax.php", nonce: "n0nce" };
  const post = fakeRequest(requestOptions);
  const $ = await loadScript(loadHealth, (jq) => {
    jq.post = post;
  });
  return { $, post };
}

const text = (id) => document.getElementById(id).textContent;

function click(id) {
  const event = new MouseEvent("click", { bubbles: true, cancelable: true });
  document.getElementById(id).dispatchEvent(event);
  return event;
}

beforeEach(() => {
  window.confirm = jest.fn(() => true);
});

afterEach(() => {
  delete window.jQuery;
  delete window.$;
  delete window.rfsdHealth;
  jest.restoreAllMocks();
});

describe("process queue now", () => {
  test("posts the action and shows the result", async () => {
    const { post } = await boot({
      response: {
        success: true,
        data: {
          processed: 5,
          queue_len: 2,
          last_run: LAST_RUN,
          last_run_human: "2023-11-14 22:13:20 (1 min ago)",
        },
      },
    });

    const event = click("rfsd-process-now");

    expect(event.defaultPrevented).toBe(true);
    expect(post).toHaveBeenCalledWith(
      "/wp-admin/admin-ajax.php",
      { action: "rfsd_process_queue_now", nonce: "n0nce" },
      expect.any(Function)
    );
    expect(text("rfsd-queue-count")).toBe("2");
    expect(text("rfsd-last-run")).toBe("2023-11-14 22:13:20 (1 min ago)");
    expect(text("rfsd-action-status")).toBe("Processed 5 items.");
  });

  test("shows n/a when there is no last run", async () => {
    await boot({
      response: {
        success: true,
        data: { processed: 0, queue_len: 0, last_run: 0, last_run_human: "" },
      },
    });
    click("rfsd-process-now");
    expect(text("rfsd-last-run")).toBe("n/a");
  });

  test("shows the server error message", async () => {
    await boot({
      response: { success: false, data: { message: "Permission denied" } },
    });
    click("rfsd-process-now");
    expect(text("rfsd-action-status")).toBe("Error: Permission denied");
    expect(text("rfsd-queue-count")).toBe("7");
  });

  test("shows unknown error when no message is given", async () => {
    await boot({ response: { success: false } });
    click("rfsd-process-now");
    expect(text("rfsd-action-status")).toBe("Error: unknown");
  });

  test("reports a failed request", async () => {
    const consoleError = jest
      .spyOn(console, "error")
      .mockImplementation(() => {});
    await boot({ fail: true, xhr: { status: 403 } });

    click("rfsd-process-now");

    expect(text("rfsd-action-status")).toBe("Request failed. See console.");
    expect(consoleError).toHaveBeenCalledWith({ status: 403 });
  });
});

describe.each([
  [
    "purge transients",
    "rfsd-purge-transients",
    "rfsd_purge_transients",
    "Purged transients.",
    "Error purging.",
  ],
  [
    "clear log",
    "rfsd-clear-log",
    "rfsd_clear_invalidation_log",
    "Cleared log. Reload to refresh the table.",
    "Error clearing log.",
  ],
])("%s", (_name, button, action, okText, errorText) => {
  test("does nothing when the user cancels", async () => {
    window.confirm = jest.fn(() => false);
    const { post } = await boot({ response: { success: true } });

    const event = click(button);

    expect(event.defaultPrevented).toBe(true);
    expect(window.confirm).toHaveBeenCalledTimes(1);
    expect(post).not.toHaveBeenCalled();
    expect(text("rfsd-action-status")).toBe("");
  });

  test("posts the action after confirmation", async () => {
    const { post } = await boot({ response: { success: true } });
    click(button);

    expect(post).toHaveBeenCalledWith(
      "/wp-admin/admin-ajax.php",
      { action, nonce: "n0nce" },
      expect.any(Function)
    );
    expect(text("rfsd-action-status")).toBe(okText);
  });

  test("shows an error response", async () => {
    await boot({ response: { success: false } });
    click(button);
    expect(text("rfsd-action-status")).toBe(errorText);
  });

  test("reports a failed request", async () => {
    await boot({ fail: true });
    click(button);
    expect(text("rfsd-action-status")).toBe("Request failed.");
  });
});

describe("localized strings", () => {
  test("uses rfsdHealth.i18n when provided", async () => {
    renderPage();
    window.rfsdHealth = {
      ajax_url: "/x",
      nonce: "n",
      i18n: { processing: "Traitement…", processed: "%d éléments traités." },
    };
    const post = fakeRequest({
      response: {
        success: true,
        data: { processed: 3, queue_len: 0, last_run_human: "" },
      },
    });
    await loadScript(loadHealth, (jq) => {
      jq.post = post;
    });
    click("rfsd-process-now");
    expect(text("rfsd-action-status")).toBe("3 éléments traités.");
  });
});
