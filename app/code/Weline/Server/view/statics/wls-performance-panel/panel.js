(function (window, document) {
  "use strict";

  if (window.__WELINE_PANEL_WLS_AGENT__) {
    return;
  }

  var WLS_PANEL_STATE_KEY = "weline-wls-performance-panel-state-v1";

  var state = {
    activeTab: "overview",
    mountNode: null,
    isLoading: false,
    error: "",
    requestId: "",
    requestFilter: "all",
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

  var requestFilters = [
    ["all", "全部"],
    ["route", "路由"],
    ["static", "静态资源"],
    ["panel", "面板请求"],
    ["api", "API/AJAX"],
    ["fpc", "FPC 命中"],
    ["slow", "慢请求"],
    ["error", "错误"]
  ];

  var staticFilePattern = /\.(?:css|js|mjs|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|otf|eot|wasm|json|xml|txt|pdf)(?:$|[?#])/i;
  var slowRequestMs = 100;
  var AGENT_CONTRACT_VERSION = "weline-panel-wls/v1";
  var AGENT_COMMAND = "weline-panel:wls";
  var AGENT_REPORT_NODE_ID = "weline-panel-wls-report";
  var sensitiveKeyPattern = /(?:^authorization$|^cookie$|password|passwd|pwd|secret|access[_-]?token|refresh[_-]?token|^token$|session[_-]?(?:id|value|token|data)|^sid$|api[_-]?key|nonce)/i;

  function hasOption(options, value) {
    return options.some(function (option) {
      return option[0] === value;
    });
  }

  function readUiState() {
    try {
      if (!window.localStorage) {
        return {};
      }
      var raw = window.localStorage.getItem(WLS_PANEL_STATE_KEY);
      if (!raw) {
        return {};
      }
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function saveUiState() {
    try {
      if (!window.localStorage) {
        return;
      }
      window.localStorage.setItem(
        WLS_PANEL_STATE_KEY,
        JSON.stringify({
          activeTab: state.activeTab,
          requestFilter: state.requestFilter,
          updatedAt: Date.now()
        })
      );
    } catch (error) {
      // Keep diagnostics usable when localStorage is unavailable.
    }
  }

  function restoreUiState() {
    var saved = readUiState();
    if (hasOption(tabs, saved.activeTab)) {
      state.activeTab = saved.activeTab;
    }
    if (hasOption(requestFilters, saved.requestFilter)) {
      state.requestFilter = saved.requestFilter;
    }
  }

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
    return window.__WELINE_PANEL_WLS_CONFIG__ || {};
  }

  function resolveMountNode(target) {
    if (typeof target === "string") {
      return document.querySelector(target);
    }
    return target && target.nodeType === 1 ? target : null;
  }

  function bindMountNode(node) {
    if (!node || node.getAttribute("data-weline-panel-wls-bound") === "1") {
      return;
    }
    node.setAttribute("data-weline-panel-wls-bound", "1");
    node.addEventListener("click", onClick);
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
      return parseJsonPayload(text, response.ok, response.status);
    });
  }

  function parseJsonPayload(text, ok, status) {
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
    if (!ok) {
      throw new Error((payload && payload.message) || ("WLS 面板端点请求失败：" + status));
    }
    return payload;
  }

  function requestWithXhr(url, options) {
    return new Promise(function (resolve, reject) {
      if (typeof window.XMLHttpRequest !== "function") {
        reject(new Error("WLS 面板端点暂不可用，当前浏览器缺少请求能力。"));
        return;
      }
      var xhr = new window.XMLHttpRequest();
      xhr.open(options.method || "GET", url, true);
      xhr.withCredentials = true;
      xhr.timeout = 15000;
      Object.keys(options.headers || {}).forEach(function (key) {
        xhr.setRequestHeader(key, options.headers[key]);
      });
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) {
          return;
        }
        var status = xhr.status === 1223 ? 204 : xhr.status;
        try {
          resolve(parseJsonPayload(xhr.responseText || "", status >= 200 && status < 300, status));
        } catch (error) {
          reject(error);
        }
      };
      xhr.onerror = function () {
        reject(new Error("WLS 面板端点暂不可用，请确认 WLS 已重载路由。"));
      };
      xhr.ontimeout = function () {
        reject(new Error("WLS 面板端点请求超时，请稍后刷新。"));
      };
      xhr.send();
    });
  }

  function withRequestTimeout(request) {
    return new Promise(function (resolve, reject) {
      var settled = false;
      var timer = window.setTimeout(function () {
        if (settled) {
          return;
        }
        settled = true;
        reject(new Error("WLS 面板端点请求超时，请稍后刷新。"));
      }, 15000);

      Promise.resolve(request).then(function (payload) {
        if (settled) {
          return;
        }
        settled = true;
        window.clearTimeout(timer);
        resolve(payload);
      }).catch(function (error) {
        if (settled) {
          return;
        }
        settled = true;
        window.clearTimeout(timer);
        reject(error);
      });
    });
  }

  function call(operation, params) {
    var isClear = operation === "wlsPerformanceClear";
    var url = requestUrl(operation, isClear ? {} : (params || {}));
    var options = {
      method: isClear ? "POST" : "GET",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Cache-Control": "no-cache",
        "X-Weline-Wls-Panel": "1",
        "X-WLS-FPC-Bypass": "1"
      }
    };
    var request;
    try {
      request = typeof window.XMLHttpRequest === "function"
        ? requestWithXhr(url, options)
        : window.fetch(url, options).then(parseJsonResponse);
    } catch (error) {
      request = Promise.reject(error);
    }
    return withRequestTimeout(request).then(function (payload) {
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

  function requestUri(row) {
    return String((row && (row.uri || row.url || row.path)) || "");
  }

  function requestPath(row) {
    var uri = requestUri(row);
    if (!uri) {
      return "";
    }
    try {
      return new URL(uri, window.location.origin).pathname || uri;
    } catch (error) {
      return uri.split("?")[0].split("#")[0];
    }
  }

  function isPanelRequest(row) {
    return requestUri(row).toLowerCase().indexOf("wls-performance-panel") !== -1;
  }

  function isStaticRequest(row) {
    var uri = requestUri(row);
    var path = requestPath(row).toLowerCase();
    if (!path) {
      return false;
    }
    return (
      path.indexOf("/static/") === 0 ||
      path.indexOf("/media/") === 0 ||
      path.indexOf("/pub/") === 0 ||
      path.indexOf("/assets/") !== -1 ||
      path.indexOf("/view/statics/") !== -1 ||
      staticFilePattern.test(uri)
    );
  }

  function isApiRequest(row) {
    var path = requestPath(row).toLowerCase();
    return (
      path.indexOf("/api/") === 0 ||
      path.indexOf("/rest/") === 0 ||
      path.indexOf("/graphql") === 0 ||
      path.indexOf("/framework/query-bin") !== -1 ||
      path.indexOf("/query-bin") !== -1 ||
      path.indexOf("/server/test/") === 0
    );
  }

  function requestType(row) {
    if (isPanelRequest(row)) {
      return { key: "panel", label: "面板" };
    }
    if (isStaticRequest(row)) {
      return { key: "static", label: "静态" };
    }
    if (isApiRequest(row)) {
      return { key: "api", label: "API" };
    }
    return { key: "route", label: "路由" };
  }

  function requestTotalMs(row) {
    var number = Number((row && (row.total_ms || row.duration_ms)) || 0);
    return Number.isFinite(number) ? number : 0;
  }

  function requestFilterMatches(row, filter) {
    var key = filter || "all";
    if (key === "all") {
      return true;
    }
    if (key === "fpc") {
      return !!(row && row.fpc_hit);
    }
    if (key === "slow") {
      return requestTotalMs(row) >= slowRequestMs;
    }
    if (key === "error") {
      return Number((row && row.status) || 0) >= 400;
    }
    return requestType(row).key === key;
  }

  function requestFilterCounts() {
    var counts = {};
    requestFilters.forEach(function (filter) {
      counts[filter[0]] = 0;
    });
    state.requests.forEach(function (row) {
      requestFilters.forEach(function (filter) {
        if (requestFilterMatches(row, filter[0])) {
          counts[filter[0]] += 1;
        }
      });
    });
    return counts;
  }

  function filteredRequests() {
    var filter = state.requestFilter || "all";
    return state.requests.filter(function (row) {
      return requestFilterMatches(row, filter);
    });
  }

  function safeNumber(value) {
    var number = Number(value || 0);
    return Number.isFinite(number) ? number : 0;
  }

  function roundMs(value) {
    return Math.round(safeNumber(value) * 100) / 100;
  }

  function percentile(values, ratio) {
    var numbers = (values || [])
      .map(safeNumber)
      .filter(function (value) {
        return Number.isFinite(value);
      })
      .sort(function (a, b) {
        return a - b;
      });
    if (!numbers.length) {
      return 0;
    }
    var index = Math.max(0, Math.min(numbers.length - 1, Math.ceil(numbers.length * ratio) - 1));
    return numbers[index];
  }

  function scrubUri(uri) {
    var raw = String(uri || "");
    if (!raw) {
      return "";
    }
    try {
      var parsed = new URL(raw, window.location.origin);
      parsed.searchParams.forEach(function (_value, key) {
        if (sensitiveKeyPattern.test(key)) {
          parsed.searchParams.set(key, "[redacted]");
        }
      });
      return parsed.pathname + parsed.search + parsed.hash;
    } catch (error) {
      var parts = raw.split("?");
      if (parts.length < 2) {
        return raw;
      }
      var query = parts.slice(1).join("?");
      var params = query
        .split("&")
        .map(function (pair) {
          var index = pair.indexOf("=");
          var key = index === -1 ? pair : pair.slice(0, index);
          if (sensitiveKeyPattern.test(decodeURIComponent(key || ""))) {
            return key + "=[redacted]";
          }
          return pair;
        })
        .join("&");
      return parts[0] + "?" + params;
    }
  }

  function sanitizeValue(value, depth) {
    var currentDepth = depth || 0;
    if (value === null || value === undefined) {
      return value;
    }
    if (typeof value === "number" || typeof value === "boolean") {
      return value;
    }
    if (typeof value === "string") {
      return value.length > 500 ? value.slice(0, 500) + "...[truncated]" : value;
    }
    if (currentDepth >= 5) {
      return "[truncated]";
    }
    if (Array.isArray(value)) {
      return value.slice(0, 200).map(function (item) {
        return sanitizeValue(item, currentDepth + 1);
      });
    }
    if (typeof value === "object") {
      var result = {};
      Object.keys(value).forEach(function (key) {
        if (sensitiveKeyPattern.test(key)) {
          result[key] = "[redacted]";
          return;
        }
        result[key] = sanitizeValue(value[key], currentDepth + 1);
      });
      return result;
    }
    return String(value);
  }

  function sanitizeMeta(meta) {
    var source = meta && typeof meta === "object" ? meta : {};
    var result = {};
    Object.keys(source).forEach(function (key) {
      var value = source[key];
      if (sensitiveKeyPattern.test(key)) {
        return;
      }
      if (value === null || value === undefined) {
        result[key] = value;
      } else if (typeof value === "number" || typeof value === "boolean") {
        result[key] = value;
      } else if (typeof value === "string") {
        result[key] = value.length > 300 ? value.slice(0, 300) + "...[truncated]" : value;
      }
    });
    return result;
  }

  function reportRequestRow(row) {
    var type = requestType(row);
    return {
      request_id: String((row && row.request_id) || ""),
      method: String((row && row.method) || ""),
      uri: scrubUri(requestUri(row)),
      path: requestPath(row),
      type: type.key,
      type_label: type.label,
      status: Number((row && row.status) || 0) || null,
      total_ms: roundMs(row && (row.total_ms || row.duration_ms)),
      worker_id: (row && row.worker_id) || null,
      worker_port: (row && row.worker_port) || null,
      pid: (row && (row.pid || row.worker_pid)) || null,
      fpc_hit: !!(row && row.fpc_hit),
      fpc_source: (row && row.fpc_source) || null,
      session_ms: roundMs(row && row.session_ms),
      db_ms: roundMs(row && row.db_ms),
      template_ms: roundMs(row && row.template_ms)
    };
  }

  function summarizeRequestGroups(rows) {
    var groups = {};
    (rows || []).forEach(function (row) {
      var type = requestType(row);
      var key = type.key;
      var total = requestTotalMs(row);
      if (!groups[key]) {
        groups[key] = {
          key: key,
          label: type.label,
          count: 0,
          total_ms: 0,
          max_ms: 0,
          values: [],
          slow_count: 0,
          error_count: 0,
          fpc_hit_count: 0
        };
      }
      groups[key].count += 1;
      groups[key].total_ms += total;
      groups[key].max_ms = Math.max(groups[key].max_ms, total);
      groups[key].values.push(total);
      if (total >= slowRequestMs) {
        groups[key].slow_count += 1;
      }
      if (Number((row && row.status) || 0) >= 400) {
        groups[key].error_count += 1;
      }
      if (row && row.fpc_hit) {
        groups[key].fpc_hit_count += 1;
      }
    });
    return Object.keys(groups)
      .map(function (key) {
        var group = groups[key];
        return {
          key: group.key,
          label: group.label,
          count: group.count,
          avg_ms: roundMs(group.count ? group.total_ms / group.count : 0),
          p95_ms: roundMs(percentile(group.values, 0.95)),
          max_ms: roundMs(group.max_ms),
          slow_count: group.slow_count,
          error_count: group.error_count,
          fpc_hit_count: group.fpc_hit_count
        };
      })
      .sort(function (a, b) {
        return b.count - a.count || b.max_ms - a.max_ms;
      });
  }

  function summarizeRequestFilters(rows) {
    var counts = {};
    requestFilters.forEach(function (filter) {
      counts[filter[0]] = 0;
    });
    (rows || []).forEach(function (row) {
      requestFilters.forEach(function (filter) {
        if (requestFilterMatches(row, filter[0])) {
          counts[filter[0]] += 1;
        }
      });
    });
    return counts;
  }

  function summarizeWorkers(rows) {
    var workers = {};
    (rows || []).forEach(function (row) {
      var key = [row.worker_id || "unknown", row.worker_port || "", row.pid || ""].join(":");
      var total = requestTotalMs(row);
      if (!workers[key]) {
        workers[key] = {
          worker_id: row.worker_id || "",
          worker_port: row.worker_port || "",
          pid: row.pid || row.worker_pid || "",
          request_count: 0,
          slow_count: 0,
          error_count: 0,
          total_ms: 0,
          max_ms: 0
        };
      }
      workers[key].request_count += 1;
      workers[key].total_ms += total;
      workers[key].max_ms = Math.max(workers[key].max_ms, total);
      if (total >= slowRequestMs) {
        workers[key].slow_count += 1;
      }
      if (Number(row.status || 0) >= 400) {
        workers[key].error_count += 1;
      }
    });
    return Object.keys(workers)
      .map(function (key) {
        var worker = workers[key];
        worker.avg_ms = roundMs(worker.request_count ? worker.total_ms / worker.request_count : 0);
        worker.total_ms = roundMs(worker.total_ms);
        worker.max_ms = roundMs(worker.max_ms);
        return worker;
      })
      .sort(function (a, b) {
        return b.request_count - a.request_count || b.max_ms - a.max_ms;
      });
  }

  function componentTotals(summary, detail) {
    var totals = ((detail && detail.trace && detail.trace.category_totals) || (summary && summary.category_totals) || {});
    return Object.keys(totals)
      .map(function (key) {
        return { category: key, total_ms: roundMs(totals[key]) };
      })
      .sort(function (a, b) {
        return b.total_ms - a.total_ms;
      });
  }

  function reportSpans(detail, limit) {
    var trace = (detail && detail.trace) || {};
    var spans = Array.isArray(trace.spans) ? trace.spans.slice() : [];
    spans.sort(function (a, b) {
      return safeNumber(b.duration_ms) - safeNumber(a.duration_ms);
    });
    return spans.slice(0, limit || 160).map(function (span) {
      return {
        id: span.id || null,
        parent_id: span.parent_id || null,
        category: span.category || "framework",
        name: span.name || "",
        start_ms: roundMs(span.start_ms),
        duration_ms: roundMs(span.duration_ms),
        meta: sanitizeMeta(span.meta)
      };
    });
  }

  function reportDetail(detail) {
    if (!detail) {
      return null;
    }
    var timing = detail.timing || {};
    var runtime = detail.runtime || {};
    var request = detail.request || {};
    return {
      request_id: detail.request_id || state.requestId || "",
      total_ms: roundMs(detail.total_ms || timing.total_ms),
      request: {
        method: request.method || "",
        uri: scrubUri(request.uri || ""),
        path: scrubUri(request.path || request.uri || "")
      },
      runtime: sanitizeValue(runtime, 0),
      fpc: sanitizeValue(detail.fpc || null, 0),
      timing: sanitizeValue(timing, 0),
      trace: {
        span_count: ((detail.trace && detail.trace.spans) || []).length,
        category_totals: sanitizeValue((detail.trace && detail.trace.category_totals) || {}, 0),
        spans: reportSpans(detail, 160)
      }
    };
  }

  function topSlowRequests(rows, limit) {
    return (rows || [])
      .slice()
      .sort(function (a, b) {
        return requestTotalMs(b) - requestTotalMs(a);
      })
      .slice(0, limit || 10)
      .map(reportRequestRow);
  }

  function buildActions(summary, rows, detail, services) {
    var actions = [];
    var groups = summarizeRequestGroups(rows);
    var totals = componentTotals(summary, detail);
    var slowest = topSlowRequests(rows, 1)[0];
    var errorCount = safeNumber(summary && summary.error_count) || rows.filter(function (row) {
      return Number(row.status || 0) >= 400;
    }).length;

    if (errorCount > 0) {
      actions.push({
        priority: "P0",
        scope: "requests",
        title: "HTTP errors detected",
        detail: "Inspect error requests first; failures can dominate WLS latency and hide cache behavior."
      });
    }
    if (slowest && slowest.total_ms >= 500) {
      actions.push({
        priority: "P1",
        scope: "requests",
        title: "Slow request above 500 ms",
        detail: slowest.method + " " + slowest.uri + " took " + slowest.total_ms + " ms."
      });
    }
    groups.forEach(function (group) {
      if ((group.key === "static" || group.key === "panel") && group.p95_ms >= slowRequestMs) {
        actions.push({
          priority: group.key === "static" ? "P1" : "P2",
          scope: group.key,
          title: group.label + " request group is slow",
          detail: "p95=" + group.p95_ms + " ms, slow=" + group.slow_count + "/" + group.count + "."
        });
      }
    });
    totals.slice(0, 3).forEach(function (item) {
      if (item.total_ms >= 100) {
        actions.push({
          priority: item.category === "db" ? "P1" : "P2",
          scope: "component",
          title: item.category + " dominates trace time",
          detail: "Aggregated " + item.total_ms + " ms in current window/request."
        });
      }
    });
    var safeServices = sanitizeValue(services || {}, 0);
    if (JSON.stringify(safeServices).toLowerCase().indexOf("error") !== -1) {
      actions.push({
        priority: "P1",
        scope: "services",
        title: "Service status contains error text",
        detail: "Inspect SessionServer and MemoryServer connection/pool state."
      });
    }
    if (!actions.length) {
      actions.push({
        priority: "P3",
        scope: "baseline",
        title: "No immediate blocker in exported WLS sample",
        detail: "Collect cold-start, hot-route, FPC-hit and static-resource samples before concluding."
      });
    }
    return actions;
  }

  function buildAgentReport(summary, requests, detail, services, options) {
    var rows = Array.isArray(requests) ? requests : [];
    var reportDetailPayload = reportDetail(detail || null);
    var safeSummary = sanitizeValue(summary || {}, 0);
    if (safeSummary && safeSummary.slowest && safeSummary.slowest.uri) {
      safeSummary.slowest.uri = scrubUri(safeSummary.slowest.uri);
    }
    return {
      contractVersion: AGENT_CONTRACT_VERSION,
      command: AGENT_COMMAND,
      generatedAt: new Date().toISOString(),
      page: {
        url: scrubUri(window.location.href),
        path: window.location.pathname,
        host: window.location.host,
        activeTab: state.activeTab,
        requestFilter: state.requestFilter || "all",
        selectedRequestId: (options && options.requestId) || state.requestId || ""
      },
      summary: safeSummary,
      requests: {
        total: rows.length,
        filters: summarizeRequestFilters(rows),
        groups: summarizeRequestGroups(rows),
        rows: rows.slice(0, (options && options.limit) || 80).map(reportRequestRow)
      },
      selectedRequest: reportDetailPayload,
      services: sanitizeValue(services || null, 0),
      workers: summarizeWorkers(rows),
      bottlenecks: {
        slowRequests: topSlowRequests(rows, 10),
        componentTotals: componentTotals(summary, detail).slice(0, 20)
      },
      actions: buildActions(summary || {}, rows, detail || null, services || null),
      agentGuide: {
        protocol: "Open the page, type weline, activate WLS 服务, then run await window.WelinePanel.publish({tabs:['wls'],refresh:true,limit:80}).",
        reportSource: "window.__WELINE_PANEL_REPORT__ or script#weline-panel-report",
        interpretationOrder: ["actions(P0->P3)", "requests.groups", "bottlenecks.slowRequests", "selectedRequest.trace.spans", "services", "workers"],
        privacy: "Sensitive URI params and span meta keys are redacted before export."
      }
    };
  }

  function snapshotReport(options) {
    return buildAgentReport(state.summary, state.requests, state.detail, state.services, options || {});
  }

  function publishReport(report) {
    window.__WELINE_PANEL_WLS_REPORT__ = report;
    var node = document.getElementById(AGENT_REPORT_NODE_ID);
    if (!node) {
      node = document.createElement("script");
      node.type = "application/json";
      node.id = AGENT_REPORT_NODE_ID;
      document.head.appendChild(node);
    }
    node.textContent = JSON.stringify(report);
    window.dispatchEvent(new CustomEvent("weline-panel:wls-report", { detail: report }));
    return report;
  }

  function captureReport(options) {
    var opts = options || {};
    if (opts.refresh === false) {
      return Promise.resolve(snapshotReport(opts));
    }
    var limit = Math.max(1, Math.min(200, Number(opts.limit || 80) || 80));
    var windowSec = Math.max(30, Math.min(3600, Number(opts.window_sec || opts.windowSec || 300) || 300));
    var selected = opts.requestId || opts.request_id || state.requestId || "";
    var loadedSummary = null;
    var loadedRequests = [];
    var loadedServices = null;
    return call("wlsPerformanceSummary", { window_sec: windowSec })
      .then(function (summary) {
        loadedSummary = summary || null;
        return call("wlsPerformanceRequests", { limit: limit });
      })
      .then(function (requests) {
        loadedRequests = normalizeRequests(requests);
        selected = selected || (loadedRequests[0] && loadedRequests[0].request_id) || "";
        return call("wlsPerformanceServices", {});
      })
      .then(function (services) {
        loadedServices = services || null;
        if (!selected) {
          return null;
        }
        return call("wlsPerformanceRequestDetail", { request_id: selected }).then(normalizeDetail);
      })
      .then(function (detail) {
        if (opts.updateState !== false) {
          state.summary = loadedSummary;
          state.requests = loadedRequests;
          state.services = loadedServices;
          state.detail = detail || null;
          state.requestId = selected || state.requestId;
          render();
        }
        return buildAgentReport(loadedSummary, loadedRequests, detail || null, loadedServices, {
          limit: limit,
          requestId: selected
        });
      });
  }

  function setLoading(flag) {
    state.isLoading = flag;
    render();
  }

  function mount(target, options) {
    var cfg = getConfig();
    var node = resolveMountNode(target || cfg.mountSelector || "#weline-panel-wls-diagnostics");
    if (!node) {
      throw new Error("WLS 性能诊断挂载点不存在。");
    }
    state.mountNode = node;
    state.mountNode.classList.add("wls-panel");
    bindMountNode(state.mountNode);
    state.requestId = (options && options.requestId) || cfg.requestId || state.requestId || "";
    render();
    return refresh(state.requestId).then(function () {
      return api;
    });
  }

  function open(options) {
    return mount((options && options.container) || null, options || {});
  }

  function close() {
    if (state.mountNode) {
      state.mountNode.innerHTML = "";
    }
    state.mountNode = null;
  }

  function refresh(detailRequestId) {
    var selected = "";

    state.error = "";
    setLoading(true);
    return call("wlsPerformanceSummary", { window_sec: 300 })
      .then(function (summary) {
        state.summary = summary || null;
        state.isLoading = false;
        render();
        return call("wlsPerformanceRequests", { limit: 80 });
      })
      .then(function (requests) {
        state.requests = normalizeRequests(requests);
        selected = detailRequestId || state.requestId || (state.requests[0] && state.requests[0].request_id) || "";
        state.requestId = selected || state.requestId;
        state.isLoading = false;
        render();
        call("wlsPerformanceServices", {})
          .then(function (services) {
            state.services = services || null;
            render();
          })
          .catch(function (error) {
            state.error = readableError(error);
            render();
          });
        if (selected) {
          call("wlsPerformanceRequestDetail", { request_id: selected })
            .then(function (detail) {
              state.detail = normalizeDetail(detail);
              state.requestId = selected;
              render();
            })
            .catch(function (error) {
              state.error = readableError(error);
              render();
            });
        }
        return null;
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
        saveUiState();
      })
      .catch(function (error) {
        state.error = readableError(error);
      })
      .finally(function () {
        setLoading(false);
      });
  }

  function onClick(event) {
    var target = event.target.closest("[data-action]");
    if (!target) {
      return;
    }
    var action = target.getAttribute("data-action");
    if (action === "refresh") {
      refresh(state.requestId);
    } else if (action === "clear") {
      clear();
    } else if (action === "tab") {
      state.activeTab = target.getAttribute("data-tab") || "overview";
      saveUiState();
      render();
    } else if (action === "request-filter") {
      state.requestFilter = target.getAttribute("data-filter") || "all";
      saveUiState();
      render();
    } else if (action === "detail") {
      loadDetail(target.getAttribute("data-request-id") || "");
    }
  }

  function render() {
    if (!state.mountNode) {
      return;
    }
    state.mountNode.innerHTML =
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
      '<div class="wls-grid wls-grid--cards">' +
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

  function normalizeValue(value) {
    return value === undefined || value === null || value === "" ? "-" : value;
  }

  function isWideKeyValue(key, value) {
    return key === "URI" || key === "请求" || key === "Span" || String(value).length > 46;
  }

  function renderKeyValues(values) {
    return (
      '<dl class="wls-kv">' +
      Object.keys(values)
        .map(function (key) {
          var value = normalizeValue(values[key]);
          var modifier = isWideKeyValue(key, value) ? " wls-kv__item--wide" : "";
          return (
            '<div class="wls-kv__item' +
            modifier +
            '"><dt>' +
            escapeHtml(key) +
            "</dt><dd>" +
            escapeHtml(value) +
            "</dd></div>"
          );
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
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">耗时分类汇总</div></div>' +
      '<div class="wls-section__note">最近 5 分钟采集到的性能 Span 按类型累计，包含嵌套调用；主要用来看哪个层级最耗时。</div>' +
      '<div class="wls-grid wls-grid--metrics">' +
      keys
        .sort(function (a, b) {
          return Number(totals[b] || 0) - Number(totals[a] || 0);
        })
        .slice(0, 12)
        .map(function (key) {
          var info = categoryInfo(key);
          return metric(info.label, ms(totals[key]), info.hint);
        })
        .join("") +
      "</div></section>"
    );
  }

  function categoryInfo(key) {
    var map = {
      framework: ["框架流程", "路由、调度、生命周期累计"],
      event: ["事件分发", "事件触发累计"],
      observer: ["事件监听器", "Observer 执行累计"],
      wls: ["WLS 服务", "会话、缓存、服务调用累计"],
      controller: ["控制器", "Controller 执行累计"],
      view: ["视图渲染", "模板与视图累计"],
      theme: ["主题渲染", "布局、Meta、主题处理累计"],
      db: ["数据库", "查询与写入累计"],
      developer: ["开发工具", "调试面板与开发注入累计"]
    };
    var normalized = String(key || "").toLowerCase();
    var item = map[normalized];
    if (item) {
      return { label: item[0], hint: item[1] };
    }
    return { label: key, hint: "未分类 Span 累计" };
  }

  function renderRequests() {
    if (!state.requests.length) {
      return '<div class="wls-empty">还没有采集到请求。在 DEV 模式加载页面后，输入 weline 并进入 WLS 服务 tab。</div>';
    }
    var rows = filteredRequests();
    var counts = requestFilterCounts();
    var activeFilter = state.requestFilter || "all";
    return (
      '<section class="wls-section"><div class="wls-section__header"><div class="wls-section__label">最近请求</div><span class="wls-pill">' +
      rows.length +
      " / " +
      state.requests.length +
      " 行</span></div>" +
      renderRequestFilters(counts, activeFilter) +
      (rows.length
        ? (
      '<div class="wls-table-wrap"><table class="wls-table"><thead><tr>' +
      "<th>URI</th><th>状态</th><th>总耗时</th><th>工作进程</th><th>FPC</th><th>会话</th><th>DB</th><th>模板</th>" +
      "</tr></thead><tbody>" +
          rows.map(renderRequestRow).join("") +
          "</tbody></table></div>"
        )
        : '<div class="wls-empty">当前分组没有请求。</div>') +
      "</section>"
    );
  }

  function renderRequestFilters(counts, activeFilter) {
    return (
      '<div class="wls-request-filters" role="toolbar" aria-label="请求分组过滤">' +
      requestFilters
        .map(function (filter) {
          var key = filter[0];
          var label = filter[1];
          return (
            '<button type="button" class="wls-filter" data-action="request-filter" data-filter="' +
            escapeHtml(key) +
            '" aria-pressed="' +
            (activeFilter === key ? "true" : "false") +
            '">' +
            '<span class="wls-filter__label">' +
            escapeHtml(label) +
            '</span><span class="wls-filter__count">' +
            escapeHtml(counts[key] || 0) +
            "</span></button>"
          );
        })
        .join("") +
      "</div>"
    );
  }

  function renderRequestRow(row) {
    var status = Number(row.status || 0);
    var statusClass = status >= 500 ? "wls-pill--danger" : status >= 400 ? "wls-pill--warn" : "wls-pill--ok";
    var type = requestType(row);
    return (
      '<tr data-action="detail" data-request-id="' +
      escapeHtml(row.request_id || "") +
      '">' +
      '<td><div class="wls-uri">' +
      '<span class="wls-request-type wls-request-type--' +
      escapeHtml(type.key) +
      '">' +
      escapeHtml(type.label) +
      "</span>" +
      '<span class="wls-uri__text">' +
      escapeHtml(row.method || "") +
      " " +
      escapeHtml(requestUri(row)) +
      "</span>" +
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
      '<div class="wls-grid wls-grid--cards">' +
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

  restoreUiState();

  var api = {
    mount: mount,
    open: open,
    close: close,
    refresh: function (options) {
      return refresh((options && options.requestId) || state.requestId);
    },
    report: function (options) {
      return captureReport(options || {});
    },
    exportReport: function (options) {
      return captureReport(options || {});
    },
    publish: function (options) {
      return captureReport(options || {}).then(publishReport);
    },
    snapshot: function (options) {
      return snapshotReport(options || {});
    },
    publishSnapshot: function (options) {
      return publishReport(snapshotReport(options || {}));
    },
    detail: function (requestId) {
      return call("wlsPerformanceRequestDetail", { request_id: requestId || state.requestId }).then(function (detail) {
        return reportDetail(normalizeDetail(detail));
      });
    }
  };
  window.__WELINE_PANEL_WLS_AGENT__ = api;
  if (window.WelinePanel && typeof window.WelinePanel.registerReportProvider === "function") {
    window.WelinePanel.registerReportProvider("wls", function (options) {
      return captureReport(options || {}).then(function (report) {
        report.contractVersion = "weline-panel-wls/v1";
        report.command = "weline-panel:wls";
        return report;
      });
    });
  } else {
    window.__WELINE_PANEL_REPORT_PROVIDERS__ = window.__WELINE_PANEL_REPORT_PROVIDERS__ || {};
    window.__WELINE_PANEL_REPORT_PROVIDERS__.wls = function (options) {
      return captureReport(options || {}).then(function (report) {
        report.contractVersion = "weline-panel-wls/v1";
        report.command = "weline-panel:wls";
        return report;
      });
    };
  }
})(window, document);
