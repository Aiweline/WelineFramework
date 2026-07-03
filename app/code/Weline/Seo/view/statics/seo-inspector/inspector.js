(function () {
  "use strict";

  var SEO_TEXT_LIMITS = {
    titleMin: 30,
    titleMax: 65,
    descriptionMin: 90,
    descriptionMax: 170,
    visibleTextMin: 2500
  };
  var SEO_AUDIT_IGNORE_SELECTOR = [
    ".weline-seo-panel",
    "#dev-tool-panel",
    "#weline-panel-token-dialog",
    "[data-weline-panel-bootstrap]",
    "[data-weline-panel-seo-bootstrap]"
  ].join(",");

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
    { id: "issues", title: "Issue 审计" },
    { id: "head", title: "Head 元数据" },
    { id: "url", title: "URL 与多语言" },
    { id: "content", title: "页面内容" },
    { id: "schema", title: "结构化数据" },
    { id: "social", title: "社交分享" },
    { id: "structure", title: "语义结构" },
    { id: "compliance", title: "合规与泄露" }
  ];

  var JSONLD_TYPE_EQUIVALENTS = {
    Article: [
      "Article",
      "NewsArticle",
      "AnalysisNewsArticle",
      "AskPublicNewsArticle",
      "BackgroundNewsArticle",
      "OpinionNewsArticle",
      "ReportageNewsArticle",
      "ReviewNewsArticle",
      "BlogPosting",
      "LiveBlogPosting",
      "SocialMediaPosting",
      "TechArticle"
    ],
    NewsArticle: [
      "NewsArticle",
      "AnalysisNewsArticle",
      "AskPublicNewsArticle",
      "BackgroundNewsArticle",
      "OpinionNewsArticle",
      "ReportageNewsArticle",
      "ReviewNewsArticle"
    ],
    BlogPosting: ["BlogPosting", "LiveBlogPosting"],
    WebPage: ["WebPage", "AboutPage", "ContactPage", "FAQPage", "ProfilePage", "CollectionPage"],
    Organization: ["Organization", "LocalBusiness", "Corporation", "NGO"],
    Review: ["Review", "CriticReview"]
  };

  var PAGE_JSONLD_RULE_ALIASES = {
    news: "news",
    news_article: "news",
    blog: "blog",
    blog_post: "blog",
    post: "blog",
    article: "article",
    story: "article",
    faq: "faq",
    review: "review",
    product: "product",
    category: "collection",
    collection: "collection",
    contact: "contact",
    legal: "legal",
    privacy: "legal",
    terms: "legal",
    home: "home",
    index: "home"
  };

  var ARTICLE_JSONLD_REQUIRED_FIELDS = [
    "headline",
    "datePublished",
    "dateModified",
    "author.name",
    "image",
    "mainEntityOfPage"
  ];

  var PAGE_JSONLD_RULES = {
    home: {
      label: "首页",
      requiredTypes: ["WebSite", "Organization", "BreadcrumbList"],
      primaryType: "WebSite",
      requiredFields: ["name", "url"],
      recommendedFields: ["publisher", "potentialAction"]
    },
    article: {
      label: "文章页",
      requiredTypes: ["Article", "BreadcrumbList"],
      primaryType: "Article",
      requiredFields: ARTICLE_JSONLD_REQUIRED_FIELDS,
      recommendedFields: ["publisher.name", "publisher.logo", "description"]
    },
    news: {
      label: "新闻页",
      requiredTypes: ["NewsArticle", "BreadcrumbList"],
      primaryType: "NewsArticle",
      requiredFields: ARTICLE_JSONLD_REQUIRED_FIELDS.concat(["publisher.name", "publisher.logo"]),
      recommendedFields: ["articleSection", "dateline", "description"]
    },
    blog: {
      label: "博客页",
      requiredTypes: ["BlogPosting", "BreadcrumbList"],
      primaryType: "BlogPosting",
      requiredFields: ARTICLE_JSONLD_REQUIRED_FIELDS,
      recommendedFields: ["publisher.name", "publisher.logo", "keywords", "articleSection", "description"]
    },
    faq: {
      label: "FAQ 页",
      requiredTypes: ["FAQPage", "BreadcrumbList"],
      primaryType: "FAQPage",
      requiredFields: ["mainEntity"],
      custom: "faq"
    },
    review: {
      label: "评测页",
      requiredTypes: ["Review", "BreadcrumbList"],
      primaryType: "Review",
      requiredFields: ["itemReviewed", "reviewRating.ratingValue", "author.name"],
      recommendedFields: ["reviewBody", "datePublished"]
    },
    product: {
      label: "产品页",
      requiredTypes: ["Product", "BreadcrumbList"],
      primaryType: "Product",
      requiredFields: ["name", "image"],
      recommendedFields: ["description", "offers.price", "offers.priceCurrency", "offers.availability", "aggregateRating.ratingValue"]
    },
    collection: {
      label: "列表页",
      requiredTypes: ["CollectionPage", "BreadcrumbList"],
      primaryType: "CollectionPage",
      requiredFields: ["name", "url"],
      recommendedFields: ["mainEntity", "description"]
    },
    contact: {
      label: "联系页",
      requiredTypes: ["ContactPage", "BreadcrumbList"],
      primaryType: "ContactPage",
      requiredFields: ["name", "url"],
      recommendedFields: ["mainEntity", "about", "publisher.name", "publisher.logo"]
    },
    legal: {
      label: "法律/政策页",
      requiredTypes: ["WebPage", "BreadcrumbList", "WebSite", "Organization"],
      primaryType: "WebPage",
      requiredFields: ["name", "url"],
      recommendedFields: ["dateModified", "publisher.name"]
    }
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
    {
      name: "article:section",
      types: ["article", "blog_post", "post", "news", "news_article"],
      test: function () { return Boolean(document.querySelector('meta[property="article:section"]')); }
    },
    {
      name: "article:modified_time",
      types: ["article", "blog_post", "post", "news", "news_article"],
      test: function () { return Boolean(document.querySelector('meta[property="article:modified_time"]')); }
    },
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

  var ENGINE_PROFILES = [
    {
      id: "google",
      name: "Google",
      label: "Google Search",
      userAgents: ["Googlebot", "Googlebot-Image", "Googlebot-News"],
      focus: ["Search Essentials", "结构化数据", "移动端", "Core Web Vitals", "AI Search 基础 SEO"]
    },
    {
      id: "bing",
      name: "Bing",
      label: "Microsoft Bing",
      userAgents: ["bingbot", "BingPreview"],
      focus: ["Bing Webmaster Guidelines", "IndexNow", "结构化数据", "可见内容一致性"]
    },
    {
      id: "yahoo",
      name: "Yahoo",
      label: "Yahoo Search",
      userAgents: ["bingbot"],
      focus: ["Yahoo 内容建议", "Bing 适配", "title/description 准确性", "图片 ALT"]
    },
    {
      id: "yandex",
      name: "Yandex",
      label: "Yandex",
      userAgents: ["YandexBot", "YandexImages"],
      focus: ["YandexBot", "sitemap", "canonical", "description", "BreadcrumbList"]
    },
    {
      id: "baidu",
      name: "Baidu",
      label: "Baidu",
      userAgents: ["Baiduspider"],
      focus: ["Baiduspider", "移动体验", "内容质量", "中文搜索反作弊", "URL 提交"]
    },
    {
      id: "duckduckgo",
      name: "DuckDuckGo",
      label: "DuckDuckGo",
      userAgents: ["DuckDuckBot"],
      focus: ["Bing 适配", "DuckDuckBot 可抓取", "实体信息清晰度"]
    },
    {
      id: "naver",
      name: "Naver",
      label: "Naver",
      userAgents: ["Yeti"],
      focus: ["Yeti", "absolute canonical", "移动/桌面映射", "schema.org", "title 唯一性"]
    },
    {
      id: "seznam",
      name: "Seznam",
      label: "Seznam.cz",
      userAgents: ["SeznamBot"],
      focus: ["SeznamBot", "robots", "绝对 sitemap", "canonical 相似性", "结构化数据"]
    },
    {
      id: "sogou",
      name: "Sogou",
      label: "Sogou",
      userAgents: ["Sogou web spider", "Sogou inst spider"],
      focus: ["Sogou spider", "robots", "meta robots", "sitemap 限制", "低质 URL 风险"]
    },
    {
      id: "ecosia_qwant",
      name: "Ecosia/Qwant",
      label: "Ecosia / Qwant / EUSP",
      userAgents: ["bingbot", "Googlebot"],
      focus: ["Bing/Google 基础适配", "欧洲多语言", "实体可信度", "隐私搜索可见性"]
    }
  ];

  var ENGINE_MATRIX_ROWS = [
    { id: "crawlability", label: "可抓取" },
    { id: "indexability", label: "可索引" },
    { id: "canonical", label: "Canonical" },
    { id: "sitemap", label: "Sitemap" },
    { id: "structured_data", label: "结构化数据" },
    { id: "mobile", label: "移动端" },
    { id: "performance", label: "性能/CWV" },
    { id: "content_spam", label: "内容/Spam 风险" },
    { id: "engine_specific", label: "平台专项" }
  ];

  var BROWSER_MODE_LIMITATIONS = [
    "浏览器内检测只能读取当前渲染 DOM，不能可靠确认跨域 robots.txt、HTTP headers、X-Robots-Tag、证书详情、重定向链和真实状态码。",
    "当前模式不能证明全站 sitemap 质量、全站重复 title/description、孤岛页、点击深度、站点级索引覆盖或搜索引擎真实收录状态。",
    "Core Web Vitals、PageSpeed、CrUX、IndexNow key、各平台站长后台状态需要服务端爬虫/API 或人工凭据验证。"
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

  function normalizeSeoType(value) {
    return String(value || "").trim().toLowerCase().replace(/[\s-]+/g, "_");
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

  function normalizeSchemaTypeName(type) {
    return String(type || "")
      .trim()
      .replace(/^https?:\/\/schema\.org\//i, "")
      .replace(/^schema:/i, "")
      .split(/[\/#]/)
      .pop();
  }

  function schemaTypeCandidates(expected) {
    var normalized = normalizeSchemaTypeName(expected);
    return JSONLD_TYPE_EQUIVALENTS[normalized] || [normalized];
  }

  function schemaTypeMatches(actual, expected) {
    var normalizedActual = normalizeSchemaTypeName(actual);
    if (!normalizedActual) return false;
    return schemaTypeCandidates(expected).indexOf(normalizedActual) !== -1;
  }

  function jsonLdTypesInclude(types, expected) {
    return (types || []).some(function (type) {
      if (Array.isArray(type)) {
        return type.some(function (entry) { return schemaTypeMatches(entry, expected); });
      }
      return schemaTypeMatches(type, expected);
    });
  }

  function extractJsonLdTypes() {
    var types = [];
    var scripts = document.querySelectorAll('head script[type="application/ld+json"]');
    scripts.forEach(function (script) {
      try {
        var data = JSON.parse(script.textContent || "{}");
        collectJsonLdNodesFromData(data).forEach(function (node) {
          jsonLdTypeList(node).forEach(function (type) {
            if (types.indexOf(type) === -1) types.push(type);
          });
        });
      } catch (_error) {
        types.push("INVALID_JSON");
      }
    });
    return types;
  }

  function collectJsonLdNodesFromData(data) {
    var nodes = [];
    function pushNode(node) {
      if (!node) return;
      if (Array.isArray(node)) {
        node.forEach(pushNode);
        return;
      }
      if (typeof node !== "object") return;
      nodes.push(node);
      if (Array.isArray(node["@graph"])) {
        node["@graph"].forEach(pushNode);
      }
    }
    pushNode(data);
    return nodes;
  }

  function collectJsonLdNodes() {
    var nodes = [];
    var scripts = document.querySelectorAll('head script[type="application/ld+json"]');
    scripts.forEach(function (script) {
      try {
        var data = JSON.parse(script.textContent || "{}");
        collectJsonLdNodesFromData(data).forEach(function (node) { nodes.push(node); });
      } catch (_error) {
        nodes.push({ "@type": "INVALID_JSON" });
      }
    });
    return nodes.filter(Boolean);
  }

  function jsonLdTypeList(node) {
    var type = node && node["@type"];
    if (!type) return [];
    return (Array.isArray(type) ? type : [type])
      .map(normalizeSchemaTypeName)
      .filter(Boolean);
  }

  function jsonLdNodesOfType(nodes, type) {
    return nodes.filter(function (node) {
      return jsonLdTypeList(node).some(function (actualType) {
        return schemaTypeMatches(actualType, type);
      });
    });
  }

  function isMeaningfulJsonLdValue(value) {
    if (value === null || value === undefined) return false;
    if (Array.isArray(value)) return value.some(isMeaningfulJsonLdValue);
    if (typeof value === "object") return Object.keys(value).length > 0;
    return String(value).trim() !== "";
  }

  function jsonLdValuesAtPath(value, parts) {
    if (!parts.length) return [value];
    if (Array.isArray(value)) {
      return value.reduce(function (list, item) {
        return list.concat(jsonLdValuesAtPath(item, parts));
      }, []);
    }
    if (!value || typeof value !== "object") return [];
    if (!Object.prototype.hasOwnProperty.call(value, parts[0])) return [];
    return jsonLdValuesAtPath(value[parts[0]], parts.slice(1));
  }

  function hasJsonLdPath(node, path) {
    return String(path || "")
      .split("|")
      .some(function (candidate) {
        var parts = candidate.split(".").filter(Boolean);
        return jsonLdValuesAtPath(node, parts).some(isMeaningfulJsonLdValue);
      });
  }

  function jsonLdRuleForSeoType(seoType) {
    var normalized = normalizeSeoType(seoType);
    var key = PAGE_JSONLD_RULE_ALIASES[normalized] || normalized;
    return PAGE_JSONLD_RULES[key] || PAGE_JSONLD_RULES.article;
  }

  function formatSchemaField(path) {
    return String(path || "").split("|")[0];
  }

  function validateFaqJsonLd(node) {
    var questions = Array.isArray(node && node.mainEntity) ? node.mainEntity : [];
    if (!questions.length) {
      return {
        level: "fail",
        label: "FAQPage mainEntity",
        detail: "FAQPage must include Question[] in mainEntity.",
        group: "schema"
      };
    }
    var invalid = questions.filter(function (question) {
      var questionTypes = jsonLdTypeList(question);
      return !questionTypes.some(function (type) { return schemaTypeMatches(type, "Question"); }) ||
        !hasJsonLdPath(question, "name") ||
        !hasJsonLdPath(question, "acceptedAnswer.text");
    }).length;
    if (invalid) {
      return {
        level: "fail",
        label: "FAQPage answers",
        detail: invalid + " FAQ item(s) missing Question.name or acceptedAnswer.text.",
        group: "schema"
      };
    }
    return {
      level: "pass",
      label: "FAQPage answers",
      detail: questions.length + " FAQ question/answer item(s) are valid.",
      group: "schema"
    };
  }

  function validatePageJsonLd(context) {
    var nodes = collectJsonLdNodes();
    var types = context.jsonTypes || extractJsonLdTypes();
    var rule = jsonLdRuleForSeoType(context.seoType);
    var checks = [];
    var missingTypes = [];
    var missingFields = [];
    var missingRecommended = [];

    if (types.indexOf("INVALID_JSON") !== -1) {
      return {
        status: "fail",
        pageType: context.seoType,
        expectedType: rule.primaryType,
        label: rule.label,
        presentTypes: types,
        missingTypes: rule.requiredTypes || [],
        missingFields: [],
        missingRecommended: [],
        checks: [
          {
            level: "fail",
            label: "JSON-LD page-type contract",
            detail: "Cannot validate " + rule.label + " schema because JSON-LD contains invalid JSON.",
            group: "schema"
          }
        ]
      };
    }

    (rule.requiredTypes || []).forEach(function (type) {
      if (jsonLdTypesInclude(types, type)) {
        checks.push({
          level: "pass",
          label: "JSON-LD @" + type,
          detail: rule.label + " schema type present.",
          group: "schema"
        });
      } else {
        missingTypes.push(type);
        checks.push({
          level: "fail",
          label: "JSON-LD @" + type,
          detail: "Missing " + type + " for " + rule.label + ". Current: " + (types.join(", ") || "none") + ".",
          group: "schema"
        });
      }
    });

    var primary = jsonLdNodesOfType(nodes, rule.primaryType)[0] || null;
    if (primary) {
      (rule.requiredFields || []).forEach(function (path) {
        if (hasJsonLdPath(primary, path)) {
          checks.push({
            level: "pass",
            label: "JSON-LD field " + formatSchemaField(path),
            detail: rule.primaryType + "." + formatSchemaField(path) + " present.",
            group: "schema"
          });
        } else {
          missingFields.push(formatSchemaField(path));
          checks.push({
            level: "fail",
            label: "JSON-LD field " + formatSchemaField(path),
            detail: rule.primaryType + " missing " + formatSchemaField(path) + " for " + rule.label + ".",
            group: "schema"
          });
        }
      });

      (rule.recommendedFields || []).forEach(function (path) {
        if (hasJsonLdPath(primary, path)) {
          checks.push({
            level: "pass",
            label: "JSON-LD recommended " + formatSchemaField(path),
            detail: rule.primaryType + "." + formatSchemaField(path) + " present.",
            group: "schema"
          });
        } else {
          missingRecommended.push(formatSchemaField(path));
          checks.push({
            level: "info",
            label: "JSON-LD recommended " + formatSchemaField(path),
            detail: rule.primaryType + " should include " + formatSchemaField(path) + " when available.",
            group: "schema"
          });
        }
      });

      if (rule.custom === "faq") checks.push(validateFaqJsonLd(primary));
    } else if (rule.primaryType) {
      checks.push({
        level: "fail",
        label: "JSON-LD primary type",
        detail: "Expected primary " + rule.primaryType + " for " + rule.label + ".",
        group: "schema"
      });
    }

    var hasFailChecks = checks.some(function (check) { return check.level === "fail"; });
    var hasWarnChecks = checks.some(function (check) { return check.level === "warn"; });

    return {
      status: hasFailChecks || missingTypes.length || missingFields.length ? "fail" : hasWarnChecks ? "warn" : "pass",
      pageType: context.seoType,
      expectedType: rule.primaryType,
      label: rule.label,
      presentTypes: types,
      missingTypes: missingTypes,
      missingFields: missingFields,
      missingRecommended: missingRecommended,
      checks: checks
    };
  }

  function stripIgnoredSeoAuditNodes(root) {
    if (!root || !root.querySelectorAll) return root;
    root.querySelectorAll("script, style, noscript, " + SEO_AUDIT_IGNORE_SELECTOR).forEach(function (node) {
      node.remove();
    });
    return root;
  }

  function isIgnoredSeoAuditNode(node) {
    return Boolean(node && node.closest && node.closest(SEO_AUDIT_IGNORE_SELECTOR));
  }

  function pageTextForScan() {
    var source = document.body || document.documentElement;
    var clone = source ? source.cloneNode(true) : null;
    if (!clone) return "";
    stripIgnoredSeoAuditNodes(clone);
    return (clone.textContent || "").replace(/\s+/g, " ").trim();
  }

  function visibleTextLength(root) {
    var clone = root.cloneNode(true);
    stripIgnoredSeoAuditNodes(clone);
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

  function shouldAuditCta(context) {
    var contentCategory = metaContent('meta[name="content-category"]');
    var pageContent = "";
    try {
      pageContent = pageHtmlForScan();
    } catch (_error) {
      pageContent = pageTextForScan();
    }
    var haystack = [
      context && context.seoType,
      contentCategory,
      context && context.title,
      context && context.description,
      pageContent
    ].join(" ").toLowerCase();
    if (["home", "product", "landing", "service", "pricing", "contact"].indexOf(normalizeSeoType(context && context.seoType)) !== -1) {
      return true;
    }
    return /\bcta\b|call to action|get started|sign up|subscribe|contact us|learn more|try now|buy now|claim|book|download|立即|马上|开始|注册|登录|购买|咨询|联系|预约|提交|下载|试用|开通|查看|打开|进入/i.test(haystack);
  }

  var CTA_SELECTOR = [
    "[data-cta]",
    "[data-cta-action]",
    "[data-cta-event]",
    "[data-pixel-event]",
    "[data-visitor-event]",
    "[data-track]",
    "a[download]",
    "button[type=\"submit\"]",
    "a[role=\"button\"]",
    ".cta",
    ".wf-btn",
    ".btn-primary",
    ".button--primary",
    ".promo-hero__cta"
  ].join(",");

  function collectCtaElements(selector) {
    return Array.from(document.querySelectorAll(selector || CTA_SELECTOR)).filter(function (el) {
      if (!el || isIgnoredSeoAuditNode(el)) return false;
      var text = (el.textContent || el.getAttribute("aria-label") || el.getAttribute("title") || "").replace(/\s+/g, " ").trim();
      return Boolean(
        text ||
          el.href ||
          el.getAttribute("data-cta") ||
          el.getAttribute("data-cta-action") ||
          el.getAttribute("data-cta-event") ||
          el.getAttribute("data-pixel-event") ||
          el.getAttribute("data-visitor-event")
      );
    });
  }

  function formatCheckLevel(level) {
    if (level === "info") return "tip";
    if (level === "unknown") return "unknown";
    return level;
  }

  function normalizeHeadingText(node) {
    return (node.textContent || "").replace(/\s+/g, " ").trim();
  }

  function collectHeadingOutline() {
    var nodes = Array.from(document.querySelectorAll("h1, h2, h3, h4, h5, h6")).filter(function (node) {
      return !isIgnoredSeoAuditNode(node);
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

  function allEngineIds() {
    return ENGINE_PROFILES.map(function (engine) { return engine.id; });
  }

  function findCheck(raw, label) {
    return (raw.checks || []).find(function (item) { return item.label === label; }) || null;
  }

  function hasCheckLevel(raw, label, level) {
    var check = findCheck(raw, label);
    return Boolean(check && check.level === level);
  }

  function hasAnyCheckLevel(raw, labels, level) {
    return labels.some(function (label) { return hasCheckLevel(raw, label, level); });
  }

  function performanceEntries(type) {
    if (!window.performance || typeof window.performance.getEntriesByType !== "function") return [];
    try {
      return window.performance.getEntriesByType(type) || [];
    } catch (_error) {
      return [];
    }
  }

  function usableNumber(value) {
    return typeof value === "number" && isFinite(value) && value >= 0 ? value : null;
  }

  function sumNumbers(items, getter) {
    return items.reduce(function (sum, item) {
      var value = usableNumber(getter(item));
      return sum + (value === null ? 0 : value);
    }, 0);
  }

  function collectPerformanceSignals() {
    var perf = window.performance || null;
    if (!perf) {
      return {
        available: false,
        detail: "window.performance 不可用。"
      };
    }

    var nav = performanceEntries("navigation")[0] || null;
    var timing = perf.timing || null;
    var paints = performanceEntries("paint");
    var resources = performanceEntries("resource");
    var lcpEntries = performanceEntries("largest-contentful-paint");
    var clsEntries = performanceEntries("layout-shift");
    var longTasks = performanceEntries("longtask");
    var fcpEntry = paints.find(function (entry) { return entry.name === "first-contentful-paint"; }) || null;
    var lcpEntry = lcpEntries.length ? lcpEntries[lcpEntries.length - 1] : null;

    function fromNav(name) {
      if (!nav) return null;
      return usableNumber(nav[name]);
    }

    function timingDelta(end, start) {
      if (!timing || !timing.navigationStart || !timing[end]) return null;
      var base = timing[start] || timing.navigationStart;
      return usableNumber(timing[end] - base);
    }

    var ttfb = null;
    var dcl = null;
    var load = null;
    if (nav) {
      var responseStart = fromNav("responseStart");
      var requestStart = fromNav("requestStart");
      var startTime = fromNav("startTime") || 0;
      ttfb = responseStart === null ? null : responseStart - (requestStart === null ? startTime : requestStart);
      dcl = fromNav("domContentLoadedEventEnd");
      load = fromNav("loadEventEnd");
    } else if (timing) {
      ttfb = timingDelta("responseStart", "requestStart");
      dcl = timingDelta("domContentLoadedEventEnd", "navigationStart");
      load = timingDelta("loadEventEnd", "navigationStart");
    }

    var fcp = fcpEntry ? usableNumber(fcpEntry.startTime) : null;
    var lcp = lcpEntry ? usableNumber(lcpEntry.renderTime || lcpEntry.loadTime || lcpEntry.startTime) : null;
    var cls = clsEntries.length
      ? clsEntries.reduce(function (sum, entry) {
          return sum + (entry.hadRecentInput ? 0 : (usableNumber(entry.value) || 0));
        }, 0)
      : null;
    var transferBytes = sumNumbers(resources, function (entry) {
      return entry.transferSize || entry.encodedBodySize || 0;
    });
    var scriptCount = resources.filter(function (entry) {
      return entry.initiatorType === "script";
    }).length;
    var stylesheetCount = resources.filter(function (entry) {
      return entry.initiatorType === "link" || entry.initiatorType === "css";
    }).length;
    var imageCount = resources.filter(function (entry) {
      return entry.initiatorType === "img" || entry.initiatorType === "image";
    }).length;
    var longTaskTotal = longTasks.length
      ? sumNumbers(longTasks, function (entry) { return entry.duration; })
      : null;

    return {
      available: Boolean(nav || timing || paints.length || resources.length),
      hasNavigationTiming: Boolean(nav || timing),
      hasPaintTiming: Boolean(paints.length),
      hasLcp: lcp !== null,
      hasCls: cls !== null,
      hasLongTasks: longTaskTotal !== null,
      ttfb: usableNumber(ttfb),
      domContentLoaded: usableNumber(dcl),
      load: usableNumber(load),
      fcp: fcp,
      lcp: lcp,
      cls: cls,
      longTaskTotal: longTaskTotal,
      resourceCount: resources.length,
      scriptCount: scriptCount,
      stylesheetCount: stylesheetCount,
      imageCount: imageCount,
      transferKb: transferBytes ? Math.round(transferBytes / 1024) : null
    };
  }

  function formatMs(value) {
    return value === null || value === undefined ? "未捕获" : Math.round(value) + "ms";
  }

  function formatDecimal(value, digits) {
    return value === null || value === undefined ? "未捕获" : Number(value).toFixed(digits || 2);
  }

  function performanceSummary(perf) {
    if (!perf || !perf.available) return "浏览器 Performance Timing 不可用。";
    var parts = [
      "TTFB " + formatMs(perf.ttfb),
      "FCP " + formatMs(perf.fcp),
      "LCP " + formatMs(perf.lcp),
      "CLS " + formatDecimal(perf.cls, 3),
      "Load " + formatMs(perf.load),
      "资源 " + perf.resourceCount + " 个"
    ];
    if (perf.transferKb !== null) parts.push("传输 " + perf.transferKb + "KB");
    if (perf.longTaskTotal !== null) parts.push("长任务 " + formatMs(perf.longTaskTotal));
    return parts.join("；");
  }

  function addMetricIssue(list, metric, value, warnAt, failAt, unit) {
    if (value === null || value === undefined) return;
    var display = unit === "score" ? formatDecimal(value, 3) : formatMs(value);
    if (value > failAt) list.fail.push(metric + " " + display);
    else if (value > warnAt) list.warn.push(metric + " " + display);
  }

  function buildPerformanceRow(signals) {
    var perf = signals.performance || null;
    if (!perf || !perf.available) {
      return engineRow(
        "performance",
        "pass",
        "info",
        "当前页面未暴露完整 Performance Timing，但浏览器渲染层未发现可判定的阻断性能风险。",
        "生产发布前可补跑 Lighthouse/PageSpeed Insights/CrUX；外部真实用户数据不计入当前页面 SEO 阻断。"
      );
    }

    var issues = { fail: [], warn: [] };
    addMetricIssue(issues, "TTFB", perf.ttfb, 800, 1800);
    addMetricIssue(issues, "FCP", perf.fcp, 1800, 3000);
    addMetricIssue(issues, "LCP", perf.lcp, 2500, 4000);
    addMetricIssue(issues, "Load", perf.load, 3000, 6000);
    addMetricIssue(issues, "CLS", perf.cls, 0.1, 0.25, "score");
    addMetricIssue(issues, "长任务", perf.longTaskTotal, 200, 600);
    if (perf.resourceCount > 120) issues.fail.push("资源数 " + perf.resourceCount + " 个");
    else if (perf.resourceCount > 80) issues.warn.push("资源数 " + perf.resourceCount + " 个");
    if (perf.transferKb !== null) {
      if (perf.transferKb > 3000) issues.fail.push("传输 " + perf.transferKb + "KB");
      else if (perf.transferKb > 1500) issues.warn.push("传输 " + perf.transferKb + "KB");
    }

    var missingCwv = [];
    if (!perf.hasLcp) missingCwv.push("LCP");
    if (!perf.hasCls) missingCwv.push("CLS");
    missingCwv.push("INP");
    var summary = performanceSummary(perf);

    if (issues.fail.length) {
      return engineRow(
        "performance",
        "fail",
        "high",
        "浏览器本地性能估算存在严重风险：" + issues.fail.join("；") + "。采样：" + summary + "。",
        "优先压缩关键 JS/CSS/图片、降低阻塞资源和长任务；再用 Lighthouse/PageSpeed/CrUX 校验移动端与桌面端。",
        "PERF_001_BROWSER_TIMING_FAIL"
      );
    }

    if (issues.warn.length) {
      return engineRow(
        "performance",
        "warn",
        "medium",
        "浏览器本地性能估算存在优化项：" + issues.warn.join("；") + "。采样：" + summary + "。",
        "用 Lighthouse/PageSpeed/CrUX 补齐真实 CWV；面板内如需更准，应在首屏脚本最早阶段采集 LCP/CLS/INP。",
        "PERF_010_CWV_EXTERNAL_REQUIRED"
      );
    }

    if (missingCwv.length) {
      return engineRow(
        "performance",
        "pass",
        "info",
        "本地 timing 未发现明显慢指标。未捕获的 " + missingCwv.join("/") + " 属于外部或早期 PerformanceObserver 采样缺口，不作为当前页面 SEO 阻断。采样：" + summary + "。",
        "上线前仍建议用 Lighthouse/PageSpeed/CrUX 补齐真实 CWV。"
      );
    }

    return engineRow(
      "performance",
      "pass",
      "info",
      "浏览器本地 timing 未发现明显性能风险。采样：" + summary + "。",
      "上线前仍需用真实网络和 CrUX/PageSpeed 校验移动端 CWV。"
    );
  }

  var CONTENT_SPAM_LABELS = {
    "prompt leak": "内部提示词泄露",
    "public copy leak": "内部文案泄露",
    "visible text": "正文厚度",
    "title/H1 alignment": "Title/H1 一致性",
    "keyword stuffing": "关键词堆砌",
    "internal links": "内链密度",
    "content images": "内容图片",
    "image alt": "图片 ALT",
    "title length": "Title 长度",
    "description length": "Description 长度",
    "keyword relevance": "关键词相关性",
    "empty links": "空链接"
  };

  var PLATFORM_CONTENT_SPAM_LABELS = {
    "prompt leak": true,
    "public copy leak": true,
    "visible text": true,
    "keyword stuffing": true,
    "keyword relevance": true,
    "empty links": true
  };

  function collectContentSpamChecks(raw) {
    var labels = Object.keys(CONTENT_SPAM_LABELS);
    return (raw.checks || [])
      .filter(function (check) {
        return labels.indexOf(check.label) !== -1 && (check.level === "fail" || check.level === "warn");
      })
      .sort(function (a, b) {
        var weight = { fail: 0, warn: 1 };
        return (weight[a.level] || 9) - (weight[b.level] || 9);
      });
  }

  function compactCheckEvidence(checks, limit) {
    return checks.slice(0, limit || 5).map(function (check) {
      var label = CONTENT_SPAM_LABELS[check.label] || check.label;
      return label + "：" + (check.detail || check.level);
    }).join("；");
  }

  function contentSpamRecommendation(checks) {
    var labels = checks.map(function (check) { return check.label; });
    var hints = [];
    if (labels.indexOf("visible text") !== -1 || labels.indexOf("content images") !== -1) {
      hints.push("补充独特正文、真实示例、FAQ 和可解释的内容图片");
    }
    if (labels.indexOf("title/H1 alignment") !== -1 || labels.indexOf("title length") !== -1 || labels.indexOf("description length") !== -1) {
      hints.push("重写 title/H1/description，让主题、搜索意图和页面正文一致");
    }
    if (labels.indexOf("keyword stuffing") !== -1 || labels.indexOf("keyword relevance") !== -1) {
      hints.push("减少堆砌词，改为自然语义覆盖和可见事实");
    }
    if (labels.indexOf("internal links") !== -1 || labels.indexOf("empty links") !== -1) {
      hints.push("补足描述性内链并清理空 href");
    }
    if (labels.indexOf("image alt") !== -1) {
      hints.push("为内容图片补充面向用户的 ALT");
    }
    if (!hints.length) hints.push("人工复核原创性、专业性、搜索意图满足度和平台 spam policy");
    return hints.join("；") + "。";
  }

  function buildContentSpamRow(raw) {
    var checks = collectContentSpamChecks(raw);
    var leakChecks = checks.filter(function (check) {
      return check.level === "fail" && (check.label === "prompt leak" || check.label === "public copy leak");
    });

    if (leakChecks.length) {
      return engineRow(
        "content_spam",
        "fail",
        "critical",
        "命中高风险内容泄露：" + compactCheckEvidence(leakChecks, 3) + "。",
        "移除内部提示词、规划词和生成器词汇，改成面向用户的自然文案。",
        "SPAM_003_PUBLIC_COPY_LEAK"
      );
    }

    var platformChecks = checks.filter(function (check) {
      return check.level === "fail" || PLATFORM_CONTENT_SPAM_LABELS[check.label];
    });

    if (platformChecks.length) {
      return engineRow(
        "content_spam",
        "warn",
        "medium",
        "命中平台级内容/Spam 风险项：" + compactCheckEvidence(platformChecks, 6) + "。",
        contentSpamRecommendation(platformChecks),
        "CONTENT_002_THIN_CONTENT"
      );
    }

    if (checks.length) {
      return engineRow(
        "content_spam",
        "info",
        "info",
        "SEO 校验仍有普通优化项：" + compactCheckEvidence(checks, 5) + "；未达到搜索平台 Spam 风险。",
        "在 SEO 校验 tab 修复这些展示质量项；平台矩阵只把薄内容、堆砌、空链和泄露类问题标为风险。"
      );
    }

    return engineRow(
      "content_spam",
      "pass",
      "info",
      "浏览器可见内容未发现明显内部泄露、关键词堆砌、薄内容或空链等平台级 Spam 风险。",
      "人工复核内容原创性、专业性、搜索意图满足度和平台 spam policy。"
    );
  }

  function normalizeIssueKey(value) {
    return String(value || "")
      .toUpperCase()
      .replace(/[^A-Z0-9]+/g, "_")
      .replace(/^_+|_+$/g, "");
  }

  function issueSeverity(check) {
    if (check.level === "fail") return CRITICAL_CHECK_LABELS[check.label] ? "critical" : "high";
    if (check.level === "warn") return "medium";
    if (check.level === "info") return "info";
    return "low";
  }

  function issueCategory(group) {
    return {
      technical: "indexability",
      issues: "site_audit",
      head: "head_meta",
      url: "international",
      content: "content",
      schema: "structured_data",
      social: "media",
      structure: "headings_ia",
      compliance: "security"
    }[group || "technical"] || "engine_specific";
  }

  function engineIssueCategory(rowId) {
    return {
      crawlability: "crawlability",
      indexability: "indexability",
      canonical: "indexability",
      sitemap: "crawlability",
      structured_data: "structured_data",
      mobile: "mobile",
      performance: "performance",
      content_spam: "content",
      engine_specific: "engine_specific"
    }[rowId || ""] || "engine_specific";
  }

  function issueFromCheck(check, engines) {
    return {
      id: normalizeIssueKey((check.group || "seo") + "_" + check.label),
      category: issueCategory(check.group),
      title: check.label,
      severity: issueSeverity(check),
      engines: engines || allEngineIds(),
      affectedUrls: [window.location.href],
      evidence: [
        {
          url: window.location.href,
          value: check.detail || check.label,
          source: "rendered_dom"
        }
      ],
      recommendation: actionFixHint(check),
      confidence: check.level === "info" ? "medium" : "high",
      blocking: check.level === "fail"
    };
  }

  function engineRow(id, status, severity, detail, recommendation, issueId) {
    return {
      id: id,
      label: (ENGINE_MATRIX_ROWS.find(function (row) { return row.id === id; }) || {}).label || id,
      status: status,
      severity: severity || (status === "fail" ? "high" : status === "warn" ? "medium" : "info"),
      detail: detail,
      recommendation: recommendation || "",
      issueId: issueId || ""
    };
  }

  function externalValidationRow(detail, recommendation) {
    return engineRow("engine_specific", "pass", "info", detail, recommendation || "使用服务端爬虫、平台站长工具或真实用户数据补充验证。");
  }

  function collectEngineSignals(raw) {
    var canonical = raw.snapshot.canonical || "";
    var sitemapNode = document.querySelector('link[rel="sitemap"][href]');
    var sitemapHref = sitemapNode ? (sitemapNode.getAttribute("href") || "") : "";
    var robotsContent = metaContent('meta[name="robots"]');
    var jsonTypes = raw.snapshot.jsonTypes || [];
    var jsonNodes = collectJsonLdNodes();
    var organization = jsonLdNodesOfType(jsonNodes, "Organization")[0] || jsonLdNodesOfType(jsonNodes, "LocalBusiness")[0] || null;
    var website = jsonLdNodesOfType(jsonNodes, "WebSite")[0] || null;
    var breadcrumb = jsonLdNodesOfType(jsonNodes, "BreadcrumbList")[0] || null;
    var links = Array.from(document.querySelectorAll("a[href]")).filter(function (node) {
      return !isIgnoredSeoAuditNode(node);
    });
    var privacyLink = links.find(function (node) {
      var text = (node.textContent || "").toLowerCase();
      var href = (node.getAttribute("href") || "").toLowerCase();
      return text.indexOf("privacy") !== -1 ||
        text.indexOf("隐私") !== -1 ||
        href.indexOf("privacy") !== -1 ||
        href.indexOf("policy") !== -1;
    });
    var jsRedirect = Array.from(document.querySelectorAll("script")).some(function (node) {
      return /(?:window\.)?location\.(?:href|replace|assign)\s*=|location\.replace\s*\(/i.test(node.textContent || "");
    });

    return {
      canonical: canonical,
      canonicalAbsolute: /^https?:\/\//i.test(canonical),
      canonicalHttps: /^https:\/\//i.test(canonical),
      sitemapHref: sitemapHref,
      sitemapRootOrAbsolute: /^(https?:\/\/|\/)/i.test(sitemapHref),
      sitemapAbsolute: /^https?:\/\//i.test(sitemapHref),
      robotsContent: robotsContent,
      noindex: /noindex/i.test(robotsContent),
      nofollow: /nofollow/i.test(robotsContent),
      nosnippet: /nosnippet|max-snippet:0/i.test(robotsContent),
      viewport: Boolean(document.querySelector('meta[name="viewport"]')),
      jsonTypes: jsonTypes,
      jsonNodes: jsonNodes,
      jsonLdValidation: raw.snapshot.jsonLdValidation || validatePageJsonLd({
        seoType: raw.snapshot.seoType || "unknown",
        jsonTypes: jsonTypes
      }),
      organization: organization,
      website: website,
      breadcrumb: breadcrumb,
      hasOrganization: Boolean(organization),
      hasWebsite: Boolean(website),
      hasBreadcrumb: Boolean(breadcrumb),
      hasSearchAction: Boolean(website && website.potentialAction),
      hasSameAs: Boolean(organization && Array.isArray(organization.sameAs) && organization.sameAs.length),
      hasPrivacyLink: Boolean(privacyLink),
      hreflangCount: collectHreflangCodes().length,
      htmlLang: raw.snapshot.htmlLang || "",
      visibleText: raw.snapshot.visibleText || 0,
      contentImages: raw.snapshot.contentImages || 0,
      jsRedirect: jsRedirect,
      hashRouting: /^#\//.test(window.location.hash || ""),
      title: raw.snapshot.title || "",
      description: raw.snapshot.description || "",
      seoType: raw.snapshot.seoType || "unknown",
      performance: collectPerformanceSignals()
    };
  }

  function buildCommonEngineRows(raw, signals) {
    var rows = {};

    if (signals.noindex || signals.jsRedirect || signals.hashRouting) {
      rows.crawlability = engineRow(
        "crawlability",
        "warn",
        "medium",
        "当前页面存在浏览器可见的抓取风险：" + [
          signals.noindex ? "noindex" : "",
          signals.jsRedirect ? "JS redirect" : "",
          signals.hashRouting ? "hash route" : ""
        ].filter(Boolean).join("、") + "。",
        "修复浏览器可见阻断后，再使用服务端爬虫模式按目标 User-Agent 验证 robots.txt、HTTP 状态码、重定向链和资源抓取权限。",
        "CRAWL_001_BROWSER_VISIBLE_RISK"
      );
    } else {
      rows.crawlability = engineRow(
        "crawlability",
        "pass",
        "info",
        "当前页面已成功加载并渲染，DOM 未发现 noindex、JS redirect 或 hash route 等浏览器可见抓取阻断。",
        "服务端爬虫模式仍可补充验证 robots.txt、HTTP 状态码、重定向链和目标搜索引擎 User-Agent。"
      );
    }

    if (signals.noindex || hasCheckLevel(raw, "indexability", "fail")) {
      rows.indexability = engineRow(
        "indexability",
        "fail",
        "critical",
        "页面 robots meta 包含 noindex 或索引阻断项。",
        "如果页面应收录，移除 noindex 并确认 robots/header 没有目标引擎阻断。",
        "META_ROBOTS_001_NOINDEX"
      );
    } else {
      rows.indexability = engineRow(
        "indexability",
        "pass",
        "info",
        "页面 meta robots 允许索引；HTTP header 与 robots.txt 仍需服务端验证。",
        "服务端爬虫补查 X-Robots-Tag 和 robots.txt。"
      );
    }

    if (!signals.canonical || hasAnyCheckLevel(raw, ["single canonical", "canonical host", "canonical path", "canonical scheme"], "fail")) {
      rows.canonical = engineRow(
        "canonical",
        "fail",
        "high",
        "canonical 缺失、重复、非 HTTPS，或与当前规范 URL 不一致。",
        "保留唯一 HTTPS absolute canonical，并让 og:url、hreflang 与 canonical 对齐。",
        "CANONICAL_001_CANONICAL_INVALID"
      );
    } else {
      rows.canonical = engineRow(
        "canonical",
        "pass",
        "info",
        "canonical 为唯一 HTTPS URL，浏览器 DOM 层通过。",
        "服务端爬虫可继续验证 canonical 目标是否 200、可索引且非 redirect。"
      );
    }

    if (!signals.sitemapHref || hasCheckLevel(raw, "sitemap discovery", "fail")) {
      rows.sitemap = engineRow(
        "sitemap",
        "warn",
        "medium",
        "当前页面未发现 sitemap link。",
        "在 head 或 robots.txt 中提供 sitemap，并由服务端验证 sitemap XML 可访问、可解析、URL 为 canonical。",
        "SITEMAP_001_NOT_FOUND"
      );
    } else if (!signals.sitemapRootOrAbsolute) {
      rows.sitemap = engineRow(
        "sitemap",
        "warn",
        "medium",
        "sitemap link 不是绝对或根相对 URL。",
        "使用绝对 URL，至少使用 /sitemap.xml。",
        "SITEMAP_002_BAD_URL"
      );
    } else {
      rows.sitemap = engineRow(
        "sitemap",
        "pass",
        "info",
        "页面暴露 sitemap link：" + signals.sitemapHref + "。",
        "服务端爬虫继续检查 XML、lastmod、redirect/noindex URL 和平台大小限制。"
      );
    }

    if (signals.jsonTypes.indexOf("INVALID_JSON") !== -1 || hasCheckLevel(raw, "JSON-LD parse", "fail")) {
      rows.structured_data = engineRow(
        "structured_data",
        "fail",
        "high",
        "JSON-LD 解析失败。",
        "修复 JSON-LD 语法，并保持 schema 与可见内容一致。",
        "SD_001_JSON_PARSE_ERROR"
      );
    } else if (signals.jsonLdValidation && signals.jsonLdValidation.status === "fail") {
      rows.structured_data = engineRow(
        "structured_data",
        "fail",
        "high",
        "页面类型结构化数据不合格：" + [
          signals.jsonLdValidation.missingTypes.length ? "缺少类型 " + signals.jsonLdValidation.missingTypes.join(", ") : "",
          signals.jsonLdValidation.missingFields.length ? "缺少字段 " + signals.jsonLdValidation.missingFields.join(", ") : ""
        ].filter(Boolean).join("；") + "。",
        "按 " + signals.jsonLdValidation.label + " 补齐 " + signals.jsonLdValidation.expectedType + " JSON-LD 类型与必需字段，并保持与可见内容一致。",
        "SD_004_PAGE_TYPE_SCHEMA_INVALID"
      );
    } else if (!signals.jsonTypes.length) {
      rows.structured_data = engineRow(
        "structured_data",
        "warn",
        "medium",
        "页面未发现 JSON-LD。",
        "按页面类型补充 WebPage、BreadcrumbList、Product、Article、FAQPage 等 schema。",
        "SD_003_TYPE_MISSING"
      );
    } else if (signals.jsonLdValidation && signals.jsonLdValidation.status === "warn") {
      rows.structured_data = engineRow(
        "structured_data",
        "warn",
        "medium",
        "页面类型结构化数据基本可读，但推荐字段不足：" + signals.jsonLdValidation.missingRecommended.join(", ") + "。",
        "补齐推荐字段，尤其是 publisher、description、articleSection、offers 或 contactPoint 等能帮助搜索平台理解页面的属性。",
        "SD_005_PAGE_TYPE_SCHEMA_RECOMMENDED_MISSING"
      );
    } else {
      rows.structured_data = engineRow(
        "structured_data",
        "pass",
        "info",
        "已解析 schema 类型：" + signals.jsonTypes.join(", ") + "。",
        "继续按平台规则检查 required/recommended 属性和可见内容一致性。"
      );
    }

    if (!signals.viewport || hasCheckLevel(raw, "viewport", "fail")) {
      rows.mobile = engineRow(
        "mobile",
        "fail",
        "high",
        "缺少移动端 viewport。",
        "添加 <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"> 并做移动端溢出检查。",
        "MOBILE_001_VIEWPORT_MISSING"
      );
    } else {
      rows.mobile = engineRow(
        "mobile",
        "pass",
        "info",
        "viewport 存在；浏览器模式未比较移动/桌面 HTML 差异。",
        "服务端或浏览器自动化补查移动端宽度、tap target、移动/桌面内容一致性。"
      );
    }

    rows.performance = buildPerformanceRow(signals);
    rows.content_spam = buildContentSpamRow(raw);

    return rows;
  }

  function buildEngineSpecificRow(engine, rows, raw, signals) {
    if (engine.id === "google") {
      if (rows.indexability.status === "fail" || rows.structured_data.status === "fail" || rows.content_spam.status === "fail") {
        return engineRow("engine_specific", "fail", "critical", "Google 基础索引、结构化数据或 spam 风险未通过。", "先修复 noindex/canonical/schema/spam，再考虑 AI Search 与 CWV。", "GOOGLE_010_SPAM_POLICY_RISK");
      }
      if (rows.performance.status === "fail") {
        return engineRow("engine_specific", "warn", "medium", "Google 基础 DOM 信号可读，但性能/CWV 本地估算存在高风险。", "先修复性能/CWV 风险，再补跑 Lighthouse、PageSpeed、CrUX 和 Search Console。", "GOOGLE_020_CWV_RISK");
      }
      return externalValidationRow("Google 基础 DOM 信号可读；Search Console 覆盖、真实 Googlebot 渲染、CWV/INP 和 AI Search 可见性仍需外部验证。", "补跑 Lighthouse/PageSpeed/CrUX/Search Console，避免把 meta keywords 或 llms.txt 当 Google 排名保证。");
    }

    if (engine.id === "bing") {
      if (rows.indexability.status === "fail" || rows.structured_data.status === "fail") {
        return engineRow("engine_specific", "fail", "high", "Bing 基础索引或结构化数据存在阻断。", "修复基础 SEO 后再接入 Bing Webmaster Tools 与 IndexNow。", "BING_003_STRUCTURED_DATA_MISMATCH");
      }
      return externalValidationRow("Bing 基础 DOM 信号可读；IndexNow key 文件、提交记录和 bingbot 抓取未在浏览器内验证。", "配置 Bing Webmaster Tools/IndexNow，并用服务端检查 /{key}.txt、提交 payload 与 bingbot robots。");
    }

    if (engine.id === "yahoo") {
      if (hasAnyCheckLevel(raw, ["title length", "title/H1 alignment", "description length", "image alt"], "fail")) {
        return engineRow("engine_specific", "warn", "medium", "Yahoo 重视准确 title、description、HTML 文本和图片 ALT，当前存在相关失败项。", "让 title/description/H1/正文一致，关键文字不要只放图片里。", "YAHOO_001_TITLE_NOT_ACCURATE");
      }
      return engineRow("engine_specific", "pass", "info", "Yahoo 基础内容信号通过；自然结果仍需关注 Bing 适配。", "保持 Bing profile 通过，并确保 sitemap 可提交。");
    }

    if (engine.id === "yandex") {
      if (!signals.hasBreadcrumb) {
        return engineRow("engine_specific", "warn", "medium", "Yandex 支持 BreadcrumbList，当前页面未发现 BreadcrumbList。", "为可索引页面补充 BreadcrumbList，并确认 sitemap 使用 canonical URL。", "YANDEX_004_BREADCRUMB_JSONLD_INVALID");
      }
      return externalValidationRow("BreadcrumbList 已存在；YandexBot 抓取、description 全站唯一性和区域语言匹配仍未验证。", "服务端检查 YandexBot robots、sitemap canonical URL、description 去重和站长后台区域语言。");
    }

    if (engine.id === "baidu") {
      if (rows.mobile.status === "fail" || rows.content_spam.status === "fail") {
        return engineRow("engine_specific", "fail", "high", "Baidu 对移动体验、空短页、标题正文不符和低质采集风险敏感，当前存在相关阻断。", "优先修复移动端与内容质量，再检查 Baiduspider 可抓取和 URL 推送。", "BAIDU_002_MOBILE_UNFRIENDLY");
      }
      if (signals.visibleText < SEO_TEXT_LIMITS.visibleTextMin) {
        return engineRow("engine_specific", "warn", "medium", "页面正文偏薄，中文搜索生态可能需要更明确的原创说明与落地页价值。", "补充真实功能、示例、FAQ、使用场景和可见文本。", "BAIDU_007_EMPTY_SHORT_PAGE");
      }
      return externalValidationRow("Baidu DOM 基础项通过；Baiduspider、移动友好真实渲染、原创/低质判定和提交接口状态需要外部验证。", "用服务端按 Baiduspider 抓取，检查百度搜索资源平台提交状态、移动适配和页面质量反馈。");
    }

    if (engine.id === "duckduckgo") {
      if (rows.indexability.status === "fail") {
        return engineRow("engine_specific", "fail", "high", "DuckDuckGo 仍依赖可抓取/可索引基础页面，当前存在索引阻断。", "先修复 indexability，再验证 DuckDuckBot 与 Bing 结果依赖。", "DDG_001_BLOCKED_DUCKDUCKBOT");
      }
      if (!signals.hasOrganization) {
        return engineRow(
          "engine_specific",
          "warn",
          "medium",
          "品牌/实体信息较弱，隐私搜索生态可能难以理解站点实体。",
          "补充 Organization/WebSite/sameAs，保持 Bing profile 通过，并服务端验证 DuckDuckBot 抓取。",
          "DDG_003_ENTITY_SOURCE_WEAK"
        );
      }
      return externalValidationRow(
        "实体信息存在；DuckDuckBot 与 Bing 依赖仍需服务端验证。",
        "保持 Bing profile 通过，并服务端验证 DuckDuckBot 抓取。"
      );
    }

    if (engine.id === "naver") {
      if (!signals.canonicalAbsolute || signals.jsRedirect) {
        return engineRow("engine_specific", "fail", "high", "Naver 要求 canonical 使用 absolute URL，并不建议只靠 JS redirect。", "使用 absolute canonical 和 HTTP redirect；独立移动 URL 需一一映射。", "NAVER_002_CANONICAL_NOT_ABSOLUTE");
      }
      return externalValidationRow("Naver canonical/title/schema 基础信号可读；Yeti robots、移动/桌面映射和 Naver Search Advisor 状态需要服务端验证。", "服务端按 Yeti 抓取，并在 Search Advisor 检查收集/索引状态。");
    }

    if (engine.id === "seznam") {
      if (signals.sitemapHref && !signals.sitemapAbsolute) {
        return engineRow("engine_specific", "warn", "medium", "Seznam 对 sitemap 更偏好绝对 URL，当前页面 sitemap link 为根相对或非绝对。", "在 robots.txt 中声明绝对 sitemap URL，并检查 SeznamBot 规则。", "SEZNAM_002_SITEMAP_NOT_ABSOLUTE");
      }
      return externalValidationRow("Seznam 基础 DOM 信号可读；SeznamBot robots、X-Robots-Tag 差异和 canonical 相似性需服务端验证。", "服务端检查 SeznamBot、robots、canonical target 非 redirect 且内容相似。");
    }

    if (engine.id === "sogou") {
      if (rows.content_spam.status === "fail") {
        return engineRow("engine_specific", "fail", "high", "Sogou 对作弊/低质 URL 风险敏感，当前页面存在内容泄露或低质阻断。", "清理低质/作弊信号，sitemap 只提交重要原创详情页。", "SOGOU_006_CHEATING_OR_LOW_QUALITY");
      }
      return externalValidationRow("Sogou 基础 DOM 信号可读；robots 生效延迟、邀请制 sitemap、10MB/50k 限制需服务端或站长平台验证。", "检查 Sogou spider robots、sitemap 文件大小、提交 URL 质量和站长平台收录反馈。");
    }

    if (engine.id === "ecosia_qwant") {
      if (rows.indexability.status === "fail" || rows.structured_data.status === "fail") {
        return engineRow("engine_specific", "fail", "high", "Ecosia/Qwant/EUSP 依赖 Bing/Google 基础适配，当前基础项失败。", "先修复 Google/Bing 基础 SEO，再补多语言和实体可信度。", "EQ_001_BING_GOOGLE_BASELINE_FAIL");
      }
      if (!signals.hasPrivacyLink) {
        return engineRow("engine_specific", "warn", "medium", "未发现隐私政策或可信联系入口，欧洲隐私搜索生态的信任信号偏弱。", "添加可访问的隐私政策、联系信息和组织实体 sameAs。", "EQ_004_PRIVACY_PAGE_MISSING");
      }
      return externalValidationRow("Bing/Google 基础项可读；EUSP 独立索引策略、区域展示和隐私搜索结果仍需外部验证。", "保持多语言 hreflang、组织实体和隐私页面清晰，并检查 Bing/Google 来源收录。");
    }

    return engineRow("engine_specific", "unknown", "info", "该平台的浏览器内专项规则尚无足够信号。", "使用服务端爬虫补充。");
  }

  function engineStatus(rows) {
    var list = Object.keys(rows).map(function (key) { return rows[key]; });
    if (list.some(function (row) { return row.status === "fail"; })) return "fail";
    if (list.some(function (row) { return row.status === "warn"; })) return "warning";
    if (list.some(function (row) { return row.status === "unknown"; })) return "unknown";
    return "pass";
  }

  function engineScore(rows) {
    var score = 100;
    Object.keys(rows).forEach(function (key) {
      var row = rows[key];
      if (row.status === "fail") score -= row.severity === "critical" ? 36 : 28;
      else if (row.status === "warn") score -= row.severity === "high" ? 18 : 12;
      else if (row.status === "unknown") score -= 4;
    });
    return Math.max(0, Math.min(100, score));
  }

  function engineRecommendations(rows) {
    return Object.keys(rows)
      .map(function (key) { return rows[key]; })
      .filter(function (row) { return row.status !== "pass" && row.recommendation; })
      .map(function (row) { return row.recommendation; })
      .filter(function (value, index, list) { return list.indexOf(value) === index; })
      .slice(0, 5);
  }

  function buildEngineMatrix(raw) {
    var signals = collectEngineSignals(raw);
    var matrix = {};
    ENGINE_PROFILES.forEach(function (engine) {
      var rows = buildCommonEngineRows(raw, signals);
      rows.engine_specific = buildEngineSpecificRow(engine, rows, raw, signals);
      var score = engineScore(rows);
      var list = ENGINE_MATRIX_ROWS.map(function (row) {
        return rows[row.id] || engineRow(row.id, "unknown", "info", "未检测。", "补充检测规则。");
      });
      matrix[engine.id] = {
        id: engine.id,
        name: engine.name,
        label: engine.label,
        userAgents: engine.userAgents,
        focus: engine.focus,
        score: score,
        status: engineStatus(rows),
        rows: list,
        blockingIssues: list
          .filter(function (row) { return row.status === "fail"; })
          .map(function (row) { return row.issueId || row.label; }),
        recommendations: engineRecommendations(rows)
      };
    });
    return matrix;
  }

  function buildScoreBreakdown(raw, engineMatrix) {
    var checks = raw.checks || [];
    var fail = checks.filter(function (item) { return item.level === "fail"; });
    var warn = checks.filter(function (item) { return item.level === "warn"; });
    function scoreForGroups(groups, base) {
      var scoped = checks.filter(function (item) { return groups.indexOf(item.group) !== -1; });
      var deductions = [];
      scoped.forEach(function (item) {
        var points = item.level === "fail" ? 16 : item.level === "warn" ? 7 : 0;
        if (!points) return;
        deductions.push({
          points: points,
          level: item.level,
          group: item.group || "general",
          label: item.label || item.name || item.group || "SEO check",
          detail: item.detail || ""
        });
      });
      var score = deductions.reduce(function (value, item) {
        return value - item.points;
      }, base);
      return {
        base: base,
        deductions: deductions,
        score: Math.max(0, Math.min(100, score))
      };
    }

    function engineFitDetails() {
      return Object.keys(engineMatrix)
        .map(function (key) { return engineMatrix[key]; })
        .filter(function (engine) { return engine && typeof engine.score === "number" && engine.score < 100; })
        .sort(function (a, b) { return a.score - b.score; })
        .slice(0, 5)
        .map(function (engine) {
          return {
            points: 100 - engine.score,
            level: engine.status === "fail" ? "fail" : "warn",
            group: "engine",
            label: engine.name || engine.id || "Search engine",
            detail: (engine.recommendations || [])[0] || "搜索平台适配项未满分。"
          };
        });
    }

    var engineScores = Object.keys(engineMatrix).map(function (key) { return engineMatrix[key].score; });
    var engineFit = engineScores.length
      ? Math.round(engineScores.reduce(function (sum, score) { return sum + score; }, 0) / engineScores.length)
      : 0;
    var indexabilityDetail = scoreForGroups(["technical", "url", "head"], 100);
    var understandabilityDetail = scoreForGroups(["head", "content", "schema", "structure", "social"], 100);
    var experienceDetail = scoreForGroups(["content", "structure", "social", "issues"], 100);
    if (!document.querySelector('meta[name="viewport"]')) {
      var viewportPenalty = Math.max(0, experienceDetail.score - 55);
      if (viewportPenalty > 0) {
        experienceDetail.deductions.push({
          points: viewportPenalty,
          level: "fail",
          group: "experience",
          label: "viewport meta",
          detail: 'Missing <meta name="viewport">; mobile experience is capped at 55.'
        });
      }
      experienceDetail.score = Math.min(experienceDetail.score, 55);
    }
    var indexability = indexabilityDetail.score;
    var understandability = understandabilityDetail.score;
    var experience = experienceDetail.score;
    var total = Math.round(indexability * 0.35 + understandability * 0.3 + experience * 0.2 + engineFit * 0.15);
    return {
      indexability: Math.max(0, Math.min(100, indexability)),
      understandability: Math.max(0, Math.min(100, understandability)),
      experience: Math.max(0, Math.min(100, experience)),
      engineFit: engineFit,
      total: total,
      legacyOverall: Math.max(0, Math.min(100, 100 - fail.length * 8 - warn.length * 3)),
      details: {
        indexability: indexabilityDetail,
        understandability: understandabilityDetail,
        experience: experienceDetail,
        engineFit: {
          base: 100,
          deductions: engineFitDetails(),
          score: engineFit
        }
      }
    };
  }

  function buildAuditIssues(raw, engineMatrix) {
    var issues = (raw.checks || [])
      .filter(function (check) { return check.level === "fail" || check.level === "warn"; })
      .map(function (check) { return issueFromCheck(check, allEngineIds()); });
    var seen = {};
    Object.keys(engineMatrix).forEach(function (engineId) {
      engineMatrix[engineId].rows.forEach(function (row) {
        if (row.status !== "fail" && row.status !== "warn") return;
        var id = row.issueId || normalizeIssueKey(engineId + "_" + row.id);
        var key = id + "|" + engineId;
        if (seen[key]) return;
        seen[key] = true;
        issues.push({
          id: id,
          category: engineIssueCategory(row.id),
          title: row.label,
          severity: row.severity || (row.status === "fail" ? "high" : "medium"),
          engines: [engineId],
          affectedUrls: [window.location.href],
          evidence: [
            {
              url: window.location.href,
              value: row.detail,
              source: row.id === "crawlability" || row.id === "performance" ? "manual" : "rendered_dom"
            }
          ],
          recommendation: row.recommendation,
          confidence: row.status === "unknown" ? "low" : "medium",
          blocking: row.status === "fail"
        });
      });
    });
    return issues;
  }

  function buildEngineDiagnostics(raw) {
    var matrix = buildEngineMatrix(raw);
    var scores = buildScoreBreakdown(raw, matrix);
    var issues = buildAuditIssues(raw, matrix);
    return {
      reportVersion: "seo-audit-browser/v1",
      generatedAt: new Date().toISOString(),
      target: {
        startUrl: window.location.href,
        scope: "single_page",
        mode: "browser",
        engines: allEngineIds(),
        market: "global"
      },
      profiles: ENGINE_PROFILES,
      rows: ENGINE_MATRIX_ROWS,
      scores: scores,
      counts: {
        critical: issues.filter(function (item) { return item.severity === "critical"; }).length,
        high: issues.filter(function (item) { return item.severity === "high"; }).length,
        medium: issues.filter(function (item) { return item.severity === "medium"; }).length,
        low: issues.filter(function (item) { return item.severity === "low"; }).length,
        info: issues.filter(function (item) { return item.severity === "info"; }).length
      },
      issues: issues,
      engineMatrix: matrix,
      limitations: BROWSER_MODE_LIMITATIONS
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
    stripIgnoredSeoAuditNodes(clone);
    return clone.innerHTML || "";
  }

  function getMainH1Text() {
    var node = Array.from(document.querySelectorAll("h1")).find(function (item) {
      return !isIgnoredSeoAuditNode(item);
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

  function canonicalizeLanguageCode(code) {
    var value = String(code || "").trim();
    if (!value) return "";
    if (value.toLowerCase() === "x-default") return "x-default";
    return value.replace(/_/g, "-").split("-").filter(Boolean).map(function (part, index) {
      if (index === 0) return part.toLowerCase();
      if (/^[a-z]{4}$/i.test(part)) {
        return part.charAt(0).toUpperCase() + part.slice(1).toLowerCase();
      }
      if (/^[a-z]{2}$/i.test(part)) return part.toUpperCase();
      return part.toLowerCase();
    }).join("-");
  }

  function validLanguageCode(code) {
    var value = String(code || "").trim();
    if (!value) return false;
    if (value.toLowerCase() === "x-default") return true;
    if (value.indexOf("_") !== -1) return false;
    var parts = value.split("-");
    if (!/^[a-z]{2}$/i.test(parts[0])) return false;
    for (var i = 1; i < parts.length; i++) {
      if (/^[a-z]{4}$/i.test(parts[i])) continue;
      if (/^[a-z]{2}$/i.test(parts[i])) continue;
      if (/^\d{3}$/.test(parts[i])) continue;
      if (/^[a-z0-9]{5,8}$/i.test(parts[i])) continue;
      return false;
    }
    return true;
  }

  function ogLocaleFromLanguageCode(code) {
    var canonical = canonicalizeLanguageCode(code);
    if (!canonical || canonical === "x-default") return "";
    var parts = canonical.split("-");
    if (parts.length === 1) return parts[0];
    return parts.map(function (part, index) {
      return index === 0 ? part.toLowerCase() : part.replace(/-/g, "_");
    }).join("_");
  }

  function collectHreflangLinks() {
    return Array.from(document.querySelectorAll('link[rel="alternate"][hreflang]')).map(function (node) {
      var rawHref = (node.getAttribute("href") || "").trim();
      return {
        code: (node.getAttribute("hreflang") || "").trim(),
        normalized: canonicalizeLanguageCode(node.getAttribute("hreflang") || ""),
        href: node.href || rawHref,
        rawHref: rawHref,
        absolute: /^https?:\/\//i.test(rawHref)
      };
    });
  }

  function hreflangKnownCodeMap(links) {
    var map = {};
    links.forEach(function (link) {
      if (link.normalized && link.normalized !== "x-default") {
        map[link.normalized.toLowerCase()] = true;
      }
    });
    return map;
  }

  function languageSegmentFromUrl(url, knownCodes) {
    try {
      var parsed = new URL(url, window.location.href);
      var first = parsed.pathname.split("/").filter(Boolean)[0] || "";
      if (!first) return "";
      var normalized = canonicalizeLanguageCode(first);
      if (knownCodes[normalized.toLowerCase()]) return normalized;
      if (first.indexOf("-") !== -1 && validLanguageCode(first)) return normalized;
    } catch (_error) {
      return "";
    }
    return "";
  }

  function buildMultilingualDiagnostics(context) {
    var links = collectHreflangLinks();
    var expectedLang = canonicalizeLanguageCode(context.lang);
    var defaultLang = canonicalizeLanguageCode(context.defaultLang || inferDefaultLanguage());
    var htmlLang = document.documentElement.lang || "";
    var normalizedHtmlLang = canonicalizeLanguageCode(htmlLang);
    var canonical = normalizeCanonicalUrl(context.canonical || "");
    var counts = {};
    var duplicateCodes = [];
    var invalidCodes = [];
    var nonAbsolute = [];
    var urlLanguageMismatches = [];
    var xDefaultCount = 0;
    var selfLink = null;
    var knownCodes = hreflangKnownCodeMap(links);

    links.forEach(function (link) {
      if (link.normalized === "x-default") xDefaultCount += 1;
      if (!validLanguageCode(link.code)) invalidCodes.push(link.code || "(empty)");
      if (!link.absolute) nonAbsolute.push(link.code || "(empty)");
      if (link.normalized) {
        counts[link.normalized] = (counts[link.normalized] || 0) + 1;
        if (counts[link.normalized] === 2) duplicateCodes.push(link.normalized);
      }
      if (link.normalized && link.normalized !== "x-default" && link.normalized === expectedLang) {
        selfLink = link;
      }
      if (link.normalized && link.normalized !== "x-default" && link.href) {
        try {
          var parsed = new URL(link.href, window.location.href);
          var siteHost = inferSiteDomain() || window.location.hostname;
          var sameSite = !siteHost || parsed.hostname === siteHost || parsed.hostname === window.location.hostname;
          var pathLang = languageSegmentFromUrl(link.href, knownCodes);
          if (sameSite && link.normalized !== defaultLang && pathLang !== link.normalized) {
            urlLanguageMismatches.push(link.normalized + " -> " + parsed.pathname + (pathLang ? " (" + pathLang + ")" : " (missing lang path)"));
          } else if (sameSite && pathLang && pathLang !== link.normalized) {
            urlLanguageMismatches.push(link.normalized + " -> " + parsed.pathname + " (" + pathLang + ")");
          }
        } catch (_error) {
          urlLanguageMismatches.push(link.normalized + " -> invalid URL");
        }
      }
    });

    var selfHrefMismatch = false;
    if (selfLink && canonical) {
      selfHrefMismatch = normalizeCanonicalUrl(selfLink.href) !== canonical;
    }

    var ogLocale = metaContent('meta[property="og:locale"]');
    var ogLocaleExpected = ogLocaleFromLanguageCode(expectedLang);
    var ogLocaleAlternates = Array.from(document.querySelectorAll('meta[property="og:locale:alternate"]'))
      .map(function (node) { return (node.getAttribute("content") || "").trim(); })
      .filter(Boolean);

    return {
      htmlLang: htmlLang,
      normalizedHtmlLang: normalizedHtmlLang,
      expectedLang: expectedLang,
      defaultLang: defaultLang,
      htmlLangValid: validLanguageCode(htmlLang),
      htmlLangMatches: normalizedHtmlLang === expectedLang,
      hreflangLinks: links,
      hreflangCount: links.length,
      invalidCodes: invalidCodes,
      duplicateCodes: duplicateCodes,
      nonAbsolute: nonAbsolute,
      urlLanguageMismatches: urlLanguageMismatches,
      xDefaultCount: xDefaultCount,
      hasSelfAlternate: Boolean(selfLink),
      selfHrefMismatch: selfHrefMismatch,
      ogLocale: ogLocale,
      ogLocaleExpected: ogLocaleExpected,
      ogLocaleAlternates: ogLocaleAlternates
    };
  }

  function auditMultilingualStandards(context, add) {
    var diagnostics = context.multilingual || buildMultilingualDiagnostics(context);

    if (!diagnostics.htmlLang) {
      add("fail", "html lang", "Missing <html lang>.", "url");
    } else if (!diagnostics.htmlLangValid) {
      add("fail", "html lang format", 'Invalid language tag "' + diagnostics.htmlLang + '". Use BCP47 form such as en-IN or zh-Hans-CN.', "url");
    } else if (!diagnostics.htmlLangMatches) {
      add(
        "fail",
        "html lang mismatch",
        'Expected "' + diagnostics.expectedLang + '", got "' + diagnostics.normalizedHtmlLang + '".',
        "url"
      );
    } else {
      add("pass", "html lang", diagnostics.normalizedHtmlLang, "url");
    }

    if (!diagnostics.hreflangCount) {
      add("warn", "hreflang set", "No alternate hreflang links found.", "url");
      return diagnostics;
    }

    add("pass", "hreflang set", diagnostics.hreflangCount + " hreflang link(s) found.", "url");

    if (diagnostics.invalidCodes.length) {
      add("fail", "hreflang code format", "Invalid hreflang code(s): " + diagnostics.invalidCodes.join(", ") + ".", "url");
    } else {
      add("pass", "hreflang code format", "All hreflang codes use language-first BCP47 form.", "url");
    }

    if (diagnostics.duplicateCodes.length) {
      add("fail", "hreflang duplicates", "Duplicate hreflang code(s): " + diagnostics.duplicateCodes.join(", ") + ".", "url");
    } else {
      add("pass", "hreflang duplicates", "No duplicate hreflang codes.", "url");
    }

    if (diagnostics.nonAbsolute.length) {
      add("fail", "hreflang absolute URL", "Non-absolute hreflang href for: " + diagnostics.nonAbsolute.join(", ") + ".", "url");
    } else {
      add("pass", "hreflang absolute URL", "All hreflang href values are fully-qualified URLs.", "url");
    }

    if (diagnostics.urlLanguageMismatches && diagnostics.urlLanguageMismatches.length) {
      add(
        "warn",
        "hreflang URL language parity",
        "hreflang URL language does not match code: " + diagnostics.urlLanguageMismatches.slice(0, 6).join("; ") + ".",
        "url"
      );
    } else {
      add("pass", "hreflang URL language parity", "hreflang code and same-site URL language segment are aligned.", "url");
    }

    if (diagnostics.xDefaultCount === 1) {
      add("pass", "hreflang x-default", "Exactly one x-default fallback is present.", "url");
    } else if (diagnostics.xDefaultCount > 1) {
      add("fail", "hreflang x-default", "Expected one x-default fallback, found " + diagnostics.xDefaultCount + ".", "url");
    } else {
      add("warn", "hreflang x-default", "Missing x-default fallback for unmatched languages.", "url");
    }

    if (diagnostics.hasSelfAlternate) {
      add("pass", "hreflang self", "Current language has a self-referencing hreflang.", "url");
    } else {
      add("warn", "hreflang self", "Missing hreflang for current page language " + diagnostics.expectedLang + ".", "url");
    }

    if (diagnostics.selfHrefMismatch) {
      add("fail", "hreflang canonical parity", "Self hreflang href should equal canonical URL.", "url");
    } else if (diagnostics.hasSelfAlternate) {
      add("pass", "hreflang canonical parity", "Self hreflang href matches canonical URL.", "url");
    }

    if (diagnostics.hreflangCount > 1) {
      add("info", "hreflang reciprocal links", "Browser mode cannot fetch every alternate page; verify each localized URL links back to the full hreflang set.", "url");
    }

    if (diagnostics.ogLocale) {
      if (!/^[a-z]{2}(?:_[A-Z][a-z]{3})?(?:_[A-Z]{2}|\_\d{3})?$/i.test(diagnostics.ogLocale)) {
        add("warn", "og:locale format", 'Open Graph locale usually uses underscore form such as "' + diagnostics.ogLocaleExpected + '".', "social");
      } else if (diagnostics.ogLocaleExpected && diagnostics.ogLocale.toLowerCase() !== diagnostics.ogLocaleExpected.toLowerCase()) {
        add("warn", "og:locale parity", 'Expected og:locale "' + diagnostics.ogLocaleExpected + '", got "' + diagnostics.ogLocale + '".', "social");
      } else {
        add("pass", "og:locale parity", "og:locale matches page language.", "social");
      }
    }

    if (diagnostics.hreflangCount > 1) {
      if (diagnostics.ogLocaleAlternates.length) {
        add("pass", "og:locale:alternate", diagnostics.ogLocaleAlternates.length + " alternate locale tag(s).", "social");
      } else {
        add("warn", "og:locale:alternate", "Multilingual page missing og:locale:alternate meta tags.", "social");
      }
    }

    return diagnostics;
  }

  function auditKeywordStandards(context, add) {
    var raw = metaContent('meta[name="keywords"]');
    var keywords = splitKeywords(raw);
    var readableText = [
      context.title,
      context.description,
      context.h1Text,
      pageTextForScan()
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

    var validation = context.jsonLdValidation || validatePageJsonLd(context);
    (validation.checks || []).forEach(function (check) {
      add(check.level, check.label, check.detail, check.group);
    });
  }

  function isPanelOrInspectorResource(value) {
    return /(?:seo-inspector|dev-tool-panel|weline-panel|panel-token|codex|browser_pass)/i.test(String(value || ""));
  }

  function resolveAuditUrl(raw) {
    if (!raw) return "";
    if (/^(?:data|blob|javascript|mailto|tel):/i.test(raw)) return "";
    try {
      return new URL(raw, window.location.href).href;
    } catch (_error) {
      return "";
    }
  }

  function collectUrlAttributesForIssues() {
    var specs = [
      ["script[src]", "src", "script"],
      ['link[href][rel~="stylesheet"],link[href][rel~="preload"],link[href][rel~="modulepreload"],link[href][rel~="icon"],link[href][rel~="apple-touch-icon"],link[href][rel~="manifest"],link[href][rel~="sitemap"]', "href", "link"],
      ["img[src]", "src", "image"],
      ["source[src]", "src", "source"],
      ["iframe[src]", "src", "iframe"],
      ["video[src]", "src", "video"],
      ["video[poster]", "poster", "video-poster"],
      ["audio[src]", "src", "audio"],
      ["embed[src]", "src", "embed"],
      ["object[data]", "data", "object"],
      ["form[action]", "action", "form"],
      ["a[href]", "href", "anchor"]
    ];
    var items = [];
    specs.forEach(function (spec) {
      Array.from(document.querySelectorAll(spec[0])).forEach(function (node) {
        if (isIgnoredSeoAuditNode(node)) return;
        var raw = (node.getAttribute(spec[1]) || "").trim();
        if (!raw || isPanelOrInspectorResource(raw)) return;
        var href = resolveAuditUrl(raw);
        if (!href) return;
        items.push({
          node: node,
          attr: spec[1],
          kind: spec[2],
          raw: raw,
          href: href
        });
      });
    });
    return items;
  }

  function issueSample(items, limit) {
    return items.slice(0, limit || 5).map(function (item) {
      return item.raw || item.href || String(item);
    }).join("; ");
  }

  function sameHostUrl(url) {
    try {
      var parsed = new URL(url, window.location.href);
      return parsed.hostname === window.location.hostname || parsed.hostname === inferSiteDomain();
    } catch (_error) {
      return false;
    }
  }

  function assetPath(url) {
    try {
      return new URL(url, window.location.href).pathname.toLowerCase();
    } catch (_error) {
      return String(url || "").toLowerCase();
    }
  }

  function assetLooksMinified(url, ext) {
    var path = assetPath(url);
    var file = path.split("/").pop() || path;
    if (new RegExp("\\.min\\." + ext + "$", "i").test(file)) return true;
    if (new RegExp("(?:^|[._-])min(?:[._-]|$)", "i").test(file)) return true;
    if (/\.[a-f0-9]{8,}\.(?:js|css)$/i.test(file)) return true;
    return false;
  }

  function collectStaticAssetUrls(kind) {
    var nodes = kind === "js"
      ? Array.from(document.querySelectorAll("script[src]"))
      : Array.from(document.querySelectorAll('link[rel~="stylesheet"][href]'));
    return nodes
      .filter(function (node) { return !isIgnoredSeoAuditNode(node); })
      .map(function (node) { return (node.getAttribute(kind === "js" ? "src" : "href") || "").trim(); })
      .filter(function (raw) { return raw && !isPanelOrInspectorResource(raw); })
      .map(function (raw) { return { raw: raw, href: resolveAuditUrl(raw) || raw }; })
      .filter(function (item) { return item.href && assetPath(item.href).endsWith("." + kind); });
  }

  function resourceUrlLabel(entry) {
    try {
      var url = new URL(entry.name || "", window.location.href);
      return url.pathname.split("/").pop() || url.pathname || entry.name;
    } catch (_error) {
      return entry.name || "resource";
    }
  }

  function collectResourceTimingIssues() {
    var resources = performanceEntries("resource");
    var issues = {
      largeScripts: [],
      largeStyles: [],
      compression: []
    };
    resources.forEach(function (entry) {
      if (!entry || !entry.name || isPanelOrInspectorResource(entry.name)) return;
      var path = assetPath(entry.name);
      var transfer = usableNumber(entry.transferSize);
      var decoded = usableNumber(entry.decodedBodySize);
      var size = decoded || transfer || usableNumber(entry.encodedBodySize) || 0;
      var item = { raw: resourceUrlLabel(entry), href: entry.name, size: size, transfer: transfer, decoded: decoded };
      if (path.endsWith(".js") && size > 260 * 1024) issues.largeScripts.push(item);
      if (path.endsWith(".css") && size > 120 * 1024) issues.largeStyles.push(item);
      if ((path.endsWith(".js") || path.endsWith(".css")) && decoded && transfer && decoded > 30 * 1024 && transfer / decoded > 0.88) {
        issues.compression.push(item);
      }
    });
    return issues;
  }

  function auditSiteIssueStandards(context, add) {
    var actualPageHttps = window.location.protocol === "https:";
    var pageIsHttps = actualPageHttps || /^https:\/\//i.test(context.canonical || "");
    var urlItems = collectUrlAttributesForIssues();
    var httpItems = urlItems.filter(function (item) {
      return /^http:\/\//i.test(item.raw) || (actualPageHttps && /^http:\/\//i.test(item.href));
    });
    var httpResources = httpItems.filter(function (item) { return item.kind !== "anchor" && item.kind !== "form"; });
    var httpForms = httpItems.filter(function (item) { return item.kind === "form"; });
    var httpInternalLinks = httpItems.filter(function (item) { return item.kind === "anchor" && sameHostUrl(item.href); });
    var protocolRelative = urlItems.filter(function (item) { return /^\/\//.test(item.raw); });

    if (pageIsHttps && httpResources.length) {
      add("fail", "mixed content resources", httpResources.length + " insecure resource URL(s): " + issueSample(httpResources, 5) + ".", "issues");
    } else {
      add("pass", "mixed content resources", "No http:// resource URLs detected for HTTPS canonical/page.", "issues");
    }

    if (pageIsHttps && httpForms.length) {
      add("fail", "insecure form action", httpForms.length + " form action(s) submit to http://: " + issueSample(httpForms, 3) + ".", "issues");
    } else {
      add("pass", "insecure form action", "No insecure form action detected.", "issues");
    }

    if (pageIsHttps && httpInternalLinks.length) {
      add("warn", "insecure internal links", httpInternalLinks.length + " same-site link(s) use http://: " + issueSample(httpInternalLinks, 5) + ".", "issues");
    } else {
      add("pass", "insecure internal links", "No same-site http:// links detected.", "issues");
    }

    if (protocolRelative.length) {
      add("warn", "protocol-relative URLs", protocolRelative.length + " protocol-relative URL(s) found: " + issueSample(protocolRelative, 5) + ".", "issues");
    } else {
      add("pass", "protocol-relative URLs", "No protocol-relative // URLs detected.", "issues");
    }

    var jsAssets = collectStaticAssetUrls("js");
    var cssAssets = collectStaticAssetUrls("css");
    var unminifiedJs = jsAssets.filter(function (asset) { return !assetLooksMinified(asset.href, "js"); });
    var unminifiedCss = cssAssets.filter(function (asset) { return !assetLooksMinified(asset.href, "css"); });
    if (unminifiedJs.length) {
      add("warn", "unminified JavaScript", unminifiedJs.length + " JS file(s) do not look minified: " + issueSample(unminifiedJs, 5) + ".", "issues");
    } else {
      add("pass", "unminified JavaScript", "JavaScript file names look minified or cache-built.", "issues");
    }
    if (unminifiedCss.length) {
      add("warn", "unminified CSS", unminifiedCss.length + " CSS file(s) do not look minified: " + issueSample(unminifiedCss, 5) + ".", "issues");
    } else {
      add("pass", "unminified CSS", "CSS file names look minified or cache-built.", "issues");
    }

    var resourceIssues = collectResourceTimingIssues();
    if (resourceIssues.largeScripts.length) {
      add("warn", "large JavaScript resources", resourceIssues.largeScripts.length + " JS resource(s) exceed 260KB decoded/transfer size: " + issueSample(resourceIssues.largeScripts, 5) + ".", "issues");
    } else {
      add("pass", "large JavaScript resources", "No large JS resource detected by Resource Timing.", "issues");
    }
    if (resourceIssues.largeStyles.length) {
      add("warn", "large CSS resources", resourceIssues.largeStyles.length + " CSS resource(s) exceed 120KB decoded/transfer size: " + issueSample(resourceIssues.largeStyles, 5) + ".", "issues");
    } else {
      add("pass", "large CSS resources", "No large CSS resource detected by Resource Timing.", "issues");
    }
    if (resourceIssues.compression.length) {
      add("warn", "static compression", resourceIssues.compression.length + " JS/CSS resource(s) look weakly compressed in Resource Timing: " + issueSample(resourceIssues.compression, 5) + ".", "issues");
    } else {
      add("pass", "static compression", "Resource Timing did not expose obvious uncompressed JS/CSS transfer.", "issues");
    }

    var contentImages = Array.from(document.querySelectorAll("main img, .site-shell img, body img")).filter(function (img) {
      if (isIgnoredSeoAuditNode(img)) return false;
      var src = img.getAttribute("src") || "";
      return src && !isDecorativeImage(img) && !isBrandChromeImage(src);
    });
    var missingDimensions = contentImages.filter(function (img) {
      return !img.getAttribute("width") || !img.getAttribute("height");
    });
    if (missingDimensions.length) {
      add("warn", "image dimensions", missingDimensions.length + " content image(s) missing width/height attributes.", "issues");
    } else if (contentImages.length) {
      add("pass", "image dimensions", "Content images include width/height attributes.", "issues");
    }

    var unsafeBlankLinks = Array.from(document.querySelectorAll('a[target="_blank"][href]')).filter(function (node) {
      if (isIgnoredSeoAuditNode(node)) return false;
      var rel = (node.getAttribute("rel") || "").toLowerCase();
      return rel.indexOf("noopener") === -1 || rel.indexOf("noreferrer") === -1;
    });
    if (unsafeBlankLinks.length) {
      add("warn", "external link rel", unsafeBlankLinks.length + ' target="_blank" link(s) missing noopener/noreferrer.', "issues");
    } else {
      add("pass", "external link rel", 'All target="_blank" links include noopener/noreferrer or none exist.', "issues");
    }
  }

  function auditSeoStandards(context, add) {
    var title = context.title;
    var description = context.description;
    var canonical = context.canonical;
    var seoType = context.seoType;
    var jsonTypes = context.jsonTypes;
    var h1Text = context.h1Text;
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

    auditMultilingualStandards(context, add);

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
    auditSiteIssueStandards(context, add);

    var internalLinks = Array.from(document.querySelectorAll("a[href]")).filter(function (node) {
      if (isIgnoredSeoAuditNode(node)) return false;
      var href = node.getAttribute("href") || "";
      return href.startsWith("/") || href.indexOf(context.siteDomain) !== -1 || href.indexOf("{{link") !== -1;
    });
    if (internalLinks.length >= 5) {
      add("pass", "internal links", internalLinks.length + " internal links detected.", "content");
    } else {
      add("warn", "internal links", "Only " + internalLinks.length + " internal links; add descriptive in-site links.", "content");
    }

    var ctaElements = collectCtaElements(CTA_SELECTOR);
    if (ctaElements.length) {
      add("pass", "CTA", ctaElements.length + " CTA element(s) detected.", "content");
    } else if (shouldAuditCta(context)) {
      add("warn", "CTA", "No primary CTA detected on this conversion-oriented page.", "content");
    } else {
      add("info", "CTA", "Current page is not a CTA-focused landing page; CTA check is informational.", "content");
    }

    var emptyLinks = Array.from(document.querySelectorAll("a[href]")).filter(function (node) {
      if (isIgnoredSeoAuditNode(node)) return false;
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
    var seoType = normalizeSeoType(inferSeoTypeFromPage());
    var defaultLang = inferDefaultLanguage();
    var title = (document.title || "").trim();
    var description = metaContent('meta[name="description"]');
    var keywords = metaContent('meta[name="keywords"]');
    var canonicalNode = document.querySelector('link[rel="canonical"]');
    var canonical = canonicalNode ? canonicalNode.href : "";
    var htmlLang = document.documentElement.lang || "";
    var jsonTypes = extractJsonLdTypes();
    var h1Count = Array.from(document.querySelectorAll("h1")).filter(function (node) {
      return !isIgnoredSeoAuditNode(node);
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

    REQUIRED_HEAD.forEach(function (rule) {
      if (rule.types && rule.types.indexOf(seoType) === -1) return;
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
    var multilingual = buildMultilingualDiagnostics({ lang: lang, canonical: canonical, defaultLang: defaultLang });
    var jsonLdValidation = validatePageJsonLd({ seoType: seoType, jsonTypes: jsonTypes });

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
        defaultLang: defaultLang,
        siteDomain: siteDomain,
        multilingual: multilingual,
        jsonLdValidation: jsonLdValidation
      },
      add
    );

    var seoSummary = summarizeChecks(checks);

    var result = {
      seoSummary: seoSummary,
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
        jsonLdValidation: jsonLdValidation,
        multilingual: multilingual,
        h1Count: h1Count,
        visibleText: textLength,
        contentImages: images.length
      },
      headingOutline: headingOutline,
      checks: checks
    };
    result.engineDiagnostics = buildEngineDiagnostics(result);
    return result;
  }

  var AGENT_CONTRACT_VERSION = "weline-panel-seo/v1";
  var AGENT_COMMAND = "weline-panel:seo";

  var CRITICAL_CHECK_LABELS = {
    "Unresolved placeholder": true,
    "html lang mismatch": true,
    "html lang format": true,
    "canonical host": true,
    "canonical path": true,
    "canonical scheme": true,
    "html lang": true,
    "hreflang code format": true,
    "hreflang duplicates": true,
    "hreflang absolute URL": true,
    "hreflang canonical parity": true,
    "mixed content resources": true,
    "insecure form action": true,
    "JSON-LD placement": true,
    "JSON-LD page-type contract": true,
    "JSON-LD primary type": true,
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
    if (criticalFails.length || score < 50) verdict = "blocked";
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
      .concat(infos.map(function (item) { return toAction(item, "P2", "seo"); }));

    var groupedChecks = {};
    SEO_CHECK_GROUPS.forEach(function (group) {
      groupedChecks[group.id] = {
        title: group.title,
        items: raw.checks.filter(function (item) { return item.group === group.id; })
      };
    });

    var promoteReady = verdict === "ship" || verdict === "polish";
    var h1Text = getMainH1Text();
    var engineDiagnostics = raw.engineDiagnostics || buildEngineDiagnostics(raw);
    var scoreBreakdown = engineDiagnostics.scores || {};

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
        jsonLdTypes: raw.snapshot.jsonTypes,
        jsonLdValidation: raw.snapshot.jsonLdValidation,
        multilingual: raw.snapshot.multilingual
      },
      scores: {
        overall: typeof scoreBreakdown.total === "number" ? scoreBreakdown.total : score,
        seo: seoScore,
        promoteReadiness: verdict,
        indexability: scoreBreakdown.indexability,
        understandability: scoreBreakdown.understandability,
        experience: scoreBreakdown.experience,
        engineFit: scoreBreakdown.engineFit,
        legacyOverall: score
      },
      summary: {
        seo: raw.seoSummary,
        engines: {
          target: engineDiagnostics.target,
          counts: engineDiagnostics.counts,
          limitations: engineDiagnostics.limitations
        }
      },
      engines: engineDiagnostics.profiles,
      engineMatrix: engineDiagnostics.engineMatrix,
      issues: engineDiagnostics.issues,
      limitations: engineDiagnostics.limitations,
      verdict: {
        status: verdict,
        label: VERDICT_LABELS[verdict],
        promoteReady: promoteReady,
        criticalFailCount: criticalFails.length,
        failCount: fails.length,
        warnCount: warns.length
      },
      checks: {
        seoGrouped: groupedChecks,
        seoFlat: raw.checks,
        headingOutline: raw.headingOutline
      },
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
      "html lang": "Add a valid BCP47 <html lang> value that matches the page language.",
      "html lang format": "Use BCP47 language tags for html lang, for example en-IN or zh-Hans-CN; do not use underscores.",
      "html lang mismatch": "Make <html lang>, body data language, canonical localized URL, and hreflang self agree.",
      "hreflang self": "Add hreflang link for current page language in head.",
      "hreflang code format": "Use language-first BCP47 hreflang values such as en-IN, hi-IN, or zh-Hans-CN; use x-default only for fallback.",
      "hreflang duplicates": "Keep exactly one alternate link per hreflang code.",
      "hreflang absolute URL": "Use fully-qualified absolute URLs in every hreflang href.",
      "hreflang canonical parity": "Point the current page hreflang href at the same URL as canonical.",
      "hreflang URL language parity": "Make each same-site hreflang URL point to the path for its language, for example hi-IN -> /hi-in/....",
      "mixed content resources": "Replace every http:// image/script/style/iframe/resource URL with https:// or a same-origin relative URL.",
      "insecure form action": "Change form action URLs to https:// or same-origin relative endpoints.",
      "insecure internal links": "Replace same-site http:// links with https:// canonical URLs.",
      "protocol-relative URLs": "Use explicit https:// URLs instead of //example.com to avoid crawler and security ambiguity.",
      "unminified JavaScript": "Use built/minified JS assets in production, preferably hashed bundles or .min.js files.",
      "unminified CSS": "Use built/minified CSS assets in production, preferably hashed bundles or .min.css files.",
      "large JavaScript resources": "Split, tree-shake, defer, or lazy-load large JavaScript bundles before promotion.",
      "large CSS resources": "Remove unused CSS, split critical CSS, and ship compressed production CSS.",
      "static compression": "Enable gzip or Brotli for JS/CSS and verify transferSize is materially smaller than decodedBodySize.",
      "image dimensions": "Add width and height attributes to content images to reduce CLS and improve rendering predictability.",
      "external link rel": 'Add rel="noopener noreferrer" to target="_blank" links.',
      "title/H1 alignment": "Make H1 the on-page expression of the same intent as title.",
      "internal links": "Add descriptive internal links to hub/guide/review pages.",
      "prompt leak": "Remove internal prompt/build vocabulary from visible copy and metadata.",
      "public copy leak": "Replace internal planning phrases with reader-facing page language.",
      "JSON-LD @WebSite": "Add WebSite JSON-LD in head via @page schema or seo-jsonld block.",
      "JSON-LD @Organization": "Add Organization JSON-LD with site logo URL in head.",
      "JSON-LD @BreadcrumbList": "Add BreadcrumbList JSON-LD matching visible route hierarchy.",
      "JSON-LD @NewsArticle": "For news pages, emit NewsArticle JSON-LD with headline, dates, author, image, mainEntityOfPage, and publisher.",
      "JSON-LD @BlogPosting": "For blog pages, emit BlogPosting JSON-LD with headline, dates, author, image, mainEntityOfPage, and publisher.",
      "JSON-LD page-type contract": "Fix invalid JSON-LD before validating page-type schema.",
      "JSON-LD primary type": "Emit the primary schema type expected by the page-type meta value.",
      "CTA": "Add a clear primary CTA. Event wiring is owned by Weline_Visitor Pixel and should be checked in the Visitor panel."
    };
    if (item.label && item.label.indexOf("JSON-LD field ") === 0) {
      return "Add the missing JSON-LD field on the page-type primary schema node and keep it consistent with visible content.";
    }
    if (item.label && item.label.indexOf("JSON-LD recommended ") === 0) {
      return "Add this recommended JSON-LD field when the page has reliable source data; do not invent values.";
    }
    return hints[item.label] || "Fix the reported check in page source HTML/head, then re-run WelinePanel SEO report.";
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
        reason: "Traffic without a working CTA chain wastes promotion spend.",
        monitor: "Verify the primary CTA destination, mobile visibility, and Visitor Pixel event forwarding."
      }
    ];
  }

  function buildAgentGuide(verdict, fails, warns, raw) {
    var steps = [];
    if (verdict === "blocked") {
      steps.push("Fix all P0 actions first: canonical/head/schema/compliance failures.");
      steps.push("Re-run WelinePanel SEO report until verdict is fix or higher.");
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
        "Use verdict.status as primary gate. fail=blocking SEO defect, warn=optimization debt, info=expected environment note. Visitor Pixel forwarding is checked in the Visitor panel, not SEO.",
      promotionGate: verdict === "ship" || verdict === "polish",
      doNotPromoteIf: ["blocked", "fix"].indexOf(verdict) !== -1,
      interpretationOrder: ["verdict", "actions(P0->P3)", "checks.seoFlat", "monitoringGaps"],
      nextSteps: steps
    };
  }

  function auditAgentReport() {
    return buildAgentReport(auditCurrentPage());
  }

  function publishAgentReport(report) {
    window.__WELINE_PANEL_SEO_REPORT__ = report;
    var node = document.getElementById("weline-panel-seo-report");
    if (!node) {
      node = document.createElement("script");
      node.type = "application/json";
      node.id = "weline-panel-seo-report";
      document.head.appendChild(node);
    }
    node.textContent = JSON.stringify(report);
    window.dispatchEvent(new CustomEvent("weline-panel:seo-report", { detail: report }));
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

  function engineTone(status) {
    if (status === "warning") return "warn";
    if (status === "unknown") return "unknown";
    return status || "info";
  }

  function engineStatusText(status) {
    return {
      pass: "Pass",
      info: "Info",
      warn: "Warning",
      warning: "Warning",
      fail: "Fail",
      unknown: "Unknown"
    }[status] || status || "Unknown";
  }

  function renderScoreCards(scores) {
    var items = [
      ["Indexability", scores.indexability, "indexability"],
      ["Understandability", scores.understandability, "understandability"],
      ["Experience", scores.experience, "experience"],
      ["Engine Fit", scores.engineFit, "engineFit"]
    ];
    var details = scores.details || {};
    return (
      '<div class="weline-seo-panel__score-grid">' +
      items
        .map(function (item) {
          var value = typeof item[1] === "number" ? item[1] : 0;
          var tone = value >= 90 ? "pass" : value >= 75 ? "warn" : "fail";
          var detail = details[item[2]] || null;
          return (
            '<div class="weline-seo-panel__score-card weline-seo-panel__score-card--' +
            tone +
            '"><span>' +
            escapeHtml(item[0]) +
            "</span><strong>" +
            escapeHtml(String(value)) +
            "</strong>" +
            renderScoreDetail(detail, value) +
            "</div>"
          );
        })
        .join("") +
      "</div>"
    );
  }

  function renderScoreDetail(detail, value) {
    var deductions = detail && Array.isArray(detail.deductions) ? detail.deductions : [];
    if (!deductions.length) {
      return '<div class="weline-seo-panel__score-detail">' + (value >= 100 ? "无扣分" : "未记录扣分明细") + "</div>";
    }

    return (
      '<ul class="weline-seo-panel__score-detail">' +
      deductions.slice(0, 3).map(function (item) {
        return (
          "<li><b>-" +
          escapeHtml(String(item.points || 0)) +
          "</b> " +
          escapeHtml(item.label || "SEO check") +
          "</li>"
        );
      }).join("") +
      (deductions.length > 3 ? "<li>+" + escapeHtml(String(deductions.length - 3)) + " more</li>" : "") +
      "</ul>"
    );
  }

  function renderEngineMatrixTable(diagnostics) {
    var matrix = diagnostics.engineMatrix || {};
    var engines = diagnostics.profiles || ENGINE_PROFILES;
    var rows = diagnostics.rows || ENGINE_MATRIX_ROWS;
    var head =
      "<tr><th>检测项</th>" +
      engines
        .map(function (engine) {
          return "<th>" + escapeHtml(engine.name) + "</th>";
        })
        .join("") +
      "</tr>";
    var body = rows
      .map(function (row) {
        return (
          "<tr><th>" +
          escapeHtml(row.label) +
          "</th>" +
          engines
            .map(function (engine) {
              var item = (matrix[engine.id] && matrix[engine.id].rows || []).find(function (entry) {
                return entry.id === row.id;
              }) || { status: "unknown", detail: "未检测。" };
              return (
                '<td><span class="weline-seo-panel__engine-dot weline-seo-panel__engine-dot--' +
                escapeHtml(engineTone(item.status)) +
                '" title="' +
                escapeHtml(item.detail) +
                '">' +
                escapeHtml(engineStatusText(item.status)) +
                "</span></td>"
              );
            })
            .join("") +
          "</tr>"
        );
      })
      .join("");
    return (
      '<section class="weline-seo-panel__section"><h3>搜索引擎适配矩阵</h3>' +
      '<div class="weline-seo-panel__engine-table-wrap"><table class="weline-seo-panel__engine-table">' +
      "<thead>" +
      head +
      "</thead><tbody>" +
      body +
      "</tbody></table></div></section>"
    );
  }

  function renderEngineFindings(item) {
    var findings = (item.rows || []).filter(function (row) {
      return row.status === "fail" || row.status === "warn" || row.status === "warning";
    });
    if (!findings.length) {
      return '<p class="weline-seo-panel__engine-ok">未发现平台合规失败或警告项。</p>';
    }
    return (
      '<div class="weline-seo-panel__engine-findings">' +
      findings
        .map(function (row) {
          var tone = engineTone(row.status);
          return (
            '<div class="weline-seo-panel__engine-finding weline-seo-panel__engine-finding--' +
            escapeHtml(tone) +
            '">' +
            '<div class="weline-seo-panel__engine-finding-head">' +
            '<span class="weline-seo-panel__badge weline-seo-panel__badge--' +
            escapeHtml(tone) +
            '">' +
            escapeHtml(engineStatusText(row.status)) +
            "</span><strong>" +
            escapeHtml(row.label) +
            "</strong></div>" +
            '<dl class="weline-seo-panel__engine-finding-detail">' +
            "<div><dt>原因</dt><dd>" +
            escapeHtml(row.detail || "未提供具体原因。") +
            "</dd></div>" +
            "<div><dt>建议</dt><dd>" +
            escapeHtml(row.recommendation || "补充服务端爬虫或平台站长工具验证。") +
            "</dd></div>" +
            "</dl></div>"
          );
        })
        .join("") +
      "</div>"
    );
  }

  function renderEngineCards(diagnostics) {
    var matrix = diagnostics.engineMatrix || {};
    var engines = diagnostics.profiles || ENGINE_PROFILES;
    return (
      '<section class="weline-seo-panel__section"><h3>平台结论</h3>' +
      '<div class="weline-seo-panel__engine-cards">' +
      engines
        .map(function (engine) {
          var item = matrix[engine.id] || {};
          var recommendations = item.recommendations && item.recommendations.length
            ? item.recommendations
            : ["当前浏览器模式未发现平台专项阻断；建议继续用服务端爬虫补查。"];
          return (
            '<article class="weline-seo-panel__engine-card weline-seo-panel__engine-card--' +
            escapeHtml(engineTone(item.status)) +
            '">' +
            '<div class="weline-seo-panel__engine-card-head"><div><h4>' +
            escapeHtml(engine.name) +
            "</h4><p>" +
            escapeHtml(engine.label) +
            "</p></div>" +
            '<span class="weline-seo-panel__engine-score">' +
            escapeHtml(String(typeof item.score === "number" ? item.score : 0)) +
            "</span></div>" +
            '<p class="weline-seo-panel__engine-focus">' +
            escapeHtml((engine.focus || []).join(" · ")) +
            "</p>" +
            '<p><span class="weline-seo-panel__badge weline-seo-panel__badge--' +
            escapeHtml(engineTone(item.status)) +
            '">' +
            escapeHtml(engineStatusText(item.status)) +
            "</span></p>" +
            '<ul class="weline-seo-panel__engine-actions">' +
            recommendations
              .map(function (text) {
                return "<li>" + escapeHtml(text) + "</li>";
              })
              .join("") +
            "</ul>" +
            renderEngineFindings(item) +
            "</article>"
          );
        })
        .join("") +
      "</div></section>"
    );
  }

  function renderLimitations(limitations) {
    if (!limitations || !limitations.length) return "";
    return (
      '<section class="weline-seo-panel__section"><h3>浏览器模式限制</h3>' +
      '<ul class="weline-seo-panel__limitations">' +
      limitations
        .map(function (item) {
          return "<li>" + escapeHtml(item) + "</li>";
        })
        .join("") +
      "</ul></section>"
    );
  }

  function renderPanelTabs() {
    return (
      '<div class="weline-seo-panel__tabs" role="tablist" aria-label="Inspector sections">' +
      '<button type="button" class="weline-seo-panel__tab is-active" data-weline-tab="seo" role="tab" aria-selected="true">SEO 校验</button>' +
      '<button type="button" class="weline-seo-panel__tab" data-weline-tab="engines" role="tab" aria-selected="false">搜索平台</button>' +
      "</div>"
    );
  }

  function renderPageContext(report) {
    var title = (report.snapshot && report.snapshot.title) || document.title || "未命名页面";
    var url = window.location.href;
    return (
      '<div class="weline-seo-panel__page-context">' +
      '<div class="weline-seo-panel__page-main"><span>当前页面</span><strong>' +
      escapeHtml(title) +
      "</strong><code>" +
      escapeHtml(url) +
      "</code></div>" +
      '<div class="weline-seo-panel__page-heading"><span>H 标签</span><div class="weline-seo-panel__heading-summary weline-seo-panel__heading-summary--compact">' +
      renderHeadingCounts((report.headingOutline && report.headingOutline.counts) || {}) +
      "</div></div>" +
      '<button type="button" class="weline-seo-panel__publish-btn" data-weline-seo-publish>发布 AI 报告</button>' +
      "</div>"
    );
  }

  function renderPanelToolbar(report) {
    return (
      '<div class="weline-seo-panel__topbar">' +
      renderPageContext(report) +
      renderPanelTabs() +
      "</div>"
    );
  }

  function renderEngineTab(report) {
    var diagnostics = report.engineDiagnostics || buildEngineDiagnostics(report);
    return (
      renderScoreCards(diagnostics.scores || {}) +
      renderEngineMatrixTable(diagnostics) +
      renderEngineCards(diagnostics) +
      renderLimitations(diagnostics.limitations)
    );
  }

  function renderIssueAuditBlock(report) {
    var issueChecks = (report.checks || []).filter(function (check) {
      return check.group === "issues" && (check.level === "fail" || check.level === "warn");
    });
    var titleSuffix = issueChecks.length ? " · " + issueChecks.length + " 个需处理" : " · 未发现阻断";
    var body = "";
    if (!issueChecks.length) {
      body = '<p class="weline-seo-panel__issue-ok">当前未发现 Semrush 风格页面 Issue。</p>';
    } else {
      body =
        '<div class="weline-seo-panel__issue-list">' +
        issueChecks
          .map(function (check) {
            return (
              '<article class="weline-seo-panel__issue-card weline-seo-panel__issue-card--' +
              escapeHtml(check.level) +
              '">' +
              '<div class="weline-seo-panel__issue-head">' +
              '<span class="weline-seo-panel__badge weline-seo-panel__badge--' +
              escapeHtml(check.level) +
              '">' +
              escapeHtml(formatCheckLevel(check.level)) +
              "</span><strong>" +
              escapeHtml(check.label) +
              "</strong></div>" +
              (check.detail ? '<p class="weline-seo-panel__hint">' + escapeHtml(check.detail) + "</p>" : "") +
              '<p class="weline-seo-panel__issue-fix"><b>建议</b> ' +
              escapeHtml(actionFixHint(check)) +
              "</p></article>"
            );
          })
          .join("") +
        "</div>";
    }
    return (
      '<section class="weline-seo-panel__section weline-seo-panel__section--issues"><h3>Issue 审计' +
      escapeHtml(titleSuffix) +
      "</h3>" +
      '<p class="weline-seo-panel__hint">这里收敛非结构性站点问题：混合内容、语言 URL 对齐、静态资源压缩/minify、图片尺寸和外链安全。</p>' +
      body +
      "</section>"
    );
  }

  function renderSeoTab(report) {
    return (
      renderSummary(report.seoSummary) +
      renderIssueAuditBlock(report) +
      '<section class="weline-seo-panel__section"><h3>页面快照</h3><dl class="weline-seo-panel__grid">' +
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
      '<div class="weline-seo-panel__field"><dt>JSON-LD contract</dt><dd>' +
      escapeHtml(
        report.snapshot.jsonLdValidation
          ? report.snapshot.jsonLdValidation.label + " · " + report.snapshot.jsonLdValidation.expectedType + " · " + report.snapshot.jsonLdValidation.status
          : "unknown"
      ) +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>Multilingual</dt><dd>' +
      escapeHtml(
        report.snapshot.multilingual
          ? "html=" + (report.snapshot.multilingual.normalizedHtmlLang || "missing") +
              " · hreflang=" + report.snapshot.multilingual.hreflangCount +
              " · x-default=" + report.snapshot.multilingual.xDefaultCount
          : "unknown"
      ) +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>Structure</dt><dd>H1=' +
      escapeHtml(String(report.snapshot.h1Count)) +
      " · text=" +
      escapeHtml(String(report.snapshot.visibleText)) +
      " chars · images=" +
      escapeHtml(String(report.snapshot.contentImages)) +
      "</dd></div>" +
      "</dl></section>" +
      '<section class="weline-seo-panel__section"><h3>H 标签目录</h3>' +
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

  var SEO_PANEL_STATE_KEY = "weline-seo-panel-state-v1";

  function normalizePanelTab(tabId) {
    return ["seo", "engines"].indexOf(tabId) !== -1 ? tabId : "seo";
  }

  function readPanelState() {
    try {
      if (!window.localStorage) return {};
      var raw = window.localStorage.getItem(SEO_PANEL_STATE_KEY);
      if (!raw) return {};
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function savePanelState(patch) {
    try {
      if (!window.localStorage) return;
      var current = readPanelState();
      Object.keys(patch || {}).forEach(function (key) {
        current[key] = patch[key];
      });
      current.updatedAt = Date.now();
      window.localStorage.setItem(SEO_PANEL_STATE_KEY, JSON.stringify(current));
    } catch (error) {
      // Ignore storage failures so the inspector remains usable in restricted browsers.
    }
  }

  var activePanelTab = normalizePanelTab(readPanelState().activeTab || "seo");

  function panelTabScopes(root, controlsRoot) {
    return [root, controlsRoot].filter(function (item, index, list) {
      return item && list.indexOf(item) === index;
    });
  }

  function bindPanelTabs(root, controlsRoot) {
    panelTabScopes(root, controlsRoot).forEach(function (scope) {
      scope.querySelectorAll("[data-weline-tab]").forEach(function (button) {
        if (button.__welineSeoTabBound) return;
        button.__welineSeoTabBound = true;
        button.addEventListener("click", function () {
          setPanelTab(root, button.getAttribute("data-weline-tab") || "seo", controlsRoot);
        });
      });
    });
  }

  function setPanelTab(root, tabId, controlsRoot) {
    activePanelTab = normalizePanelTab(tabId);
    savePanelState({ activeTab: activePanelTab });
    panelTabScopes(root, controlsRoot).forEach(function (scope) {
      scope.querySelectorAll("[data-weline-tab]").forEach(function (button) {
        var isActive = button.getAttribute("data-weline-tab") === activePanelTab;
        button.classList.toggle("is-active", isActive);
        button.setAttribute("aria-selected", isActive ? "true" : "false");
      });
    });
    root.querySelectorAll("[data-weline-panel]").forEach(function (panel) {
      var isActive = panel.getAttribute("data-weline-panel") === activePanelTab;
      panel.classList.toggle("is-active", isActive);
      panel.hidden = !isActive;
    });
  }

  function renderPanelBody(report, options) {
    options = options || {};
    return (
      (options.externalToolbar ? "" : renderPanelToolbar(report)) +
      '<div class="weline-seo-panel__tab-panel is-active" data-weline-panel="seo" role="tabpanel">' +
      renderSeoTab(report) +
      "</div>" +
      '<div class="weline-seo-panel__tab-panel" data-weline-panel="engines" role="tabpanel" hidden>' +
      renderEngineTab(report) +
      "</div>"
    );
  }

  function resolveContainer(container) {
    if (typeof container === "string") {
      return document.querySelector(container);
    }
    return container && container.nodeType === 1 ? container : null;
  }

  function renderInto(container) {
    var options = arguments.length > 1 && arguments[1] ? arguments[1] : {};
    var root = resolveContainer(container);
    if (!root) {
      throw new Error("SEO 诊断挂载点不存在。");
    }
    var raw = auditCurrentPage();
    var toolbarRoot = resolveContainer(options.toolbarContainer || null);
    if (toolbarRoot) {
      toolbarRoot.classList.add("weline-seo-panel__toolbar-host");
      toolbarRoot.innerHTML = renderPanelToolbar(raw);
    }
    root.classList.add("weline-seo-panel", "weline-seo-panel--embedded");
    root.innerHTML =
      '<div class="weline-seo-panel__dialog">' +
      '<div class="weline-seo-panel__body">' +
      renderPanelBody(raw, { externalToolbar: Boolean(toolbarRoot) }) +
      "</div></div>";
    bindPanelTabs(root, toolbarRoot);
    setPanelTab(root, activePanelTab, toolbarRoot);
    publishAgentReport(buildAgentReport(raw));
    return raw;
  }

  window.__WELINE_SEO_INSPECTOR__ = {
    renderInto: renderInto,
    audit: auditCurrentPage,
    report: auditAgentReport,
    publish: function () {
      return publishAgentReport(auditAgentReport());
    }
  };

  publishAgentReport(buildAgentReport(auditCurrentPage()));
})();
