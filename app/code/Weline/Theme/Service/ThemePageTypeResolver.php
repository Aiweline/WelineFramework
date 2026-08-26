<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use ReflectionObject;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;

final class ThemePageTypeResolver
{
    private const LAYOUT_TO_PAGE_TYPE = [
        ThemeLayout::PAGE_TYPE_HOME => ThemeLayout::PAGE_TYPE_HOME,
        ThemeLayout::PAGE_TYPE_CATEGORY => ThemeLayout::PAGE_TYPE_CATEGORY,
        ThemeLayout::PAGE_TYPE_PRODUCT => ThemeLayout::PAGE_TYPE_PRODUCT,
        ThemeLayout::PAGE_TYPE_PRODUCT_LIST => ThemeLayout::PAGE_TYPE_PRODUCT_LIST,
        'cms' => ThemeLayout::PAGE_TYPE_CMS,
        ThemeLayout::PAGE_TYPE_CMS => ThemeLayout::PAGE_TYPE_CMS,
        ThemeLayout::PAGE_TYPE_CART => ThemeLayout::PAGE_TYPE_CART,
        ThemeLayout::PAGE_TYPE_CHECKOUT => ThemeLayout::PAGE_TYPE_CHECKOUT,
        ThemeLayout::PAGE_TYPE_ACCOUNT => ThemeLayout::PAGE_TYPE_ACCOUNT,
        'account_auth' => ThemeLayout::PAGE_TYPE_ACCOUNT,
        'account_profile' => ThemeLayout::PAGE_TYPE_ACCOUNT,
        'account_orders' => ThemeLayout::PAGE_TYPE_ACCOUNT,
        'account_logout' => ThemeLayout::PAGE_TYPE_ACCOUNT,
        ThemeLayout::PAGE_TYPE_SEARCH => ThemeLayout::PAGE_TYPE_SEARCH,
        ThemeLayout::PAGE_TYPE_DEFAULT => ThemeLayout::PAGE_TYPE_DEFAULT,
        'checkout_success' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'checkout_failer' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'customer_service' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'help' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'order_tracking' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'contact' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'promotion' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'review' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'qa' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'rma' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'activity' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'policy' => ThemeLayout::PAGE_TYPE_DEFAULT,
    ];

    private const PREVIEW_ROUTE_BY_PAGE_TYPE = [
        ThemeLayout::PAGE_TYPE_HOME => 'index/index',
        ThemeLayout::PAGE_TYPE_CATEGORY => 'theme/frontend/theme-preview/content',
        ThemeLayout::PAGE_TYPE_PRODUCT => 'theme/frontend/theme-preview/content',
        ThemeLayout::PAGE_TYPE_PRODUCT_LIST => 'theme/frontend/theme-preview/content',
        ThemeLayout::PAGE_TYPE_CMS => 'page/default',
        ThemeLayout::PAGE_TYPE_CART => 'cart',
        ThemeLayout::PAGE_TYPE_CHECKOUT => 'checkout',
        ThemeLayout::PAGE_TYPE_ACCOUNT => 'account',
        ThemeLayout::PAGE_TYPE_SEARCH => 'search',
        ThemeLayout::PAGE_TYPE_DEFAULT => 'index/index',
    ];

    public function extractBaseLayoutType(?string $layoutType): string
    {
        $layoutType = trim((string)$layoutType);
        if ($layoutType === '') {
            return '';
        }

        $parts = explode('.', $layoutType, 2);
        return trim($parts[0]);
    }

    public function resolveLayoutType(
        ?string $layoutType = null,
        mixed $controller = null,
        ?Request $request = null,
        string $default = ThemeLayout::PAGE_TYPE_DEFAULT
    ): string {
        $resolved = $this->extractBaseLayoutType($layoutType);
        if ($resolved !== '') {
            return $resolved;
        }

        $resolved = $this->detectLayoutTypeFromController($controller);
        if ($resolved !== '') {
            return $resolved;
        }

        $resolved = $this->detectLayoutTypeFromRequest($request);
        if ($resolved !== '') {
            return $resolved;
        }

        return $default;
    }

    public function resolvePageType(
        ?string $layoutType = null,
        mixed $controller = null,
        ?Request $request = null,
        string $default = ThemeLayout::PAGE_TYPE_DEFAULT
    ): string {
        $resolvedLayoutType = $this->resolveLayoutType($layoutType, $controller, $request, $default);
        return $this->mapLayoutTypeToPageType($resolvedLayoutType);
    }

    public function mapLayoutTypeToPageType(?string $layoutType): string
    {
        $baseLayoutType = $this->extractBaseLayoutType($layoutType);
        if ($baseLayoutType === '') {
            return ThemeLayout::PAGE_TYPE_DEFAULT;
        }

        return self::LAYOUT_TO_PAGE_TYPE[$baseLayoutType] ?? $baseLayoutType;
    }

    public function getPreviewRouteByPageType(?string $pageType): string
    {
        $pageType = trim((string)$pageType);
        if ($pageType === '') {
            $pageType = ThemeLayout::PAGE_TYPE_DEFAULT;
        }

        return self::PREVIEW_ROUTE_BY_PAGE_TYPE[$pageType] ?? self::PREVIEW_ROUTE_BY_PAGE_TYPE[ThemeLayout::PAGE_TYPE_DEFAULT];
    }

    public function getPreviewPathByPageType(?string $pageType): string
    {
        return '/' . ltrim($this->getPreviewRouteByPageType($pageType), '/');
    }

    public function resolveLayoutTypeFromUri(string $requestUri, string $default = ThemeLayout::PAGE_TYPE_DEFAULT): string
    {
        $resolved = $this->detectLayoutTypeFromUri($requestUri);
        return $resolved !== '' ? $resolved : $default;
    }

    public function resolvePageTypeFromUri(string $requestUri, string $default = ThemeLayout::PAGE_TYPE_DEFAULT): string
    {
        return $this->mapLayoutTypeToPageType($this->resolveLayoutTypeFromUri($requestUri, $default));
    }

    private function detectLayoutTypeFromController(mixed $controller): string
    {
        if (!is_object($controller)) {
            return '';
        }

        try {
            $reflection = new ReflectionObject($controller);
            if ($reflection->hasProperty('layoutType')) {
                $property = $reflection->getProperty('layoutType');
                $property->setAccessible(true);
                $resolved = $this->extractBaseLayoutType((string)$property->getValue($controller));
                if ($resolved !== '') {
                    return $resolved;
                }
            }
        } catch (\Throwable) {
        }

        return $this->detectLayoutTypeFromClassName(get_class($controller));
    }

    private function detectLayoutTypeFromRequest(?Request $request): string
    {
        $request ??= $this->getRequest();
        if (!$request) {
            return '';
        }

        $requestLayoutType = $this->extractBaseLayoutType((string)$request->getParam('layout_type', ''));
        if ($requestLayoutType !== '') {
            return $requestLayoutType;
        }

        foreach (['class/full_class_name', 'class/name', 'class/controller_name'] as $routerKey) {
            $routerClass = (string)$request->getRouterData($routerKey);
            $resolved = $this->detectLayoutTypeFromClassName($routerClass);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        $requestUri = (string) (\w_env('request.uri', '') ?? '');
        return $this->detectLayoutTypeFromUri($requestUri);
    }

    private function detectLayoutTypeFromClassName(string $className): string
    {
        $normalized = strtolower(str_replace(['\\', '/'], '_', $className));
        if ($normalized === '') {
            return '';
        }

        $contains = static fn(string $needle): bool => str_contains($normalized, strtolower($needle));

        if ($contains('search') && $contains('frontend')) {
            return ThemeLayout::PAGE_TYPE_SEARCH;
        }
        if ($contains('category')) {
            return ThemeLayout::PAGE_TYPE_CATEGORY;
        }
        if ($contains('product') && ($contains('productlist') || $contains('product_list'))) {
            return ThemeLayout::PAGE_TYPE_PRODUCT_LIST;
        }
        // Catalog listing must win over generic product detail inference.
        if ($contains('product') && $contains('catalog')) {
            return ThemeLayout::PAGE_TYPE_PRODUCT_LIST;
        }
        if ($contains('product')) {
            return ThemeLayout::PAGE_TYPE_PRODUCT;
        }
        if ($contains('checkout') && $contains('success')) {
            return 'checkout_success';
        }
        if ($contains('checkout')) {
            return ThemeLayout::PAGE_TYPE_CHECKOUT;
        }
        if ($contains('cart')) {
            return ThemeLayout::PAGE_TYPE_CART;
        }
        if ($contains('account') && ($contains('login') || $contains('register') || $contains('forgotpassword'))) {
            return 'account.auth';
        }
        if ($contains('account')) {
            return ThemeLayout::PAGE_TYPE_ACCOUNT;
        }
        if ($contains('customerservice')) {
            return 'customer_service';
        }
        if ($contains('promotion')) {
            return 'promotion';
        }
        if ($contains('review')) {
            return 'review';
        }
        if ($contains('qa')) {
            return 'qa';
        }
        if ($contains('rma')) {
            return 'rma';
        }
        if ($contains('cms') || $contains('page_view')) {
            return 'cms';
        }
        if ($contains('frontend_index')) {
            return ThemeLayout::PAGE_TYPE_HOME;
        }

        return '';
    }

    private function detectLayoutTypeFromUri(string $requestUri): string
    {
        $path = strtolower((string)parse_url($requestUri, PHP_URL_PATH));
        $path = trim($path, '/');

        if ($path === '' || str_ends_with($path, 'index/index') || $path === 'index') {
            return ThemeLayout::PAGE_TYPE_HOME;
        }
        if ($this->pathMatchesRoute($path, 'search')) {
            return ThemeLayout::PAGE_TYPE_SEARCH;
        }
        // products before product — avoid matching product-list prefix incorrectly.
        if ($this->pathMatchesRoute($path, 'products') || $this->pathMatchesRoute($path, 'product-list')) {
            return ThemeLayout::PAGE_TYPE_PRODUCT_LIST;
        }
        if ($this->pathMatchesRoute($path, 'product')) {
            return ThemeLayout::PAGE_TYPE_PRODUCT;
        }
        if ($this->pathMatchesRoute($path, 'category') || $this->pathMatchesRoute($path, 'categories')) {
            return ThemeLayout::PAGE_TYPE_CATEGORY;
        }
        if ($this->pathMatchesRoute($path, 'page')) {
            return 'cms';
        }
        if ($this->pathMatchesRoute($path, 'checkout/success')) {
            return 'checkout_success';
        }
        if ($this->pathMatchesRoute($path, 'checkout')) {
            return ThemeLayout::PAGE_TYPE_CHECKOUT;
        }
        if ($this->pathMatchesRoute($path, 'cart')) {
            return ThemeLayout::PAGE_TYPE_CART;
        }
        if ($this->pathMatchesRoute($path, 'account/login')
            || $this->pathMatchesRoute($path, 'account/register')
            || $this->pathMatchesRoute($path, 'account/forgot')
            || $this->pathMatchesRoute($path, 'account/forgot-password')
            || $this->pathMatchesRoute($path, 'customer/account/login')
            || $this->pathMatchesRoute($path, 'customer/account/register')
            || $this->pathMatchesRoute($path, 'customer/account/forgot')
            || $this->pathMatchesRoute($path, 'customer/account/forgot-password')
        ) {
            return 'account.auth';
        }
        if ($this->pathMatchesRoute($path, 'account') || $this->pathMatchesRoute($path, 'customer/account')) {
            return ThemeLayout::PAGE_TYPE_ACCOUNT;
        }
        if ($this->pathMatchesRoute($path, 'orders/track')
            || $this->pathMatchesRoute($path, 'order/track')
            || $this->pathMatchesRoute($path, 'order/tracking')
        ) {
            return 'order_tracking';
        }
        if ($this->pathMatchesRoute($path, 'help') || $this->pathMatchesRoute($path, 'faq')) {
            return 'help';
        }
        if ($this->pathMatchesRoute($path, 'customer/service') || $this->pathMatchesRoute($path, 'customer-service')) {
            return 'customer_service';
        }
        if ($this->pathMatchesRoute($path, 'contact') || $path === 'support' || str_ends_with($path, '/support')) {
            return 'contact';
        }
        if ($this->pathMatchesRoute($path, 'promotion')) {
            return 'promotion';
        }
        if ($this->pathMatchesRoute($path, 'review')) {
            return 'review';
        }
        if ($this->pathMatchesRoute($path, 'qa')) {
            return 'qa';
        }
        if ($this->pathMatchesRoute($path, 'rma')) {
            return 'rma';
        }

        return '';
    }

    /**
     * Match a route after leading/trailing slashes were trimmed.
     * Bare paths like product/{slug} must not require a leading "/product/".
     */
    private function pathMatchesRoute(string $normalizedPath, string $route): bool
    {
        $route = strtolower(trim($route, '/'));
        if ($route === '' || $normalizedPath === '') {
            return false;
        }

        return $normalizedPath === $route
            || str_starts_with($normalizedPath, $route . '/')
            || str_contains($normalizedPath, '/' . $route . '/')
            || str_ends_with($normalizedPath, '/' . $route);
    }

    private function getRequest(): ?Request
    {
        try {
            return ObjectManager::getInstance(Request::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
