(function () {
  "use strict";

  var SEO_TEXT_LIMITS = {
    titleMin: 30,
    titleMax: 65,
    // Google 未规定 description 字数；以下仅作「明显过短/过长」软提示，不作硬门槛。
    descriptionSoftMin: 50,
    descriptionSoftMax: 320,
    visibleTextMin: 2500
  };
  var SEO_AUDIT_IGNORE_SELECTOR = [
    ".weline-seo-panel",
    "#dev-tool-panel",
    "#weline-panel-token-dialog",
    "[data-weline-panel-bootstrap]",
    "[data-weline-panel-seo-bootstrap]",
    // Chrome UI (drawers/dialogs) must not pollute page SEO heading/image audits.
    ".w-dialog",
    "dialog",
    ".mini-cart-drawer",
    "[data-w-drawer]",
    "[data-drawer]",
    ".weline-header-drawer",
    ".language-switcher-panel",
    ".w-language-drawer",
    "[aria-modal='true']"
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
    { id: "compliance", title: "合规与泄露" },
    { id: "eeat", title: "Google 自测" }
  ];

  /**
   * Internal self-checks aligned to Google Search Central
   * "Creating helpful, reliable, people-first content" (Who / How / Why).
   * Machine-verifiable signals only. Not a ranking gate; E-E-A-T is not a single factor.
   * Docs: https://developers.google.com/search/docs/fundamentals/creating-helpful-content
   */
  var EEAT_STRICT_RULES = [
    {
      id: "who_visible_byline",
      dimension: "Who",
      level: "warn",
      scoringExempt: true,
      when: ["article", "blog", "news"],
      label: "Visible author byline",
      detailMissing: "文章页未见可见署名（Google Who：读者应能看出谁写了内容）。内部自测 · 非排名门槛。"
    },
    {
      id: "who_byline_schema_match",
      dimension: "Who",
      level: "warn",
      scoringExempt: true,
      when: ["article", "blog", "news"],
      label: "Byline ↔ Person.name match",
      detailMissing: "可见署名与 JSON-LD Person.name 不一致（避免壳 schema）。内部自测 · 非排名门槛。"
    },
    {
      id: "who_person_author",
      dimension: "Who",
      level: "warn",
      scoringExempt: true,
      when: ["article", "blog", "news"],
      label: "Article Person author",
      detailMissing: "无 Person 作者（仅 Organization @id 回退或空）。Google 鼓励准确署名。内部自测 · 非排名门槛。"
    },
    {
      id: "who_person_url_or_sameas",
      dimension: "Who",
      level: "tip",
      scoringExempt: true,
      when: ["article", "blog", "news"],
      label: "Person url or sameAs",
      detailMissing: "Person 缺可消歧的 url 或 sameAs（官方鼓励作者背景可延伸）。jobTitle 非必填。内部自测 · 非排名门槛。"
    },
    {
      id: "who_author_background",
      dimension: "Who",
      level: "tip",
      scoringExempt: true,
      when: ["article", "blog", "news"],
      label: "Author background reachable",
      detailMissing: "无作者主页链接且页内无作者简介（Google Who：署名宜链到作者背景）。内部自测 · 非排名门槛。"
    },
    {
      id: "who_publisher",
      dimension: "Who",
      level: "warn",
      scoringExempt: true,
      when: ["article", "blog", "news"],
      label: "Article publisher",
      detailMissing: "Article 缺 publisher / 未关联 Organization（站点「谁发布」）。内部自测 · 非排名门槛。"
    },
    {
      id: "trust_about_contact",
      dimension: "Trust",
      level: "tip",
      scoringExempt: true,
      when: ["all"],
      label: "About/Contact discoverability",
      detailMissing: "无 AboutPage/ContactPage 且导航未见关于/联系（Google：站点背景可核查）。内部自测 · 非排名门槛。"
    },
    {
      id: "trust_org_logo",
      dimension: "Trust",
      level: "warn",
      scoringExempt: true,
      when: ["home", "all"],
      label: "Organization.logo",
      detailMissing: "Organization 缺绝对 logo（商家实体示例字段）。≠ 权威性本体。内部自测 · 非排名门槛。"
    },
    {
      id: "trust_org_sameas",
      dimension: "Trust",
      level: "warn",
      scoringExempt: true,
      when: ["home", "all"],
      label: "Organization.sameAs",
      detailMissing: "Organization 缺 sameAs（商家实体示例字段）。≠ 权威性本体。内部自测 · 非排名门槛。"
    },
    {
      id: "how_article_dates",
      dimension: "How",
      level: "tip",
      scoringExempt: true,
      when: ["article", "blog", "news"],
      label: "Article dates",
      detailMissing: "缺 datePublished 或 dateModified（有助读者理解时效；勿仅改日期刷鲜）。内部自测 · 非排名门槛。"
    },
    {
      id: "how_content_substance",
      dimension: "How",
      level: "tip",
      scoringExempt: true,
      when: ["article", "blog", "news"],
      label: "Content substance (not Experience)",
      detailMissing: "正文偏短或无配图（充实度提示，≠ Google Experience 一手体验）。内部自测 · 非排名门槛。"
    },
    {
      id: "how_review_author",
      dimension: "How",
      level: "tip",
      scoringExempt: true,
      when: ["all"],
      label: "Review author",
      detailMissing: "存在 Review 但 author 为空。内部自测 · 非排名门槛。"
    },
    {
      id: "why_primary_audience",
      dimension: "Why",
      level: "tip",
      scoringExempt: true,
      when: ["all"],
      label: "Why: audience & intent (manual)",
      detailMissing: "官方 Why（为谁写、是否主要为排名而写）需人工自审；面板无法代判意图。内部自测 · 非排名门槛。"
    },
    {
      id: "why_main_content_first",
      dimension: "Why",
      level: "tip",
      scoringExempt: true,
      when: ["article", "blog", "news", "product", "home"],
      label: "Why: main content present",
      detailMissing: "主内容区偏短或难定位（Why 代理：页面应首先服务读者主任务）。内部自测 · 非排名门槛。"
    }
  ];

  /** @deprecated id aliases kept for older evidence strings */
  var EEAT_RULE_ID_ALIASES = {
    eeat_org_sameas: "trust_org_sameas",
    eeat_org_logo: "trust_org_logo",
    eeat_article_author_missing: "who_person_author",
    eeat_article_author_shallow: "who_person_url_or_sameas",
    eeat_article_dates: "how_article_dates",
    eeat_publisher: "who_publisher",
    eeat_about_contact: "trust_about_contact",
    eeat_review_author: "how_review_author",
    eeat_experience_signal: "how_content_substance"
  };

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
    Product: ["Product", "ProductGroup"],
    ProductGroup: ["ProductGroup", "Product"],
    WebPage: ["WebPage", "AboutPage", "ContactPage", "FAQPage", "ProfilePage", "CollectionPage"],
    Organization: ["Organization", "LocalBusiness", "OnlineStore", "OnlineBusiness", "Corporation", "NGO"],
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
    collection_page: "collection",
    products: "collection",
    best_sellers: "collection",
    new_arrivals: "collection",
    blog_list: "collection",
    blog_category: "collection",
    searchable_landing: "collection",
    tag_collection: "collection",
    tag_landing: "collection",
    contact: "contact",
    about: "about",
    about_page: "about",
    legal: "legal",
    privacy: "legal",
    terms: "legal",
    policy: "legal",
    accessibility: "legal",
    cookie: "legal",
    shipping: "legal",
    refund: "legal",
    disclaimer: "legal",
    term_condition: "legal",
    home: "home",
    homepage: "home",
    index: "home",
    web_page: "web_page",
    webpage: "web_page"
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
      requiredTypes: ["WebSite", "Organization"],
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
      requiredTypes: ["Product"],
      primaryType: "Product",
      requiredFields: ["name", "image", "offers|hasVariant"],
      recommendedFields: [
        "description",
        "sku",
        "brand.name",
        "offers.price|offers.lowPrice",
        "offers.priceCurrency",
        "offers.availability|hasVariant",
        "offers.url|hasVariant",
        "aggregateRating.ratingValue",
        "aggregateRating.reviewCount"
      ]
    },
    collection: {
      label: "列表页",
      // Google has no ecommerce CollectionPage/ItemList rich result; BreadcrumbList is the documented feature.
      requiredTypes: ["BreadcrumbList", "Organization", "WebSite"],
      primaryType: "WebPage",
      requiredFields: ["name", "url"],
      recommendedFields: ["description"]
    },
    contact: {
      label: "联系页",
      requiredTypes: ["ContactPage", "BreadcrumbList"],
      primaryType: "ContactPage",
      requiredFields: ["name", "url"],
      recommendedFields: ["mainEntity", "about", "publisher.name", "publisher.logo"]
    },
    about: {
      label: "关于页",
      requiredTypes: ["AboutPage", "BreadcrumbList"],
      primaryType: "AboutPage",
      requiredFields: ["name", "url"],
      recommendedFields: ["description", "publisher.name", "publisher.logo"]
    },
    legal: {
      label: "法律/政策页",
      requiredTypes: ["WebPage", "BreadcrumbList", "WebSite", "Organization"],
      primaryType: "WebPage",
      requiredFields: ["name", "url"],
      recommendedFields: ["dateModified", "publisher.name"]
    },
    web_page: {
      label: "普通页面",
      requiredTypes: ["WebSite", "Organization"],
      primaryType: "WebPage",
      requiredFields: ["name", "url"],
      recommendedFields: ["description", "breadcrumb"]
    }
  };

  var REQUIRED_HEAD = [
    { name: "title", test: function () { return Boolean((document.title || "").trim()); } },
    {
      // Google default is index,follow when robots meta is omitted — do not require the tag.
      // Fail only when an explicit blocking directive is present (handled in auditSeoStandards).
      name: "robots meta",
      soft: true,
      levelWhenMissing: "pass",
      detailWhenMissing: "Omitted; Google defaults to index,follow.",
      detailWhenPresent: "Present in head.",
      test: function () {
        var node = document.querySelector('meta[name="robots"]');
        if (!node) return true;
        var content = (node.getAttribute("content") || "").trim();
        return content !== "" && !/noindex|none/i.test(content);
      }
    },
    { name: "page-type meta", soft: true, levelWhenMissing: "tip", test: function () { return Boolean(document.querySelector('meta[name="page-type"]')); } },
    {
      name: "content-category meta",
      soft: true,
      levelWhenMissing: "tip",
      test: function () { return Boolean(document.querySelector('meta[name="content-category"]')); }
    },
    {
      // Google ignores meta keywords for ranking; keep as tip only.
      name: "keywords meta",
      soft: true,
      levelWhenMissing: "tip",
      test: function () { return Boolean(document.querySelector('meta[name="keywords"]')); }
    },
    {
      name: "article:section",
      types: ["article", "blog_post", "post", "news", "news_article"],
      soft: true,
      levelWhenMissing: "warn",
      test: function () { return Boolean(document.querySelector('meta[property="article:section"]')); }
    },
    {
      name: "article:modified_time",
      types: ["article", "blog_post", "post", "news", "news_article"],
      soft: true,
      levelWhenMissing: "warn",
      test: function () { return Boolean(document.querySelector('meta[property="article:modified_time"]')); }
    },
    { name: "og:site_name", soft: true, levelWhenMissing: "warn", test: function () { return Boolean(document.querySelector('meta[property="og:site_name"]')); } },
    { name: "og:locale", soft: true, levelWhenMissing: "warn", test: function () { return Boolean(document.querySelector('meta[property="og:locale"]')); } },
    { name: "og:title", test: function () { return Boolean(document.querySelector('meta[property="og:title"]')); } },
    { name: "og:description", test: function () { return Boolean(document.querySelector('meta[property="og:description"]')); } },
    { name: "og:type", test: function () { return Boolean(document.querySelector('meta[property="og:type"]')); } },
    { name: "og:url", test: function () { return Boolean(document.querySelector('meta[property="og:url"]')); } },
    { name: "og:image", test: function () { return Boolean(document.querySelector('meta[property="og:image"]')); } },
    { name: "og:image:alt", soft: true, levelWhenMissing: "warn", test: function () { return Boolean(document.querySelector('meta[property="og:image:alt"]')); } },
    { name: "twitter:card", soft: true, levelWhenMissing: "warn", test: function () { return Boolean(document.querySelector('meta[name="twitter:card"]')); } },
    { name: "twitter:title", soft: true, levelWhenMissing: "warn", test: function () { return Boolean(document.querySelector('meta[name="twitter:title"]')); } },
    {
      name: "twitter:description",
      soft: true,
      levelWhenMissing: "warn",
      test: function () { return Boolean(document.querySelector('meta[name="twitter:description"]')); }
    },
    { name: "twitter:image", soft: true, levelWhenMissing: "warn", test: function () { return Boolean(document.querySelector('meta[name="twitter:image"]')); } },
    { name: "twitter:image:alt", soft: true, levelWhenMissing: "tip", test: function () { return Boolean(document.querySelector('meta[name="twitter:image:alt"]')); } },
    {
      // Google discovers sitemaps via robots.txt / Search Console — HTML link is optional convenience.
      name: "sitemap link",
      soft: true,
      levelWhenMissing: "tip",
      detailWhenMissing: "Optional HTML discovery. Prefer robots.txt Sitemap: (Google Search Central).",
      test: function () { return Boolean(document.querySelector('link[rel="sitemap"][href]')); }
    },
    {
      // Google favicon docs: any supported rel=icon / shortcut icon / apple-touch-icon is enough.
      // Do not require both SVG and PNG 32x32.
      name: "favicon",
      soft: true,
      levelWhenMissing: "warn",
      detailWhenMissing: "Add <link rel=\"icon\" href=\"...\"> for SERP branding (Google favicon guidelines).",
      test: function () {
        return Boolean(document.querySelector(
          'link[rel="icon"][href], link[rel="shortcut icon"][href], link[rel="apple-touch-icon"][href]'
        ));
      }
    },
    {
      name: "apple-touch-icon",
      soft: true,
      levelWhenMissing: "tip",
      test: function () { return Boolean(document.querySelector('link[rel="apple-touch-icon"][href]')); }
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
      focus: ["YandexBot", "发现链", "canonical", "description", "Schema 子集"]
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
      focus: ["SeznamBot", "robots", "绝对 Sitemap URL", "canonical", "结构化数据"]
    },
    {
      id: "sogou",
      name: "Sogou",
      label: "Sogou",
      userAgents: ["Sogou web spider", "Sogou inst spider"],
      focus: ["Sogou spider", "robots", "meta robots", "提交质量", "低质 URL 风险"]
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
    "本地做不了、可先忽略：真实 CrUX / Core Web Vitals 场站分、GSC 收录与覆盖、外链画像。这些要站长后台或第三方 API，本面板无法本地验真。",
    "浏览器内检测只能读取当前渲染 DOM；跨域 robots.txt、HTTP headers、X-Robots-Tag、证书详情、重定向链和真实状态码仍可能不完整。",
    "同源会额外探测 /robots.txt 的 Sitemap: 与 /sitemap.xml 是否可达；仍不能证明全站 sitemap URL 质量、重复 TDK、孤岛页、点击深度或真实收录状态。",
    "本地 Performance 仅为估算（传输体积/资源数），不作收录硬失败；上线再用 PageSpeed / CrUX / Search Console 验真即可。"
  ];

  /** Local-first score guidance shown above the four health cards. */
  var LOCAL_SCORE_FOCUS = {
    title: "本地优先看什么",
    lead: "本机验收重点修「站内可控」信号；CrUX、GSC 收录、外链本地做不了，不必纠结。",
    primary: [
      { key: "indexability", label: "可收录", why: "robots / canonical / 无 noindex" },
      { key: "understandability", label: "可理解性", why: "title、描述、H1、正文、结构化数据" },
      { key: "experience", label: "体验", why: "图片 alt、标题层级、明显页面问题" }
    ],
    reference: [
      { key: "engineFit", label: "引擎适配", why: "矩阵参考分；其中性能多为本机估算，不等于 CrUX" },
      { key: "eeat", label: "Google 自测", why: "Helpful Content Who/How/Trust 内部可验证项；非 E-E-A-T 排名门槛" }
    ]
  };

  /**
   * Browser matrix rules must cite official docs. Severity/score are Weline UX only.
   * browserTestable=false → never hard-fail from DOM alone; leave external validation note.
   */
  var SEARCH_ENGINE_RULE_CATALOG = [
    {
      id: "META_ROBOTS_001_NOINDEX",
      row: "indexability",
      engines: ["*"],
      officialUrls: ["https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag"],
      signal: "meta[name=robots] contains noindex|none",
      severity: "critical",
      browserTestable: true
    },
    {
      id: "SITEMAP_DISCOVERY_ROBOTS_TXT",
      row: "sitemap",
      engines: ["*"],
      officialUrls: ["https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap"],
      signal: "robots.txt Sitemap: or webmaster console submit (not HTML link rel=sitemap)",
      severity: "info",
      browserTestable: false,
      notes: "HTML <link rel=sitemap> is optional convenience only; never an Indexability fail."
    },
    {
      id: "FAVICON_SERP_OPTIONAL",
      row: "engine_specific",
      engines: ["google"],
      officialUrls: ["https://developers.google.com/search/docs/appearance/favicon-in-search"],
      signal: "link[rel=icon|shortcut icon|apple-touch-icon] (ICO/PNG/GIF/JPEG/BMP/...)",
      severity: "info",
      browserTestable: true,
      notes: "SERP branding only; not an indexing gate. Do not require SVG specifically."
    },
    {
      id: "CANONICAL_ABSOLUTE",
      row: "canonical",
      engines: ["*"],
      officialUrls: ["https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls"],
      signal: "single absolute canonical URL",
      severity: "high",
      browserTestable: true
    },
    {
      id: "MOBILE_VIEWPORT",
      row: "mobile",
      engines: ["google", "bing", "baidu"],
      officialUrls: [
        "https://developers.google.com/search/docs/crawling-indexing/mobile/mobile-sites-mobile-first-indexing",
        "https://ziyuan.baidu.com/wiki/3519"
      ],
      signal: "meta name=viewport present",
      severity: "high",
      browserTestable: true
    },
    {
      id: "STRUCTURED_DATA_OPTIONAL",
      row: "structured_data",
      engines: ["*"],
      officialUrls: ["https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data"],
      signal: "JSON-LD optional for indexing; invalid markup may be ignored",
      severity: "medium",
      browserTestable: true,
      notes: "Parse error = fail; missing types = enhancement warn only."
    },
    {
      id: "GOOGLE_CWV_EXTERNAL",
      row: "performance",
      engines: ["google"],
      officialUrls: ["https://developers.google.com/search/docs/appearance/core-web-vitals"],
      signal: "LCP/INP/CLS via CrUX/Search Console (browser estimate only)",
      severity: "info",
      browserTestable: false,
      notes: "Local browser cannot verify CrUX/GSC; explain only, never deduct engineFit."
    },
    {
      id: "BING_WEBMASTER_INDEXNOW",
      row: "engine_specific",
      engines: ["bing", "yahoo", "yandex", "seznam", "naver"],
      officialUrls: [
        "https://www.bing.com/webmasters/help/webmaster-guidelines-30fba23a",
        "https://www.indexnow.org/documentation.html"
      ],
      signal: "IndexNow key + Bing Webmaster / participating engines (server/API)",
      severity: "info",
      browserTestable: false
    },
    {
      id: "BAIDU_MOBILE_LANDING",
      row: "mobile",
      engines: ["baidu"],
      officialUrls: [
        "https://ziyuan.baidu.com/college/documentinfo?id=2315&page=3",
        "https://ziyuan.baidu.com/wiki/3519"
      ],
      signal: "mobile landing experience + viewport",
      severity: "high",
      browserTestable: true
    },
    {
      id: "BAIDU_THIN_CONTENT_HEURISTIC",
      row: "content_spam",
      engines: ["baidu"],
      officialUrls: ["https://ziyuan.baidu.com/college/documentinfo?id=2315&page=3"],
      signal: "qualitative 空短页 risk; Weline visibleText heuristic is not an official quota",
      severity: "medium",
      browserTestable: true
    },
    {
      id: "YANDEX_SCHEMA_LIMITED",
      row: "structured_data",
      engines: ["yandex"],
      officialUrls: [
        "https://yandex.com/support/webmaster/en/schema-org/what-is-schema-org",
        "https://yandex.com/support/webmaster/en/schema-org/semantic-faq"
      ],
      signal: "Yandex processes a limited Schema.org subset; unsupported types are skipped",
      severity: "info",
      browserTestable: true,
      notes: "Do not treat BreadcrumbList as a Yandex hard requirement."
    },
    {
      id: "YAHOO_VIA_BING",
      row: "engine_specific",
      engines: ["yahoo"],
      officialUrls: ["https://www.bing.com/webmasters/help/webmaster-guidelines-30fba23a"],
      signal: "Yahoo web results largely inherit Bing quality / crawl signals",
      severity: "info",
      browserTestable: false
    },
    {
      id: "DDG_SOURCES",
      row: "engine_specific",
      engines: ["duckduckgo"],
      officialUrls: ["https://duckduckgo.com/duckduckgo-help-pages/results/sources/"],
      signal: "DuckDuckGo blends sources including Bing; keep pages crawlable/indexable",
      severity: "info",
      browserTestable: false,
      notes: "Organization JSON-LD is not an official DDG hard rule."
    },
    {
      id: "EQ_PARTNER_INDEX",
      row: "engine_specific",
      engines: ["ecosia_qwant"],
      officialUrls: ["https://help.qwant.com/docs/search/divers/comment-referencer-mon-site-sur-qwant/"],
      signal: "Ecosia/Qwant rely on partner indexes; no privacy-page SEO hard rule",
      severity: "info",
      browserTestable: false
    },
    {
      id: "SEZNAM_SITEMAP_ABSOLUTE",
      row: "sitemap",
      engines: ["seznam"],
      officialUrls: ["https://www.indexnow.org/documentation.html"],
      signal: "Prefer absolute sitemap URL in robots.txt / IndexNow participation",
      severity: "info",
      browserTestable: false
    }
  ];

  function catalogRulesForEngine(engineId, rowId) {
    return SEARCH_ENGINE_RULE_CATALOG.filter(function (rule) {
      if (rowId && rule.row !== rowId) return false;
      var engines = rule.engines || [];
      return engines.indexOf("*") !== -1 || engines.indexOf(engineId) !== -1;
    });
  }

  function catalogUrls(ruleIds) {
    var urls = [];
    (ruleIds || []).forEach(function (id) {
      var rule = SEARCH_ENGINE_RULE_CATALOG.find(function (item) { return item.id === id; });
      if (!rule) return;
      (rule.officialUrls || []).forEach(function (url) {
        if (urls.indexOf(url) === -1) urls.push(url);
      });
    });
    return urls;
  }

  var latestSitemapProbe = window.__WELINE_SEO_SITEMAP_PROBE__ || null;
  var sitemapProbeInflight = null;

  function ensureSitemapProbe(forceRefresh) {
    if (!forceRefresh && latestSitemapProbe && (Date.now() - (latestSitemapProbe.at || 0)) < 60000) {
      return Promise.resolve(latestSitemapProbe);
    }
    if (sitemapProbeInflight) return sitemapProbeInflight;
    var origin = window.location.origin;
    var fetchText = function (url) {
      return fetch(url, { method: "GET", credentials: "same-origin", cache: "no-store" })
        .then(function (response) {
          if (!response.ok) {
            return { ok: false, status: response.status, text: "" };
          }
          return response.text().then(function (text) {
            return { ok: true, status: response.status, text: text || "" };
          });
        })
        .catch(function (error) {
          return { ok: false, status: 0, text: "", error: String((error && error.message) || error || "fetch failed") };
        });
    };
    sitemapProbeInflight = Promise.all([
      fetchText(origin + "/robots.txt"),
      fetchText(origin + "/sitemap.xml")
    ]).then(function (pair) {
      var robots = pair[0];
      var sitemap = pair[1];
      var robotsSitemapUrls = [];
      String(robots.text || "").split(/\r?\n/).forEach(function (line) {
        var match = line.match(/^\s*Sitemap:\s*(\S+)/i);
        if (match && match[1]) robotsSitemapUrls.push(match[1]);
      });
      var looksXml = /<(?:sitemapindex|urlset)[\s>]/i.test(sitemap.text || "");
      latestSitemapProbe = {
        at: Date.now(),
        robotsOk: Boolean(robots.ok),
        robotsStatus: robots.status,
        robotsSitemapUrls: robotsSitemapUrls,
        sitemapOk: Boolean(sitemap.ok && looksXml),
        sitemapStatus: sitemap.status,
        sitemapLooksXml: looksXml,
        sitemapBytes: (sitemap.text || "").length
      };
      window.__WELINE_SEO_SITEMAP_PROBE__ = latestSitemapProbe;
      sitemapProbeInflight = null;
      return latestSitemapProbe;
    });
    return sitemapProbeInflight;
  }

  function refreshReportAfterSitemapProbe() {
    return ensureSitemapProbe(false).then(function () {
      if (typeof window.__WELINE_SEO_INSPECTOR__ !== "undefined" && window.__WELINE_SEO_INSPECTOR__.publish) {
        window.__WELINE_SEO_INSPECTOR__.publish();
      }
      return latestSitemapProbe;
    });
  }


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

  /** page-type 事实别名 → inspector 规则键（policy/accessibility → legal） */
  function aliasSeoType(value) {
    var normalized = normalizeSeoType(value);
    return PAGE_JSONLD_RULE_ALIASES[normalized] || normalized;
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
    var seen = [];

    function pushNode(node) {
      if (!node) return;
      if (Array.isArray(node)) {
        node.forEach(pushNode);
        return;
      }
      if (typeof node !== "object") return;
      if (seen.indexOf(node) !== -1) return;
      seen.push(node);
      nodes.push(node);
      if (Array.isArray(node["@graph"])) {
        node["@graph"].forEach(pushNode);
      }
      // Product / ProductGroup embed reviews nested under review|reviews — surface them
      // so local rich-result cards can validate Review + AggregateRating alignment.
      if (node.review) pushNode(node.review);
      if (node.reviews) pushNode(node.reviews);
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
    return PAGE_JSONLD_RULES[key] || PAGE_JSONLD_RULES.web_page;
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
    if (img.getAttribute("aria-hidden") === "true" ||
      role === "presentation" ||
      role === "none") {
      return true;
    }
    // Empty alt is decorative by HTML convention — except content photo slots where empty alt is a real SEO miss.
    if (alt === "") {
      if (img.closest(
        ".product-native-detail__description-body, [data-testid='product-description-body'], " +
        ".product-native-detail__primary-image, .product-native-detail__stage, " +
        "article .entry-content, .cms-content, [data-seo-content-image]"
      )) {
        return false;
      }
      // Labeled thumb/control chrome: empty alt avoids double announcement.
      if (img.closest("button[aria-label], a[aria-label], [role='tab'][aria-label]")) {
        return true;
      }
      return true;
    }
    return false;
  }

  /** Empty alt = decorative (handled above). Useful content alt: CJK ≥2 chars, Latin ≥8. */
  function hasUsefulImageAlt(alt) {
    var text = String(alt || "").trim();
    if (!text) return false;
    if (/[\u3400-\u9fff]/.test(text)) return text.length >= 2;
    return text.length >= 8;
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
      if (isIgnoredSeoAuditNode(node)) return false;
      // Chrome chrome (header/footer/nav/dialogs/drawers) must not drive page outline SEO.
      if (node.closest(
        "header, footer, nav, [role='navigation'], [role='dialog'], [role='alertdialog'], " +
        "[data-w-component='drawer'], .w-drawer, [data-b2b-apply-drawer], [data-product-quote-modal]"
      )) return false;
      return true;
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
    var live = window.__WELINE_SEO_LIVE_CWV__ || {};
    if (live.lcp != null && (lcp === null || live.lcp > lcp)) {
      lcp = usableNumber(live.lcp);
    }
    if (live.cls != null) {
      cls = usableNumber(live.cls);
    }
    var inp = live.inp != null ? usableNumber(live.inp) : null;

    return {
      available: Boolean(nav || timing || paints.length || resources.length || live.lcp != null || live.cls != null || live.inp != null),
      hasNavigationTiming: Boolean(nav || timing),
      hasPaintTiming: Boolean(paints.length),
      hasLcp: lcp !== null,
      hasCls: cls !== null,
      hasInp: inp !== null,
      hasLongTasks: longTaskTotal !== null,
      samplingNote: live.started
        ? "面板打开后短时 PerformanceObserver 采样（非完整导航 CrUX）"
        : "仅读取打开时已有 Performance buffer",
      ttfb: usableNumber(ttfb),
      domContentLoaded: usableNumber(dcl),
      load: usableNumber(load),
      fcp: fcp,
      lcp: lcp,
      cls: cls,
      inp: inp,
      longTaskTotal: longTaskTotal,
      resourceCount: resources.length,
      scriptCount: scriptCount,
      stylesheetCount: stylesheetCount,
      imageCount: imageCount,
      transferKb: transferBytes ? Math.round(transferBytes / 1024) : null
    };
  }

  function startLiveCwvObservers() {
    if (window.__WELINE_SEO_LIVE_CWV__ && window.__WELINE_SEO_LIVE_CWV__.started) {
      return;
    }
    if (typeof PerformanceObserver !== "function") {
      window.__WELINE_SEO_LIVE_CWV__ = { started: false };
      return;
    }
    var state = { started: true, lcp: null, cls: 0, inp: null };
    window.__WELINE_SEO_LIVE_CWV__ = state;
    try {
      var lcpObserver = new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        if (!entries.length) return;
        var last = entries[entries.length - 1];
        state.lcp = usableNumber(last.renderTime || last.loadTime || last.startTime);
      });
      lcpObserver.observe({ type: "largest-contentful-paint", buffered: true });
    } catch (e) {}
    try {
      var clsObserver = new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) {
          if (!entry.hadRecentInput) {
            state.cls += usableNumber(entry.value) || 0;
          }
        });
      });
      clsObserver.observe({ type: "layout-shift", buffered: true });
    } catch (e2) {}
    try {
      var inpObserver = new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) {
          var delay = usableNumber(entry.duration);
          if (delay === null) return;
          if (state.inp === null || delay > state.inp) {
            state.inp = delay;
          }
        });
      });
      inpObserver.observe({ type: "event", buffered: true, durationThreshold: 16 });
    } catch (e3) {
      try {
        var fidObserver = new PerformanceObserver(function (list) {
          var first = list.getEntries()[0];
          if (!first) return;
          state.inp = usableNumber(first.processingStart - first.startTime);
        });
        fidObserver.observe({ type: "first-input", buffered: true });
      } catch (e4) {}
    }
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
      "INP " + formatMs(perf.inp),
      "Load " + formatMs(perf.load),
      "资源 " + perf.resourceCount + " 个"
    ];
    if (perf.transferKb !== null) parts.push("传输 " + perf.transferKb + "KB");
    if (perf.longTaskTotal !== null) parts.push("长任务 " + formatMs(perf.longTaskTotal));
    if (perf.samplingNote) parts.push(perf.samplingNote);
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
      return localExternalInfoRow(
        "performance",
        "【本地无法验真 · 不扣分】当前页未暴露完整 Performance Timing；真实 Google CWV（CrUX / Search Console / PageSpeed）本地域名测不了，不扣引擎适配分。",
        "生产发布前补跑 Lighthouse / PageSpeed Insights / CrUX。",
        "PERF_000_TIMING_UNAVAILABLE_INFO",
        catalogUrls(["GOOGLE_CWV_EXTERNAL"])
      );
    }

    var issues = { fail: [], warn: [] };
    addMetricIssue(issues, "TTFB", perf.ttfb, 800, 1800);
    addMetricIssue(issues, "FCP", perf.fcp, 1800, 3000);
    addMetricIssue(issues, "LCP", perf.lcp, 2500, 4000);
    addMetricIssue(issues, "INP", perf.inp, 200, 500);
    addMetricIssue(issues, "Load", perf.load, 3000, 6000);
    addMetricIssue(issues, "CLS", perf.cls, 0.1, 0.25, "score");
    addMetricIssue(issues, "长任务", perf.longTaskTotal, 200, 600);
    if (perf.resourceCount > 120) issues.warn.push("资源数 " + perf.resourceCount + " 个");
    else if (perf.resourceCount > 80) issues.warn.push("资源数 " + perf.resourceCount + " 个");
    if (perf.transferKb !== null) {
      // Transfer size alone is not a Google CWV hard fail; keep as warn when timing is healthy.
      if (perf.transferKb > 1500) issues.warn.push("传输 " + perf.transferKb + "KB");
    }

    var missingCwv = [];
    if (!perf.hasLcp) missingCwv.push("LCP");
    if (!perf.hasCls) missingCwv.push("CLS");
    if (!perf.hasInp) missingCwv.push("INP");
    var summary = performanceSummary(perf);

    if (issues.fail.length) {
      return localExternalInfoRow(
        "performance",
        "【本地无法验真 · 不扣分】本机 Performance 估算偏慢：" +
          issues.fail.join("；") +
          "。采样：" +
          summary +
          "。真实 Google Core Web Vitals（LCP/INP/CLS）来自 CrUX / Search Console / PageSpeed，本地域名测不了，故不扣引擎适配分。",
        "可参考压缩关键 JS/CSS/图片；验真请用 Lighthouse、PageSpeed Insights 或 Search Console（生产域名）。",
        "PERF_001_BROWSER_TIMING_LOCAL_INFO",
        catalogUrls(["GOOGLE_CWV_EXTERNAL"])
      );
    }

    if (issues.warn.length) {
      return localExternalInfoRow(
        "performance",
        "【本地无法验真 · 不扣分】本机性能估算有优化空间：" +
          issues.warn.join("；") +
          "。采样：" +
          summary +
          "。真实 CWV 需 CrUX / Search Console / PageSpeed；本面板只做本地估算说明，不代替站长后台，也不扣分。",
        "可参考压缩首包/图片/阻塞资源；上线后用 Lighthouse / PageSpeed / Search Console 验真。",
        "PERF_010_CWV_EXTERNAL_INFO",
        catalogUrls(["GOOGLE_CWV_EXTERNAL"])
      );
    }

    if (missingCwv.length) {
      return localExternalInfoRow(
        "performance",
        "【本地无法验真 · 不扣分】本地 timing 未发现明显慢指标，但未捕获 " +
          missingCwv.join("/") +
          "（早期采样缺口）。真实场站级 CWV 仍需 CrUX / Search Console。采样：" +
          summary +
          "。",
        "上线前建议用 Lighthouse / PageSpeed / CrUX 补齐真实 CWV。",
        "PERF_011_CWV_SAMPLE_GAP_INFO",
        catalogUrls(["GOOGLE_CWV_EXTERNAL"])
      );
    }

    return localExternalInfoRow(
      "performance",
      "【本地无法验真 · 不扣分】本机 timing 未见明显性能风险。采样：" +
        summary +
        "。Google 真实移动端 CWV 仍须生产域名 + CrUX / PageSpeed 验真，本地不扣分。",
      "上线后用真实网络与 CrUX / PageSpeed 校验移动端 CWV。",
      "PERF_012_CWV_LOCAL_OK_INFO",
      catalogUrls(["GOOGLE_CWV_EXTERNAL"])
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

  function engineRow(id, status, severity, detail, recommendation, issueId, officialUrls) {
    return {
      id: id,
      label: (ENGINE_MATRIX_ROWS.find(function (row) { return row.id === id; }) || {}).label || id,
      status: status,
      severity: severity || (status === "fail" ? "high" : status === "warn" ? "medium" : "info"),
      detail: detail,
      recommendation: recommendation || "",
      issueId: issueId || "",
      officialUrls: Array.isArray(officialUrls) ? officialUrls : [],
      scoringExempt: false,
      noticeKind: ""
    };
  }

  /** 本地浏览器无法验真的外部项：醒目说明，绝不计入引擎适配扣分。 */
  function localExternalInfoRow(id, detail, recommendation, issueId, officialUrls) {
    var row = engineRow(
      id,
      "info",
      "info",
      detail,
      recommendation || "上线后用站长工具 / CrUX / PageSpeed 验真；本面板本地模式不因此扣分。",
      issueId || "",
      officialUrls
    );
    row.scoringExempt = true;
    row.noticeKind = "local_external";
    return row;
  }

  function externalValidationRow(detail, recommendation, officialUrls) {
    return localExternalInfoRow(
      "engine_specific",
      "【本地无法验真 · 不扣分】" + detail,
      recommendation || "使用服务端爬虫、平台站长工具或真实用户数据补充验证。",
      "ENGINE_EXTERNAL_VALIDATION_INFO",
      officialUrls
    );
  }

  function engineStatus(rows) {
    var list = Object.keys(rows || {}).map(function (key) { return rows[key]; });
    if (list.some(function (row) { return row && row.status === "fail" && !row.scoringExempt; })) return "fail";
    if (list.some(function (row) { return row && row.status === "warn" && !row.scoringExempt; })) return "warning";
    if (list.some(function (row) { return row && row.status === "unknown" && !row.scoringExempt; })) return "unknown";
    return "pass";
  }

  function engineScore(rows) {
    var score = 100;
    Object.keys(rows || {}).forEach(function (key) {
      var row = rows[key];
      if (!row || row.scoringExempt) return;
      if (row.status === "fail") score -= row.severity === "critical" ? 36 : 28;
      else if (row.status === "warn") score -= row.severity === "high" ? 18 : 12;
      else if (row.status === "unknown") score -= 4;
    });
    return Math.max(0, Math.min(100, score));
  }

  function engineRecommendations(rows) {
    return Object.keys(rows || {})
      .map(function (key) { return rows[key]; })
      .filter(function (row) {
        return row && row.recommendation && row.status !== "pass" && row.status !== "info" && !row.scoringExempt;
      })
      .map(function (row) { return row.recommendation; })
      .filter(function (value, index, list) { return list.indexOf(value) === index; })
      .slice(0, 5);
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
      sitemapProbe: latestSitemapProbe,
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

  function buildEngineRows(engine, raw, signals) {
    var rows = {};
    var engineId = engine.id;
    var mobileStrict = ["google", "bing", "baidu"].indexOf(engineId) !== -1;
    var schemaStrict = ["google", "bing"].indexOf(engineId) !== -1;

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
        "修复浏览器可见阻断后，再使用服务端爬虫按目标 User-Agent（" + (engine.userAgents || []).join(", ") + "）验证 robots.txt、HTTP 状态码与重定向链。",
        "CRAWL_001_BROWSER_VISIBLE_RISK",
        catalogUrls(["META_ROBOTS_001_NOINDEX"])
      );
    } else {
      rows.crawlability = engineRow(
        "crawlability",
        "pass",
        "info",
        "当前页面已成功加载并渲染；未发现 noindex / JS redirect / hash route。目标爬虫 " + (engine.userAgents || []).join(", ") + " 的 robots.txt 仍需服务端验证。",
        "服务端爬虫按该引擎 User-Agent 补查 robots.txt 与 HTTP 状态。"
      );
    }

    if (signals.noindex || hasCheckLevel(raw, "indexability", "fail")) {
      rows.indexability = engineRow(
        "indexability",
        "fail",
        "critical",
        "页面 robots meta 包含 noindex 或索引阻断项。",
        "若应收录，移除 noindex，并确认 X-Robots-Tag / robots.txt 未阻断目标引擎。",
        "META_ROBOTS_001_NOINDEX",
        catalogUrls(["META_ROBOTS_001_NOINDEX"])
      );
    } else {
      rows.indexability = engineRow(
        "indexability",
        "pass",
        "info",
        "页面未声明 noindex（缺省等同可索引）；HTTP header 与 robots.txt 仍需服务端验证。",
        "服务端补查 X-Robots-Tag 与目标引擎 robots 规则。",
        "",
        catalogUrls(["META_ROBOTS_001_NOINDEX"])
      );
    }

    if (!signals.canonical || hasAnyCheckLevel(raw, ["single canonical", "canonical host", "canonical path", "canonical scheme"], "fail")) {
      rows.canonical = engineRow(
        "canonical",
        "fail",
        "high",
        "canonical 缺失、重复，或与当前规范 URL 不一致。",
        "保留唯一绝对 canonical，并让 og:url、hreflang 与之对齐。",
        "CANONICAL_001_CANONICAL_INVALID",
        catalogUrls(["CANONICAL_ABSOLUTE"])
      );
    } else if (!signals.canonicalAbsolute) {
      rows.canonical = engineRow(
        "canonical",
        engineId === "naver" ? "fail" : "warn",
        "high",
        "canonical 不是绝对 URL。",
        "使用 https:// 绝对地址作为 canonical。",
        "CANONICAL_002_NOT_ABSOLUTE",
        catalogUrls(["CANONICAL_ABSOLUTE"])
      );
    } else if (!signals.canonicalHttps) {
      rows.canonical = engineRow(
        "canonical",
        "warn",
        "medium",
        "canonical 非 HTTPS。官方推荐 HTTPS 规范 URL，但非一律不可索引。",
        "迁移到 HTTPS canonical，并同步 og:url / hreflang。",
        "CANONICAL_003_NOT_HTTPS",
        catalogUrls(["CANONICAL_ABSOLUTE"])
      );
    } else {
      rows.canonical = engineRow(
        "canonical",
        "pass",
        "info",
        "canonical 为唯一 HTTPS 绝对 URL（浏览器 DOM）。",
        "服务端可继续验证目标是否 200、可索引且非 redirect。"
      );
    }

    var probe = signals.sitemapProbe || latestSitemapProbe;
    var robotsSitemapUrls = (probe && probe.robotsSitemapUrls) || [];
    var probeReady = Boolean(probe);
    var discoveryOk = Boolean((probe && (probe.sitemapOk || robotsSitemapUrls.length)) || signals.sitemapHref);
    if (signals.sitemapHref && !signals.sitemapRootOrAbsolute) {
      rows.sitemap = engineRow(
        "sitemap",
        "warn",
        "medium",
        "页面 HTML sitemap link 不是绝对或根相对 URL。",
        "官方发现主路径是 robots.txt Sitemap:；若保留 HTML link，请用绝对 URL。",
        "SITEMAP_002_BAD_URL",
        catalogUrls(["SITEMAP_DISCOVERY_ROBOTS_TXT", "SEZNAM_SITEMAP_ABSOLUTE"])
      );
    } else if (discoveryOk) {
      rows.sitemap = engineRow(
        "sitemap",
        "pass",
        "info",
        "Sitemap 发现链已确认：" + [
          robotsSitemapUrls.length ? ("robots.txt Sitemap: " + robotsSitemapUrls.join(", ")) : "",
          probe && probe.sitemapOk ? ("/sitemap.xml HTTP " + probe.sitemapStatus + " · " + probe.sitemapBytes + "B") : "",
          signals.sitemapHref ? ("HTML link " + signals.sitemapHref) : ""
        ].filter(Boolean).join("；") + "。全站 URL 质量仍需服务端审计。",
        "继续用全站审计检查 XML 内 URL 是否 canonical/可索引；勿把「关注点含 sitemap」当成缺失。",
        "",
        catalogUrls(["SITEMAP_DISCOVERY_ROBOTS_TXT", "SEZNAM_SITEMAP_ABSOLUTE"])
      );
    } else if (!probeReady) {
      rows.sitemap = engineRow(
        "sitemap",
        "pass",
        "info",
        "同源 robots.txt / sitemap.xml 探测进行中；HTML link " + (signals.sitemapHref || "未声明（可选）") + "。",
        "等待同源探测完成；官方主路径仍是 robots.txt Sitemap:。",
        "",
        catalogUrls(["SITEMAP_DISCOVERY_ROBOTS_TXT"])
      );
    } else {
      rows.sitemap = engineRow(
        "sitemap",
        "warn",
        "medium",
        "同源未读到 robots.txt Sitemap:，且 /sitemap.xml 不可用或非 sitemap XML。",
        "在 robots.txt 添加 Sitemap: https://…/sitemap.xml，并确保 XML 可访问。",
        "SITEMAP_DISCOVERY_ROBOTS_TXT",
        catalogUrls(["SITEMAP_DISCOVERY_ROBOTS_TXT"])
      );
    }

    if (signals.jsonTypes.indexOf("INVALID_JSON") !== -1 || hasCheckLevel(raw, "JSON-LD parse", "fail")) {
      rows.structured_data = engineRow(
        "structured_data",
        "fail",
        "high",
        "JSON-LD 解析失败。",
        "修复 JSON-LD 语法，并保持与可见内容一致。",
        "SD_001_JSON_PARSE_ERROR",
        catalogUrls(["STRUCTURED_DATA_OPTIONAL", "YANDEX_SCHEMA_LIMITED"])
      );
    } else if (signals.jsonLdValidation && signals.jsonLdValidation.status === "fail") {
      rows.structured_data = engineRow(
        "structured_data",
        schemaStrict ? "warn" : "pass",
        "medium",
        "页面类型结构化数据不完整（增强项，非索引硬门槛）：" + [
          signals.jsonLdValidation.missingTypes.length ? "缺少类型 " + signals.jsonLdValidation.missingTypes.join(", ") : "",
          signals.jsonLdValidation.missingFields.length ? "缺少字段 " + signals.jsonLdValidation.missingFields.join(", ") : ""
        ].filter(Boolean).join("；") + "。",
        "按页面类型补齐 schema；Google/Bing 文档写明结构化数据不保证排名。",
        "SD_004_PAGE_TYPE_SCHEMA_INVALID",
        catalogUrls(["STRUCTURED_DATA_OPTIONAL"])
      );
    } else if (!signals.jsonTypes.length) {
      rows.structured_data = engineRow(
        "structured_data",
        schemaStrict ? "warn" : "pass",
        "medium",
        schemaStrict
          ? "未发现 JSON-LD（富结果增强缺失，不代表不可索引）。"
          : "未发现 JSON-LD；对本引擎浏览器模式不作为失败项（Yandex 等仅支持有限 Schema 子集）。",
        "按需补充与可见内容一致的 schema；勿把缺省 JSON-LD 当收录失败。",
        "SD_003_TYPE_MISSING",
        catalogUrls(["STRUCTURED_DATA_OPTIONAL", "YANDEX_SCHEMA_LIMITED"])
      );
    } else if (signals.jsonLdValidation && signals.jsonLdValidation.status === "warn") {
      rows.structured_data = engineRow(
        "structured_data",
        "pass",
        "info",
        "结构化数据可读；推荐字段可再补：" + (signals.jsonLdValidation.missingRecommended || []).join(", ") + "。",
        "补齐推荐字段仅影响富结果理解，不作为索引阻断。",
        "SD_005_PAGE_TYPE_SCHEMA_RECOMMENDED_MISSING",
        catalogUrls(["STRUCTURED_DATA_OPTIONAL"])
      );
    } else {
      rows.structured_data = engineRow(
        "structured_data",
        "pass",
        "info",
        "已解析 schema 类型：" + signals.jsonTypes.join(", ") + "。",
        "继续保持 schema 与可见内容一致。"
      );
    }

    if (!signals.viewport || hasCheckLevel(raw, "viewport", "fail")) {
      rows.mobile = engineRow(
        "mobile",
        mobileStrict ? "fail" : "warn",
        "high",
        "缺少移动端 viewport。",
        "添加 <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">。",
        "MOBILE_001_VIEWPORT_MISSING",
        catalogUrls(["MOBILE_VIEWPORT", "BAIDU_MOBILE_LANDING"])
      );
    } else {
      rows.mobile = engineRow(
        "mobile",
        "pass",
        "info",
        "viewport 存在；" + (engineId === "baidu" ? "百度移动落地页体验仍需服务端/真机验证。" : "浏览器模式未比较移动/桌面 HTML 差异。"),
        "服务端或自动化补查移动宽度、tap target 与内容一致性。",
        "",
        catalogUrls(engineId === "baidu" ? ["BAIDU_MOBILE_LANDING"] : ["MOBILE_VIEWPORT"])
      );
    }

    rows.performance = buildPerformanceRow(signals);
    if (engineId === "google" && rows.performance && rows.performance.noticeKind === "local_external") {
      rows.performance.detail =
        "Google：" +
        (rows.performance.detail || "【本地无法验真 · 不扣分】真实 CWV / CrUX / GSC 本地测不了。");
      rows.performance.issueId = rows.performance.issueId || "GOOGLE_020_CWV_EXTERNAL_INFO";
      rows.performance.officialUrls = catalogUrls(["GOOGLE_CWV_EXTERNAL"]);
    } else if (engineId === "google") {
      rows.performance.officialUrls = catalogUrls(["GOOGLE_CWV_EXTERNAL"]);
    }

    rows.content_spam = buildContentSpamRow(raw);
    if (engineId === "baidu" && signals.visibleText < SEO_TEXT_LIMITS.visibleTextMin && rows.content_spam.status === "pass") {
      rows.content_spam = engineRow(
        "content_spam",
        "warn",
        "medium",
        "可见正文偏薄（Weline 启发式 " + SEO_TEXT_LIMITS.visibleTextMin + "+ 字符）。百度文档讨论「空短页」是定性风险，并非公开固定字数门槛。",
        "补充真实功能、示例、FAQ 与落地价值；再用百度搜索资源平台质量反馈核对。",
        "BAIDU_THIN_CONTENT_HEURISTIC",
        catalogUrls(["BAIDU_THIN_CONTENT_HEURISTIC"])
      );
    }

    rows.engine_specific = buildEngineSpecificRow(engine, rows, raw, signals);
    return rows;
  }

  function buildEngineSpecificRow(engine, rows, raw, signals) {
    if (engine.id === "google") {
      if (rows.indexability.status === "fail" || rows.content_spam.status === "fail") {
        return engineRow(
          "engine_specific",
          "fail",
          "critical",
          "Google：索引阻断或 spam/低质信号未通过（Search Essentials）。",
          "先修复 noindex/spam，再处理 CWV 与富结果。",
          "GOOGLE_010_SPAM_POLICY_RISK",
          catalogUrls(["META_ROBOTS_001_NOINDEX"])
        );
      }
      if (rows.performance && rows.performance.noticeKind === "local_external") {
        return localExternalInfoRow(
          "engine_specific",
          "【本地无法验真 · 不扣分】Google 的 Lighthouse / PageSpeed Insights / CrUX / Search Console 覆盖与真实 Googlebot 渲染，本地域名测不了。下方「性能/CWV」仅为本机估算参考，不计入引擎适配扣分。",
          "生产域名上用 Search Console、PageSpeed Insights 或 CrUX 验真；本面板只醒目说明限制，不因此扣分。",
          "GOOGLE_020_CWV_EXTERNAL_INFO",
          catalogUrls(["GOOGLE_CWV_EXTERNAL"])
        );
      }
      var faviconOk = Boolean(document.querySelector(
        'link[rel="icon"][href], link[rel="shortcut icon"][href], link[rel="apple-touch-icon"][href]'
      ));
      if (!faviconOk) {
        return engineRow(
          "engine_specific",
          "pass",
          "info",
          "Google：缺 favicon 只影响 SERP 品牌图标资格，不是索引失败。",
          "按 Google favicon 指南提供任一支持的 rel=icon（勿强制 SVG）。",
          "FAVICON_SERP_OPTIONAL",
          catalogUrls(["FAVICON_SERP_OPTIONAL"])
        );
      }
      return externalValidationRow(
        "Google 基础 DOM 信号可读；Search Console 覆盖、真实 Googlebot 渲染、CWV/INP 仍需外部验证。",
        "补跑 Lighthouse/PageSpeed/CrUX/Search Console。",
        catalogUrls(["GOOGLE_CWV_EXTERNAL", "FAVICON_SERP_OPTIONAL"])
      );
    }

    if (engine.id === "bing") {
      if (rows.indexability.status === "fail") {
        return engineRow(
          "engine_specific",
          "fail",
          "high",
          "Bing：存在索引阻断。",
          "修复 noindex 后再接入 Bing Webmaster Tools / IndexNow。",
          "BING_001_INDEX_BLOCKED",
          catalogUrls(["META_ROBOTS_001_NOINDEX", "BING_WEBMASTER_INDEXNOW"])
        );
      }
      return externalValidationRow(
        "Bing 基础 DOM 信号可读；IndexNow key、提交记录与 bingbot 抓取需服务端/API 验证。",
        "配置 Bing Webmaster Tools / IndexNow。",
        catalogUrls(["BING_WEBMASTER_INDEXNOW"])
      );
    }

    if (engine.id === "yahoo") {
      return externalValidationRow(
        "Yahoo 网页结果 largely 依赖 Bing；浏览器模式按 Bing 基础信号评估，无独立硬规。",
        "保持 Bing profile / IndexNow 可用。",
        catalogUrls(["YAHOO_VIA_BING", "BING_WEBMASTER_INDEXNOW"])
      );
    }

    if (engine.id === "yandex") {
      return externalValidationRow(
        "Yandex 仅处理有限 Schema.org 子集；BreadcrumbList 不是 Yandex 硬性收录条件。YandexBot / 站长后台仍需外部验证。",
        "服务端检查 YandexBot robots、sitemap 与 Webmaster 区域设置。",
        catalogUrls(["YANDEX_SCHEMA_LIMITED", "BING_WEBMASTER_INDEXNOW"])
      );
    }

    if (engine.id === "baidu") {
      if (rows.mobile.status === "fail" || rows.content_spam.status === "fail") {
        return engineRow(
          "engine_specific",
          "fail",
          "high",
          "Baidu：移动体验或内容质量相关阻断（对照百度移动/空短页文档方向）。",
          "优先修复 viewport/移动落地页与低质内容，再检查 Baiduspider 与资源平台提交。",
          "BAIDU_002_MOBILE_UNFRIENDLY",
          catalogUrls(["BAIDU_MOBILE_LANDING", "BAIDU_THIN_CONTENT_HEURISTIC"])
        );
      }
      if (rows.content_spam.status === "warn" && rows.content_spam.issueId === "BAIDU_THIN_CONTENT_HEURISTIC") {
        return engineRow(
          "engine_specific",
          "warn",
          "medium",
          "Baidu：存在空短页启发式风险（非官方固定字数）。",
          "补充原创落地内容，并用百度搜索资源平台反馈核对。",
          "BAIDU_THIN_CONTENT_HEURISTIC",
          catalogUrls(["BAIDU_THIN_CONTENT_HEURISTIC"])
        );
      }
      return externalValidationRow(
        "Baidu DOM 基础项通过；Baiduspider、真实移动渲染与提交接口需外部验证。",
        "按 Baiduspider 服务端抓取，并检查搜索资源平台。",
        catalogUrls(["BAIDU_MOBILE_LANDING"])
      );
    }

    if (engine.id === "duckduckgo") {
      if (rows.indexability.status === "fail") {
        return engineRow(
          "engine_specific",
          "fail",
          "high",
          "DuckDuckGo：页面存在索引阻断；其结果源包含 Bing 等，需先可索引。",
          "先修复 indexability，再验证 DuckDuckBot / Bing 依赖。",
          "DDG_001_BLOCKED_DUCKDUCKBOT",
          catalogUrls(["DDG_SOURCES", "META_ROBOTS_001_NOINDEX"])
        );
      }
      return externalValidationRow(
        "DuckDuckGo 无独立 Organization 硬规；保持可抓取并与 Bing 基础信号一致。",
        "服务端验证 DuckDuckBot，并保持 Bing profile。",
        catalogUrls(["DDG_SOURCES"])
      );
    }

    if (engine.id === "naver") {
      if (!signals.canonicalAbsolute) {
        return engineRow(
          "engine_specific",
          "fail",
          "high",
          "Naver：canonical 须为绝对 URL（浏览器可测）。",
          "使用绝对 canonical；独立移动 URL 需映射并在 Search Advisor 验证。",
          "NAVER_002_CANONICAL_NOT_ABSOLUTE",
          catalogUrls(["CANONICAL_ABSOLUTE"])
        );
      }
      if (signals.jsRedirect) {
        return engineRow(
          "engine_specific",
          "warn",
          "medium",
          "检测到 JS redirect；更稳妥的是 HTTP 重定向（需服务端确认）。",
          "改用 HTTP redirect，避免仅靠 JS 跳转。",
          "NAVER_003_JS_REDIRECT",
          catalogUrls(["CANONICAL_ABSOLUTE"])
        );
      }
      return externalValidationRow(
        "Naver canonical 基础信号可读；Yeti robots 与 Search Advisor 状态需服务端验证。",
        "服务端按 Yeti 抓取并检查 Search Advisor。",
        catalogUrls(["BING_WEBMASTER_INDEXNOW"])
      );
    }

    if (engine.id === "seznam") {
      return externalValidationRow(
        "Seznam 基础 DOM 信号可读；绝对 Sitemap / SeznamBot / IndexNow 需服务端验证。",
        "在 robots.txt 声明绝对 Sitemap:，并检查 SeznamBot。",
        catalogUrls(["SEZNAM_SITEMAP_ABSOLUTE", "BING_WEBMASTER_INDEXNOW"])
      );
    }

    if (engine.id === "sogou") {
      if (rows.content_spam.status === "fail") {
        return engineRow(
          "engine_specific",
          "fail",
          "high",
          "Sogou：内容泄露/低质信号存在；sitemap 应只提交重要原创页（站长实践）。",
          "清理低质信号后再提交。",
          "SOGOU_006_CHEATING_OR_LOW_QUALITY"
        );
      }
      return externalValidationRow(
        "Sogou 基础 DOM 信号可读；robots 生效与 sitemap 限额需站长平台验证。",
        "检查 Sogou spider robots 与提交质量。"
      );
    }

    if (engine.id === "ecosia_qwant") {
      if (rows.indexability.status === "fail") {
        return engineRow(
          "engine_specific",
          "fail",
          "high",
          "Ecosia/Qwant 依赖合作方索引；当前存在索引阻断。",
          "先修复 Google/Bing 基础可索引性。",
          "EQ_001_BING_GOOGLE_BASELINE_FAIL",
          catalogUrls(["EQ_PARTNER_INDEX", "META_ROBOTS_001_NOINDEX"])
        );
      }
      return externalValidationRow(
        "Ecosia/Qwant 无「必须有隐私页」的官方 SEO 硬规；保持 Bing/Google 基础可发现性即可。",
        "检查合作方收录与多语言 hreflang。",
        catalogUrls(["EQ_PARTNER_INDEX"])
      );
    }

    return engineRow("engine_specific", "unknown", "info", "该平台的浏览器内专项规则尚无足够官方可测信号。", "使用服务端爬虫补充。");
  }

  function buildEngineMatrix(raw) {
    var signals = collectEngineSignals(raw);
    var matrix = {};
    ENGINE_PROFILES.forEach(function (engine) {
      var rows = buildEngineRows(engine, raw, signals);
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
        catalogRules: catalogRulesForEngine(engine.id),
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
        if (item && item.scoringExempt) return;
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
      legacyOverall: Math.max(0, Math.min(100, 100
        - fail.filter(function (item) { return !item.scoringExempt; }).length * 8
        - warn.filter(function (item) { return !item.scoringExempt; }).length * 3)),
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
      ruleCatalog: SEARCH_ENGINE_RULE_CATALOG,
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
      var parts = parsed.pathname.split("/").filter(Boolean);
      // Strip leading ISO currency codes (e.g. /EUR/en_US/products).
      while (parts.length && /^[A-Za-z]{3}$/.test(parts[0]) && parts[0].toUpperCase() === parts[0]) {
        parts.shift();
      }
      var first = parts[0] || "";
      if (!first) return "";
      var normalized = canonicalizeLanguageCode(first);
      if (knownCodes[normalized.toLowerCase()]) return normalized;
      if (first.indexOf("-") !== -1 && validLanguageCode(first)) return normalized;
      if (first.indexOf("_") !== -1 && validLanguageCode(first.replace(/_/g, "-"))) {
        return canonicalizeLanguageCode(first);
      }
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
      // Google Search does not use meta keywords for ranking; tip only, never fail score.
      add("info", "meta keywords", "No meta keywords tag (optional). Google ignores keywords meta for ranking.", "head");
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

  function eeatRuleApplies(rule, seoType) {
    var when = Array.isArray(rule.when) ? rule.when : ["all"];
    if (when.indexOf("all") !== -1) return true;
    var articleFamily = ["article", "blog", "news", "blog_post", "news_article", "post"];
    if (when.some(function (w) { return articleFamily.indexOf(w) !== -1; })
      && articleFamily.indexOf(seoType) !== -1) {
      return true;
    }
    return when.indexOf(seoType) !== -1;
  }

  function eeatIsAbsoluteUrl(value) {
    return typeof value === "string" && /^https?:\/\//i.test(value.trim());
  }

  function eeatLogoAbsolute(organization) {
    if (!organization || !organization.logo) return false;
    if (typeof organization.logo === "string") return eeatIsAbsoluteUrl(organization.logo);
    if (typeof organization.logo === "object") {
      var url = organization.logo.url || organization.logo.contentUrl || organization.logo["@id"] || "";
      return eeatIsAbsoluteUrl(String(url));
    }
    return false;
  }

  function eeatPersonAuthors(article) {
    if (!article) return [];
    var raw = article.author;
    var list = [];
    if (Array.isArray(raw)) list = raw;
    else if (raw) list = [raw];
    return list.filter(function (author) {
      if (!author || typeof author !== "object") return false;
      var types = jsonLdTypeList(author);
      if (types.length && !types.some(function (t) { return schemaTypeMatches(t, "Person"); })) {
        return false;
      }
      var name = flattenJsonLdText(author.name || "");
      return Boolean(name);
    });
  }

  function eeatAuthorIsOrgOnly(article, organization) {
    if (!article || !article.author) return true;
    var raw = article.author;
    var list = Array.isArray(raw) ? raw : [raw];
    if (!list.length) return true;
    var orgId = organization && organization["@id"] ? String(organization["@id"]) : "";
    return list.every(function (author) {
      if (!author) return true;
      if (typeof author === "string") return false;
      if (typeof author !== "object") return true;
      if (eeatPersonAuthors({ author: author }).length) return false;
      var id = author["@id"] ? String(author["@id"]) : "";
      if (orgId && id && id === orgId) return true;
      var types = jsonLdTypeList(author);
      return types.some(function (t) { return schemaTypeMatches(t, "Organization"); }) && !flattenJsonLdText(author.name || "");
    });
  }

  function eeatAuthorLacksIdentityUrl(person) {
    if (!person) return true;
    var hasUrl = eeatIsAbsoluteUrl(String(person.url || ""));
    var hasSameAs = Array.isArray(person.sameAs) && person.sameAs.some(function (u) { return eeatIsAbsoluteUrl(String(u || "")); });
    return !(hasUrl || hasSameAs);
  }

  /** @deprecated use eeatAuthorLacksIdentityUrl */
  function eeatAuthorIsShallow(person) {
    return eeatAuthorLacksIdentityUrl(person);
  }

  function eeatHasAboutContactInGraph(nodes) {
    return jsonLdNodesOfType(nodes, "AboutPage").length > 0
      || jsonLdNodesOfType(nodes, "ContactPage").length > 0;
  }

  function eeatCollectAboutContactAnchors() {
    return Array.from(document.querySelectorAll("a[href]")).filter(function (a) {
      if (isIgnoredSeoAuditNode(a)) return false;
      var href = String(a.getAttribute("href") || "").toLowerCase();
      var text = String(a.textContent || "").toLowerCase();
      return /about|contact|关于|联系|隐私|privacy/.test(href + " " + text);
    });
  }

  function eeatHasAboutContactNav() {
    return eeatCollectAboutContactAnchors().length > 0;
  }

  function eeatFindVisibleBylineName() {
    var selectors = [
      "[itemprop='author']",
      "[rel='author']",
      ".author",
      ".byline",
      ".post-author",
      ".article-author",
      ".amazon-blog-article__author-inline",
      "[data-author]",
      "[class*='author']"
    ];
    var candidates = [];
    selectors.forEach(function (sel) {
      Array.from(document.querySelectorAll(sel)).forEach(function (node) {
        if (isIgnoredSeoAuditNode(node)) return;
        var text = String(node.textContent || "").replace(/\s+/g, " ").trim();
        if (!text || text.length > 120) return;
        text = text.replace(/^(作者|Author|By|撰稿)\s*[:：]?\s*/i, "").trim();
        if (text) candidates.push(text);
      });
    });
    return candidates[0] || "";
  }

  function eeatNormalizePersonName(name) {
    return String(name || "")
      .replace(/\s+/g, " ")
      .trim()
      .toLowerCase();
  }

  function eeatNamesMatch(a, b) {
    var left = eeatNormalizePersonName(a);
    var right = eeatNormalizePersonName(b);
    if (!left || !right) return false;
    if (left === right) return true;
    return left.indexOf(right) !== -1 || right.indexOf(left) !== -1;
  }

  function eeatAuthorBackgroundReachable(persons) {
    var hasBio = Boolean(document.querySelector(".author-bio, .author-description, [data-author-bio], .amazon-blog-article__author-bio"));
    if (hasBio) return true;
    var pageText = String((document.body && document.body.innerText) || "");
    if (/作者简介|About the author|Author bio/i.test(pageText)) return true;
    return persons.some(function (p) {
      if (eeatIsAbsoluteUrl(String(p.url || ""))) return true;
      if (flattenJsonLdText(p.description || "")) return true;
      return false;
    });
  }

  function eeatPublisherLinked(article, organization) {
    if (!article || !article.publisher) return false;
    var pub = article.publisher;
    if (typeof pub === "string") return Boolean(pub);
    if (typeof pub !== "object") return false;
    if (flattenJsonLdText(pub.name || "")) return true;
    var pubId = pub["@id"] ? String(pub["@id"]) : "";
    var orgId = organization && organization["@id"] ? String(organization["@id"]) : "";
    if (pubId && orgId && pubId === orgId) return true;
    return jsonLdTypeList(pub).some(function (t) { return schemaTypeMatches(t, "Organization"); });
  }

  function eeatFindRule(id) {
    var resolved = EEAT_RULE_ID_ALIASES[id] || id;
    return EEAT_STRICT_RULES.find(function (r) { return r.id === resolved || r.id === id; }) || null;
  }

  /**
   * Google Helpful Content self-check (Who/How/Trust) — machine-verifiable only.
   * @param {{seoType?:string,visibleText?:number,contentImages?:number}} context
   * @param {function(string,string,string,string,Object=):void} add
   */
  function auditEeatStrict(context, add) {
    var seoType = normalizeSeoType(context && context.seoType ? context.seoType : inferSeoTypeFromPage());
    var nodes = collectJsonLdNodes();
    var organization = jsonLdNodesOfType(nodes, "Organization")[0] || null;
    var article = jsonLdNodesOfType(nodes, "Article")[0]
      || jsonLdNodesOfType(nodes, "BlogPosting")[0]
      || jsonLdNodesOfType(nodes, "NewsArticle")[0]
      || null;
    var reviews = jsonLdNodesOfType(nodes, "Review");
    var hasSameAs = Boolean(organization && Array.isArray(organization.sameAs) && organization.sameAs.length);
    var articleFamily = ["article", "blog", "news", "blog_post", "news_article", "post"];
    var isArticlePage = articleFamily.indexOf(seoType) !== -1 || Boolean(article);
    var isHome = seoType === "home";

    function pushRule(rule, pass, detail) {
      if (!rule) return;
      var applies = eeatRuleApplies(rule, seoType);
      if (!applies && isArticlePage && rule.when.some(function (w) {
        return ["article", "blog", "news"].indexOf(w) !== -1;
      })) {
        applies = true;
      }
      if (!applies && (organization || isHome) && rule.when.indexOf("home") !== -1) {
        applies = true;
      }
      if (!applies && rule.when.indexOf("all") !== -1) applies = true;
      if (!applies) return;

      if (pass) {
        add("pass", rule.label, detail || "OK", "eeat", { scoringExempt: true, eeatId: rule.id, eeatDimension: rule.dimension });
        return;
      }
      add(
        rule.level,
        rule.label,
        detail || rule.detailMissing,
        "eeat",
        { scoringExempt: true, eeatId: rule.id, eeatDimension: rule.dimension }
      );
    }

    if (organization || isHome) {
      pushRule(
        eeatFindRule("trust_org_sameas"),
        hasSameAs,
        hasSameAs ? "Organization.sameAs 已提供（实体示例字段）。" : null
      );
      var logoOk = eeatLogoAbsolute(organization);
      pushRule(
        eeatFindRule("trust_org_logo"),
        logoOk,
        logoOk ? "Organization.logo 为绝对 URL。" : null
      );
    }

    if (isArticlePage) {
      var persons = eeatPersonAuthors(article);
      var orgOnly = eeatAuthorIsOrgOnly(article, organization);
      var hasPerson = persons.length > 0 && !orgOnly;
      var visibleByline = eeatFindVisibleBylineName();
      var personName = hasPerson ? flattenJsonLdText(persons[0].name || "") : "";

      pushRule(
        eeatFindRule("who_person_author"),
        hasPerson,
        hasPerson ? "Article 含 Person 作者。" : null
      );

      // Visible byline: pass only when DOM byline exists; schema-only → tip via custom level
      if (visibleByline) {
        pushRule(
          eeatFindRule("who_visible_byline"),
          true,
          "可见署名：「" + visibleByline + "」。"
        );
      } else if (hasPerson) {
        add(
          "tip",
          "Visible author byline",
          "未见 DOM 署名，仅有 schema Person（Google Who 强调读者可见署名）。内部自测 · 非排名门槛。",
          "eeat",
          { scoringExempt: true, eeatId: "who_visible_byline", eeatDimension: "Who" }
        );
      } else {
        pushRule(eeatFindRule("who_visible_byline"), false, null);
      }

      if (visibleByline && hasPerson) {
        var matchOk = eeatNamesMatch(visibleByline, personName);
        pushRule(
          eeatFindRule("who_byline_schema_match"),
          matchOk,
          matchOk
            ? "可见署名与 Person.name 一致。"
            : "可见署名「" + visibleByline + "」与 Person.name「" + personName + "」不一致。"
        );
      } else if (visibleByline && !hasPerson) {
        pushRule(eeatFindRule("who_byline_schema_match"), false, "有可见署名但缺 Person schema。");
      }

      if (hasPerson) {
        var identityOk = persons.some(function (p) { return !eeatAuthorLacksIdentityUrl(p); });
        pushRule(
          eeatFindRule("who_person_url_or_sameas"),
          identityOk,
          identityOk ? "Person 含 url 或 sameAs。" : null
        );
        var bgOk = eeatAuthorBackgroundReachable(persons);
        pushRule(
          eeatFindRule("who_author_background"),
          bgOk,
          bgOk ? "作者背景可延伸（主页/简介）。" : null
        );
      }

      var hasPublished = Boolean(article && (article.datePublished || article.date_published));
      var hasModified = Boolean(article && (article.dateModified || article.date_modified));
      pushRule(
        eeatFindRule("how_article_dates"),
        hasPublished && hasModified,
        hasPublished && hasModified ? "含 datePublished 与 dateModified。" : null
      );

      pushRule(
        eeatFindRule("who_publisher"),
        eeatPublisherLinked(article, organization),
        eeatPublisherLinked(article, organization) ? "Article.publisher 已关联。" : null
      );

      var visibleText = typeof context.visibleText === "number"
        ? context.visibleText
        : visibleTextLength(document.body || document.documentElement);
      var images = typeof context.contentImages === "number" ? context.contentImages : 0;
      var substanceOk = visibleText >= SEO_TEXT_LIMITS.visibleTextMin && images >= 1;
      pushRule(
        eeatFindRule("how_content_substance"),
        substanceOk,
        substanceOk ? "正文与配图充实度尚可（≠ Experience）。" : null
      );
    }

    var aboutRule = eeatFindRule("trust_about_contact");
    var aboutOk = eeatHasAboutContactInGraph(nodes) || eeatHasAboutContactNav();
    pushRule(aboutRule, aboutOk, aboutOk ? "已发现 About/Contact 页或导航入口。" : null);

    var reviewRule = eeatFindRule("how_review_author");
    if (reviews.length) {
      var reviewAuthorOk = reviews.every(function (review) {
        if (!review.author) return false;
        if (typeof review.author === "string") return Boolean(review.author.trim());
        return Boolean(flattenJsonLdText(review.author.name || review.author.author_name || ""));
      });
      pushRule(reviewRule, reviewAuthorOk, reviewAuthorOk ? "Review.author 齐全。" : null);
    }

    // Why: machine proxy for main content. Audience/intent is not machine-judged —
    // when proxy passes, omit tip (and do not mark intent as pass); tip only if main content weak.
    var whyMain = eeatFindRule("why_main_content_first");
    var mainEl = document.querySelector("main, [role='main'], article, .amazon-blog-article, .product-native-detail");
    var mainLen = mainEl ? visibleTextLength(mainEl) : 0;
    var pageText = typeof context.visibleText === "number"
      ? context.visibleText
      : visibleTextLength(document.body || document.documentElement);
    var whyMainOk = mainLen >= Math.min(SEO_TEXT_LIMITS.visibleTextMin, 280)
      || (pageText >= SEO_TEXT_LIMITS.visibleTextMin && Boolean(mainEl));
    pushRule(
      whyMain,
      whyMainOk,
      whyMainOk ? "主内容区可定位且篇幅尚可（Why 代理）。" : null
    );
    if (!whyMainOk) {
      pushRule(
        eeatFindRule("why_primary_audience"),
        false,
        null
      );
    }
  }

  function buildEeatStrictReport(checks) {
    var eeatChecks = (checks || []).filter(function (c) { return c && c.group === "eeat"; });
    var dimensions = ["Who", "How", "Why", "Trust"];
    var byDimension = {};
    dimensions.forEach(function (dim) {
      byDimension[dim] = { pass: 0, warn: 0, tip: 0, items: [] };
    });
    eeatChecks.forEach(function (check) {
      var dim = check.eeatDimension || "Trust";
      if (dim === "Experience" || dim === "Expertise" || dim === "Authoritativeness" || dim === "Trustworthiness") {
        dim = dim === "Trustworthiness" || dim === "Authoritativeness" ? "Trust" : (dim === "Experience" ? "How" : "Who");
      }
      if (!byDimension[dim]) byDimension[dim] = { pass: 0, warn: 0, tip: 0, items: [] };
      if (check.level === "pass") byDimension[dim].pass += 1;
      else if (check.level === "warn") byDimension[dim].warn += 1;
      else if (check.level === "tip" || check.level === "info") byDimension[dim].tip += 1;
      byDimension[dim].items.push(check);
    });
    var gaps = eeatChecks.filter(function (c) { return c.level === "warn" || c.level === "tip"; });
    var status = gaps.some(function (c) { return c.level === "warn"; })
      ? "warn"
      : gaps.length
        ? "tip"
        : eeatChecks.length
          ? "pass"
          : "empty";
    return {
      status: status,
      note: "对照 Google Helpful Content（Who/How/Why）机检代理 · 与上方「对照 Google 官方示例」JSON-LD 字段对齐是两套机制 · Trust 为实体可核查代理 · 非排名门槛 · E-E-A-T 非单独因子",
      officialUrl: "https://developers.google.com/search/docs/fundamentals/creating-helpful-content",
      summary: {
        total: eeatChecks.length,
        pass: eeatChecks.filter(function (c) { return c.level === "pass"; }).length,
        warn: eeatChecks.filter(function (c) { return c.level === "warn"; }).length,
        tip: eeatChecks.filter(function (c) { return c.level === "tip" || c.level === "info"; }).length
      },
      dimensions: byDimension,
      items: eeatChecks,
      catalogSize: EEAT_STRICT_RULES.length
    };
  }

  var latestEeatStrictReport = null;
  var latestAccessibilityCompletenessReport = null;

  /**
   * 无障碍声明（/policy/accessibility）完整度：只读 DOM/head/JSON-LD，不写页内 SEO。
   * 任意页可查「导航是否可发现」；声明页额外查 legal 管线事实。
   */
  function a11yCollectStatementAnchors() {
    return Array.from(document.querySelectorAll("a[href]")).filter(function (a) {
      if (isIgnoredSeoAuditNode(a)) return false;
      var href = String(a.getAttribute("href") || "").toLowerCase();
      var text = String(a.textContent || "").replace(/\s+/g, " ").trim().toLowerCase();
      if (/\/policy\/accessibility(?:\/|$|\?|#)/.test(href)) return true;
      return /无障碍|accessibility\s*statement|accessibility\s*policy|barrierefreiheit|accessibilit/.test(text + " " + href);
    });
  }

  function a11yIsOnStatementPage() {
    try {
      var path = String((window.location && window.location.pathname) || "").toLowerCase();
      return /\/policy\/accessibility(?:\/|$)/.test(path);
    } catch (_e) {
      return false;
    }
  }

  function a11yStatementPageUrl() {
    try {
      return new URL("/policy/accessibility", window.location.href).href;
    } catch (_e2) {
      return "/policy/accessibility";
    }
  }

  function auditAccessibilityStatementCompleteness(add) {
    var onPage = a11yIsOnStatementPage();
    var anchors = a11yCollectStatementAnchors();
    var navOk = anchors.length > 0;
    add(
      navOk ? "pass" : "warn",
      "无障碍声明入口",
      navOk
        ? ("导航/页脚已发现声明链接 ×" + anchors.length + "（示例：" + (anchors[0].getAttribute("href") || "") + "）。")
        : "全页未见 /policy/accessibility 或「无障碍声明」链。请确认 Theme 页脚/政策菜单已启用该项。",
      "accessibility"
    );

    if (!onPage) {
      add(
        "info",
        "声明页专项检测",
        "当前不在声明页。打开「无障碍」Tab 内链接前往声明页后，可复查 content-category / legal 管线。",
        "accessibility"
      );
      return;
    }

    var pageType = String(metaContent('meta[name="page-type"]') || "").trim().toLowerCase();
    var contentCategory = String(metaContent('meta[name="content-category"]') || "").trim().toLowerCase();
    var robots = String(metaContent('meta[name="robots"]') || "").trim().toLowerCase();
    var seoType = aliasSeoType(inferSeoTypeFromPage());
    if (seoType !== "legal") {
      var fromUrl = inferSeoTypeFromUrlPath(window.location.href);
      if (fromUrl === "legal") seoType = "legal";
    }
    var jsonTypes = extractJsonLdTypes();
    var hasMain = Boolean(document.querySelector("main, [role='main'], #main-content"));
    var hasSkip = Boolean(
      document.querySelector(
        'a[href="#main-content"], a[href*="#main-content"], .skip-to-content, [data-skip-to-content]'
      )
    );
    var h1 = getMainH1Text();
    var h2Count = Array.from(document.querySelectorAll("main h2, article h2, .amazon-policy__article h2")).filter(function (n) {
      return !isIgnoredSeoAuditNode(n);
    }).length;
    var hasWebPage = jsonLdTypesInclude(jsonTypes, "WebPage");
    var hasBreadcrumb = jsonLdTypesInclude(jsonTypes, "BreadcrumbList");
    var hasBadType = jsonLdTypesInclude(jsonTypes, "AccessibilityPage");
    var robotsOk = !robots || !/noindex|none/i.test(robots);

    add(
      pageType === "policy" ? "pass" : "warn",
      "声明页 page-type 事实",
      pageType === "policy"
        ? "page-type=policy（Theme 事实层正确，未伪装成 legal）。"
        : ("期望 page-type=policy，实际：" + (pageType || "missing") + "。"),
      "accessibility"
    );
    add(
      contentCategory === "legal" ? "pass" : "fail",
      "声明页 content-category",
      contentCategory === "legal"
        ? "content-category=legal（Seo HeadRenderer 归一层）。"
        : ("期望 content-category=legal，实际：" + (contentCategory || "missing") + "。须走 Seo 管线别名，禁止页内手写。"),
      "accessibility"
    );
    add(
      seoType === "legal" ? "pass" : "warn",
      "Inspector 归类 legal",
      seoType === "legal"
        ? "当前页 seoType=legal（page-type 别名或 /policy URL 启发式）。"
        : ("期望 seoType=legal，实际：" + (seoType || "unknown") + "。"),
      "accessibility"
    );
    add(
      robotsOk ? "pass" : "fail",
      "声明页可索引",
      robotsOk ? (robots ? ("robots=" + robots) : "未写 robots（Google 默认 index,follow）。") : ("阻断索引：" + robots),
      "accessibility"
    );
    add(
      hasMain ? "pass" : "warn",
      "主内容地标",
      hasMain ? "存在 main / #main-content。" : "缺少 main 地标，辅助技术难跳到正文。",
      "accessibility"
    );
    add(
      hasSkip ? "pass" : "tip",
      "跳到主内容",
      hasSkip ? "存在 Skip to main / #main-content 链。" : "建议提供「跳到主内容」链（非 SEO 硬门槛）。",
      "accessibility"
    );
    add(
      h1 ? "pass" : "warn",
      "声明页 H1",
      h1 ? ("H1：" + h1) : "缺少可见 H1。",
      "accessibility"
    );
    add(
      h2Count >= 3 ? "pass" : "tip",
      "声明章节结构",
      h2Count >= 3
        ? ("正文区 H2 ×" + h2Count + "（承诺/范围/限制等章节可见）。")
        : ("正文 H2 仅 " + h2Count + " 个；声明页通常应有多节。"),
      "accessibility"
    );
    add(
      hasWebPage && !hasBadType ? "pass" : "warn",
      "声明页 JSON-LD 壳",
      hasBadType
        ? "禁止 AccessibilityPage；请保持 WebPage。"
        : (hasWebPage ? "JSON-LD 含 WebPage。" : "缺少 WebPage（应由 Seo 组装，勿在 phtml 手写）。"),
      "accessibility"
    );
    add(
      hasBreadcrumb ? "pass" : "warn",
      "声明页 BreadcrumbList",
      hasBreadcrumb ? "JSON-LD 含 BreadcrumbList。" : "缺少 BreadcrumbList（Theme bag + Seo 组装）。",
      "accessibility"
    );
  }

  function buildAccessibilityCompletenessReport(checks) {
    var items = (checks || []).filter(function (c) { return c && c.group === "accessibility"; });
    var pass = items.filter(function (c) { return c.level === "pass"; }).length;
    var fail = items.filter(function (c) { return c.level === "fail"; }).length;
    var warn = items.filter(function (c) { return c.level === "warn"; }).length;
    var tip = items.filter(function (c) { return c.level === "tip" || c.level === "info"; }).length;
    var status = fail ? "fail" : warn ? "warn" : tip && pass === 0 ? "tip" : "pass";
    var complete = fail === 0 && warn === 0;
    return {
      status: status,
      complete: complete,
      onStatementPage: a11yIsOnStatementPage(),
      statementUrl: a11yStatementPageUrl(),
      summary: { pass: pass, fail: fail, warn: warn, tip: tip, total: items.length },
      items: items
    };
  }

  function renderAccessibilityCompletenessSection(report) {
    if (!report) return "";
    var tone = report.complete ? "pass" : report.status === "fail" ? "fail" : report.status === "warn" ? "warn" : "tip";
    var title = report.complete
      ? "无障碍声明 · 完整"
      : (report.status === "fail" ? "无障碍声明 · 不完整（有失败项）" : "无障碍声明 · 有缺口");
    var gaps = (report.items || []).filter(function (c) { return c.level !== "pass"; });
    var passes = (report.items || []).filter(function (c) { return c.level === "pass"; });
    var listHtml = gaps.length
      ? "<ul>" +
        gaps
          .map(function (c) {
            return (
              '<li class="weline-seo-panel__eeat-item weline-seo-panel__eeat-item--' +
              escapeHtml(c.level) +
              '"><span class="weline-seo-panel__badge weline-seo-panel__badge--' +
              escapeHtml(c.level === "tip" || c.level === "info" ? "tip" : c.level) +
              '">' +
              escapeHtml(formatCheckLevel(c.level)) +
              "</span> " +
              escapeHtml(c.label) +
              (c.detail ? '<p class="weline-seo-panel__hint">' + escapeHtml(c.detail) + "</p>" : "") +
              "</li>"
            );
          })
          .join("") +
        "</ul>"
      : '<p class="weline-seo-panel__issue-ok">关键项均已通过。</p>';
    var passSummary = passes.length
      ? '<p class="weline-seo-panel__eeat-pass-summary">' +
        escapeHtml("已通过 " + passes.length + " 项：" + passes.map(function (c) { return c.label; }).join(" · ")) +
        "</p>"
      : "";
    return (
      '<section class="weline-seo-panel__section weline-seo-panel__section--a11y" data-weline-a11y-completeness>' +
      "<h3>" +
      escapeHtml(title) +
      ' <span class="weline-seo-panel__badge weline-seo-panel__badge--' +
      escapeHtml(tone === "tip" ? "tip" : tone) +
      '">' +
      escapeHtml(report.complete ? "完整" : formatCheckLevel(report.status)) +
      "</span></h3>" +
      '<p class="weline-seo-panel__hint">检查 Theme 声明入口 + Seo 管线（page-type 事实 / content-category=legal / legal 归类）。<b>禁止</b>在政策 phtml 手写 meta/JSON-LD。</p>' +
      '<p class="weline-seo-panel__hint">通过 ' +
      escapeHtml(String((report.summary && report.summary.pass) || 0)) +
      " · 失败 " +
      escapeHtml(String((report.summary && report.summary.fail) || 0)) +
      " · 警告 " +
      escapeHtml(String((report.summary && report.summary.warn) || 0)) +
      " · 提示 " +
      escapeHtml(String((report.summary && report.summary.tip) || 0)) +
      "</p>" +
      '<p class="weline-seo-panel__a11y-open"><a class="weline-seo-panel__publish-btn" href="' +
      escapeHtml(report.statementUrl || "/policy/accessibility") +
      '">打开无障碍声明页</a>' +
      (report.onStatementPage ? " <span class=\"weline-seo-panel__hint\">（当前已在声明页）</span>" : "") +
      "</p>" +
      listHtml +
      passSummary +
      "</section>"
    );
  }

  function renderAccessibilityTab(report) {
    var a11yReport =
      latestAccessibilityCompletenessReport ||
      buildAccessibilityCompletenessReport((report && report.checks) || []);
    return (
      renderAccessibilityCompletenessSection(a11yReport) +
      '<section class="weline-seo-panel__section"><h3>说明</h3>' +
      '<p class="weline-seo-panel__hint">「完整」= 无 fail/warn：站点可发现声明入口；在声明页上 Seo 输出 content-category=legal、page-type=policy、seoType=legal，且 JSON-LD 为 WebPage+BreadcrumbList。</p>' +
      '<p class="weline-seo-panel__hint">页内无障碍体验（对比度/键盘陷阱等）不在本 Tab 全量 WCAG 扫描范围内；本 Tab 聚焦<strong>无障碍声明页 SEO/发现完整度</strong>。</p>' +
      "</section>"
    );
  }

  function renderEeatStrictSection(report) {
    if (!report) return "";
    var summary = report.summary || {};
    var tone = report.status === "pass" ? "pass" : report.status === "warn" ? "warn" : report.status === "tip" ? "warn" : "info";
    var dimHtml = ["Who", "How", "Why", "Trust"]
      .map(function (dim) {
        var bucket = (report.dimensions && report.dimensions[dim]) || { pass: 0, warn: 0, tip: 0, items: [] };
        var gapItems = (bucket.items || []).filter(function (c) { return c.level !== "pass"; });
        var passItems = (bucket.items || []).filter(function (c) { return c.level === "pass"; });
        var gapHtml = gapItems
          .map(function (c) {
            return (
              '<li class="weline-seo-panel__eeat-item weline-seo-panel__eeat-item--' +
              escapeHtml(c.level) +
              '"><span class="weline-seo-panel__badge weline-seo-panel__badge--' +
              escapeHtml(c.level === "tip" ? "tip" : c.level) +
              '">' +
              escapeHtml(formatCheckLevel(c.level)) +
              "</span> " +
              escapeHtml(c.label) +
              (c.detail ? '<p class="weline-seo-panel__hint">' + escapeHtml(c.detail) + "</p>" : "") +
              "</li>"
            );
          })
          .join("");
        var passHtml = "";
        if (passItems.length) {
          passHtml =
            '<p class="weline-seo-panel__eeat-pass-summary">' +
            escapeHtml("已通过 " + passItems.length + " 项：" + passItems.map(function (c) { return c.label; }).join(" · ")) +
            "</p>";
        }
        var bodyHtml = gapHtml
          ? "<ul>" + gapHtml + "</ul>" + passHtml
          : (passHtml || '<p class="weline-seo-panel__issue-ok">本维无缺口。</p>');
        return (
          '<div class="weline-seo-panel__eeat-dim" data-eeat-dim="' +
          escapeHtml(dim) +
          '">' +
          "<h4>" +
          escapeHtml(dim === "Why" ? "Why（意图）" : dim) +
          "</h4>" +
          '<p class="weline-seo-panel__hint">通过 ' +
          escapeHtml(String(bucket.pass || 0)) +
          " · 警告 " +
          escapeHtml(String(bucket.warn || 0)) +
          " · 提示 " +
          escapeHtml(String(bucket.tip || 0)) +
          "</p>" +
          bodyHtml +
          "</div>"
        );
      })
      .join("");
    return (
      '<section class="weline-seo-panel__section weline-seo-panel__section--eeat" data-weline-eeat-strict data-weline-google-selfcheck>' +
      "<h3>Google Helpful Content 自测</h3>" +
      '<p class="weline-seo-panel__hint">' +
      escapeHtml(report.note || "内部自测 · 非排名门槛") +
      " · 目录 " +
      escapeHtml(String(report.catalogSize || EEAT_STRICT_RULES.length)) +
      " 项 · 不计入四维本地分 · <a href=\"" +
      escapeHtml(report.officialUrl || "https://developers.google.com/search/docs/fundamentals/creating-helpful-content") +
      "\" target=\"_blank\" rel=\"noopener noreferrer\">官方文档</a></p>" +
      '<div class="weline-seo-panel__local-rich-summary weline-seo-panel__local-rich-summary--' +
      escapeHtml(tone) +
      '"><strong>' +
      escapeHtml(String(summary.total || 0)) +
      " 项</strong><span>通过 " +
      escapeHtml(String(summary.pass || 0)) +
      "</span><span>警告 " +
      escapeHtml(String(summary.warn || 0)) +
      "</span><span>提示 " +
      escapeHtml(String(summary.tip || 0)) +
      "</span></div>" +
      '<div class="weline-seo-panel__eeat-grid">' +
      dimHtml +
      "</div></section>"
    );
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
    } else if (breadcrumb) {
      add("warn", "Breadcrumb items", "BreadcrumbList should expose itemListElement.", "schema");
    } else if (context.seoType === "home") {
      // Google BreadcrumbList needs ≥2 ListItems; single-level homepage should omit the type.
      add("info", "Breadcrumb items", "Homepage omits BreadcrumbList (expected; needs ≥2 trail items).", "schema");
    } else {
      add("tip", "Breadcrumb items", "No BreadcrumbList; add when the URL has a multi-level trail.", "schema");
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
    return /(?:seo-inspector|dev-tool-panel|weline-panel|panel-token|\/dev\/tool\/|hot-update|codex|browser_pass)/i.test(String(value || ""));
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

  function isThirdPartyScriptUrl(href) {
    try {
      var host = new URL(href, window.location.href).hostname.toLowerCase();
      return /(^|\.)(google\.com|gstatic\.com|googleapis\.com|googletagmanager\.com|google-analytics\.com|facebook\.net|fbcdn\.net|cloudflare\.com|cloudflareinsights\.com|recaptcha\.net)$/i.test(host)
        || /recaptcha/i.test(href);
    } catch (_e) {
      return /recaptcha|gstatic\.com\/recaptcha|google\.com\/recaptcha/i.test(String(href || ""));
    }
  }

  function collectResourceTimingIssues() {
    var resources = performanceEntries("resource");
    var issues = {
      largeScripts: [],
      largeThirdPartyScripts: [],
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
      if (path.endsWith(".js") && size > 260 * 1024) {
        if (isThirdPartyScriptUrl(entry.name)) issues.largeThirdPartyScripts.push(item);
        else issues.largeScripts.push(item);
      }
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
      add(
        "tip",
        "unminified JavaScript",
        unminifiedJs.length +
          " JS file(s) look unminified on this page (common in DEV).【不扣分】生产 (!DEV) 经 deploy:upgrade / setup:upgrade 自动 minify。",
        "issues"
      );
    } else {
      add("pass", "unminified JavaScript", "JavaScript file names look minified or cache-built.", "issues");
    }
    if (unminifiedCss.length) {
      add(
        "tip",
        "unminified CSS",
        unminifiedCss.length +
          " CSS file(s) look unminified on this page (common in DEV).【不扣分】生产 (!DEV) 经 deploy:upgrade / setup:upgrade 自动 minify。",
        "issues"
      );
    } else {
      add("pass", "unminified CSS", "CSS file names look minified or cache-built.", "issues");
    }

    var resourceIssues = collectResourceTimingIssues();
    if (resourceIssues.largeScripts.length) {
      add("warn", "large JavaScript resources", resourceIssues.largeScripts.length + " first-party JS resource(s) exceed 260KB decoded/transfer size: " + issueSample(resourceIssues.largeScripts, 5) + ".", "issues");
    } else {
      add("pass", "large JavaScript resources", "No large first-party JS resource detected by Resource Timing.", "issues");
    }
    if (resourceIssues.largeThirdPartyScripts.length) {
      add(
        "tip",
        "large third-party JavaScript",
        resourceIssues.largeThirdPartyScripts.length +
          " third-party JS resource(s) exceed 260KB (e.g. reCAPTCHA/analytics): " +
          issueSample(resourceIssues.largeThirdPartyScripts, 5) +
          "。【不扣分】第三方脚本无法 tree-shake；本机体验主分不扣此项。",
        "issues"
      );
    }
    if (resourceIssues.largeStyles.length) {
      var unminifiedLargeCss = resourceIssues.largeStyles.filter(function (item) {
        return !assetLooksMinified(item.href || item.raw || "", "css");
      });
      var productionLargeCss = resourceIssues.largeStyles.filter(function (item) {
        return assetLooksMinified(item.href || item.raw || "", "css");
      });
      if (unminifiedLargeCss.length) {
        add(
          "tip",
          "large CSS resources (DEV)",
          unminifiedLargeCss.length +
            " unminified CSS file(s) exceed 120KB in this DEV/source build: " +
            issueSample(unminifiedLargeCss, 5) +
            "。【不扣分】DEV 源码体积大属预期；面板无法在本页自动拆包。生产 (!DEV) minify 后复测；仍超标再按 large CSS resources 扣分。",
          "issues"
        );
      }
      if (productionLargeCss.length) {
        add(
          "warn",
          "large CSS resources",
          productionLargeCss.length +
            " minified/production-looking CSS resource(s) still exceed 120KB: " +
            issueSample(productionLargeCss, 5) +
            ". Remove unused CSS / split critical CSS — this remains a real Experience deduction.",
          "issues"
        );
      }
    } else {
      add("pass", "large CSS resources", "No large CSS resource detected by Resource Timing.", "issues");
    }
    if (resourceIssues.compression.length) {
      add(
        "tip",
        "static compression",
        resourceIssues.compression.length +
          " JS/CSS resource(s) look weakly compressed in Resource Timing: " +
          issueSample(resourceIssues.compression, 5) +
          "。【不扣分】本机/DEV 常未开 gzip/Brotli；生产环境由 WLS/网关自动压缩，本地可忽略。",
        "issues"
      );
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
    var probe = latestSitemapProbe;
    var robotsMaps = (probe && probe.robotsSitemapUrls) || [];
    if (probe && (probe.sitemapOk || robotsMaps.length)) {
      add(
        "pass",
        "sitemap discovery",
        "Same-origin discovery OK: " +
          (robotsMaps.length ? ("robots.txt Sitemap: " + robotsMaps.join(", ")) : "") +
          (probe.sitemapOk ? ((robotsMaps.length ? "; " : "") + "/sitemap.xml HTTP " + probe.sitemapStatus) : "") +
          (sitemapLink ? ("; HTML link " + (sitemapLink.getAttribute("href") || "")) : "") +
          ".",
        "technical"
      );
    } else if (sitemapLink) {
      var sitemapHref = sitemapLink.getAttribute("href") || "";
      if (/^(https?:\/\/|\/)/i.test(sitemapHref)) {
        add("pass", "sitemap discovery", "HTML sitemap link present: " + sitemapHref + " (robots.txt probe pending or empty).", "technical");
      } else {
        add("warn", "sitemap discovery", "Sitemap link should be absolute or root-relative.", "technical");
      }
    } else if (!probe) {
      add("tip", "sitemap discovery", "Probing same-origin /robots.txt and /sitemap.xml…", "technical");
    } else {
      add(
        "warn",
        "sitemap discovery",
        "Same-origin robots.txt has no Sitemap: and /sitemap.xml is missing or not XML.",
        "technical"
      );
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
    var ogImage = metaContent('meta[property="og:image"]');
    var ogImageAlt = metaContent('meta[property="og:image:alt"]');
    var twitterTitle = metaContent('meta[name="twitter:title"]');
    var twitterDescription = metaContent('meta[name="twitter:description"]');
    var twitterCard = metaContent('meta[name="twitter:card"]');
    var twitterImage = metaContent('meta[name="twitter:image"]');
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

    if (ogImageAlt && String(ogImageAlt).trim().length >= 2) {
      add("pass", "og:image alt", "og:image:alt describes the share image.", "social");
    } else if (ogImage) {
      add("warn", "og:image alt", "Add og:image:alt describing the share image.", "social");
    }

    if (twitterImageAlt && String(twitterImageAlt).trim().length >= 2) {
      add("pass", "twitter:image alt", "twitter:image:alt describes the share image.", "social");
    } else if (twitterImage) {
      add("warn", "twitter:image alt", "Add twitter:image:alt describing the share image.", "social");
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
    var seoType = aliasSeoType(inferSeoTypeFromPage());
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
      .filter(function (img) { return !isIgnoredSeoAuditNode(img); })
      .map(function (img) {
        return {
          src: img.getAttribute("src") || "",
          alt: img.getAttribute("alt") || "",
          decorative: isDecorativeImage(img)
        };
      })
      .filter(function (img) { return img.src && !img.decorative && !isBrandChromeImage(img.src); });
    var missingAlt = images.filter(function (img) { return !hasUsefulImageAlt(img.alt); }).length;

    function add(level, label, detail, group, meta) {
      var row = { level: level, label: label, detail: detail || "", group: group || "technical" };
      if (meta && typeof meta === "object") {
        if (meta.scoringExempt) row.scoringExempt = true;
        if (meta.eeatId) row.eeatId = meta.eeatId;
        if (meta.eeatDimension) row.eeatDimension = meta.eeatDimension;
      }
      checks.push(row);
    }

    if (document.body && document.body.innerHTML.indexOf("{{") !== -1) {
      add("fail", "Unresolved placeholder", "Body still contains {{...}} tokens.", "technical");
    }

    REQUIRED_HEAD.forEach(function (rule) {
      if (rule.types && rule.types.indexOf(seoType) === -1) return;
      if (rule.test()) {
        add("pass", rule.name, rule.detailWhenPresent || "Present in head.", "head");
        return;
      }
      if (rule.soft) {
        add(
          rule.levelWhenMissing || "tip",
          rule.name,
          rule.detailWhenMissing || "Missing from head (soft; not a Google hard requirement).",
          "head"
        );
        return;
      }
      add("fail", rule.name, "Missing from head.", "head");
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
    } else if (title.length > SEO_TEXT_LIMITS.titleMax && title.length <= SEO_TEXT_LIMITS.titleMax + 20) {
      add(
        "warn",
        "title length",
        "Slightly long (" + title.length + " chars; soft target " + SEO_TEXT_LIMITS.titleMax + "). Brand suffixes often push past SERP width.",
        "content"
      );
    } else {
      add(
        "fail",
        "title length",
        "Expected " + SEO_TEXT_LIMITS.titleMin + "-" + SEO_TEXT_LIMITS.titleMax + ", got " + title.length + ".",
        "content"
      );
    }

    if (description) {
      if (description.length < SEO_TEXT_LIMITS.descriptionSoftMin) {
        add(
          "warn",
          "description length",
          "Short description (" + description.length + " chars). Google has no fixed limit; enrich only if the pitch feels thin.",
          "content"
        );
      } else if (description.length > SEO_TEXT_LIMITS.descriptionSoftMax) {
        add(
          "warn",
          "description length",
          "Long description (" + description.length + " chars). SERP snippets truncate by width; front-load the key pitch.",
          "content"
        );
      } else {
        add("pass", "description length", description.length + " chars (Google has no fixed length rule).", "content");
      }
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

    auditEeatStrict(
      {
        seoType: seoType,
        visibleText: textLength,
        contentImages: images.length
      },
      add
    );

    auditAccessibilityStatementCompleteness(add);

    var seoSummary = summarizeChecks(checks);
    latestEeatStrictReport = buildEeatStrictReport(checks);
    latestAccessibilityCompletenessReport = buildAccessibilityCompletenessReport(checks);
    try {
      window.__WELINE_PANEL_SEO_EEAT_REPORT__ = latestEeatStrictReport;
      window.__WELINE_PANEL_SEO_A11Y_REPORT__ = latestAccessibilityCompletenessReport;
    } catch (_e) {}

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
    "semantic main": true,
    "H1 count": true
  };

  var VERDICT_LABELS = {
    ship: "可推广：技术 SEO 与页面结构达标，可进入投放/外链阶段。",
    polish: "可推广但需抛光：无阻断项，建议先修 warn 再大规模推广。",
    fix: "先修复再推广：存在明确 SEO 失败项，不建议当前大规模投放。",
    blocked: "阻断发布：存在关键失败项，必须先修复后再推广。"
  };

  var SITE_AUDIT_CONTRACT_VERSION = "weline-seo-site-audit/v1";
  var SITE_CRAWL_CONTRACT_VERSION = "weline-seo-site-crawl/v1";
  var latestSiteCrawlReport = window.__WELINE_PANEL_SEO_CRAWL_REPORT__ || null;
  var siteCrawlRunning = false;
  var siteCrawlStatus = "";
  var siteCrawlError = "";
  var latestPageAuditReport = window.__WELINE_PANEL_SEO_PAGE_AUDIT_REPORT__ || null;
  var pageAuditRunning = false;
  var pageAuditStatus = "";
  var pageAuditError = "";
  var latestLocalRichReport = window.__WELINE_PANEL_SEO_LOCAL_RICH_REPORT__ || null;
  var localRichRunning = false;
  var localRichStatus = "";
  var localRichError = "";
  var SITE_AUDIT_CATEGORY_LABELS = {
    crawlability: "Crawlability",
    indexability: "Indexability",
    head_meta: "Meta & Head",
    international: "International SEO",
    content: "Content Quality",
    structured_data: "Structured Data",
    media: "Media & Social",
    headings_ia: "Headings & IA",
    security: "Security & Compliance",
    performance: "Performance",
    mobile: "Mobile UX",
    site_audit: "Site Audit Issues",
    engine_specific: "Search Engine Fit"
  };

  function uniqueStrings(items) {
    var seen = {};
    return (items || []).filter(function (item) {
      var value = String(item || "").trim();
      if (!value || seen[value]) return false;
      seen[value] = true;
      return true;
    });
  }

  function siteAuditSeverity(issue) {
    var severity = String(issue && issue.severity || "").toLowerCase();
    if (severity === "critical" || severity === "high") return "error";
    if (severity === "medium") return "warning";
    return "notice";
  }

  function siteAuditPriority(issue) {
    var severity = String(issue && issue.severity || "").toLowerCase();
    if (issue && issue.blocking) return "P0";
    if (severity === "critical") return "P0";
    if (severity === "high") return "P1";
    if (severity === "medium") return "P2";
    return "P3";
  }

  function siteAuditWhy(issue) {
    var category = String(issue && issue.category || "");
    var title = String(issue && issue.title || "");
    if (title === "mixed content resources") {
      return "HTTPS 页面加载 HTTP 资源会触发混合内容、降低信任信号，并可能导致资源被浏览器或搜索渲染环境阻断。";
    }
    if (title.indexOf("hreflang") !== -1 || category === "international") {
      return "多语言标记错误会让搜索引擎难以选择正确地区/语言版本，造成错误收录、重复页或语言串页。";
    }
    if (category === "performance") {
      return "性能问题会影响抓取渲染、Core Web Vitals、移动端体验和转化效率。";
    }
    if (category === "structured_data") {
      return "结构化数据不合格会降低搜索引擎理解页面类型和富结果资格的概率。";
    }
    if (category === "indexability" || category === "crawlability") {
      return "抓取或索引基础项失败会直接影响页面能否进入搜索引擎索引。";
    }
    if (category === "content") {
      return "内容质量问题会影响搜索意图匹配、摘要展示、平台 spam 风险和 AI 摘取稳定性。";
    }
    return "该问题会降低搜索引擎对页面质量、可理解性或可推广性的判断。";
  }

  function siteAuditEvidence(issue) {
    return (issue && Array.isArray(issue.evidence) ? issue.evidence : []).map(function (item) {
      return {
        url: item.url || window.location.href,
        value: item.value || "",
        source: item.source || "rendered_dom"
      };
    });
  }

  function mergeSiteAuditIssues(issues) {
    var map = {};
    (issues || []).forEach(function (issue) {
      var id = issue.id || normalizeIssueKey((issue.category || "seo") + "_" + (issue.title || "issue"));
      var key = id + "|" + (issue.category || "") + "|" + (issue.title || "");
      if (!map[key]) {
        map[key] = {
          id: id,
          type: "issue",
          severity: siteAuditSeverity(issue),
          rawSeverity: issue.severity || "medium",
          priority: siteAuditPriority(issue),
          category: issue.category || "site_audit",
          categoryLabel: SITE_AUDIT_CATEGORY_LABELS[issue.category] || issue.category || "Site Audit",
          title: issue.title || id,
          status: "open",
          affectedUrls: [],
          affectedCount: 0,
          engines: [],
          evidence: [],
          whyItMatters: siteAuditWhy(issue),
          howToFix: issue.recommendation || "Fix the reported issue, then rerun the Weline SEO audit.",
          confidence: issue.confidence || "medium",
          blocking: Boolean(issue.blocking)
        };
      }
      map[key].affectedUrls = uniqueStrings(map[key].affectedUrls.concat(issue.affectedUrls || [window.location.href]));
      map[key].affectedCount = map[key].affectedUrls.length;
      map[key].engines = uniqueStrings(map[key].engines.concat(issue.engines || []));
      map[key].evidence = map[key].evidence.concat(siteAuditEvidence(issue)).slice(0, 8);
      map[key].blocking = map[key].blocking || Boolean(issue.blocking);
      if (siteAuditPriority(issue) < map[key].priority) map[key].priority = siteAuditPriority(issue);
    });
    return Object.keys(map).map(function (key) { return map[key]; });
  }

  function siteAuditIssueCounts(findings) {
    return {
      errors: findings.filter(function (issue) { return issue.severity === "error"; }).length,
      warnings: findings.filter(function (issue) { return issue.severity === "warning"; }).length,
      notices: findings.filter(function (issue) { return issue.severity === "notice"; }).length,
      total: findings.length
    };
  }

  function buildSiteAuditCategories(findings) {
    var map = {};
    findings.forEach(function (issue) {
      var key = issue.category || "site_audit";
      if (!map[key]) {
        map[key] = {
          id: key,
          label: issue.categoryLabel || SITE_AUDIT_CATEGORY_LABELS[key] || key,
          errors: 0,
          warnings: 0,
          notices: 0,
          issueIds: []
        };
      }
      if (issue.severity === "error") map[key].errors += 1;
      else if (issue.severity === "warning") map[key].warnings += 1;
      else map[key].notices += 1;
      map[key].issueIds.push(issue.id);
    });
    return Object.keys(map).map(function (key) { return map[key]; });
  }

  function buildSiteAuditOutput(raw, engineDiagnostics, scoreBreakdown, verdict) {
    var findings = mergeSiteAuditIssues(engineDiagnostics.issues || []);
    findings.sort(function (a, b) {
      var order = { P0: 0, P1: 1, P2: 2, P3: 3 };
      return (order[a.priority] || 9) - (order[b.priority] || 9);
    });
    var counts = siteAuditIssueCounts(findings);
    var pageIssueIds = findings
      .filter(function (issue) { return issue.affectedUrls.indexOf(window.location.href) !== -1; })
      .map(function (issue) { return issue.id; });
    return {
      contractVersion: SITE_AUDIT_CONTRACT_VERSION,
      outputStyle: "seo-audit-platform",
      generatedAt: new Date().toISOString(),
      crawl: {
        mode: "browser-rendered-single-page",
        scope: "single_page",
        startUrl: window.location.href,
        crawledPages: 1,
        discoveredPages: 1,
        blockedByRobots: null,
        httpStatus: "browser-rendered"
      },
      project: {
        domain: raw.snapshot.siteDomain || window.location.hostname,
        market: "global",
        engines: allEngineIds()
      },
      health: {
        score: typeof scoreBreakdown.total === "number" ? scoreBreakdown.total : 0,
        verdict: verdict,
        errors: counts.errors,
        warnings: counts.warnings,
        notices: counts.notices
      },
      thematicScores: {
        indexability: scoreBreakdown.indexability,
        understandability: scoreBreakdown.understandability,
        experience: scoreBreakdown.experience,
        engineFit: scoreBreakdown.engineFit
      },
      issueCounts: counts,
      categories: buildSiteAuditCategories(findings),
      issues: findings,
      pages: [
        {
          url: window.location.href,
          title: raw.snapshot.title || "",
          canonical: raw.snapshot.canonical || "",
          language: raw.snapshot.pageLang || "",
          seoType: raw.snapshot.seoType || "",
          healthScore: typeof scoreBreakdown.total === "number" ? scoreBreakdown.total : 0,
          issueCount: pageIssueIds.length,
          issueIds: pageIssueIds
        }
      ],
      exports: {
        jsonPath: "report.siteAudit",
        issueColumns: ["severity", "priority", "category", "title", "affectedCount", "affectedUrls", "whyItMatters", "howToFix", "evidence"]
      },
      limitations: engineDiagnostics.limitations || BROWSER_MODE_LIMITATIONS
    };
  }

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
    var siteAudit = buildSiteAuditOutput(raw, engineDiagnostics, scoreBreakdown, verdict);
    var siteCrawl = latestSiteCrawlReport || null;

    return {
      contractVersion: AGENT_CONTRACT_VERSION,
      auditContractVersion: SITE_AUDIT_CONTRACT_VERSION,
      siteCrawlContractVersion: SITE_CRAWL_CONTRACT_VERSION,
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
          audit: siteAudit.issueCounts,
          limitations: engineDiagnostics.limitations
        },
        siteCrawl: siteCrawl ? {
          health: siteCrawl.health || {},
          crawl: siteCrawl.crawl || {},
          issueCount: Array.isArray(siteCrawl.issues) ? siteCrawl.issues.length : 0
        } : null
      },
      siteAudit: siteAudit,
      siteCrawl: siteCrawl,
      auditPlatform: siteAudit,
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
      "description length": "Keep a clear, relevant meta description. Google has no fixed char rule; front-load value if it is very short or very long.",
      "meta keywords": "Add @page keywords or site.seo.keywords with 4-12 comma-separated page-intent phrases.",
      "keyword count": "Keep meta keywords focused: 4-12 comma-separated phrases.",
      "keyword relevance": "Use keywords that naturally appear in title, description, H1, or visible copy.",
      "primary keyword placement": "Place the primary keyword naturally in title or H1.",
      "keyword stuffing": "Remove repeated keyword variants and keep only distinct search intents.",
      "sitemap discovery": "Google discovers sitemaps via robots.txt Sitemap: / Search Console. HTML <link rel=\"sitemap\"> is optional convenience only.",
      "visible text": "Add page-specific facts/modules. 2500+ chars is a Weline thin-page heuristic (Baidu documents 空短页 qualitatively, not a fixed quota).",
      "image alt": "给内容图写描述性 alt（商品名/场景）。图库缩略图在带 aria-label 的按钮内可用空 alt；详情正文图禁止空 alt。",
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
      "unminified JavaScript": "【不扣分】DEV 常出源码 JS；生产 (!DEV) 经 deploy:upgrade / setup:upgrade 自动 minify。",
      "unminified CSS": "【不扣分】DEV 常出源码 CSS；生产 (!DEV) 经 deploy:upgrade / setup:upgrade 自动 minify。",
      "large JavaScript resources": "Split, tree-shake, defer, or lazy-load large first-party JavaScript bundles before promotion.",
      "large third-party JavaScript": "【不扣分】第三方脚本无法 tree-shake；可交互后再加载。不计入本机体验主扣分。",
      "favicon": "Google favicon guidelines: provide one supported <link rel=\"icon\"> (ICO/PNG/GIF/JPEG/BMP/...). Square, preferably >48px. SERP branding only — not an indexing gate.",
      "large CSS resources (DEV)": "【不扣分】DEV 源码 CSS 体积大属预期；面板无法在本页自动拆包。生产 minify 后复测；仍超标则按 large CSS resources 扣分。",
      "large CSS resources": "Remove unused CSS, split critical CSS, and ship compressed production CSS. Real oversized bundles remain an Experience deduction.",
      "static compression": "【不扣分】生产会自动 gzip/Brotli。本机弱压缩只作提示；上线后用 Network 或 curl -I 确认 Content-Encoding。",
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
        "Use siteAudit.health and siteAudit.issues as the default SEO audit output. verdict.status remains the promotion gate. fail=blocking SEO defect, warn=optimization debt, info=expected environment note. Visitor Pixel forwarding is checked in the Visitor panel, not SEO.",
      promotionGate: verdict === "ship" || verdict === "polish",
      doNotPromoteIf: ["blocked", "fix"].indexOf(verdict) !== -1,
      interpretationOrder: ["siteAudit.health", "siteAudit.issues(P0->P3)", "verdict", "actions(P0->P3)", "checks.seoFlat", "monitoringGaps"],
      nextSteps: steps
    };
  }

  function auditAgentReport() {
    return buildAgentReport(auditCurrentPage());
  }

  function publishAgentReport(report) {
    window.__WELINE_PANEL_SEO_REPORT__ = report;
    window.__WELINE_PANEL_SEO_AUDIT__ = report && report.siteAudit ? report.siteAudit : null;
    var node = document.getElementById("weline-panel-seo-report");
    if (!node) {
      node = document.createElement("script");
      node.type = "application/json";
      node.id = "weline-panel-seo-report";
      document.head.appendChild(node);
    }
    node.textContent = JSON.stringify(report);
    var auditNode = document.getElementById("weline-panel-seo-audit");
    if (!auditNode) {
      auditNode = document.createElement("script");
      auditNode.type = "application/json";
      auditNode.id = "weline-panel-seo-audit";
      document.head.appendChild(auditNode);
    }
    auditNode.textContent = JSON.stringify(window.__WELINE_PANEL_SEO_AUDIT__ || {});
    publishSiteCrawlReport(latestSiteCrawlReport);
    window.dispatchEvent(new CustomEvent("weline-panel:seo-report", { detail: report }));
    window.dispatchEvent(new CustomEvent("weline-panel:seo-audit", { detail: window.__WELINE_PANEL_SEO_AUDIT__ }));
    return report;
  }

  function publishSiteCrawlReport(report) {
    if (report) {
      latestSiteCrawlReport = report;
      window.__WELINE_PANEL_SEO_CRAWL_REPORT__ = report;
    }
    var node = document.getElementById("weline-panel-seo-crawl-report");
    if (!node) {
      node = document.createElement("script");
      node.type = "application/json";
      node.id = "weline-panel-seo-crawl-report";
      document.head.appendChild(node);
    }
    node.textContent = JSON.stringify(window.__WELINE_PANEL_SEO_CRAWL_REPORT__ || {});
    window.dispatchEvent(new CustomEvent("weline-panel:seo-crawl", {
      detail: window.__WELINE_PANEL_SEO_CRAWL_REPORT__ || null
    }));
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
      ' 项</strong><span>通过</span></div>' +
      '<div class="weline-seo-panel__stat weline-seo-panel__stat--fail"><strong>' +
      summary.fail +
      ' 项</strong><span>失败</span></div>' +
      '<div class="weline-seo-panel__stat weline-seo-panel__stat--warn"><strong>' +
      summary.warn +
      ' 项</strong><span>警告</span></div>' +
      '<div class="weline-seo-panel__stat weline-seo-panel__stat--info"><strong>' +
      (summary.info || 0) +
      ' 项</strong><span>提示</span></div>' +
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
      info: "说明·不扣分",
      warn: "Warning",
      warning: "Warning",
      fail: "Fail",
      unknown: "Unknown"
    }[status] || status || "Unknown";
  }

  function collectLocalExternalNotices(diagnostics) {
    var matrix = diagnostics.engineMatrix || {};
    var seen = {};
    var notices = [];
    Object.keys(matrix).forEach(function (engineId) {
      var item = matrix[engineId] || {};
      (item.rows || []).forEach(function (row) {
        if (!row || row.noticeKind !== "local_external") return;
        var key = (row.issueId || row.id) + "|" + (row.detail || "");
        if (seen[key]) return;
        seen[key] = true;
        notices.push({
          engine: item.name || engineId,
          label: row.label || row.id,
          detail: row.detail || "",
          recommendation: row.recommendation || ""
        });
      });
    });
    return notices;
  }

  function renderLocalExternalNoticeBanner(diagnostics) {
    var notices = collectLocalExternalNotices(diagnostics);
    if (!notices.length) return "";
    var googleFirst = notices.filter(function (item) {
      return /Google|GOOGLE|CWV|CrUX|Search Console|PageSpeed/i.test(
        (item.engine || "") + " " + (item.label || "") + " " + (item.detail || "")
      );
    });
    var highlight = googleFirst[0] || notices[0];
    return (
      '<section class="weline-seo-panel__section weline-seo-panel__external-notice" role="note" aria-label="本地无法验真说明">' +
      '<div class="weline-seo-panel__external-notice-banner">' +
      '<p class="weline-seo-panel__external-notice-kicker">本地无法验真 · 不扣分</p>' +
      "<h3>Google / CWV 等站外数据本机测不了</h3>" +
      "<p>" +
      escapeHtml(
        highlight.detail ||
          "Lighthouse、PageSpeed Insights、CrUX、Search Console 覆盖与真实爬虫渲染，需要生产域名与官方工具；本地浏览器模式只做说明，不计入引擎适配扣分。"
      ) +
      "</p>" +
      (highlight.recommendation
        ? '<p class="weline-seo-panel__external-notice-action"><b>建议：</b>' +
          escapeHtml(highlight.recommendation) +
          "</p>"
        : "") +
      '<p class="weline-seo-panel__hint weline-seo-panel__hint--compact">矩阵里标「说明·不扣分」的格子均属此类；真正的收录/内容/风险失败仍会显示 Warning / Fail 并扣分。</p>' +
      "</div></section>"
    );
  }

  function renderLocalScoreFocus() {
    var focus = LOCAL_SCORE_FOCUS;
    return (
      '<section class="weline-seo-panel__section weline-seo-panel__local-focus" aria-label="本地优先指标">' +
      "<h3>" +
      escapeHtml(focus.title) +
      "</h3>" +
      '<p class="weline-seo-panel__hint">' +
      escapeHtml(focus.lead) +
      "</p>" +
      '<div class="weline-seo-panel__local-focus-cols">' +
      '<div class="weline-seo-panel__local-focus-col">' +
      '<span class="weline-seo-panel__local-focus-badge weline-seo-panel__local-focus-badge--primary">本地主看</span>' +
      "<ul>" +
      focus.primary
        .map(function (item) {
          return (
            "<li><b>" +
            escapeHtml(item.label) +
            "</b> — " +
            escapeHtml(item.why) +
            "</li>"
          );
        })
        .join("") +
      "</ul></div>" +
      '<div class="weline-seo-panel__local-focus-col">' +
      '<span class="weline-seo-panel__local-focus-badge weline-seo-panel__local-focus-badge--ref">本地参考</span>' +
      "<ul>" +
      focus.reference
        .map(function (item) {
          return (
            "<li><b>" +
            escapeHtml(item.label) +
            "</b> — " +
            escapeHtml(item.why) +
            "</li>"
          );
        })
        .join("") +
      "</ul>" +
      '<p class="weline-seo-panel__hint weline-seo-panel__hint--compact">外部（本地可不纠结）：CrUX / GSC 收录 / 外链 — 需 API 或站长后台。</p>' +
      "</div></div></section>"
    );
  }

  function renderScoreCards(scores) {
    var items = [
      ["可收录", scores.indexability, "indexability", "本地主看"],
      ["可理解性", scores.understandability, "understandability", "本地主看"],
      ["体验", scores.experience, "experience", "本地主看"],
      ["引擎适配", scores.engineFit, "engineFit", "本地参考"]
    ];
    var details = scores.details || {};
    return (
      renderLocalScoreFocus() +
      '<div class="weline-seo-panel__score-grid">' +
      items
        .map(function (item) {
          var value = typeof item[1] === "number" ? item[1] : 0;
          var tone = value >= 90 ? "pass" : value >= 75 ? "warn" : "fail";
          var detail = details[item[2]] || null;
          var focusTone = item[3] === "本地参考" ? "ref" : "primary";
          return (
            '<div class="weline-seo-panel__score-card weline-seo-panel__score-card--' +
            tone +
            '"><span class="weline-seo-panel__score-focus weline-seo-panel__score-focus--' +
            focusTone +
            '">' +
            escapeHtml(item[3]) +
            "</span><span>" +
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
      '<p class="weline-seo-panel__hint">「性能/CWV」与多数「平台专项」在本地标为「说明·不扣分」：真实 CrUX / GSC / 官方 API 本机做不了。只有 Fail / Warning 才扣引擎适配分。</p>' +
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
    var notices = (item.rows || []).filter(function (row) {
      return row.noticeKind === "local_external" || row.status === "info";
    });
    var html = "";
    if (notices.length) {
      html +=
        '<div class="weline-seo-panel__engine-notices">' +
        notices
          .map(function (row) {
            return (
              '<div class="weline-seo-panel__engine-finding weline-seo-panel__engine-finding--info">' +
              '<div class="weline-seo-panel__engine-finding-head">' +
              '<span class="weline-seo-panel__badge weline-seo-panel__badge--info">说明·不扣分</span><strong>' +
              escapeHtml(row.label) +
              "</strong></div>" +
              '<dl class="weline-seo-panel__engine-finding-detail">' +
              "<div><dt>原因</dt><dd>" +
              escapeHtml(row.detail || "本地无法验真的外部项。") +
              "</dd></div>" +
              "<div><dt>建议</dt><dd>" +
              escapeHtml(row.recommendation || "上线后用站长工具验真；本项不扣分。") +
              "</dd></div>" +
              "</dl></div>"
            );
          })
          .join("") +
        "</div>";
    }
    if (!findings.length) {
      return (
        html +
        (html
          ? ""
          : '<p class="weline-seo-panel__engine-ok">未发现平台合规失败或警告项。</p>')
      );
    }
    return (
      html +
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
            '<p class="weline-seo-panel__engine-focus">关注点（非失败项）：' +
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
      '<section class="weline-seo-panel__section"><h3>本地做不到 / 浏览器模式限制</h3>' +
      '<p class="weline-seo-panel__hint">下列项本地无法验真，<b>不扣引擎适配分</b>；开发阶段看上方醒目说明即可，上线后再接站长工具或 API。</p>' +
      '<ul class="weline-seo-panel__limitations">' +
      limitations
        .map(function (item) {
          return "<li>" + escapeHtml(item) + "</li>";
        })
        .join("") +
      "</ul></section>"
    );
  }

  function defaultSitemapUrl() {
    var link = document.querySelector('link[rel="sitemap"][href], link[type="application/xml"][rel="sitemap"][href]');
    if (link && link.href) {
      try {
        var linkedUrl = new URL(link.href, window.location.href);
        if (linkedUrl.host === window.location.host && window.location.protocol === "http:" && linkedUrl.protocol === "https:") {
          linkedUrl.protocol = "http:";
        }
        return linkedUrl.href;
      } catch (_linkError) {
        return link.href;
      }
    }
    try {
      return new URL("/sitemap.xml", window.location.href).href;
    } catch (_error) {
      return "/sitemap.xml";
    }
  }

  function unwrapApiPayload(payload) {
    if (payload && payload.data && payload.data.report) return payload.data.report;
    if (payload && payload.report) return payload.report;
    if (payload && payload.data && payload.data.id && payload.data.status && payload.data.report === undefined) {
      return payload.data;
    }
    return payload || null;
  }

  function unwrapCrawlEnvelope(payload) {
    var data = payload && payload.data && typeof payload.data === "object" ? payload.data : payload;
    if (!data || typeof data !== "object") {
      return { id: "", status: "", report: null };
    }
    var report = data.report || null;
    if (!report && data.contractVersion) {
      report = data;
    }
    var status = String(data.status || (report && report.crawl && report.crawl.status) || "");
    return {
      id: String(data.id || (report && report.crawl && report.crawl.id) || ""),
      status: status,
      report: report,
      ttl: data.ttl
    };
  }

  function delayMs(ms) {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, ms);
    });
  }

  function crawlSeverityTone(severity) {
    if (severity === "error") return "fail";
    if (severity === "warning") return "warn";
    return "info";
  }

  function crawlSeverityLabel(severity) {
    return {
      error: "Error",
      warning: "Warning",
      notice: "Notice"
    }[severity] || "Notice";
  }

  function renderSiteCrawlHealth(report) {
    if (!report) return "";
    var health = report.health || {};
    var crawl = report.crawl || {};
    var sampling = crawl.sampling || {};
    var score = typeof health.score === "number" ? health.score : 0;
    var scoreTone = score >= 90 ? "pass" : score >= 75 ? "warn" : "fail";
    var items = [
      ["Health", score, scoreTone],
      ["Errors", health.errors || 0, "fail"],
      ["Warnings", health.warnings || 0, "warn"],
      ["Scanned", crawl.scanned || 0, "info"],
      ["Failed", crawl.failed || 0, (crawl.failed || 0) ? "fail" : "pass"]
    ];
    if (sampling.discovered) {
      items.push(["Discovered", sampling.discovered, "info"]);
      items.push(["Sampled", sampling.sampled || crawl.totalUrls || 0, "info"]);
      if (sampling.collapsed) {
        items.push(["Collapsed", sampling.collapsed, "warn"]);
      }
    }
    return (
      '<div class="weline-seo-panel__crawl-health">' +
      items.map(function (item) {
        return (
          '<div class="weline-seo-panel__crawl-health-card weline-seo-panel__crawl-health-card--' +
          escapeHtml(item[2]) +
          '"><span>' +
          escapeHtml(item[0]) +
          "</span><strong>" +
          escapeHtml(String(item[1])) +
          "</strong></div>"
        );
      }).join("") +
      "</div>"
    );
  }

  function renderAffectedUrlList(urls, affectedCount, options) {
    options = options || {};
    var list = Array.isArray(urls) ? urls.filter(function (url) { return !!url; }) : [];
    var total = Math.max(Number(affectedCount) || 0, list.length);
    if (!list.length && !total) {
      return "";
    }
    var preview = Math.max(1, Number(options.preview) || 12);
    var open = options.open ? " open" : "";
    var label = options.label || "受影响地址";
    var shown = list.slice(0, preview);
    var hidden = Math.max(0, total - list.length);
    return (
      '<details class="weline-seo-panel__crawl-url-details"' + open + ">" +
      "<summary>" +
      escapeHtml(label) +
      "（" +
      escapeHtml(String(total)) +
      "）</summary>" +
      '<div class="weline-seo-panel__crawl-url-list">' +
      (list.length
        ? shown.map(function (url) {
            return '<code title="' + escapeHtml(url) + '">' + escapeHtml(url) + "</code>";
          }).join("")
        : '<small>报告未附带具体地址，请查看下方 Issue 或页面明细。</small>') +
      (hidden > 0
        ? "<small>另有 " + escapeHtml(String(hidden)) + " 个地址未附带在报告中。</small>"
        : "") +
      "</div></details>"
    );
  }

  function resolveAffectedGroups(item, fallbackUrls) {
    if (item && Array.isArray(item.affectedGroups) && item.affectedGroups.length) {
      return item.affectedGroups;
    }
    var urls = Array.isArray(item && item.affectedUrls) && item.affectedUrls.length
      ? item.affectedUrls
      : (fallbackUrls || []);
    var evidence = Array.isArray(item && item.evidence) ? item.evidence : [];
    var imagesByPage = {};
    evidence.forEach(function (row) {
      if (!row || !row.url) return;
      var list = [];
      (row.images || []).forEach(function (img) { if (img) list.push(img); });
      if (row.resource) list.push(row.resource);
      if (row.image) list.push(row.image);
      if (!imagesByPage[row.url]) imagesByPage[row.url] = [];
      imagesByPage[row.url] = imagesByPage[row.url].concat(list);
    });
    return urls.filter(function (url) {
      return url && !/\/pub\/media\/|\.(webp|png|jpe?g|gif|svg)(\?|$)/i.test(url);
    }).map(function (url) {
      var path = "/";
      try { path = new URL(url, window.location.origin).pathname || "/"; } catch (e) { path = String(url); }
      var images = Array.from(new Set(imagesByPage[url] || []));
      return {
        key: "page:" + url,
        label: "发现页面",
        parentPath: path,
        parentUrl: url,
        kind: "page",
        count: 1,
        imageCount: images.length,
        urls: [url],
        images: images
      };
    });
  }

  function renderAffectedGroups(groups, options) {
    options = options || {};
    var list = Array.isArray(groups) ? groups : [];
    if (!list.length) {
      return "";
    }
    var openFirst = !!options.openFirst;
    return (
      '<div class="weline-seo-panel__crawl-groups">' +
      '<div class="weline-seo-panel__crawl-groups-title">按发现页面（点开看相关图片）</div>' +
      list.map(function (group, index) {
        var pageUrl = group.parentUrl || (group.urls && group.urls[0]) || "";
        var images = Array.isArray(group.images) ? group.images : [];
        var imageCount = Number(group.imageCount || images.length || 0);
        var summary =
          escapeHtml(group.label || "发现页面") +
          " · " +
          escapeHtml(group.parentPath || pageUrl || "") +
          " · " +
          (imageCount > 0 ? (escapeHtml(String(imageCount)) + " 张图") : "无图项");
        return (
          '<details class="weline-seo-panel__crawl-group"' +
          (openFirst && index === 0 ? " open" : "") +
          "><summary>" +
          summary +
          "</summary>" +
          '<div class="weline-seo-panel__crawl-group-body">' +
          '<div class="weline-seo-panel__crawl-url-list"><b>发现页面</b><code title="' +
          escapeHtml(pageUrl) +
          '">' +
          escapeHtml(pageUrl) +
          "</code></div>" +
          (images.length
            ? '<div class="weline-seo-panel__crawl-url-list"><b>相关图片</b>' +
              images.map(function (url) {
                return '<code title="' + escapeHtml(url) + '">' + escapeHtml(url) + "</code>";
              }).join("") +
              "</div>"
            : '<p class="weline-seo-panel__hint">该项是页面级问题（如缺少 title），不是某张图片上的问题。</p>') +
          "</div></details>"
        );
      }).join("") +
      "</div>"
    );
  }

  function renderSiteCrawlDeductions(report) {
    var deductions = report && report.health && Array.isArray(report.health.deductions) ? report.health.deductions : [];
    var issues = report && Array.isArray(report.issues) ? report.issues : [];
    var issueMap = {};
    issues.forEach(function (issue) {
      if (issue && issue.id) {
        issueMap[issue.id] = issue;
      }
    });
    if (!deductions.length) {
      return '<p class="weline-seo-panel__issue-ok">当前全站审计没有扣分项。</p>';
    }
    return (
      '<section class="weline-seo-panel__section"><h3>扣分来源</h3>' +
      '<p class="weline-seo-panel__hint">先看是在哪个页面发现的问题；点开后看该页下相关图片。sitemap 中的图片 loc 不再当独立页面抓取。</p>' +
      '<div class="weline-seo-panel__crawl-deductions">' +
      deductions.slice(0, 20).map(function (item, index) {
        var linked = issueMap[item.issueId] || {};
        var source = item.affectedGroups && item.affectedGroups.length ? item : linked;
        var groups = resolveAffectedGroups(source, item.affectedUrls || linked.affectedUrls || []);
        return (
          '<div class="weline-seo-panel__crawl-deduction">' +
          '<div class="weline-seo-panel__crawl-deduction-head">' +
          '<b>-' +
          escapeHtml(String(item.points || 0)) +
          "</b><span>" +
          escapeHtml(item.title || item.issueId || "SEO issue") +
          "</span><small>" +
          escapeHtml(String(groups.length || item.affectedCount || 0)) +
          " 个发现页</small></div>" +
          renderAffectedGroups(groups, { openFirst: index === 0 }) +
          "</div>"
        );
      }).join("") +
      (deductions.length > 20 ? '<p class="weline-seo-panel__hint">还有 ' + escapeHtml(String(deductions.length - 20)) + ' 个扣分项未展开。</p>' : "") +
      "</div></section>"
    );
  }

  function renderSiteCrawlIssues(report) {
    var issues = report && Array.isArray(report.issues) ? report.issues : [];
    if (!issues.length) {
      return '<section class="weline-seo-panel__section"><h3>Issue</h3><p class="weline-seo-panel__issue-ok">未发现全站 SEO Issue。</p></section>';
    }
    return (
      '<section class="weline-seo-panel__section"><h3>Issue 列表</h3>' +
      '<div class="weline-seo-panel__crawl-issues">' +
      issues.map(function (issue) {
        var tone = crawlSeverityTone(issue.severity);
        var urls = Array.isArray(issue.affectedUrls) ? issue.affectedUrls : [];
        var evidence = Array.isArray(issue.evidence) ? issue.evidence : [];
        return (
          '<article class="weline-seo-panel__crawl-issue weline-seo-panel__crawl-issue--' +
          escapeHtml(tone) +
          '">' +
          '<div class="weline-seo-panel__crawl-issue-head"><span class="weline-seo-panel__badge weline-seo-panel__badge--' +
          escapeHtml(tone) +
          '">' +
          escapeHtml(crawlSeverityLabel(issue.severity)) +
          "</span><strong>" +
          escapeHtml(issue.title || issue.id) +
          "</strong><em>" +
          escapeHtml(issue.priority || "P3") +
          " · " +
          escapeHtml(issue.category || "SEO") +
          " · -" +
          escapeHtml(String(issue.deduction || 0)) +
          "</em></div>" +
          '<dl class="weline-seo-panel__crawl-issue-detail">' +
          "<div><dt>影响</dt><dd>" +
          escapeHtml(issue.whyItMatters || "影响搜索引擎理解和页面质量。") +
          "</dd></div>" +
          "<div><dt>建议</dt><dd>" +
          escapeHtml(issue.howToFix || "修复对应页面源 HTML 后重新扫描。") +
          "</dd></div>" +
          "</dl>" +
          renderAffectedGroups(
            resolveAffectedGroups(issue, urls),
            { openFirst: false }
          ) +
          (evidence.length ? '<details class="weline-seo-panel__crawl-evidence"><summary>证据</summary><pre>' + escapeHtml(JSON.stringify(evidence.slice(0, 4), null, 2)) + "</pre></details>" : "") +
          "</article>"
        );
      }).join("") +
      "</div></section>"
    );
  }

  function renderSiteCrawlPages(report) {
    var pages = report && Array.isArray(report.pages) ? report.pages : [];
    if (!pages.length) {
      return "";
    }
    return (
      '<section class="weline-seo-panel__section"><h3>页面明细</h3>' +
      '<div class="weline-seo-panel__crawl-table-wrap"><table class="weline-seo-panel__crawl-table">' +
      "<thead><tr><th>Score</th><th>Status</th><th>Title</th><th>URL</th><th>Canonical</th><th>Lang</th><th>Issues</th></tr></thead><tbody>" +
      pages.slice(0, 120).map(function (page) {
        return (
          "<tr><td><b>" +
          escapeHtml(String(page.score || 0)) +
          "</b></td><td>" +
          escapeHtml(String(page.status || 0)) +
          "</td><td>" +
          escapeHtml(page.title || "missing") +
          "</td><td><code>" +
          escapeHtml(page.url || "") +
          "</code></td><td><code>" +
          escapeHtml(page.canonical || "") +
          "</code></td><td>" +
          escapeHtml(page.language || "") +
          "</td><td>" +
          escapeHtml(String((page.issueIds || []).length)) +
          "</td></tr>"
        );
      }).join("") +
      "</tbody></table></div>" +
      (pages.length > 120 ? '<p class="weline-seo-panel__hint">页面明细仅展示前 120 条，完整数据请查看 JSON 导出。</p>' : "") +
      "</section>"
    );
  }

  function renderSiteCrawlFailures(report) {
    var failed = report && Array.isArray(report.failedUrls) ? report.failedUrls : [];
    if (!failed.length) {
      return "";
    }
    return (
      '<section class="weline-seo-panel__section weline-seo-panel__section--issues"><h3>失败 URL</h3>' +
      '<div class="weline-seo-panel__crawl-failures">' +
      failed.slice(0, 40).map(function (item) {
        return '<div><b>' + escapeHtml(String(item.status || 0)) + '</b><code>' + escapeHtml(item.url || "") + '</code><span>' + escapeHtml(item.error || "") + "</span></div>";
      }).join("") +
      "</div></section>"
    );
  }

  function renderSiteCrawlExport(report) {
    if (!report) return "";
    return (
      '<section class="weline-seo-panel__section"><h3>JSON 导出</h3>' +
      '<details class="weline-seo-panel__crawl-export"><summary>展开 JSON</summary><pre>' +
      escapeHtml(JSON.stringify(report, null, 2)) +
      "</pre></details></section>"
    );
  }

  function renderSiteCrawlTab() {
    var state = readPanelState();
    var sitemapUrl = state.crawlSitemapUrl || defaultSitemapUrl();
    var limit = state.crawlLimit || 100;
    var report = latestSiteCrawlReport;
    var status = siteCrawlRunning
      ? '<p class="weline-seo-panel__crawl-status is-running">全站审计运行中，请保持面板打开。</p>'
      : (siteCrawlStatus ? '<p class="weline-seo-panel__crawl-status">' + escapeHtml(siteCrawlStatus) + "</p>" : "");
    var error = siteCrawlError ? '<p class="weline-seo-panel__crawl-status is-error">' + escapeHtml(siteCrawlError) + "</p>" : "";

    return (
      '<section class="weline-seo-panel__section weline-seo-panel__crawl-start">' +
      "<h3>全站 Sitemap 审计</h3>" +
      '<p class="weline-seo-panel__hint">按需扫描 sitemap 内同源页面，输出健康分、扣分原因、受影响 URL 和修复建议。单页请用「当前页检测」Tab；结构化数据请用「富文本」Tab。生产环境必须通过 Weline Panel token。</p>' +
      '<div class="weline-seo-panel__crawl-form">' +
      '<label><span>Sitemap</span><input type="url" data-weline-crawl-sitemap value="' +
      escapeHtml(sitemapUrl) +
      '" placeholder="https://example.com/sitemap.xml"></label>' +
      '<label><span>Limit</span><input type="number" min="1" max="500" step="1" data-weline-crawl-limit value="' +
      escapeHtml(String(limit)) +
      '"></label>' +
      '<button type="button" class="weline-seo-panel__publish-btn" data-weline-crawl-start ' +
      (siteCrawlRunning ? "disabled" : "") +
      ">" +
      (siteCrawlRunning ? "审计中" : "开始全站审计") +
      "</button></div>" +
      status +
      error +
      "</section>" +
      (report ? renderSiteCrawlHealth(report) + renderSiteCrawlDeductions(report) + renderSiteCrawlIssues(report) + renderSiteCrawlPages(report) + renderSiteCrawlFailures(report) + renderSiteCrawlExport(report) : "")
    );
  }

  function renderPanelTabs() {
    return (
      '<div class="weline-seo-panel__tabs" role="tablist" aria-label="Inspector sections">' +
      '<button type="button" class="weline-seo-panel__tab is-active" data-weline-tab="seo" role="tab" aria-selected="true">SEO 校验</button>' +
      '<button type="button" class="weline-seo-panel__tab" data-weline-tab="a11y" role="tab" aria-selected="false">无障碍</button>' +
      '<button type="button" class="weline-seo-panel__tab" data-weline-tab="engines" role="tab" aria-selected="false">搜索平台</button>' +
      '<button type="button" class="weline-seo-panel__tab" data-weline-tab="crawl" role="tab" aria-selected="false">全站审计</button>' +
      '<button type="button" class="weline-seo-panel__tab" data-weline-tab="page" role="tab" aria-selected="false">当前页检测</button>' +
      '<button type="button" class="weline-seo-panel__tab" data-weline-tab="rich" role="tab" aria-selected="false">富文本</button>' +
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
      '<div class="weline-seo-panel__page-actions">' +
      '<button type="button" class="weline-seo-panel__publish-btn" data-weline-page-audit data-weline-page-audit-use-current="1" ' +
      (pageAuditRunning ? "disabled" : "") +
      ">" +
      (pageAuditRunning ? "检测中…" : "检测当前 URL") +
      "</button>" +
      '<button type="button" class="weline-seo-panel__publish-btn" data-weline-seo-publish>发布 AI 报告</button>' +
      "</div></div>"
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
      renderLocalExternalNoticeBanner(diagnostics) +
      renderEngineMatrixTable(diagnostics) +
      renderEngineCards(diagnostics) +
      renderLimitations(diagnostics.limitations)
    );
  }

  function renderIssueCard(check, fixLabel) {
    return (
      '<article class="weline-seo-panel__issue-card weline-seo-panel__issue-card--' +
      escapeHtml(check.level) +
      '">' +
      '<div class="weline-seo-panel__issue-head">' +
      '<span class="weline-seo-panel__badge weline-seo-panel__badge--' +
      escapeHtml(check.level === "tip" ? "tip" : check.level) +
      '">' +
      escapeHtml(formatCheckLevel(check.level)) +
      "</span><strong>" +
      escapeHtml(check.label) +
      "</strong></div>" +
      (check.detail ? '<p class="weline-seo-panel__hint">' + escapeHtml(check.detail) + "</p>" : "") +
      '<p class="weline-seo-panel__issue-fix"><b>' +
      escapeHtml(fixLabel || "建议") +
      "</b> " +
      escapeHtml(actionFixHint(check)) +
      "</p></article>"
    );
  }

  function renderIssueAuditBlock(report) {
    var issueChecks = (report.checks || []).filter(function (check) {
      return check.group === "issues" && (check.level === "fail" || check.level === "warn");
    });
    var tipChecks = (report.checks || []).filter(function (check) {
      return check.group === "issues" && (check.level === "tip" || check.level === "info");
    });
    var titleSuffix = issueChecks.length ? " · " + issueChecks.length + " 个需处理" : " · 未发现阻断";
    if (tipChecks.length) {
      titleSuffix += " · " + tipChecks.length + " 条提示(不扣分)";
    }
    var body = "";
    if (!issueChecks.length) {
      body = '<p class="weline-seo-panel__issue-ok">当前未发现需处理的 Issue（fail/warn）。</p>';
    } else {
      body = '<div class="weline-seo-panel__issue-list">' + issueChecks.map(function (check) {
        return renderIssueCard(check, "建议");
      }).join("") + "</div>";
    }
    if (tipChecks.length) {
      body +=
        '<div class="weline-seo-panel__issue-tips">' +
        '<h4 class="weline-seo-panel__issue-tips-title">提示 · 不扣分</h4>' +
        '<p class="weline-seo-panel__hint">以下项本地/DEV 常见或生产会自动处理，<b>不计分、不计入「需处理」</b>；文案已写明原因。</p>' +
        '<div class="weline-seo-panel__issue-list">' +
        tipChecks.map(function (check) {
          return renderIssueCard(check, "不扣分原因");
        }).join("") +
        "</div></div>";
    }
    return (
      '<section class="weline-seo-panel__section weline-seo-panel__section--issues"><h3>Issue 审计' +
      escapeHtml(titleSuffix) +
      "</h3>" +
      '<p class="weline-seo-panel__hint">需处理：混合内容、语言 URL、生产态超大资源、图片尺寸、外链安全。压缩/minify/第三方体积等本地提示见下方「不扣分」。</p>' +
      body +
      "</section>"
    );
  }


  /**
   * Full local rich-result catalog. Opportunistic types are validated when present;
   * page expectations (PAGE_RICH_EXPECTATIONS) decide which must appear.
   */
  var LOCAL_RICH_RESULT_RULES = [
    {
      type: "Product",
      also: ["ProductGroup"],
      label: "商品",
      // Google product snippet: name + (offers | aggregateRating | review). Image recommended for merchant.
      required: ["name"],
      offerRequiredAny: ["offers", "hasVariant", "aggregateRating", "review"],
      recommended: ["image", "description", "sku", "brand.name", "offers.price|offers.lowPrice", "offers.priceCurrency", "offers.availability", "offers.url"]
    },
    {
      type: "Article",
      also: ["NewsArticle", "BlogPosting"],
      label: "文章",
      required: ["headline", "image", "datePublished", "author", "publisher"],
      recommended: ["dateModified", "mainEntityOfPage", "description"]
    },
    {
      type: "FAQPage",
      also: [],
      label: "FAQ",
      required: ["mainEntity"],
      recommended: []
    },
    {
      type: "BreadcrumbList",
      also: [],
      label: "面包屑",
      required: ["itemListElement"],
      recommended: []
    },
    {
      type: "Organization",
      also: ["LocalBusiness", "OnlineStore", "OnlineBusiness"],
      label: "组织/商家",
      required: ["name"],
      recommended: ["url", "logo"]
    },
    {
      type: "WebSite",
      also: [],
      label: "网站",
      required: ["name", "url"],
      recommended: ["publisher", "potentialAction"]
    },
    {
      type: "Review",
      also: [],
      label: "评价",
      required: ["itemReviewed", "reviewRating", "author"],
      recommended: ["reviewBody", "datePublished"]
    },
    {
      type: "ItemList",
      also: [],
      label: "列表(Carousel)",
      required: ["itemListElement"],
      recommended: []
    },
    {
      type: "CollectionPage",
      also: [],
      label: "集合页",
      required: ["name"],
      recommended: ["url"]
    },
    {
      type: "VideoObject",
      also: [],
      label: "视频",
      required: ["name", "thumbnailUrl", "uploadDate"],
      recommended: ["description", "contentUrl", "embedUrl"]
    },
    {
      type: "HowTo",
      also: [],
      label: "操作指南",
      required: ["name", "step"],
      recommended: ["description", "totalTime", "tool", "supply"]
    },
    {
      type: "Event",
      also: [],
      label: "活动",
      required: ["name", "startDate", "location"],
      recommended: ["description", "image", "offers", "organizer"]
    },
    {
      type: "Recipe",
      also: [],
      label: "食谱",
      required: ["name", "image", "recipeIngredient", "recipeInstructions"],
      recommended: ["author", "totalTime", "recipeYield", "nutrition"]
    }
  ];

  /**
   * Page-type → rich catalog expectations (Google Search Central documented features only).
   * required: missing ⇒ fail; optional: missing ⇒ warn; other catalog types only when present.
   */
  var PAGE_RICH_EXPECTATIONS = {
    // Homepage: Google documents WebSite + Organization; BreadcrumbList needs ≥2 ListItems,
    // so a lone「首页」trail is not a valid Google BreadcrumbList — do not warn as missing.
    home: { required: ["WebSite", "Organization"], optional: [] },
    product: { required: ["Product", "BreadcrumbList", "Organization", "WebSite"], optional: [] },
    article: { required: ["Article", "BreadcrumbList", "Organization", "WebSite"], optional: [] },
    blog: { required: ["Article", "BreadcrumbList", "Organization", "WebSite"], optional: [] },
    news: { required: ["Article", "BreadcrumbList", "Organization", "WebSite"], optional: [] },
    faq: { required: ["FAQPage", "BreadcrumbList", "Organization", "WebSite"], optional: [] },
    review: { required: ["Review", "BreadcrumbList", "Organization", "WebSite"], optional: [] },
    // Ecommerce list pages: BreadcrumbList is the Google rich result; Org/WebSite are site signals.
    collection: { required: ["BreadcrumbList", "Organization", "WebSite"], optional: [] },
    contact: { required: ["Organization", "WebSite", "BreadcrumbList"], optional: [] },
    about: { required: ["AboutPage", "Organization", "WebSite", "BreadcrumbList"], optional: [] },
    legal: { required: ["WebSite", "Organization", "BreadcrumbList"], optional: [] },
    web_page: { required: ["Organization", "WebSite"], optional: ["BreadcrumbList"] }
  };

  function collectJsonLdFromRoot(root) {
    var nodes = [];
    var types = [];
    var parseErrors = [];
    if (!root || !root.querySelectorAll) {
      return { nodes: nodes, types: types, parseErrors: parseErrors };
    }
    root.querySelectorAll('script[type="application/ld+json"]').forEach(function (script) {
      var raw = String(script.textContent || "").trim();
      if (!raw) return;
      try {
        var data = JSON.parse(raw);
        collectJsonLdNodesFromData(data).forEach(function (node) {
          nodes.push(node);
          jsonLdTypeList(node).forEach(function (type) {
            if (types.indexOf(type) === -1) types.push(type);
          });
        });
      } catch (error) {
        parseErrors.push((error && error.message) || "invalid JSON-LD");
        types.push("INVALID_JSON");
      }
    });
    return { nodes: nodes, types: types, parseErrors: parseErrors };
  }

  function localRichNodeMatches(node, rule) {
    var wanted = [rule.type].concat(rule.also || []);
    return jsonLdTypeList(node).some(function (type) {
      return wanted.some(function (expected) { return schemaTypeMatches(type, expected); });
    });
  }

  function resolvePageRichExpectations(seoType) {
    var normalized = normalizeSeoType(seoType);
    var aliased = PAGE_JSONLD_RULE_ALIASES[normalized] || normalized;
    return PAGE_RICH_EXPECTATIONS[aliased]
      || PAGE_RICH_EXPECTATIONS[normalized]
      || PAGE_RICH_EXPECTATIONS.web_page;
  }

  function inferSeoTypeFromUrlPath(url) {
    var path = "";
    try {
      path = new URL(url || "", window.location.href).pathname || "";
    } catch (_e) {
      path = String(url || "");
    }
    path = String(path).toLowerCase().replace(/\/+$/, "") || "/";
    if (path === "/" || path === "") return "home";
    if (/\/product(?:\/|$)/.test(path) || /\/p\//.test(path)) return "product";
    if (/\/faq(?:\/|$|\?)/.test(path)) return "faq";
    // Blog index/category are list pages (no Article); only /blog/{slug} is a post.
    if (/\/blog\/category(?:\/|$)/.test(path)) return "collection";
    if (/\/blog\/rss\.xml$/.test(path)) return "";
    if (/\/blog$/.test(path)) return "collection";
    if (/\/blog\/[^/]+$/.test(path)) return "blog";
    if (/\/post(?:\/|$)/.test(path)) return "blog";
    if (/\/news(?:\/|$)/.test(path)) return "news";
    if (/\/review(?:\/|$)/.test(path)) return "review";
    if (/\/contact(?:\/|$)/.test(path)) return "contact";
    if (/\/about(?:\/|$)/.test(path)) return "about";
    // 政策壳 /policy、/policy/* → legal（与 PAGE_JSONLD_RULE_ALIASES.policy 一致）
    if (/\/policy(?:\/|$)/.test(path)) return "legal";
    if (/\/categor|\/collection|\/products(?:\/|$)|\/search(?:\/|$)|\/tag(?:\/|$)|\/best-sellers|\/new-arrivals/.test(path)) {
      return "collection";
    }
    return "";
  }

  function inferSeoTypeForRich(doc, url, types) {
    var root = doc && doc.querySelectorAll ? doc : document;
    var explicit = "";
    try {
      var meta = root.querySelector('meta[name="page-type"]');
      explicit = meta ? String(meta.getAttribute("content") || "").trim() : "";
    } catch (_e) {}
    var explicitNorm = explicit ? normalizeSeoType(explicit) : "";
    // Explicit blog list/category beats any residual /blog* URL heuristic.
    if (explicitNorm === "blog_list" || explicitNorm === "blog_category") {
      return explicitNorm;
    }
    // Strong route signal — polluted page-type meta must not reclassify /product/ as list.
    var fromUrl = inferSeoTypeFromUrlPath(url);
    if (fromUrl) return fromUrl;
    if (explicitNorm) return explicitNorm;
    try {
      var bodyClass = root.body ? String(root.body.className || "") : "";
      var match = bodyClass.match(/\bseo-([a-z0-9-]+)\b/i);
      if (match) return normalizeSeoType(match[1]);
    } catch (_e2) {}

    var typeList = Array.isArray(types) ? types : [];
    if (jsonLdTypesInclude(typeList, "Product") || jsonLdTypesInclude(typeList, "ProductGroup")) return "product";
    if (jsonLdTypesInclude(typeList, "FAQPage")) return "faq";
    if (jsonLdTypesInclude(typeList, "BlogPosting")) return "blog";
    if (jsonLdTypesInclude(typeList, "NewsArticle")) return "news";
    if (jsonLdTypesInclude(typeList, "Review") && !jsonLdTypesInclude(typeList, "Product")) return "review";
    if (jsonLdTypesInclude(typeList, "ItemList") || jsonLdTypesInclude(typeList, "CollectionPage")) return "collection";
    if (jsonLdTypesInclude(typeList, "Article")) return "article";
    if (jsonLdTypesInclude(typeList, "WebSite") && typeList.length <= 4) return "home";
    return "web_page";
  }

  function flattenJsonLdText(value) {
    if (value === null || value === undefined) return "";
    if (Array.isArray(value)) {
      return value
        .map(flattenJsonLdText)
        .filter(Boolean)
        .join(" / ");
    }
    if (typeof value === "object") {
      if (Object.prototype.hasOwnProperty.call(value, "name")) {
        return flattenJsonLdText(value.name);
      }
      if (Object.prototype.hasOwnProperty.call(value, "@value")) {
        return String(value["@value"] || "").trim();
      }
      return "";
    }
    return String(value).trim();
  }

  function shortSchemaEnum(value) {
    var text = flattenJsonLdText(value);
    if (!text) return "";
    var match = text.match(/schema\.org\/([^\/\s?#]+)/i);
    return match ? match[1] : text;
  }

  function pushRichFact(facts, label, value) {
    var text = flattenJsonLdText(value);
    if (!text) return;
    facts.push({ label: label, value: text });
  }

  function localRichItemPreview(node, rule) {
    var facts = [];
    var title = "";
    var detectedType = jsonLdTypeList(node).join(", ") || rule.type;

    if (rule.type === "Product") {
      title = flattenJsonLdText(node.name) || "商品";
      pushRichFact(facts, "类型", detectedType);
      pushRichFact(facts, "品牌", node.brand && node.brand.name ? node.brand.name : node.brand);
      var currency = firstJsonLdScalar(node, "offers.priceCurrency") || firstJsonLdScalar(node, "offers.offers.priceCurrency");
      var low = firstJsonLdScalar(node, "offers.lowPrice") || firstJsonLdScalar(node, "offers.price") || firstJsonLdScalar(node, "offers.offers.price");
      var high = firstJsonLdScalar(node, "offers.highPrice");
      if (low || high) {
        var priceText = low && high && low !== high ? low + "–" + high : low || high;
        pushRichFact(facts, "价格", (currency ? currency + " " : "") + priceText);
      } else if (currency) {
        pushRichFact(facts, "货币", currency);
      }
      pushRichFact(
        facts,
        "库存",
        shortSchemaEnum(
          firstJsonLdScalar(node, "offers.availability") || firstJsonLdScalar(node, "offers.offers.availability")
        )
      );
      pushRichFact(facts, "SKU", node.sku);
      var variantCount = Array.isArray(node.hasVariant) ? node.hasVariant.length : 0;
      var offerCount = firstJsonLdScalar(node, "offers.offerCount");
      if (variantCount) pushRichFact(facts, "变体", String(variantCount));
      else if (offerCount) pushRichFact(facts, "报价数", String(offerCount));
      var imageCount = Array.isArray(node.image) ? node.image.length : node.image ? 1 : 0;
      if (imageCount) pushRichFact(facts, "图片", String(imageCount));
      return { title: title, facts: facts };
    }

    if (rule.type === "BreadcrumbList") {
      var crumbs = Array.isArray(node.itemListElement) ? node.itemListElement : [];
      var trailNames = [];
      var trailUrls = [];
      crumbs.forEach(function (item, index) {
        var crumbName = flattenJsonLdText(
          item.name || (item.item && item.item.name) || ""
        );
        var crumbUrl = "";
        if (typeof item.item === "string") crumbUrl = item.item.trim();
        else if (item.item && typeof item.item === "object") {
          crumbUrl = flattenJsonLdText(item.item.url || item.item["@id"] || "");
        }
        // Never use fragment node ids (#breadcrumb) as the human trail label.
        if (crumbUrl && /#breadcrumb\b/i.test(crumbUrl) && !crumbName) {
          crumbUrl = "";
        }
        if (crumbName) trailNames.push(crumbName);
        else if (crumbUrl && !/#breadcrumb\b/i.test(crumbUrl)) trailNames.push(crumbUrl);
        if (crumbName || crumbUrl) {
          trailUrls.push(
            String(index + 1) +
              ". " +
              (crumbName || "（未命名）") +
              (crumbUrl ? " → " + crumbUrl : "")
          );
        }
      });
      title = trailNames.join(" › ") || "面包屑";
      if (/#breadcrumb\b/i.test(title)) {
        title = "面包屑";
      }
      pushRichFact(facts, "层数", String(crumbs.length || 0));
      if (trailUrls.length) {
        pushRichFact(facts, "路径", trailUrls.join(" · "));
      }
      return { title: title, facts: facts };
    }

    if (rule.type === "ItemList") {
      var listItems = Array.isArray(node.itemListElement) ? node.itemListElement : [];
      title = flattenJsonLdText(node.name) || "ItemList";
      pushRichFact(facts, "条目", String(listItems.length || 0));
      pushRichFact(facts, "说明", "仅 Course/Movie/Recipe/Restaurant 才是 Google Carousel");
      return { title: title, facts: facts };
    }

    if (rule.type === "Organization" || rule.type === "WebSite") {
      title = flattenJsonLdText(node.name) || rule.label || rule.type;
      pushRichFact(facts, "URL", node.url);
      if (rule.type === "Organization" && node.logo) {
        pushRichFact(facts, "Logo", typeof node.logo === "string" ? "已提供" : flattenJsonLdText(node.logo.url || node.logo));
      }
      return { title: title, facts: facts };
    }

    if (rule.type === "Article") {
      title = flattenJsonLdText(node.headline || node.name) || "文章";
      pushRichFact(facts, "作者", node.author && node.author.name ? node.author.name : node.author);
      pushRichFact(facts, "发布", node.datePublished);
      pushRichFact(facts, "更新", node.dateModified);
      return { title: title, facts: facts };
    }

    if (rule.type === "Review") {
      title =
        flattenJsonLdText(
          (node.author && node.author.name) ||
            node.name ||
            node.headline ||
            (node.reviewBody ? String(node.reviewBody).slice(0, 48) : "")
        ) || "评价";
      pushRichFact(facts, "评分", firstJsonLdScalar(node, "reviewRating.ratingValue"));
      pushRichFact(facts, "对象", node.itemReviewed && node.itemReviewed.name ? node.itemReviewed.name : node.itemReviewed);
      return { title: title, facts: facts };
    }

    if (rule.type === "FAQPage") {
      var questions = Array.isArray(node.mainEntity) ? node.mainEntity : [];
      title = flattenJsonLdText(node.name) || "FAQ";
      pushRichFact(facts, "问答数", String(questions.length || 0));
      return { title: title, facts: facts };
    }

    if (rule.type === "CollectionPage") {
      title = flattenJsonLdText(node.name) || "集合页";
      pushRichFact(facts, "URL", node.url);
      pushRichFact(facts, "说明", "非 Google 电商列表富结果文档类型");
      return { title: title, facts: facts };
    }

    title = flattenJsonLdText(node.name || node.headline || node["@id"]) || rule.label || rule.type;
    pushRichFact(facts, "类型", detectedType);
    return { title: title, facts: facts };
  }

  /**
   * Trimmed Google Search Central JSON-LD samples for side-by-side compare.
   * Keep shapes faithful to official docs; do not invent ecommerce CollectionPage/ItemList.
   */
  var GOOGLE_OFFICIAL_RICH_EXAMPLES = {
    BreadcrumbList: {
      docUrl: "https://developers.google.com/search/docs/appearance/structured-data/breadcrumb",
      title: "Google BreadcrumbList 官方示例",
      notes: [
        "末级 ListItem 通常省略 item",
        "非末级 item 必须是绝对 URL",
        "不要给 BreadcrumbList 加 @id"
      ],
      example: {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        itemListElement: [
          { "@type": "ListItem", position: 1, name: "Books", item: "https://example.com/books" },
          { "@type": "ListItem", position: 2, name: "Science Fiction", item: "https://example.com/books/sciencefiction" },
          { "@type": "ListItem", position: 3, name: "Award Winners" }
        ]
      }
    },
    Organization: {
      docUrl: "https://developers.google.com/search/docs/appearance/structured-data/organization",
      title: "Google OnlineStore（Organization 子类）官方示例",
      notes: [
        "电商站点 Google 建议用 OnlineStore，而不是泛 Organization",
        "无硬性必填；推荐 name / url / logo / sameAs 等",
        "可放首页或 About，不必每页重复完整商家政策"
      ],
      example: {
        "@context": "https://schema.org",
        "@type": "OnlineStore",
        name: "Example Online Store",
        url: "https://www.example.com",
        logo: "https://www.example.com/assets/images/logo.png",
        sameAs: [
          "https://example.net/profile/example12",
          "https://example.org/@example34"
        ]
      }
    },
    WebSite: {
      docUrl: "https://developers.google.com/search/docs/appearance/structured-data/sitelinks-searchbox",
      title: "Google WebSite + SearchAction 官方示例",
      notes: [
        "potentialAction 描述站内搜索（SearchAction）",
        "urlTemplate 必须含字面量 {search_term_string}（Google 替换变量，不是待填示例）",
        "target 可为 EntryPoint 或字符串模板"
      ],
      example: {
        "@context": "https://schema.org",
        "@type": "WebSite",
        url: "https://www.example.com/",
        name: "Example",
        potentialAction: {
          "@type": "SearchAction",
          target: {
            "@type": "EntryPoint",
            urlTemplate: "https://www.example.com/search?q={search_term_string}"
          },
          "query-input": "required name=search_term_string"
        }
      }
    },
    Product: {
      docUrl: "https://developers.google.com/search/docs/appearance/structured-data/product-snippet",
      title: "Google Product snippet 官方示例（精简）",
      notes: [
        "name 必填；另需 offers / aggregateRating / review 之一",
        "商品富结果在详情页，不要塞进列表页 Product ItemList"
      ],
      example: {
        "@context": "https://schema.org/",
        "@type": "Product",
        name: "Executive Anvil",
        image: "https://example.com/anvil.jpg",
        description: "Sleek anvil with a black steel head",
        sku: "0446310786",
        brand: { "@type": "Brand", name: "ACME" },
        offers: {
          "@type": "Offer",
          url: "https://example.com/anvil",
          priceCurrency: "USD",
          price: "119.99",
          availability: "https://schema.org/InStock"
        }
      }
    },
    Article: {
      docUrl: "https://developers.google.com/search/docs/appearance/structured-data/article",
      title: "Google Article 官方示例（精简）",
      notes: ["headline / image / datePublished / author / publisher 为常见必填"],
      example: {
        "@context": "https://schema.org",
        "@type": "NewsArticle",
        headline: "Article headline",
        image: ["https://example.com/photos/1x1/photo.jpg"],
        datePublished: "2024-01-05T08:00:00+08:00",
        dateModified: "2024-02-05T09:20:00+08:00",
        author: [{ "@type": "Person", name: "Jane Doe" }],
        publisher: { "@type": "Organization", name: "Example" }
      }
    },
    FAQPage: {
      docUrl: "https://developers.google.com/search/docs/appearance/structured-data/faqpage",
      title: "Google FAQPage 官方示例（精简）",
      notes: ["mainEntity 为 Question/Answer 列表"],
      example: {
        "@context": "https://schema.org",
        "@type": "FAQPage",
        mainEntity: [{
          "@type": "Question",
          name: "How to find return policy?",
          acceptedAnswer: {
            "@type": "Answer",
            text: "Find return policy on the order page."
          }
        }]
      }
    },
    Review: {
      docUrl: "https://developers.google.com/search/docs/appearance/structured-data/review-snippet",
      title: "Google Review 官方示例（精简）",
      notes: ["itemReviewed / reviewRating / author 必填"],
      example: {
        "@context": "https://schema.org",
        "@type": "Review",
        itemReviewed: { "@type": "Product", name: "Executive Anvil" },
        reviewRating: { "@type": "Rating", ratingValue: 4 },
        author: { "@type": "Person", name: "Fred Benson" },
        reviewBody: "Great product."
      }
    },
    ItemList: {
      docUrl: "https://developers.google.com/search/docs/appearance/structured-data/carousel",
      title: "Google Carousel/ItemList 官方范围说明",
      notes: [
        "Carousel 仅文档化 Course / Movie / Recipe / Restaurant",
        "电商 Product 列表不是 Google Carousel 富结果"
      ],
      example: {
        "@context": "https://schema.org",
        "@type": "ItemList",
        itemListElement: [{
          "@type": "ListItem",
          position: 1,
          url: "https://example.com/recipe/1"
        }]
      }
    }
  };

  function resolveGoogleOfficialExample(type) {
    return GOOGLE_OFFICIAL_RICH_EXAMPLES[type] || null;
  }

  function compactJsonLdForCompare(node) {
    if (!node || typeof node !== "object") return null;
    try {
      var priority = [
        "@context",
        "@type",
        "@id",
        "name",
        "url",
        "logo",
        "publisher",
        "potentialAction",
        "availableLanguage",
        "offers",
        "hasVariant",
        "aggregateRating",
        "review",
        "brand",
        "sku",
        "image",
        "description",
        "sameAs",
        "itemListElement",
        "mainEntity",
        "headline",
        "author",
        "datePublished",
        "itemReviewed",
        "reviewRating"
      ];
      var seen = {};
      var out = {};
      function putKey(key) {
        if (seen[key] || !Object.prototype.hasOwnProperty.call(node, key)) return;
        seen[key] = true;
        var value = node[key];
        // Always show the real on-page values — never invent "… +N" placeholders
        // (those would look like fake schema to operators reviewing the panel).
        if (key === "availableLanguage" || key === "sameAs") {
          out[key] = value;
          return;
        }
        if (key === "potentialAction" || key === "offers" || key === "publisher" || key === "brand") {
          out[key] = value;
          return;
        }
        if (key === "itemListElement" && Array.isArray(value)) {
          // Keep every ListItem; nested BlogPosting bodies stay intact for review.
          out[key] = value;
          return;
        }
        if (Array.isArray(value)) {
          out[key] = value.map(function (entry) {
            if (!entry || typeof entry !== "object") return entry;
            return entry;
          });
          return;
        }
        out[key] = value;
      }
      priority.forEach(putKey);
      Object.keys(node).forEach(putKey);
      return out;
    } catch (_error) {
      return null;
    }
  }

  function buildGoogleCompare(type, ourNode) {
    var pack = resolveGoogleOfficialExample(type);
    if (!pack || !pack.example) return null;
    var example = pack.example;
    var our = compactJsonLdForCompare(ourNode);
    var diffs = [];

    if (!ourNode) {
      diffs.push({ level: "fail", text: "本站未检出该类型；右侧为 Google 官方示例形状" });
      (pack.notes || []).forEach(function (note) {
        diffs.push({ level: "note", text: note });
      });
    } else {
      Object.keys(example).forEach(function (key) {
        if (key === "@context") return;
        if (key === "@type") {
          var ourType = ourNode["@type"];
          var exampleType = example["@type"];
          if (String(ourType) !== String(exampleType)) {
            diffs.push({
              level: "note",
              text:
                "@type 本站=" +
                String(ourType) +
                " / 示例=" +
                String(exampleType) +
                (type === "Organization"
                  ? "（电商推荐 OnlineStore，属 Organization 子类，可接受）"
                  : "")
            });
          } else {
            diffs.push({ level: "pass", text: "@type 与官方示例一致：" + String(exampleType) });
          }
          return;
        }
        if (ourNode[key] === undefined || ourNode[key] === null || ourNode[key] === "") {
          diffs.push({ level: "warn", text: "相对官方示例，本站缺字段：" + key });
        }
      });

      if (type === "BreadcrumbList") {
        var crumbs = Array.isArray(ourNode.itemListElement) ? ourNode.itemListElement : [];
        if (crumbs.length) {
          var lastCrumb = crumbs[crumbs.length - 1];
          if (lastCrumb && lastCrumb.item) {
            diffs.push({ level: "warn", text: "官方示例末级 ListItem 省略 item；本站末级仍带 item" });
          } else {
            diffs.push({ level: "pass", text: "末级省略 item：与 Google 示例一致" });
          }
        }
        if (ourNode["@id"]) {
          diffs.push({ level: "fail", text: "官方示例无 BreadcrumbList.@id；本站有 @id" });
        } else {
          diffs.push({ level: "pass", text: "无 BreadcrumbList.@id：与 Google 示例一致" });
        }
      }

      if (type === "WebSite") {
        if (ourNode.potentialAction) {
          diffs.push({ level: "pass", text: "已含 potentialAction（SearchAction）" });
        } else {
          diffs.push({ level: "warn", text: "官方示例含 potentialAction；本站缺失" });
        }
      }

      if (type === "ItemList") {
        diffs.push({
          level: "note",
          text: "若嵌套 Product：不是 Google Carousel 文档范围；商品请用详情页 Product"
        });
      }

      (pack.notes || []).slice(0, 3).forEach(function (note) {
        diffs.push({ level: "note", text: note });
      });
    }

    return {
      docUrl: pack.docUrl,
      title: pack.title,
      notes: pack.notes || [],
      exampleJson: JSON.stringify(example, null, 2),
      ourJson: our ? JSON.stringify(our, null, 2) : "",
      diffs: diffs.slice(0, 14)
    };
  }

  function renderGoogleCompareHtml(compare) {
    if (!compare) return "";
    var diffs = (compare.diffs || [])
      .map(function (row) {
        var level = row && row.level ? row.level : "note";
        return (
          '<li class="is-' +
          escapeHtml(level) +
          '">' +
          escapeHtml((row && row.text) || "") +
          "</li>"
        );
      })
      .join("");
    return (
      '<details class="weline-seo-panel__google-compare" open>' +
      "<summary>对照 Google 官方示例</summary>" +
      '<p class="weline-seo-panel__hint">' +
      '<a href="' +
      escapeHtml(compare.docUrl || "#") +
      '" target="_blank" rel="noopener noreferrer">Search Central 文档</a>' +
      " · " +
      escapeHtml(compare.title || "") +
      "</p>" +
      (diffs
        ? '<ul class="weline-seo-panel__google-compare-diffs">' + diffs + "</ul>"
        : "") +
      '<div class="weline-seo-panel__google-compare-grid">' +
      "<div>" +
      '<span class="weline-seo-panel__google-compare-label">本站输出</span>' +
      "<pre>" +
      escapeHtml(compare.ourJson || "（未输出）") +
      "</pre>" +
      "</div>" +
      "<div>" +
      '<span class="weline-seo-panel__google-compare-label">Google 官方示例</span>' +
      "<pre>" +
      escapeHtml(compare.exampleJson || "") +
      "</pre>" +
      "</div>" +
      "</div>" +
      "</details>"
    );
  }

  function evaluateLocalRichItem(node, rule, seoType) {
    var issues = [];
    var warnings = [];
    var notes = [];
    var pageType = normalizeSeoType(seoType || "");
    (rule.required || []).forEach(function (path) {
      if (!hasJsonLdPath(node, path)) {
        issues.push("缺少必填字段 " + formatSchemaField(path));
      }
    });
    if (Array.isArray(rule.offerRequiredAny) && rule.offerRequiredAny.length) {
      var hasOfferShape = rule.offerRequiredAny.some(function (path) { return hasJsonLdPath(node, path); });
      if (!hasOfferShape) {
        issues.push("商品摘要需提供 offers、hasVariant、aggregateRating 或 review（Google product snippet）");
      }
    }
    if (rule.type === "FAQPage") {
      var faqCheck = validateFaqJsonLd(node);
      if (faqCheck.level === "fail") {
        issues.push(faqCheck.detail);
      }
    }
    (rule.recommended || []).forEach(function (path) {
      if (!hasJsonLdPath(node, path)) {
        warnings.push("建议补充 " + formatSchemaField(path));
      }
    });
    // AggregateRating only when the page already has review facts — never invent ratings.
    if (rule.type === "Product"
      && (hasJsonLdPath(node, "review") || hasJsonLdPath(node, "aggregateRating"))
      && !hasJsonLdPath(node, "aggregateRating.ratingValue")
    ) {
      warnings.push("建议补充 aggregateRating.ratingValue");
    }
    if (rule.type === "Product" && (hasJsonLdPath(node, "review") || hasJsonLdPath(node, "aggregateRating"))) {
      var ratingValue = firstJsonLdScalar(node, "aggregateRating.ratingValue");
      var reviewCount = firstJsonLdScalar(node, "aggregateRating.reviewCount");
      if (!reviewCount) {
        var embedded = node.review;
        if (Array.isArray(embedded)) reviewCount = String(embedded.length);
        else if (embedded) reviewCount = "1";
      }
      if (ratingValue || reviewCount) {
        notes.push(
          "已对接评论评分 " +
          (ratingValue || "—") +
          (reviewCount ? "（" + reviewCount + " 条）" : "")
        );
      }
    }
    if (rule.type === "CollectionPage") {
      if (pageType === "blog_list" || pageType === "blog_category") {
        notes.push("博客列表 CollectionPage + Article/BlogPosting ItemList：页面发现信号（非电商 Product 富结果）");
      } else {
        warnings.push("CollectionPage 不是 Google 电商列表富结果文档类型；列表页请用 BreadcrumbList + 站点 Organization/WebSite");
      }
    }
    if (rule.type === "BreadcrumbList") {
      var crumbItems = Array.isArray(node.itemListElement) ? node.itemListElement : [];
      if (crumbItems.length < 2) {
        issues.push("Google BreadcrumbList 需要至少 2 个 ListItem");
      }
      var missingNames = 0;
      var fragmentOnly = 0;
      var nonAbsolute = 0;
      crumbItems.forEach(function (item, index) {
        var crumbName = flattenJsonLdText(
          item.name || (item.item && item.item.name) || ""
        );
        var crumbUrl = "";
        if (typeof item.item === "string") crumbUrl = item.item.trim();
        else if (item.item && typeof item.item === "object") {
          crumbUrl = flattenJsonLdText(item.item.url || item.item["@id"] || "");
        }
        if (!crumbName) missingNames += 1;
        if (crumbUrl && /#breadcrumb\b/i.test(crumbUrl)) fragmentOnly += 1;
        var isLast = index === crumbItems.length - 1;
        // Google examples: non-last item is absolute URL; last ListItem usually omits item.
        if (!isLast) {
          if (!crumbUrl || !/^https?:\/\//i.test(crumbUrl)) nonAbsolute += 1;
        } else if (crumbUrl && !/^https?:\/\//i.test(crumbUrl)) {
          nonAbsolute += 1;
        }
      });
      if (missingNames) {
        issues.push("有 " + missingNames + " 级缺少 name（Google ListItem 必填）");
      }
      if (fragmentOnly) {
        issues.push("ListItem.item 不能写 …#breadcrumb；应写该级页面的绝对 URL（见 Google BreadcrumbList 示例）");
      }
      if (nonAbsolute) {
        issues.push("ListItem.item 须为绝对 URL（Google BreadcrumbList 示例）；相对路径如 / 不合规");
      }
      if (node["@id"] && /#breadcrumb\b/i.test(String(node["@id"]))) {
        issues.push("不要给 BreadcrumbList 加 @id …#breadcrumb；Google 示例只有 itemListElement");
      }
    }
    if (rule.type === "ItemList") {
      var listItems = Array.isArray(node.itemListElement) ? node.itemListElement : [];
      var nestedProduct = listItems.some(function (entry) {
        var listed = entry && entry.item ? entry.item : entry;
        var t = listed && listed["@type"];
        if (Array.isArray(t)) return t.indexOf("Product") !== -1 || t.indexOf("ProductGroup") !== -1;
        return t === "Product" || t === "ProductGroup";
      });
      var nestedArticle = listItems.some(function (entry) {
        var listed = entry && entry.item ? entry.item : entry;
        var t = listed && listed["@type"];
        if (Array.isArray(t)) {
          return t.indexOf("Article") !== -1 || t.indexOf("BlogPosting") !== -1 || t.indexOf("NewsArticle") !== -1;
        }
        return t === "Article" || t === "BlogPosting" || t === "NewsArticle";
      });
      if (nestedProduct) {
        warnings.push("Google Carousel/ItemList 不支持电商 Product；商品富结果应在详情页 Product/ProductGroup 上，列表页请移除该 ItemList");
      } else if (nestedArticle || pageType === "blog_list" || pageType === "blog_category") {
        notes.push("博客 Article/BlogPosting ItemList：列表发现信号（页面输出完整条目，非省略占位）");
      } else {
        notes.push("ItemList 仅当与 Course/Movie/Recipe/Restaurant 组合时才是 Google Carousel 富结果");
      }
    }
    var status = issues.length ? "fail" : warnings.length ? "warn" : "pass";
    var preview = localRichItemPreview(node, rule);
    return {
      type: rule.type,
      label: rule.label,
      name: preview.title || rule.type,
      facts: preview.facts || [],
      status: status,
      issues: issues,
      warnings: warnings.slice(0, 8),
      notes: notes.slice(0, 6),
      expectation: "present",
      googleCompare: buildGoogleCompare(rule.type, node)
    };
  }

  function firstJsonLdScalar(node, path) {
    var values = [];
    String(path || "")
      .split("|")
      .forEach(function (candidate) {
        var parts = candidate.split(".").filter(Boolean);
        jsonLdValuesAtPath(node, parts).forEach(function (value) {
          if (value === null || value === undefined) return;
          if (typeof value === "object") return;
          var text = String(value).trim();
          if (text) values.push(text);
        });
      });
    return values[0] || "";
  }

  function missingRichExpectationItem(rule, severity) {
    return {
      type: rule.type,
      label: rule.label,
      name: "未输出",
      facts: [],
      status: severity === "optional" ? "warn" : "fail",
      issues: severity === "optional" ? [] : ["本页类型期望有 " + rule.label + "（" + rule.type + "），但 JSON-LD 中未检测到"],
      warnings: severity === "optional"
        ? ["建议补充 " + rule.label + "（" + rule.type + "）结构化数据"]
        : [],
      expectation: severity === "optional" ? "optional-missing" : "required-missing",
      googleCompare: buildGoogleCompare(rule.type, null)
    };
  }

  function buildLocalRichReport(payload) {
    payload = payload || {};
    var nodes = Array.isArray(payload.nodes) ? payload.nodes : [];
    var types = Array.isArray(payload.types) ? payload.types : [];
    var parseErrors = Array.isArray(payload.parseErrors) ? payload.parseErrors : [];
    var seoType = normalizeSeoType(
      payload.seoType || inferSeoTypeForRich(payload.doc || null, payload.url || "", types)
    );
    var expectations = resolvePageRichExpectations(seoType);
    var requiredTypes = expectations.required || [];
    var optionalTypes = expectations.optional || [];
    var items = [];
    var matched = {};
    var coveredRuleTypes = {};

    LOCAL_RICH_RESULT_RULES.forEach(function (rule) {
      var found = false;
      nodes.forEach(function (node) {
        if (!localRichNodeMatches(node, rule)) return;
        var key = rule.type + "::" + (
          node["@id"] ||
          node.name ||
          node.headline ||
          (node.author && node.author.name) ||
          (node.reviewBody ? String(node.reviewBody).slice(0, 24) : "") ||
          items.length
        );
        if (matched[key]) return;
        matched[key] = true;
        found = true;
        coveredRuleTypes[rule.type] = true;
        items.push(evaluateLocalRichItem(node, rule, seoType));
      });
      if (found) return;

      if (requiredTypes.indexOf(rule.type) !== -1) {
        coveredRuleTypes[rule.type] = true;
        items.push(missingRichExpectationItem(rule, "required"));
        return;
      }
      if (optionalTypes.indexOf(rule.type) !== -1) {
        coveredRuleTypes[rule.type] = true;
        items.push(missingRichExpectationItem(rule, "optional"));
      }
    });

    // Stable order: required expectations first, then optional, then opportunistic extras.
    var order = requiredTypes.concat(optionalTypes);
    items.sort(function (a, b) {
      var ai = order.indexOf(a.type);
      var bi = order.indexOf(b.type);
      if (ai === -1) ai = 1000;
      if (bi === -1) bi = 1000;
      if (ai !== bi) return ai - bi;
      return String(a.label || "").localeCompare(String(b.label || ""));
    });

    if (parseErrors.length) {
      items.unshift({
        type: "ParseError",
        label: "JSON-LD 解析",
        name: "无效 JSON-LD",
        status: "fail",
        issues: parseErrors.slice(0, 5),
        warnings: [],
        expectation: "parse"
      });
    }

    var pass = items.filter(function (item) { return item.status === "pass"; }).length;
    var warn = items.filter(function (item) { return item.status === "warn"; }).length;
    var fail = items.filter(function (item) { return item.status === "fail"; }).length;
    var status = fail ? "fail" : warn ? "warn" : items.length ? "pass" : "empty";
    var pageRule = jsonLdRuleForSeoType(seoType);
    var requestedUrl = String(payload.requestedUrl || payload.url || "");
    var finalUrl = String(payload.finalUrl || payload.url || "");
    var requestedKind = inferSeoTypeFromUrlPath(requestedUrl);
    var finalKind = inferSeoTypeFromUrlPath(finalUrl) || seoType;
    var routeConflict = "";
    if (requestedKind && finalKind && requestedKind !== finalKind) {
      var requestedRule = jsonLdRuleForSeoType(requestedKind);
      var finalRule = jsonLdRuleForSeoType(finalKind);
      routeConflict =
        "请求 URL 像「" +
        ((requestedRule && requestedRule.label) || requestedKind) +
        "」，但最终落到「" +
        ((finalRule && finalRule.label) || finalKind) +
        "」（常见原因：301 跳转 / 商品不存在）。以下按最终页面检测，不是按输入框里的商品路径硬判。";
    }

    return {
      url: finalUrl || payload.url || "",
      requestedUrl: requestedUrl,
      finalUrl: finalUrl,
      routeConflict: routeConflict,
      source: payload.source || "local",
      generatedAt: new Date().toISOString(),
      seoType: seoType,
      pageLabel: (pageRule && pageRule.label) || seoType,
      expectedRequired: requiredTypes.slice(),
      expectedOptional: optionalTypes.slice(),
      catalogSize: LOCAL_RICH_RESULT_RULES.length,
      types: types.filter(function (type) { return type !== "INVALID_JSON"; }),
      hierarchy: null,
      summary: {
        status: status,
        items: items.length,
        pass: pass,
        warn: warn,
        fail: fail
      },
      items: items,
      note: "按 Google Search Central 文档化富结果类型检测；不替代官方富媒体测试。"
    };
  }

  function buildCollectionHierarchyHint() {
    // Intentionally unused: ecommerce CollectionPage→ItemList hierarchy is not a Google rich result.
    return null;
  }

  function analyzeLocalRichFromDocument(doc, url, source, options) {
    options = options || {};
    var extracted = collectJsonLdFromRoot(doc);
    var finalUrl = String(options.finalUrl || url || "");
    var requestedUrl = String(options.requestedUrl || url || "");
    return buildLocalRichReport({
      url: finalUrl,
      requestedUrl: requestedUrl,
      finalUrl: finalUrl,
      source: source || "dom",
      doc: doc,
      nodes: extracted.nodes,
      types: extracted.types,
      parseErrors: extracted.parseErrors
    });
  }

  function analyzeLocalRichFromCrawlPage(page, requestedUrl, finalUrl) {
    var jsonLd = (page && page.jsonLd) || {};
    var resolvedFinal = String(finalUrl || page.finalUrl || page.url || requestedUrl || "");
    return buildLocalRichReport({
      url: resolvedFinal,
      requestedUrl: String(requestedUrl || resolvedFinal),
      finalUrl: resolvedFinal,
      source: "server-fetch",
      seoType: page && (page.seoType || page.page_type || page.pageType) || "",
      nodes: Array.isArray(jsonLd.nodes) ? jsonLd.nodes : [],
      types: Array.isArray(jsonLd.types) ? jsonLd.types : [],
      parseErrors: Array.isArray(jsonLd.errors) ? jsonLd.errors : []
    });
  }

  function publishLocalRichReport(report) {
    latestLocalRichReport = report || null;
    try {
      window.__WELINE_PANEL_SEO_LOCAL_RICH_REPORT__ = latestLocalRichReport;
    } catch (_e) {}
  }

  function renderLocalRichReport(report) {
    if (!report) return "";
    var summary = report.summary || {};
    var status = summary.status || "empty";
    var tone = status === "pass" ? "pass" : status === "warn" ? "warn" : status === "fail" ? "fail" : "info";
    var expectedRequired = Array.isArray(report.expectedRequired) ? report.expectedRequired : [];
    var expectedOptional = Array.isArray(report.expectedOptional) ? report.expectedOptional : [];
    var pageLabel = String(report.pageLabel || report.seoType || "未知页面");
    var seoType = String(report.seoType || "");
    var detectedTypes = Array.isArray(report.types) ? report.types : [];
    var typeChips = detectedTypes
      .map(function (type) {
        return '<span class="weline-seo-panel__local-rich-chip">' + escapeHtml(String(type)) + "</span>";
      })
      .join("");
    var expectChips = expectedRequired
      .map(function (type) {
        return '<span class="weline-seo-panel__local-rich-chip weline-seo-panel__local-rich-chip--expect">' + escapeHtml(String(type)) + "</span>";
      })
      .join("");
    var pageBanner =
      '<div class="weline-seo-panel__local-rich-pagetype' +
      (report.routeConflict ? " weline-seo-panel__local-rich-pagetype--conflict" : "") +
      '" role="status">' +
      '<span class="weline-seo-panel__local-rich-pagetype-kicker">' +
      (report.routeConflict ? "最终页类型（有跳转）" : "判定页类型") +
      "</span>" +
      '<strong class="weline-seo-panel__local-rich-pagetype-label">' +
      escapeHtml(pageLabel) +
      "</strong>" +
      (seoType
        ? '<code class="weline-seo-panel__local-rich-pagetype-code">' + escapeHtml(seoType) + "</code>"
        : "") +
      (report.routeConflict
        ? '<p class="weline-seo-panel__local-rich-pagetype-conflict">' + escapeHtml(report.routeConflict) + "</p>"
        : '<p class="weline-seo-panel__local-rich-pagetype-hint">对照下方卡片：若页类型与最终 URL/内容不符，说明检测器映射可能有误。</p>') +
      (report.requestedUrl && report.finalUrl && report.requestedUrl !== report.finalUrl
        ? '<p class="weline-seo-panel__local-rich-pagetype-urls"><span>请求</span><code>' +
          escapeHtml(report.requestedUrl) +
          "</code><span>最终</span><code>" +
          escapeHtml(report.finalUrl) +
          "</code></p>"
        : "") +
      (expectChips
        ? '<div class="weline-seo-panel__local-rich-chips"><span class="weline-seo-panel__local-rich-chips-label">期望</span>' +
          expectChips +
          "</div>"
        : "") +
      (typeChips
        ? '<div class="weline-seo-panel__local-rich-chips"><span class="weline-seo-panel__local-rich-chips-label">检出</span>' +
          typeChips +
          "</div>"
        : "") +
      "</div>";
    var head =
      '<section class="weline-seo-panel__section weline-seo-panel__section--local-rich">' +
      "<h3>本地富结果检测结果</h3>" +
      pageBanner +
      '<p class="weline-seo-panel__hint">' +
      escapeHtml(report.note || "") +
      " · 来源 " +
      escapeHtml(report.source || "local") +
      " · 目录 " +
      escapeHtml(String(report.catalogSize || LOCAL_RICH_RESULT_RULES.length)) +
      " 类" +
      " · 每张卡片含 Google 官方示例对照" +
      (expectedOptional.length
        ? " · 建议：" + escapeHtml(expectedOptional.join(", "))
        : "") +
      "</p>" +
      '<div class="weline-seo-panel__local-rich-summary weline-seo-panel__local-rich-summary--' +
      escapeHtml(tone) +
      '">' +
      "<strong>" +
      escapeHtml(String(summary.items || 0)) +
      " 项</strong>" +
      "<span>通过 " +
      escapeHtml(String(summary.pass || 0)) +
      "</span><span>警告 " +
      escapeHtml(String(summary.warn || 0)) +
      "</span><span>失败 " +
      escapeHtml(String(summary.fail || 0)) +
      "</span>" +
      "<code>" +
      escapeHtml(report.url || "") +
      "</code></div>";

    var hierarchy = report.hierarchy;
    var hierarchyHtml = "";
    if (hierarchy) {
      hierarchyHtml =
        '<div class="weline-seo-panel__local-rich-hierarchy" role="note">' +
        "<strong>集合页层级（JSON-LD）</strong>" +
        "<pre>" +
        escapeHtml(
          "CollectionPage  " +
            (hierarchy.collectionName || "") +
            "\n└─ mainEntity → ItemList  " +
            (hierarchy.itemListId || "") +
            "\n   └─ 本页 " +
            String(hierarchy.itemCount || 0) +
            " 件 Product/ListItem" +
            (hierarchy.sampleNames && hierarchy.sampleNames.length
              ? "\n      · " + hierarchy.sampleNames.join("\n      · ")
              : "")
        ) +
        "</pre>" +
        '<p class="weline-seo-panel__hint">' +
        escapeHtml(hierarchy.note || "") +
        "</p></div>";
    }

    if (!report.items || !report.items.length) {
      return (
        head +
        hierarchyHtml +
        '<p class="weline-seo-panel__hint">未发现可识别的富结果结构化数据（JSON-LD）。可检查 head/body 中的 application/ld+json。</p>' +
        "</section>"
      );
    }

    var cards = report.items
      .map(function (item) {
        var issues = (item.issues || [])
          .map(function (line) {
            return "<li>" + escapeHtml(line) + "</li>";
          })
          .join("");
        var warnings = (item.warnings || [])
          .map(function (line) {
            return "<li>" + escapeHtml(line) + "</li>";
          })
          .join("");
        var notes = (item.notes || [])
          .map(function (line) {
            return "<li>" + escapeHtml(line) + "</li>";
          })
          .join("");
        var expectationHint = "";
        if (item.expectation === "required-missing") {
          expectationHint = '<p class="weline-seo-panel__hint">期望类型缺失（必测）。</p>';
        } else if (item.expectation === "optional-missing") {
          expectationHint = '<p class="weline-seo-panel__hint">建议类型缺失（可选）。</p>';
        }
        var factsHtml = "";
        if (item.facts && item.facts.length) {
          factsHtml =
            '<dl class="weline-seo-panel__local-rich-facts">' +
            item.facts
              .map(function (fact) {
                return (
                  "<div><dt>" +
                  escapeHtml(fact.label || "") +
                  "</dt><dd>" +
                  escapeHtml(fact.value || "") +
                  "</dd></div>"
                );
              })
              .join("") +
            "</dl>";
        }
        return (
          '<article class="weline-seo-panel__local-rich-card weline-seo-panel__local-rich-card--' +
          escapeHtml(item.status) +
          '">' +
          '<div class="weline-seo-panel__issue-head">' +
          '<span class="weline-seo-panel__badge weline-seo-panel__badge--' +
          escapeHtml(item.status === "pass" ? "pass" : item.status === "warn" ? "warn" : "fail") +
          '">' +
          escapeHtml(item.status === "pass" ? "通过" : item.status === "warn" ? "警告" : "失败") +
          "</span><strong>" +
          escapeHtml(item.label || item.type) +
          "</strong></div>" +
          (item.name
            ? '<p class="weline-seo-panel__local-rich-title">' + escapeHtml(item.name) + "</p>"
            : "") +
          factsHtml +
          (issues ? '<ul class="weline-seo-panel__local-rich-list is-fail">' + issues + "</ul>" : "") +
          (warnings ? '<ul class="weline-seo-panel__local-rich-list is-warn">' + warnings + "</ul>" : "") +
          (notes ? '<ul class="weline-seo-panel__local-rich-list is-note">' + notes + "</ul>" : "") +
          expectationHint +
          (!issues && !warnings && !notes && !expectationHint ? '<p class="weline-seo-panel__hint">必填字段齐全。</p>' : "") +
          renderGoogleCompareHtml(item.googleCompare) +
          "</article>"
        );
      })
      .join("");

    return (
      head +
      hierarchyHtml +
      '<div class="weline-seo-panel__local-rich-list-wrap">' +
      cards +
      "</div>" +
      "</section>"
    );
  }

  function defaultToolUrl() {
    return window.location.href;
  }

  function currentToolUrlPageKey() {
    try {
      var loc = new URL(window.location.href);
      var path = String(loc.pathname || "/").replace(/\/+$/, "") || "/";
      return String(loc.origin || "") + path;
    } catch (_e) {
      return String(window.location.pathname || "/").replace(/\/+$/, "") || "/";
    }
  }

  function toolUrlPageKey(url) {
    try {
      var loc = new URL(String(url || ""), window.location.href);
      var path = String(loc.pathname || "/").replace(/\/+$/, "") || "/";
      return String(loc.origin || "") + path;
    } catch (_e) {
      return "";
    }
  }

  function resolvePersistedToolUrl() {
    var state = readPanelState();
    var pageKey = currentToolUrlPageKey();
    var savedPageKey = String((state && state.toolUrlPageKey) || "").trim();
    var url = String((state && state.toolUrl) || "").trim();
    var sameBrowsePage = !savedPageKey || savedPageKey === pageKey;
    var sameTargetPath = !!url && toolUrlPageKey(url) === pageKey;
    // Sticky only when both the browse page and the detection URL path match.
    // Prevents foreign product slugs (e.g. you-ai-…) from sticking on another PDP.
    if (sameBrowsePage && sameTargetPath) {
      return url;
    }
    var fallback = defaultToolUrl();
    if (url && (!sameBrowsePage || !sameTargetPath)) {
      savePanelState({
        toolUrl: fallback,
        toolUrlPageKey: pageKey
      });
    }
    return fallback;
  }

  function readToolUrlFromScope(scope, attrSelector) {
    var input = scope && scope.querySelector(attrSelector);
    var value = input ? String(input.value || "").trim() : "";
    return value || resolvePersistedToolUrl();
  }

  function persistToolUrl(url) {
    var next = String(url || "").trim() || defaultToolUrl();
    var pageKey = currentToolUrlPageKey();
    // Cross-path URLs stay usable for one-shot tests via the input, but must not
    // become the sticky default for the current browse page.
    if (toolUrlPageKey(next) === pageKey) {
      savePanelState({
        toolUrl: next,
        toolUrlPageKey: pageKey
      });
    } else {
      savePanelState({
        toolUrl: defaultToolUrl(),
        toolUrlPageKey: pageKey
      });
    }
    return next;
  }

  function isSameAsCurrentPage(url) {
    try {
      return toolUrlPageKey(url) === currentToolUrlPageKey();
    } catch (e) {
      return String(url || "").trim() === window.location.href;
    }
  }

  function syncToolUrlFormChrome(root) {
    var forms = root.querySelectorAll(".weline-seo-panel__crawl-form--url-tool");
    forms.forEach(function (form) {
      var input = form.querySelector("[data-weline-tool-url]");
      if (!input) {
        return;
      }
      var samePage = isSameAsCurrentPage(input.value);
      input.setAttribute("title", String(input.value || "").trim() || defaultToolUrl());
      var mismatch = form.querySelector(".weline-seo-panel__url-mismatch");
      if (!samePage && !mismatch) {
        mismatch = document.createElement("p");
        mismatch.className = "weline-seo-panel__url-mismatch";
        mismatch.setAttribute("role", "status");
        mismatch.textContent =
          "检测 URL 与地址栏当前页不一致。请先对齐当前页，或确认你要测的是另一个地址。";
        form.insertBefore(mismatch, form.firstChild);
      } else if (samePage && mismatch) {
        mismatch.remove();
      }
      var actions = form.querySelector(".weline-seo-panel__url-inline-actions");
      if (!actions) {
        return;
      }
      var copyBtn = actions.querySelector("[data-weline-tool-url-copy-site]");
      var syncBtn = actions.querySelector("[data-weline-tool-url-current]");
      var runBtn = actions.querySelector(
        "[data-weline-rich-local-test], [data-weline-page-audit], [data-weline-tool-run]"
      );
      if (!syncBtn || !runBtn) {
        return;
      }
      // Prefer: when mismatched, sync button is publish-btn (primary). Keep 复制 first.
      if (!samePage) {
        syncBtn.className = "weline-seo-panel__publish-btn";
        runBtn.className = "weline-seo-panel__btn";
        if (runBtn.compareDocumentPosition(syncBtn) & Node.DOCUMENT_POSITION_FOLLOWING) {
          actions.insertBefore(syncBtn, runBtn);
        }
      } else {
        runBtn.className = "weline-seo-panel__publish-btn";
        syncBtn.className = "weline-seo-panel__btn";
        if (syncBtn.compareDocumentPosition(runBtn) & Node.DOCUMENT_POSITION_FOLLOWING) {
          actions.insertBefore(runBtn, syncBtn);
        }
      }
      if (copyBtn && actions.firstElementChild !== copyBtn) {
        actions.insertBefore(copyBtn, actions.firstElementChild);
      }
    });
  }

  function googleRichResultsTestUrl(pageUrl) {
    return (
      "https://search.google.com/test/rich-results?url=" +
      encodeURIComponent(pageUrl) +
      "&hl=zh-cn"
    );
  }

  function renderToolUrlForm(options) {
    options = options || {};
    var url = options.url || resolvePersistedToolUrl();
    var running = !!options.running;
    var buttonLabel = options.buttonLabel || "开始检测";
    var buttonAttr = options.buttonAttr || "data-weline-tool-run";
    var useCurrentAttr = options.useCurrentAttr || "";
    var samePage = isSameAsCurrentPage(url);
    var primaryIsSync = !samePage && !!useCurrentAttr;
    var primaryAttr = primaryIsSync ? useCurrentAttr : buttonAttr;
    var primaryLabel = primaryIsSync ? "填入当前页" : buttonLabel;
    var secondaryHtml = "";
    if (useCurrentAttr) {
      if (primaryIsSync) {
        secondaryHtml =
          '<button type="button" class="weline-seo-panel__btn" ' +
          buttonAttr +
          " " +
          (running ? "disabled" : "") +
          ">" +
          (running ? "检测中…" : escapeHtml(buttonLabel)) +
          "</button>";
      } else {
        secondaryHtml =
          '<button type="button" class="weline-seo-panel__btn" ' +
          useCurrentAttr +
          " " +
          (running ? "disabled" : "") +
          ">填入当前页</button>";
      }
    }
    return (
      '<div class="weline-seo-panel__crawl-form weline-seo-panel__crawl-form--url-tool">' +
      (!samePage
        ? '<p class="weline-seo-panel__url-mismatch" role="status">检测 URL 与地址栏当前页不一致。请先对齐当前页，或确认你要测的是另一个地址。</p>'
        : "") +
      '<label class="weline-seo-panel__url-field"><span>检测 URL</span>' +
      '<div class="weline-seo-panel__url-field-row">' +
      '<input type="url" data-weline-tool-url value="' +
      escapeHtml(url) +
      '" placeholder="' +
      escapeHtml(defaultToolUrl()) +
      '" title="' +
      escapeHtml(url) +
      '">' +
      '<div class="weline-seo-panel__url-inline-actions">' +
      '<button type="button" class="weline-seo-panel__btn" data-weline-tool-url-copy-site title="复制本站当前页正确地址">复制</button>' +
      '<button type="button" class="weline-seo-panel__publish-btn" ' +
      primaryAttr +
      " " +
      (running ? "disabled" : "") +
      ">" +
      (running && !primaryIsSync ? "检测中…" : escapeHtml(primaryLabel)) +
      "</button>" +
      secondaryHtml +
      "</div></div></label>" +
      "</div>"
    );
  }

  function renderLocalStructuredDataSummary(report) {
    var snapshot = (report && report.snapshot) || {};
    var types = Array.isArray(snapshot.jsonTypes) ? snapshot.jsonTypes : [];
    var validation = snapshot.jsonLdValidation || null;
    return (
      '<section class="weline-seo-panel__section"><h3>当前打开页 · 本地结构化数据</h3>' +
      '<p class="weline-seo-panel__hint">仅当检测 URL 等于当前打开页时可用。正式资格以 Google「富媒体搜索结果测试」为准。</p>' +
      '<dl class="weline-seo-panel__grid">' +
      '<div class="weline-seo-panel__field"><dt>JSON-LD types</dt><dd>' +
      escapeHtml(types.join(", ") || "none") +
      "</dd></div>" +
      '<div class="weline-seo-panel__field"><dt>Contract</dt><dd>' +
      escapeHtml(
        validation
          ? validation.label + " · " + validation.expectedType + " · " + validation.status
          : "unknown"
      ) +
      "</dd></div>" +
      "</dl></section>"
    );
  }

  function renderRichResultsTab(report) {
    var url = resolvePersistedToolUrl();
    var gscBox = window.__WELINE_SEO_GSC_RESULT__
      ? '<pre class="weline-seo-panel__gsc-result">' + escapeHtml(JSON.stringify(window.__WELINE_SEO_GSC_RESULT__, null, 2)) + "</pre>"
      : '<p class="weline-seo-panel__hint" data-weline-seo-gsc-status>尚未查询。需已绑定 Google Search Console，且仅在面板授权后调用。</p>';
    var samePage = isSameAsCurrentPage(url);
    var status = localRichRunning
      ? '<p class="weline-seo-panel__crawl-status is-running">正在本地解析结构化数据…</p>'
      : (localRichStatus ? '<p class="weline-seo-panel__crawl-status">' + escapeHtml(localRichStatus) + "</p>" : "");
    var error = localRichError
      ? '<p class="weline-seo-panel__crawl-status is-error">' + escapeHtml(localRichError) + "</p>"
      : "";
    return (
      '<section class="weline-seo-panel__section weline-seo-panel__section--tools">' +
      "<h3>富文本 / 富结果检测</h3>" +
      '<p class="weline-seo-panel__hint">本机/内网域名 Google 官方测不了。请优先用<strong>本地测试</strong>（解析页面 JSON-LD）。公开域名仍可打开 Google 富媒体测试或查 GSC。</p>' +
      renderToolUrlForm({
        url: url,
        running: localRichRunning,
        buttonLabel: "本地测试",
        buttonAttr: "data-weline-rich-local-test",
        useCurrentAttr: "data-weline-tool-url-current"
      }) +
      '<div class="weline-seo-panel__tool-actions">' +
      '<button type="button" class="weline-seo-panel__btn" data-weline-rich-open-google>打开 Google 测试</button>' +
      '<button type="button" class="weline-seo-panel__btn" data-weline-seo-gsc-inspect>查询 GSC 索引/富结果</button>' +
      '<button type="button" class="weline-seo-panel__btn" data-weline-seo-copy-html ' +
      (samePage ? "" : 'disabled title="仅当前打开页可复制 HTML"') +
      ">复制当前页 HTML</button>" +
      "</div>" +
      '<div data-weline-seo-tool-status class="weline-seo-panel__hint"></div>' +
      status +
      error +
      renderLocalRichReport(latestLocalRichReport) +
      renderEeatStrictSection(latestEeatStrictReport || buildEeatStrictReport((report && report.checks) || [])) +
      gscBox +
      (samePage ? renderLocalStructuredDataSummary(report) : "") +
      "</section>"
    );
  }

  function renderPageAuditTab() {
    var report = latestPageAuditReport;
    var url = resolvePersistedToolUrl();
    var status = pageAuditRunning
      ? '<p class="weline-seo-panel__crawl-status is-running">正在服务端抓取并检测 URL…</p>'
      : (pageAuditStatus ? '<p class="weline-seo-panel__crawl-status">' + escapeHtml(pageAuditStatus) + "</p>" : "");
    var error = pageAuditError
      ? '<p class="weline-seo-panel__crawl-status is-error">' + escapeHtml(pageAuditError) + "</p>"
      : "";
    return (
      '<section class="weline-seo-panel__section weline-seo-panel__section--page-audit">' +
      "<h3>当前页服务端检测</h3>" +
      '<p class="weline-seo-panel__hint">用与全站审计相同的服务端抓取规则检测单个 URL（搜索引擎视角 HTML）。默认填当前打开页，可改成站点内其他地址。「SEO 校验」Tab 仍是浏览器侧即时检查。</p>' +
      renderToolUrlForm({
        url: url,
        running: pageAuditRunning,
        buttonLabel: "开始检测",
        buttonAttr: "data-weline-page-audit",
        useCurrentAttr: "data-weline-tool-url-current"
      }) +
      status +
      error +
      (report
        ? renderSiteCrawlHealth(report) +
          renderSiteCrawlDeductions(report) +
          renderSiteCrawlIssues(report) +
          renderSiteCrawlPages(report) +
          renderSiteCrawlFailures(report)
        : "") +
      "</section>"
    );
  }

  function renderSeoTab(report) {
    var a11yTeaser =
      latestAccessibilityCompletenessReport ||
      buildAccessibilityCompletenessReport((report && report.checks) || []);
    var teaserTone = a11yTeaser.complete ? "pass" : a11yTeaser.status === "fail" ? "fail" : "warn";
    return (
      renderSummary(report.seoSummary) +
      '<section class="weline-seo-panel__section weline-seo-panel__section--a11y-teaser">' +
      "<h3>无障碍声明完整度</h3>" +
      '<p class="weline-seo-panel__hint">快捷查看：' +
      '<span class="weline-seo-panel__badge weline-seo-panel__badge--' +
      escapeHtml(teaserTone === "tip" ? "tip" : teaserTone) +
      '">' +
      escapeHtml(a11yTeaser.complete ? "完整" : formatCheckLevel(a11yTeaser.status)) +
      "</span> · 通过 " +
      escapeHtml(String((a11yTeaser.summary && a11yTeaser.summary.pass) || 0)) +
      " / 共 " +
      escapeHtml(String((a11yTeaser.summary && a11yTeaser.summary.total) || 0)) +
      ' · <button type="button" class="weline-seo-panel__btn" data-weline-tab="a11y">打开「无障碍」Tab</button></p>' +
      "</section>" +
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
    return ["seo", "a11y", "engines", "crawl", "page", "rich"].indexOf(tabId) !== -1 ? tabId : "seo";
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

  function closestActionTarget(target, selector, scope) {
    var node = target && target.nodeType === 1 ? target : (target && target.parentElement);
    if (!node || typeof node.closest !== "function") return null;
    var match = node.closest(selector);
    return match && scope && scope.contains(match) ? match : null;
  }

  function setToolStatus(root, message) {
    var node = root.querySelector("[data-weline-seo-tool-status]");
    if (node) {
      node.textContent = message || "";
    }
  }

  function buildRichResultsHtmlExport() {
    var clone = document.documentElement.cloneNode(true);
    clone.querySelectorAll(
      "[data-weline-panel], [data-weline-seo-inspector], [data-weline-panel-seo-bootstrap], script[data-weline-panel-seo-bootstrap], .weline-seo-panel, #weline-dev-tool-panel, #weline-panel-root"
    ).forEach(function (node) {
      if (node && node.parentNode) {
        node.parentNode.removeChild(node);
      }
    });
    return "<!DOCTYPE html>\n" + clone.outerHTML;
  }

  function copyTextToClipboard(text, onDone, onFail) {
    var value = String(text || "");
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(onDone).catch(onFail);
      return;
    }
    try {
      var area = document.createElement("textarea");
      area.value = value;
      area.setAttribute("readonly", "readonly");
      area.style.position = "fixed";
      area.style.left = "-9999px";
      document.body.appendChild(area);
      area.select();
      document.execCommand("copy");
      document.body.removeChild(area);
      onDone();
    } catch (e) {
      onFail(e);
    }
  }

  function copySiteUrlToClipboard(root) {
    var siteUrl = defaultToolUrl();
    persistToolUrl(siteUrl);
    root.querySelectorAll("[data-weline-tool-url]").forEach(function (input) {
      input.value = siteUrl;
      input.setAttribute("title", siteUrl);
    });
    syncToolUrlFormChrome(root);
    copyTextToClipboard(
      siteUrl,
      function () {
        setToolStatus(root, "已复制本站当前页地址：" + siteUrl);
      },
      function (err) {
        setToolStatus(root, "复制失败：" + ((err && err.message) || "请手动复制地址栏 URL"));
      }
    );
  }

  function copyHtmlForRichResults(root) {
    var html = buildRichResultsHtmlExport();
    copyTextToClipboard(
      html,
      function () {
        setToolStatus(root, "已复制 " + html.length + " 字符 HTML。请粘贴到 Google Rich Results Test → 代码。");
      },
      function (err) {
        setToolStatus(root, "复制失败：" + ((err && err.message) || "请手动全选页面源码"));
      }
    );
  }

  function inspectUrlWithGsc(root, controlsRoot, pageUrl) {
    if (!window.WelinePanel || typeof window.WelinePanel.apiFetch !== "function") {
      setToolStatus(root, "WelinePanel.apiFetch 不可用，请重新打开 weline 面板。");
      return;
    }
    var url = persistToolUrl(pageUrl || readToolUrlFromScope(root, "[data-weline-tool-url]"));
    setToolStatus(root, "正在查询 Google URL Inspection…");
    window.WelinePanel.apiFetch("seo/gsc/inspect", {
      method: "POST",
      body: { url: url }
    })
      .then(function (payload) {
        var data = (payload && payload.data) || payload || {};
        window.__WELINE_SEO_GSC_RESULT__ = data.data || data;
        setToolStatus(root, "GSC 查询完成：" + url);
        refreshRichPanel(root, controlsRoot);
      })
      .catch(function (error) {
        var message = (error && error.message) || "GSC 查询失败";
        var details = error && error.payload ? error.payload : null;
        if (details && details.data) {
          window.__WELINE_SEO_GSC_RESULT__ = details;
        }
        setToolStatus(root, message);
        refreshRichPanel(root, controlsRoot);
      });
  }

  function bindPanelActions(root, controlsRoot) {
    bindPanelTabs(root, controlsRoot);
    if (!root.__welineSeoActionsDelegated) {
      root.__welineSeoActionsDelegated = true;
      root.addEventListener("click", function (event) {
        var crawlButton = closestActionTarget(event.target, "[data-weline-crawl-start]", root);
        if (crawlButton) {
          event.preventDefault();
          event.stopPropagation();
          startSiteCrawl(root, controlsRoot);
          return;
        }
        var copySiteUrl = closestActionTarget(event.target, "[data-weline-tool-url-copy-site]", root);
        if (copySiteUrl) {
          event.preventDefault();
          event.stopPropagation();
          copySiteUrlToClipboard(root);
          return;
        }
        var fillCurrent = closestActionTarget(event.target, "[data-weline-tool-url-current]", root);
        if (fillCurrent) {
          event.preventDefault();
          event.stopPropagation();
          persistToolUrl(defaultToolUrl());
          refreshRichPanel(root, controlsRoot);
          return;
        }
        var pageAuditButton = closestActionTarget(event.target, "[data-weline-page-audit]", root);
        if (pageAuditButton) {
          event.preventDefault();
          event.stopPropagation();
          var forceCurrent = pageAuditButton.getAttribute("data-weline-page-audit-use-current") === "1";
          startCurrentUrlAudit(root, controlsRoot, forceCurrent ? defaultToolUrl() : null);
          return;
        }
        var localRich = closestActionTarget(event.target, "[data-weline-rich-local-test]", root);
        if (localRich) {
          event.preventDefault();
          event.stopPropagation();
          startLocalRichResultsTest(root, controlsRoot);
          return;
        }
        var openGoogle = closestActionTarget(event.target, "[data-weline-rich-open-google]", root);
        if (openGoogle) {
          event.preventDefault();
          event.stopPropagation();
          var richUrl = persistToolUrl(readToolUrlFromScope(root, "[data-weline-tool-url]"));
          window.open(googleRichResultsTestUrl(richUrl), "_blank", "noopener");
          setToolStatus(root, "已在新标签打开 Google 富媒体测试：" + richUrl);
          return;
        }
        var copyButton = closestActionTarget(event.target, "[data-weline-seo-copy-html]", root);
        if (copyButton) {
          event.preventDefault();
          event.stopPropagation();
          copyHtmlForRichResults(root);
          return;
        }
        var gscButton = closestActionTarget(event.target, "[data-weline-seo-gsc-inspect]", root);
        if (gscButton) {
          event.preventDefault();
          event.stopPropagation();
          inspectUrlWithGsc(root, controlsRoot, readToolUrlFromScope(root, "[data-weline-tool-url]"));
          return;
        }
        var publishButton = closestActionTarget(event.target, "[data-weline-seo-publish]", root);
        if (publishButton) {
          event.preventDefault();
          event.stopPropagation();
          publishAgentReport(auditAgentReport());
        }
      });
      root.addEventListener("change", function (event) {
        var target = event.target;
        if (target && target.matches && target.matches("[data-weline-tool-url]")) {
          persistToolUrl(target.value);
          root.querySelectorAll("[data-weline-tool-url]").forEach(function (input) {
            if (input !== target) input.value = String(target.value || "").trim() || defaultToolUrl();
          });
          syncToolUrlFormChrome(root);
        }
      });
      root.addEventListener("input", function (event) {
        var target = event.target;
        if (target && target.matches && target.matches("[data-weline-tool-url]")) {
          syncToolUrlFormChrome(root);
        }
      });
    }
    if (controlsRoot && !controlsRoot.__welineSeoActionsDelegated) {
      controlsRoot.__welineSeoActionsDelegated = true;
      controlsRoot.addEventListener("click", function (event) {
        var crawlButton = closestActionTarget(event.target, "[data-weline-crawl-start]", controlsRoot);
        if (crawlButton) {
          event.preventDefault();
          event.stopPropagation();
          startSiteCrawl(root, controlsRoot);
          return;
        }
        var pageAuditButton = closestActionTarget(event.target, "[data-weline-page-audit]", controlsRoot);
        if (pageAuditButton) {
          event.preventDefault();
          event.stopPropagation();
          startCurrentUrlAudit(root, controlsRoot, defaultToolUrl());
          return;
        }
        var publishButton = closestActionTarget(event.target, "[data-weline-seo-publish]", controlsRoot);
        if (publishButton) {
          event.preventDefault();
          event.stopPropagation();
          publishAgentReport(auditAgentReport());
        }
      });
    }
    root.querySelectorAll("[data-weline-crawl-start]").forEach(function (button) {
      if (button.__welineSeoCrawlBound) return;
      button.__welineSeoCrawlBound = true;
      button.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        startSiteCrawl(root, controlsRoot);
      });
    });
    panelTabScopes(root, controlsRoot).forEach(function (scope) {
      scope.querySelectorAll("[data-weline-page-audit]").forEach(function (button) {
        if (button.__welinePageAuditBound) return;
        button.__welinePageAuditBound = true;
        button.addEventListener("click", function (event) {
          event.preventDefault();
          event.stopPropagation();
          var forceCurrent = button.getAttribute("data-weline-page-audit-use-current") === "1";
          startCurrentUrlAudit(root, controlsRoot, forceCurrent ? defaultToolUrl() : null);
        });
      });
      scope.querySelectorAll("[data-weline-seo-publish]").forEach(function (button) {
        if (button.__welineSeoPublishBound) return;
        button.__welineSeoPublishBound = true;
        button.addEventListener("click", function () {
          publishAgentReport(auditAgentReport());
        });
      });
    });
  }

  function refreshSeoPanel(root, controlsRoot) {
    var panel = root.querySelector('[data-weline-panel="seo"]');
    if (panel) {
      panel.innerHTML = renderSeoTab(auditCurrentPage());
    }
    if (controlsRoot) {
      controlsRoot.innerHTML = renderPanelToolbar(auditCurrentPage());
    }
    bindPanelActions(root, controlsRoot);
  }

  function refreshPageAuditPanel(root, controlsRoot) {
    var panel = root.querySelector('[data-weline-panel="page"]');
    if (panel) {
      panel.innerHTML = renderPageAuditTab();
    }
    if (controlsRoot) {
      controlsRoot.innerHTML = renderPanelToolbar(auditCurrentPage());
    }
    bindPanelActions(root, controlsRoot);
    applyPanelTabUi(root, controlsRoot);
  }


  function startLocalRichResultsTest(root, controlsRoot) {
    var pageUrl = persistToolUrl(readToolUrlFromScope(root.querySelector('[data-weline-panel="rich"]') || root, "[data-weline-tool-url]"));
    localRichRunning = true;
    localRichStatus = "正在本地解析…" + pageUrl;
    localRichError = "";
    // Do not force-sticky foreign URLs onto the current browse pageKey.
    savePanelState({ activeTab: "rich" });
    refreshRichPanel(root, controlsRoot);
    root.querySelectorAll("[data-weline-tool-url]").forEach(function (input) {
      input.value = pageUrl;
    });

    var finish = function (report) {
      publishLocalRichReport(report);
      var pageLabel = (report && (report.pageLabel || report.seoType)) || "";
      localRichStatus =
        "本地检测完成：" +
        (report && report.routeConflict ? "有跳转 · " : "") +
        (pageLabel ? pageLabel + " · " : "") +
        (((report.summary && report.summary.items) || 0)) +
        " 项 · 通过 " +
        (((report.summary && report.summary.pass) || 0)) +
        " / 警告 " +
        (((report.summary && report.summary.warn) || 0)) +
        " / 失败 " +
        (((report.summary && report.summary.fail) || 0));
      setToolStatus(root, localRichStatus);
    };

    var fail = function (error) {
      localRichError = (error && error.message) || "本地富结果检测失败";
      setToolStatus(root, localRichError);
    };

    var run = Promise.resolve();
    if (isSameAsCurrentPage(pageUrl)) {
      run = Promise.resolve(
        analyzeLocalRichFromDocument(document, window.location.href, "current-dom", {
          requestedUrl: pageUrl,
          finalUrl: window.location.href
        })
      );
    } else {
      run = fetch(pageUrl, {
        method: "GET",
        credentials: "same-origin",
        headers: { Accept: "text/html,application/xhtml+xml" },
        redirect: "follow"
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error("抓取失败 HTTP " + response.status);
          }
          var finalUrl = response.url || pageUrl;
          return response.text().then(function (html) {
            var doc = new DOMParser().parseFromString(html, "text/html");
            return analyzeLocalRichFromDocument(doc, finalUrl, "same-origin-fetch", {
              requestedUrl: pageUrl,
              finalUrl: finalUrl
            });
          });
        })
        .catch(function (fetchError) {
          if (!window.WelinePanel || typeof window.WelinePanel.apiFetch !== "function") {
            throw fetchError;
          }
          localRichStatus = "浏览器抓取失败，改用服务端抓取…" + ((fetchError && fetchError.message) || "");
          refreshRichPanel(root, controlsRoot);
          return window.WelinePanel.apiFetch("seo/crawl/start", {
            method: "POST",
            requestTimeoutMs: 25000,
            body: {
              mode: "page",
              pageUrl: pageUrl,
              startUrl: pageUrl,
              limit: 1,
              timeout: 8,
              currentPage: { title: document.title || "", url: window.location.href }
            }
          }).then(function (payload) {
            return pollSiteCrawlUntilDone(payload, root, controlsRoot, { pageMode: false, silent: true });
          }).then(function (report) {
            var pages = (report && report.pages) || [];
            var page = pages[0] || null;
            if (!page) {
              throw new Error("服务端未返回页面结构化数据");
            }
            var finalUrl = page.finalUrl || page.url || pageUrl;
            return analyzeLocalRichFromCrawlPage(page, pageUrl, finalUrl);
          });
        });
    }

    run
      .then(finish)
      .catch(fail)
      .finally(function () {
        localRichRunning = false;
        refreshRichPanel(root, controlsRoot);
      });
  }

  function refreshRichPanel(root, controlsRoot) {
    var panel = root.querySelector('[data-weline-panel="rich"]');
    if (panel) {
      panel.innerHTML = renderRichResultsTab(auditCurrentPage());
    }
    bindPanelActions(root, controlsRoot);
    applyPanelTabUi(root, controlsRoot);
  }

  function publishPageAuditReport(report) {
    latestPageAuditReport = report || null;
    try {
      window.__WELINE_PANEL_SEO_PAGE_AUDIT_REPORT__ = latestPageAuditReport;
    } catch (e) {}
  }

  function startCurrentUrlAudit(root, controlsRoot, forcedUrl) {
    var pageUrl = String(forcedUrl || "").trim();
    if (!pageUrl) {
      pageUrl = readToolUrlFromScope(root.querySelector('[data-weline-panel="page"]') || root, "[data-weline-tool-url]");
    }
    pageUrl = persistToolUrl(pageUrl);

    if (!window.WelinePanel || typeof window.WelinePanel.apiFetch !== "function") {
      pageAuditError = "WelinePanel.apiFetch 不可用，请刷新页面后重新打开 weline 面板。";
      refreshPageAuditPanel(root, controlsRoot);
      return;
    }

    pageAuditRunning = true;
    pageAuditStatus = "正在服务端抓取 URL…";
    pageAuditError = "";
    savePanelState({ activeTab: "page" });
    refreshPageAuditPanel(root, controlsRoot);
    root.querySelectorAll("[data-weline-tool-url]").forEach(function (input) {
      input.value = pageUrl;
    });

    window.WelinePanel.apiFetch("seo/crawl/start", {
      method: "POST",
      requestTimeoutMs: 25000,
      body: {
        mode: "page",
        pageUrl: pageUrl,
        startUrl: pageUrl,
        limit: 1,
        timeout: 8,
        currentPage: {
          title: document.title || "",
          url: window.location.href
        }
      }
    }).then(function (payload) {
      return pollSiteCrawlUntilDone(payload, root, controlsRoot, { pageMode: true });
    }).then(function (report) {
      if (!report || report.contractVersion !== SITE_CRAWL_CONTRACT_VERSION) {
        throw new Error("当前 URL 审计返回格式不正确。");
      }
      publishPageAuditReport(report);
      pageAuditStatus =
        "检测完成：" + pageUrl + " · score " +
        (((report.health && report.health.score) || 0)) +
        "，" +
        ((report.issues && report.issues.length) || 0) +
        " 个 issue。";
    }).catch(function (error) {
      pageAuditError = (error && error.message) || "当前 URL 检测失败。";
    }).finally(function () {
      pageAuditRunning = false;
      refreshPageAuditPanel(root, controlsRoot);
    });
  }

  function refreshCrawlPanel(root, controlsRoot) {
    var panel = root.querySelector('[data-weline-panel="crawl"]');
    if (!panel) return;
    panel.innerHTML = renderSiteCrawlTab();
    bindPanelActions(root, controlsRoot);
    applyPanelTabUi(root, controlsRoot);
  }

  function startSiteCrawl(root, controlsRoot) {
    var panel = root.querySelector('[data-weline-panel="crawl"]') || root;
    var sitemapInput = panel.querySelector("[data-weline-crawl-sitemap]");
    var limitInput = panel.querySelector("[data-weline-crawl-limit]");
    var sitemapUrl = sitemapInput ? String(sitemapInput.value || "").trim() : defaultSitemapUrl();
    var limit = limitInput ? Number(limitInput.value || 100) : 100;
    if (!Number.isFinite(limit) || limit <= 0) limit = 100;
    limit = Math.max(1, Math.min(500, Math.round(limit)));
    savePanelState({ activeTab: "crawl", crawlSitemapUrl: sitemapUrl, crawlLimit: limit });

    if (!window.WelinePanel || typeof window.WelinePanel.apiFetch !== "function") {
      siteCrawlError = "WelinePanel.apiFetch 不可用，请刷新页面后重新打开 weline 面板。";
      refreshCrawlPanel(root, controlsRoot);
      return;
    }

    siteCrawlRunning = true;
    siteCrawlStatus = "正在抓取 sitemap 并审计页面...";
    siteCrawlError = "";
    refreshCrawlPanel(root, controlsRoot);

    window.WelinePanel.apiFetch("seo/crawl/start", {
      method: "POST",
      requestTimeoutMs: 25000,
      body: {
        mode: "sitemap",
        startUrl: window.location.href,
        sitemapUrl: sitemapUrl,
        limit: limit,
        currentPage: {
          title: document.title || "",
          url: window.location.href
        }
      }
    }).then(function (payload) {
      return pollSiteCrawlUntilDone(payload, root, controlsRoot, { pageMode: false });
    }).then(function (report) {
      if (!report || report.contractVersion !== SITE_CRAWL_CONTRACT_VERSION) {
        throw new Error("SEO 全站审计返回格式不正确。");
      }
      publishSiteCrawlReport(report);
      var sampling = (report.crawl && report.crawl.sampling) || {};
      var collapsedNote = sampling.collapsed
        ? ("（sitemap " + (sampling.discovered || 0) + " → 抽样 " + (sampling.sampled || report.crawl.totalUrls || 0) + "，合并同结构 " + sampling.collapsed + "）")
        : "";
      siteCrawlStatus = "审计完成：" + ((report.crawl && report.crawl.scanned) || 0) + " 个页面，" + ((report.issues && report.issues.length) || 0) + " 个 issue。" + collapsedNote;
      publishAgentReport(buildAgentReport(auditCurrentPage()));
    }).catch(function (error) {
      siteCrawlError = (error && error.message) || "SEO 全站审计失败。";
    }).finally(function () {
      siteCrawlRunning = false;
      refreshCrawlPanel(root, controlsRoot);
    });
  }

  function pollSiteCrawlUntilDone(payload, root, controlsRoot, options) {
    options = options || {};
    var pageMode = !!options.pageMode;
    var envelope = unwrapCrawlEnvelope(payload);
    var report = envelope.report || unwrapApiPayload(payload);
    if (!report || report.contractVersion !== SITE_CRAWL_CONTRACT_VERSION) {
      return Promise.reject(new Error(pageMode ? "当前 URL 审计返回格式不正确。" : "SEO 全站审计返回格式不正确。"));
    }

    var status = String(envelope.status || (report.crawl && report.crawl.status) || "completed").toLowerCase();
    if (status === "failed" || status === "error") {
      return Promise.reject(new Error(pageMode ? "当前 URL 检测失败。" : "SEO 全站审计失败。"));
    }

    if (status === "running") {
      var scanned = (report.crawl && report.crawl.scanned) || 0;
      var total = (report.crawl && report.crawl.totalUrls) || 0;
      var progress = total > 0
        ? ("正在审计页面 " + scanned + "/" + total + "…")
        : ("正在审计页面…（已扫描 " + scanned + "）");
      if (options.silent) {
        localRichStatus = progress;
        localRichRunning = true;
        refreshRichPanel(root, controlsRoot);
      } else if (pageMode) {
        pageAuditStatus = progress;
        pageAuditRunning = true;
        refreshPageAuditPanel(root, controlsRoot);
      } else {
        siteCrawlStatus = progress;
        siteCrawlRunning = true;
        refreshCrawlPanel(root, controlsRoot);
      }
      var crawlId = envelope.id || (report.crawl && report.crawl.id) || "";
      return delayMs(500).then(function () {
        return window.WelinePanel.apiFetch("seo/crawl/result", {
          method: "GET",
          requestTimeoutMs: 25000,
          params: crawlId ? { id: crawlId } : {}
        });
      }).then(function (nextPayload) {
        return pollSiteCrawlUntilDone(nextPayload, root, controlsRoot, options);
      });
    }

    return Promise.resolve(report);
  }

  function applyPanelTabUi(root, controlsRoot) {
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

  function setPanelTab(root, tabId, controlsRoot) {
    activePanelTab = normalizePanelTab(tabId);
    savePanelState({ activeTab: activePanelTab });
    // Re-render URL tools when opening them so cross-page stale localStorage
    // cannot keep showing a previous PDP while the browser is on /products/.
    if (activePanelTab === "rich") {
      refreshRichPanel(root, controlsRoot);
      return;
    }
    if (activePanelTab === "page") {
      refreshPageAuditPanel(root, controlsRoot);
      return;
    }
    applyPanelTabUi(root, controlsRoot);
  }

  function renderPanelBody(report, options) {
    options = options || {};
    return (
      (options.externalToolbar ? "" : renderPanelToolbar(report)) +
      '<div class="weline-seo-panel__tab-panel is-active" data-weline-panel="seo" role="tabpanel">' +
      renderSeoTab(report) +
      "</div>" +
      '<div class="weline-seo-panel__tab-panel" data-weline-panel="a11y" role="tabpanel" hidden>' +
      renderAccessibilityTab(report) +
      "</div>" +
      '<div class="weline-seo-panel__tab-panel" data-weline-panel="engines" role="tabpanel" hidden>' +
      renderEngineTab(report) +
      "</div>" +
      '<div class="weline-seo-panel__tab-panel" data-weline-panel="crawl" role="tabpanel" hidden>' +
      renderSiteCrawlTab(report) +
      "</div>" +
      '<div class="weline-seo-panel__tab-panel" data-weline-panel="page" role="tabpanel" hidden>' +
      renderPageAuditTab() +
      "</div>" +
      '<div class="weline-seo-panel__tab-panel" data-weline-panel="rich" role="tabpanel" hidden>' +
      renderRichResultsTab(report) +
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
    startLiveCwvObservers();
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
    bindPanelActions(root, toolbarRoot);
    setPanelTab(root, activePanelTab, toolbarRoot);
    publishAgentReport(buildAgentReport(raw));
    refreshReportAfterSitemapProbe();
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
  refreshReportAfterSitemapProbe();
})();
