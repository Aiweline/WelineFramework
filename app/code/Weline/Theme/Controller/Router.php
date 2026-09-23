<?php

declare(strict_types=1);

namespace Weline\Theme\Controller;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemePageTypeResolver;

/**
 * Theme route preprocessor.
 *
 * Keep legacy preview entry `?preview_theme=...` compatible by rewriting
 * frontend preview entry requests to Theme preview gateway.
 */
class Router implements RouterInterface
{
    /**
     * Legacy short policy paths → canonical /policy/* (301). Layout_resolve is 1:1;
     * these aliases are redirects, not a revived defaultPublicRouteMap (QA-13).
     *
     * @var array<string, string>
     */
    private const LEGACY_POLICY_REDIRECTS = [
        'privacy' => 'policy/privacy',
        'privacy-policy' => 'policy/privacy',
        'cookie' => 'policy/cookie',
        'cookies' => 'policy/cookie',
        'cookie-policy' => 'policy/cookie',
        'refund' => 'policy/refund',
        'refund-policy' => 'policy/refund',
    ];

    public static function rewritePreviewThemeQuery(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $request = null;
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            if ($request->isBackend()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $themeId = (int)($request?->getParam('preview_theme', 0) ?? 0);
        if ($themeId <= 0) {
            return;
        }

        $normalizedPath = trim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($normalizedPath, 'theme/frontend/theme-preview')) {
            return;
        }

        if (self::shouldSkipPreviewRewrite($normalizedPath)) {
            return;
        }

        // Prefer layout inferred from the public path being rewritten (product/{slug}, …).
        // Query page_type/layout_type may be stale when navigating between preview pages.
        $layoutType = '';
        try {
            /** @var ThemePageTypeResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);
            if ($normalizedPath !== '') {
                $layoutType = $resolver->resolveLayoutTypeFromUri('/' . $normalizedPath, '');
            }
            if ($layoutType === '') {
                $layoutType = $resolver->resolveLayoutTypeFromUri(
                    (string)($request?->getUri() ?? '/'),
                    ''
                );
            }
        } catch (\Throwable) {
            $layoutType = '';
        }
        if ($layoutType === '') {
            $layoutType = trim((string)($request?->getParam('page_type', $request?->getParam('layout_type', '')) ?? ''));
        }
        if ($layoutType === '') {
            if (self::isThemePreviewEntryPath($normalizedPath)) {
                $layoutType = 'homepage';
            } else {
                return;
            }
        }

        $editorArea = strtolower(trim((string)($request?->getParam('editor_area', $request?->getParam('preview_area', 'frontend')) ?? 'frontend')));
        if ($editorArea !== PreviewContextService::AREA_BACKEND) {
            $editorArea = PreviewContextService::AREA_FRONTEND;
        }

        $queryOverrides = [
            'editor_area' => $editorArea,
            'page_type' => $layoutType,
        ];

        if ($editorArea === PreviewContextService::AREA_BACKEND) {
            // Legacy preview_theme is an explicit theme choice and must win over
            // any stale preview context that may already exist in the worker.
            $queryOverrides['backend_theme_id'] = $themeId;
        } else {
            $queryOverrides['frontend_theme_id'] = $themeId;
        }

        if ((string)($request?->getParam('layout_type', '') ?? '') === '') {
            $queryOverrides['layout_type'] = $layoutType;
        }
        if ((string)($request?->getParam('preview_mode', '') ?? '') === '') {
            $queryOverrides['preview_mode'] = PreviewContextService::DEFAULT_PREVIEW_MODE;
        }
        if ((string)($request?->getParam('status', '') ?? '') === '') {
            $queryOverrides['status'] = PreviewContextService::DEFAULT_STATUS;
        }
        if ((string)($request?->getParam('shell', '') ?? '') === '') {
            $queryOverrides['shell'] = PreviewContextService::SHELL_PREVIEW;
        }
        if ((string)($request?->getParam('target_type', '') ?? '') === '') {
            $queryOverrides['target_type'] = PreviewContextService::TARGET_TYPE_LAYOUT;
        }
        if ((string)($request?->getParam('target_value', '') ?? '') === '') {
            $queryOverrides['target_value'] = $layoutType;
        }

        self::applyQueryOverrides($request, $queryOverrides);

        $path = 'theme/frontend/theme-preview/gateway';
    }

    public static function rewriteDefaultThemePublicPage(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            if ($request->isBackend()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        // PageBuilder / AI-site storefronts own pretty paths like /about and /contact.
        // Theme's shell fallback must not steal them into cms_page/contact layouts.
        if (self::isPageBuilderOwnedCurrentWebsite()) {
            return;
        }

        $normalizedPath = self::normalizePublicPath($path);

        if (isset(self::LEGACY_POLICY_REDIRECTS[$normalizedPath])) {
            $rule['module'] = 'Weline_Theme';
            throw new ResponseTerminateException(301, '', [
                'Location' => self::buildLegacyPolicyLocation(self::LEGACY_POLICY_REDIRECTS[$normalizedPath]),
            ]);
        }

        if ($normalizedPath === ''
            || str_starts_with($normalizedPath, 'theme/frontend/')
            || self::shouldSkipPreviewRewrite($normalizedPath)
            || self::isInstalledModuleOwnedPublicRoute($normalizedPath)
            || self::generatedFrontendRouteExists($normalizedPath, $request)
        ) {
            if ($normalizedPath === 'search' && class_exists('Weline\\Search\\Controller\\Router')) {
                \Weline\Search\Controller\Router::process($path, $rule);
            }
            if ($normalizedPath === 'compare' && class_exists('Weline\\Compare\\Controller\\Router')) {
                \Weline\Compare\Controller\Router::process($path, $rule);
            }
            if (
                (
                    $normalizedPath === 'product'
                    || str_starts_with($normalizedPath, 'product/')
                    || in_array($normalizedPath, ['products', 'category', 'categories'], true)
                    || str_starts_with($normalizedPath, 'category/')
                )
                && class_exists('Weline\\Product\\Controller\\Router')
            ) {
                \Weline\Product\Controller\Router::process($path, $rule);
            }
            if (class_exists('Weline\\Blog\\Controller\\Router')) {
                \Weline\Blog\Controller\Router::process($path, $rule);
            }
            if (class_exists('Weline\\Payment\\Controller\\Router')) {
                \Weline\Payment\Controller\Router::process($path, $rule);
            }
            if (class_exists('Weline\\Customer\\Controller\\Router')) {
                \Weline\Customer\Controller\Router::process($path, $rule);
            }
            if (class_exists('Weline\\Order\\Controller\\Router')) {
                \Weline\Order\Controller\Router::process($path, $rule);
            }
            if (class_exists('Weline\\Promotion\\Controller\\Router')) {
                \Weline\Promotion\Controller\Router::process($path, $rule);
            }
            return;
        }

        if (class_exists('Weline\\Customer\\Controller\\Router')) {
            \Weline\Customer\Controller\Router::process($path, $rule);
            if (!empty($rule['module'])) {
                return;
            }
        }

        if (class_exists('Weline\\Order\\Controller\\Router')) {
            \Weline\Order\Controller\Router::process($path, $rule);
            if (!empty($rule['module'])) {
                return;
            }
        }

        // Reverse-infer layout/option from public path via layout_resolve (no alias table).
        // Slug entity routes stay on module controllers; only fixed Theme shells land on Policy.
        try {
            /** @var \Weline\Theme\Service\LayoutResolveService $layoutResolve */
            $layoutResolve = ObjectManager::getInstance(\Weline\Theme\Service\LayoutResolveService::class);
            $resolved = $layoutResolve->resolveFromPath($normalizedPath);
        } catch (\Throwable) {
            return;
        }
        if (!(bool)($resolved['claimed'] ?? false)
            || (string)($resolved['layout_path'] ?? '') === ''
            || trim((string)($resolved['entity_slug'] ?? '')) !== ''
        ) {
            return;
        }

        $layoutOption = (string)($resolved['layout_option'] ?? 'default');
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }

        self::applyQueryOverrides($request, [
            'page_type' => (string)$resolved['layout_path'],
            'layout_type' => (string)$resolved['layout_path'],
            'layout_option' => $layoutOption,
        ]);

        $path = 'theme/frontend/policy';
    }

    /**
     * Non-PageBuilder websites: Theme shells claimed by layout_resolve with no entity slug.
     * Stale UrlManager pagebuilder rewrites must not steal those paths.
     */
    public static function prefersShellPublicAlias(string $path): bool
    {
        if (self::isPageBuilderOwnedCurrentWebsite()) {
            return false;
        }

        try {
            /** @var \Weline\Theme\Service\LayoutResolveService $layoutResolve */
            $layoutResolve = ObjectManager::getInstance(\Weline\Theme\Service\LayoutResolveService::class);
            $resolved = $layoutResolve->resolveFromPath(self::normalizePublicPath($path));
        } catch (\Throwable) {
            return false;
        }

        return (bool)($resolved['claimed'] ?? false)
            && (string)($resolved['layout_path'] ?? '') !== ''
            && trim((string)($resolved['entity_slug'] ?? '')) === '';
    }

    /**
     * Optional Websites module: page_builder / pagebuilder_ai_site scopes are owned by PageBuilder.
     */
    private static function isPageBuilderOwnedCurrentWebsite(): bool
    {
        if (!\class_exists(\Weline\Websites\Model\Website::class)) {
            return false;
        }

        $websiteId = 0;
        try {
            $websiteId = (int)(
                \Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_ID', '')
                ?: \Weline\Framework\Env\WelineEnv::get('website_id', 0)
            );
        } catch (\Throwable) {
            $websiteId = (int)($_SERVER['WELINE_WEBSITE_ID'] ?? 0);
        }
        if ($websiteId <= 0) {
            return false;
        }

        try {
            /** @var \Weline\Websites\Model\Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $website = clone $websiteModel;
            $website->clearData()->clearQuery()->load($websiteId);
            if (!$website->getId()) {
                return false;
            }

            return \in_array(
                (string)$website->getData(\Weline\Websites\Model\Website::schema_fields_SCOPE),
                ['page_builder', 'pagebuilder_ai_site'],
                true
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private static function applyQueryOverrides(?Request $request, array $queryOverrides): void
    {
        foreach ($queryOverrides as $key => $value) {
            if ($request) {
                $request->setGet($key, $value);
            }
        }

        if ($request) {
            $request->setData('params', $request->getParameterBag()->all());
        }
    }

    private static function shouldSkipPreviewRewrite(string $normalizedPath): bool
    {
        if ($normalizedPath === '') {
            return false;
        }

        if (str_contains($normalizedPath, '.')) {
            return true;
        }

        $staticOrApiPrefixes = [
            'static',
            'pub/static',
            'pub/media',
            'media',
            'uploads',
            'api',
            'rest',
            'graphql',
        ];

        foreach ($staticOrApiPrefixes as $prefix) {
            if ($normalizedPath === $prefix || str_starts_with($normalizedPath, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePublicPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        if (str_contains($path, '://')) {
            $path = (string)(parse_url($path, PHP_URL_PATH) ?: '');
        }
        if (str_contains($path, '?')) {
            $path = explode('?', $path, 2)[0];
        }

        return strtolower(trim($path, '/'));
    }

    /**
     * Installed business modules own their public aliases. Theme routes are a
     * shell-only fallback and must not consume those aliases before the
     * module router gets a chance to resolve them.
     */
    private static function isInstalledModuleOwnedPublicRoute(string $normalizedPath): bool
    {
        if (class_exists('Weline\\Product\\Controller\\Router')) {
            if (in_array($normalizedPath, ['products', 'category', 'categories'], true)) {
                return true;
            }
            if (preg_match('#^category/.+#D', $normalizedPath) === 1) {
                return true;
            }
            // Match Weline_Product\\Controller\\Router: numeric id and kebab slug detail URLs.
            if (preg_match('#^product/[1-9][0-9]*$#D', $normalizedPath) === 1) {
                return true;
            }
            if (preg_match('#^product/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $normalizedPath) === 1) {
                return true;
            }
            // Bare /product always belongs to Product (Detail redirects when identity missing).
            // Theme must not map it to the product layout Policy shell.
            if ($normalizedPath === 'product') {
                return true;
            }
        }

        if (class_exists('Weline\\Search\\Controller\\Router') && $normalizedPath === 'search') {
            return true;
        }

        if (class_exists('Weline\\Compare\\Controller\\Router') && $normalizedPath === 'compare') {
            return true;
        }

        if (class_exists('Weline\\Blog\\Controller\\Router')) {
            if ($normalizedPath === 'blog' || $normalizedPath === 'blog/category') {
                return true;
            }
            if (preg_match('#^blog/category/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $normalizedPath) === 1) {
                return true;
            }
            if (preg_match('#^blog/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $normalizedPath) === 1) {
                return true;
            }
        }

        if (class_exists('Weline\\Payment\\Controller\\Router')) {
            if ($normalizedPath === 'guide/payment') {
                return true;
            }
            if (preg_match('#^guide/payment/([a-z0-9][a-z0-9_.-]*)/policy$#D', $normalizedPath) === 1) {
                return true;
            }
            if (preg_match('#^guide/payment/([a-z0-9][a-z0-9_.-]*)/agreement$#D', $normalizedPath) === 1) {
                return true;
            }
            if (preg_match('#^guide/payment/([a-z0-9][a-z0-9_.-]*)$#D', $normalizedPath) === 1) {
                return true;
            }
        }

        if (class_exists('Weline\\Customer\\Controller\\Router')) {
            if ($normalizedPath === 'customer/account/create') {
                return true;
            }
            if ($normalizedPath === 'guide/social-login') {
                return true;
            }
            if (preg_match('#^guide/social-login/([a-z0-9][a-z0-9_.-]*)/policy$#D', $normalizedPath) === 1) {
                return true;
            }
            if (preg_match('#^guide/social-login/([a-z0-9][a-z0-9_.-]*)$#D', $normalizedPath) === 1) {
                return true;
            }
        }

        if (class_exists('Weline\\Shipping\\Controller\\Router')) {
            if (in_array($normalizedPath, ['guide/shipping', 'guide/returns'], true)) {
                return true;
            }
        }

        if (class_exists('Weline\\Promotion\\Controller\\Router')) {
            if ($normalizedPath === 'promotion') {
                return true;
            }
            if (preg_match('#^promotion/([a-z0-9_-]+)$#D', $normalizedPath) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function buildLegacyPolicyLocation(string $canonicalRelative): string
    {
        $canonicalRelative = trim(str_replace('\\', '/', $canonicalRelative), '/');
        try {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            $built = (string)$url->getUrl($canonicalRelative);
            if ($built !== '') {
                return $built;
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $base = rtrim((string)$request->getOriginBaseUrl(), '/');
            if ($base !== '') {
                return $base . '/' . $canonicalRelative;
            }
        } catch (\Throwable) {
            // keep relative fallback
        }

        return '/' . $canonicalRelative;
    }

    private static function generatedFrontendRouteExists(string $normalizedPath, Request $request): bool
    {
        $routePath = self::normalizeGeneratedRoutePath($normalizedPath);
        if ($routePath === '') {
            return true;
        }

        if (!is_file(Env::path_FRONTEND_PC_ROUTER_FILE)) {
            return false;
        }

        try {
            $routes = include Env::path_FRONTEND_PC_ROUTER_FILE;
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($routes)) {
            return false;
        }

        $method = strtoupper((string)$request->getMethod());
        $candidates = [
            $routePath,
            $routePath . '::' . $method,
        ];
        if ($method === 'HEAD') {
            $candidates[] = $routePath . '::GET';
        }

        foreach ($candidates as $candidate) {
            if (isset($routes[$candidate])) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeGeneratedRoutePath(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }
            $segment = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $segment) ?? $segment;
            $segment = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $segment) ?? $segment;
            $segments[$index] = strtolower($segment);
        }

        return implode('/', $segments);
    }

    private static function isThemePreviewEntryPath(string $normalizedPath): bool
    {
        return $normalizedPath === '' || $normalizedPath === 'index' || $normalizedPath === 'index/index';
    }

    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        self::rewritePreviewThemeQuery($path, $rule);
        self::rewriteDefaultThemePublicPage($path, $rule);
    }
}
