<?php

declare(strict_types=1);

namespace Weline\Theme\Controller;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
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
     * Public URLs shipped by the default Theme. These are fallback routes only:
     * an existing generated frontend route always wins.
     *
     * @return array<string, array{layout_type: string, layout_option: string, title: string}>
     */
    private static function defaultPublicRouteMap(): array
    {
        return [
            'products' => ['layout_type' => 'product_list', 'layout_option' => 'default', 'title' => '产品'],
            'product-list' => ['layout_type' => 'product_list', 'layout_option' => 'default', 'title' => '产品'],
            'categories' => ['layout_type' => 'category', 'layout_option' => 'default', 'title' => '分类'],
            'category' => ['layout_type' => 'category', 'layout_option' => 'default', 'title' => '分类'],
            'product' => ['layout_type' => 'product', 'layout_option' => 'default', 'title' => '商品'],
            'cart' => ['layout_type' => 'cart', 'layout_option' => 'default', 'title' => '购物车'],
            'checkout' => ['layout_type' => 'checkout', 'layout_option' => 'default', 'title' => '结账'],
            'checkout/success' => ['layout_type' => 'checkout_success', 'layout_option' => 'default', 'title' => '下单成功'],
            'checkout/failer' => ['layout_type' => 'checkout_failer', 'layout_option' => 'default', 'title' => '下单失败'],
            'account' => ['layout_type' => 'account', 'layout_option' => 'default', 'title' => '账户'],
            'account/login' => ['layout_type' => 'account_auth', 'layout_option' => 'default', 'title' => '登录'],
            'account/register' => ['layout_type' => 'account_auth', 'layout_option' => 'default', 'title' => '注册'],
            'account/forgot' => ['layout_type' => 'account_auth', 'layout_option' => 'default', 'title' => '找回密码'],
            'account/orders' => ['layout_type' => 'account_orders', 'layout_option' => 'default', 'title' => '订单'],
            'account/profile' => ['layout_type' => 'account_profile', 'layout_option' => 'default', 'title' => '账户资料'],
            'account/logout' => ['layout_type' => 'account_logout', 'layout_option' => 'default', 'title' => '退出登录'],
            'login' => ['layout_type' => 'account_auth', 'layout_option' => 'default', 'title' => '登录'],
            'register' => ['layout_type' => 'account_auth', 'layout_option' => 'default', 'title' => '注册'],
            'contact' => ['layout_type' => 'contact', 'layout_option' => 'default', 'title' => '联系我们'],
            'support' => ['layout_type' => 'contact', 'layout_option' => 'default', 'title' => '支持'],
            'help' => ['layout_type' => 'cms_page', 'layout_option' => 'default', 'title' => '帮助中心'],
            'faq' => ['layout_type' => 'cms_page', 'layout_option' => 'default', 'title' => '常见问题'],
            'about' => ['layout_type' => 'cms_page', 'layout_option' => 'default', 'title' => '关于我们'],
            'solutions' => ['layout_type' => 'cms_page', 'layout_option' => 'default', 'title' => '解决方案'],
            'docs' => ['layout_type' => 'cms_page', 'layout_option' => 'default', 'title' => '文档'],
            'page' => ['layout_type' => 'cms_page', 'layout_option' => 'default', 'title' => '页面'],
            'policy' => ['layout_type' => 'policy', 'layout_option' => 'default', 'title' => '政策'],
            'privacy' => ['layout_type' => 'policy', 'layout_option' => 'privacy', 'title' => '隐私政策'],
            'terms' => ['layout_type' => 'policy', 'layout_option' => 'term-condition', 'title' => '服务条款'],
            'term-condition' => ['layout_type' => 'policy', 'layout_option' => 'term-condition', 'title' => '服务条款'],
            'terms-and-conditions' => ['layout_type' => 'policy', 'layout_option' => 'term-condition', 'title' => '服务条款'],
            'cookie' => ['layout_type' => 'policy', 'layout_option' => 'cookie', 'title' => 'Cookie 政策'],
            'refund' => ['layout_type' => 'policy', 'layout_option' => 'refund', 'title' => '退款政策'],
            'returns' => ['layout_type' => 'policy', 'layout_option' => 'refund', 'title' => '退货政策'],
            'disclaimer' => ['layout_type' => 'policy', 'layout_option' => 'disclaimer', 'title' => '免责声明'],
            'policy/privacy' => ['layout_type' => 'policy', 'layout_option' => 'privacy', 'title' => '隐私政策'],
            'policy/term-condition' => ['layout_type' => 'policy', 'layout_option' => 'term-condition', 'title' => '服务条款'],
            'policy/terms' => ['layout_type' => 'policy', 'layout_option' => 'term-condition', 'title' => '服务条款'],
            'policy/cookie' => ['layout_type' => 'policy', 'layout_option' => 'cookie', 'title' => 'Cookie 政策'],
            'policy/refund' => ['layout_type' => 'policy', 'layout_option' => 'refund', 'title' => '退款政策'],
            'policy/disclaimer' => ['layout_type' => 'policy', 'layout_option' => 'disclaimer', 'title' => '免责声明'],
            'review' => ['layout_type' => 'review', 'layout_option' => 'default', 'title' => '评价'],
            'rma' => ['layout_type' => 'rma', 'layout_option' => 'default', 'title' => '退换货'],
            'activity' => ['layout_type' => 'activity', 'layout_option' => 'default', 'title' => '活动'],
        ];
    }

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

        $layoutType = trim((string)($request?->getParam('page_type', $request?->getParam('layout_type', '')) ?? ''));
        if ($layoutType === '') {
            try {
                /** @var ThemePageTypeResolver $resolver */
                $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);
                $layoutType = $resolver->resolveLayoutTypeFromUri(
                    (string)($request?->getUri() ?? '/'),
                    ''
                );
            } catch (\Throwable) {
                $layoutType = '';
            }
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

        $normalizedPath = self::normalizePublicPath($path);
        if ($normalizedPath === ''
            || str_starts_with($normalizedPath, 'theme/frontend/')
            || self::shouldSkipPreviewRewrite($normalizedPath)
            || self::generatedFrontendRouteExists($normalizedPath, $request)
        ) {
            return;
        }

        $target = self::resolveDefaultPublicTarget($normalizedPath);
        if ($target === null) {
            return;
        }

        self::applyQueryOverrides($request, [
            'page_type' => $target['layout_type'],
            'layout_type' => $target['layout_type'],
            'layout_option' => $target['layout_option'],
            'theme_public_route' => $normalizedPath,
            'theme_page_title' => $target['title'],
        ]);

        $path = 'theme/frontend/policy';
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
     * @return array{layout_type: string, layout_option: string, title: string}|null
     */
    private static function resolveDefaultPublicTarget(string $normalizedPath): ?array
    {
        $routes = self::defaultPublicRouteMap();
        if (isset($routes[$normalizedPath])) {
            return $routes[$normalizedPath];
        }

        if (str_starts_with($normalizedPath, 'product/')) {
            return ['layout_type' => 'product', 'layout_option' => 'default', 'title' => '商品'];
        }
        if (str_starts_with($normalizedPath, 'category/')) {
            return ['layout_type' => 'category', 'layout_option' => 'default', 'title' => '分类'];
        }
        if (str_starts_with($normalizedPath, 'page/')) {
            return ['layout_type' => 'cms_page', 'layout_option' => 'default', 'title' => '页面'];
        }

        return null;
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
