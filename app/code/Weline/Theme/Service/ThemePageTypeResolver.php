<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use ReflectionObject;
use Weline\Framework\App\State;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
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
        ThemeLayout::PAGE_TYPE_DASHBOARD => ThemeLayout::PAGE_TYPE_DASHBOARD,
        'account_auth' => ThemeLayout::PAGE_TYPE_ACCOUNT,
        'account.auth' => ThemeLayout::PAGE_TYPE_ACCOUNT,
        'account.challenge' => ThemeLayout::PAGE_TYPE_ACCOUNT,
        'account_profile' => ThemeLayout::PAGE_TYPE_ACCOUNT,
        'account_orders' => ThemeLayout::PAGE_TYPE_ACCOUNT,
        'account_logout' => ThemeLayout::PAGE_TYPE_ACCOUNT,
        ThemeLayout::PAGE_TYPE_SEARCH => ThemeLayout::PAGE_TYPE_SEARCH,
        ThemeLayout::PAGE_TYPE_BLOG => ThemeLayout::PAGE_TYPE_BLOG,
        ThemeLayout::PAGE_TYPE_BLOG_CATEGORY => ThemeLayout::PAGE_TYPE_BLOG_CATEGORY,
        ThemeLayout::PAGE_TYPE_PROMOTION => ThemeLayout::PAGE_TYPE_PROMOTION,
        ThemeLayout::PAGE_TYPE_ACTIVITY => ThemeLayout::PAGE_TYPE_ACTIVITY,
        ThemeLayout::PAGE_TYPE_CHECKOUT_SUCCESS => ThemeLayout::PAGE_TYPE_CHECKOUT_SUCCESS,
        ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE => ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE,
        'checkout_failer' => ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE,
        ThemeLayout::PAGE_TYPE_FAQ => ThemeLayout::PAGE_TYPE_FAQ,
        ThemeLayout::PAGE_TYPE_PAYMENT_GUIDE => ThemeLayout::PAGE_TYPE_PAYMENT_GUIDE,
        ThemeLayout::PAGE_TYPE_GUIDE => ThemeLayout::PAGE_TYPE_GUIDE,
        ThemeLayout::PAGE_TYPE_ABOUT => ThemeLayout::PAGE_TYPE_ABOUT,
        ThemeLayout::PAGE_TYPE_CONTACT => ThemeLayout::PAGE_TYPE_CONTACT,
        'customer_service' => ThemeLayout::PAGE_TYPE_CONTACT,
        ThemeLayout::PAGE_TYPE_REVIEW => ThemeLayout::PAGE_TYPE_REVIEW,
        ThemeLayout::PAGE_TYPE_QA => ThemeLayout::PAGE_TYPE_QA,
        ThemeLayout::PAGE_TYPE_RMA => ThemeLayout::PAGE_TYPE_RMA,
        ThemeLayout::PAGE_TYPE_POLICY => ThemeLayout::PAGE_TYPE_POLICY,
        ThemeLayout::PAGE_TYPE_TERMS => ThemeLayout::PAGE_TYPE_TERMS,
        ThemeLayout::PAGE_TYPE_NOT_FOUND => ThemeLayout::PAGE_TYPE_NOT_FOUND,
        ThemeLayout::PAGE_TYPE_DEFAULT => ThemeLayout::PAGE_TYPE_DEFAULT,
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
        ThemeLayout::PAGE_TYPE_BLOG => 'blog',
        ThemeLayout::PAGE_TYPE_BLOG_CATEGORY => 'blog',
        ThemeLayout::PAGE_TYPE_PROMOTION => 'promotion',
        ThemeLayout::PAGE_TYPE_ACTIVITY => 'activity',
        ThemeLayout::PAGE_TYPE_CHECKOUT_SUCCESS => 'checkout/success',
        ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE => 'theme/frontend/theme-preview/content',
        ThemeLayout::PAGE_TYPE_FAQ => 'faq',
        ThemeLayout::PAGE_TYPE_PAYMENT_GUIDE => 'guide/payment',
        ThemeLayout::PAGE_TYPE_GUIDE => 'guide/shipping',
        ThemeLayout::PAGE_TYPE_ABOUT => 'about',
        ThemeLayout::PAGE_TYPE_CONTACT => 'contact',
        ThemeLayout::PAGE_TYPE_REVIEW => 'review',
        ThemeLayout::PAGE_TYPE_QA => 'qa',
        ThemeLayout::PAGE_TYPE_RMA => 'rma',
        ThemeLayout::PAGE_TYPE_POLICY => 'policy',
        ThemeLayout::PAGE_TYPE_TERMS => 'terms',
        ThemeLayout::PAGE_TYPE_NOT_FOUND => 'theme/frontend/theme-preview/content',
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
        if ($contains('blog') && $contains('category')) {
            return ThemeLayout::PAGE_TYPE_BLOG_CATEGORY;
        }
        if ($contains('blog') && ($contains('index') || $contains('frontend_index'))) {
            return ThemeLayout::PAGE_TYPE_BLOG_CATEGORY;
        }
        if ($contains('blog')) {
            return ThemeLayout::PAGE_TYPE_BLOG;
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
            return ThemeLayout::PAGE_TYPE_CHECKOUT_SUCCESS;
        }
        if ($contains('checkout') && ($contains('failure') || $contains('failer'))) {
            return ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE;
        }
        if ($contains('checkout')) {
            return ThemeLayout::PAGE_TYPE_CHECKOUT;
        }
        if ($contains('cart')) {
            return ThemeLayout::PAGE_TYPE_CART;
        }
        if ($contains('account') && $contains('challenge')) {
            return 'account.challenge';
        }
        if ($contains('account') && ($contains('login') || $contains('register') || $contains('forgotpassword'))) {
            return 'account.auth';
        }
        if ($contains('account')) {
            return ThemeLayout::PAGE_TYPE_ACCOUNT;
        }
        if ($contains('dashboard')) {
            return ThemeLayout::PAGE_TYPE_DASHBOARD;
        }
        if ($contains('customerservice')) {
            return 'customer_service';
        }
        if ($contains('payment') && $contains('guide')) {
            return ThemeLayout::PAGE_TYPE_PAYMENT_GUIDE;
        }
        if ($contains('guide')) {
            return ThemeLayout::PAGE_TYPE_GUIDE;
        }
        if ($contains('faq')) {
            return ThemeLayout::PAGE_TYPE_FAQ;
        }
        if ($contains('contact')) {
            return ThemeLayout::PAGE_TYPE_CONTACT;
        }
        if ($contains('about')) {
            return ThemeLayout::PAGE_TYPE_ABOUT;
        }
        if ($contains('promotion')) {
            return ThemeLayout::PAGE_TYPE_PROMOTION;
        }
        if ($contains('activity')) {
            return ThemeLayout::PAGE_TYPE_ACTIVITY;
        }
        if ($contains('review')) {
            return ThemeLayout::PAGE_TYPE_REVIEW;
        }
        if ($contains('qa')) {
            return ThemeLayout::PAGE_TYPE_QA;
        }
        if ($contains('rma')) {
            return ThemeLayout::PAGE_TYPE_RMA;
        }
        if ($contains('terms') || $contains('termcondition')) {
            return ThemeLayout::PAGE_TYPE_TERMS;
        }
        if ($contains('policy') || $contains('privacy')) {
            return ThemeLayout::PAGE_TYPE_POLICY;
        }
        if ($contains('notfound') || $contains('not_found')) {
            return ThemeLayout::PAGE_TYPE_NOT_FOUND;
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
        $path = $this->normalizeStorefrontPath($requestUri);

        if ($path === '' || str_ends_with($path, 'index/index') || $path === 'index') {
            return ThemeLayout::PAGE_TYPE_HOME;
        }
        if ($this->pathMatchesRoute($path, 'search')) {
            return ThemeLayout::PAGE_TYPE_SEARCH;
        }
        if ($path === 'blog' || str_starts_with($path, 'blog/category')) {
            return ThemeLayout::PAGE_TYPE_BLOG_CATEGORY;
        }
        if ($this->pathMatchesRoute($path, 'blog')) {
            return ThemeLayout::PAGE_TYPE_BLOG;
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
            return ThemeLayout::PAGE_TYPE_CHECKOUT_SUCCESS;
        }
        if ($this->pathMatchesRoute($path, 'checkout/failure')) {
            return ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE;
        }
        if ($this->pathMatchesRoute($path, 'checkout/failer')) {
            return 'checkout_failer';
        }
        if ($this->pathMatchesRoute($path, 'checkout')) {
            return ThemeLayout::PAGE_TYPE_CHECKOUT;
        }
        if ($this->pathMatchesRoute($path, 'cart')) {
            return ThemeLayout::PAGE_TYPE_CART;
        }
        if ($this->pathMatchesRoute($path, 'account/challenge')
            || $this->pathMatchesRoute($path, 'customer/account/challenge')
        ) {
            return 'account.challenge';
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
        if ($this->pathMatchesRoute($path, 'dashboard')) {
            return ThemeLayout::PAGE_TYPE_DASHBOARD;
        }
        if ($this->pathMatchesRoute($path, 'orders/track')
            || $this->pathMatchesRoute($path, 'order/track')
            || $this->pathMatchesRoute($path, 'order/tracking')
        ) {
            // Legacy public track URLs redirect into account orders.
            return 'account_orders';
        }
        if ($this->pathMatchesRoute($path, 'faq')) {
            return ThemeLayout::PAGE_TYPE_FAQ;
        }
        if ($this->pathMatchesRoute($path, 'customer/service') || $this->pathMatchesRoute($path, 'customer-service')) {
            return 'customer_service';
        }
        if ($this->pathMatchesRoute($path, 'contact') || $path === 'support' || str_ends_with($path, '/support')) {
            return ThemeLayout::PAGE_TYPE_CONTACT;
        }
        if ($this->pathMatchesRoute($path, 'about')) {
            return ThemeLayout::PAGE_TYPE_ABOUT;
        }
        if ($this->pathMatchesRoute($path, 'promotion')) {
            return ThemeLayout::PAGE_TYPE_PROMOTION;
        }
        if ($this->pathMatchesRoute($path, 'activity')) {
            return ThemeLayout::PAGE_TYPE_ACTIVITY;
        }
        if ($this->pathMatchesRoute($path, 'review')) {
            return ThemeLayout::PAGE_TYPE_REVIEW;
        }
        if ($this->pathMatchesRoute($path, 'qa')) {
            return ThemeLayout::PAGE_TYPE_QA;
        }
        if ($this->pathMatchesRoute($path, 'rma')) {
            return ThemeLayout::PAGE_TYPE_RMA;
        }
        if ($this->pathMatchesRoute($path, 'guide/payment') || $this->pathMatchesRoute($path, 'payment-guide')) {
            return ThemeLayout::PAGE_TYPE_PAYMENT_GUIDE;
        }
        if ($this->pathMatchesRoute($path, 'guide')) {
            return ThemeLayout::PAGE_TYPE_GUIDE;
        }
        if ($this->pathMatchesRoute($path, 'terms')
            || $this->pathMatchesRoute($path, 'term-condition')
            || $this->pathMatchesRoute($path, 'terms-and-conditions')
            || $this->pathMatchesRoute($path, 'policy/terms')
            || $this->pathMatchesRoute($path, 'policy/term-condition')
        ) {
            return ThemeLayout::PAGE_TYPE_TERMS;
        }
        if ($this->pathMatchesRoute($path, 'policy')
            || $this->pathMatchesRoute($path, 'privacy')
            || $this->pathMatchesRoute($path, 'cookie')
            || $this->pathMatchesRoute($path, 'cookies')
            || $this->pathMatchesRoute($path, 'cookie-policy')
            || $this->pathMatchesRoute($path, 'refund')
            || $this->pathMatchesRoute($path, 'returns')
            || $this->pathMatchesRoute($path, 'disclaimer')
        ) {
            return ThemeLayout::PAGE_TYPE_POLICY;
        }
        if ($this->pathMatchesRoute($path, 'not-found')
            || $this->pathMatchesRoute($path, 'not_found')
            || $this->pathMatchesRoute($path, '404')
        ) {
            return ThemeLayout::PAGE_TYPE_NOT_FOUND;
        }

        return '';
    }

    private function normalizeStorefrontPath(string $requestUri): string
    {
        $path = (string)\parse_url($requestUri, \PHP_URL_PATH);
        $path = '/' . \trim(\str_replace('\\', '/', $path), '/');

        try {
            $path = Url::peelWebsiteMountPathFromRelativePath($path);
        } catch (\Throwable) {
            // A standalone resolver call may not have an initialized Website context.
        }

        $segments = \array_values(\array_filter(
            \explode('/', \trim($path, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));

        try {
            $localized = State::resolveLocalizationFromPathSegments($segments);
            if (isset($localized['remaining']) && \is_array($localized['remaining'])) {
                $segments = \array_values(\array_map('strval', $localized['remaining']));
            }
        } catch (\Throwable) {
            // Keep the unmodified path when localization metadata is unavailable.
        }

        return \strtolower(\implode('/', $segments));
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
