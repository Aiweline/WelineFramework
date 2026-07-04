<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

class PublicApiAuthRouteMatcher
{
    private const AUTH_PATH_PATTERNS = [
        'api/rest/v1/auth/login',
        'api/rest/v1/auth/exchange',
        'api/rest/v1/auth/refresh',
        'api/rest/v1/auth/token-info',
        'api/rest/v1/auth/logout',
        'api/rest/v1/auth/me',
        'api/rest/v1/apps/token',
        'api/rest/v1/apps/refresh',
        'api/rest/v1/apps/revoke',
        'api/rest/v1/backend/auth/login',
        'api/rest/v1/backend/auth/refresh',
        'api/rest/v1/backend/auth/logout',
        'api/rest/v1/backend/auth/me',
        'api/rest/v1/backend/auth/token-info',
        'multipass/rest/v1/identity/authorize',
        'multipass/rest/v1/identity/token',
        'multipass/rest/v1/identity/refresh',
        'multipass/rest/v1/identity/revoke',
        'multipass/rest/v1/identity/userinfo',
        'multipass/rest/v1/identity/bind',
        'api/weshop/rest/v1/auth/token',
        'api/weshop/rest/v1/auth/challenge/verify',
        'api/weshop/rest/v1/auth/login',
        'api/weshop/rest/v1/auth/exchange',
        'api/weshop/rest/v1/auth/refresh',
        'api/weshop/rest/v1/auth/token-info',
        'api/weshop/rest/v1/auth/logout',
        'api/weshop/rest/v1/auth/me',
        'api/rest/v1/weshop/auth/token',
        'api/rest/v1/weshop/auth/challenge/verify',
        'api/rest/v1/weshop/auth/login',
        'api/rest/v1/weshop/auth/exchange',
        'api/rest/v1/weshop/auth/refresh',
        'api/rest/v1/weshop/auth/token-info',
        'api/rest/v1/weshop/auth/logout',
        'api/rest/v1/weshop/auth/me',
        'weshop/rest/v1/auth/token',
        'weshop/rest/v1/auth/challenge/verify',
        'weshop/rest/v1/auth/login',
        'weshop/rest/v1/auth/exchange',
        'weshop/rest/v1/auth/refresh',
        'weshop/rest/v1/auth/token-info',
        'weshop/rest/v1/auth/logout',
        'weshop/rest/v1/auth/me',
    ];

    private const DEMO_PATH_PATTERNS = [
        'dev/tool/rest/v1/trace',
        'dev/tool/rest/v1/panel',
        'dev/tool/rest/v1/panel/session',
        'dev/tool/rest/v1/routes',
        'dev/tool/rest/v1/seo/crawl',
        'dev/tool/rest/v1/document/modules',
        'dev/tool/rest/v1/document/search',
        'dev/tool/rest/v1/document/detail',
        'dev/tool/rest/v1/document/catalogs',
        'datatable/rest/v1/demo-table',
        'datatable/rest/v1/demo-table/data',
        'datatable/rest/v1/demo-table/fields',
        'datatable/rest/v1/demo-table/save-config',
        'datatable/rest/v1/demo-table/clear-config',
        'datatable/rest/v1/demo-table/create',
        'datatable/rest/v1/demo-table/save-data',
        'datatable/rest/v1/demo-table/delete-data',
        'datatable/rest/v1/demo-table/export-data',
        'datatable/rest/v1/demo-table/init-data',
        'datatable/rest/v1/demo-table/clear-data',
        'datatable/rest/v1/demo-form/fields',
        'datatable/rest/v1/demo-form/record',
    ];

    private const GUEST_FRONTEND_PATH_PATTERNS = [
        'visitor/rest/v1/statistics',
        'visitor/rest/v1/analytics',
        'api/rest/v1/weshop/checkout/methods',
        'api/rest/v1/weshop/cart/add',
        'api/rest/v1/weshop/cart/options',
        'api/rest/v1/weshop/cart/update',
        'api/rest/v1/weshop/cart/remove',
        'api/rest/v1/weshop/cart/mini-items',
        'api/rest/v1/weshop/order/list',
        'api/rest/v1/weshop/order/detail',
        'api/rest/v1/weshop/order/unpaid-count',
        'api/rest/v1/weshop/order/unpaid-list',
        'api/rest/v1/weshop/invoice/list',
    ];

    private const WORKER_QUERY_BIN_PATH_PATTERNS = [
        'api/framework/query-bin',
        'framework/query-bin',
    ];

    private const AUTH_CONTROLLERS = ['Auth', 'Challenge'];

    private const AUTH_ACTIONS = [
        'postToken',
        'postLogin',
        'postExchange',
        'postRefresh',
        'postVerify',
        'getTokenInfo',
        'postLogout',
        'getMe',
        'login',
        'refresh',
        'logout',
        'me',
        'tokenInfo',
    ];

    public function matches(Request $request): bool
    {
        if ($this->matchesWorkerQueryBinRoute($request)) {
            return true;
        }

        $controller = (string) $request->getController();
        $action = (string) $request->getAction();
        $controllerClass = (string) ($request->getRouterData('controller') ?? '');

        if ($controller !== '' && $action !== '') {
            if (in_array($controller, self::AUTH_CONTROLLERS, true) && in_array($action, self::AUTH_ACTIONS, true)) {
                return true;
            }

            if (
                $controllerClass !== ''
                && (str_contains($controllerClass, '\\Auth') || str_ends_with($controllerClass, '\\Auth'))
                && in_array($action, self::AUTH_ACTIONS, true)
            ) {
                return true;
            }

            if (stripos($controller, 'auth') !== false && in_array($action, self::AUTH_ACTIONS, true)) {
                return true;
            }
        }

        $paths = array_filter([
            $request->getRouteUrlPath(),
            $request->getPath(),
            (string) ($request->getRouterData('module_path') ?? ''),
        ]);

        foreach ($paths as $path) {
            if ($this->matchesPath((string) $path)) {
                return true;
            }
        }

        return false;
    }

    private function matchesWorkerQueryBinRoute(Request $request): bool
    {
        $controllerClass = (string) ($request->getRouterData('controller') ?? '');
        if (
            $controllerClass !== ''
            && class_exists($controllerClass)
            && (
                $controllerClass === \Weline\Framework\Controller\Api\QueryBin::class
                || is_subclass_of($controllerClass, \Weline\Framework\Controller\Api\QueryBin::class)
            )
        ) {
            return true;
        }

        $paths = array_filter([
            $request->getRouteUrlPath(),
            $request->getPath(),
            (string) ($request->getRouterData('module_path') ?? ''),
        ]);

        foreach ($paths as $path) {
            if ($this->matchesPath((string) $path, self::WORKER_QUERY_BIN_PATH_PATTERNS)) {
                return true;
            }
        }

        return false;
    }

    public function matchesGuestFrontendRoute(Request $request): bool
    {
        $paths = array_filter([
            $request->getRouteUrlPath(),
            $request->getPath(),
            (string) ($request->getRouterData('module_path') ?? ''),
        ]);

        foreach ($paths as $path) {
            if ($this->matchesPath((string) $path, self::GUEST_FRONTEND_PATH_PATTERNS)) {
                return true;
            }
        }

        return $this->matchesPublicFrontendController(
            (string) ($request->getRouterData('controller') ?? ''),
            (string) $request->getAction()
        );
    }

    private function matchesPath(string $path, ?array $patterns = null): bool
    {
        $normalizedPath = ltrim($path, '/');

        foreach (($patterns ?? array_merge(self::AUTH_PATH_PATTERNS, self::DEMO_PATH_PATTERNS)) as $pattern) {
            if (
                $normalizedPath === $pattern
                || str_starts_with($normalizedPath, $pattern . '/')
                || str_ends_with($normalizedPath, '/' . $pattern)
                || str_ends_with($normalizedPath, $pattern)
                || str_contains($normalizedPath, '/' . $pattern . '/')
                || str_contains($normalizedPath, '/' . $pattern)
                || preg_match('/[\/\-_]' . preg_quote($pattern, '/') . '(\/|$)/', $normalizedPath) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function matchesPublicFrontendController(string $controllerClass, string $action): bool
    {
        if ($controllerClass === '' || $action === '' || !class_exists($controllerClass)) {
            return false;
        }

        if (
            $controllerClass === \Weline\Framework\Controller\Api\QueryBin::class
            || is_subclass_of($controllerClass, \Weline\Framework\Controller\Api\QueryBin::class)
        ) {
            return false;
        }

        if (!is_subclass_of($controllerClass, \Weline\Framework\App\Controller\FrontendRestController::class)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($controllerClass);
            if (!$reflection->hasMethod($action)) {
                return false;
            }

            if ($reflection->getAttributes(\Weline\Framework\Acl\Acl::class) !== []) {
                return false;
            }

            $method = $reflection->getMethod($action);
            return $method->getAttributes(\Weline\Framework\Acl\Acl::class) === [];
        } catch (\Throwable) {
            return false;
        }
    }
}
