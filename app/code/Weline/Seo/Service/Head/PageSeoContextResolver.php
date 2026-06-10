<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

use Weline\Seo\Structure\Faq\FaqStructureNormalizer;

class PageSeoContextResolver
{
    public function __construct(
        private readonly ?HeadProviderRegistry $providerRegistry = null,
        private readonly ?HeadIntegrationContextService $headIntegrationContextService = null,
        private readonly ?FaqStructureNormalizer $faqStructureNormalizer = null
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function resolve($template, array $options = []): array
    {
        $meta = $this->pageMeta($template);
        $seo = $this->toArray($this->readTemplate($template, 'seo'));
        $product = $this->readTemplate($template, 'product');
        $category = $this->readTemplate($template, 'category');
        $page = $this->readTemplate($template, 'page');
        $currentPost = $this->readTemplate($template, 'current_post');
        if (!$page && $currentPost) {
            $page = $currentPost;
        }

        $siteName = $this->firstNonEmpty([
            $this->readTemplate($template, 'site_name'),
            $this->read($seo, ['site_name', 'siteName']),
            $this->read($meta, ['site_name', 'siteName']),
            'Weline Framework',
        ]);

        $layoutName = $this->layoutName($template, $meta);
        $controllerTitle = $this->firstNonEmpty([
            $this->read($meta, ['controller_title']),
            $this->meaningfulTemplateTitle($template),
        ]);
        $layoutAwareTitle = $this->combineTitleAndLayoutName((string) $controllerTitle, (string) $layoutName);
        $title = $this->firstNonEmpty([
            $this->read($seo, ['title', 'meta_title']),
            $this->readTemplate($template, 'meta_title'),
            $this->read($meta, ['meta_title']),
            $this->read($product, ['meta_name', 'meta_title', 'name', 'title']),
            $this->read($category, ['meta_title', 'name', 'title']),
            $this->read($page, ['meta_title', 'title', 'name']),
            $layoutAwareTitle,
            $this->read($meta, ['title']),
            $this->routeTitle($template),
            $siteName,
        ]);

        $description = $this->normalizeDescription($this->firstNonEmpty([
            $this->read($seo, ['description', 'meta_description']),
            $this->readTemplate($template, 'meta_description'),
            $this->metaDescription($meta),
            $this->read($product, ['meta_description', 'short_description', 'description']),
            $this->read($category, ['meta_description', 'description']),
            $this->read($page, ['meta_description', 'ai_description', 'description', 'excerpt']),
            $this->readTemplate($template, 'description'),
        ]));
        if ($description === '') {
            $description = $this->defaultDescription((string) $title, (string) $siteName);
        }

        $keywords = $this->firstNonEmpty([
            $this->read($seo, ['keywords', 'meta_keywords']),
            $this->readTemplate($template, 'meta_keywords'),
            $this->read($meta, ['meta_keywords', 'keywords']),
            $this->read($product, ['meta_keywords', 'keywords', 'tags']),
            $this->read($category, ['meta_keywords', 'keywords']),
            $this->read($page, ['meta_keywords', 'keywords', 'tags']),
        ]);

        $url = $this->currentUrl($template);
        $canonical = $this->firstNonEmpty([
            $this->read($seo, ['canonical', 'canonical_url']),
            $this->readTemplate($template, 'canonical_url'),
            $this->read($meta, ['canonical', 'canonical_url']),
            $this->read($product, ['canonical', 'url']),
            $this->read($category, ['canonical', 'url']),
            $this->read($page, ['canonical', 'url']),
            $this->canonicalizeUrl($url),
        ]);

        $image = $this->absoluteUrl($template, $this->firstNonEmpty([
            $this->read($seo, ['image', 'og_image']),
            $this->read($product, ['image']),
            $this->firstImage($this->read($product, ['images'])),
            $this->read($page, ['image', 'cover_image', 'featured_image']),
        ]));

        $pageType = $this->detectPageType($product, $category, $page, $meta, $seo);
        $breadcrumbs = $this->normalizeBreadcrumbs($this->firstNonEmpty([
            $this->readTemplate($template, 'breadcrumbs'),
            $this->read($seo, ['breadcrumbs']),
            $this->read($meta, ['breadcrumbs']),
        ]), $template);

        $explicitRobots = (string) $this->firstNonEmpty([
            $this->read($seo, ['robots']),
            $this->read($meta, ['robots']),
        ]);

        $context = [
            'page_type' => $pageType,
            'site_name' => (string) $siteName,
            'title' => (string) $title,
            'description' => (string) $description,
            'keywords' => $keywords,
            'robots' => $explicitRobots,
            '_robots_explicit' => $explicitRobots !== '',
            'url' => $url,
            'canonical_url' => (string) $canonical,
            'image' => $image,
            'locale' => (string) ($this->readTemplate($template, 'lang_local') ?: w_env('user.lang', 'en_US')),
            'alternates' => $this->normalizeAlternates($this->read($seo, ['alternates', 'hreflang'])),
            'breadcrumbs' => $breadcrumbs,
            'product' => $product,
            'category' => $category,
            'page' => $page,
            'current_post' => $currentPost,
            'article' => $this->toArray($this->firstNonEmpty([
                $this->readTemplate($template, 'article'),
                $this->read($seo, ['article']),
                $this->read($page, ['article']),
                $currentPost,
            ])),
            'item_list' => $this->normalizeItemList($this->firstNonEmpty([
                $this->readTemplate($template, 'item_list'),
                $this->read($seo, ['item_list', 'items']),
                $this->read($page, ['item_list', 'items']),
                $this->read($category, ['item_list', 'seo_directory']),
            ]), $template),
            'sitemap' => $this->toArray($this->firstNonEmpty([
                $this->read($seo, ['sitemap']),
                $this->read($meta, ['sitemap']),
                $this->read($page, ['sitemap']),
            ])),
            'geo' => $this->toArray($this->firstNonEmpty([
                $this->read($seo, ['geo']),
                $this->read($meta, ['geo']),
                $this->read($page, ['geo']),
            ])),
            'faqs' => $this->normalizeFaqs($this->firstNonEmpty([$this->readTemplate($template, 'faqs'), $this->read($page, ['faqs'])])),
            'qa_list' => $this->normalizeQaList($this->firstNonEmpty([
                $this->readTemplate($template, 'qa_list'),
                $this->read($page, ['qa_list']),
            ])),
            'organization' => $this->normalizeOrganization($seo, $meta, $template, (string) $siteName),
        ];

        $context = $this->mergeIntegrationContext(
            $context,
            $this->resolveIntegrationContext($template, $context)
        );

        $context = $this->applySeoProfileProviders($template, $context);
        $context['faqs'] = $this->normalizeFaqs($context['faqs'] ?? []);
        if (trim((string) ($context['robots'] ?? '')) === '') {
            $context['robots'] = $this->defaultRobots(
                (string) ($context['page_type'] ?? $pageType),
                (string) ($context['canonical_url'] ?? $canonical),
                (string) ($context['url'] ?? $url)
            );
        }

        return $context;
    }

    private function readTemplate($template, string $key): mixed
    {
        if (is_object($template) && method_exists($template, 'getData')) {
            return $template->getData($key);
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageMeta($template): array
    {
        $meta = $this->toArray($this->readTemplate($template, 'meta'));
        $layoutMeta = $this->toArray($this->readTemplate($template, 'layout'));
        if ($layoutMeta === []) {
            return $meta;
        }

        $pageMeta = array_replace($meta, $layoutMeta);
        foreach (['layout_name', 'name', 'layout_description', 'description', 'title', 'meta_title', 'meta_description'] as $key) {
            if (!array_key_exists($key, $layoutMeta)) {
                unset($pageMeta[$key]);
            }
        }

        return $pageMeta;
    }

    /**
     * @param mixed $source
     * @param string[] $keys
     */
    private function read(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }
            if (is_object($source)) {
                if (method_exists($source, 'getData')) {
                    $value = $source->getData($key);
                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                }
                $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
                if (method_exists($source, $method)) {
                    return $source->{$method}();
                }
            }
        }
        return null;
    }

    /**
     * @param mixed[] $values
     */
    private function firstNonEmpty(array $values): mixed
    {
        foreach ($values as $value) {
            if (is_array($value) && $value !== []) {
                return $value;
            }
            if (is_object($value) && !method_exists($value, '__toString')) {
                continue;
            }
            if (!is_array($value) && $value !== null && trim((string) $value) !== '') {
                return $value;
            }
        }
        return '';
    }

    private function normalizeDescription(mixed $description): string
    {
        $text = trim(html_entity_decode(strip_tags((string) $description), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        return mb_substr($text, 0, 300);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function metaDescription(array $meta): string
    {
        $metaDescription = $this->normalizeMetaText($meta['meta_description'] ?? null);
        if ($metaDescription !== '') {
            return $metaDescription;
        }

        $description = $this->normalizeMetaText($meta['description'] ?? null);
        $layoutDescription = $this->normalizeMetaText($meta['layout_description'] ?? null);
        if ($description !== '' && $description !== $layoutDescription) {
            return $description;
        }

        return '';
    }

    private function defaultDescription(string $title, string $siteName): string
    {
        $title = trim($title);
        $siteName = trim($siteName);
        if ($title !== '' && $siteName !== '' && $title !== $siteName) {
            return $this->normalizeDescription($title . ' - ' . $siteName);
        }
        return $this->normalizeDescription($title ?: $siteName);
    }

    private function meaningfulTemplateTitle($template): string
    {
        $title = trim((string) $this->readTemplate($template, 'title'));
        if ($title === '') {
            return '';
        }

        $moduleTitle = $this->requestModuleName();
        if (($moduleTitle !== '' && $title === $moduleTitle) || $this->isModuleCodeTitle($title)) {
            return '';
        }

        return $title;
    }

    private function isModuleCodeTitle(string $title): bool
    {
        return (bool) preg_match('/^[A-Z][A-Za-z0-9]*_[A-Z][A-Za-z0-9_]*$/', $title);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function layoutName($template, array $meta): string
    {
        return (string) $this->firstNonEmpty([
            $this->normalizeMetaText($meta['layout_name'] ?? null),
            $this->normalizeMetaText($meta['name'] ?? null),
            $this->layoutNameFromThemeContext($template),
        ]);
    }

    private function layoutNameFromThemeContext($template): string
    {
        $themeData = $this->readTemplate($template, 'theme');
        if (!is_array($themeData)) {
            return '';
        }

        $layoutType = trim((string) ($themeData['layoutType'] ?? ''));
        $layoutOption = trim((string) ($themeData['layoutOption'] ?? ''));
        $area = trim((string) ($themeData['area'] ?? 'frontend')) ?: 'frontend';
        $theme = $themeData['theme'] ?? null;
        if ($layoutType === '' || $layoutOption === '' || !is_object($theme)) {
            return '';
        }

        if (!class_exists(\Weline\Theme\Helper\LayoutPathResolver::class)
            || !class_exists(\Weline\Theme\Helper\ComponentMetaParser::class)) {
            return '';
        }

        try {
            $layoutPath = \Weline\Theme\Helper\LayoutPathResolver::buildLayoutPath('', $area, $layoutType, $layoutOption);
            $resolvedLayoutPath = \Weline\Theme\Helper\LayoutPathResolver::resolveLayoutTemplate($layoutPath, $theme, $area);
            if (!$resolvedLayoutPath) {
                return '';
            }

            $layoutFilePath = \Weline\Theme\Helper\LayoutPathResolver::getLayoutFilePath($resolvedLayoutPath, $theme, $area);
            $layoutName = $this->layoutNameFromFile($layoutFilePath);
            if ($layoutName !== '') {
                return $layoutName;
            }

            if (strpos($resolvedLayoutPath, '::') !== false) {
                [, $relativePath] = explode('::', $resolvedLayoutPath, 2);
                $defaultPath = \Weline\Theme\Helper\LayoutPathResolver::getDefaultLayoutPath($relativePath, $area);
                return $this->layoutNameFromFile($defaultPath);
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function layoutNameFromFile(?string $layoutFilePath): string
    {
        if (!$layoutFilePath || !is_file($layoutFilePath)) {
            return '';
        }

        try {
            $parsed = \Weline\Theme\Helper\ComponentMetaParser::parse($layoutFilePath);
        } catch (\Throwable) {
            return '';
        }

        $meta = $parsed['meta'] ?? [];
        if (!is_array($meta)) {
            return '';
        }

        return (string) $this->firstNonEmpty([
            $this->normalizeMetaText($meta['layout_name'] ?? null),
            $this->normalizeMetaText($meta['name'] ?? null),
        ]);
    }

    private function normalizeMetaText(mixed $value): string
    {
        $text = '';
        if (is_array($value)) {
            foreach (['default', 'name', 'value', 'label'] as $key) {
                if (isset($value[$key]) && trim((string) $value[$key]) !== '') {
                    $text = trim((string) $value[$key]);
                    break;
                }
            }
        } else {
            $text = trim((string) $value);
        }

        return $text === '' ? '' : (string) __($text);
    }

    private function combineTitleAndLayoutName(string $title, string $layoutName): string
    {
        $title = trim($title);
        $layoutName = trim($layoutName);
        if ($title === '') {
            return $layoutName;
        }
        if ($layoutName === '' || mb_strtolower($title) === mb_strtolower($layoutName)) {
            return $title;
        }
        if (mb_strpos(mb_strtolower($title), mb_strtolower($layoutName)) !== false) {
            return $title;
        }
        return $title . ' | ' . $layoutName;
    }

    private function requestModuleName(): string
    {
        $request = $this->currentRequest();
        if (is_object($request) && method_exists($request, 'getModuleName')) {
            return trim((string) $request->getModuleName());
        }
        return '';
    }

    private function routeTitle($template): string
    {
        $path = $this->requestPath($template);
        if ($path === '') {
            return '';
        }

        $path = (string) (parse_url($path, PHP_URL_PATH) ?: $path);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static function (string $segment): bool {
            return $segment !== '' && !is_numeric($segment);
        }));
        if ($segments === []) {
            return '';
        }

        return $this->humanizeRouteSegment((string) end($segments));
    }

    private function requestPath($template): string
    {
        $fullUri = (string) \Weline\Framework\Env\WelineEnv::server('WELINE_FULL_REQUEST_URI', '');
        if ($fullUri !== '' && preg_match('/^https?:\/\//i', $fullUri)) {
            return (string) (parse_url($fullUri, PHP_URL_PATH) ?: '');
        }

        $uri = (string) \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
        if ($uri !== '') {
            return (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
        }

        $request = $this->currentRequest();
        if (is_object($request) && method_exists($request, 'getUrlPath')) {
            try {
                return trim((string) $request->getUrlPath());
            } catch (\Throwable) {
            }
        }

        $currentPage = $this->readTemplate($template, 'current_page');
        return is_string($currentPage) ? trim($currentPage) : '';
    }

    private function currentRequest(): mixed
    {
        try {
            if (class_exists(\Weline\Framework\Manager\ObjectManager::class)
                && class_exists(\Weline\Framework\Http\Request::class)) {
                return \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function humanizeRouteSegment(string $segment): string
    {
        $segment = strtolower(trim($segment));
        if ($segment === '') {
            return '';
        }

        $labels = [
            'login' => 'Sign In',
            'signin' => 'Sign In',
            'sign-in' => 'Sign In',
            'register' => 'Register',
            'forgot-password' => 'Forgot Password',
            'reset-password' => 'Reset Password',
        ];
        if (isset($labels[$segment])) {
            return $labels[$segment];
        }

        return ucwords(preg_replace('/[-_]+/', ' ', $segment) ?: $segment);
    }

    private function currentUrl($template): string
    {
        try {
            if (is_object($template) && isset($template->request)) {
                return (string) $template->request->getUrlBuilder()->getCurrentUrl([], true);
            }
        } catch (\Throwable) {
        }

        $fullUrl = (string) \Weline\Framework\Env\WelineEnv::server('WELINE_FULL_REQUEST_URI', '');
        if ($fullUrl !== '' && preg_match('/^https?:\/\//i', $fullUrl)) {
            return $fullUrl;
        }

        $baseUrl = $this->currentRequestBaseUrl();
        if ($baseUrl === '') {
            return '';
        }

        $uri = (string) \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '/');
        if ($uri === '') {
            $uri = '/';
        }
        if (preg_match('/^https?:\/\//i', $uri)) {
            return $uri;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($uri, '/');
    }

    private function canonicalizeUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return $url;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $this->canonicalPath((string)($parts['path'] ?? '/'));
        return $scheme . '://' . $host . $port . $path;
    }

    private function canonicalPath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return '/';
        }

        $currentCurrency = strtoupper(trim((string) \w_env('user.currency', '')));
        $defaultCurrency = strtoupper(trim((string) (
            \w_env('website.currency', '')
            ?: \Weline\Framework\App\Env::get('currency', 'CNY')
            ?: 'CNY'
        )));
        if ($currentCurrency === '') {
            $currentCurrency = $defaultCurrency;
        }

        $currentLanguage = trim((string) \w_env('user.lang', ''));
        $defaultLanguage = trim((string) (
            \w_env('website.language', '')
            ?: \Weline\Framework\App\Env::get('locale', \Weline\Framework\App\Env::get('lang', 'zh_Hans_CN'))
            ?: 'zh_Hans_CN'
        ));
        if ($currentLanguage === '') {
            $currentLanguage = $defaultLanguage;
        }

        $languageIndex = 0;
        $firstSegment = strtoupper((string)($segments[0] ?? ''));
        if ($firstSegment !== '' && $firstSegment === $currentCurrency) {
            if ($currentCurrency === $defaultCurrency) {
                array_splice($segments, 0, 1);
            } else {
                $languageIndex = 1;
            }
        }

        if (isset($segments[$languageIndex])
            && $currentLanguage !== ''
            && $currentLanguage === $defaultLanguage
            && (string)$segments[$languageIndex] === $currentLanguage
        ) {
            array_splice($segments, $languageIndex, 1);
        }

        return $segments === [] ? '/' : '/' . implode('/', $segments);
    }

    private function absoluteUrl($template, mixed $url): string
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        $baseUrl = $this->currentRequestBaseUrl();
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
        }
        try {
            if (is_object($template) && isset($template->request)) {
                return rtrim((string) $template->request->getBaseUrl(), '/') . '/' . ltrim($url, '/');
            }
        } catch (\Throwable) {
        }

        return $url;
    }

    private function currentRequestBaseUrl(): string
    {
        $fullUrl = (string) \Weline\Framework\Env\WelineEnv::server('WELINE_FULL_REQUEST_URI', '');
        if ($fullUrl !== '' && preg_match('/^https?:\/\//i', $fullUrl)) {
            $parts = parse_url($fullUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                return (string) ($parts['scheme'] ?? 'https') . '://' . (string) $parts['host'] . $port;
            }
        }

        $scheme = (string) \Weline\Framework\Env\WelineEnv::server('REQUEST_SCHEME', '');
        if ($scheme === '') {
            $https = (string) \Weline\Framework\Env\WelineEnv::server('HTTPS', '');
            $scheme = $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
        }

        $host = (string) \Weline\Framework\Env\WelineEnv::server(
            'HTTP_HOST',
            \Weline\Framework\Env\WelineEnv::server('SERVER_NAME', '')
        );
        if ($host === '') {
            $configured = rtrim((string) (
                \Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_URL', '')
                ?: w_env('website_url', '')
                ?: w_env('website.url', '')
            ), '/');
            return $configured;
        }

        return $scheme . '://' . $host;
    }

    private function firstImage(mixed $images): string
    {
        if (!is_array($images) || $images === []) {
            return '';
        }
        $first = reset($images);
        if (is_array($first)) {
            return (string) ($first['url'] ?? $first['src'] ?? $first['image'] ?? '');
        }
        return (string) $first;
    }

    private function detectPageType(mixed $product, mixed $category, mixed $page, array $meta, array $seo): string
    {
        $explicit = (string) $this->firstNonEmpty([
            $this->read($seo, ['page_type', 'type']),
            $this->read($meta, ['page_type', 'type']),
            $this->read($page, ['page_type', 'type']),
        ]);
        if ($explicit !== '') {
            return $explicit;
        }
        if ($product) {
            return 'product';
        }
        if ($category) {
            return 'category';
        }
        return 'web_page';
    }

    private function defaultRobots(string $pageType, string $canonical, string $url): string
    {
        if ($this->isNoIndexPage($pageType, $canonical !== '' ? $canonical : $url)) {
            return 'noindex,follow';
        }
        return 'index,follow';
    }

    private function isNoIndexPage(string $pageType, string $url): bool
    {
        $normalizedType = strtolower(str_replace([' ', '-'], '_', trim($pageType)));
        if (in_array($normalizedType, [
            'search',
            'search_results',
            'cart',
            'checkout',
            'login',
            'register',
            'account',
            'customer_account',
            'backend',
            'admin',
            'preview',
            'api',
        ], true)) {
            return true;
        }

        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        foreach ($segments as $segment) {
            if (in_array($segment, [
                'backend',
                'admin',
                'rest',
                'api',
                'cart',
                'checkout',
                'login',
                'register',
                'account',
                'search',
                'preview',
                'theme-preview',
                'workspace-preview',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{name:string,url:string}>
     */
    private function normalizeBreadcrumbs(mixed $breadcrumbs, $template): array
    {
        if (!is_array($breadcrumbs)) {
            return [];
        }
        $normalized = [];
        foreach ($breadcrumbs as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? $item['label'] ?? $item['title'] ?? ''));
            $url = $this->absoluteUrl($template, (string) ($item['url'] ?? $item['href'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalized[] = ['name' => $name, 'url' => $url];
        }
        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeAlternates(mixed $alternates): array
    {
        return is_array($alternates) ? array_filter($alternates, static fn ($url): bool => is_string($url) && trim($url) !== '') : [];
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function resolveIntegrationContext($template, array $context): array
    {
        if ($this->headIntegrationContextService === null) {
            return [];
        }

        return $this->headIntegrationContextService->resolve($template, $context);
    }

    /**
     * @return array<int, array{question:string,answer:string}>
     */
    private function normalizeFaqs(mixed $faqs): array
    {
        return ($this->faqStructureNormalizer ?? new FaqStructureNormalizer())->normalize($faqs);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeQaList(mixed $items): array
    {
        if (!is_array($items) || !$this->isList($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn ($item): bool => is_array($item)));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $integration
     * @return array<string, mixed>
     */
    private function mergeIntegrationContext(array $context, array $integration): array
    {
        if ($integration === []) {
            return $context;
        }

        foreach (['schema_nodes', 'item_list', 'faqs', 'qa_list'] as $listKey) {
            if (isset($integration[$listKey]) && is_array($integration[$listKey]) && $this->isList($integration[$listKey])) {
                $existing = isset($context[$listKey]) && is_array($context[$listKey]) && $this->isList($context[$listKey])
                    ? $context[$listKey]
                    : [];
                $context[$listKey] = array_values(array_merge($existing, $integration[$listKey]));
                unset($integration[$listKey]);
            }
        }

        return array_replace_recursive($context, $integration);
    }

    /**
     * @return array<int, array{name:string,url:string,image?:string,description?:string}>
     */
    private function normalizeItemList(mixed $items, $template): array
    {
        if (!is_array($items)) {
            return [];
        }

        $flat = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $this->appendItemListEntry($flat, $item, $template);
        }

        return $flat;
    }

    /**
     * @param array<int, array<string, string>> $flat
     * @param array<string, mixed> $item
     */
    private function appendItemListEntry(array &$flat, array $item, $template): void
    {
        $name = trim((string) ($item['name'] ?? $item['title'] ?? $item['label'] ?? ''));
        $url = trim((string) ($item['url'] ?? $item['href'] ?? $item['loc'] ?? ''));
        if ($name !== '' && $url !== '') {
            $entry = [
                'name' => $name,
                'url' => $this->absoluteUrl($template, $url),
            ];
            $image = trim((string) ($item['image'] ?? $item['thumbnail'] ?? ''));
            if ($image !== '') {
                $entry['image'] = $this->absoluteUrl($template, $image);
            }
            $description = $this->normalizeDescription($item['description'] ?? $item['summary'] ?? '');
            if ($description !== '') {
                $entry['description'] = $description;
            }
            $flat[] = $entry;
        }

        $children = $item['children'] ?? [];
        if (is_array($children)) {
            foreach ($children as $child) {
                if (is_array($child)) {
                    $this->appendItemListEntry($flat, $child, $template);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeOrganization(array $seo, array $meta, $template, string $siteName): array
    {
        $organization = $this->toArray($seo['organization'] ?? $meta['organization'] ?? []);
        $organization['name'] = (string) ($organization['name'] ?? $siteName);
        $organization['url'] = (string) ($organization['url'] ?? $this->absoluteUrl($template, '/'));
        return $organization;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function applySeoProfileProviders($template, array $context): array
    {
        if (!$this->providerRegistry || !method_exists($this->providerRegistry, 'getSeoProfileProviders')) {
            return $context;
        }
        foreach ($this->providerRegistry->getSeoProfileProviders() as $provider) {
            try {
                $provided = $provider->provideSeoProfile($template, $context);
                if ($provided !== []) {
                    $context = $this->mergeProviderContext($context, $provided);
                }
            } catch (\Throwable) {
            }
        }
        return $context;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $provided
     * @return array<string, mixed>
     */
    private function mergeProviderContext(array $context, array $provided): array
    {
        foreach (['schema_nodes', 'item_list', 'faqs', 'qa_list'] as $listKey) {
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
}
