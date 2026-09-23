<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\DeveloperAccessPolicy;
use Weline\Seo\Structure\SeoStructureRegistry;

class HeadRenderer
{
    private const INSPECTOR_CSS_SOURCE = 'Weline_Seo::seo-inspector/inspector.css';
    private const INSPECTOR_JS_SOURCE = 'Weline_Seo::seo-inspector/inspector.js';
    private const REQUEST_CLAIM_KEY = 'weline.seo.head.rendered';

    /** @var string|null Request id that already claimed the head slot in this worker. */
    private static ?string $claimedRequestId = null;

    public function __construct(
        private readonly PageSeoContextResolver $resolver,
        private readonly ?SeoStructureRegistry $structureRegistry = null,
        private readonly ?SeoSlotProviderRegistry $slotProviderRegistry = null
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $options
     */
    public function render($template, array $options = []): string
    {
        $slot = trim((string) ($options['slot'] ?? 'head'));
        if ($slot === '') {
            $slot = 'head';
        }
        if ($slot === 'head' && $this->claimHeadRender($template)) {
            return '';
        }
        if ($slot === 'inspector') {
            return $this->renderSeoPanelTabBootstrap($template, 'inspector-slot');
        }

        $context = $this->resolver->resolve($template, $options);
        $context['_template'] = $template;
        $frontendTitle = $this->readTemplateData($template, '__weline_frontend_final_title');
        if ($frontendTitle !== '') {
            $context['title'] = $frontendTitle;
            $context['_frontend_title_rendered'] = true;
        } elseif (class_exists(\Weline\Frontend\Service\Head\TitleComposer::class)) {
            try {
                /** @var \Weline\Frontend\Service\Head\TitleComposer $composer */
                $composer = ObjectManager::getInstance(\Weline\Frontend\Service\Head\TitleComposer::class);
                $composed = trim($composer->compose($template, $context));
                if ($composed !== '') {
                    $context['title'] = $composed;
                }
            } catch (\Throwable) {
            }
        }
        return match ($slot) {
            'meta' => $this->renderMeta($context),
            'canonical' => $this->renderCanonical($context),
            'social' => $this->renderSocial($context),
            'schema', 'structured-data' => $this->renderStructuredData($context),
            default => implode("\n", array_filter([
                $slot === 'head' ? $this->renderMeta($context) : '',
                $slot === 'head' ? $this->renderCanonical($context) : '',
                $slot === 'head' ? $this->renderSocial($context) : '',
                $slot === 'head' ? $this->renderStructuredData($context) : '',
                $slot !== 'head' ? $this->renderCustomSlot($slot, $template, $context, $options) : '',
                $slot === 'footer' ? $this->renderSeoPanelTabBootstrap($template) : '',
            ])),
        };
    }

    private function claimHeadRender($template): bool
    {
        $requestId = \Weline\Framework\Runtime\RequestContext::getId();
        if ($requestId !== null && $requestId !== '') {
            if (self::$claimedRequestId === $requestId
                || \Weline\Framework\Runtime\RequestContext::has(self::REQUEST_CLAIM_KEY)
            ) {
                self::$claimedRequestId = $requestId;
                $this->claimTemplateRender($template, '__weline_seo_head_rendered');
                return true;
            }

            self::$claimedRequestId = $requestId;
            \Weline\Framework\Runtime\RequestContext::set(self::REQUEST_CLAIM_KEY, true);
            $this->claimTemplateRender($template, '__weline_seo_head_rendered');
            return false;
        }

        return $this->claimTemplateRender($template, '__weline_seo_head_rendered');
    }

    private function claimTemplateRender($template, string $key): bool
    {
        if (!is_object($template) || !method_exists($template, 'getData') || !method_exists($template, 'setData')) {
            return false;
        }

        if (!empty($template->getData($key))) {
            return true;
        }

        $template->setData($key, true);
        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderMeta(array $context): string
    {
        $html = [];
        $title = trim((string) ($context['title'] ?? ''));
        $description = trim((string) ($context['description'] ?? ''));
        $keywords = $context['keywords'] ?? '';
        if (is_array($keywords)) {
            $keywords = implode(', ', array_filter(array_map('strval', $keywords)));
        }

        if ($title !== '' && empty($context['_frontend_title_rendered'])) {
            $html[] = '<title>' . $this->escape($title) . '</title>';
        }
        if ($description !== '') {
            $html[] = '<meta name="description" content="' . $this->escape($description) . '">';
        }
        if (trim((string) $keywords) !== '') {
            $html[] = '<meta name="keywords" content="' . $this->escape((string) $keywords) . '">';
        }
        if (!empty($context['robots'])) {
            $html[] = '<meta name="robots" content="' . $this->escape((string) $context['robots']) . '">';
        }
        $pageType = $this->headPageType((string) ($context['page_type'] ?? ''));
        if ($pageType !== '') {
            $html[] = '<meta name="page-type" content="' . $this->escape($pageType) . '">';
        }
        $contentCategory = trim((string) ($context['content_category'] ?? ''));
        if ($contentCategory === '') {
            $contentCategory = $this->defaultContentCategory($pageType);
        }
        if ($contentCategory !== '') {
            $html[] = '<meta name="content-category" content="' . $this->escape($contentCategory) . '">';
        }
        return implode("\n", $html);
    }

    private function headPageType(string $pageType): string
    {
        $normalized = $this->normalizePageType($pageType);
        return $normalized === '' ? '' : str_replace('_', '-', $normalized);
    }

    private function defaultContentCategory(string $pageType): string
    {
        $normalized = $this->normalizePageType($pageType);
        // 法律/政策页别名 → content-category=legal；page-type meta 仍保持 Theme 事实（如 policy）
        if ($this->isLegalContentCategoryAlias($normalized)) {
            return 'legal';
        }

        return match ($normalized) {
            'home' => 'framework',
            'product' => 'product',
            'category', 'tag_collection', 'collection', 'products' => 'collection',
            'search', 'search_results' => 'search',
            'blog_post', 'post', 'article', 'news', 'news_article' => 'article',
            'contact' => 'contact',
            default => '',
        };
    }

    /**
     * 政策/法律页 page_type 别名（normalize 后：空格与连字符已变为下划线）。
     */
    private function isLegalContentCategoryAlias(string $normalizedPageType): bool
    {
        return in_array($normalizedPageType, [
            'policy',
            'privacy',
            'accessibility',
            'cookie',
            'shipping',
            'refund',
            'disclaimer',
            'term_condition',
            'terms',
            'legal',
        ], true);
    }

    private function readTemplateData($template, string $key): string
    {
        if (is_object($template) && method_exists($template, 'getData')) {
            return trim((string) $template->getData($key));
        }
        return '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderCanonical(array $context): string
    {
        $html = [];
        if (!empty($context['canonical_url'])) {
            $html[] = '<link rel="canonical" href="' . $this->escape((string) $context['canonical_url']) . '">';
        }
        $sitemapUrl = trim((string) ($context['sitemap_url'] ?? ''));
        if ($sitemapUrl === '' && !empty($context['sitemap']) && is_array($context['sitemap'])) {
            $sitemapUrl = trim((string) ($context['sitemap']['url'] ?? $context['sitemap']['href'] ?? ''));
        }
        // Convenience discovery only — Google primarily uses robots.txt Sitemap: (already emitted by Weline_Seo).
        if ($sitemapUrl === '') {
            $canonical = trim((string) ($context['canonical_url'] ?? $context['url'] ?? ''));
            $root = $canonical !== '' ? $this->siteRoot($canonical) : '';
            if ($root !== '') {
                $sitemapUrl = rtrim($root, '/') . '/sitemap.xml';
            }
        }
        if ($sitemapUrl !== '') {
            $html[] = '<link rel="sitemap" type="application/xml" href="' . $this->escape($sitemapUrl) . '">';
        }
        foreach ((array) ($context['alternates'] ?? []) as $locale => $url) {
            if (!is_string($locale) || !is_string($url) || trim($url) === '') {
                continue;
            }
            $hreflang = $this->normalizeHreflang($locale);
            if ($hreflang === '') {
                continue;
            }
            $html[] = '<link rel="alternate" hreflang="' . $this->escape($hreflang) . '" href="' . $this->escape($url) . '">';
        }
        foreach ((array) ($context['feeds'] ?? []) as $feed) {
            if (!is_array($feed)) {
                continue;
            }
            $href = trim((string) ($feed['href'] ?? $feed['url'] ?? ''));
            if ($href === '') {
                continue;
            }
            $type = trim((string) ($feed['type'] ?? 'application/rss+xml'));
            if ($type === '') {
                $type = 'application/rss+xml';
            }
            $title = trim((string) ($feed['title'] ?? ''));
            $attrs = 'rel="alternate" type="' . $this->escape($type) . '"';
            if ($title !== '') {
                $attrs .= ' title="' . $this->escape($title) . '"';
            }
            $html[] = '<link ' . $attrs . ' href="' . $this->escape($href) . '">';
        }
        return implode("\n", $html);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderSocial(array $context): string
    {
        $title = (string) ($context['title'] ?? '');
        $description = (string) ($context['description'] ?? '');
        $url = (string) ($context['canonical_url'] ?? $context['url'] ?? '');
        $type = $this->socialType((string) ($context['page_type'] ?? ''));
        $html = [
            '<meta property="og:type" content="' . $this->escape($type) . '">',
        ];
        $ogLocale = $this->toOgLocale((string) ($context['og_locale'] ?? $context['html_locale'] ?? $context['locale'] ?? ''));
        if ($ogLocale !== '') {
            $html[] = '<meta property="og:locale" content="' . $this->escape($ogLocale) . '">';
        }
        $siteName = trim((string) ($context['site_name'] ?? ''));
        if ($siteName !== '') {
            $html[] = '<meta property="og:site_name" content="' . $this->escape($siteName) . '">';
        }
        if ($title !== '') {
            $html[] = '<meta property="og:title" content="' . $this->escape($title) . '">';
            $html[] = '<meta name="twitter:title" content="' . $this->escape($title) . '">';
        }
        if ($description !== '') {
            $html[] = '<meta property="og:description" content="' . $this->escape($description) . '">';
            $html[] = '<meta name="twitter:description" content="' . $this->escape($description) . '">';
        }
        if ($url !== '') {
            $html[] = '<meta property="og:url" content="' . $this->escape($url) . '">';
        }
        if (!empty($context['image'])) {
            $html[] = '<meta property="og:image" content="' . $this->escape((string) $context['image']) . '">';
            $html[] = '<meta name="twitter:image" content="' . $this->escape((string) $context['image']) . '">';
            $imageAlt = trim((string) ($context['image_alt'] ?? $context['title'] ?? ''));
            if ($imageAlt !== '') {
                $html[] = '<meta property="og:image:alt" content="' . $this->escape($imageAlt) . '">';
                $html[] = '<meta name="twitter:image:alt" content="' . $this->escape($imageAlt) . '">';
            }
            $html[] = '<meta name="twitter:card" content="summary_large_image">';
        } else {
            $html[] = '<meta name="twitter:card" content="summary">';
        }
        if (($context['page_type'] ?? '') === 'product') {
            foreach ($this->productSocialTags($context) as $property => $value) {
                $html[] = '<meta property="' . $this->escape($property) . '" content="' . $this->escape($value) . '">';
            }
        }
        if ($this->isArticlePageType((string) ($context['page_type'] ?? ''))) {
            $section = $this->firstNonEmptyValue([
                $this->read($context['article'] ?? null, ['articleSection', 'article_section', 'section', 'category']),
                $this->read($context, ['article_section', 'section']),
            ]);
            if (is_string($section) && trim($section) !== '') {
                $html[] = '<meta property="article:section" content="' . $this->escape(trim($section)) . '">';
            }
            $published = $this->firstNonEmptyValue([
                $this->read($context['article'] ?? null, ['datePublished', 'date_published', 'published_at']),
            ]);
            if (is_string($published) && trim($published) !== '') {
                $formatted = $this->formatDate($published);
                if ($formatted !== '') {
                    $html[] = '<meta property="article:published_time" content="' . $this->escape($formatted) . '">';
                }
            }
            $modified = $this->firstNonEmptyValue([
                $this->read($context['article'] ?? null, ['dateModified', 'date_modified', 'updated_at']),
            ]);
            if (is_string($modified) && trim($modified) !== '') {
                $formatted = $this->formatDate($modified);
                if ($formatted !== '') {
                    $html[] = '<meta property="article:modified_time" content="' . $this->escape($formatted) . '">';
                }
            }
        }
        foreach ($this->alternateOgLocales($context, $ogLocale) as $alternateLocale) {
            $html[] = '<meta property="og:locale:alternate" content="' . $this->escape($alternateLocale) . '">';
        }
        return implode("\n", $html);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderStructuredData(array $context): string
    {
        $graph = $this->buildGraph($context);
        if ($graph === []) {
            return '';
        }
        return '<script type="application/ld+json">' . "\n"
            . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n" . '</script>';
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    private function renderCustomSlot(string $slot, $template, array $context, array $options): string
    {
        $slotContext = $context;
        $slotContext['_slot'] = $slot;
        $slotContext['_options'] = $options;
        $providedContext = [];

        foreach ($this->slotProviderRegistry()->getProviders() as $provider) {
            try {
                if (!$provider->supports($slot, $template, $slotContext, $options)) {
                    continue;
                }

                $provided = $provider->provide($slot, $template, $slotContext, $options);
                if ($provided !== []) {
                    $slotContext = $this->mergeProviderContext($slotContext, $provided);
                    $providedContext = $this->mergeProviderContext($providedContext, $provided);
                }
            } catch (\Throwable) {
            }
        }

        if ($providedContext === []) {
            return '';
        }

        return implode("\n", array_filter([
            $this->renderSlotBlocks($slot, $slotContext),
            $this->renderSlotStructuredData($providedContext, $slotContext),
        ]));
    }

    private function renderSeoPanelTabBootstrap($template, string $source = 'footer-slot'): string
    {
        if (!ObjectManager::getInstance(DeveloperAccessPolicy::class)->shouldInjectBootstrap()) {
            return '';
        }

        if ($this->claimTemplateRender($template, '__weline_panel_seo_bootstrap_rendered')) {
            return '';
        }

        $cssUrl = $this->jsonString($this->withPanelAssetVersion($this->resolveStaticUrl($template, self::INSPECTOR_CSS_SOURCE)));
        $jsUrl = $this->jsonString($this->withPanelAssetVersion($this->resolveStaticUrl($template, self::INSPECTOR_JS_SOURCE)));
        $sourceAttribute = $this->escape($source);

        return <<<HTML
<script data-no-extract="true" data-load-order="last" data-weline-panel-seo-bootstrap="true" data-weline-panel-seo-source="{$sourceAttribute}">
(function () {
  "use strict";

  if (window.__WELINE_PANEL_SEO_BOOTSTRAPPED__) {
    return;
  }

  window.__WELINE_PANEL_SEO_BOOTSTRAPPED__ = true;

  var INSPECTOR_CSS_URL = {$cssUrl};
  var INSPECTOR_JS_URL = {$jsUrl};
  var inspectorPromise = null;
  var stylesheetPromise = null;

  function appendToHead(node) {
    (document.head || document.documentElement).appendChild(node);
  }

  function waitForInspector() {
    return new Promise(function (resolve, reject) {
      var attempts = 0;
      var timer = window.setInterval(function () {
        attempts += 1;

        if (window.__WELINE_SEO_INSPECTOR__) {
          window.clearInterval(timer);
          resolve(window.__WELINE_SEO_INSPECTOR__);
          return;
        }

        if (attempts >= 200) {
          window.clearInterval(timer);
          reject(new Error("Timed out while loading SEO inspector"));
        }
      }, 50);
    });
  }

  function loadStylesheet() {
    if (document.querySelector('link[data-weline-seo-inspector="true"]')) {
      return Promise.resolve();
    }

    if (stylesheetPromise) {
      return stylesheetPromise;
    }

    stylesheetPromise = new Promise(function (resolve, reject) {
      var link = document.createElement("link");
      link.rel = "stylesheet";
      link.href = INSPECTOR_CSS_URL;
      link.setAttribute("data-weline-seo-inspector", "true");
      link.onload = function () { resolve(); };
      link.onerror = function () { reject(new Error("Failed to load SEO inspector CSS")); };
      appendToHead(link);
    });

    return stylesheetPromise;
  }

  function ensureInspectorScript() {
    if (window.__WELINE_SEO_INSPECTOR__) {
      return Promise.resolve(window.__WELINE_SEO_INSPECTOR__);
    }

    if (inspectorPromise) {
      return inspectorPromise;
    }

    if (document.querySelector('script[data-weline-seo-inspector="true"]')) {
      inspectorPromise = waitForInspector();
      return inspectorPromise;
    }

    inspectorPromise = new Promise(function (resolve, reject) {
      var script = document.createElement("script");
      script.src = INSPECTOR_JS_URL;
      script.async = true;
      script.defer = true;
      script.setAttribute("data-weline-seo-inspector", "true");
      script.onload = function () {
        if (window.__WELINE_SEO_INSPECTOR__) {
          resolve(window.__WELINE_SEO_INSPECTOR__);
          return;
        }
        reject(new Error("SEO inspector loaded without registering API"));
      };
      script.onerror = function () { reject(new Error("Failed to load SEO inspector script")); };
      appendToHead(script);
    });

    return inspectorPromise;
  }

  function reportError(error) {
    if (typeof console !== "undefined" && typeof console.error === "function") {
      console.error("[seo-inspector]", error);
    }
  }

  function normalizeSeoReport(report) {
    var output = Object.assign({}, report || {});
    output.contractVersion = "weline-panel-seo/v1";
    output.command = "weline-panel:seo";
    return output;
  }

  function ensurePanelAuthorized() {
    var config = window.__WELINE_PANEL_CONFIG__ || {};
    if (config.tokenRequired !== true) {
      return Promise.resolve(true);
    }
    if (!window.WelinePanel || typeof window.WelinePanel.isAuthorized !== "function") {
      return Promise.reject(new Error("Weline Panel token required."));
    }
    if (window.WelinePanel.isAuthorized()) {
      return Promise.resolve(true);
    }
    if (typeof window.WelinePanel.requestAuthorization === "function") {
      return Promise.resolve(window.WelinePanel.requestAuthorization()).then(function (allowed) {
        if (!allowed) {
          throw new Error("Weline Panel token required.");
        }
        return true;
      });
    }
    return Promise.reject(new Error("Weline Panel token required."));
  }

  function getPanelSeoReport() {
    return ensurePanelAuthorized().then(function () {
      return ensureInspectorScript().then(function (inspector) {
        if (inspector && typeof inspector.report === "function") {
          return normalizeSeoReport(inspector.report());
        }
        if (inspector && typeof inspector.publish === "function") {
          return normalizeSeoReport(inspector.publish());
        }
        return normalizeSeoReport(null);
      });
    });
  }

	  function escapeHtml(value) {
	    return String(value == null ? "" : value)
	      .replace(/&/g, "&amp;")
	      .replace(new RegExp("\\x3C", "g"), "&lt;")
	      .replace(/>/g, "&gt;")
	      .replace(/"/g, "&quot;")
	      .replace(/'/g, "&#039;");
	  }

  function updateHeaderContext(report) {
    var hint = document.querySelector(".dev-tool-header-hint");
    if (!hint) {
      return;
    }
    var snapshot = report && report.snapshot ? report.snapshot : {};
    var page = report && report.page ? report.page : {};
    var title = snapshot.title || page.title || document.title || "未命名页面";
    var url = page.url || window.location.href;
    hint.textContent = title + " · " + url;
    hint.title = title + "\\n" + url;
    hint.classList.add("is-visible");
    hint.setAttribute("aria-hidden", "false");
  }

  function bindSeoPublishButtons() {
    var scopes = Array.prototype.slice.call(arguments).filter(Boolean);
    scopes.forEach(function (scope) {
      Array.prototype.slice.call(scope.querySelectorAll("[data-weline-seo-publish]")).forEach(function (publishButton) {
        if (publishButton.__welineSeoPublishBound) {
          return;
        }
        publishButton.__welineSeoPublishBound = true;
        publishButton.addEventListener("click", function () {
          publishButton.disabled = true;
          var publish = window.WelinePanel && typeof window.WelinePanel.publish === "function"
            ? window.WelinePanel.publish({ tabs: ["seo"], refresh: true })
            : getPanelSeoReport();
          Promise.resolve(publish).finally(function () {
            publishButton.disabled = false;
          });
        });
      });
    });
  }

  function renderSeoTab(ctx) {
    var content = ctx && ctx.content ? ctx.content : document.getElementById("dev-tool-content");
    var searchArea = ctx && ctx.searchArea ? ctx.searchArea : document.getElementById("dev-tool-search-area-seo");
	    if (!content) {
	      return getPanelSeoReport();
	    }
    updateHeaderContext(null);
    if (searchArea) {
      searchArea.innerHTML = '\\x3Cdiv class="dev-tool-loading">\\x3Ci class="pin spinning">\\x3C/i>\\x3Cdiv>加载 SEO 面板...\\x3C/div>\\x3C/div>';
    }
	    content.innerHTML = '\\x3Cdiv class="dev-tool-loading">\\x3Ci class="pin spinning">\\x3C/i>\\x3Cdiv>加载 SEO 诊断...\\x3C/div>\\x3C/div>';
	    return ensurePanelAuthorized()
	      .then(function () {
	        return loadStylesheet().catch(function (error) {
	          reportError(error);
          return true;
        });
      })
	      .then(ensureInspectorScript)
	      .then(function (inspector) {
	        content.innerHTML =
	          '\\x3Cdiv id="weline-panel-seo-diagnostics">\\x3C/div>';
        var target = content.querySelector("#weline-panel-seo-diagnostics");
        var raw = null;
        if (inspector && typeof inspector.renderInto === "function") {
          raw = inspector.renderInto(target, { toolbarContainer: searchArea });
        }
        bindSeoPublishButtons(content, searchArea);
        updateHeaderContext(raw);
        if (inspector && typeof inspector.report === "function") {
          var report = normalizeSeoReport(inspector.report());
          updateHeaderContext(report);
          return report;
	        }
	        return normalizeSeoReport(raw);
	    }).catch(function (error) {
        if (searchArea) {
          searchArea.innerHTML = '\\x3Cdiv class="dev-tool-empty">' + escapeHtml((error && error.message) || "SEO 诊断加载失败") + '\\x3C/div>';
        }
	      content.innerHTML = '\\x3Cdiv class="dev-tool-empty">' + escapeHtml((error && error.message) || "SEO 诊断加载失败") + '\\x3C/div>';
	      throw error;
	    });
	  }

  function registerWithWelinePanel() {
    var manifest = {
      id: "seo",
      title: "SEO",
      order: 200,
      activate: renderSeoTab,
      report: function () {
        return getPanelSeoReport();
      }
    };
    window.__WELINE_PANEL_TAB_QUEUE__ = window.__WELINE_PANEL_TAB_QUEUE__ || [];
    window.__WELINE_PANEL_REPORT_PROVIDERS__ = window.__WELINE_PANEL_REPORT_PROVIDERS__ || {};
    window.__WELINE_PANEL_REPORT_PROVIDERS__.seo = function () {
      return getPanelSeoReport();
    };
    if (window.WelinePanel && typeof window.WelinePanel.registerTab === "function") {
      window.WelinePanel.registerTab(manifest);
    } else {
      window.__WELINE_PANEL_TAB_QUEUE__.push(manifest);
    }
    if (window.WelinePanel && typeof window.WelinePanel.registerReportProvider === "function") {
      window.WelinePanel.registerReportProvider("seo", function () {
        return getPanelSeoReport();
      });
    }
  }

  registerWithWelinePanel();
})();
</script>
HTML;
    }

    private function resolveStaticUrl($template, string $source): string
    {
        if (is_object($template) && method_exists($template, 'fetchTagSourceFile')) {
            try {
                $url = trim((string) $template->fetchTagSourceFile('statics', $source));
                if ($url !== '') {
                    return $url;
                }
            } catch (\Throwable) {
            }
        }

        $normalizedSource = str_replace('\\', '/', $source);
        if (str_contains($normalizedSource, '::')) {
            [$module, $path] = explode('::', $normalizedSource, 2);
            return '/' . trim(str_replace('_', '/', $module), '/') . '/view/statics/' . ltrim($path, '/');
        }

        return '/' . ltrim($normalizedSource, '/');
    }

    private function withPanelAssetVersion(string $url): string
    {
        $version = '20260922-a11y-completeness-1';
        $jsPath = dirname(__DIR__, 2) . '/view/statics/seo-inspector/inspector.js';
        if (is_file($jsPath)) {
            $mtime = (int)@filemtime($jsPath);
            if ($mtime > 0) {
                $version .= '-' . $mtime;
            }
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
    }

    private function jsonString(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '""';
    }

    /**
     * @param array<string, mixed> $provided
     * @param array<string, mixed> $context
     */
    private function renderSlotStructuredData(array $provided, array $context): string
    {
        foreach (['schema_nodes', 'article', 'product', 'item_list', 'faqs', 'qa_list', 'breadcrumbs', 'breadcrumb_trails', 'organization'] as $key) {
            if (!empty($provided[$key])) {
                return $this->renderStructuredData($context);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $provided
     * @return array<string, mixed>
     */
    private function mergeProviderContext(array $context, array $provided): array
    {
        foreach (['schema_nodes', 'item_list', 'faqs', 'qa_list', 'blocks'] as $listKey) {
            if (isset($provided[$listKey]) && is_array($provided[$listKey]) && $this->isList($provided[$listKey])) {
                $existing = isset($context[$listKey]) && is_array($context[$listKey]) && $this->isList($context[$listKey])
                    ? $context[$listKey]
                    : [];
                $context[$listKey] = array_values(array_merge($existing, $provided[$listKey]));
                unset($provided[$listKey]);
            }
        }

        return array_replace_recursive($context, $provided);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderSlotBlocks(string $slot, array $context): string
    {
        $blocks = $context['blocks'] ?? [];
        if (!is_array($blocks) || $blocks === []) {
            return '';
        }

        $html = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $rendered = $this->renderSlotBlock($block);
            if ($rendered !== '') {
                $html[] = $rendered;
            }
        }

        if ($html === []) {
            return '';
        }

        return '<div data-seo-slot="' . $this->escape($slot) . '">' . "\n"
            . implode("\n", $html)
            . "\n" . '</div>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderSlotBlock(array $block): string
    {
        $type = trim((string)($block['type'] ?? ''));
        if ($type === '') {
            return '';
        }

        $items = $block['items'] ?? [];
        if (is_array($items) && $items !== []) {
            return $this->renderSlotItemList($type, $block, $items);
        }

        $text = trim((string)($block['text'] ?? $block['summary'] ?? ''));
        if ($text === '') {
            return '';
        }

        return '<div data-seo-block="' . $this->escape($type) . '">' . $this->escape($text) . '</div>';
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int|string, mixed> $items
     */
    private function renderSlotItemList(string $type, array $block, array $items): string
    {
        $list = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string)($item['name'] ?? $item['title'] ?? ''));
            $url = trim((string)($item['url'] ?? $item['href'] ?? ''));
            if ($name === '') {
                continue;
            }

            $label = $this->escape($name);
            $list[] = $url !== ''
                ? '<li><a href="' . $this->escape($url) . '">' . $label . '</a></li>'
                : '<li>' . $label . '</li>';
        }

        if ($list === []) {
            return '';
        }

        $title = trim((string)($block['title'] ?? ''));
        return '<nav data-seo-block="' . $this->escape($type) . '">'
            . ($title !== '' ? '<strong>' . $this->escape($title) . '</strong>' : '')
            . '<ul>' . implode('', $list) . '</ul>'
            . '</nav>';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function buildGraph(array $context): array
    {
        $url = (string) ($context['canonical_url'] ?? $context['url'] ?? '');
        $siteUrl = $this->siteRoot($url);
        $organization = (array) ($context['organization'] ?? []);
        $orgId = rtrim($siteUrl, '/') . '/#organization';
        $graph = [
            [
                // Google Organization guide: ecommerce sites should use OnlineStore.
                '@type' => 'OnlineStore',
                '@id' => $orgId,
                'name' => (string) ($organization['name'] ?? $context['site_name'] ?? ''),
                'url' => (string) ($organization['url'] ?? $siteUrl),
            ],
            [
                '@type' => 'WebSite',
                '@id' => rtrim($siteUrl, '/') . '/#website',
                'url' => $siteUrl,
                'name' => (string) ($context['site_name'] ?? ''),
                'publisher' => ['@id' => $orgId],
            ],
        ];

        if (!empty($organization['logo'])) {
            $logo = $this->absoluteUrl((string) $organization['logo'], $url !== '' ? $url : $siteUrl);
            if ($logo !== '') {
                $graph[0]['logo'] = $logo;
            }
        }
        if (!empty($organization['alternateName'])) {
            $alternate = $organization['alternateName'];
            if (is_string($alternate) && trim($alternate) !== '') {
                $graph[0]['alternateName'] = trim($alternate);
            } elseif (is_array($alternate)) {
                $names = array_values(array_filter(array_map(
                    static fn ($item): string => trim((string) $item),
                    $alternate
                )));
                if ($names !== []) {
                    $graph[0]['alternateName'] = count($names) === 1 ? $names[0] : $names;
                }
            }
        }
        if (!empty($organization['sameAs']) && is_array($organization['sameAs'])) {
            $graph[0]['sameAs'] = array_values(array_filter($organization['sameAs']));
        }
        if (!empty($organization['telephone'])) {
            $graph[0]['telephone'] = (string) $organization['telephone'];
        }
        if (!empty($organization['address'])) {
            $graph[0]['address'] = $organization['address'];
        }
        $availableLanguages = $this->availableLanguages($context);
        if ($availableLanguages !== []) {
            $graph[1]['availableLanguage'] = $availableLanguages;
        }
        $searchUrlTemplate = $this->searchUrlTemplate($context, $siteUrl);
        if ($searchUrlTemplate !== '') {
            $graph[1]['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $searchUrlTemplate,
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        $product = [];
        if ($this->normalizePageType((string) ($context['page_type'] ?? '')) === 'product') {
            $product = $this->productNode($context, $url, $orgId);
        }
        $article = [];
        if ($this->isArticlePageType((string) ($context['page_type'] ?? ''))) {
            $article = $this->articleNode($context, $url, $orgId);
        }
        // Ecommerce Product ItemList is not a Google product rich result.
        // Blog list/category may emit Article/BlogPosting ItemList (Carousel documents Article).
        $itemList = $this->itemListNode($context, $url);

        $webPage = $this->webPageNode($context, $orgId);
        if ($product !== []) {
            $webPage['mainEntity'] = ['@id' => $url . '#product'];
        } elseif ($article !== []) {
            $webPage['mainEntity'] = ['@id' => $url . '#article'];
        } elseif ($itemList !== []) {
            $webPage['mainEntity'] = ['@id' => $url . '#itemlist'];
        } elseif ($this->isFaqMainEntityPage($context)) {
            $webPage['mainEntity'] = ['@id' => $url . '#faq'];
        }
        $graph[] = $webPage;

        foreach ($this->breadcrumbNodes($context, $url) as $breadcrumbNode) {
            $graph[] = $breadcrumbNode;
        }
        if ($product !== []) {
            $graph[] = $product;
        }
        if ($article !== []) {
            $graph[] = $article;
        }
        if ($itemList !== []) {
            $graph[] = $itemList;
        }
        foreach ($this->structureRegistry()->buildNodes($context, $url) as $node) {
            $graph[] = $node;
        }
        foreach ((array) ($context['schema_nodes'] ?? []) as $node) {
            if (is_array($node) && $node !== []) {
                $graph[] = $node;
            }
        }

        return $this->dedupeGraphNodes(array_values(array_filter($graph)));
    }

    /**
     * Prefer the first node for a given @id so Provider schema_nodes cannot double Product.
     *
     * @param array<int, array<string, mixed>> $graph
     * @return array<int, array<string, mixed>>
     */
    private function dedupeGraphNodes(array $graph): array
    {
        $seen = [];
        $deduped = [];
        foreach ($graph as $node) {
            if (!is_array($node) || $node === []) {
                continue;
            }
            $id = trim((string) ($node['@id'] ?? ''));
            if ($id !== '') {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
            }
            $deduped[] = $node;
        }

        return $deduped;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function webPageNode(array $context, string $orgId): array
    {
        $node = [
            '@type' => $this->webPageType((string) ($context['page_type'] ?? '')),
            '@id' => (string) ($context['canonical_url'] ?? $context['url'] ?? '') . '#webpage',
            'url' => (string) ($context['canonical_url'] ?? $context['url'] ?? ''),
            'name' => (string) ($context['title'] ?? ''),
            'description' => (string) ($context['description'] ?? ''),
            'isPartOf' => ['@id' => $this->siteRoot((string) ($context['canonical_url'] ?? '')) . '#website'],
            'publisher' => ['@id' => $orgId],
        ];
        $language = $this->htmlLanguage($context);
        if ($language !== '') {
            $node['inLanguage'] = $language;
        }
        return $node;
    }

    /**
     * Google allows multiple BreadcrumbList nodes for multi-path navigation.
     *
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function breadcrumbNodes(array $context, string $url): array
    {
        $trails = [];
        $rawTrails = $context['breadcrumb_trails'] ?? null;
        if (is_array($rawTrails) && $rawTrails !== []) {
            foreach ($rawTrails as $trail) {
                if (is_array($trail) && $trail !== []) {
                    $trails[] = $trail;
                }
            }
        }
        if ($trails === [] && !empty($context['breadcrumbs']) && is_array($context['breadcrumbs'])) {
            $trails[] = (array) $context['breadcrumbs'];
        }

        $nodes = [];
        foreach ($trails as $trail) {
            $node = $this->breadcrumbNode($trail, $url);
            if ($node !== []) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Google BreadcrumbList example shape:
     * { "@type":"BreadcrumbList", "itemListElement":[ ListItem{position,name,item?}, ... ] }
     * — no @id; last ListItem omits item; earlier item values are absolute URLs.
     *
     * @param array<int, array{name?:string,url?:string}> $breadcrumbs
     * @return array<string, mixed>
     */
    private function breadcrumbNode(array $breadcrumbs, string $url): array
    {
        $cleaned = [];
        foreach ($breadcrumbs as $breadcrumb) {
            if (!is_array($breadcrumb)) {
                continue;
            }
            $name = $this->cleanText((string) ($breadcrumb['name'] ?? ''));
            // Prefer the product/page leaf over "Title | Brand" site-suffix pollution.
            if (str_contains($name, ' | ')) {
                $name = trim((string) explode(' | ', $name, 2)[0]);
            }
            if ($name === '') {
                continue;
            }
            $cleaned[] = [
                'name' => $name,
                'url' => trim((string) ($breadcrumb['url'] ?? '')),
            ];
        }
        if (count($cleaned) < 2) {
            return [];
        }

        $items = [];
        $total = count($cleaned);
        foreach ($cleaned as $index => $breadcrumb) {
            $entry = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $breadcrumb['name'],
            ];
            $isLast = $index === $total - 1;
            if (!$isLast) {
                $rawItem = $breadcrumb['url'];
                if ($rawItem === '' || $rawItem === '#' ) {
                    $rawItem = $this->siteRoot($url);
                }
                $absolute = $this->absoluteUrl($rawItem === '/' ? '/' : $rawItem, $url);
                if ($absolute === '' && $rawItem !== '') {
                    $absolute = $this->absoluteUrl('/' . ltrim($rawItem, '/'), $url);
                }
                if ($absolute !== '') {
                    $entry['item'] = $absolute;
                }
            }
            $items[] = $entry;
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * Blog list/category: Article/BlogPosting ItemList for discovery.
     * Ecommerce Product ItemList / CollectionPage.mainEntity is not a Google product rich result.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function itemListNode(array $context, string $url): array
    {
        $pageType = $this->normalizePageType((string) ($context['page_type'] ?? ''));
        if (!in_array($pageType, ['blog_list', 'blog_category'], true)) {
            return [];
        }

        $rawItems = $context['item_list'] ?? null;
        if (!is_array($rawItems) || $rawItems === []) {
            return [];
        }

        $elements = [];
        $position = 0;
        foreach ($rawItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $row['title'] ?? $row['headline'] ?? ''));
            $itemUrl = trim((string) ($row['url'] ?? $row['canonical_url'] ?? $row['public_url'] ?? ''));
            if ($name === '' || $itemUrl === '') {
                continue;
            }
            $absolute = $this->absoluteUrl($itemUrl, $url);
            if ($absolute === '') {
                $absolute = $itemUrl;
            }
            $position++;
            $articleNode = [
                '@type' => 'BlogPosting',
                'headline' => $name,
                'url' => $absolute,
            ];
            $description = trim((string) ($row['description'] ?? $row['excerpt'] ?? ''));
            if ($description !== '') {
                $articleNode['description'] = $description;
            }
            $published = trim((string) ($row['datePublished'] ?? $row['date_published'] ?? $row['published_at'] ?? ''));
            if ($published !== '') {
                $articleNode['datePublished'] = $this->formatDate($published) ?: $published;
            }
            $image = $row['image'] ?? null;
            if (is_string($image) && trim($image) !== '') {
                $imageUrl = $this->absoluteUrl(trim($image), $url);
                $articleNode['image'] = [$imageUrl !== '' ? $imageUrl : trim($image)];
            } elseif (is_array($image) && $image !== []) {
                $images = [];
                foreach ($image as $img) {
                    $raw = trim((string) $img);
                    if ($raw === '') {
                        continue;
                    }
                    $imageUrl = $this->absoluteUrl($raw, $url);
                    $images[] = $imageUrl !== '' ? $imageUrl : $raw;
                }
                if ($images !== []) {
                    $articleNode['image'] = $images;
                }
            }

            $elements[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $name,
                'url' => $absolute,
                'item' => $articleNode,
            ];
        }

        if ($elements === []) {
            return [];
        }

        return [
            '@type' => 'ItemList',
            '@id' => $url . '#itemlist',
            'name' => (string) ($context['title'] ?? ''),
            'numberOfItems' => count($elements),
            'itemListElement' => $elements,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function productNode(array $context, string $url, string $orgId): array
    {
        $product = $context['product'] ?? null;
        $name = $this->readNonEmpty($product, ['name', 'title']) ?: $this->readNonEmpty($context, ['title']);
        if (!$name) {
            return [];
        }

        $variants = $this->readList($this->read($product, ['variants', 'has_variant']));
        $schemaType = (string) ($this->readNonEmpty($product, ['schema_type', '@type']) ?: '');
        $isProductGroup = $schemaType === 'ProductGroup'
            || $variants !== []
            || $this->readNonEmpty($product, ['product_group_id', 'productGroupID']) !== null;
        $description = $this->cleanText((string) ($this->readNonEmpty($product, ['meta_description', 'short_description', 'description']) ?: ($context['description'] ?? '')));
        $node = [
            '@type' => $isProductGroup ? 'ProductGroup' : 'Product',
            '@id' => $url . '#product',
            'name' => (string) $name,
            'description' => $description,
            'url' => $url,
        ];

        $images = $this->productImages($context, $url);
        if ($images !== []) {
            $node['image'] = $images;
        }

        $brand = $this->readNonEmpty($product, ['brand', 'brand_name', 'manufacturer']);
        if ($brand !== null) {
            $node['brand'] = is_array($brand)
                ? array_replace(['@type' => 'Brand'], array_filter($brand, static fn ($value): bool => $value !== null && $value !== ''))
                : ['@type' => 'Brand', 'name' => (string) $brand];
        }

        foreach ([
            'sku' => ['sku'],
            'mpn' => ['mpn', 'manufacturer_part_number'],
            'productID' => ['product_id', 'entity_id', 'id', 'productID'],
            'gtin' => ['gtin'],
            'gtin8' => ['gtin8'],
            'gtin12' => ['gtin12', 'upc'],
            'gtin13' => ['gtin13', 'ean'],
            'gtin14' => ['gtin14'],
            'color' => ['color'],
            'size' => ['size'],
            'material' => ['material'],
            'pattern' => ['pattern'],
            'category' => ['category', 'category_path'],
        ] as $field => $keys) {
            $value = $this->readNonEmpty($product, $keys);
            if ($value !== null && !is_array($value)) {
                $node[$field] = (string) $value;
            }
        }

        $condition = $this->schemaCondition($this->readNonEmpty($product, ['item_condition', 'condition']));
        if ($condition !== '') {
            $node['itemCondition'] = $condition;
        }

        $properties = $this->productAdditionalProperties($product);
        if ($properties !== []) {
            $node['additionalProperty'] = $properties;
        }

        if ($isProductGroup) {
            $groupId = $this->readNonEmpty($product, ['product_group_id', 'productGroupID', 'spu', 'product_id', 'sku']);
            if ($groupId !== null && !is_array($groupId)) {
                $node['productGroupID'] = (string) $groupId;
            }
            $variesBy = $this->productVariesBy($product);
            if ($variesBy !== []) {
                $node['variesBy'] = $variesBy;
            }
            $variantNodes = $this->productVariantNodes($variants, $url, $orgId, $context);
            if ($variantNodes !== []) {
                $node['hasVariant'] = $variantNodes;
            }
        }

        $offers = $this->productOffers($product, $url, $orgId, $context);
        if ($offers !== []) {
            $node['offers'] = $offers;
        }

        $reviewNodes = $this->productReviewNodes(
            $context,
            (string) $name,
            $url,
            $isProductGroup ? 'ProductGroup' : 'Product',
            (string) ($node['@id'] ?? ($url . '#product')),
        );
        if ($reviewNodes !== []) {
            $node['review'] = count($reviewNodes) === 1 ? $reviewNodes[0] : $reviewNodes;
            $aggregateRating = $this->buildProductAggregateRating($product, $reviewNodes);
            if ($aggregateRating !== []) {
                $node['aggregateRating'] = $aggregateRating;
            }
        }

        return $node;
    }

    /**
     * @param array<int, array<string, mixed>> $reviewNodes
     * @return array<string, mixed>
     */
    private function buildProductAggregateRating(mixed $product, array $reviewNodes): array
    {
        if ($reviewNodes === []) {
            return [];
        }

        $rating = $this->readNonEmpty($product, ['rating', 'rating_value']);
        $reviewCount = (int) ($this->readNonEmpty($product, ['review_count', 'reviewCount']) ?: 0);

        if (($rating === null || (float) $rating <= 0)) {
            $ratings = [];
            foreach ($reviewNodes as $reviewNode) {
                $value = $reviewNode['reviewRating']['ratingValue'] ?? null;
                if ($value !== null && trim((string) $value) !== '') {
                    $ratings[] = (float) $value;
                }
            }
            if ($ratings !== []) {
                $rating = round(array_sum($ratings) / count($ratings), 1);
            }
        }

        if ($reviewCount <= 0) {
            $reviewCount = count($reviewNodes);
        }

        if ($rating === null || (float) $rating <= 0 || $reviewCount <= 0) {
            return [];
        }

        $aggregateRating = [
            '@type' => 'AggregateRating',
            'ratingValue' => (string) $rating,
            'reviewCount' => $reviewCount,
        ];

        $bestRating = $this->readNonEmpty($product, ['best_rating', 'bestRating']);
        $worstRating = $this->readNonEmpty($product, ['worst_rating', 'worstRating']);
        if ($bestRating !== null) {
            $aggregateRating['bestRating'] = (string) $bestRating;
        } else {
            $aggregateRating['bestRating'] = '5';
        }
        if ($worstRating !== null) {
            $aggregateRating['worstRating'] = (string) $worstRating;
        } else {
            $aggregateRating['worstRating'] = '1';
        }

        return $aggregateRating;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function productReviewNodes(
        array $context,
        string $productName,
        string $productUrl,
        string $reviewedType = 'Product',
        string $reviewedId = '',
    ): array {
        $reviews = $this->readList($context['reviews'] ?? []);
        if ($reviews === []) {
            $reviews = $this->readList($this->read($context['product'] ?? null, ['reviews']));
        }

        $nodes = [];
        foreach ($reviews as $review) {
            if (!is_array($review)) {
                continue;
            }
            $node = $this->buildProductReviewNode(
                $review,
                $productName,
                $productUrl,
                $reviewedType,
                $reviewedId,
            );
            if ($node !== []) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function buildProductReviewNode(
        array $review,
        string $productName,
        string $productUrl,
        string $reviewedType = 'Product',
        string $reviewedId = '',
    ): array {
        $body = $this->cleanText((string) ($this->readNonEmpty($review, [
            'reviewBody',
            'body',
            'content',
            'text',
        ]) ?: $this->readNonEmpty($review, ['title']) ?: ''));
        $authorName = trim((string) ($this->readNonEmpty($review, [
            'author',
            'author_name',
            'reviewer',
            'customer_name',
            'name',
        ]) ?: ''));
        $rating = $this->readNonEmpty($review, ['ratingValue', 'rating', 'score']);
        $published = $this->readNonEmpty($review, ['datePublished', 'published_at', 'created_at']);

        if ($body === '' && $rating === null) {
            return [];
        }

        $itemReviewed = [
            '@type' => $reviewedType === 'ProductGroup' ? 'ProductGroup' : 'Product',
            'name' => $productName,
            'url' => $productUrl,
        ];
        if ($reviewedId !== '') {
            $itemReviewed['@id'] = $reviewedId;
        }

        $node = [
            '@type' => 'Review',
            'itemReviewed' => $itemReviewed,
        ];

        if ($authorName !== '') {
            $node['author'] = [
                '@type' => 'Person',
                'name' => $authorName,
            ];
        }
        if ($body !== '') {
            $node['reviewBody'] = $body;
        }
        if ($rating !== null) {
            $node['reviewRating'] = [
                '@type' => 'Rating',
                'ratingValue' => (string) $rating,
                'bestRating' => (string) ($this->readNonEmpty($review, ['best_rating', 'bestRating']) ?: '5'),
                'worstRating' => (string) ($this->readNonEmpty($review, ['worst_rating', 'worstRating']) ?: '1'),
            ];
        }
        if ($published !== null) {
            $node['datePublished'] = $this->formatDate($published);
        }

        return $node;
    }

    private function webPageType(string $pageType): string
    {
        // Google-documented page subtypes only where they match Search features.
        // Ecommerce listing shells stay plain WebPage (no Product ItemList).
        // Blog list/category use CollectionPage + Article ItemList.
        // FAQPage is emitted once via structure registry, not as the shell.
        return match ($this->normalizePageType($pageType)) {
            'about', 'about_page' => 'AboutPage',
            'contact', 'contact_page' => 'ContactPage',
            'blog_list', 'blog_category' => 'CollectionPage',
            default => 'WebPage',
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isFaqMainEntityPage(array $context): bool
    {
        if (empty($context['faqs']) || !is_array($context['faqs'])) {
            return false;
        }

        $pageType = $this->normalizePageType((string) ($context['page_type'] ?? ''));
        if (in_array($pageType, ['faq', 'faq_page', 'customer_service', 'contact', 'contact_page'], true)) {
            return true;
        }

        return ($context['page_type'] ?? '') !== 'product';
    }

    private function structureRegistry(): SeoStructureRegistry
    {
        return $this->structureRegistry ?? new SeoStructureRegistry();
    }

    private function slotProviderRegistry(): SeoSlotProviderRegistry
    {
        return $this->slotProviderRegistry ?? new SeoSlotProviderRegistry(ObjectManager::getInstance());
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function productSocialTags(array $context): array
    {
        $product = $context['product'] ?? null;
        $tags = [];
        $price = $this->readNonEmpty($product, ['price', 'final_price']);
        if ($price !== null && !is_array($price)) {
            $tags['product:price:amount'] = (string) $price;
            $tags['product:price:currency'] = $this->productCurrency($product, $context);
        }
        $availability = $this->productAvailability($product);
        if ($availability !== '') {
            $tags['product:availability'] = match ($availability) {
                'https://schema.org/InStock' => 'in stock',
                'https://schema.org/OutOfStock' => 'out of stock',
                'https://schema.org/PreOrder' => 'preorder',
                'https://schema.org/BackOrder' => 'backorder',
                default => basename($availability),
            };
        }
        $condition = $this->schemaCondition($this->readNonEmpty($product, ['item_condition', 'condition']));
        if ($condition !== '') {
            $tags['product:condition'] = strtolower((string) preg_replace('/Condition$/', '', basename($condition)));
        }
        $sku = $this->readNonEmpty($product, ['sku']);
        if ($sku !== null && !is_array($sku)) {
            $tags['product:retailer_item_id'] = (string) $sku;
        }
        return $tags;
    }

    /**
     * @param array<string, mixed> $context
     * @return string[]
     */
    private function productImages(array $context, string $url): array
    {
        $product = $context['product'] ?? null;
        $images = [];
        foreach ([
            $context['image'] ?? null,
            $this->read($product, ['main_image']),
            $this->read($product, ['image']),
            $this->read($product, ['images']),
            $this->read($product, ['product_images', 'media_gallery']),
        ] as $value) {
            foreach ($this->readList($value) as $image) {
                if (is_array($image)) {
                    $image = $image['url'] ?? $image['src'] ?? $image['image'] ?? '';
                }
                $image = $this->absoluteUrl((string) $image, $url);
                if ($image !== '') {
                    $images[$image] = true;
                }
            }
        }
        return array_keys($images);
    }

    private function productOffers(mixed $product, string $url, string $orgId, array $context): array
    {
        $providedOffers = $this->read($product, ['offers']);
        if (is_array($providedOffers) && $providedOffers !== []) {
            return $this->normalizeOffers($providedOffers, $url, $orgId, $product, $context);
        }

        $variants = $this->readList($this->read($product, ['variants', 'has_variant']));
        if ($variants !== []) {
            $normalized = [];
            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $built = $this->buildOffer(
                    $variant,
                    (string) ($variant['url'] ?? $url),
                    $orgId,
                    $context,
                    $product
                );
                if ($built !== []) {
                    $sku = $this->readNonEmpty($variant, ['sku']);
                    if ($sku !== null && !is_array($sku)) {
                        $built['sku'] = (string) $sku;
                    }
                    $normalized[] = $built;
                }
            }
            if ($normalized === []) {
                return [];
            }
            if (count($normalized) === 1) {
                return $normalized[0];
            }

            return $this->aggregateOffer($normalized, $this->productCurrency($product, $context), $url);
        }

        $offer = $this->buildOffer($product, $url, $orgId, $context);
        return $offer !== [] ? $offer : [];
    }

    private function buildOffer(mixed $source, string $url, string $orgId, array $context, mixed $fallbackProduct = null): array
    {
        $price = $this->readNonEmpty($source, ['price', 'final_price', 'sale_price']);
        if ($price === null || is_array($price)) {
            return [];
        }
        $offer = [
            '@type' => 'Offer',
            'url' => $this->absoluteUrl($url, (string) ($context['canonical_url'] ?? $context['url'] ?? '')),
            'price' => (string) $price,
            'priceCurrency' => $this->productCurrency($source, $context, $fallbackProduct),
            'seller' => ['@id' => $orgId],
        ];

        $availability = $this->productAvailability($source);
        if ($availability === '' && $fallbackProduct !== null) {
            $availability = $this->productAvailability($fallbackProduct);
        }
        if ($availability !== '') {
            $offer['availability'] = $availability;
        }

        $condition = $this->schemaCondition($this->readNonEmpty($source, ['item_condition', 'condition']) ?: $this->readNonEmpty($fallbackProduct, ['item_condition', 'condition']));
        if ($condition === '') {
            // Google Product/Offer examples default to NewCondition for sellable catalog offers.
            $condition = 'https://schema.org/NewCondition';
        }
        $offer['itemCondition'] = $condition;

        foreach (['price_valid_until' => 'priceValidUntil', 'priceValidUntil' => 'priceValidUntil'] as $sourceKey => $targetKey) {
            $value = $this->readNonEmpty($source, [$sourceKey]);
            if ($value !== null && !is_array($value)) {
                $offer[$targetKey] = (string) $value;
                break;
            }
        }

        $shippingDetails = $this->readNonEmpty($source, ['shipping_details', 'shippingDetails'])
            ?: $this->readNonEmpty($fallbackProduct, ['shipping_details', 'shippingDetails']);
        if (is_array($shippingDetails) && $shippingDetails !== []) {
            $offer['shippingDetails'] = $shippingDetails;
        }

        $returnPolicy = $this->readNonEmpty($source, ['merchant_return_policy', 'hasMerchantReturnPolicy'])
            ?: $this->readNonEmpty($fallbackProduct, ['merchant_return_policy', 'hasMerchantReturnPolicy']);
        if (is_array($returnPolicy) && $returnPolicy !== []) {
            $offer['hasMerchantReturnPolicy'] = $returnPolicy;
        }

        return $offer;
    }

    /**
     * @param array<int|string, mixed> $offers
     * @return array<string, mixed>
     */
    private function normalizeOffers(array $offers, string $url, string $orgId, mixed $product, array $context): array
    {
        if (!$this->isList($offers)) {
            $offerType = (string) ($offers['@type'] ?? $offers['type'] ?? 'Offer');
            if ($offerType === 'AggregateOffer') {
                $offers['@type'] = 'AggregateOffer';
                unset($offers['type']);
                return $offers;
            }
            return $this->buildOffer($offers, (string) ($offers['url'] ?? $url), $orgId, $context, $product);
        }

        $normalized = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $built = $this->buildOffer($offer, (string) ($offer['url'] ?? $url), $orgId, $context, $product);
            if ($built !== []) {
                $normalized[] = $built;
            }
        }

        if (count($normalized) === 1) {
            return $normalized[0];
        }

        return $normalized !== [] ? $this->aggregateOffer($normalized, $this->productCurrency($product, $context), $url) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $offers
     * @return array<string, mixed>
     */
    private function aggregateOffer(array $offers, string $currency, string $url = ''): array
    {
        $prices = [];
        $availability = '';
        $hasInStock = false;
        $hasOutOfStock = false;
        foreach ($offers as $offer) {
            if (isset($offer['price']) && is_numeric($offer['price'])) {
                $prices[] = (float) $offer['price'];
            }
            $offerAvailability = trim((string) ($offer['availability'] ?? ''));
            if ($offerAvailability === 'https://schema.org/InStock'
                || $offerAvailability === 'http://schema.org/InStock'
            ) {
                $hasInStock = true;
            } elseif ($offerAvailability !== '') {
                $hasOutOfStock = true;
            }
        }
        if ($hasInStock) {
            $availability = 'https://schema.org/InStock';
        } elseif ($hasOutOfStock) {
            $availability = 'https://schema.org/OutOfStock';
        }

        $aggregate = [
            '@type' => 'AggregateOffer',
            'offerCount' => count($offers),
            'priceCurrency' => $currency,
            'offers' => $offers,
        ];
        // Google Offer examples always carry a product URL; AggregateOffer benefits from the same.
        $absolute = $this->absoluteUrl($url, $url);
        if ($absolute !== '') {
            $aggregate['url'] = $absolute;
        }
        if ($availability !== '') {
            $aggregate['availability'] = $availability;
        }
        if ($prices !== []) {
            $aggregate['lowPrice'] = number_format(min($prices), 2, '.', '');
            $aggregate['highPrice'] = number_format(max($prices), 2, '.', '');
        }
        return $aggregate;
    }

    /**
     * @param array<int, mixed> $variants
     * @return array<int, array<string, mixed>>
     */
    private function productVariantNodes(array $variants, string $url, string $orgId, array $context): array
    {
        $nodes = [];
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $name = $this->readNonEmpty($variant, ['name', 'title']);
            $variantId = $this->readNonEmpty($variant, ['product_id', 'id', 'sku']) ?: $index + 1;
            $node = [
                '@type' => 'Product',
                '@id' => $url . '#variant-' . rawurlencode((string) $variantId),
                'isVariantOf' => ['@id' => $url . '#product'],
                'name' => (string) ($name ?: ($context['title'] ?? '')),
            ];
            foreach (['sku' => ['sku'], 'color' => ['color'], 'size' => ['size'], 'material' => ['material'], 'pattern' => ['pattern']] as $field => $keys) {
                $value = $this->readNonEmpty($variant, $keys);
                if ($value !== null && !is_array($value)) {
                    $node[$field] = (string) $value;
                }
            }
            $variantProperties = $this->productAdditionalProperties($variant);
            if ($variantProperties !== []) {
                $node['additionalProperty'] = $variantProperties;
            }
            $image = $this->absoluteUrl((string) ($this->readNonEmpty($variant, ['image', 'main_image']) ?? ''), $url);
            if ($image !== '') {
                $node['image'] = [$image];
            }
            $offer = $this->buildOffer($variant, (string) ($variant['url'] ?? $url), $orgId, $context, $context['product'] ?? null);
            if ($offer !== []) {
                $sku = $this->readNonEmpty($variant, ['sku']);
                if ($sku !== null && !is_array($sku)) {
                    $offer['sku'] = (string) $sku;
                }
                $node['offers'] = $offer;
            }
            $nodes[] = $node;
        }
        return $nodes;
    }

    /**
     * @return string[]
     */
    private function productVariesBy(mixed $product): array
    {
        $values = [];
        foreach ($this->readList($this->read($product, ['varies_by', 'variesBy'])) as $value) {
            $mapped = $this->schemaPropertyUrl(trim((string) $value));
            if ($mapped !== null) {
                $values[$mapped] = true;
            }
        }
        return array_keys($values);
    }

    private function schemaPropertyUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^https?:\/\/schema\.org\//i', $value)) {
            return $value;
        }
        $normalized = strtolower($value);
        return match ($normalized) {
            'color', 'colour' => 'https://schema.org/color',
            'size' => 'https://schema.org/size',
            'material' => 'https://schema.org/material',
            'pattern', 'style', 'style_type' => 'https://schema.org/pattern',
            default => null,
        };
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function productAdditionalProperties(mixed $product): array
    {
        $properties = [];
        foreach ([
            $this->read($product, ['additionalProperty', 'additional_property']),
            $this->read($product, ['specifications']),
            $this->read($product, ['attributes']),
        ] as $source) {
            foreach ($this->readList($source) as $item) {
                if (is_array($item) && isset($item['items']) && is_array($item['items'])) {
                    foreach ($item['items'] as $nested) {
                        $this->appendProperty($properties, $nested);
                    }
                    continue;
                }
                $this->appendProperty($properties, $item);
            }
        }
        return array_values($properties);
    }

    /**
     * @param array<string, array<string, string>> $properties
     */
    private function appendProperty(array &$properties, mixed $item): void
    {
        if (!is_array($item)) {
            return;
        }
        $name = trim((string) ($item['name'] ?? $item['label'] ?? $item['attribute'] ?? ''));
        $value = $item['value'] ?? $item['text'] ?? null;
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map('strval', $value)));
        }
        $value = trim((string) $value);
        if ($name === '' || $value === '') {
            return;
        }
        $property = [
            '@type' => 'PropertyValue',
            'name' => $name,
            'value' => $value,
        ];
        $code = trim((string) ($item['propertyID'] ?? $item['property_id'] ?? $item['code'] ?? $item['attribute_code'] ?? ''));
        if ($code !== '') {
            $property['propertyID'] = $code;
        }
        $properties[strtolower($name)] = $property;
    }

    private function productCurrency(mixed $product, array $context, mixed $fallbackProduct = null): string
    {
        $currency = $this->readNonEmpty($product, ['price_currency', 'currency'])
            ?: $this->readNonEmpty($fallbackProduct, ['price_currency', 'currency'])
            ?: ($context['currency'] ?? '')
            ?: w_env('user.currency', 'USD');
        return strtoupper((string) $currency);
    }

    private function productAvailability(mixed $product): string
    {
        $explicit = $this->readNonEmpty($product, ['availability']);
        if ($explicit !== null && !is_array($explicit)) {
            $value = trim((string) $explicit);
            if (preg_match('/^https?:\/\//i', $value)) {
                return $value;
            }
            return match (strtolower(str_replace([' ', '-', '_'], '', $value))) {
                'instock', 'available' => 'https://schema.org/InStock',
                'outofstock', 'soldout', 'unavailable' => 'https://schema.org/OutOfStock',
                'preorder' => 'https://schema.org/PreOrder',
                'backorder' => 'https://schema.org/BackOrder',
                default => '',
            };
        }

        $inStock = $this->read($product, ['in_stock']);
        if ($inStock !== null && $inStock !== '') {
            $bool = filter_var($inStock, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool !== null) {
                return $bool ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
            }
            return $this->isInStock($inStock) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
        }

        $stock = $this->read($product, ['stock', 'qty', 'stock_status']);
        if ($stock === null || $stock === '') {
            return '';
        }
        return $this->isInStock($stock) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
    }

    private function schemaCondition(mixed $condition): string
    {
        if ($condition === null || is_array($condition)) {
            return '';
        }
        $condition = trim((string) $condition);
        if ($condition === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $condition)) {
            return $condition;
        }
        return match (strtolower(str_replace([' ', '-', '_'], '', $condition))) {
            'new', 'newcondition' => 'https://schema.org/NewCondition',
            'used', 'usedcondition' => 'https://schema.org/UsedCondition',
            'refurbished', 'refurbishedcondition' => 'https://schema.org/RefurbishedCondition',
            'damaged', 'damagedcondition' => 'https://schema.org/DamagedCondition',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function articleNode(array $context, string $url, string $orgId): array
    {
        $page = $context['page'] ?? null;
        $article = $context['article'] ?? [];
        $currentPost = $context['current_post'] ?? null;
        $headline = (string) ($this->readNonEmpty($article, ['headline', 'title', 'name'])
            ?: $this->readNonEmpty($currentPost, ['headline', 'title', 'name'])
            ?: $this->readNonEmpty($page, ['headline', 'title', 'name'])
            ?: ($context['title'] ?? ''));
        if (trim($headline) === '') {
            return [];
        }

        $node = [
            '@type' => $this->isNewsArticle($context) ? 'NewsArticle' : 'BlogPosting',
            '@id' => $url . '#article',
            'headline' => $headline,
            'description' => (string) ($this->readNonEmpty($article, ['description', 'summary', 'excerpt'])
                ?: $this->readNonEmpty($currentPost, ['description', 'summary', 'excerpt'])
                ?: $this->readNonEmpty($page, ['description', 'summary', 'excerpt', 'meta_description'])
                ?: ($context['description'] ?? '')),
            'url' => $url,
            'mainEntityOfPage' => ['@id' => $url . '#webpage'],
            'publisher' => ['@id' => $orgId],
            'author' => $this->articleAuthors($article, $currentPost, $orgId),
        ];

        $images = [];
        foreach ([$context['image'] ?? '', $this->readNonEmpty($article, ['image', 'cover_image', 'featured_image']), $this->readNonEmpty($currentPost, ['image', 'cover_image', 'featured_image'])] as $image) {
            if (!is_array($image) && trim((string) $image) !== '') {
                $images[(string) $image] = true;
            }
        }
        if ($images !== []) {
            $node['image'] = array_keys($images);
        }

        $published = $this->firstNonEmptyValue([
            $this->read($article, ['datePublished', 'date_published', 'published_at', 'created_at', 'create_time']),
            $this->read($currentPost, ['datePublished', 'date_published', 'published_at', 'created_at', 'create_time']),
            $this->read($page, ['datePublished', 'date_published', 'published_at', 'created_at', 'create_time']),
        ]);
        $modified = $this->firstNonEmptyValue([
            $this->read($article, ['dateModified', 'date_modified', 'updated_at', 'modified_at', 'update_time']),
            $this->read($currentPost, ['dateModified', 'date_modified', 'updated_at', 'modified_at', 'update_time']),
            $this->read($page, ['dateModified', 'date_modified', 'updated_at', 'modified_at', 'update_time']),
        ]);
        if ($published) {
            $node['datePublished'] = $this->formatDate($published);
        }
        if ($modified) {
            $node['dateModified'] = $this->formatDate($modified);
        }
        $section = $this->firstNonEmptyValue([
            $this->read($article, ['articleSection', 'article_section', 'section', 'category']),
            $this->read($currentPost, ['articleSection', 'article_section', 'section', 'category', 'category_name']),
        ]);
        if ($section !== null && !is_array($section)) {
            $node['articleSection'] = (string) $section;
        }
        $keywords = $this->articleKeywords($article, $currentPost, $context);
        if ($keywords !== []) {
            $node['keywords'] = implode(', ', $keywords);
        }
        $wordCount = $this->firstNonEmptyValue([
            $this->read($article, ['wordCount', 'word_count']),
            $this->read($currentPost, ['wordCount', 'word_count']),
        ]);
        if (is_numeric($wordCount)) {
            $node['wordCount'] = (int) $wordCount;
        }
        $language = $this->htmlLanguage($context);
        if ($language !== '') {
            $node['inLanguage'] = $language;
        }
        if ($node['@type'] === 'NewsArticle' && !empty($context['speakable']) && is_array($context['speakable'])) {
            $node['speakable'] = array_replace(['@type' => 'SpeakableSpecification'], $context['speakable']);
        }
        return $node;
    }

    private function socialType(string $pageType): string
    {
        $normalized = $this->normalizePageType($pageType);
        if ($normalized === 'product') {
            return 'product';
        }
        if ($this->isArticlePageType($normalized)) {
            return 'article';
        }
        return 'website';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function searchUrlTemplate(array $context, string $siteUrl): string
    {
        // Only an explicit false disables SearchAction. Empty/null/missing must not
        // trip filter_var(..., FILTER_VALIDATE_BOOLEAN) === false (PHP treats '' as false).
        if (array_key_exists('site_search_enabled', $context)) {
            $raw = $context['site_search_enabled'];
            $explicitOff = $raw === false
                || $raw === 0
                || $raw === '0'
                || $raw === 'false'
                || $raw === 'False'
                || $raw === 'FALSE'
                || $raw === 'off'
                || $raw === 'Off'
                || $raw === 'OFF';
            if ($explicitOff) {
                return '';
            }
        }
        $default = rtrim($siteUrl, '/') . '/search?q={search_term_string}';
        $template = trim((string) ($context['search_url_template'] ?? $context['site_search_url_template'] ?? ''));
        if ($template === '' || !str_contains($template, '{search_term_string}')) {
            $template = $default;
        }
        if ($template === '' || !str_contains($template, '{search_term_string}')) {
            return '';
        }
        if (!preg_match('/^https?:\/\//i', $template)) {
            $template = rtrim($siteUrl, '/') . '/' . ltrim($template, '/');
        }
        return $template;
    }

    private function isArticlePageType(string $pageType): bool
    {
        return in_array($this->normalizePageType($pageType), ['article', 'blog_post', 'post', 'news', 'news_article'], true);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isNewsArticle(array $context): bool
    {
        if (in_array($this->normalizePageType((string) ($context['page_type'] ?? '')), ['news', 'news_article'], true)) {
            return true;
        }
        foreach ([$context['article'] ?? [], $context['current_post'] ?? null, $context['page'] ?? null] as $source) {
            $isNews = $this->read($source, ['is_news']);
            if ($isNews !== null && filter_var($isNews, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
            $type = strtolower(trim((string) ($this->readNonEmpty($source, ['article_type', 'content_type', 'type']) ?? '')));
            if (in_array($type, ['news', 'news_article'], true)) {
                return true;
            }
        }
        return false;
    }

    private function normalizePageType(string $pageType): string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($pageType)));
        if ($normalized === 'homepage' || $normalized === 'index') {
            return 'home';
        }

        return $normalized;
    }

    private function firstNonEmptyValue(array $values): mixed
    {
        foreach ($values as $value) {
            if (is_array($value) && $value !== []) {
                return $value;
            }
            if (!is_array($value) && $value !== null && trim((string) $value) !== '') {
                return $value;
            }
        }
        return null;
    }

    private function articleAuthors(mixed $article, mixed $currentPost, string $orgId): array
    {
        $authors = [];
        foreach ([
            $this->read($article, ['authors', 'author']),
            $this->read($currentPost, ['authors', 'author']),
        ] as $source) {
            if ($source === null || $source === '') {
                continue;
            }
            // Single Person object, list of Persons, or plain string — never (string)array → "Array".
            if (is_array($source) && (isset($source['name']) || isset($source['@type']) || isset($source['author_name']))) {
                $source = [$source];
            }
            foreach ($this->readList($source) as $author) {
                if (is_array($author)) {
                    $name = trim((string) ($author['name'] ?? $author['author_name'] ?? ''));
                    if ($name !== '' && strcasecmp($name, 'Array') !== 0) {
                        $authors[] = array_replace(['@type' => 'Person'], $author, ['name' => $name]);
                    }
                    continue;
                }
                $name = trim((string) $author);
                if ($name !== '' && strcasecmp($name, 'Array') !== 0) {
                    $authors[] = ['@type' => 'Person', 'name' => $name];
                }
            }
        }

        if ($authors === []) {
            $name = (string) ($this->readNonEmpty($article, ['author_name'])
                ?: $this->readNonEmpty($currentPost, ['author_name'])
                ?: '');
            if (trim($name) !== '' && strcasecmp(trim($name), 'Array') !== 0) {
                $authors[] = ['@type' => 'Person', 'name' => trim($name)];
            }
        }

        if ($authors === []) {
            return ['@id' => $orgId];
        }
        return count($authors) === 1 ? $authors[0] : $authors;
    }

    /**
     * @param array<string, mixed> $context
     * @return string[]
     */
    private function articleKeywords(mixed $article, mixed $currentPost, array $context): array
    {
        $keywords = [];
        foreach ([
            $this->read($article, ['keywords', 'tags']),
            $this->read($currentPost, ['keywords', 'tags']),
            $context['keywords'] ?? null,
        ] as $source) {
            if (is_string($source) && str_contains($source, ',')) {
                $source = array_map('trim', explode(',', $source));
            }
            foreach ($this->readList($source) as $keyword) {
                if (is_array($keyword)) {
                    $keyword = $keyword['name'] ?? $keyword['label'] ?? '';
                }
                $keyword = trim((string) $keyword);
                if ($keyword !== '') {
                    $keywords[$keyword] = true;
                }
            }
        }
        return array_keys($keywords);
    }

    private function read(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }
            if (is_object($source) && method_exists($source, 'getData')) {
                $value = $source->getData($key);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    private function readNonEmpty(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $this->read($source, [$key]);
            if (is_array($value) && $value !== []) {
                return $value;
            }
            if (!is_array($value) && $value !== null && trim((string) $value) !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * @return array<int, mixed>
     */
    private function readList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->isList($decoded) ? $decoded : [$decoded];
            }
            return [$value];
        }
        if (!is_array($value)) {
            return [$value];
        }
        return $this->isList($value) ? $value : [$value];
    }

    private function isInStock(mixed $stock): bool
    {
        if (is_numeric($stock)) {
            return (float) $stock > 0;
        }
        $stock = strtolower((string) $stock);
        if (in_array($stock, ['out_of_stock', 'out-of-stock', 'out of stock', 'soldout', 'sold out', 'unavailable'], true)) {
            return false;
        }
        return $stock === '' || str_contains($stock, 'in') || str_contains($stock, 'available');
    }

    private function absoluteUrl(string $url, string $pageUrl): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '?') || str_starts_with($url, '#')) {
            $parts = parse_url($pageUrl);
            if (!is_array($parts) || empty($parts['host'])) {
                return $url;
            }
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path = (string) ($parts['path'] ?? '/');

            return (string) ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . $port . $path . $url;
        }
        if (!str_starts_with($url, '/')) {
            // Relative path without leading slash — resolve against site root of the page URL.
            return $this->absoluteUrl('/' . ltrim($url, '/'), $pageUrl);
        }

        $parts = parse_url($pageUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return (string) ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . $port . $url;
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
        return trim($value);
    }

    private function siteRoot(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return rtrim((string) w_env('website.url', ''), '/');
        }
        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
    }

    private function formatDate(mixed $value): string
    {
        if (is_numeric($value)) {
            return date('c', (int) $value);
        }
        $time = strtotime((string) $value);
        return date('c', $time ?: time());
    }

    /**
     * @param array<string, mixed> $context
     * @return string[]
     */
    private function availableLanguages(array $context): array
    {
        $languages = [];
        foreach ((array) ($context['available_languages'] ?? []) as $language) {
            if (!is_string($language)) {
                continue;
            }
            $normalized = $this->normalizeHreflang($language);
            if ($normalized !== '' && $normalized !== 'x-default') {
                $languages[$normalized] = true;
            }
        }
        foreach ((array) ($context['alternates'] ?? []) as $locale => $_url) {
            if (!is_string($locale)) {
                continue;
            }
            $normalized = $this->normalizeHreflang($locale);
            if ($normalized !== '' && $normalized !== 'x-default') {
                $languages[$normalized] = true;
            }
        }
        return array_keys($languages);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function htmlLanguage(array $context): string
    {
        foreach (['html_locale', 'locale'] as $key) {
            $language = $this->normalizeHreflang((string) ($context[$key] ?? ''));
            if ($language !== '' && $language !== 'x-default') {
                return $language;
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $context
     * @return string[]
     */
    private function alternateOgLocales(array $context, string $currentOgLocale): array
    {
        $alternates = [];
        foreach ((array) ($context['alternates'] ?? []) as $locale => $_url) {
            if (!is_string($locale) || strtolower($locale) === 'x-default') {
                continue;
            }
            $ogLocale = $this->toOgLocale($locale);
            if ($ogLocale === '' || $ogLocale === $currentOgLocale) {
                continue;
            }
            $alternates[$ogLocale] = true;
        }
        return array_keys($alternates);
    }

    private function normalizeHreflang(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return '';
        }
        if (strtolower($locale) === 'x-default') {
            return 'x-default';
        }
        $parts = array_values(array_filter(preg_split('/[-_]/', $locale) ?: [], static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return '';
        }
        $normalized = [strtolower($parts[0])];
        for ($i = 1, $count = count($parts); $i < $count; $i++) {
            $part = $parts[$i];
            $normalized[] = strlen($part) === 4
                ? ucfirst(strtolower($part))
                : strtoupper($part);
        }
        return implode('-', $normalized);
    }

    private function toOgLocale(string $locale): string
    {
        $hreflang = $this->normalizeHreflang($locale);
        if ($hreflang === '' || $hreflang === 'x-default') {
            return '';
        }
        return str_replace('-', '_', $hreflang);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
