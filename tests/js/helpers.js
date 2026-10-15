/**
 * Load a fresh jQuery, expose it globally, and run a plugin script against the
 * current document. `load()` must require() the script; `setup($)` runs first
 * so tests can stub jQuery methods. Resolves once jQuery's ready callbacks
 * have fired.
 */
async function loadScript(load, setup = () => {}) {
  let $;
  jest.isolateModules(() => {
    $ = require("jquery");
  });
  window.jQuery = window.$ = $;
  setup($);

  // require() (not eval) so Jest can collect coverage for the script.
  jest.isolateModules(load);

  await flush();
  return $;
}

/** Let pending timers run. jQuery's ready callbacks take a few async hops. */
async function flush() {
  for (let i = 0; i < 5; i++) {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}

/**
 * Stand-in for a jqXHR: calls the success callback right away (unless
 * `fail` is set) and lets the test check whether .fail() handlers ran.
 */
function fakeRequest({ response, fail = false, xhr = { status: 500 } } = {}) {
  return jest.fn((url, data, success) => {
    if (!fail && success) {
      success(response);
    }
    return {
      fail(handler) {
        if (fail) {
          handler(xhr);
        }
        return this;
      },
    };
  });
}

module.exports = { loadScript, flush, fakeRequest };
