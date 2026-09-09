(function (window, document) {
  "use strict";

  if (window.WelineSecurityProbesPanel) {
    return;
  }

  var DEFAULT_ENDPOINT = "/server/test/wls-security-probes";
  var STYLE_ID = "weline-security-probes-panel-style";

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function ensureStyles() {
    if (document.getElementById(STYLE_ID)) {
      return;
    }
    var style = document.createElement("style");
    style.id = STYLE_ID;
    style.textContent = [
      ".wsp-root{display:grid;gap:12px;padding:4px 2px 12px;color:inherit}",
      ".wsp-head{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;justify-content:space-between}",
      ".wsp-head h3{margin:0;font-size:15px}",
      ".wsp-help{display:block;margin-top:4px;font-size:12px;opacity:.78;line-height:1.45}",
      ".wsp-actions{display:flex;flex-wrap:wrap;gap:8px}",
      ".wsp-btn{appearance:none;border:1px solid rgba(127,140,153,.45);background:transparent;color:inherit;border-radius:8px;padding:6px 12px;font-size:12px;cursor:pointer}",
      ".wsp-btn-primary{background:var(--color-primary,#2563eb);border-color:var(--color-primary,#2563eb);color:#fff}",
      ".wsp-btn-danger{border-color:rgba(240,68,56,.65);color:var(--color-danger,#f04438)}",
      ".wsp-btn[disabled]{opacity:.45;cursor:not-allowed}",
      ".wsp-metrics{display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(120px,1fr))}",
      ".wsp-metric{border:1px solid rgba(127,140,153,.35);border-radius:8px;padding:10px 12px;background:rgba(127,140,153,.08)}",
      ".wsp-metric span{display:block;font-size:11px;opacity:.75}",
      ".wsp-metric strong{display:block;margin-top:4px;font-size:13px;word-break:break-all}",
      ".wsp-live{display:grid;gap:10px;padding:12px;border:1px solid rgba(240,68,56,.35);border-radius:10px;background:rgba(240,68,56,.06)}",
      ".wsp-live-title{margin:0;font-size:13px;font-weight:600}",
      ".wsp-live-help{margin:0;font-size:12px;opacity:.82;line-height:1.45}",
      ".wsp-live-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}",
      ".wsp-switch{display:inline-flex;align-items:center;gap:8px;font-size:12px;cursor:pointer;user-select:none}",
      ".wsp-switch input{width:16px;height:16px;accent-color:var(--color-danger,#f04438)}",
      ".wsp-ban-state{font-size:12px;opacity:.9}",
      ".wsp-ban-state.is-banned{color:var(--color-danger,#f04438);font-weight:600}",
      ".wsp-list{display:grid;gap:10px}",
      ".wsp-card{border:1px solid rgba(127,140,153,.35);border-radius:8px;padding:12px;background:rgba(127,140,153,.06)}",
      ".wsp-card.is-pass{border-color:rgba(18,183,106,.55)}",
      ".wsp-card.is-fail{border-color:rgba(240,68,56,.55);order:-1}",
      ".wsp-card-top{display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;align-items:flex-start}",
      ".wsp-card h4{margin:0;font-size:13px}",
      ".wsp-card .wsp-desc{display:block;margin-top:4px;font-size:12px;opacity:.78;line-height:1.4}",
      ".wsp-pills{display:flex;flex-wrap:wrap;gap:6px;align-items:center}",
      ".wsp-pill{font-size:11px;border-radius:999px;padding:2px 8px;border:1px solid rgba(127,140,153,.4)}",
      ".wsp-card code{display:block;margin:8px 0 4px;font-size:11px;word-break:break-all;opacity:.9}",
      ".wsp-card small{display:block;font-size:11px;opacity:.8}",
      ".wsp-error{border:1px solid rgba(240,68,56,.45);border-radius:8px;padding:10px 12px;font-size:12px;color:#f04438}",
      ".wsp-list{display:flex;flex-direction:column;gap:10px}",
      ".wsp-acc{border:1px solid rgba(127,140,153,.35);border-radius:10px;background:rgba(127,140,153,.05);overflow:hidden}",
      ".wsp-acc.is-fail{border-color:rgba(240,68,56,.55)}",
      ".wsp-acc>summary{list-style:none;cursor:pointer;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;padding:10px 12px;font-size:13px;font-weight:600;user-select:none}",
      ".wsp-acc>summary::-webkit-details-marker{display:none}",
      ".wsp-acc>summary::before{content:'▸';opacity:.7;margin-right:4px}",
      ".wsp-acc[open]>summary::before{content:'▾'}",
      ".wsp-acc-meta{display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;font-weight:500;font-size:11px;opacity:.85}",
      ".wsp-acc-body{display:flex;flex-direction:column;gap:10px;padding:0 12px 12px}"
    ].join("");
    document.head.appendChild(style);
  }

  function formatExpires(ts) {
    var n = Number(ts || 0);
    if (!Number.isFinite(n) || n <= 0) {
      return "-";
    }
    try {
      var d = new Date(n * 1000);
      var pad = function (v) {
        return String(v).padStart(2, "0");
      };
      return (
        d.getFullYear() +
        "-" +
        pad(d.getMonth() + 1) +
        "-" +
        pad(d.getDate()) +
        " " +
        pad(d.getHours()) +
        ":" +
        pad(d.getMinutes()) +
        ":" +
        pad(d.getSeconds())
      );
    } catch (e) {
      return String(n);
    }
  }

  function parseExpect(raw) {
    if (Array.isArray(raw)) {
      return raw
        .map(function (v) {
          return parseInt(v, 10);
        })
        .filter(function (n) {
          return Number.isFinite(n) && n > 0;
        });
    }
    return String(raw || "")
      .split("/")
      .map(function (v) {
        return parseInt(v, 10);
      })
      .filter(function (n) {
        return Number.isFinite(n) && n > 0;
      });
  }

  function parseHeaders(raw) {
    if (!raw) {
      return {};
    }
    try {
      var parsed = typeof raw === "string" ? JSON.parse(raw) : raw;
      if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
        return {};
      }
      var out = {};
      Object.keys(parsed).forEach(function (key) {
        var name = String(key || "").trim();
        if (!name) {
          return;
        }
        out[name] = String(parsed[key] == null ? "" : parsed[key]);
      });
      return out;
    } catch (e) {
      return {};
    }
  }

  function mount(content, options) {
    ensureStyles();
    options = options || {};
    var endpoint = String(options.endpoint || DEFAULT_ENDPOINT).replace(/\/+$/, "");
    content.innerHTML =
      '<div class="wsp-root" data-weline-security-probes-panel>' +
      '<div class="dev-tool-loading"><div>加载安全探针会话…</div></div>' +
      "</div>";

    return fetch(endpoint, {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" }
    })
      .then(function (resp) {
        return resp.json().then(function (payload) {
          return { ok: resp.ok, status: resp.status, payload: payload || {} };
        });
      })
      .then(function (result) {
        var root = content.querySelector("[data-weline-security-probes-panel]");
        if (!root) {
          return;
        }
        if (!result.ok || !result.payload.success) {
          root.innerHTML =
            '<div class="wsp-error">' +
            escapeHtml((result.payload && result.payload.message) || "加载失败 HTTP " + result.status) +
            "</div>";
          return;
        }
        renderSession(root, result.payload, endpoint);
      })
      .catch(function (err) {
        var root = content.querySelector("[data-weline-security-probes-panel]");
        if (root) {
          root.innerHTML =
            '<div class="wsp-error">' +
            escapeHtml((err && err.message) || String(err)) +
            "</div>";
        }
      });
  }

  function renderSession(root, session, endpoint) {
    var headerName = String(session.header || "X-Weline-Security-Probe-Token");
    var token = String(session.token || "");
    var liveBanHeader = String(session.live_ban_header || "X-Weline-Security-Live-Ban-Test");
    var liveBanToken = String(session.live_ban_token || "");
    var unlockPath = String(session.unlock_path || endpoint + "/unlock");
    var origin = String(session.origin || window.location.origin || "").replace(/\/$/, "");
    var cases = Array.isArray(session.cases) ? session.cases : [];
    var expiresLabel = formatExpires(session.expires_at);
    var clientIp = String(session.client_ip || "-");
    var banned = !!session.banned;
    var liveBanEnabled = false;

    var groups = [];
    var groupMap = {};
    cases.forEach(function (item) {
      var key = String(item.group || "other");
      if (!groupMap[key]) {
        groupMap[key] = {
          key: key,
          label: String(item.group_label || item.group || key),
          items: []
        };
        groups.push(groupMap[key]);
      }
      groupMap[key].items.push(item);
    });

    function renderCard(item) {
      var expect = parseExpect(item.expect_status);
      var expectLabel = expect.length ? expect.join("/") : "-";
      var headersJson = JSON.stringify(
        item.headers && typeof item.headers === "object" ? item.headers : {}
      );
      var body = item.body == null ? "" : String(item.body);
      return (
        '<article class="wsp-card" data-probe-id="' +
        escapeHtml(item.id || "") +
        '" data-probe-group="' +
        escapeHtml(String(item.group || "")) +
        '" data-probe-method="' +
        escapeHtml(String(item.method || "GET").toUpperCase()) +
        '" data-probe-path="' +
        escapeHtml(item.path || "/") +
        '" data-probe-expect="' +
        escapeHtml(expectLabel) +
        '" data-probe-headers="' +
        escapeHtml(headersJson) +
        '" data-probe-body="' +
        escapeHtml(body) +
        '">' +
        '<div class="wsp-card-top">' +
        "<div><h4>" +
        escapeHtml(item.title || item.id || "") +
        '</h4><span class="wsp-desc">' +
        escapeHtml(item.description || "") +
        "</span></div>" +
        '<div class="wsp-pills">' +
        '<span class="wsp-pill">' +
        escapeHtml(item.severity_label || item.severity || "") +
        "</span>" +
        '<button type="button" class="wsp-btn" data-wsp-run-one>运行</button>' +
        "</div></div>" +
        "<code>" +
        escapeHtml(String(item.method || "GET").toUpperCase() + " " + (item.path || "/")) +
        "</code>" +
        "<small>期望状态码：" +
        escapeHtml(expectLabel) +
        "</small>" +
        "<small data-wsp-result>尚未运行</small>" +
        "</article>"
      );
    }

    var cardsHtml = groups
      .map(function (group, index) {
        var openAttr = index === 0 ? " open" : "";
        return (
          '<details class="wsp-acc" data-wsp-group="' +
          escapeHtml(group.key) +
          '"' +
          openAttr +
          ">" +
          "<summary><span>" +
          escapeHtml(group.label) +
          '</span><span class="wsp-acc-meta" data-wsp-group-meta>' +
          escapeHtml(String(group.items.length)) +
          " 条</span></summary>" +
          '<div class="wsp-acc-body" data-wsp-group-body>' +
          group.items.map(renderCard).join("") +
          "</div></details>"
        );
      })
      .join("");

    root.innerHTML =
      '<div class="wsp-head"><div><h3>攻击用例快速测试</h3>' +
      '<span class="wsp-help">默认带探针豁免 token：返回 403/400 但不 ban。需要验收真实封禁时，打开下方「实战封禁」。</span></div>' +
      '<div class="wsp-actions">' +
      '<button type="button" class="wsp-btn wsp-btn-primary" data-wsp-run-all>全部运行</button>' +
      '<button type="button" class="wsp-btn" data-wsp-run-failed>重跑失败</button>' +
      '<button type="button" class="wsp-btn" data-wsp-refresh>刷新会话</button>' +
      "</div></div>" +
      '<div class="wsp-live" data-wsp-live-zone>' +
      '<p class="wsp-live-title">实战封禁（危险）</p>' +
      '<p class="wsp-live-help">开启后请求<strong>不带探针豁免</strong>，命中规则会锁定当前 IP。可用「解封本机」凭面板会话解除，再访问店面查看锁定表现。</p>' +
      '<div class="wsp-live-row">' +
      '<label class="wsp-switch"><input type="checkbox" data-wsp-live-ban /> 不带豁免 token（真实封禁）</label>' +
      '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">' +
      '<span class="wsp-ban-state' +
      (banned ? " is-banned" : "") +
      '" data-wsp-ban-state>' +
      (banned ? "本机已锁定" : "本机未锁定") +
      " · " +
      escapeHtml(clientIp) +
      "</span>" +
      '<button type="button" class="wsp-btn wsp-btn-danger" data-wsp-unlock>解封本机 IP</button>' +
      "</div></div></div>" +
      '<div class="wsp-metrics">' +
      '<article class="wsp-metric"><span>Token 头</span><strong>' +
      escapeHtml(headerName) +
      "</strong></article>" +
      '<article class="wsp-metric"><span>过期时间</span><strong>' +
      escapeHtml(expiresLabel) +
      "</strong></article>" +
      '<article class="wsp-metric"><span>用例数</span><strong>' +
      escapeHtml(String(cases.length)) +
      "</strong></article>" +
      '<article class="wsp-metric"><span>本轮结果</span><strong data-wsp-summary>0 / 0</strong></article>' +
      "</div>" +
      (token
        ? ""
        : '<div class="wsp-error">未能签发探针 token，请点「刷新会话」重试。</div>') +
      '<div class="wsp-list" data-wsp-list>' +
      cardsHtml +
      "</div>";

    var listEl = root.querySelector("[data-wsp-list]");
    var cards = Array.prototype.slice.call(root.querySelectorAll(".wsp-card"));
    var summaryEl = root.querySelector("[data-wsp-summary]");
    var banStateEl = root.querySelector("[data-wsp-ban-state]");
    var liveToggle = root.querySelector("[data-wsp-live-ban]");

    function setBanState(isBanned, ip) {
      banned = !!isBanned;
      if (!banStateEl) {
        return;
      }
      banStateEl.classList.toggle("is-banned", banned);
      banStateEl.textContent =
        (banned ? "本机已锁定" : "本机未锁定") + " · " + String(ip || clientIp || "-");
    }

    function sortCardsByResult() {
      if (!listEl) {
        return;
      }
      var sections = Array.prototype.slice.call(listEl.querySelectorAll(".wsp-acc"));
      sections.forEach(function (section) {
        var body = section.querySelector("[data-wsp-group-body]");
        if (!body) {
          return;
        }
        var sectionCards = Array.prototype.slice.call(body.querySelectorAll(".wsp-card"));
        sectionCards.sort(function (a, b) {
          var af = a.classList.contains("is-fail") ? 0 : a.classList.contains("is-pass") ? 2 : 1;
          var bf = b.classList.contains("is-fail") ? 0 : b.classList.contains("is-pass") ? 2 : 1;
          return af - bf;
        });
        sectionCards.forEach(function (card) {
          body.appendChild(card);
        });
        var failCount = sectionCards.filter(function (c) {
          return c.classList.contains("is-fail");
        }).length;
        var passCount = sectionCards.filter(function (c) {
          return c.classList.contains("is-pass");
        }).length;
        var meta = section.querySelector("[data-wsp-group-meta]");
        if (meta) {
          meta.textContent =
            sectionCards.length +
            " 条 · 通过 " +
            passCount +
            " · 失败 " +
            failCount;
        }
        section.classList.toggle("is-fail", failCount > 0);
        if (failCount > 0) {
          section.open = true;
        }
      });
      sections.sort(function (a, b) {
        var af = a.classList.contains("is-fail") ? 0 : 1;
        var bf = b.classList.contains("is-fail") ? 0 : 1;
        return af - bf;
      });
      sections.forEach(function (section) {
        listEl.appendChild(section);
      });
      cards = Array.prototype.slice.call(listEl.querySelectorAll(".wsp-card"));
    }

    function setResult(card, text, ok) {
      var el = card.querySelector("[data-wsp-result]");
      if (el) {
        el.textContent = text;
      }
      card.classList.toggle("is-pass", ok === true);
      card.classList.toggle("is-fail", ok === false);
      if (ok === true || ok === false) {
        sortCardsByResult();
      }
    }

    function refreshSummary() {
      if (!summaryEl) {
        return;
      }
      var done = cards.filter(function (c) {
        return c.classList.contains("is-pass") || c.classList.contains("is-fail");
      }).length;
      var passed = cards.filter(function (c) {
        return c.classList.contains("is-pass");
      }).length;
      summaryEl.textContent = passed + " / " + done;
    }

    function runCard(card) {
      var method = (card.getAttribute("data-probe-method") || "GET").toUpperCase();
      var path = card.getAttribute("data-probe-path") || "/";
      var expect = parseExpect(card.getAttribute("data-probe-expect"));
      var extraHeaders = parseHeaders(card.getAttribute("data-probe-headers") || "{}");
      var body = card.getAttribute("data-probe-body") || "";
      var url = origin + path;
      setResult(card, "运行中…", null);
      var headers = {};
      if (liveBanEnabled) {
        if (liveBanToken) {
          headers[liveBanHeader] = liveBanToken;
        }
      } else if (token) {
        headers[headerName] = token;
      }
      Object.keys(extraHeaders).forEach(function (key) {
        if (String(key).toLowerCase() === "user-agent") {
          headers["X-Weline-Security-Probe-UA"] = extraHeaders[key];
        }
        headers[key] = extraHeaders[key];
      });
      var fetchOpts = {
        method: method,
        headers: headers,
        credentials: "omit",
        cache: "no-store",
        redirect: "manual",
        signal:
          typeof AbortSignal !== "undefined" && typeof AbortSignal.timeout === "function"
            ? AbortSignal.timeout(8000)
            : undefined
      };
      if (body !== "" && method !== "GET" && method !== "HEAD") {
        fetchOpts.body = body;
      }
      return fetch(url, fetchOpts)
        .then(function (resp) {
          var status = resp.status;
          var ok = expect.length === 0 ? status >= 400 : expect.indexOf(status) !== -1;
          setResult(card, "状态 " + String(status) + " · " + (ok ? "通过" : "未通过"), ok);
          if (liveBanEnabled && status === 403) {
            setBanState(true, clientIp);
          }
        })
        .catch(function (err) {
          setResult(
            card,
            "请求失败：" + (err && err.message ? err.message : String(err)),
            false
          );
        })
        .then(function () {
          refreshSummary();
        });
    }

    function runAll(onlyFailed) {
      var chain = Promise.resolve();
      cards.forEach(function (card) {
        if (onlyFailed && !card.classList.contains("is-fail")) {
          return;
        }
        chain = chain.then(function () {
          return runCard(card);
        });
      });
      return chain;
    }

    function unlockSelf() {
      var btn = root.querySelector("[data-wsp-unlock]");
      if (btn) {
        btn.disabled = true;
      }
      return fetch(unlockPath, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: (function () {
          var h = { Accept: "application/json" };
          if (token) {
            h[headerName] = token;
          } else if (liveBanToken) {
            h[liveBanHeader] = liveBanToken;
          }
          return h;
        })()
      })
        .then(function (resp) {
          return resp.json().then(function (payload) {
            return { ok: resp.ok, payload: payload || {} };
          });
        })
        .then(function (result) {
          var ip = (result.payload && result.payload.client_ip) || clientIp;
          setBanState(!!(result.payload && result.payload.banned), ip);
          if (!result.ok || !(result.payload && result.payload.success)) {
            window.alert(
              (result.payload && result.payload.message) || "解封失败，请刷新会话后重试。"
            );
            return;
          }
          window.alert((result.payload && result.payload.message) || "已解封本机 IP。");
        })
        .catch(function (err) {
          window.alert((err && err.message) || String(err));
        })
        .then(function () {
          if (btn) {
            btn.disabled = false;
          }
        });
    }

    if (liveToggle) {
      liveToggle.addEventListener("change", function () {
        liveBanEnabled = !!liveToggle.checked;
        if (liveBanEnabled && !liveBanToken) {
          window.alert("缺少实战封禁凭证，请先点「刷新会话」。");
          liveToggle.checked = false;
          liveBanEnabled = false;
        }
      });
    }
    root.querySelector("[data-wsp-run-all]")?.addEventListener("click", function () {
      runAll(false);
    });
    root.querySelector("[data-wsp-run-failed]")?.addEventListener("click", function () {
      runAll(true);
    });
    root.querySelector("[data-wsp-refresh]")?.addEventListener("click", function () {
      mount(root.parentElement || root, { endpoint: endpoint });
    });
    root.querySelector("[data-wsp-unlock]")?.addEventListener("click", function () {
      unlockSelf();
    });
    cards.forEach(function (card) {
      card.querySelector("[data-wsp-run-one]")?.addEventListener("click", function () {
        runCard(card);
      });
    });
  }

  window.WelineSecurityProbesPanel = {
    mount: mount,
    endpoint: DEFAULT_ENDPOINT
  };
})(window, document);
