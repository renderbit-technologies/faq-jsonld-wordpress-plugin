jQuery(document).ready(function ($) {
  var $assocType = $("#fqj_assoc_type");
  var $container = $("#fqj_assoc_container");

  // Strings come from wp_localize_script (fqjAdmin.i18n); English is the fallback.
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
    (window.fqjAdmin && window.fqjAdmin.i18n) || {}
  );

  function renderField(type, data) {
    $container.empty();
    if (type === "urls") {
      var urls = data.urls ? data.urls.join("\n") : "";
      $container.append(
        $("<p>").text(i18n.urlsHelp)
      );
      $container.append(
        $(
          '<textarea id="fqj_assoc_urls" name="fqj_assoc_urls" rows="6" style="width:100%;"></textarea>'
        ).val(urls)
      );
    } else if (type === "posts") {
      $container.append($("<p>").text(i18n.postsHelp));
      $container.append(
        '<select id="fqj_assoc_posts_select" name="fqj_assoc_posts_select[]" multiple="multiple" style="width:100%"></select>'
      );
      $("#fqj_assoc_posts_select").select2({
        placeholder: i18n.postsPlaceholder,
        ajax: {
          url: fqjAdmin.ajax_url,
          dataType: "json",
          delay: 250,
          data: function (params) {
            return {
              q: params.term,
              action: "fqj_search_posts",
              nonce: fqjAdmin.nonce
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
        var select = $("#fqj_assoc_posts_select");
        data.posts.forEach(function (p) {
          var option = new Option(p.text, p.id, true, true);
          select.append(option);
        });
        select.trigger("change");
      }
    } else if (type === "post_types") {
      $container.append($("<p>").text(i18n.postTypesHelp));
      $container.append('<div id="fqj_post_types_wrap"></div>');
      $.get(
        fqjAdmin.ajax_url,
        { action: "fqj_get_post_types", nonce: fqjAdmin.nonce },
        function (resp) {
          if (!resp || !resp.length) return;
          var wrap = $("#fqj_post_types_wrap");
          resp.forEach(function (pt) {
            var checked =
              data.post_types && data.post_types.indexOf(pt.name) !== -1
                ? "checked"
                : "";
            var $box = $(
              '<input type="checkbox" name="fqj_assoc_post_types[]">'
            )
              .val(pt.name)
              .prop("checked", !!checked);
            wrap.append(
              $('<label style="display:block;margin-bottom:4px;"></label>')
                .append($box)
                .append(document.createTextNode(" " + pt.label))
            );
          });
        },
        "json"
      );
    } else if (type === "tax_terms") {
      $container.append($("<p>").text(i18n.termsHelp));
      $container.append(
        '<select id="fqj_assoc_terms_select" name="fqj_assoc_terms_select[]" multiple="multiple" style="width:100%"></select>'
      );
      $("#fqj_assoc_terms_select").select2({
        placeholder: i18n.termsPlaceholder,
        ajax: {
          url: fqjAdmin.ajax_url,
          dataType: "json",
          delay: 250,
          data: function (params) {
            return {
              q: params.term,
              action: "fqj_search_terms",
              nonce: fqjAdmin.nonce
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
        var select = $("#fqj_assoc_terms_select");
        data.terms.forEach(function (t) {
          var option = new Option(t.text, t.id, true, true);
          select.append(option);
        });
        select.trigger("change");
      }
    } else if (type === "global") {
      $container.append($("<p>").text(i18n.globalHelp));
      $container.append(
        '<input type="hidden" name="fqj_assoc_global" value="1">'
      );
    }
  }

  // Read the in-progress selection out of the currently rendered field.
  function captureState(type) {
    var out = {};
    if (type === "urls") {
      var raw = $("#fqj_assoc_urls").val();
      out.urls = raw
        ? raw.split(/\r\n|\n|\r/).filter(function (l) {
            return l.trim() !== "";
          })
        : [];
    } else if (type === "posts" || type === "tax_terms") {
      var key = type === "posts" ? "posts" : "terms";
      var id = type === "posts" ? "#fqj_assoc_posts_select" : "#fqj_assoc_terms_select";
      out[key] = $(id)
        .find("option:selected")
        .map(function () {
          return { id: this.value, text: this.text };
        })
        .get();
    } else if (type === "post_types") {
      out.post_types = $('input[name="fqj_assoc_post_types[]"]:checked')
        .map(function () {
          return this.value;
        })
        .get();
    }
    return out;
  }

  // preload data
  var initData = {};
  try {
    var raw = $("#fqj_assoc_data").val();
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
  var stateByType = {};
  stateByType[currentType] = initData;

  renderField(currentType, initData);

  $assocType.on("change", function () {
    stateByType[currentType] = captureState(currentType);
    currentType = $(this).val();
    renderField(currentType, stateByType[currentType] || {});
  });
});
