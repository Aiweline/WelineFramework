(function () {
  "use strict";

  var SEO_TEXT_LIMITS = {
    titleMin: 30,
    titleMax: 65,
    descriptionMin: 90,
    descriptionMax: 170,
    visibleTextMin: 2500
  };

  var PUBLIC_COPY_LEAKS = [
    { re: /English pages stay|Hindi guides stay|public root path|clean language split/i, name: "internal language URL split copy" },
    { re: /Open cluster/i, name: "generic builder cluster CTA" },
    { re: /Hindi readers should/i, name: "unlocalized Hindi reader suffix" },
    { re: /planned topics|plan-driven pairs/i, name: "internal planning vocabulary" },
    { re: /\bstays inside\b/i, name: "generated cluster containment phrase" },
    { re: /zh\.wikipedia\.org\/wiki\/%E6%8B%89%E5%AF%86/i, name: "unfit Chinese Rummy source URL" }
  ];

  var PROMPT_LEAK_PATTERNS = [
    { re: /negative prompt|positive prompt|image prompt/i, name: "image prompt vocabulary" },
    { re: /build contract|master plan|SEO plan|CTA rule|generator vocabulary/i, name: "internal build vocabulary" },
    { re: /glossy-game-banner|real-human glossy-banner|Teen Patti Master-style/i, name: "internal visual reference" },
    { re: /crop-safe negative space|contact sheet|screenshot validation|slot type/i, name: "internal QA vocabulary" },
    { re: /Midjourney|Stable Diffusion|Codex instruction|user instruction/i, name: "tool/instruction leak" }
  ];

  var SEO_CHECK_GROUPS = [
    { id: "technical", title: "技术 SEO" },
    { id: "head", title: "Head 元数据" },
    { id: "url", title: "URL 与多语言" },
    { id: "content", title: "页面内容" },
    { id: "schema", title: "结构化数据" },
    { id: "social", title: "社交分享" },
    { id: "structure", title: "语义结构" },
    { id: "compliance", title: "合规与泄露" }
  ];

  var EXPECTED_JSONLD = {
    home: ["WebSite", "Organization", "BreadcrumbList"],
    article: ["Article", "BreadcrumbList"],
    review: ["Review", "BreadcrumbList"],
    faq: ["FAQPage", "BreadcrumbList"],
    contact: ["ContactPage", "BreadcrumbList"],
    legal: ["WebPage", "BreadcrumbList", "WebSite", "Organization"]
  };

  var REQUIRED_HEAD = [
    { name: "title", test: function () { return Boolean((document.title || "").trim()); } },
    {
      name: "robots meta",
      test: function () {
        var node = document.querySelector('meta[name="robots"]');
        return Boolean(
          node &&
            /index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1/i.test(
              node.getAttribute("content") || ""
            )
        );
      }
    },
    { name: "page-type meta", test: function () { return Boolean(document.querySelector('meta[name="page-type"]')); } },
    {
      name: "content-category meta",
      test: function () { return Boolean(document.querySelector('meta[name="content-category"]')); }
    },
    { name: "keywords meta", test: function () { return Boolean(document.querySelector('meta[name="keywords"]')); } },
    { name: "article:section", test: function () { return Boolean(document.querySelector('meta[property="article:section"]')); } },
    {
      name: "article:modified_time",
      test: function () { return Boolean(document.querySelector('meta[property="article:modified_time"]')); }
    },
    { name: "hreflang x-default", test: function () { return Boolean(document.querySelector('link[rel="alternate"][hreflang="x-default"]')); } },
    { name: "og:site_name", test: function () { return Boolean(document.querySelector('meta[property="og:site_name"]')); } },
    { name: "og:locale", test: function () { return Boolean(document.querySelector('meta[property="og:locale"]')); } },
    { name: "og:title", test: function () { return Boolean(document.querySelector('meta[property="og:title"]')); } },
    { name: "og:description", test: function () { return Boolean(document.querySelector('meta[property="og:description"]')); } },
    { name: "og:type", test: function () { return Boolean(document.querySelector('meta[property="og:type"]')); } },
    { name: "og:url", test: function () { return Boolean(document.querySelector('meta[property="og:url"]')); } },
    { name: "og:image", test: function () { return Boolean(document.querySelector('meta[property="og:image"]')); } },
    { name: "og:image:alt", test: function () { return Boolean(document.querySelector('meta[property="og:image:alt"]')); } },
    { name: "twitter:card", test: function () { return Boolean(document.querySelector('meta[name="twitter:card"]')); } },
    { name: "twitter:title", test: function () { return Boolean(document.querySelector('meta[name="twitter:title"]')); } },
    {
      name: "twitter:description",
      test: function () { return Boolean(document.querySelector('meta[name="twitter:description"]')); }
    },
    { name: "twitter:image", test: function () { return Boolean(document.querySelector('meta[name="twitter:image"]')); } },
    { name: "twitter:image:alt", test: function () { return Boolean(document.querySelector('meta[name="twitter:image:alt"]')); } },
    { name: "sitemap link", test: function () { return Boolean(document.querySelector('link[rel="sitemap"][href]')); } },
    { name: "favicon svg", test: function () { return Boolean(document.querySelector('link[rel="icon"][type="image/svg+xml"]')); } },
    {
      name: "favicon png",
      test: function () { return Boolean(document.querySelector('link[rel="icon"][type="image/png"][sizes="32x32"]')); }
    },
    {
      name: "apple-touch-icon",
      test: function () { return Boolean(document.querySelector('link[rel="apple-touch-icon"][sizes="180x180"]')); }
    },
    { name: "charset", test: function () { return Boolean(document.querySelector('meta[charset="UTF-8"], meta[charset="utf-8"]')); } },
    { name: "viewport", test: function () { return Boolean(document.querySelector('meta[name="viewport"]')); } }
  ];

  function metaContent(selector) {
    var node = document.querySelector(selector);
    return node ? (node.getAttribute("content") || "").trim() : "";
  }

  function splitKeywords(value) {
    return String(value || "")
      .split(/[,;|]/)
      .map(function (entry) { return entry.replace(/\s+/g, " ").trim(); })
      .filter(Boolean);
  }

  function normalizedKeywordKey(value) {
    return String(value || "").toLowerCase().replace(/\s+/g, " ").trim();
  }

  function keywordAppearsInText(keyword, text) {
    var key = normalizedKeywordKey(keyword);
    if (!key) return false;
    return normalizedKeywordKey(text).indexOf(key) !== -1;
  }

  function countDuplicates(items) {
    var seen = {};
    var duplicates = 0;
    items.forEach(function (item) {
      var key = normalizedKeywordKey(item);
      if (!key) return;
      if (seen[key]) duplicates += 1;
      seen[key] = true;
    });
    return duplicates;
  }

  function textTokenSet(value) {
    var tokens = {};
    String(value || "")
      .toLowerCase()
      .replace(/[^a-z0-9\s-]/g, " ")
      .split(/\s+/)
      .filter(function (token) { return token.length >= 3; })
      .forEach(function (token) { tokens[token] = true; });
    return tokens;
  }

  function readBodyMeta(name) {
    var body = document.body;
    if (!body || !body.dataset) return "";
    return body.dataset[name] || "";
  }

  function inferSeoTypeFromPage() {
    var explicit = metaContent('meta[name="page-type"]');
    if (explicit) return explicit;
    var bodyClass = document.body ? document.body.className : "";
    var match = bodyClass.match(/\bseo-([a-z0-9-]+)\b/i);
    return match ? match[1] : "article";
  }

  function normalizeLangFromHreflang(code) {
    var normalized = String(code || "").trim().toLowerCase().replace(/_/g, "-");
    if (!normalized || normalized === "x-default") return "";
    if (normalized === "en-in" || normalized === "en") return "en-in";
    if (normalized === "hi-in" || normalized === "hi") return "hi-in";
    return normalized;
  }

  function isKnownLangSegment(segment) {
    return ["en-in", "hi-in", "en", "hi"].includes(String(segment || "").toLowerCase());
  }

  function stripDevPathSegments(parts) {
    var segments = parts.slice();
    if (segments.length && segments[0].indexOf(".") !== -1) segments.shift();
    if (segments.length && isKnownLangSegment(segments[0])) segments.shift();
    return segments;
  }

  function inferSlug() {
    var pageId = readBodyMeta("pageId");
    if (pageId) return pageId === "home" ? "index" : pageId;
    var path = window.location.pathname.replace(/\/+$/, "") || "/";
    var parts = path.split("/").filter(Boolean);
    if (!parts.length) return "index";
    parts = stripDevPathSegments(parts);
    if (!parts.length) return "index";
    return parts.join("/");
  }

  function inferLang() {
    return readBodyMeta("language") || document.documentElement.lang || "en-in";
  }

  function inferSiteDomain() {
    var fromBody = readBodyMeta("siteDomain");
    if (fromBody) return fromBody;
    var canonical = document.querySelector('link[rel="canonical"]');
    if (canonical && canonical.href) {
      try {
        return new URL(canonical.href).hostname;
      } catch (_error) {
        return "";
      }
    }
    return window.location.hostname || "";
  }

  function normalizeCanonicalUrl(url) {
    if (!url) return "";
    try {
      var parsed = new URL(url);
      var path = parsed.pathname || "/";
      if (!path.endsWith("/")) path += "/";
      return parsed.origin + path;
    } catch (_error) {
      return String(url).trim();
    }
  }

  function expectedCanonicalFromHreflang(lang) {
    var alternates = Array.from(document.querySelectorAll('link[rel="alternate"][hreflang]'));
    for (var i = 0; i < alternates.length; i++) {
      var node = alternates[i];
      var code = (node.getAttribute("hreflang") || "").trim();
      if (!code || code.toLowerCase() === "x-default") continue;
      if (!hreflangMatchesPage(code, lang) || !node.href) continue;
      return normalizeCanonicalUrl(node.href);
    }
    return "";
  }

  function expectedCanonical(siteDomain, lang, slug, defaultLang) {
    var host = "https://" + siteDomain;
    if (lang === defaultLang) {
      return slug === "index" ? host + "/" : host + "/" + slug + "/";
    }
    return slug === "index" ? host + "/" + lang + "/" : host + "/" + lang + "/" + slug + "/";
  }

  function resolveExpectedCanonical(siteDomain, lang, slug, defaultLang) {
    var fromHreflang = expectedCanonicalFromHreflang(lang);
    if (fromHreflang) return fromHreflang;
    return normalizeCanonicalUrl(expectedCanonical(siteDomain, lang, slug, defaultLang));
  }

  function inferDefaultLanguage() {
    var fromBody = readBodyMeta("defaultLanguage");
    if (fromBody) return fromBody;

    var xDefault = document.querySelector('link[rel="alternate"][hreflang="x-default"]');
    if (!xDefault || !xDefault.href) return "en-in";

    try {
      var xHref = new URL(xDefault.href).href;
      var alternates = Array.from(document.querySelectorAll('link[rel="alternate"][hreflang]'));
      for (var i = 0; i < alternates.length; i++) {
        var node = alternates[i];
        var code = (node.getAttribute("hreflang") || "").trim();
        if (!code || code.toLowerCase() === "x-default") continue;
        if (new URL(node.href).href === xHref) {
          return normalizeLangFromHreflang(code);
        }
      }

      var parts = new URL(xDefault.href).pathname.split("/").filter(Boolean);
      if (parts.length && isKnownLangSegment(parts[0])) return parts[0];
    } catch (_error) {
      return "en-in";
    }

    return "en-in";
  }

  function collectHreflangCodes() {
    return Array.from(document.querySelectorAll('link[rel="alternate"][hreflang]'))
      .map(function (node) { return (node.getAttribute("hreflang") || "").trim(); })
      .filter(Boolean);
  }

  function extractJsonLdTypes() {
    var types = [];
    var scripts = document.querySelectorAll('head script[type="application/ld+json"]');
    scripts.forEach(function (script) {
      try {
        var data = JSON.parse(script.textContent || "{}");
        if (Array.isArray(data["@graph"])) {
          data["@graph"].forEach(function (node) {
            if (node && node["@type"]) types.push(node["@type"]);
          });
        } else if (data["@type"]) {
          types.push(data["@type"]);
        }
      } catch (_error) {
        types.push("INVALID_JSON");
      }
    });
    return types;
  }

  function collectJsonLdNodes() {
    var nodes = [];
    var scripts = document.querySelectorAll('head script[type="application/ld+json"]');
    scripts.forEach(function (script) {
      try {
        var data = JSON.parse(script.textContent || "{}");
        if (Array.isArray(data)) {
          data.forEach(function (item) { nodes.push(item); });
        } else {
          nodes.push(data);
        }
        if (Array.isArray(data["@graph"])) {
          data["@graph"].forEach(function (item) { nodes.push(item); });
        }
      } catch (_error) {
        nodes.push({ "@type": "INVALID_JSON" });
      }
    });
    return nodes.filter(Boolean);
  }

  function jsonLdTypeList(node) {
    var type = node && node["@type"];
    if (!type) return [];
    return Array.isArray(type) ? type : [type];
  }

  function jsonLdNodesOfType(nodes, type) {
    return nodes.filter(function (node) {
      return jsonLdTypeList(node).indexOf(type) !== -1;
    });
  }

  function visibleTextLength(root) {
    var clone = root.cloneNode(true);
    clone.querySelectorAll("script, style, .weline-seo-panel").forEach(function (node) { node.remove(); });
    return (clone.textContent || "").replace(/\s+/g, " ").trim().length;
  }

  function isBrandChromeImage(src) {
    return /(?:logo[^/]*\.(?:svg|png|webp)|favicon|apple-touch-icon)/i.test(src || "");
  }

  function isDecorativeImage(img) {
    var role = (img.getAttribute("role") || "").toLowerCase();
    var alt = img.getAttribute("alt");
    return img.getAttribute("aria-hidden") === "true" ||
      role === "presentation" ||
      role === "none" ||
      alt === "";
  }

  function isLocalHost() {
    var host = (window.location.hostname || "").toLowerCase();
    if (!host) return true;
    if (host === "localhost" || host === "127.0.0.1" || host === "[::1]" || host === "::1") return true;
    if (host.endsWith(".local")) return true;
    if (/^192\.168\./.test(host) || /^10\./.test(host) || /^172\.(1[6-9]|2\d|3[01])\./.test(host)) return true;
    return false;
  }

  function isChineseBrowser() {
    var langs = [];
    if (Array.isArray(navigator.languages) && navigator.languages.length) langs = navigator.languages.slice();
    else if (navigator.language) langs = [navigator.language];
    return langs.some(function (lang) {
      return /^zh(?:[-_]|$)/i.test(String(lang || "").trim());
    });
  }

  function detectGa4MeasurementId() {
    if (window.__SITE_GA4__ && window.__SITE_GA4__.measurementId) return window.__SITE_GA4__.measurementId;
    var fromBody = readBodyMeta("ga4Id");
    if (fromBody) return fromBody;
    var script = document.querySelector('script[src*="googletagmanager.com/gtag/js"]');
    if (script && script.src) {
      var match = script.src.match(/[?&]id=(G-[A-Z0-9]+)/i);
      if (match) return match[1];
    }
    return "";
  }

  var GA4_TRACK_SELECTOR = [
    "[data-ga-event]",
    "[data-track]",
    "[data-apk-action]",
    ".apk-fab",
    ".academy-app-promo__cta",
    ".apk-promo-strip__cta",
    ".club-apk-promo__cta",
    ".promo-hero__cta"
  ].join(",");

  function detectApkDownloadUrl() {
    var downloadLink = document.querySelector('a[download][href$=".apk"]');
    if (downloadLink && downloadLink.href) return downloadLink.href;
    var fab = document.querySelector(".apk-fab[href]");
    if (fab && fab.href && /\.apk(?:$|[?#])/i.test(fab.href)) return fab.href;
    var hero = document.querySelector(".promo-hero__cta[href], .academy-app-promo__cta[href]");
    if (hero && hero.href && /\.apk(?:$|[?#])/i.test(hero.href)) return hero.href;
    return "";
  }

  function inferGa4CtaPosition(el) {
    var explicit = el.getAttribute("data-cta-position") || (el.dataset && el.dataset.ctaPosition) || "";
    if (explicit) return explicit;
    if (el.classList.contains("apk-fab")) return "contextual";
    if (el.classList.contains("promo-hero__cta")) return "hero";
    if (el.classList.contains("academy-app-promo__cta") || el.closest(".academy-app-promo")) return "footer";
    if (el.classList.contains("apk-promo-strip__cta")) return "mid";
    if (el.closest(".promo-hero")) return "hero";
    if (el.closest("footer")) return "footer";
    if (el.closest("main, .site-shell")) return "mid";
    return "contextual";
  }

  function resolveGa4EventName(el) {
    return (
      el.getAttribute("data-ga-event") ||
      el.getAttribute("data-track") ||
      (el.hasAttribute("data-apk-action") ? "apk_download_click" : "") ||
      "apk_download_click"
    );
  }

  function inferGa4MatchType(el, apkDownloadUrl) {
    if (el.matches("[data-ga-event]")) return "[data-ga-event]";
    if (el.matches("[data-track]")) return "[data-track]";
    if (el.matches("[data-apk-action]")) return "[data-apk-action]";
    if (el.classList.contains("apk-fab")) return ".apk-fab";
    if (el.classList.contains("academy-app-promo__cta")) return ".academy-app-promo__cta";
    if (el.classList.contains("apk-promo-strip__cta")) return ".apk-promo-strip__cta";
    if (el.classList.contains("club-apk-promo__cta")) return ".club-apk-promo__cta";
    if (el.classList.contains("promo-hero__cta")) return ".promo-hero__cta";
    if (el.matches('a[download][href$=".apk"]')) return 'a[download][href$=".apk"]';
    if (apkDownloadUrl && el.href === apkDownloadUrl) return "site apk download href";
    return "tracked CTA";
  }

  function buildGa4SelectorHint(el) {
    if (el.id) return "#" + el.id;
    if (el.getAttribute("data-ga-event")) return '[data-ga-event="' + el.getAttribute("data-ga-event") + '"]';
    if (el.getAttribute("data-track")) return '[data-track="' + el.getAttribute("data-track") + '"]';
    if (el.classList.contains("apk-fab")) return ".apk-fab";
    var classes = Array.from(el.classList || []).slice(0, 2).join(".");
    return el.tagName.toLowerCase() + (classes ? "." + classes : "");
  }

  function resolveGa4TriggerDelivery(entry) {
    var delivery = entry && entry.delivery ? entry.delivery : {};
    if (delivery.mode === "ga4" || delivery.submitted) {
      return {
        label: "Sent",
        tone: "pass",
        detail: "已触发并推送到 GA4（gtag event）。"
      };
    }
    if (delivery.mode === "preview") {
      return {
        label: "Preview",
        tone: "info",
        detail: "已触发；仅 console/dataLayer 预览，未写入 GA4。"
      };
    }
    if (delivery.mode === "no_gtag") {
      return {
        label: "No gtag",
        tone: "fail",
        detail: "已触发，但 gtag 未加载，事件未推送。"
      };
    }
    var reasons = [];
    if (delivery.blockReason === "local_host") reasons.push("本地/私网 host 门禁");
    if (delivery.blockReason === "chinese_browser") reasons.push("zh-* locale 门禁");
    return {
      label: "Triggered",
      tone: "pass",
      detail:
        "已在 Inspector 面板记录为已触发，未推送到 GA4" +
        (reasons.length ? "（" + reasons.join(" · ") + "）" : "。")
    };
  }

  function formatGa4TriggerTime(timestamp) {
    if (!timestamp) return "just now";
    try {
      return new Date(timestamp).toLocaleTimeString();
    } catch (error) {
      return String(timestamp);
    }
  }

  function collectGa4RecentTriggers() {
    var runtime = window.__SITE_GA4__ || {};
    var items = Array.isArray(runtime.recentTriggers) ? runtime.recentTriggers.slice() : [];
    return {
      total: items.length,
      items: items.map(function (entry, index) {
        var params = entry.params || {};
        return {
          index: index + 1,
          id: entry.id || "",
          eventName: entry.eventName || "apk_download_click",
          ctaPosition: params.cta_position || "",
          linkText: params.link_text || "",
          linkUrl: params.link_url || "",
          eventId: params.event_id || entry.id || "",
          timestamp: entry.timestamp || 0,
          timeLabel: formatGa4TriggerTime(entry.timestamp),
          delivery: resolveGa4TriggerDelivery(entry)
        };
      })
    };
  }

  function resolveGa4EventDelivery(ga4) {
    if (ga4.eventsWillFire) {
      return {
        status: "will_fire",
        label: "Will fire",
        tone: "pass",
        detail: "点击后会发送 gtag('event', …)。"
      };
    }
    if (ga4.previewOnly) {
      return {
        status: "preview",
        label: "Preview",
        tone: "info",
        detail: "点击后会在面板记录为已触发，并输出 console.info('[site-analytics preview]', …)。"
      };
    }
    if (!ga4.eventsAllowed) {
      var reasons = [];
      if (ga4.localHost) reasons.push("本地/私网 host 门禁");
      if (ga4.chineseBrowser) reasons.push("zh-* locale 门禁");
      return {
        status: "panel_only",
        label: "Panel only",
        tone: "pass",
        detail:
          "点击会在 Inspector 面板记录为已触发，但不会推送到 GA4" +
          (reasons.length ? "（" + reasons.join(" · ") + "）。" : "。")
      };
    }
    if (ga4.configured && !ga4.gtagRuntime) {
      return {
        status: "blocked",
        label: "No gtag",
        tone: "fail",
        detail: "已配置 GA4 ID，但 gtag 未加载，事件无法写入 GA4。"
      };
    }
    return {
      status: "blocked",
      label: "Blocked",
      tone: "info",
      detail: "当前环境不会提交 CTA 事件。"
    };
  }

  function collectGa4PageEvents(ga4) {
    var apkDownloadUrl = detectApkDownloadUrl();
    var items = [];
    var seen = new Set();

    function pushElement(el) {
      if (!el || el.closest(".weline-seo-panel")) return;
      var signature =
        resolveGa4EventName(el) +
        "|" +
        inferGa4CtaPosition(el) +
        "|" +
        inferGa4MatchType(el, apkDownloadUrl) +
        "|" +
        (el.href || "") +
        "|" +
        (el.textContent || "").replace(/\s+/g, " ").trim().slice(0, 60);
      if (seen.has(signature)) return;
      seen.add(signature);

      var delivery = resolveGa4EventDelivery(ga4);
      items.push({
        index: items.length + 1,
        eventName: resolveGa4EventName(el),
        matchType: inferGa4MatchType(el, apkDownloadUrl),
        ctaPosition: inferGa4CtaPosition(el),
        linkText: (el.textContent || "").replace(/\s+/g, " ").trim().slice(0, 80) || "(empty label)",
        linkUrl: el.href || apkDownloadUrl || "",
        selectorHint: buildGa4SelectorHint(el),
        tagName: (el.tagName || "").toLowerCase(),
        delivery: delivery
      });
    }

    Array.from(document.querySelectorAll(GA4_TRACK_SELECTOR)).forEach(pushElement);
    Array.from(document.querySelectorAll('a[download][href$=".apk"]')).forEach(pushElement);
    if (apkDownloadUrl) {
      Array.from(document.querySelectorAll("a[href]"))
        .filter(function (node) {
          return node.href === apkDownloadUrl;
        })
        .forEach(pushElement);
    }

    var byEventName = {};
    items.forEach(function (item) {
      if (!byEventName[item.eventName]) {
        byEventName[item.eventName] = {
          eventName: item.eventName,
          count: 0,
          positions: {},
          delivery: item.delivery
        };
      }
      byEventName[item.eventName].count += 1;
      byEventName[item.eventName].positions[item.ctaPosition] =
        (byEventName[item.eventName].positions[item.ctaPosition] || 0) + 1;
    });

    return {
      total: items.length,
      uniqueEventNames: Object.keys(byEventName).length,
      apkDownloadUrl: apkDownloadUrl,
      defaultEventName: "apk_download_click",
      items: items,
      byEventName: Object.keys(byEventName).map(function (key) {
        return byEventName[key];
      })
    };
  }

  function collectGa4Status() {
    var runtime = window.__SITE_GA4__ || {};
    var measurementId = runtime.measurementId || detectGa4MeasurementId();
    var configured = typeof runtime.configured === "boolean" ? runtime.configured : /^G-[A-Z0-9]+$/i.test(measurementId);
    var enableInDev = readBodyMeta("ga4EnableInDev") === "true";
    var localHost = runtime.blockedReasons
      ? runtime.blockedReasons.indexOf("local_host") !== -1
      : isLocalHost();
    var chineseBrowser = runtime.blockedReasons
      ? runtime.blockedReasons.indexOf("chinese_browser") !== -1
      : isChineseBrowser();
    var eventsAllowed = typeof runtime.eventsAllowed === "boolean" ? runtime.eventsAllowed : !localHost && !chineseBrowser;
    var gtagScript =
      typeof runtime.gtagScript === "boolean"
        ? runtime.gtagScript
        : Boolean(document.querySelector('script[src*="googletagmanager.com/gtag/js"]'));
    var gtagRuntime = typeof runtime.gtagRuntime === "boolean" ? runtime.gtagRuntime : typeof window.gtag === "function";
    var eventsWillFire =
      typeof runtime.eventsWillFire === "boolean"
        ? runtime.eventsWillFire
        : configured && gtagRuntime && eventsAllowed;
    var previewOnly =
      typeof runtime.previewOnly === "boolean" ? runtime.previewOnly : !configured && eventsAllowed;
    var browserLanguages =
      runtime.browserLanguages && runtime.browserLanguages.length
        ? runtime.browserLanguages.slice()
        : Array.isArray(navigator.languages) && navigator.languages.length
          ? navigator.languages.slice()
          : navigator.language
            ? [navigator.language]
            : [];

    var status = {
      measurementId: measurementId,
      configured: configured,
      enableInDev: enableInDev,
      gtagScript: gtagScript,
      gtagRuntime: gtagRuntime,
      eventsAllowed: eventsAllowed,
      eventsWillFire: eventsWillFire,
      previewOnly: previewOnly,
      localHost: localHost,
      chineseBrowser: chineseBrowser,
      host: window.location.hostname || "",
      browserLanguages: browserLanguages,
      pageEvents: null,
      recentTriggers: collectGa4RecentTriggers(),
      eventPresentation: resolveGa4EventPresentation({
        measurementId: measurementId,
        configured: configured,
        enableInDev: enableInDev,
        gtagScript: gtagScript,
        gtagRuntime: gtagRuntime,
        eventsAllowed: eventsAllowed,
        eventsWillFire: eventsWillFire,
        previewOnly: previewOnly,
        localHost: localHost,
        chineseBrowser: chineseBrowser,
        host: window.location.hostname || "",
        browserLanguages: browserLanguages
      })
    };
    status.pageEvents = collectGa4PageEvents(status);
    return status;
  }

  function formatCheckLevel(level) {
    if (level === "info") return "tip";
    return level;
  }

  function resolveGa4EventPresentation(ga4) {
    if (ga4.eventsWillFire) {
      return {
        label: "Will fire",
        tone: "pass",
        headline: "CTA 事件会正常上报。",
        detail:
          "当前为公网 host，浏览器 locale 非 zh-*，且 GA4/gtag 已就绪。点击 Download / Claim / FAB 会发送 gtag('event', 'apk_download_click', …)，附带 page_id、cta_position、event_id 等参数。"
      };
    }

    if (ga4.previewOnly) {
      return {
        label: "Preview only",
        tone: "info",
        headline: "尚未配置 GA4 ID，仅本地预览。",
        detail:
          "当前 host 与浏览器 locale 允许调试，但 site.config.js 里还没有 analytics.ga4MeasurementId。CTA 点击会在 Inspector 面板记录为已触发，并输出 console.info('[site-analytics preview]', …)，不会写入 GA4。"
      };
    }

    var notes = [];
    if (ga4.localHost) {
      notes.push(
        "Host 门禁：" +
          (ga4.host || "本地/私网 host") +
          " 属于 127.0.0.1 / localhost / .local / 私网 IP。为避免污染 GA4，点击不会推送到 GA4，但 Inspector 面板仍会记录「已触发」供本地 QA。"
      );
    }
    if (ga4.chineseBrowser) {
      notes.push(
        "Locale 门禁：浏览器语言含 zh-*（" +
          ga4.browserLanguages.join(", ") +
          "）。按站点策略，CTA 自定义事件不会推送到 GA4，但 Inspector 面板仍会记录「已触发」。"
      );
    }
    if (ga4.configured && ga4.gtagRuntime && notes.length) {
      notes.push("GA4 已连接，但上述门禁未通过前，CTA 自定义事件仍被抑制——这是预期行为，不是故障。");
    } else if (ga4.configured && !ga4.gtagRuntime && ga4.localHost && !ga4.enableInDev) {
      notes.push(
        "Dev 策略：enableInDev=false，本地预览不注入 gtag.js；生产 export 后仍会正常加载 GA4。"
      );
    } else if (ga4.configured && !ga4.gtagRuntime && ga4.localHost) {
      notes.push("本地预览未加载 gtag；切换到生产域名后会按 measurement ID 注入。");
    }

    return {
      label: notes.length > 1 ? "Panel only" : "Panel only",
      tone: "info",
      headline: "CTA 不推送到 GA4，但 Inspector 面板会记录「已触发」。",
      detail: notes.join(" ")
    };
  }

  function auditGa4Status(ga4) {
    var issues = [];
    var presentation = ga4.eventPresentation || resolveGa4EventPresentation(ga4);

    if (!ga4.configured) {
      issues.push({
        level: "info",
        label: "GA4 measurement ID",
        detail: "site.config.js 尚未设置 analytics.ga4MeasurementId。本地 QA 可接受；生产 export 前请填入 G-XXXXXXXX。"
      });
    } else {
      issues.push({
        level: "pass",
        label: "GA4 measurement ID",
        detail: "已在 site.config.js 配置 " + ga4.measurementId + "。"
      });
    }

    if (ga4.configured && ga4.gtagScript && ga4.gtagRuntime) {
      issues.push({
        level: "pass",
        label: "gtag runtime",
        detail: "googletagmanager.com/gtag/js 已加载，window.gtag() 可用。"
      });
    } else if (ga4.configured && ga4.localHost && !ga4.enableInDev) {
      issues.push({
        level: "info",
        label: "gtag runtime",
        detail:
          "已配置 measurement ID，但 enableInDev=false，本地 127.0.0.1/localhost 不注入 gtag——这是 dev 策略，不是连接失败。"
      });
    } else if (ga4.configured && !ga4.gtagRuntime && ga4.localHost) {
      issues.push({
        level: "info",
        label: "gtag runtime",
        detail: "本地/私网预览未加载 gtag；同一 ID 在生产域名 export 后会正常注入。"
      });
    } else if (ga4.configured && !ga4.gtagRuntime) {
      issues.push({
        level: "fail",
        label: "gtag runtime",
        detail: "非本地页面已配置 measurement ID，但 gtag() 缺失。请检查 GA4 snippet 是否注入到 head。"
      });
    } else if (!ga4.configured) {
      issues.push({
        level: "info",
        label: "gtag runtime",
        detail: "未配置 GA4 ID 前不会加载 gtag。"
      });
    }

    if (ga4.localHost) {
      issues.push({
        level: "info",
        label: "Host gate",
        detail:
          "当前 host " +
          (ga4.host || "(empty)") +
          " 命中本地/私网规则（127.0.0.1、localhost、.local、LAN）。CTA 不会推送到 GA4，但 Inspector 面板仍会记录点击为「已触发」。"
      });
    } else {
      issues.push({ level: "pass", label: "Host gate", detail: "公网/生产 host — Host 门禁已放行。" });
    }

    if (ga4.chineseBrowser) {
      issues.push({
        level: "info",
        label: "Locale gate",
        detail:
          "浏览器 locale 含 zh-*（" +
          ga4.browserLanguages.join(", ") +
          "）。按策略 CTA 不会推送到 GA4，但 Inspector 面板仍会记录点击为「已触发」。"
      });
    } else {
      issues.push({
        level: "pass",
        label: "Locale gate",
        detail: "浏览器 locale 非 zh-* — Locale 门禁已放行。"
      });
    }

    var pageEvents = ga4.pageEvents || { total: 0, byEventName: [], items: [] };
    if (pageEvents.total > 0) {
      issues.push({
        level: "pass",
        label: "Page event targets",
        detail:
          "检测到 " +
          pageEvents.total +
          " 个可追踪 CTA，事件名：" +
          (pageEvents.byEventName.map(function (item) { return item.eventName; }).join(", ") || pageEvents.defaultEventName) +
          "。"
      });
    } else {
      issues.push({
        level: "warn",
        label: "Page event targets",
        detail: "当前页未发现 GA4 自动监听的 Download/FAB/CTA 元素。"
      });
    }

    issues.push({
      level: presentation.tone === "pass" ? "pass" : presentation.tone,
      label: "CTA 事件结果",
      detail: presentation.headline + " " + presentation.detail
    });

    return issues;
  }

  function renderGa4Checks(checks) {
    if (!checks.length) return "";
    return (
      '<ul class="weline-seo-panel__checks weline-seo-panel__ga4-checks">' +
      checks
        .map(function (check) {
          return (
            '<li class="weline-seo-panel__check">' +
            '<span class="weline-seo-panel__badge weline-seo-panel__badge--' +
            escapeHtml(check.level) +
            '">' +
            escapeHtml(formatCheckLevel(check.level)) +
            "</span>" +
            "<div><strong>" +
            escapeHtml(check.label) +
            "</strong>" +
            (check.detail ? '<p class="weline-seo-panel__hint">' + escapeHtml(check.detail) + "</p>" : "") +
            "</div></li>"
          );
        })
        .join("") +
      "</ul>"
    );
  }

  function renderGa4RecentTriggers(recentTriggers) {
    var triggers = recentTriggers || { total: 0, items: [] };
    if (!triggers.total) {
      return (
        '<section class="weline-seo-panel__section weline-seo-panel__ga4-triggers" data-weline-ga4-triggers="true">' +
        "<h3>点击记录</h3>" +
        '<p class="weline-seo-panel__hint">尚未检测到 CTA 点击。点击 Download / FAB 后，即使门禁阻止 GA4 推送，此处也会显示「已触发」。</p>' +
        "</section>"
      );
    }

    var rows = triggers.items
      .map(function (item) {
        return (
          '<li class="weline-seo-panel__ga4-trigger">' +
          '<div class="weline-seo-panel__ga4-trigger-head">' +
          '<span class="weline-seo-panel__badge weline-seo-panel__badge--' +
          escapeHtml(item.delivery.tone) +
          '">' +
          escapeHtml(item.delivery.label) +
          "</span>" +
          "<strong>" +
          escapeHtml(item.eventName) +
          "</strong>" +
          '<span class="weline-seo-panel__ga4-trigger-time">' +
          escapeHtml(item.timeLabel) +
          "</span>" +
          "</div>" +
          (item.linkText
            ? '<p class="weline-seo-panel__ga4-trigger-text">' + escapeHtml(item.linkText) + "</p>"
            : "") +
          '<dl class="weline-seo-panel__ga4-event-meta">' +
          (item.ctaPosition
            ? "<div><dt>位置</dt><dd>" + escapeHtml(item.ctaPosition) + "</dd></div>"
            : "") +
          (item.linkUrl ? "<div><dt>链接</dt><dd>" + escapeHtml(item.linkUrl) + "</dd></div>" : "") +
          (item.eventId ? "<div><dt>event_id</dt><dd>" + escapeHtml(item.eventId) + "</dd></div>" : "") +
          "<div><dt>结果</dt><dd>" + escapeHtml(item.delivery.detail) + "</dd></div>" +
          "</dl>" +
          "</li>"
        );
      })
      .join("");

    return (
      '<section class="weline-seo-panel__section weline-seo-panel__ga4-triggers" data-weline-ga4-triggers="true">' +
      "<h3>点击记录</h3>" +
      '<p class="weline-seo-panel__ga4-events-intro">' +
      escapeHtml("本页已记录 " + triggers.total + " 次 CTA 点击。门禁环境下仅面板可见，不会推送到 GA4。") +
      "</p>" +
      '<ul class="weline-seo-panel__ga4-trigger-list">' +
      rows +
      "</ul>" +
      "</section>"
    );
  }

  function renderGa4PageEvents(pageEvents, ga4) {
    if (!pageEvents || !pageEvents.total) {
      return (
        '<section class="weline-seo-panel__section weline-seo-panel__ga4-events">' +
        "<h3>本页事件</h3>" +
        '<p class="weline-seo-panel__hint">未发现可追踪的 GA4 CTA 元素。请检查 .apk-fab、Download 链接或 data-ga-event 属性。</p>' +
        "</section>"
      );
    }

    var delivery = resolveGa4EventDelivery(ga4);
    var summaryChips = pageEvents.byEventName
      .map(function (group) {
        var positions = Object.keys(group.positions)
          .map(function (key) {
            return key + "×" + group.positions[key];
          })
          .join(", ");
        return (
          '<div class="weline-seo-panel__ga4-event-chip">' +
          "<span>" +
          escapeHtml(group.eventName) +
          "</span>" +
          "<strong>" +
          escapeHtml(String(group.count)) +
          " 个触发点</strong>" +
          '<small>' +
          escapeHtml(positions) +
          "</small>" +
          "</div>"
        );
      })
      .join("");

    var rows = pageEvents.items
      .map(function (item) {
        return (
          '<li class="weline-seo-panel__ga4-event">' +
          '<div class="weline-seo-panel__ga4-event-head">' +
          '<span class="weline-seo-panel__badge weline-seo-panel__badge--' +
          escapeHtml(item.delivery.tone) +
          '">' +
          escapeHtml(item.delivery.label) +
          "</span>" +
          "<strong>" +
          escapeHtml(item.eventName) +
          "</strong>" +
          '<span class="weline-seo-panel__ga4-event-pos">' +
          escapeHtml(item.ctaPosition) +
          "</span>" +
          "</div>" +
          '<p class="weline-seo-panel__ga4-event-text">' +
          escapeHtml(item.linkText) +
          "</p>" +
          '<dl class="weline-seo-panel__ga4-event-meta">' +
          "<div><dt>匹配规则</dt><dd>" +
          escapeHtml(item.matchType) +
          "</dd></div>" +
          "<div><dt>元素</dt><dd>" +
          escapeHtml(item.selectorHint) +
          "</dd></div>" +
          (item.linkUrl
            ? "<div><dt>链接</dt><dd>" + escapeHtml(item.linkUrl) + "</dd></div>"
            : "") +
          "<div><dt>点击结果</dt><dd>" +
          escapeHtml(item.delivery.detail) +
          "</dd></div>" +
          "</dl>" +
          "</li>"
        );
      })
      .join("");

    return (
      '<section class="weline-seo-panel__section weline-seo-panel__ga4-events">' +
      "<h3>本页事件</h3>" +
      '<p class="weline-seo-panel__ga4-events-intro">' +
      escapeHtml(
        "扫描到 " +
          pageEvents.total +
          " 个 GA4 自动监听触发点，默认事件 apk_download_click；当前环境点击结果：" +
          delivery.label +
          "。"
      ) +
      "</p>" +
      (pageEvents.apkDownloadUrl
        ? '<p class="weline-seo-panel__hint">APK 下载 URL：' + escapeHtml(pageEvents.apkDownloadUrl) + "</p>"
        : "") +
      '<div class="weline-seo-panel__ga4-event-summary">' +
      summaryChips +
      "</div>" +
      '<ul class="weline-seo-panel__ga4-event-list">' +
      rows +
      "</ul>" +
      "</section>"
    );
  }

  function renderGa4Status(ga4, ga4Checks, pageEvents) {
    function chip(label, value, tone) {
      return (
        '<div class="weline-seo-panel__ga4-chip weline-seo-panel__ga4-chip--' +
        escapeHtml(tone) +
        '"><span>' +
        escapeHtml(label) +
        "</span><strong>" +
        escapeHtml(value) +
        "</strong></div>"
      );
    }

    var presentation = ga4.eventPresentation || resolveGa4EventPresentation(ga4);
    var checks = ga4Checks || auditGa4Status(ga4);
    var connectionTone = ga4.configured && ga4.gtagRuntime ? "pass" : ga4.configured ? "info" : "info";
    if (ga4.configured && !ga4.gtagRuntime && !ga4.localHost) connectionTone = "fail";
    if (ga4.configured && ga4.localHost && !ga4.enableInDev) connectionTone = "info";

    return (
      '<div class="weline-seo-panel__ga4-grid">' +
      chip("GA4 ID", ga4.configured ? ga4.measurementId : "Not configured", ga4.configured ? "pass" : "info") +
      chip("gtag", ga4.gtagRuntime ? "Loaded" : ga4.gtagScript ? "Script only" : "Not loaded", connectionTone) +
      chip("CTA events", presentation.label, presentation.tone) +
      "</div>" +
      '<p class="weline-seo-panel__ga4-note">' +
      escapeHtml(presentation.headline) +
      " " +
      escapeHtml(presentation.detail) +
      "</p>" +
      '<dl class="weline-seo-panel__grid">' +
      '<div class="weline-seo-panel__field"><dt>Current host</dt><dd>' +
      escapeHtml(ga4.host || "(empty)") +
      (ga4.localHost ? " · Host 门禁开启（本地预览预期行为）" : " · Host 门禁关闭") +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>Browser languages</dt><dd>' +
      escapeHtml(ga4.browserLanguages.join(", ") || "unknown") +
      (ga4.chineseBrowser ? " · Locale 门禁开启（zh 浏览器预期行为）" : " · Locale 门禁关闭") +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>Dev gtag policy</dt><dd>' +
      escapeHtml(
        ga4.enableInDev
          ? "enableInDev=true — dev 预览在已配置 ID 时可能加载 gtag。"
          : "enableInDev=false — 本地预览即使有 measurement ID 也不注入 gtag。"
      ) +
      "</dd></div>" +
      "</dl>" +
      renderGa4RecentTriggers(ga4.recentTriggers) +
      renderGa4PageEvents(pageEvents || ga4.pageEvents, ga4) +
      renderGa4Checks(checks)
    );
  }

  function normalizeHeadingText(node) {
    return (node.textContent || "").replace(/\s+/g, " ").trim();
  }

  function collectHeadingOutline() {
    var nodes = Array.from(document.querySelectorAll("h1, h2, h3, h4, h5, h6")).filter(function (node) {
      return !node.closest(".weline-seo-panel");
    });

    var items = nodes.map(function (node, index) {
      var level = Number(node.tagName.slice(1));
      var text = normalizeHeadingText(node);
      var zone = "body";
      if (node.closest("header")) zone = "header";
      else if (node.closest("footer")) zone = "footer";
      else if (node.closest("main, .site-shell")) zone = "main";

      return {
        index: index + 1,
        level: level,
        tag: node.tagName.toLowerCase(),
        text: text,
        zone: zone,
        empty: !text,
        skipped: false,
        issue: ""
      };
    });

    var lastLevel = 0;
    items.forEach(function (item) {
      if (lastLevel === 0) {
        if (item.level !== 1) {
          item.skipped = true;
          item.issue = "First visible heading should be H1, got " + item.tag.toUpperCase() + ".";
        }
      } else if (item.level > lastLevel + 1) {
        item.skipped = true;
        item.issue = "Skipped from H" + lastLevel + " to H" + item.level + ".";
      }
      lastLevel = item.level;
    });

    var counts = { h1: 0, h2: 0, h3: 0, h4: 0, h5: 0, h6: 0 };
    items.forEach(function (item) {
      counts[item.tag] = (counts[item.tag] || 0) + 1;
    });

    return { items: items, counts: counts };
  }

  function buildHeadingTree(items) {
    var root = { children: [] };
    var stack = [{ level: 0, node: root }];

    items.forEach(function (item) {
      var entry = { item: item, children: [] };
      while (stack.length > 1 && stack[stack.length - 1].level >= item.level) {
        stack.pop();
      }
      stack[stack.length - 1].node.children.push(entry);
      stack.push({ level: item.level, node: entry });
    });

    return root.children;
  }

  function auditHeadingOutline(outline) {
    var issues = [];
    if (!outline.items.length) {
      issues.push({ level: "fail", label: "Heading outline", detail: "No H1-H6 headings found on page." });
      return issues;
    }

    var emptyCount = outline.items.filter(function (item) { return item.empty; }).length;
    if (emptyCount) {
      issues.push({
        level: "fail",
        label: "Empty headings",
        detail: emptyCount + " heading node(s) have no visible text."
      });
    } else {
      issues.push({ level: "pass", label: "Empty headings", detail: "All headings contain text." });
    }

    var skipped = outline.items.filter(function (item) { return item.skipped; });
    if (skipped.length) {
      issues.push({
        level: "warn",
        label: "Heading level jumps",
        detail: skipped.length + " node(s) skip levels or start below H1."
      });
    } else {
      issues.push({ level: "pass", label: "Heading level order", detail: "No H-level skips detected." });
    }

    if (outline.counts.h1 === 1) {
      issues.push({ level: "pass", label: "H1 in outline", detail: "Single H1 anchor present." });
    }

    return issues;
  }

  function summarizeChecks(checks) {
    return {
      pass: checks.filter(function (item) { return item.level === "pass"; }).length,
      fail: checks.filter(function (item) { return item.level === "fail"; }).length,
      warn: checks.filter(function (item) { return item.level === "warn"; }).length,
      info: checks.filter(function (item) { return item.level === "info"; }).length,
      total: checks.length
    };
  }

  function normalizeCompareText(value) {
    return String(value || "")
      .toLowerCase()
      .replace(/\s+/g, " ")
      .trim();
  }

  function textsAlign(left, right) {
    var a = normalizeCompareText(left);
    var b = normalizeCompareText(right);
    if (!a || !b) return false;
    if (a === b) return true;
    return a.indexOf(b) !== -1 || b.indexOf(a) !== -1;
  }

  function pageHtmlForScan() {
    var clone = document.documentElement.cloneNode(true);
    clone.querySelectorAll(".weline-seo-panel, script, style").forEach(function (node) {
      node.remove();
    });
    return clone.innerHTML || "";
  }

  function getMainH1Text() {
    var node = Array.from(document.querySelectorAll("h1")).find(function (item) {
      return !item.closest(".weline-seo-panel");
    });
    return node ? normalizeHeadingText(node) : "";
  }

  function hreflangMatchesPage(code, lang) {
    var normalizedCode = String(code || "").toLowerCase().replace(/_/g, "-");
    var normalizedLang = String(lang || "").toLowerCase().replace(/_/g, "-");
    if (normalizedCode === normalizedLang) return true;
    var codeParts = normalizedCode.split("-");
    var langParts = normalizedLang.split("-");
    return codeParts[0] === langParts[0] && codeParts[1] === langParts[1];
  }

  function auditKeywordStandards(context, add) {
    var raw = metaContent('meta[name="keywords"]');
    var keywords = splitKeywords(raw);
    var readableText = [
      context.title,
      context.description,
      context.h1Text,
      document.body ? document.body.textContent || "" : ""
    ].join(" ");

    if (!raw || !keywords.length) {
      add("fail", "meta keywords", "Missing meta keywords. Add 4-12 page-intent keywords.", "head");
      return;
    }

    if (keywords.length >= 4 && keywords.length <= 12) {
      add("pass", "keyword count", keywords.length + " keywords.", "head");
    } else if (keywords.length < 4) {
      add("warn", "keyword count", "Only " + keywords.length + " keyword(s); target 4-12.", "head");
    } else {
      add("warn", "keyword count", keywords.length + " keywords; trim to the strongest 4-12.", "head");
    }

    if (raw.length <= 255) {
      add("pass", "keywords length", raw.length + " chars.", "head");
    } else {
      add("warn", "keywords length", raw.length + " chars; avoid keyword stuffing over 255 chars.", "head");
    }

    var duplicates = countDuplicates(keywords);
    if (duplicates) add("warn", "keyword duplicates", duplicates + " duplicate keyword(s) detected.", "head");
    else add("pass", "keyword duplicates", "No duplicate keywords.", "head");

    var related = keywords.filter(function (keyword) {
      return keywordAppearsInText(keyword, readableText);
    });
    if (related.length >= Math.min(3, keywords.length)) {
      add("pass", "keyword relevance", related.length + " keyword(s) appear in title/description/H1/body.", "content");
    } else if (related.length) {
      add(
        "warn",
        "keyword relevance",
        "Only " + related.length + " keyword(s) appear in visible page context; align keywords with page copy.",
        "content"
      );
    } else {
      add("fail", "keyword relevance", "Keywords do not appear in title, description, H1, or body copy.", "content");
    }

    var primary = keywords[0] || "";
    if (primary && (keywordAppearsInText(primary, context.title) || keywordAppearsInText(primary, context.h1Text))) {
      add("pass", "primary keyword placement", 'Primary keyword "' + primary + '" appears in title or H1.', "content");
    } else if (primary && keywordAppearsInText(primary, context.description)) {
      add("warn", "primary keyword placement", 'Primary keyword "' + primary + '" only appears in description.', "content");
    } else {
      add("warn", "primary keyword placement", "Primary keyword should appear naturally in title or H1.", "content");
    }

    var tokenCounts = {};
    keywords.forEach(function (keyword) {
      var tokens = textTokenSet(keyword);
      Object.keys(tokens).forEach(function (token) {
        tokenCounts[token] = (tokenCounts[token] || 0) + 1;
      });
    });
    var repeatLimit = Math.max(6, Math.ceil(keywords.length * 0.7));
    var repeatedTokens = Object.keys(tokenCounts).filter(function (token) { return tokenCounts[token] > repeatLimit; });
    if (repeatedTokens.length) {
      add("warn", "keyword stuffing", "Repeated token(s): " + repeatedTokens.slice(0, 6).join(", ") + ".", "head");
    } else {
      add("pass", "keyword stuffing", "No obvious keyword stuffing pattern.", "head");
    }
  }

  function auditJsonLdQuality(context, add) {
    var nodes = collectJsonLdNodes();
    if (!nodes.length) {
      add("fail", "JSON-LD coverage", "No JSON-LD blocks found in head.", "schema");
      return;
    }

    var website = jsonLdNodesOfType(nodes, "WebSite")[0];
    if (website && website.publisher) {
      add("pass", "WebSite publisher", "WebSite schema includes publisher.", "schema");
    } else if (context.seoType === "home") {
      add("warn", "WebSite publisher", "Home WebSite schema should include publisher Organization.", "schema");
    }

    var organization = jsonLdNodesOfType(nodes, "Organization")[0];
    if (organization && organization.logo) {
      add("pass", "Organization logo", "Organization schema includes logo.", "schema");
    } else if (context.seoType === "home") {
      add("warn", "Organization logo", "Organization schema should include logo URL.", "schema");
    }

    var breadcrumb = jsonLdNodesOfType(nodes, "BreadcrumbList")[0];
    if (breadcrumb && Array.isArray(breadcrumb.itemListElement) && breadcrumb.itemListElement.length) {
      add("pass", "Breadcrumb items", breadcrumb.itemListElement.length + " breadcrumb item(s).", "schema");
    } else {
      add("warn", "Breadcrumb items", "BreadcrumbList should expose itemListElement.", "schema");
    }

    var article = jsonLdNodesOfType(nodes, "Article")[0];
    if (article) {
      if (article.headline && article.mainEntityOfPage && article.dateModified) {
        add("pass", "Article required fields", "Article has headline, mainEntityOfPage, and dateModified.", "schema");
      } else {
        add("warn", "Article required fields", "Article schema should include headline, mainEntityOfPage, and dateModified.", "schema");
      }
    }

    var faq = jsonLdNodesOfType(nodes, "FAQPage")[0];
    if (faq) {
      var count = Array.isArray(faq.mainEntity) ? faq.mainEntity.length : 0;
      if (count >= 2) add("pass", "FAQ entities", count + " FAQ entities.", "schema");
      else add("warn", "FAQ entities", "FAQPage should include at least 2 Q&A entities.", "schema");
    }
  }

  function auditSeoStandards(context, add) {
    var title = context.title;
    var description = context.description;
    var canonical = context.canonical;
    var seoType = context.seoType;
    var jsonTypes = context.jsonTypes;
    var h1Text = context.h1Text;
    var hreflangCodes = context.hreflangCodes;
    var htmlForScan = context.htmlForScan;

    if (!title) add("fail", "title content", "Title tag is empty.", "head");
    if (!description) add("fail", "meta description", "Meta description is empty.", "head");
    auditKeywordStandards(context, add);

    var robots = document.querySelector('meta[name="robots"]');
    var robotsContent = robots ? robots.getAttribute("content") || "" : "";
    if (/noindex/i.test(robotsContent)) {
      add("fail", "indexability", 'Robots meta contains "noindex".', "technical");
    } else {
      add("pass", "indexability", "Robots meta allows indexing.", "technical");
    }

    var titleNodes = document.querySelectorAll("head title");
    if (titleNodes.length === 1) add("pass", "single title tag", "Exactly one <title> in head.", "head");
    else add("fail", "single title tag", "Expected one <title>, found " + titleNodes.length + ".", "head");

    var descriptionNodes = document.querySelectorAll('head meta[name="description"]');
    if (descriptionNodes.length === 1) add("pass", "single description meta", "Exactly one meta description.", "head");
    else add("fail", "single description meta", "Expected one meta description, found " + descriptionNodes.length + ".", "head");

    var canonicalNodes = document.querySelectorAll('head link[rel="canonical"]');
    if (canonicalNodes.length === 1) add("pass", "single canonical", "Exactly one canonical link.", "url");
    else add("fail", "single canonical", "Expected one canonical link, found " + canonicalNodes.length + ".", "url");

    var sitemapLink = document.querySelector('link[rel="sitemap"][href]');
    if (sitemapLink) {
      var sitemapHref = sitemapLink.getAttribute("href") || "";
      if (/^(https?:\/\/|\/)/i.test(sitemapHref)) {
        add("pass", "sitemap discovery", "Sitemap link present: " + sitemapHref + ".", "technical");
      } else {
        add("warn", "sitemap discovery", "Sitemap link should be absolute or root-relative.", "technical");
      }
    } else {
      add("fail", "sitemap discovery", "Missing <link rel=\"sitemap\" href=\"/sitemap.xml\">.", "technical");
    }

    if (document.querySelector("header")) add("pass", "semantic header", "<header> present.", "structure");
    else add("warn", "semantic header", "Missing <header> landmark.", "structure");

    if (document.querySelector("main, .site-shell")) add("pass", "semantic main", "Primary content landmark present.", "structure");
    else add("fail", "semantic main", "Missing <main> or .site-shell wrapper.", "structure");

    if (document.querySelector("footer")) add("pass", "semantic footer", "<footer> present.", "structure");
    else add("warn", "semantic footer", "Missing <footer> landmark.", "structure");

    if (h1Text && title && !textsAlign(title, h1Text)) {
      add(
        "warn",
        "title/H1 alignment",
        'Title and H1 should describe the same topic. Title: "' + title + '". H1: "' + h1Text + '".',
        "content"
      );
    } else if (h1Text && title) {
      add("pass", "title/H1 alignment", "Title and H1 are semantically aligned.", "content");
    }

    var ogTitle = metaContent('meta[property="og:title"]');
    var ogDescription = metaContent('meta[property="og:description"]');
    var ogUrl = metaContent('meta[property="og:url"]');
    var ogType = metaContent('meta[property="og:type"]');
    var ogImageAlt = metaContent('meta[property="og:image:alt"]');
    var twitterTitle = metaContent('meta[name="twitter:title"]');
    var twitterDescription = metaContent('meta[name="twitter:description"]');
    var twitterCard = metaContent('meta[name="twitter:card"]');
    var twitterImageAlt = metaContent('meta[name="twitter:image:alt"]');

    if (ogTitle && textsAlign(title, ogTitle)) add("pass", "og:title parity", "og:title matches page title.", "social");
    else if (ogTitle) add("warn", "og:title parity", "og:title differs from <title>.", "social");

    if (ogDescription && textsAlign(description, ogDescription)) {
      add("pass", "og:description parity", "og:description matches meta description.", "social");
    } else if (ogDescription) {
      add("warn", "og:description parity", "og:description differs from meta description.", "social");
    }

    if (canonical && ogUrl && canonical === ogUrl) add("pass", "og:url parity", "og:url equals canonical.", "social");
    else if (canonical && ogUrl) add("fail", "og:url parity", "og:url should equal canonical URL.", "social");

    var localeAlternates = document.querySelectorAll('meta[property="og:locale:alternate"]');
    var localeLangCount = hreflangCodes.filter(function (code) { return code !== "x-default"; }).length;
    if (localeLangCount > 1) {
      if (localeAlternates.length) {
        add("pass", "og:locale:alternate", localeAlternates.length + " alternate locale tag(s).", "social");
      } else {
        add("warn", "og:locale:alternate", "Multilingual page missing og:locale:alternate meta tags.", "social");
      }
    }

    if (twitterTitle && textsAlign(title, twitterTitle)) {
      add("pass", "twitter:title parity", "twitter:title matches page title.", "social");
    } else if (twitterTitle) {
      add("warn", "twitter:title parity", "twitter:title differs from <title>.", "social");
    }

    if (twitterDescription && textsAlign(description, twitterDescription)) {
      add("pass", "twitter:description parity", "twitter:description matches meta description.", "social");
    } else if (twitterDescription) {
      add("warn", "twitter:description parity", "twitter:description differs from meta description.", "social");
    }

    if (/summary_large_image/i.test(twitterCard)) {
      add("pass", "twitter:card", 'Uses "summary_large_image".', "social");
    } else if (twitterCard) {
      add("warn", "twitter:card", 'Prefer twitter:card="summary_large_image" for share previews.', "social");
    }

    if (ogImageAlt && textsAlign(ogImageAlt, title)) {
      add("pass", "og:image alt", "og:image:alt aligns with title.", "social");
    } else if (ogImageAlt) {
      add("warn", "og:image alt", "og:image:alt should describe the share image and page intent.", "social");
    }

    if (twitterImageAlt && textsAlign(twitterImageAlt, title)) {
      add("pass", "twitter:image alt", "twitter:image:alt aligns with title.", "social");
    } else if (twitterImageAlt) {
      add("warn", "twitter:image alt", "twitter:image:alt should describe the share image and page intent.", "social");
    }

    if (canonical && /^https:\/\//i.test(canonical)) {
      add("pass", "canonical scheme", "Canonical uses HTTPS absolute URL.", "url");
    } else if (canonical) {
      add("fail", "canonical scheme", "Canonical must be an absolute HTTPS URL.", "url");
    }

    var normalizedLangCode = context.lang.toLowerCase().replace(/^([a-z]{2})-([a-z]{2})$/, function (_m, a, b) {
      return a + "-" + b.toUpperCase();
    });
    var hasSelfAlternate = hreflangCodes.some(function (code) {
      return hreflangMatchesPage(code, context.lang) || code === normalizedLangCode;
    });
    if (hasSelfAlternate) add("pass", "hreflang self", "Current language has a self-referencing hreflang.", "url");
    else add("warn", "hreflang self", "Missing hreflang for current page language " + context.lang + ".", "url");

    Array.from(document.querySelectorAll('link[rel="alternate"][hreflang]')).forEach(function (node) {
      var href = node.href || node.getAttribute("href") || "";
      if (href && !/^https?:\/\//i.test(href)) {
        add("fail", "hreflang absolute URL", 'hreflang="' + node.getAttribute("hreflang") + '" is not absolute.', "url");
      }
    });

    if (jsonTypes.indexOf("INVALID_JSON") !== -1) {
      add("fail", "JSON-LD parse", "One or more JSON-LD blocks failed to parse.", "schema");
    } else if (jsonTypes.length) {
      add("pass", "JSON-LD parse", jsonTypes.length + " schema type(s) parsed successfully.", "schema");
    }

    if (seoType === "home" && ogType === "website") add("pass", "home og:type", 'og:type is "website".', "schema");
    if (seoType === "faq" && jsonTypes.indexOf("FAQPage") !== -1) {
      add("pass", "FAQPage schema", "FAQPage JSON-LD present.", "schema");
    }
    auditJsonLdQuality(context, add);

    var internalLinks = Array.from(document.querySelectorAll("a[href]")).filter(function (node) {
      if (node.closest(".weline-seo-panel")) return false;
      var href = node.getAttribute("href") || "";
      return href.startsWith("/") || href.indexOf(context.siteDomain) !== -1 || href.indexOf("{{link") !== -1;
    });
    if (internalLinks.length >= 5) {
      add("pass", "internal links", internalLinks.length + " internal links detected.", "content");
    } else {
      add("warn", "internal links", "Only " + internalLinks.length + " internal links; add descriptive in-site links.", "content");
    }

    var downloadCta = document.querySelector(
      '.apk-fab, .promo-hero__cta, .academy-app-promo__cta, [data-ga-event], [data-apk-action], a[download][href$=".apk"]'
    );
    if (downloadCta) add("pass", "APK download CTA", "Download/FAB CTA module detected.", "content");
    else add("warn", "APK download CTA", "No APK download CTA module detected on page.", "content");

    var emptyLinks = Array.from(document.querySelectorAll("a[href]")).filter(function (node) {
      if (node.closest(".weline-seo-panel")) return false;
      var href = (node.getAttribute("href") || "").trim();
      return !href || href === "#";
    });
    if (emptyLinks.length) {
      add("warn", "empty links", emptyLinks.length + ' anchor(s) use empty href or "#".', "content");
    } else {
      add("pass", "empty links", "No empty anchor hrefs detected.", "content");
    }

    PUBLIC_COPY_LEAKS.forEach(function (pattern) {
      if (pattern.re.test(htmlForScan)) {
        add("fail", "public copy leak", "Detected internal copy pattern: " + pattern.name + ".", "compliance");
      }
    });
    if (!PUBLIC_COPY_LEAKS.some(function (pattern) { return pattern.re.test(htmlForScan); })) {
      add("pass", "public copy leak", "No known internal planning/copy leaks detected.", "compliance");
    }

    PROMPT_LEAK_PATTERNS.forEach(function (pattern) {
      if (pattern.re.test(htmlForScan)) {
        add("fail", "prompt leak", "Detected internal prompt/build vocabulary: " + pattern.name + ".", "compliance");
      }
    });
    if (!PROMPT_LEAK_PATTERNS.some(function (pattern) { return pattern.re.test(htmlForScan); })) {
      add("pass", "prompt leak", "No prompt/generator vocabulary detected in visible copy.", "compliance");
    }

    if (isLocalHost() && canonical && canonical.indexOf("https://" + context.siteDomain) === 0) {
      add(
        "info",
        "dev canonical preview",
        "Dev host uses production canonical (" + canonical + "). This is expected for export preview.",
        "technical"
      );
    }
  }

  function auditCurrentPage() {
    var checks = [];
    var siteDomain = inferSiteDomain();
    var lang = inferLang();
    var slug = inferSlug();
    var seoType = inferSeoTypeFromPage();
    var defaultLang = inferDefaultLanguage();
    var title = (document.title || "").trim();
    var description = metaContent('meta[name="description"]');
    var keywords = metaContent('meta[name="keywords"]');
    var canonicalNode = document.querySelector('link[rel="canonical"]');
    var canonical = canonicalNode ? canonicalNode.href : "";
    var htmlLang = document.documentElement.lang || "";
    var jsonTypes = extractJsonLdTypes();
    var h1Count = Array.from(document.querySelectorAll("h1")).filter(function (node) {
      return !node.closest(".weline-seo-panel");
    }).length;
    var textLength = visibleTextLength(document.body || document.documentElement);
    var images = Array.from(document.querySelectorAll("main img, .site-shell img, body img"))
      .map(function (img) {
        return {
          src: img.getAttribute("src") || "",
          alt: img.getAttribute("alt") || "",
          decorative: isDecorativeImage(img)
        };
      })
      .filter(function (img) { return img.src && !img.decorative && !isBrandChromeImage(img.src); });
    var missingAlt = images.filter(function (img) { return !img.alt || img.alt.length < 8; }).length;

    function add(level, label, detail, group) {
      checks.push({ level: level, label: label, detail: detail || "", group: group || "technical" });
    }

    if (document.body && document.body.innerHTML.indexOf("{{") !== -1) {
      add("fail", "Unresolved placeholder", "Body still contains {{...}} tokens.", "technical");
    }

    if (htmlLang !== lang) {
      add("fail", "html lang mismatch", 'Expected "' + lang + '", got "' + htmlLang + '".', "url");
    } else {
      add("pass", "html lang", lang, "url");
    }

    REQUIRED_HEAD.forEach(function (rule) {
      if (rule.test()) add("pass", rule.name, "Present in head.", "head");
      else add("fail", rule.name, "Missing from head.", "head");
    });

    if (canonical && siteDomain && canonical.indexOf("https://" + siteDomain) === 0) {
      add("pass", "canonical host", siteDomain, "url");
    } else {
      add("fail", "canonical host", canonical || "Missing canonical link.", "url");
    }

    var expected = resolveExpectedCanonical(siteDomain, lang, slug, defaultLang);
    var normalizedCanonical = normalizeCanonicalUrl(canonical);
    if (normalizedCanonical === expected) add("pass", "canonical path", expected, "url");
    else add("fail", "canonical path", "Expected " + expected + (canonical ? ", got " + normalizedCanonical : "."), "url");

    if (title.length >= SEO_TEXT_LIMITS.titleMin && title.length <= SEO_TEXT_LIMITS.titleMax) {
      add("pass", "title length", title.length + " chars", "content");
    } else {
      add(
        "fail",
        "title length",
        "Expected " + SEO_TEXT_LIMITS.titleMin + "-" + SEO_TEXT_LIMITS.titleMax + ", got " + title.length + ".",
        "content"
      );
    }

    if (description.length >= SEO_TEXT_LIMITS.descriptionMin && description.length <= SEO_TEXT_LIMITS.descriptionMax) {
      add("pass", "description length", description.length + " chars", "content");
    } else {
      add(
        "fail",
        "description length",
        "Expected " + SEO_TEXT_LIMITS.descriptionMin + "-" + SEO_TEXT_LIMITS.descriptionMax + ", got " + description.length + ".",
        "content"
      );
    }

    if (h1Count === 1) add("pass", "H1 count", "Exactly one H1.", "structure");
    else add("fail", "H1 count", "Expected exactly one H1, got " + h1Count + ".", "structure");

    if (document.querySelector('body script[type="application/ld+json"]')) {
      add("fail", "JSON-LD placement", "JSON-LD must live in head, not body.", "schema");
    } else {
      add("pass", "JSON-LD placement", "Head-only JSON-LD.", "schema");
    }

    if (textLength >= SEO_TEXT_LIMITS.visibleTextMin) add("pass", "visible text", textLength + " chars", "content");
    else {
      add(
        "warn",
        "visible text",
        "Thin page body: " + textLength + " chars (target " + SEO_TEXT_LIMITS.visibleTextMin + "+).",
        "content"
      );
    }

    var hreflangCodes = collectHreflangCodes();
    hreflangCodes.forEach(function (code) {
      if (code === "x-default") return;
      add("pass", "hreflang " + code, "Present.", "url");
    });
    if (!hreflangCodes.length) add("warn", "hreflang set", "No alternate hreflang links found.", "url");

    (EXPECTED_JSONLD[seoType] || EXPECTED_JSONLD.article).forEach(function (type) {
      if (jsonTypes.indexOf(type) !== -1) add("pass", "JSON-LD @" + type, "Present.", "schema");
      else add("fail", "JSON-LD @" + type, "Missing. Current: " + (jsonTypes.join(", ") || "none") + ".", "schema");
    });

    if (seoType === "home" && metaContent('meta[property="og:type"]') !== "website") {
      add("fail", "home og:type", 'Expected "website".', "schema");
    }
    if (seoType === "faq" && jsonTypes.indexOf("FAQPage") === -1) {
      add("fail", "FAQPage schema", "FAQ pages should expose FAQPage JSON-LD.", "schema");
    }
    if (seoType === "legal" && metaContent('meta[property="og:type"]') !== "website") {
      add("warn", "legal og:type", 'Legal pages usually use og:type "website".', "schema");
    }

    if (images.length < 1) add("warn", "content images", "No non-brand content images detected.", "content");
    else add("pass", "content images", images.length + " detected.", "content");
    if (missingAlt) add("warn", "image alt", missingAlt + " content image(s) missing useful alt text.", "content");
    else if (images.length) add("pass", "image alt", "Content images include alt text.", "content");

    var headingOutline = collectHeadingOutline();
    auditHeadingOutline(headingOutline).forEach(function (item) {
      add(item.level, item.label, item.detail, "structure");
    });

    auditSeoStandards(
      {
        title: title,
        description: description,
        keywords: keywords,
        canonical: canonical,
        seoType: seoType,
        jsonTypes: jsonTypes,
        h1Text: getMainH1Text(),
        hreflangCodes: hreflangCodes,
        htmlForScan: pageHtmlForScan(),
        lang: lang,
        siteDomain: siteDomain
      },
      add
    );

    var ga4Status = collectGa4Status();
    var ga4PageEvents = ga4Status.pageEvents;
    var ga4Checks = auditGa4Status(ga4Status).map(function (item) {
      return Object.assign({}, item, { group: "ga4" });
    });

    var seoSummary = summarizeChecks(checks);
    var ga4Summary = summarizeChecks(ga4Checks);

    return {
      seoSummary: seoSummary,
      ga4Summary: ga4Summary,
      summary: seoSummary,
      snapshot: {
        title: title,
        description: description,
        keywords: keywords,
        canonical: canonical,
        htmlLang: htmlLang,
        seoType: seoType,
        pageLang: lang,
        siteDomain: siteDomain,
        jsonTypes: jsonTypes,
        h1Count: h1Count,
        visibleText: textLength,
        contentImages: images.length
      },
      headingOutline: headingOutline,
      ga4Status: ga4Status,
      ga4PageEvents: ga4PageEvents,
      ga4Checks: ga4Checks,
      checks: checks
    };
  }

  var AGENT_CONTRACT_VERSION = "1.0.0";
  var AGENT_COMMAND = "weline-seo";

  var CRITICAL_CHECK_LABELS = {
    "Unresolved placeholder": true,
    "html lang mismatch": true,
    "canonical host": true,
    "canonical path": true,
    "canonical scheme": true,
    "JSON-LD placement": true,
    "indexability": true,
    "prompt leak": true,
    "public copy leak": true,
    "title content": true,
    "meta description": true,
    "meta keywords": true,
    "semantic main": true,
    "H1 count": true
  };

  var VERDICT_LABELS = {
    ship: "可推广：技术 SEO 与页面结构达标，可进入投放/外链阶段。",
    polish: "可推广但需抛光：无阻断项，建议先修 warn 再大规模推广。",
    fix: "先修复再推广：存在明确 SEO 失败项，不建议当前大规模投放。",
    blocked: "阻断发布：存在关键失败项，必须先修复后再推广。"
  };

  function buildAgentReport(raw) {
    var fails = raw.checks.filter(function (item) { return item.level === "fail"; });
    var warns = raw.checks.filter(function (item) { return item.level === "warn"; });
    var infos = raw.checks.filter(function (item) { return item.level === "info"; });
    var criticalFails = fails.filter(function (item) { return CRITICAL_CHECK_LABELS[item.label]; });
    var ga4Fails = (raw.ga4Checks || []).filter(function (item) { return item.level === "fail"; });

    var score = 100;
    fails.forEach(function () { score -= 8; });
    warns.forEach(function () { score -= 3; });
    score = Math.max(0, Math.min(100, score));

    var seoScore = Math.max(
      0,
      Math.min(
        100,
        100 -
          fails.length * 8 -
          warns.length * 3
      )
    );

    var verdict = "ship";
    if (criticalFails.length || ga4Fails.length || score < 50) verdict = "blocked";
    else if (fails.length || score < 75) verdict = "fix";
    else if (warns.length || score < 90) verdict = "polish";

    function toAction(item, priority, scope) {
      return {
        priority: priority,
        scope: scope,
        level: item.level,
        group: item.group || "general",
        label: item.label,
        detail: item.detail,
        fixHint: actionFixHint(item)
      };
    }

    var actions = fails
      .map(function (item) { return toAction(item, criticalFails.indexOf(item) !== -1 ? "P0" : "P1", "seo"); })
      .concat(warns.map(function (item) { return toAction(item, "P1", "seo"); }))
      .concat(infos.map(function (item) { return toAction(item, "P2", "seo"); }))
      .concat(
        (raw.ga4Checks || [])
          .filter(function (item) { return item.level === "fail" || item.level === "warn"; })
          .map(function (item) { return toAction(item, item.level === "fail" ? "P1" : "P2", "ga4"); })
      )
      .concat(
        (raw.ga4Checks || [])
          .filter(function (item) { return item.level === "info"; })
          .map(function (item) { return toAction(item, "P3", "ga4"); })
      );

    var groupedChecks = {};
    SEO_CHECK_GROUPS.forEach(function (group) {
      groupedChecks[group.id] = {
        title: group.title,
        items: raw.checks.filter(function (item) { return item.group === group.id; })
      };
    });

    var promoteReady = verdict === "ship" || verdict === "polish";
    var h1Text = getMainH1Text();

    return {
      contractVersion: AGENT_CONTRACT_VERSION,
      command: AGENT_COMMAND,
      generatedAt: new Date().toISOString(),
      page: {
        url: window.location.href,
        pathname: window.location.pathname,
        siteDomain: raw.snapshot.siteDomain,
        language: raw.snapshot.pageLang,
        htmlLang: raw.snapshot.htmlLang,
        seoType: raw.snapshot.seoType,
        title: raw.snapshot.title,
        description: raw.snapshot.description,
        keywords: raw.snapshot.keywords,
        canonical: raw.snapshot.canonical,
        h1: h1Text,
        h1Count: raw.snapshot.h1Count,
        visibleTextChars: raw.snapshot.visibleText,
        contentImages: raw.snapshot.contentImages,
        jsonLdTypes: raw.snapshot.jsonTypes
      },
      scores: {
        overall: score,
        seo: seoScore,
        promoteReadiness: verdict
      },
      summary: {
        seo: raw.seoSummary,
        ga4: raw.ga4Summary
      },
      verdict: {
        status: verdict,
        label: VERDICT_LABELS[verdict],
        promoteReady: promoteReady,
        criticalFailCount: criticalFails.length,
        failCount: fails.length + ga4Fails.length,
        warnCount: warns.length
      },
      checks: {
        seoGrouped: groupedChecks,
        seoFlat: raw.checks,
        ga4: raw.ga4Checks || [],
        headingOutline: raw.headingOutline
      },
      ga4: raw.ga4Status,
      ga4PageEvents: raw.ga4PageEvents || raw.ga4Status.pageEvents,
      actions: actions,
      monitoringGaps: recommendedMonitoringGaps(raw),
      agentGuide: buildAgentGuide(verdict, fails, warns, raw)
    };
  }

  function actionFixHint(item) {
    var hints = {
      "title length": "Adjust @page title to 30-65 chars with primary intent keyword near front.",
      "description length": "Rewrite meta description to 90-170 chars with value + CTA cue.",
      "meta keywords": "Add @page keywords or site.seo.keywords with 4-12 comma-separated page-intent phrases.",
      "keyword count": "Keep meta keywords focused: 4-12 comma-separated phrases.",
      "keyword relevance": "Use keywords that naturally appear in title, description, H1, or visible copy.",
      "primary keyword placement": "Place the primary keyword naturally in title or H1.",
      "keyword stuffing": "Remove repeated keyword variants and keep only distinct search intents.",
      "sitemap discovery": "Add <link rel=\"sitemap\" type=\"application/xml\" href=\"/sitemap.xml\"> in base head.",
      "visible text": "Add page-specific facts/modules until visible text reaches 2500+ chars.",
      "image alt": "Give each content image descriptive alt tied to page intent, not keyword stuffing.",
      "hreflang self": "Add hreflang link for current page language in head.",
      "title/H1 alignment": "Make H1 the on-page expression of the same intent as title.",
      "internal links": "Add descriptive internal links to hub/guide/review pages.",
      "prompt leak": "Remove internal prompt/build vocabulary from visible copy and metadata.",
      "public copy leak": "Replace internal planning phrases with reader-facing APK/card-game language.",
      "JSON-LD @WebSite": "Add WebSite JSON-LD in head via @page schema or seo-jsonld block.",
      "JSON-LD @Organization": "Add Organization JSON-LD with site logo URL in head.",
      "JSON-LD @BreadcrumbList": "Add BreadcrumbList JSON-LD matching visible route hierarchy.",
      "gtag runtime": "Ensure GA4 snippet is injected on production export; ignore local dev gate.",
      "APK download CTA": "Add FAB + in-page promo CTA wired to {{apk_download}} and data-ga-event."
    };
    return hints[item.label] || "Fix the reported check in page source HTML/head, then re-run weline-seo.";
  }

  function recommendedMonitoringGaps(raw) {
    return [
      {
        id: "site-index-coverage",
        priority: "P1",
        reason: "Page-level pass does not prove whole-site sitemap/index coverage.",
        monitor: "Run scripts/audit-seo.mjs and verify GSC indexed URLs vs sitemap."
      },
      {
        id: "cwv",
        priority: "P1",
        reason: "Current inspector does not score LCP/CLS/INP.",
        monitor: "Check PageSpeed Insights or CrUX before paid traffic."
      },
      {
        id: "serp-snippet",
        priority: "P1",
        reason: "Title/description may pass length but lose CTR vs competitors.",
        monitor: "Compare live SERP snippet for target keyword in target locale."
      },
      {
        id: "hi-pair",
        priority: "P0",
        reason: "Promoting EN without matching HI page loses hreflang and locale intent.",
        monitor: "Ensure /hi-in/ slug pair exists and is translated, not fallback English."
      },
      {
        id: "image-uniqueness",
        priority: "P1",
        reason: "Alt text pass does not catch repeated/near-duplicate content images.",
        monitor: "Use dist image hash audit and contact-sheet visual QA."
      },
      {
        id: "aeo-extractability",
        priority: "P1",
        reason: "SEO pass != AI Overview / LLM citation readiness.",
        monitor: "Add direct-answer block, FAQ schema, llms.txt facts, and fact rows near hero."
      },
      {
        id: "conversion-chain",
        priority: "P0",
        reason: "Traffic without working APK CTA chain wastes promotion spend.",
        monitor: "Verify download href resolves to APK, FAB visible on mobile, GA4 fires on prod."
      }
    ];
  }

  function buildAgentGuide(verdict, fails, warns, raw) {
    var steps = [];
    if (verdict === "blocked") {
      steps.push("Fix all P0 actions first: canonical/head/schema/compliance failures.");
      steps.push("Re-run weline-seo until verdict is fix or higher.");
    } else if (verdict === "fix") {
      steps.push("Clear all fail-level SEO checks before promotion.");
      steps.push("Prioritize head/canonical/schema/content thickness/image alt.");
    } else if (verdict === "polish") {
      steps.push("Page is promotable, but resolve warn items to improve CTR and AI extractability.");
      steps.push("Focus on visible text depth, alt text, internal links, and AEO fact blocks.");
    } else {
      steps.push("Page is promotion-ready at SEO layer; shift to backlinks, SERP snippet tests, and CWV.");
    }

    if (raw.snapshot.visibleText < SEO_TEXT_LIMITS.visibleTextMin) {
      steps.push("Content thickness below target: add page-specific fact modules, not boilerplate.");
    }

    return {
      howToJudge:
        "Use verdict.status as primary gate. fail=blocking SEO defect, warn=optimization debt, info=expected environment note (especially GA4 local/zh gates). Never treat info as fail.",
      promotionGate: verdict === "ship" || verdict === "polish",
      doNotPromoteIf: ["blocked", "fix"].indexOf(verdict) !== -1,
      interpretationOrder: ["verdict", "actions(P0->P3)", "checks.seoFlat", "monitoringGaps", "ga4"],
      nextSteps: steps
    };
  }

  function auditAgentReport() {
    return buildAgentReport(auditCurrentPage());
  }

  function publishAgentReport(report) {
    window.__WELINE_SEO_REPORT__ = report;
    var node = document.getElementById("weline-seo-report");
    if (!node) {
      node = document.createElement("script");
      node.type = "application/json";
      node.id = "weline-seo-report";
      document.head.appendChild(node);
    }
    node.textContent = JSON.stringify(report);
    window.dispatchEvent(new CustomEvent("weline-seo:report", { detail: report }));
    return report;
  }

  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function renderSummary(summary) {
    return (
      '<div class="weline-seo-panel__summary">' +
      '<div class="weline-seo-panel__stat weline-seo-panel__stat--pass"><strong>' +
      summary.pass +
      '</strong><span>Passed</span></div>' +
      '<div class="weline-seo-panel__stat weline-seo-panel__stat--fail"><strong>' +
      summary.fail +
      '</strong><span>Failed</span></div>' +
      '<div class="weline-seo-panel__stat weline-seo-panel__stat--warn"><strong>' +
      summary.warn +
      '</strong><span>Warnings</span></div>' +
      '<div class="weline-seo-panel__stat weline-seo-panel__stat--info"><strong>' +
      (summary.info || 0) +
      '</strong><span>Tips</span></div>' +
      "</div>"
    );
  }

  function renderChecks(checks) {
    return checks
      .map(function (check) {
        return (
          '<li class="weline-seo-panel__check">' +
          '<span class="weline-seo-panel__badge weline-seo-panel__badge--' + escapeHtml(check.level) + '">' +
          escapeHtml(formatCheckLevel(check.level)) +
          "</span>" +
          "<div><strong>" +
          escapeHtml(check.label) +
          "</strong>" +
          (check.detail ? '<p class="weline-seo-panel__hint">' + escapeHtml(check.detail) + "</p>" : "") +
          "</div></li>"
        );
      })
      .join("");
  }

  function renderGroupedChecks(checks) {
    return SEO_CHECK_GROUPS.map(function (group) {
      var items = checks.filter(function (check) { return check.group === group.id; });
      if (!items.length) return "";
      return (
        '<section class="weline-seo-panel__check-group">' +
        "<h4>" +
        escapeHtml(group.title) +
        "</h4>" +
        '<ul class="weline-seo-panel__checks">' +
        renderChecks(items) +
        "</ul></section>"
      );
    }).join("");
  }

  function renderSeoTab(report) {
    return (
      renderSummary(report.seoSummary) +
      '<section class="weline-seo-panel__section"><h3>Page snapshot</h3><dl class="weline-seo-panel__grid">' +
      '<div class="weline-seo-panel__field"><dt>Title</dt><dd>' +
      escapeHtml(report.snapshot.title) +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>Description</dt><dd>' +
      escapeHtml(report.snapshot.description) +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>Keywords</dt><dd>' +
      escapeHtml(report.snapshot.keywords || "missing") +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>Canonical</dt><dd>' +
      escapeHtml(report.snapshot.canonical) +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>SEO type</dt><dd>' +
      escapeHtml(report.snapshot.seoType) +
      " · " +
      escapeHtml(report.snapshot.pageLang) +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>JSON-LD</dt><dd>' +
      escapeHtml(report.snapshot.jsonTypes.join(", ") || "none") +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>Structure</dt><dd>H1=' +
      escapeHtml(String(report.snapshot.h1Count)) +
      " · text=" +
      escapeHtml(String(report.snapshot.visibleText)) +
      " chars · images=" +
      escapeHtml(String(report.snapshot.contentImages)) +
      "</dd></div>" +
      "</dl></section>" +
      '<section class="weline-seo-panel__section"><h3>Heading outline</h3>' +
      '<div class="weline-seo-panel__heading-summary">' +
      renderHeadingCounts(report.headingOutline.counts) +
      "</div>" +
      renderHeadingTree(buildHeadingTree(report.headingOutline.items)) +
      "</section>" +
      '<section class="weline-seo-panel__section"><h3>SEO checks</h3>' +
      renderGroupedChecks(report.checks) +
      "</section>"
    );
  }

  function renderGa4Tab(report) {
    return (
      renderSummary(report.ga4Summary) +
      renderGa4Status(report.ga4Status, report.ga4Checks, report.ga4PageEvents)
    );
  }

  function renderHeadingCounts(counts) {
    return ["h1", "h2", "h3", "h4", "h5", "h6"]
      .map(function (tag) {
        return (
          '<span class="weline-seo-panel__heading-count weline-seo-panel__heading-count--' +
          tag +
          '">' +
          escapeHtml(tag.toUpperCase()) +
          " " +
          escapeHtml(String(counts[tag] || 0)) +
          "</span>"
        );
      })
      .join("");
  }

  function renderHeadingTree(nodes) {
    if (!nodes.length) {
      return '<p class="weline-seo-panel__hint">No H1-H6 headings found in page content.</p>';
    }

    return (
      '<ul class="weline-seo-panel__heading-tree">' +
      nodes
        .map(function (node) {
          var item = node.item;
          var issueClass = item.empty || item.skipped ? " is-issue" : "";
          var zone =
            item.zone !== "main"
              ? '<span class="weline-seo-panel__heading-zone">' + escapeHtml(item.zone) + "</span>"
              : "";

          return (
            '<li class="weline-seo-panel__heading-node weline-seo-panel__heading-node--' +
            escapeHtml(item.tag) +
            issueClass +
            '">' +
            '<div class="weline-seo-panel__heading-row">' +
            '<span class="weline-seo-panel__heading-tag">' +
            escapeHtml(item.tag.toUpperCase()) +
            "</span>" +
            '<span class="weline-seo-panel__heading-text">' +
            escapeHtml(item.text || "(empty heading)") +
            "</span>" +
            zone +
            "</div>" +
            (item.issue ? '<p class="weline-seo-panel__hint">' + escapeHtml(item.issue) + "</p>" : "") +
            (node.children.length ? renderHeadingTree(node.children) : "") +
            "</li>"
          );
        })
        .join("") +
      "</ul>"
    );
  }

  var panelRoot = null;
  var activePanelTab = "seo";
  var ga4TriggerListenerBound = false;

  function refreshGa4TriggerSection() {
    if (!panelRoot || panelRoot.hidden) return;
    var ga4Panel = panelRoot.querySelector('[data-weline-panel="ga4"]');
    if (!ga4Panel) return;
    var ga4Status = collectGa4Status();
    var section = ga4Panel.querySelector("[data-weline-ga4-triggers]");
    var html = renderGa4RecentTriggers(ga4Status.recentTriggers);
    if (section) {
      section.outerHTML = html;
    }
  }

  function bindGa4TriggerListener() {
    if (ga4TriggerListenerBound) return;
    ga4TriggerListenerBound = true;
    window.addEventListener("site:ga4-trigger", function () {
      refreshGa4TriggerSection();
    });
  }

  function bindPanelTabs(root) {
    root.querySelectorAll("[data-weline-tab]").forEach(function (button) {
      button.addEventListener("click", function () {
        setPanelTab(root, button.getAttribute("data-weline-tab") || "seo");
      });
    });
  }

  function setPanelTab(root, tabId) {
    activePanelTab = tabId === "ga4" ? "ga4" : "seo";
    root.querySelectorAll("[data-weline-tab]").forEach(function (button) {
      var isActive = button.getAttribute("data-weline-tab") === activePanelTab;
      button.classList.toggle("is-active", isActive);
      button.setAttribute("aria-selected", isActive ? "true" : "false");
    });
    root.querySelectorAll("[data-weline-panel]").forEach(function (panel) {
      var isActive = panel.getAttribute("data-weline-panel") === activePanelTab;
      panel.classList.toggle("is-active", isActive);
      panel.hidden = !isActive;
    });
  }

  function ensurePanel() {
    if (panelRoot) return panelRoot;

    panelRoot = document.createElement("div");
    panelRoot.className = "weline-seo-panel";
    panelRoot.hidden = true;
    panelRoot.innerHTML =
      '<div class="weline-seo-panel__dialog" role="dialog" aria-modal="true" aria-labelledby="weline-seo-panel-title">' +
      '<div class="weline-seo-panel__header">' +
      '<div><h2 class="weline-seo-panel__title" id="weline-seo-panel-title">SEO Inspector</h2><p class="weline-seo-panel__subtitle"></p></div>' +
      '<button type="button" class="weline-seo-panel__close">Close</button>' +
      "</div>" +
      '<div class="weline-seo-panel__body"></div>' +
      "</div>";

    panelRoot.querySelector(".weline-seo-panel__close").addEventListener("click", closePanel);
    panelRoot.addEventListener("click", function (event) {
      if (event.target === panelRoot) closePanel();
    });
    document.body.appendChild(panelRoot);
    return panelRoot;
  }

  function renderPanel(report) {
    var root = ensurePanel();
    root.querySelector(".weline-seo-panel__subtitle").textContent = window.location.href;
    root.querySelector(".weline-seo-panel__body").innerHTML =
      '<div class="weline-seo-panel__tabs" role="tablist" aria-label="Inspector sections">' +
      '<button type="button" class="weline-seo-panel__tab is-active" data-weline-tab="seo" role="tab" aria-selected="true">SEO 校验</button>' +
      '<button type="button" class="weline-seo-panel__tab" data-weline-tab="ga4" role="tab" aria-selected="false">GA4 监控</button>' +
      "</div>" +
      '<div class="weline-seo-panel__tab-panel is-active" data-weline-panel="seo" role="tabpanel">' +
      renderSeoTab(report) +
      "</div>" +
      '<div class="weline-seo-panel__tab-panel" data-weline-panel="ga4" role="tabpanel" hidden>' +
      renderGa4Tab(report) +
      "</div>";
    bindPanelTabs(root);
    setPanelTab(root, activePanelTab);
  }

  function openPanel() {
    bindGa4TriggerListener();
    var report = auditCurrentPage();
    renderPanel(report);
    ensurePanel().hidden = false;
    document.documentElement.style.overflow = "hidden";
  }

  function closePanel() {
    if (!panelRoot) return;
    panelRoot.hidden = true;
    document.documentElement.style.overflow = "";
  }

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && panelRoot && !panelRoot.hidden) {
      event.preventDefault();
      closePanel();
    }
  });

  window.__WELINE_SEO_INSPECTOR__ = {
    open: openPanel,
    close: closePanel,
    audit: auditCurrentPage,
    report: auditAgentReport,
    publish: function () {
      return publishAgentReport(auditAgentReport());
    }
  };

  publishAgentReport(buildAgentReport(auditCurrentPage()));
  bindGa4TriggerListener();
})();
