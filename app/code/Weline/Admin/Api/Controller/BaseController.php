<?php

declare(strict_types=1);

namespace Weline\Admin\Api\Controller;

use Weline\Admin\Controller\Index;
use Weline\Backend\Api\Config\BackendUserConfigStore;
use Weline\Backend\Api\Runtime\BackendWarmupContext;
use Weline\Backend\Api\View\BackendThemeConfigInterface;
use Weline\Framework\App\State;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Cache\Contract\SharedCacheStateFactoryInterface;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\RuntimeCachePolicy;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\View\BackendLayoutProviderInterface;

class BaseController extends BackendController
{
    private const ADMIN_FULL_PAGE_CACHE_NS = 'weline_admin_runtime';
    private const ADMIN_FULL_PAGE_CACHE_TTL = 600;
    private const ADMIN_FULL_PAGE_CACHE_MAX_ITEMS = 32;

    /**
     * @var array<string, array{expires_at: float, html: string}>
     */
    private static array $adminFullPageCache = [];
    private static ?SharedCacheStateInterface $adminRuntimeCache = null;
    private static bool $adminRuntimeCacheResolved = false;
    private static string $lastAdminRuntimeCacheGetStatus = 'none';

    public static function clearRuntimeFullPageCache(): void
    {
        self::$adminFullPageCache = [];
        $cache = self::adminRuntimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->clearNamespace(self::ADMIN_FULL_PAGE_CACHE_NS);
        } catch (\Throwable) {
            self::$adminRuntimeCache = null;
            self::$adminRuntimeCacheResolved = true;
        }
    }

    public function __init()
    {
        parent::__init();
        $this->assign('title', __('WelineFramework Admin'));
        $this->assign('logo_title', __('WelineFramework'));
    }

    protected function fetch(string $fileName = '', array $data = []): mixed
    {
        $adminFullPageCacheKey = $this->canUseAdminFullPageCache($fileName)
            ? $this->buildAdminFullPageCacheKey($fileName)
            : null;
        if ($adminFullPageCacheKey !== null) {
            $cached = $this->getAdminFullPageCache($adminFullPageCacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $content = parent::fetch($fileName, $data);
        if (!$this->shouldWrapBackendContent($content)) {
            if ($adminFullPageCacheKey !== null && $this->isCacheableAdminFullPageHtml($content)) {
                $this->rememberAdminFullPageCache($adminFullPageCacheKey, (string)$content);
            }
            return $content;
        }

        [$layoutType, $layoutOption] = $this->resolveBackendLayoutSpec();
        $layoutPath = $this->resolveBackendLayoutTemplate($layoutType, $layoutOption);
        if ($layoutPath === null) {
            return $content;
        }

        $template = $this->getTemplate();
        $template->setData('layout', null);
        $template->setData('contentTemplate', '');

        $fullHtml = $template->fetch($layoutPath, [
            'content' => (string)$content,
            'contentTemplate' => '',
        ]);
        if ($adminFullPageCacheKey !== null && \is_string($fullHtml) && $fullHtml !== '') {
            $this->rememberAdminFullPageCache($adminFullPageCacheKey, $fullHtml);
        }

        return $fullHtml;
    }

    protected function fetchBase(string $fileName = '', array $data = []): mixed
    {
        if ($data) {
            $this->assign($data);
        }
        if ($fileName) {
            if (is_int(strpos($fileName, '::'))) {
                return $this->getTemplate()->fetch($fileName);
            }
        }

        $controllerClassName = $this->request->getRouterData('class/controller_name');
        if ($fileName === '') {
            if (in_array(strtoupper($this->request->getRouterData('class/method')), $this->request::METHODS)) {
                $fileName = $controllerClassName;
            } else {
                $fileName = $controllerClassName . '/' . $this->request->getRouterData('class/method');
            }
        } elseif (is_bool(strpos($fileName, '/')) || is_bool(strpos($fileName, '\\'))) {
            $fileName = $controllerClassName . DS . $fileName;
        } else {
            $fileName = $controllerClassName . '/' . $this->request->getRouterData('class/method') . DS . $fileName;
        }

        $before = $this->getTemplate()->fetch('Weline_Admin::templates/Backend/page-layout/main-content-before.phtml');
        $content = $this->getTemplate()->fetch('templates' . DS . $fileName);
        $after = $this->getTemplate()->fetch('Weline_Admin::templates/Backend/page-layout/main-content-after.phtml');

        return $before . $content . $after;
    }

    private function shouldWrapBackendContent(mixed $content): bool
    {
        if (!is_string($content) || trim($content) === '') {
            return false;
        }

        if ($this->layoutType === null || $this->isAjaxLikeRequest()) {
            return false;
        }

        $trimmed = ltrim($content);
        return !str_starts_with($trimmed, '<!DOCTYPE html>')
            && stripos($trimmed, '<html') === false;
    }

    private function isCacheableAdminFullPageHtml(mixed $content): bool
    {
        if (!\is_string($content) || \trim($content) === '') {
            return false;
        }

        $trimmed = \ltrim($content);
        return \str_starts_with($trimmed, '<!DOCTYPE html>')
            || \stripos($trimmed, '<html') !== false;
    }

    private function isAjaxLikeRequest(): bool
    {
        $requestedWith = strtolower((string)$this->request->getServer('HTTP_X_REQUESTED_WITH'));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        if ((int)$this->request->getParam('isAjax', 0) === 1) {
            return true;
        }

        $accept = strtolower((string)$this->request->getHeader('Accept'));
        return str_contains($accept, 'application/json');
    }

    private function resolveBackendLayoutSpec(): array
    {
        $layoutType = (string)($this->layoutType ?? 'default.default');
        $parts = explode('.', $layoutType, 2);
        $layoutName = trim((string)($parts[0] ?? 'default'));
        $layoutOption = trim((string)($parts[1] ?? 'default'));

        return [
            $layoutName !== '' ? $layoutName : 'default',
            $layoutOption !== '' ? $layoutOption : 'default',
        ];
    }

    private function resolveBackendLayoutTemplate(string $layoutType, string $layoutOption): ?string
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(BackendLayoutProviderInterface::class);
        return $provider instanceof BackendLayoutProviderInterface
            ? $provider->resolve($layoutType, $layoutOption)
            : null;
    }

    private function canUseAdminFullPageCache(string $fileName): bool
    {
        if (!$this instanceof Index) {
            return false;
        }

        if ($fileName !== '') {
            return false;
        }

        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent()) {
            return false;
        }

        $method = \strtoupper((string)$this->request->getMethod());
        if (!\in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($this->isAjaxLikeRequest()) {
            return false;
        }

        $query = \method_exists($this->request, 'getQuery') ? $this->request->getQuery() : [];
        if (\is_array($query) && $query !== []) {
            return false;
        }

        return $this->resolveAdminFullPageCacheUserId() > 0;
    }

    private function resolveAdminFullPageCacheUserId(): int
    {
        if (\class_exists(BackendWarmupContext::class)) {
            $warmupUserId = BackendWarmupContext::currentUserId();
            if ($warmupUserId > 0) {
                return $warmupUserId;
            }
        }

        try {
            if (isset($this->session)) {
                $userId = (int)($this->session->getUserId() ?? 0);
                if ($userId > 0) {
                    return $userId;
                }

                $user = $this->session->getLoginUser();
                if (\is_object($user) && \method_exists($user, 'getId')) {
                    return \max(0, (int)$user->getId());
                }
            }
        } catch (\Throwable) {
        }

        try {
            /** @var BackendUserConfigStore $userConfig */
            $userConfig = ObjectManager::getInstance(BackendUserConfigStore::class);
            return $userConfig->getCurrentUserId();
        } catch (\Throwable) {
        }

        return 0;
    }

    private function buildAdminFullPageCacheKey(string $fileName): string
    {
        $host = \strtolower(\trim((string)($this->request->getServer('HTTP_HOST') ?? '')));
        $uri = (string)(\function_exists('w_env_request_uri') ? \w_env_request_uri() : ($this->request->getUri() ?? ''));
        $path = (string)(\parse_url($uri, PHP_URL_PATH) ?: $uri);
        $routePath = \trim((string)$this->request->getRouteUrlPath(), '/');
        $requestScope = KeyBuilder::requestScopeHash([
            'scope' => 'admin_full_page',
            'file' => $fileName,
            'route' => $routePath,
            'path' => $path,
        ], ['full_request_uri' => false]);

        return \sha1((string)\json_encode([
            'v' => 4,
            'controller' => static::class,
            'file' => $fileName,
            'route' => $routePath,
            'path' => $path,
            'host' => $host,
            'area' => (string)($this->request->getServer('WELINE_AREA') ?? ''),
            'area_route' => (string)($this->request->getServer('WELINE_AREA_ROUTE') ?? ''),
            'backend_prefix' => (string)(\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''),
            'rest_backend_prefix' => (string)(\Weline\Framework\App\Env::getAreaRoutePrefix('rest_backend') ?? ''),
            'website_id' => (string)($this->request->getServer('WELINE_WEBSITE_ID') ?? ''),
            'website_code' => (string)($this->request->getServer('WELINE_WEBSITE_CODE') ?? ''),
            'website_url' => (string)($this->request->getServer('WELINE_WEBSITE_URL') ?? ''),
            'user_id' => $this->resolveAdminFullPageCacheUserId(),
            'lang' => State::getLang(),
            'lang_local' => State::getLangLocal(),
            'currency' => State::getCurrency(),
            'theme_config_hash' => $this->resolveAdminThemeConfigHash(),
            'request_scope' => $requestScope,
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function resolveAdminThemeConfigHash(): string
    {
        try {
            $userId = $this->resolveAdminFullPageCacheUserId();
            if ($userId <= 0) {
                return '';
            }

            /** @var BackendUserConfigStore $configStore */
            $configStore = ObjectManager::getInstance(BackendUserConfigStore::class);
            $rawConfig = $configStore->getForUser($userId, BackendThemeConfigInterface::SESSION_CONFIG_KEY);
            if ($rawConfig === '') {
                return '';
            }

            $decoded = \json_decode($rawConfig, true);
            if (\is_array($decoded)) {
                return \sha1((string)\json_encode(
                    $decoded,
                    \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
                ));
            }

            return \sha1($rawConfig);
        } catch (\Throwable) {
            return '';
        }
    }

    private function getAdminFullPageCache(string $key): ?string
    {
        $now = \microtime(true);
        if (isset(self::$adminFullPageCache[$key])) {
            $cached = self::$adminFullPageCache[$key];
            if (($cached['expires_at'] ?? 0.0) >= $now && ($cached['html'] ?? '') !== '') {
                $this->setAdminCacheHeaders('local');
                return (string)$cached['html'];
            }
            unset(self::$adminFullPageCache[$key]);
        }

        $runtimeStart = \microtime(true);
        $runtimeCached = $this->adminRuntimeCacheGet('admin.view.' . $key);
        $runtimeDurationMs = (\microtime(true) - $runtimeStart) * 1000;
        $this->setPerfHeader('X-WLS-Admin-View-Cache-Get-Ms', \sprintf('%.2f', $runtimeDurationMs));
        if (\is_string($runtimeCached) && $runtimeCached !== '') {
            self::$adminFullPageCache[$key] = [
                'expires_at' => $now + $this->adminFullPageCacheTtl(),
                'html' => $runtimeCached,
            ];
            $this->trimAdminFullPageLocalCache();
            $this->setAdminCacheHeaders('shared');
            return $runtimeCached;
        }

        $this->setAdminCacheHeaders('miss:' . self::$lastAdminRuntimeCacheGetStatus);
        return null;
    }

    private function rememberAdminFullPageCache(string $key, string $html): void
    {
        $ttl = $this->adminFullPageCacheTtl();
        self::$adminFullPageCache[$key] = [
            'expires_at' => \microtime(true) + $ttl,
            'html' => $html,
        ];
        $this->trimAdminFullPageLocalCache();
        $this->adminRuntimeCacheSet('admin.view.' . $key, $html, $ttl);
        $this->setPerfHeader('X-WLS-Admin-View-Cache-Full-Html', 'stored');
        $this->setPerfHeader('X-WLS-Controller-Cache-Full-Html', 'stored');
        $this->setAdminCacheHeaders('stored');
    }

    private function trimAdminFullPageLocalCache(): void
    {
        if (\count(self::$adminFullPageCache) <= self::ADMIN_FULL_PAGE_CACHE_MAX_ITEMS) {
            return;
        }

        \uasort(
            self::$adminFullPageCache,
            static fn (array $a, array $b): int => ((float)($b['expires_at'] ?? 0.0)) <=> ((float)($a['expires_at'] ?? 0.0))
        );
        self::$adminFullPageCache = \array_slice(self::$adminFullPageCache, 0, self::ADMIN_FULL_PAGE_CACHE_MAX_ITEMS, true);
    }

    private function adminRuntimeCacheGet(string $key): mixed
    {
        $cache = self::adminRuntimeCache();
        if ($cache === null) {
            self::$lastAdminRuntimeCacheGetStatus = 'unavailable';
            return null;
        }

        try {
            $value = $cache->get(self::ADMIN_FULL_PAGE_CACHE_NS, $key);
            self::$lastAdminRuntimeCacheGetStatus = $value === null ? 'empty' : 'value';
            return $value;
        } catch (\Throwable $throwable) {
            self::$lastAdminRuntimeCacheGetStatus = 'error:' . $throwable::class;
            self::$adminRuntimeCache = null;
            self::$adminRuntimeCacheResolved = true;
            return null;
        }
    }

    private function adminRuntimeCacheSet(string $key, string $html, int $ttl): void
    {
        $cache = self::adminRuntimeCache();
        if ($cache === null) {
            $this->setPerfHeader('X-WLS-Admin-View-Cache-Store', 'unavailable');
            $this->setPerfHeader('X-WLS-Controller-Cache-Store', 'unavailable');
            return;
        }

        try {
            $stored = $cache->set(self::ADMIN_FULL_PAGE_CACHE_NS, $key, $html, \max(1, $ttl));
            $status = $stored ? 'ok' : 'fail';
            $this->setPerfHeader('X-WLS-Admin-View-Cache-Store', $status);
            $this->setPerfHeader('X-WLS-Controller-Cache-Store', $status);
        } catch (\Throwable $throwable) {
            $status = 'error:' . $throwable::class;
            $this->setPerfHeader('X-WLS-Admin-View-Cache-Store', $status);
            $this->setPerfHeader('X-WLS-Controller-Cache-Store', $status);
            self::$adminRuntimeCache = null;
            self::$adminRuntimeCacheResolved = true;
        }
    }

    private function setAdminCacheHeaders(string $status): void
    {
        $this->setPerfHeader('X-WLS-Admin-View-Cache', $status);
        $this->setPerfHeader('X-WLS-Controller-Cache', $status);
    }

    private function setPerfHeader(string $name, string $value): void
    {
        try {
            $this->request->getResponse()->setHeader($name, $value);
        } catch (\Throwable) {
        }
    }

    private function adminFullPageCacheTtl(): int
    {
        return self::cachePolicy()->ttl('backend.admin_view_ttl', self::ADMIN_FULL_PAGE_CACHE_TTL, 1, 600);
    }

    private static function adminRuntimeCache(): ?SharedCacheStateInterface
    {
        if (self::$adminRuntimeCacheResolved) {
            return self::$adminRuntimeCache;
        }
        self::$adminRuntimeCacheResolved = true;

        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent()) {
            return null;
        }

        try {
            $factory = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(SharedCacheStateFactoryInterface::class);
            if (!$factory instanceof SharedCacheStateFactoryInterface) {
                return null;
            }
            self::$adminRuntimeCache = $factory->create(self::cachePolicy()->memoryOptions([
                'consumer_code' => 'weline_admin_full_page',
                'connect_timeout' => 0.04,
                'timeout' => 0.08,
                'acquire_timeout' => 0.04,
            ]));
        } catch (\Throwable) {
            self::$adminRuntimeCache = null;
        }

        return self::$adminRuntimeCache;
    }

    private static function cachePolicy(): RuntimeCachePolicy
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class);
    }
}
