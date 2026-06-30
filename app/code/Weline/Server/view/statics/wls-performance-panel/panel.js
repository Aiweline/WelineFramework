(function (window, document) {
  "use strict";

  if (window.__WELINE_WLS_PANEL__) {
    return;
  }

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
    ["overview", "概览"],
    ["requests", "请求"],
    ["waterfall", "瀑布图"],
    ["services", "服务"],
    ["workers", "工作进程"],
    ["logs", "日志"]
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
    node.setAttribute("aria-label", "WLS 性能面板");
    document.body.appendChild(node);
    node.addEventListener("click", onClick);
    document.addEventListener("keydown", onKeydown);
    return node;
  }

  function readableError(error) {
    var message = String((error && error.message) || error || "");
    if (message.indexOf("Invalid Weline binary magic") !== -1) {
      return "WLS API 响应格式异常，请刷新页面后重试。";
    }
    if (message.indexOf("Unexpected end of Weline binary packet") !== -1) {
      return "WLS API 响应不完整，请刷新页面后重试。";
    }
    if (message.indexOf("Unsupported Weline binary") !== -1 || message.indexOf("Unknown Weline binary") !== -1) {
      return "WLS API 响应版本不兼容，请刷新页面后重试。";
    }
    return message || "未知错误";
  }

  function endpointBase() {
    var cfg = getConfig();
    return String(cfg.endpointUrl || cfg.performanceEndpointUrl || "/server/test/wls-performance-panel").replace(/\/+$/, "");
  }

  function operationPath(operation) {
    var map = {
      wlsPerformanceSummary: "summary",
      wlsPerformanceRequests: "requests",
      wlsPerformanceRequestDetail: "request-detail",
      wlsPerformanceServices: "services",
      wlsPerformanceClear: "clear"
    };

    return map[operation] || operation;
  }

  function requestUrl(operation, params) {
    var url = new URL(endpointBase() + "/" + operationPath(operation), window.location.origin);
    Object.keys(params || {}).forEach(function (key) {
      var value = params[key];
      if (value === undefined || value === null || value === "") {
        return;
      }
      url.searchParams.set(key, String(value));
    });
    url.searchParams.set("_", String(Date.now()));
    return url.toString();
  }

  function parseJsonResponse(response) {
    return response.text().then(function (text) {
      var payload;
      var trimmed = String(text || "").trim();
      if (!trimmed) {
        payload = {};
      } else {
        try {
          payload = JSON.parse(trimmed);
        } catch (error) {
          throw new Error("WLS 面板端点返回了非 JSON 响应。");
        }
      }
      if (!response.ok) {
        throw new Error((payload && payload.message) || ("WLS 面板端点请求失败：" + response.status));
      }
      return payload;
    });
  }

  function call(operation, params) {
    var isClear = operation === "wlsPerformanceClear";
    return window.fetch(requestUrl(operation, isClear ? {} : (params || {})), {
      method: isClear ? "POST" : "GET",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Cache-Control": "no-cache",
        "X-Weline-Wls-Panel": "1",
        "X-WLS-FPC-Bypass": "1"
      }
    }).then(parseJsonResponse).then(function (payload) {
      if (payload && payload.success === false && operation !== "wlsPerformanceRequestDetail") {
        throw new Error(payload.message || "WLS 面板请求失败。");
      }
      return payload;
    }).catch(function (error) {
      if (error && error.name === "TypeError") {
        throw new Error("WLS 面板端点暂不可用，请确认 WLS 已重载路由。");
      }
      throw error;
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
        state.error = readableError(error);
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
        state.error = readableError(error);
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
        state.error = readableError(error);
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
    var requestId = state.requestId ? "请求 " + state.requestId : "未选择请求";
    return (
      '<header class="wls-panel__header">' +
      '<div class="wls-panel__title"><strong>WLS 性能</strong><span>' +
      escapeHtml(requestId) +
      "</span></div>" +
      '<div class="wls-panel__actions">' +
      '<button type="button" data-action="refresh">刷新</button>' +
      '<button type="button" data-action="clear">清空</button>' +
      '<button type="button" class="wls-panel__close" data-action="close" aria-label="关闭">x</button>' +
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
      return '<div class="wls-loading">正在加载 WLS 性能数据...</div>';
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
      metric("请求数", numberText(s.request_count), "最近 5 分钟") +
      metric("平均耗时", ms(s.avg_ms), "已采集请求") +
      metric("P95", ms(s.p95_ms), "尾部延迟") +
      metric("P99", ms(s.p99_ms), "最慢尾部") +
      metric("FPC 命中", numberText(s.fpc_hit_count), "短期缓冲") +
      metric("错误数", numberText(s.error_count), "HTTP 5xx") +
      "</div>" +
      '<div class="wls-grid wls-grid--two">' +
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">当前请求</div></div>' +
      renderKeyValues({
        "总耗时": ms(detail.total_ms || timing.total_ms || 0),
        URI: (detail.request && detail.request.uri) || "",
        "工作进程": [runtime.worker_id || "", runtime.worker_port || ""].filter(Boolean).join(" / "),
        "会话": ms(timing.session_start_ms || 0),
        "路由": ms(timing.router_start_ms || timing.router_start_call_ms || 0),
        FPC: fpcText(detail.fpc && detail.fpc.hit, detail.fpc && detail.fpc.source)
      }) +
      "</section>" +
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">最慢请求</div></div>' +
      renderKeyValues({
        "总耗时": ms(slowest.total_ms || 0),
        URI: slowest.uri || "",
        "工作进程": [slowest.worker_id || "", slowest.worker_port || ""].filter(Boolean).join(" / "),
        FPC: fpcText(slowest.fpc_hit, slowest.fpc_source),
        DB: ms(slowest.db_ms || 0),
        模板: ms(slowest.template_ms || 0)
      }) +
      "</section>" +
      "</div>" +
      renderCategoryTotals(s.category_totals || {}) +
      "</div>"
    );
  }

  function fpcText(hit, source) {
    return hit ? "命中 " + (source || "") : "未命中";
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
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">组件耗时汇总</div></div>' +
      '<div class="wls-grid wls-grid--metrics">' +
      keys
        .sort(function (a, b) {
          return Number(totals[b] || 0) - Number(totals[a] || 0);
        })
        .slice(0, 12)
        .map(function (key) {
          return metric(key, ms(totals[key]), "聚合 Span 耗时");
        })
        .join("") +
      "</div></section>"
    );
  }

  function renderRequests() {
    if (!state.requests.length) {
      return '<div class="wls-empty">还没有采集到请求。在 DEV 模式加载一个页面后，再输入 wls 打开面板。</div>';
    }
    return (
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">最近请求</div><span class="wls-pill">' +
      state.requests.length +
      " 行</span></div>" +
      '<div class="wls-table-wrap"><table class="wls-table"><thead><tr>' +
      "<th>URI</th><th>状态</th><th>总耗时</th><th>工作进程</th><th>FPC</th><th>会话</th><th>DB</th><th>模板</th>" +
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
      escapeHtml(fpcText(row.fpc_hit, row.fpc_source)) +
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
      return '<div class="wls-empty">当前请求还没有 Span 数据。</div>';
    }
    var max = spans.reduce(function (carry, span) {
      return Math.max(carry, Number(span.duration_ms || 0));
    }, 1);
    return (
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">按耗时排序的瀑布图</div><span class="wls-pill">' +
      spans.length +
      " 个 Span</span></div>" +
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
      return '<div class="wls-empty">还没有 SessionServer 或 MemoryServer 采样。</div>';
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
            " 个样本</span></div>" +
            renderKeyValues({
              "最近耗时": ms(item.last_ms),
              "最大耗时": ms(item.max_ms),
              Span: item.last_span || "-",
              请求: item.last_request_id || "-",
              "最后出现": item.last_seen_at ? new Date(Number(item.last_seen_at) * 1000).toLocaleString() : "-"
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
      var key = [row.worker_id || "未知", row.worker_port || "", row.pid || ""].join(":");
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
      return '<div class="wls-empty">还没有工作进程采样。</div>';
    }
    return (
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">工作进程</div></div>' +
      '<div class="wls-table-wrap"><table class="wls-table"><thead><tr><th>工作进程</th><th>PID</th><th>请求数</th><th>慢请求</th><th>错误</th><th>平均耗时</th></tr></thead><tbody>' +
      rows
        .map(function (row) {
          return (
            "<tr><td>" +
            escapeHtml([row.worker_id || "未知", row.worker_port || ""].filter(Boolean).join(" / ")) +
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
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">耗时快照</div></div>' +
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
