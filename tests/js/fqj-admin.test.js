const { loadScript, fakeRequest } = require("./helpers");

const loadAdmin = () => require("../../assets/js/fqj-admin.js");

function renderMetaBox(type, data) {
  const types = ["urls", "posts", "post_types", "tax_terms", "global", "other"];
  document.body.replaceChildren();

  const select = document.createElement("select");
  select.id = "fqj_assoc_type";
  types.forEach((t) => select.append(new Option(t, t, false, t === type)));

  const hidden = document.createElement("input");
  hidden.type = "hidden";
  hidden.id = "fqj_assoc_data";
  hidden.value = typeof data === "string" ? data : JSON.stringify(data || {});

  const container = document.createElement("div");
  container.id = "fqj_assoc_container";

  document.body.append(select, hidden, container);
}

/** Load the script with select2 and $.get stubbed. */
async function boot(type, data, getResponse) {
  renderMetaBox(type, data);
  window.fqjAdmin = {
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

const container = () => document.getElementById("fqj_assoc_container");

afterEach(() => {
  delete window.jQuery;
  delete window.$;
  delete window.fqjAdmin;
});

describe("URL associations", () => {
  test("renders saved URLs one per line", async () => {
    await boot("urls", { urls: ["https://a.test/", "https://b.test/x"] });

    const textarea = document.getElementById("fqj_assoc_urls");
    expect(textarea).not.toBeNull();
    expect(textarea.name).toBe("fqj_assoc_urls");
    expect(textarea.value).toBe("https://a.test/\nhttps://b.test/x");
  });

  test("renders an empty textarea without saved data", async () => {
    await boot("urls", {});
    expect(document.getElementById("fqj_assoc_urls").value).toBe("");
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
      action: "fqj_search_posts",
      nonce: "abc123",
    });
    const results = [{ id: 1, text: "One" }];
    expect(opts.ajax.processResults(results)).toEqual({ results });
    expect(opts.templateResult({ id: 1, text: "One" })).toBe("One");
    expect(opts.templateResult({ text: "Searching…" })).toBe("Searching…");
    expect(opts.templateSelection({ id: 1, text: "One" })).toBe("One");

    const select = document.getElementById("fqj_assoc_posts_select");
    expect(select.name).toBe("fqj_assoc_posts_select[]");
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
      document.querySelectorAll("#fqj_assoc_posts_select option")
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
      { action: "fqj_get_post_types", nonce: "abc123" },
      expect.any(Function),
      "json"
    );

    const boxes = Array.from(
      document.querySelectorAll('input[name="fqj_assoc_post_types[]"]')
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
      document.querySelectorAll('input[name="fqj_assoc_post_types[]"]')
    ).toHaveLength(0);
  });

  test("tolerates a null response", async () => {
    await boot("post_types", {}, null);
    expect(document.getElementById("fqj_post_types_wrap").children).toHaveLength(0);
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
      action: "fqj_search_terms",
      nonce: "abc123",
    });
    expect(opts.ajax.processResults([])).toEqual({ results: [] });
    expect(opts.templateResult({ text: "T" })).toBe("T");
    expect(opts.templateSelection({ text: "T" })).toBe("T");

    const option = document.querySelector("#fqj_assoc_terms_select option");
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
    const flag = document.querySelector('input[name="fqj_assoc_global"]');
    expect(flag.type).toBe("hidden");
    expect(flag.value).toBe("1");
  });
});

describe("initial data and switching", () => {
  test("falls back to empty data on invalid JSON", async () => {
    await boot("urls", "{not json");
    expect(document.getElementById("fqj_assoc_urls").value).toBe("");
  });

  test("renders nothing for an unknown type", async () => {
    await boot("other", {});
    expect(container().innerHTML).toBe("");
  });

  test("changing the type re-renders without the saved data", async () => {
    const { $, select2 } = await boot("urls", { urls: ["https://a.test/"] });

    $("#fqj_assoc_type").val("posts").trigger("change");
    expect(document.getElementById("fqj_assoc_urls")).toBeNull();
    expect(document.getElementById("fqj_assoc_posts_select")).not.toBeNull();
    expect(select2).toHaveBeenCalledTimes(1);

    $("#fqj_assoc_type").val("urls").trigger("change");
    expect(document.getElementById("fqj_assoc_urls").value).toBe("");
    expect(container().querySelectorAll("textarea")).toHaveLength(1);
  });
});
