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
        'promotion' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'review' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'qa' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'rma' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'activity' => ThemeLayout::PAGE_TYPE_DEFAULT,
        'policy' => ThemeLayout::PAGE_TYPE_DEFAULT,
    ];

    private const PREVIEW_ROUTE_BY_PAGE_TYPE = [
        ThemeLayout::PAGE_TYPE_HOME => 'index/index',
        ThemeLayout::PAGE_TYPE_CATEGORY => 'category/default',
        ThemeLayout::PAGE_TYPE_PRODUCT => 'product/default',
        ThemeLayout::PAGE_TYPE_PRODUCT_LIST => 'products',
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
        if (str_contains($path, '/search')) {
            return ThemeLayout::PAGE_TYPE_SEARCH;
        }
        if (str_contains($path, '/products')) {
            return ThemeLayout::PAGE_TYPE_PRODUCT_LIST;
        }
        if (str_contains($path, '/product/')) {
            return ThemeLayout::PAGE_TYPE_PRODUCT;
        }
        if (str_contains($path, '/category/')) {
            return ThemeLayout::PAGE_TYPE_CATEGORY;
        }
        if (str_contains($path, '/page/')) {
            return 'cms';
        }
        if (str_contains($path, '/checkout/success')) {
            return 'checkout_success';
        }
        if (str_contains($path, '/checkout')) {
            return ThemeLayout::PAGE_TYPE_CHECKOUT;
        }
        if (str_contains($path, '/cart')) {
            return ThemeLayout::PAGE_TYPE_CART;
        }
        if (str_contains($path, '/account/login') || str_contains($path, '/account/register') || str_contains($path, '/account/forgot')) {
            return 'account.auth';
        }
        if (str_contains($path, '/account')) {
            return ThemeLayout::PAGE_TYPE_ACCOUNT;
        }
        if (str_contains($path, '/customer') && str_contains($path, '/service')) {
            return 'customer_service';
        }
        if (str_contains($path, '/promotion')) {
            return 'promotion';
        }
        if (str_contains($path, '/review')) {
            return 'review';
        }
        if (str_contains($path, '/qa')) {
            return 'qa';
        }
        if (str_contains($path, '/rma')) {
            return 'rma';
        }

        return '';
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
