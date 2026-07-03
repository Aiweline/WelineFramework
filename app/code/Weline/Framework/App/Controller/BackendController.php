<?php

declare(strict_types=1);

namespace Weline\Framework\App\Controller;

use Weline\Framework\App\State;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Controller\PcController;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 后台控制器基类
 *
 * 提供后台页面的通用功能：
 * - 登录状态检查
 * - Session 管理（通过 AuthenticatedSession）
 * - 缓存管理
 * - 布局配置
 */
class BackendController extends PcController
{
    protected CachePool $cache;
    
    /** 认证 Session（使用新架构） */
    protected AuthenticatedSessionInterface $session;
    
    /**
     * 后端默认使用 default.default 布局，自动加载主题变量 CSS
     * 格式：布局类型.布局选项（如 'default.default', 'dashboard.default', 'login.default'）
     * 子类可覆盖此属性以使用其他布局或设为 null 禁用布局
     */
    protected ?string $layoutType = 'default.default';

    public function __init()
    {
        $this->normalizeBackendRuntimeContext();
        $this->getEventManager()->dispatch('Weline_Framework_App::backend_controller_init_before');
        $this->cache = $this->getControllerCache();
        
        if (!isset($this->session)) {
            $this->session = SessionFactory::getInstance()->createBackendSession();
        }
        // 尽早初始化底层 Session（读 Cookie 或生成新 ID，并加入 flush 队列），避免后续 302 前 flush 时尚未 start 导致落库遗漏
        $this->session->start(null);

        parent::__init();
        
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        
        $this->getEventManager()->dispatch('Weline_Framework_App::backend_controller_init_after');
        // 后置 observer 可能在当前请求内恢复 remember-me 登录态，这里重新对齐到最新 backend session 实例。
        $this->session = SessionFactory::getInstance()->createBackendSession();
        $this->loginCheck();
    }

    private function normalizeBackendRuntimeContext(): void
    {
        WelineEnv::set('area', 'backend', 'BackendController normalize');
        WelineEnv::set('is_backend', true, 'BackendController normalize');

        $initializedVars = \get_object_vars($this);
        if (!isset($initializedVars['request']) || !$initializedVars['request'] instanceof Request) {
            $this->request = ObjectManager::getInstance(Request::class);
        }

        $this->request->setServer('WELINE_AREA', 'backend');
        $this->request->setServer('WELINE_IS_BACKEND', '1');

        if (\class_exists(\Weline\Backend\Service\BackendWarmupContext::class)
            && \Weline\Backend\Service\BackendWarmupContext::isInternalWarmupRequest($this->request)
        ) {
            $warmupUser = \Weline\Backend\Service\BackendWarmupContext::resolveWarmupUser($this->request);
            if ($warmupUser !== null) {
                \Weline\Backend\Service\BackendWarmupContext::installForUser($warmupUser);
            }
        }
    }

    protected function loginCheck(): void
    {
        if ((\defined('ENV_TEST') && ENV_TEST === true) || \defined('PHPUNIT_COMPOSER_INSTALL') || \defined('__PHPUNIT_PHAR__')) {
            return;
        }

        if (\class_exists(\Weline\Backend\Service\BackendWarmupContext::class)
            && \Weline\Backend\Service\BackendWarmupContext::isInternalWarmupRequest($this->request)
            && \Weline\Backend\Service\BackendWarmupContext::isActive()
        ) {
            return;
        }

        $isHttpRequest = !CLI || \w_env('request.uri') !== null;
        $sessionIsLogin = $this->session->isLoggedIn();
        
        if ($isHttpRequest && !$sessionIsLogin) {
            $whitelist_url_cache_key = 'whitelist_url_cache_key';
            $whitelist_url = $this->cache->get($whitelist_url_cache_key);
            
            if (!$whitelist_url) {
                /** @var EventsManager $evenManager */
                $evenManager = ObjectManager::getInstance(EventsManager::class);
                $whitelistUrlData = new DataObject(['whitelist_url' => []]);
                $evenManager->dispatch('Weline_Framework_Router::backend_whitelist_url', $whitelistUrlData);
                $whitelist_url = $whitelistUrlData->getData('whitelist_url');
                $this->cache->set($whitelist_url_cache_key, $whitelist_url);
            }
            
            $routeUrlPath = $this->request->getRouteUrlPath();
            
            if (!$this->isBackendWhitelistedRoute($routeUrlPath, $whitelist_url)) {
                if ($this->isSseLikeRequest()) {
                    throw new ResponseTerminateException(
                        Response::json([
                            'error' => 'UNAUTHORIZED',
                            'message' => (string)__('未登录或登录已过期'),
                            'route' => $routeUrlPath,
                        ], 401)
                    );
                }

                /** @var EventsManager $evenManager */
                $evenManager = ObjectManager::getInstance(EventsManager::class);
                $noLoginRedirectUrl = new DataObject(['no_login_redirect_url' => '']);
                $evenManager->dispatch('Weline_Framework_Router::backend_no_login_redirect_url', $noLoginRedirectUrl);
                $no_login_redirect_url = $this->normalizeBackendLoginUrlSameOrigin(
                    (string)$noLoginRedirectUrl->getData('no_login_redirect_url')
                );
                
                if ($no_login_redirect_url) {
                    $this->redirect($this->withBackendLoginReturnUrl((string)$no_login_redirect_url, $routeUrlPath));
                }
                
                $this->noRouter();
            }
        }
    }

    private function isBackendWhitelistedRoute(string $routeUrlPath, array $whitelistUrls): bool
    {
        $normalizedRoute = \strtolower(\trim($routeUrlPath, '/'));
        foreach ($whitelistUrls as $whitelistUrl) {
            $normalizedWhitelist = \strtolower(\trim((string)$whitelistUrl, '/'));
            if ($normalizedWhitelist === '') {
                continue;
            }

            if ($normalizedRoute === $normalizedWhitelist || \str_ends_with($normalizedRoute, '/' . $normalizedWhitelist)) {
                return true;
            }
        }

        return false;
    }

    private function withBackendLoginReturnUrl(string $loginUrl, string $routeUrlPath): string
    {
        if (!$this->request->isDocumentNavigationRequest()) {
            return $loginUrl;
        }

        $normalizedRoute = \strtolower(\trim($routeUrlPath, '/'));
        if ($normalizedRoute === ''
            || $normalizedRoute === 'admin/login'
            || $normalizedRoute === 'admin/login/post'
            || $normalizedRoute === 'admin/login/logout'
            || $this->isApiOrInterfaceRoute($normalizedRoute)
        ) {
            return $loginUrl;
        }

        $returnUrl = $this->getCurrentRequestUrl();
        if ($returnUrl === '') {
            return $loginUrl;
        }

        $query = [
            'no_access_reason' => 'not_logged_in',
            'return_url' => $returnUrl,
        ];

        return $loginUrl . (\str_contains($loginUrl, '?') ? '&' : '?') . \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function isApiOrInterfaceRoute(string $routeUrlPath): bool
    {
        $segments = \array_values(\array_filter(
            \explode('/', \trim($routeUrlPath, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));

        foreach ($segments as $segment) {
            if (\in_array(\strtolower($segment), ['api', 'rest', 'graphql'], true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeBackendLoginUrlSameOrigin(string $loginUrl): string
    {
        $parsed = \parse_url($loginUrl);
        $path = \is_array($parsed) ? (string)($parsed['path'] ?? '') : '';
        if ($path === '') {
            $path = $this->getBackendPathWithPrefix('admin/login');
        }

        $query = \is_array($parsed) && isset($parsed['query']) && $parsed['query'] !== ''
            ? '?' . $parsed['query']
            : '';
        $path = $this->normalizeBackendPathForSameOrigin($path);
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = (string)($this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: 'localhost');

        return $scheme . '://' . $host . (\str_starts_with($path, '/') ? $path : '/' . $path) . $query;
    }

    private function getBackendPathWithPrefix(string $path): string
    {
        $backendPrefix = \Weline\Framework\App\Env::getAreaRoutePrefix('backend');
        $areaRoute = (string)($this->request->getServer('WELINE_AREA_ROUTE') ?? '');
        if ($areaRoute !== '' && $backendPrefix !== null && $backendPrefix !== ''
            && (\str_starts_with($areaRoute, $backendPrefix . '/') || $areaRoute === $backendPrefix)
        ) {
            return '/' . \trim($areaRoute, '/') . '/' . \ltrim($path, '/');
        }

        if ($backendPrefix !== null && $backendPrefix !== '') {
            $currency = (string)(\w_env('user.currency', 'CNY') ?? 'CNY');
            $language = (string)(\w_env('user.lang', 'zh_Hans_CN') ?? 'zh_Hans_CN');
            return '/' . $backendPrefix . '/' . $currency . '/' . $language . '/' . \ltrim($path, '/');
        }

        return '/' . \ltrim($path, '/');
    }

    private function getCurrentRequestUrl(): string
    {
        $uri = (string)($this->request->getServer('WELINE_ORIGIN_REQUEST_URI') ?: $this->request->getServer('REQUEST_URI'));
        if ($uri === '') {
            return '';
        }

        $path = (string)(\parse_url($uri, PHP_URL_PATH) ?: $uri);
        $query = (string)(\parse_url($uri, PHP_URL_QUERY) ?: '');
        $uri = $this->normalizeBackendPathForSameOrigin($path) . ($query !== '' ? '?' . $query : '');

        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = (string)($this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: 'localhost');
        return $scheme . '://' . $host . (\str_starts_with($uri, '/') ? $uri : '/' . $uri);
    }

    private function normalizeBackendPathForSameOrigin(string $path): string
    {
        $path = '/' . \trim($path, '/');
        $segments = \explode('/', \trim($path, '/'));
        $firstSegment = (string)($segments[0] ?? '');

        if (isset($segments[1], $segments[2], $segments[3])
            && $firstSegment !== ''
            && $this->isCurrencySegment($segments[1])
            && $this->isLocaleSegment($segments[2])
            && $segments[3] === $firstSegment
        ) {
            \array_splice($segments, 3, 1);
            return '/' . \implode('/', $segments);
        }

        return $path;
    }

    private function isCurrencySegment(string $segment): bool
    {
        return State::isAllowedCurrencyCode($segment);
    }

    private function isLocaleSegment(string $segment): bool
    {
        return (bool)\preg_match('/^[a-z]{2}(?:[_-][A-Za-z0-9]{2,8}){1,3}$/', $segment);
    }

    private function isSseLikeRequest(): bool
    {
        $accept = $this->request->getHeader('Accept');
        $acceptHeader = \is_array($accept) ? \implode(',', $accept) : (string)$accept;
        if ($acceptHeader !== '' && \str_contains(\strtolower($acceptHeader), 'text/event-stream')) {
            return true;
        }

        $routeUrlPath = \strtolower((string)$this->request->getRouteUrlPath());
        $requestUri = \strtolower((string)$this->request->getUri());

        return \str_contains($routeUrlPath, 'stream-sse')
            || \str_contains($routeUrlPath, 'operation-sse')
            // fetch+POST 的 AI 流式端点常不带 EventSource 风格 Accept，仍需按 SSE 处理登录失败响应
            || \str_contains($routeUrlPath, 'component-config-stream')
            || \str_contains($routeUrlPath, 'page-content-stream')
            || (\str_contains($routeUrlPath, '/ai-generate/') && \str_contains($routeUrlPath, '-stream'))
            || \str_contains($requestUri, 'stream-sse')
            || \str_contains($requestUri, 'operation-sse')
            || \str_contains($requestUri, 'component-config-stream')
            || \str_contains($requestUri, 'page-content-stream')
            || (\str_contains($requestUri, '/ai-generate/') && \str_contains($requestUri, '-stream'));
    }

    /**
     * 获取当前登录用户
     *
     * @return \Weline\Framework\Session\Auth\AuthenticableInterface|null
     */
    protected function getLoginUser(): ?\Weline\Framework\Session\Auth\AuthenticableInterface
    {
        return $this->session->getUser();
    }

    /**
     * 获取当前登录用户 ID
     *
     * @return int|string|null
     */
    protected function getLoginUserId(): int|string|null
    {
        return $this->session->getUserId();
    }

    /**
     * 获取当前登录用户名
     *
     * @return string|null
     */
    protected function getLoginUsername(): ?string
    {
        return $this->session->getUsername();
    }

    /**
     * 检查是否已登录
     *
     * @return bool
     */
    protected function isLoggedIn(): bool
    {
        return $this->session->isLoggedIn();
    }
}
