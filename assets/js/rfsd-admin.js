/* global rfsdAdmin */
jQuery(document).ready(function ($) {
  var $assocType = $("#rfsd_assoc_type");
  var $container = $("#rfsd_assoc_container");

  // Strings come from wp_localize_script (rfsdAdmin.i18n); English is the fallback.
  var i18n = $.extend(
    {
      urlsHelp: "Enter one full URL per line (e.g. https://example.com/about/)",
      postsHelp: "Search posts/pages and add multiple results.",
      postsPlaceholder: "Search posts by title...",
      postTypesHelp: "Select post type(s) to associate this FAQ with.",
      termsHelp: "Search taxonomy terms and add multiple results.",
      termsPlaceholder: "Search terms by name...",
      globalHelp: "This FAQ will be included site-wide."
    },
    (window.rfsdAdmin && window.rfsdAdmin.i18n) || {}
  );

  // Build elements from a tag name and attributes rather than HTML strings.
  function el(tag, attrs) {
    var $el = $(document.createElement(tag));
    if (attrs) $el.attr(attrs);
    return $el;
  }

  // Data the current field was rendered from, a render counter so late AJAX
  // responses for an earlier render are ignored, and whether the post type
  // checkboxes have arrived yet.
  var renderedData = {};
  var renderSeq = 0;
  var postTypesLoaded = false;

  function renderField(type, data) {
    $container.empty();
    renderedData = data;
    renderSeq++;
    postTypesLoaded = false;
    var seq = renderSeq;
    if (type === "urls") {
      var urls = data.urls ? data.urls.join("\n") : "";
      $container.append(el("p").text(i18n.urlsHelp));
      $container.append(
        el("textarea", {
          id: "rfsd_assoc_urls",
          name: "rfsd_assoc_urls",
          rows: 6,
          style: "width:100%;"
        }).val(urls)
      );
    } else if (type === "posts") {
      $container.append(el("p").text(i18n.postsHelp));
      $container.append(
        el("select", {
          id: "rfsd_assoc_posts_select",
          name: "rfsd_assoc_posts_select[]",
          multiple: "multiple",
          style: "width:100%"
        })
      );
      $("#rfsd_assoc_posts_select").select2({
        placeholder: i18n.postsPlaceholder,
        ajax: {
          url: rfsdAdmin.ajax_url,
          dataType: "json",
          delay: 250,
          data: function (params) {
            return {
              q: params.term,
              action: "rfsd_search_posts",
              nonce: rfsdAdmin.nonce
            };
          },
          processResults: function (data) {
            return { results: data };
          },
          cache: true
        },
        minimumInputLength: 1,
        templateResult: function (item) {
          if (!item.id) return item.text;
          return item.text;
        },
        templateSelection: function (item) {
          return item.text;
        }
      });

      if (data.posts && data.posts.length) {
        var select = $("#rfsd_assoc_posts_select");
        data.posts.forEach(function (p) {
          var option = new Option(p.text, p.id, true, true);
          select.append(option);
        });
        select.trigger("change");
      }
    } else if (type === "post_types") {
      $container.append(el("p").text(i18n.postTypesHelp));
      $container.append(el("div", { id: "rfsd_post_types_wrap" }));
      $.get(
        rfsdAdmin.ajax_url,
        { action: "rfsd_get_post_types", nonce: rfsdAdmin.nonce },
        function (resp) {
          if (seq !== renderSeq) return;
          if (!resp || !resp.length) return;
          var wrap = $("#rfsd_post_types_wrap");
          resp.forEach(function (pt) {
            var checked =
              data.post_types && data.post_types.indexOf(pt.name) !== -1
                ? "checked"
                : "";
            var $box = el("input", {
              type: "checkbox",
              name: "rfsd_assoc_post_types[]"
            })
              .val(pt.name)
              .prop("checked", !!checked);
            wrap.append(
              el("label", { style: "display:block;margin-bottom:4px;" })
                .append($box)
                .append(document.createTextNode(" " + pt.label))
            );
          });
          postTypesLoaded = true;
        },
        "json"
      );
    } else if (type === "tax_terms") {
      $container.append(el("p").text(i18n.termsHelp));
      $container.append(
        el("select", {
          id: "rfsd_assoc_terms_select",
          name: "rfsd_assoc_terms_select[]",
          multiple: "multiple",
          style: "width:100%"
        })
      );
      $("#rfsd_assoc_terms_select").select2({
        placeholder: i18n.termsPlaceholder,
        ajax: {
          url: rfsdAdmin.ajax_url,
          dataType: "json",
          delay: 250,
          data: function (params) {
            return {
              q: params.term,
              action: "rfsd_search_terms",
              nonce: rfsdAdmin.nonce
            };
          },
          processResults: function (data) {
            return { results: data };
          },
          cache: true
        },
        minimumInputLength: 1,
        templateResult: function (item) {
          return item.text;
        },
        templateSelection: function (item) {
          return item.text;
        }
      });
      if (data.terms && data.terms.length) {
        var termsSelect = $("#rfsd_assoc_terms_select");
        data.terms.forEach(function (t) {
          var option = new Option(t.text, t.id, true, true);
          termsSelect.append(option);
        });
        termsSelect.trigger("change");
      }
    } else if (type === "global") {
      $container.append(el("p").text(i18n.globalHelp));
      $container.append(
        el("input", { type: "hidden", name: "rfsd_assoc_global", value: "1" })
      );
    }
  }

  // Selected options of a Select2 field as {id, text} pairs.
  function selectedOptions(selector) {
    return $(selector)
      .find("option:selected")
      .map(function () {
        return { id: this.value, text: this.text };
      })
      .get();
  }

  // Read the in-progress selection out of the currently rendered field.
  function captureState(type) {
    if (type === "urls") {
      var raw = $("#rfsd_assoc_urls").val();
      return {
        urls: raw
          ? raw.split(/\r\n|\n|\r/).filter(function (l) {
              return l.trim() !== "";
            })
          : []
      };
    }
    if (type === "posts") {
      return { posts: selectedOptions("#rfsd_assoc_posts_select") };
    }
    if (type === "tax_terms") {
      return { terms: selectedOptions("#rfsd_assoc_terms_select") };
    }
    if (type === "post_types") {
      // Until the checkboxes load, keep the data they would have shown.
      if (!postTypesLoaded) {
        return { post_types: renderedData.post_types || [] };
      }
      return {
        post_types: $('input[name="rfsd_assoc_post_types[]"]:checked')
          .map(function () {
            return this.value;
          })
          .get()
      };
    }
    return {};
  }

  // preload data
  var initData = {};
  try {
    var raw = $("#rfsd_assoc_data").val();
    if (raw) initData = JSON.parse(raw);
  } catch (e) {
    initData = {};
  }
  if (!initData || typeof initData !== "object") {
    initData = {};
  }

  // The saved data belongs to the type selected on load. Other types start empty,
  // and in-progress edits are remembered per type while switching.
  var currentType = $assocType.val();
  var stateByType = new Map();
  stateByType.set(currentType, initData);

  renderField(currentType, initData);

  $assocType.on("change", function () {
    stateByType.set(currentType, captureState(currentType));
    currentType = $(this).val();
    renderField(currentType, stateByType.get(currentType) || {});
  });
});
