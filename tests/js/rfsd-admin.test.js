const { loadScript, fakeRequest } = require("./helpers");

const loadAdmin = () => require("../../assets/js/rfsd-admin.js");

function renderMetaBox(type, data) {
  const types = ["urls", "posts", "post_types", "tax_terms", "global", "other"];
  document.body.replaceChildren();

  const select = document.createElement("select");
  select.id = "rfsd_assoc_type";
  types.forEach((t) => select.append(new Option(t, t, false, t === type)));

  const hidden = document.createElement("input");
  hidden.type = "hidden";
  hidden.id = "rfsd_assoc_data";
  hidden.value = typeof data === "string" ? data : JSON.stringify(data || {});

  const container = document.createElement("div");
  container.id = "rfsd_assoc_container";

  document.body.append(select, hidden, container);
}

/** Load the script with select2 and $.get stubbed. */
async function boot(type, data, getResponse) {
  renderMetaBox(type, data);
  window.rfsdAdmin = {
    ajax_url: "/wp-admin/admin-ajax.php",
    nonce: "abc123",
    post_id: 42,
  };

  const select2 = jest.fn(function () {
    return this;
  });
  const get = fakeRequest({ response: getResponse });

  const $ = await loadScript(loadAdmin, (jq) => {
    jq.fn.select2 = select2;
    jq.get = get;
  });
  return { $, select2, get };
}

const container = () => document.getElementById("rfsd_assoc_container");

afterEach(() => {
  delete window.jQuery;
  delete window.$;
  delete window.rfsdAdmin;
});

describe("URL associations", () => {
  test("renders saved URLs one per line", async () => {
    await boot("urls", { urls: ["https://a.test/", "https://b.test/x"] });

    const textarea = document.getElementById("rfsd_assoc_urls");
    expect(textarea).not.toBeNull();
    expect(textarea.name).toBe("rfsd_assoc_urls");
    expect(textarea.value).toBe("https://a.test/\nhttps://b.test/x");
  });

  test("renders an empty textarea without saved data", async () => {
    await boot("urls", {});
    expect(document.getElementById("rfsd_assoc_urls").value).toBe("");
  });
});

describe("post associations", () => {
  test("sets up Select2 with the search endpoint", async () => {
    const { select2 } = await boot("posts", {});

    expect(select2).toHaveBeenCalledTimes(1);
    const opts = select2.mock.calls[0][0];
    expect(opts.minimumInputLength).toBe(1);
    expect(opts.ajax.url).toBe("/wp-admin/admin-ajax.php");
    expect(opts.ajax.data({ term: "ship" })).toEqual({
      q: "ship",
      action: "rfsd_search_posts",
      nonce: "abc123",
    });
    const results = [{ id: 1, text: "One" }];
    expect(opts.ajax.processResults(results)).toEqual({ results });
    expect(opts.templateResult({ id: 1, text: "One" })).toBe("One");
    expect(opts.templateResult({ text: "Searching…" })).toBe("Searching…");
    expect(opts.templateSelection({ id: 1, text: "One" })).toBe("One");

    const select = document.getElementById("rfsd_assoc_posts_select");
    expect(select.name).toBe("rfsd_assoc_posts_select[]");
    expect(select.multiple).toBe(true);
  });

  test("preselects saved posts", async () => {
    await boot("posts", {
      posts: [
        { id: 5, text: "Five – http://x/five/" },
        { id: 9, text: "Post #9 (not found)" },
      ],
    });

    const options = Array.from(
      document.querySelectorAll("#rfsd_assoc_posts_select option")
    );
    expect(options.map((o) => [o.value, o.text, o.selected])).toEqual([
      ["5", "Five – http://x/five/", true],
      ["9", "Post #9 (not found)", true],
    ]);
  });
});

describe("post type associations", () => {
  test("loads public post types and checks saved ones", async () => {
    const { get } = await boot("post_types", { post_types: ["page"] }, [
      { name: "post", label: "Post" },
      { name: "page", label: "Page" },
    ]);

    expect(get).toHaveBeenCalledWith(
      "/wp-admin/admin-ajax.php",
      { action: "rfsd_get_post_types", nonce: "abc123" },
      expect.any(Function),
      "json"
    );

    const boxes = Array.from(
      document.querySelectorAll('input[name="rfsd_assoc_post_types[]"]')
    );
    expect(boxes.map((b) => [b.value, b.checked])).toEqual([
      ["post", false],
      ["page", true],
    ]);
    expect(container().textContent).toContain("Page");
  });

  test("renders no checkboxes for an empty response", async () => {
    await boot("post_types", {}, []);
    expect(
      document.querySelectorAll('input[name="rfsd_assoc_post_types[]"]')
    ).toHaveLength(0);
  });

  test("tolerates a null response", async () => {
    await boot("post_types", {}, null);
    expect(document.getElementById("rfsd_post_types_wrap").children).toHaveLength(0);
  });
});

describe("taxonomy term associations", () => {
  test("sets up Select2 with the term search endpoint and preselects terms", async () => {
    const { select2 } = await boot("tax_terms", {
      terms: [{ id: 3, text: "News (category)" }],
    });

    const opts = select2.mock.calls[0][0];
    expect(opts.ajax.data({ term: "ne" })).toEqual({
      q: "ne",
      action: "rfsd_search_terms",
      nonce: "abc123",
    });
    expect(opts.ajax.processResults([])).toEqual({ results: [] });
    expect(opts.templateResult({ text: "T" })).toBe("T");
    expect(opts.templateSelection({ text: "T" })).toBe("T");

    const option = document.querySelector("#rfsd_assoc_terms_select option");
    expect([option.value, option.text, option.selected]).toEqual([
      "3",
      "News (category)",
      true,
    ]);
  });
});

describe("global association", () => {
  test("renders a hidden flag", async () => {
    await boot("global", {});
    const flag = document.querySelector('input[name="rfsd_assoc_global"]');
    expect(flag.type).toBe("hidden");
    expect(flag.value).toBe("1");
  });
});

describe("initial data and switching", () => {
  test("falls back to empty data on invalid JSON", async () => {
    await boot("urls", "{not json");
    expect(document.getElementById("rfsd_assoc_urls").value).toBe("");
  });

  test("renders nothing for an unknown type", async () => {
    await boot("other", {});
    expect(container().innerHTML).toBe("");
  });

  test("changing the type renders the new type and restores the old one", async () => {
    const { $, select2 } = await boot("urls", { urls: ["https://a.test/"] });

    $("#rfsd_assoc_type").val("posts").trigger("change");
    expect(document.getElementById("rfsd_assoc_urls")).toBeNull();
    expect(document.getElementById("rfsd_assoc_posts_select")).not.toBeNull();
    expect(select2).toHaveBeenCalledTimes(1);

    $("#rfsd_assoc_type").val("urls").trigger("change");
    expect(document.getElementById("rfsd_assoc_urls").value).toBe("https://a.test/");
    expect(container().querySelectorAll("textarea")).toHaveLength(1);
  });

  test("switching away and back restores in-progress edits per type", async () => {
    const { $ } = await boot("urls", { urls: ["https://a.test/"] });

    // Edit the initial type, then switch away and back.
    $("#rfsd_assoc_urls").val("https://a.test/\nhttps://edited.test/");
    $("#rfsd_assoc_type").val("posts").trigger("change");

    // Start a selection on the new type.
    $("#rfsd_assoc_posts_select").append(new Option("Five", "5", true, true));
    $("#rfsd_assoc_type").val("urls").trigger("change");
    expect(document.getElementById("rfsd_assoc_urls").value).toBe(
      "https://a.test/\nhttps://edited.test/"
    );

    $("#rfsd_assoc_type").val("posts").trigger("change");
    const options = Array.from(
      document.querySelectorAll("#rfsd_assoc_posts_select option")
    );
    expect(options.map((o) => [o.value, o.text, o.selected])).toEqual([
      ["5", "Five", true],
    ]);
  });

  test("the saved data only belongs to the initially selected type", async () => {
    const { $ } = await boot("urls", { urls: ["https://a.test/"] });
    $("#rfsd_assoc_type").val("tax_terms").trigger("change");
    expect(document.querySelectorAll("#rfsd_assoc_terms_select option")).toHaveLength(0);
  });

  test("keeps saved post types while their request is pending and ignores stale responses", async () => {
    renderMetaBox("post_types", { post_types: ["page"] });
    window.rfsdAdmin = { ajax_url: "/wp-admin/admin-ajax.php", nonce: "abc123" };
    const pending = [];
    const get = jest.fn((url, data, success) => {
      pending.push(success);
      return {
        fail() {
          return this;
        },
      };
    });
    const $ = await loadScript(loadAdmin, (jq) => {
      jq.fn.select2 = jest.fn(function () {
        return this;
      });
      jq.get = get;
    });
    const types = [
      { name: "post", label: "Post" },
      { name: "page", label: "Page" },
    ];

    // Switch away and back before the first request answers.
    $("#rfsd_assoc_type").val("global").trigger("change");
    $("#rfsd_assoc_type").val("post_types").trigger("change");
    pending[0](types);
    pending[1](types);

    const boxes = Array.from(
      document.querySelectorAll('input[name="rfsd_assoc_post_types[]"]')
    );
    expect(boxes.map((b) => [b.value, b.checked])).toEqual([
      ["post", false],
      ["page", true],
    ]);
  });

  test("restores checked post types", async () => {
    const { $ } = await boot("post_types", { post_types: ["page"] }, [
      { name: "post", label: "Post" },
      { name: "page", label: "Page" },
    ]);
    $('input[value="post"]').prop("checked", true);
    $("#rfsd_assoc_type").val("global").trigger("change");
    $("#rfsd_assoc_type").val("post_types").trigger("change");
    const boxes = Array.from(
      document.querySelectorAll('input[name="rfsd_assoc_post_types[]"]')
    );
    expect(boxes.map((b) => [b.value, b.checked])).toEqual([
      ["post", true],
      ["page", true],
    ]);
  });

  test("uses localized strings when provided", async () => {
    renderMetaBox("global", {});
    window.rfsdAdmin = {
      ajax_url: "/x",
      nonce: "n",
      i18n: { globalHelp: "Sitewide FAQ" },
    };
    await loadScript(loadAdmin, (jq) => {
      jq.fn.select2 = jest.fn();
      jq.get = jest.fn();
    });
    expect(container().textContent).toContain("Sitewide FAQ");
  });
});
