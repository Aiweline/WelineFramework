<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

class PageSeoContextResolver
{
    public function __construct(
        private readonly ?HeadProviderRegistry $providerRegistry = null
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function resolve($template, array $options = []): array
    {
        $meta = $this->toArray($this->readTemplate($template, 'meta'));
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

        $layoutName = $this->layoutName($meta);
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
            $this->read($meta, ['meta_description', 'description']),
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

        $context = [
            'page_type' => $pageType,
            'site_name' => (string) $siteName,
            'title' => (string) $title,
            'description' => (string) $description,
            'keywords' => $keywords,
            'robots' => (string) $this->firstNonEmpty([$this->read($seo, ['robots']), $this->read($meta, ['robots']), 'index,follow']),
            'url' => $url,
            'canonical_url' => (string) $canonical,
            'image' => $image,
            'locale' => (string) ($this->readTemplate($template, 'lang_local') ?: w_env('user.lang', 'en_US')),
            'alternates' => $this->normalizeAlternates($this->read($seo, ['alternates', 'hreflang'])),
            'breadcrumbs' => $breadcrumbs,
            'product' => $product,
            'category' => $category,
            'page' => $page,
            'faqs' => $this->normalizeFaqs($this->firstNonEmpty([$this->readTemplate($template, 'faqs'), $this->read($page, ['faqs'])])),
            'organization' => $this->normalizeOrganization($seo, $meta, $template, (string) $siteName),
        ];

        return $this->applyHeadContextProviders($template, $context);
    }

    private function readTemplate($template, string $key): mixed
    {
        if (is_object($template) && method_exists($template, 'getData')) {
            return $template->getData($key);
        }
        return null;
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
    private function layoutName(array $meta): string
    {
        return (string) $this->firstNonEmpty([
            $this->normalizeMetaText($meta['layout_name'] ?? null),
            $this->normalizeMetaText($meta['name'] ?? null),
        ]);
    }

    private function normalizeMetaText(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['default', 'name', 'value', 'label'] as $key) {
                if (isset($value[$key]) && trim((string) $value[$key]) !== '') {
                    return trim((string) $value[$key]);
                }
            }
            return '';
        }

        return trim((string) $value);
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
        $fullUri = (string) ($_SERVER['WELINE_FULL_REQUEST_URI'] ?? '');
        if ($fullUri !== '' && preg_match('/^https?:\/\//i', $fullUri)) {
            return (string) (parse_url($fullUri, PHP_URL_PATH) ?: '');
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
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

        $fullUrl = (string) ($_SERVER['WELINE_FULL_REQUEST_URI'] ?? '');
        if ($fullUrl !== '' && preg_match('/^https?:\/\//i', $fullUrl)) {
            return $fullUrl;
        }

        $baseUrl = $this->currentRequestBaseUrl();
        if ($baseUrl === '') {
            return '';
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
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
        $path = $parts['path'] ?? '/';
        return $scheme . '://' . $host . $port . $path;
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
        $fullUrl = (string) ($_SERVER['WELINE_FULL_REQUEST_URI'] ?? '');
        if ($fullUrl !== '' && preg_match('/^https?:\/\//i', $fullUrl)) {
            $parts = parse_url($fullUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                return (string) ($parts['scheme'] ?? 'https') . '://' . (string) $parts['host'] . $port;
            }
        }

        $scheme = (string) ($_SERVER['REQUEST_SCHEME'] ?? '');
        if ($scheme === '') {
            $https = (string) ($_SERVER['HTTPS'] ?? '');
            $scheme = $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        if ($host === '') {
            $configured = rtrim((string) ($_SERVER['WELINE_WEBSITE_URL'] ?? w_env('website_url', '') ?: w_env('website.url', '')), '/');
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
     * @return array<int, array{question:string,answer:string}>
     */
    private function normalizeFaqs(mixed $faqs): array
    {
        if (!is_array($faqs)) {
            return [];
        }
        $normalized = [];
        foreach ($faqs as $faq) {
            if (!is_array($faq)) {
                continue;
            }
            $question = trim((string) ($faq['question'] ?? $faq['q'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? $faq['a'] ?? ''));
            if ($question !== '' && $answer !== '') {
                $normalized[] = ['question' => $question, 'answer' => $answer];
            }
        }
        return $normalized;
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
    private function applyHeadContextProviders($template, array $context): array
    {
        if (!$this->providerRegistry) {
            return $context;
        }
        foreach ($this->providerRegistry->getHeadContextProviders() as $provider) {
            try {
                $provided = $provider->provide($template, $context);
                if ($provided !== []) {
                    $context = array_replace_recursive($context, $provided);
                }
            } catch (\Throwable) {
            }
        }
        return $context;
    }
}
