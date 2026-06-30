(function (window, document) {
  "use strict";

  if (window.__WELINE_WLS_PANEL__) {
    return;
  }

  var apiBootstrapPromise = null;
  var apiPromise = null;
  var state = {
    activeTab: "overview",
    isOpen: false,
    isLoading: false,
    error: "",
    requestId: "",
    summary: null,
    requests: [],
    detail: null,
    services: null
  };

  var tabs = [
    ["overview", "Overview"],
    ["requests", "Requests"],
    ["waterfall", "Waterfall"],
    ["services", "Services"],
    ["workers", "Workers"],
    ["logs", "Logs"]
  ];

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function ms(value) {
    var number = Number(value || 0);
    if (!Number.isFinite(number)) {
      number = 0;
    }
    return number.toFixed(number >= 100 ? 0 : 2) + " ms";
  }

  function numberText(value) {
    var number = Number(value || 0);
    if (!Number.isFinite(number)) {
      return "0";
    }
    return String(Math.round(number * 100) / 100);
  }

  function getConfig() {
    return window.__WELINE_WLS_PANEL_CONFIG__ || {};
  }

  function root() {
    var node = document.getElementById("weline-wls-performance-panel");
    if (node) {
      return node;
    }

    node = document.createElement("div");
    node.id = "weline-wls-performance-panel";
    node.className = "wls-panel";
    node.hidden = true;
    node.setAttribute("role", "dialog");
    node.setAttribute("aria-modal", "true");
    node.setAttribute("aria-label", "WLS performance panel");
    document.body.appendChild(node);
    node.addEventListener("click", onClick);
    document.addEventListener("keydown", onKeydown);
    return node;
  }

  function ensureApiRuntime() {
    var cfg;
    var apiCfg;
    var scriptUrl;
    var existingScript;

    if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== "function") {
      if (window.WelineApiModule && window.WelineApiModule.__full && typeof window.WelineApiModule.resource === "function") {
        window.Weline = window.Weline || {};
        window.Weline.Api = window.WelineApiModule;
        return Promise.resolve(window.WelineApiModule);
      }
    } else {
      return Promise.resolve(window.Weline.Api);
    }

    if (apiBootstrapPromise) {
      return apiBootstrapPromise;
    }

    cfg = getConfig();
    apiCfg = cfg.api || {};
    scriptUrl = cfg.apiScriptUrl || "/Weline/Frontend/view/statics/js/weline-api.js";

    apiBootstrapPromise = new Promise(function (resolve, reject) {
      function finish() {
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === "function") {
          resolve(window.Weline.Api);
          return;
        }
        if (window.WelineApiModule && window.WelineApiModule.__full && typeof window.WelineApiModule.resource === "function") {
          window.Weline = window.Weline || {};
          window.Weline.Api = window.WelineApiModule;
          resolve(window.WelineApiModule);
          return;
        }
        reject(new Error("Weline.Api.resource is unavailable."));
      }

      window.Weline = window.Weline || {};
      window.Weline.config = window.Weline.config || {};
      window.Weline.config.api = Object.assign({ area: "frontend" }, window.Weline.config.api || {}, apiCfg);
      window.WelineApiConfig = Object.assign({}, window.Weline.config.api || {}, window.WelineApiConfig || {}, apiCfg);

      existingScript = document.querySelector('script[data-weline-wls-panel-api="1"]');
      if (existingScript) {
        if (existingScript.getAttribute("data-loaded") === "1") {
          finish();
          return;
        }
        existingScript.addEventListener("load", finish, { once: true });
        existingScript.addEventListener("error", function () {
          reject(new Error("Failed to load Weline.Api script."));
        }, { once: true });
        return;
      }

      existingScript = document.createElement("script");
      existingScript.src = scriptUrl;
      existingScript.async = true;
      existingScript.setAttribute("data-weline-wls-panel-api", "1");
      existingScript.addEventListener("load", function () {
        existingScript.setAttribute("data-loaded", "1");
        finish();
      }, { once: true });
      existingScript.addEventListener("error", function () {
        reject(new Error("Failed to load Weline.Api script."));
      }, { once: true });
      (document.head || document.documentElement).appendChild(existingScript);
    });

    return apiBootstrapPromise;
  }

  function serverApi() {
    if (!apiPromise) {
      apiPromise = ensureApiRuntime().then(function (api) {
        return api.resource("server");
      });
    }
    return apiPromise;
  }

  function wait(msValue) {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, msValue);
    });
  }

  function isBinaryBootstrapError(error) {
    return String((error && error.message) || error || "").indexOf("Invalid Weline binary magic") !== -1;
  }

  function callOnce(operation, params) {
    return serverApi().then(function (api) {
      if (!api || typeof api[operation] !== "function") {
        throw new Error("Server API operation is unavailable: " + operation);
      }
      return api[operation](params || {});
    });
  }

  function call(operation, params) {
    return callOnce(operation, params).catch(function (error) {
      if (!isBinaryBootstrapError(error)) {
        throw error;
      }
      apiPromise = null;
      return wait(160).then(function () {
        return callOnce(operation, params);
      });
    });
  }

  function normalizeRequests(result) {
    if (Array.isArray(result)) {
      return result;
    }
    if (result && Array.isArray(result.requests)) {
      return result.requests;
    }
    if (result && result.data && Array.isArray(result.data)) {
      return result.data;
    }
    return [];
  }

  function normalizeDetail(result) {
    if (result && result.request) {
      return result.request;
    }
    if (result && result.data) {
      return result.data;
    }
    return result || null;
  }

  function setLoading(flag) {
    state.isLoading = flag;
    render();
  }

  function open(options) {
    var cfg = getConfig();
    state.isOpen = true;
    state.requestId = (options && options.requestId) || cfg.requestId || state.requestId || "";
    root().hidden = false;
    render();
    refresh(state.requestId);
    setTimeout(function () {
      var close = root().querySelector("[data-action='close']");
      if (close && typeof close.focus === "function") {
        close.focus();
      }
    }, 0);
  }

  function close() {
    state.isOpen = false;
    root().hidden = true;
  }

  function refresh(detailRequestId) {
    var selected = "";

    state.error = "";
    setLoading(true);
    call("wlsPerformanceSummary", { window_sec: 300 })
      .then(function (summary) {
        state.summary = summary || null;
        return call("wlsPerformanceRequests", { limit: 80 });
      })
      .then(function (requests) {
        state.requests = normalizeRequests(requests);
        return call("wlsPerformanceServices", {});
      })
      .then(function (services) {
        state.services = services || null;
        selected = detailRequestId || state.requestId || (state.requests[0] && state.requests[0].request_id) || "";
        if (!selected) {
          return null;
        }
        return call("wlsPerformanceRequestDetail", { request_id: selected }).then(function (detail) {
          state.detail = normalizeDetail(detail);
          state.requestId = selected;
        });
      })
      .catch(function (error) {
        state.error = error && error.message ? error.message : String(error);
      })
      .finally(function () {
        setLoading(false);
      });
  }

  function clear() {
    state.error = "";
    setLoading(true);
    call("wlsPerformanceClear", {})
      .then(function () {
        state.summary = null;
        state.requests = [];
        state.detail = null;
        state.services = null;
      })
      .catch(function (error) {
        state.error = error && error.message ? error.message : String(error);
      })
      .finally(function () {
        setLoading(false);
      });
  }

  function loadDetail(requestId) {
    if (!requestId) {
      return;
    }
    state.requestId = requestId;
    state.error = "";
    setLoading(true);
    call("wlsPerformanceRequestDetail", { request_id: requestId })
      .then(function (detail) {
        state.detail = normalizeDetail(detail);
        state.activeTab = "waterfall";
      })
      .catch(function (error) {
        state.error = error && error.message ? error.message : String(error);
      })
      .finally(function () {
        setLoading(false);
      });
  }

  function onKeydown(event) {
    if (event.key === "Escape" && state.isOpen) {
      close();
    }
  }

  function onClick(event) {
    var target = event.target.closest("[data-action]");
    if (!target) {
      return;
    }
    var action = target.getAttribute("data-action");
    if (action === "close") {
      close();
    } else if (action === "refresh") {
      refresh(state.requestId);
    } else if (action === "clear") {
      clear();
    } else if (action === "tab") {
      state.activeTab = target.getAttribute("data-tab") || "overview";
      render();
    } else if (action === "detail") {
      loadDetail(target.getAttribute("data-request-id") || "");
    }
  }

  function render() {
    if (!state.isOpen) {
      return;
    }
    var node = root();
    node.innerHTML =
      '<div class="wls-panel__scrim" data-action="close"></div>' +
      '<section class="wls-panel__shell">' +
      renderHeader() +
      renderTabs() +
      '<div class="wls-panel__body">' +
      renderBody() +
      "</div>" +
      "</section>";
  }

  function renderHeader() {
    var requestId = state.requestId ? "Request " + state.requestId : "No request selected";
    return (
      '<header class="wls-panel__header">' +
      '<div class="wls-panel__title"><strong>WLS Performance</strong><span>' +
      escapeHtml(requestId) +
      "</span></div>" +
      '<div class="wls-panel__actions">' +
      '<button type="button" data-action="refresh">Refresh</button>' +
      '<button type="button" data-action="clear">Clear</button>' +
      '<button type="button" class="wls-panel__close" data-action="close" aria-label="Close">x</button>' +
      "</div>" +
      "</header>"
    );
  }

  function renderTabs() {
    return (
      '<nav class="wls-panel__tabs" role="tablist">' +
      tabs
        .map(function (tab) {
          return (
            '<button type="button" class="wls-panel__tab" role="tab" data-action="tab" data-tab="' +
            tab[0] +
            '" aria-selected="' +
            (state.activeTab === tab[0] ? "true" : "false") +
            '">' +
            escapeHtml(tab[1]) +
            "</button>"
          );
        })
        .join("") +
      "</nav>"
    );
  }

  function renderBody() {
    if (state.error) {
      return '<div class="wls-error">' + escapeHtml(state.error) + "</div>";
    }
    if (state.isLoading && !state.summary && !state.requests.length) {
      return '<div class="wls-loading">Loading WLS performance data...</div>';
    }
    if (state.activeTab === "overview") {
      return renderOverview();
    }
    if (state.activeTab === "requests") {
      return renderRequests();
    }
    if (state.activeTab === "waterfall") {
      return renderWaterfall();
    }
    if (state.activeTab === "services") {
      return renderServices();
    }
    if (state.activeTab === "workers") {
      return renderWorkers();
    }
    return renderLogs();
  }

  function metric(label, value, hint, modifier) {
    return (
      '<div class="wls-tile ' +
      escapeHtml(modifier || "") +
      '"><div class="wls-tile__label">' +
      escapeHtml(label) +
      '</div><div class="wls-tile__value">' +
      escapeHtml(value) +
      '</div><div class="wls-tile__hint">' +
      escapeHtml(hint || "") +
      "</div></div>"
    );
  }

  function renderOverview() {
    var s = state.summary || {};
    var slowest = s.slowest || {};
    var detail = state.detail || {};
    var timing = detail.timing || {};
    var runtime = detail.runtime || {};
    return (
      '<div class="wls-grid">' +
      '<div class="wls-grid wls-grid--metrics">' +
      metric("Requests", numberText(s.request_count), "last 5 minutes") +
      metric("Average", ms(s.avg_ms), "all captured requests") +
      metric("P95", ms(s.p95_ms), "tail latency") +
      metric("P99", ms(s.p99_ms), "worst tail") +
      metric("FPC hits", numberText(s.fpc_hit_count), "short-term buffer") +
      metric("Errors", numberText(s.error_count), "HTTP 5xx") +
      "</div>" +
      '<div class="wls-grid wls-grid--two">' +
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">Current request</div></div>' +
      renderKeyValues({
        total: ms(detail.total_ms || timing.total_ms || 0),
        uri: (detail.request && detail.request.uri) || "",
        worker: [runtime.worker_id || "", runtime.worker_port || ""].filter(Boolean).join(" / "),
        session: ms(timing.session_start_ms || 0),
        router: ms(timing.router_start_ms || timing.router_start_call_ms || 0),
        fpc: detail.fpc && detail.fpc.hit ? "hit " + (detail.fpc.source || "") : "miss"
      }) +
      "</section>" +
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">Slowest request</div></div>' +
      renderKeyValues({
        total: ms(slowest.total_ms || 0),
        uri: slowest.uri || "",
        worker: [slowest.worker_id || "", slowest.worker_port || ""].filter(Boolean).join(" / "),
        fpc: slowest.fpc_hit ? "hit " + (slowest.fpc_source || "") : "miss",
        db: ms(slowest.db_ms || 0),
        template: ms(slowest.template_ms || 0)
      }) +
      "</section>" +
      "</div>" +
      renderCategoryTotals(s.category_totals || {}) +
      "</div>"
    );
  }

  function renderKeyValues(values) {
    return (
      '<dl class="wls-kv">' +
      Object.keys(values)
        .map(function (key) {
          return "<dt>" + escapeHtml(key) + "</dt><dd>" + escapeHtml(values[key] || "-") + "</dd>";
        })
        .join("") +
      "</dl>"
    );
  }

  function renderCategoryTotals(totals) {
    var keys = Object.keys(totals || {});
    if (!keys.length) {
      return "";
    }
    return (
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">Component totals</div></div>' +
      '<div class="wls-grid wls-grid--metrics">' +
      keys
        .sort(function (a, b) {
          return Number(totals[b] || 0) - Number(totals[a] || 0);
        })
        .slice(0, 12)
        .map(function (key) {
          return metric(key, ms(totals[key]), "aggregated span time");
        })
        .join("") +
      "</div></section>"
    );
  }

  function renderRequests() {
    if (!state.requests.length) {
      return '<div class="wls-empty">No requests captured yet. Load a page in DEV mode, then type wls again.</div>';
    }
    return (
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">Recent requests</div><span class="wls-pill">' +
      state.requests.length +
      " rows</span></div>" +
      '<div class="wls-table-wrap"><table class="wls-table"><thead><tr>' +
      "<th>URI</th><th>Status</th><th>Total</th><th>Worker</th><th>FPC</th><th>Session</th><th>DB</th><th>Template</th>" +
      "</tr></thead><tbody>" +
      state.requests.map(renderRequestRow).join("") +
      "</tbody></table></div></section>"
    );
  }

  function renderRequestRow(row) {
    var status = Number(row.status || 0);
    var statusClass = status >= 500 ? "wls-pill--danger" : status >= 400 ? "wls-pill--warn" : "wls-pill--ok";
    return (
      '<tr data-action="detail" data-request-id="' +
      escapeHtml(row.request_id || "") +
      '">' +
      '<td><div class="wls-uri">' +
      escapeHtml(row.method || "") +
      " " +
      escapeHtml(row.uri || "") +
      "</div></td>" +
      '<td><span class="wls-pill ' +
      statusClass +
      '">' +
      escapeHtml(status || "-") +
      "</span></td>" +
      "<td>" +
      escapeHtml(ms(row.total_ms)) +
      "</td><td>" +
      escapeHtml([row.worker_id || "", row.worker_port || ""].filter(Boolean).join(" / ") || "-") +
      "</td><td>" +
      escapeHtml(row.fpc_hit ? "hit " + (row.fpc_source || "") : "miss") +
      "</td><td>" +
      escapeHtml(ms(row.session_ms)) +
      "</td><td>" +
      escapeHtml(ms(row.db_ms)) +
      "</td><td>" +
      escapeHtml(ms(row.template_ms)) +
      "</td></tr>"
    );
  }

  function selectedSpans() {
    var detail = state.detail || {};
    var trace = detail.trace || {};
    var spans = Array.isArray(trace.spans) ? trace.spans.slice() : [];
    spans.sort(function (a, b) {
      return Number(b.duration_ms || 0) - Number(a.duration_ms || 0);
    });
    return spans;
  }

  function renderWaterfall() {
    var spans = selectedSpans();
    if (!spans.length) {
      return '<div class="wls-empty">No spans for the selected request yet.</div>';
    }
    var max = spans.reduce(function (carry, span) {
      return Math.max(carry, Number(span.duration_ms || 0));
    }, 1);
    return (
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">Waterfall by duration</div><span class="wls-pill">' +
      spans.length +
      " spans</span></div>" +
      '<div class="wls-waterfall">' +
      spans
        .slice(0, 160)
        .map(function (span) {
          var width = Math.max(1, Math.round((Number(span.duration_ms || 0) / max) * 100));
          var meta = span.meta ? " " + JSON.stringify(span.meta) : "";
          return (
            '<div class="wls-waterfall__row"><div class="wls-waterfall__name">' +
            escapeHtml((span.category || "framework") + " / " + (span.name || "") + meta) +
            '</div><div class="wls-waterfall__bar"><span class="wls-waterfall__fill" style="width:' +
            width +
            '%"></span></div><div class="wls-waterfall__time">' +
            escapeHtml(ms(span.duration_ms)) +
            "</div></div>"
          );
        })
        .join("") +
      "</div></section>"
    );
  }

  function renderServices() {
    var services = (state.services && state.services.services) || {};
    var keys = Object.keys(services);
    if (!keys.length) {
      return '<div class="wls-empty">No SessionServer or MemoryServer samples yet.</div>';
    }
    return (
      '<div class="wls-grid wls-grid--two">' +
      keys
        .map(function (key) {
          var item = services[key] || {};
          return (
            '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">' +
            escapeHtml(key) +
            '</div><span class="wls-pill">' +
            escapeHtml(numberText(item.sample_count)) +
            " samples</span></div>" +
            renderKeyValues({
              last: ms(item.last_ms),
              max: ms(item.max_ms),
              span: item.last_span || "-",
              request: item.last_request_id || "-",
              seen: item.last_seen_at ? new Date(Number(item.last_seen_at) * 1000).toLocaleString() : "-"
            }) +
            "</section>"
          );
        })
        .join("") +
      "</div>"
    );
  }

  function renderWorkers() {
    var workers = {};
    state.requests.forEach(function (row) {
      var key = [row.worker_id || "unknown", row.worker_port || "", row.pid || ""].join(":");
      if (!workers[key]) {
        workers[key] = {
          key: key,
          worker_id: row.worker_id || "",
          worker_port: row.worker_port || "",
          pid: row.pid || "",
          count: 0,
          slow: 0,
          errors: 0,
          total: 0
        };
      }
      workers[key].count += 1;
      workers[key].total += Number(row.total_ms || 0);
      if (Number(row.total_ms || 0) >= 500) {
        workers[key].slow += 1;
      }
      if (Number(row.status || 0) >= 500) {
        workers[key].errors += 1;
      }
    });
    var rows = Object.keys(workers).map(function (key) {
      return workers[key];
    });
    if (!rows.length) {
      return '<div class="wls-empty">No worker samples yet.</div>';
    }
    return (
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">Workers</div></div>' +
      '<div class="wls-table-wrap"><table class="wls-table"><thead><tr><th>Worker</th><th>PID</th><th>Requests</th><th>Slow</th><th>Errors</th><th>Average</th></tr></thead><tbody>' +
      rows
        .map(function (row) {
          return (
            "<tr><td>" +
            escapeHtml([row.worker_id || "unknown", row.worker_port || ""].filter(Boolean).join(" / ")) +
            "</td><td>" +
            escapeHtml(row.pid || "-") +
            "</td><td>" +
            escapeHtml(row.count) +
            "</td><td>" +
            escapeHtml(row.slow) +
            "</td><td>" +
            escapeHtml(row.errors) +
            "</td><td>" +
            escapeHtml(ms(row.count ? row.total / row.count : 0)) +
            "</td></tr>"
          );
        })
        .join("") +
      "</tbody></table></div></section>"
    );
  }

  function renderLogs() {
    var detail = state.detail || {};
    var timing = detail.timing || {};
    var payload = {
      timing: timing,
      trace_top: timing.trace_top || [],
      trace_db_top: timing.trace_db_top || [],
      category_totals: (detail.trace && detail.trace.category_totals) || {}
    };
    return (
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">Timing snapshot</div></div>' +
      '<pre class="wls-code">' +
      escapeHtml(JSON.stringify(payload, null, 2)) +
      "</pre></section>"
    );
  }

  window.__WELINE_WLS_PANEL__ = {
    open: open,
    close: close,
    refresh: function () {
      refresh(state.requestId);
    }
  };
})(window, document);
