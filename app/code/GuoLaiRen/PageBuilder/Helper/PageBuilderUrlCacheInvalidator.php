<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Helper;

use GuoLaiRen\PageBuilder\Controller\Router;
use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\UrlManager\Model\UrlRewrite;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;
/**
 * PageBuilder URL / 路由 / WLS dispatch 相关缓存：按页面粒度失效。
 *
 * - url_rewrite 池：按 RouterRewrite 使用的 website_{id}_{path} 键删除
 * - router 池：按完整 URI 删除 unified/url/rule/start 及旧版 fpc: 键（含 FPM/WLS 共用的 FPC HTML）
 * - Router 静态 handle 缓存：当前进程
 * - WLS：IPC 通知各 Worker 清理同站 handle 缓存并重置 ObjectManager（避免 dispatch 单例脏读）
 */
final class PageBuilderUrlCacheInvalidator
{
    /**
     * 全量清理（删除站点、批量操作等仍可用）
     * 说明：
     * - FPM 模式：直接清理 router/url_rewrite 池与本进程 Router 静态缓存
     * - WLS 模式：除上述本地清理外，业务入口应继续按需触发 WLS IPC 广播
     */
    public static function invalidateRouterAndRewrite(): void
    {
        try {
            if (class_exists(CacheManager::class)) {
                $cacheManager = ObjectManager::getInstance(CacheManager::class);
                $routerPool = $cacheManager->pool('router');
                if (method_exists($routerPool, 'clear')) {
                    $routerPool->clear();
                }
                $rewritePool = $cacheManager->pool('url_rewrite');
                if (method_exists($rewritePool, 'clear')) {
                    $rewritePool->clear();
                }
            }
        } catch (\Throwable) {
        }
        Router::clearCache();
        self::notifyWlsGlobalCacheClear();
    }

    /**
     * @return array{ok:bool, page_id?:int, keys_deleted?:int, wls_notified?:bool}
     */
    public static function invalidateForPageId(int $pageId, string $wlsInstance = 'default'): array
    {
        if ($pageId <= 0) {
            return ['ok' => false];
        }
        $page = clone ObjectManager::getInstance(Page::class);
        $page->clear()->load($pageId);
        if (!$page->getId()) {
            return ['ok' => false];
        }

        $websiteId = (int)($page->getData(Page::schema_fields_WEBSITE_ID) ?? 0);
        $handle = (string)($page->getData(Page::schema_fields_HANDLE) ?? '');
        $isHome = ($page->getData(Page::schema_fields_TYPE) ?? '') === Page::TYPE_HOME;
        $langLocals = self::collectLangLocalsForPage($page);

        $deleted = 0;
        $deleted += self::deleteUrlRewritePoolEntries($websiteId, $pageId);
        $deleted += self::deleteRouterPoolEntriesForPage($websiteId, $pageId, $langLocals);

        Router::clearHandleCacheForPage($websiteId, $handle, $isHome);

        $wlsOk = self::notifyWlsWorkersPageInvalidate($wlsInstance, $websiteId, $handle, $isHome);

        return [
            'ok' => true,
            'page_id' => $pageId,
            'keys_deleted' => $deleted,
            'wls_notified' => $wlsOk,
        ];
    }

    /**
     * @return list<string>
     */
    private static function collectLangLocalsForPage(Page $page): array
    {
        $locals = [];
        $default = (string)($page->getData(Page::schema_fields_DEFAULT_LOCALE) ?? '');
        if ($default !== '') {
            $locals[] = $default;
        }
        $json = (string)($page->getData(Page::schema_fields_LOCALES) ?? '');
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $c) {
                    if (is_string($c) && $c !== '') {
                        $locals[] = $c;
                    }
                }
            }
        }
        foreach (['en_US', 'zh_Hans_CN', ''] as $fallback) {
            if (!in_array($fallback, $locals, true)) {
                $locals[] = $fallback;
            }
        }

        return array_values(array_unique($locals));
    }

    private static function deleteUrlRewritePoolEntries(int $websiteId, int $pageId): int
    {
        $n = 0;
        try {
            $pool = ObjectManager::getInstance(CacheManager::class)->pool('url_rewrite');
        } catch (\Throwable) {
            return 0;
        }

        /** @var UrlRewrite $model */
        $model = ObjectManager::getInstance(UrlRewrite::class);
        $rows = $model->clear()
            ->where(UrlRewrite::schema_fields_URL_ID, "pagebuilder_page_{$pageId}")
            ->pagination(1, 32)
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            $rows = [];
        }

        $rewriteSegments = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rw = (string)($row[UrlRewrite::schema_fields_REWRITE] ?? '');
            $rewriteSegments[] = trim($rw, '/');
        }
        $rewriteSegments = array_values(array_unique($rewriteSegments));

        foreach ($rewriteSegments as $seg) {
            $cacheKey = 'website_' . $websiteId . '_' . $seg;
            if (method_exists($pool, 'delete') && $pool->delete($cacheKey)) {
                ++$n;
            }
        }

        return $n;
    }

    private static function deleteRouterPoolEntriesForPage(int $websiteId, int $pageId, array $langLocals): int
    {
        $n = 0;
        try {
            $pool = ObjectManager::getInstance(CacheManager::class)->pool('router');
        } catch (\Throwable) {
            return 0;
        }

        $bases = self::collectPublicBaseUrls($websiteId);
        $paths = self::collectPublicPathSuffixes($websiteId, $pageId);
        $methods = ['GET', 'HEAD'];

        foreach ($bases as $base) {
            foreach ($paths as $pathSuffix) {
                $full = self::absoluteUriFromBaseAndSuffix($base, $pathSuffix);
                if ($full === '' || !KeyBuilder::isValidFullPageCacheKey($full)) {
                    continue;
                }
                foreach ($methods as $method) {
                    $keys = KeyBuilder::routerPoolKeysForFullRequestUri($full, $method, $langLocals);
                    foreach ($keys as $key) {
                        if (method_exists($pool, 'delete') && $pool->delete($key)) {
                            ++$n;
                        }
                    }
                }
            }
        }

        return $n;
    }

    /**
     * @return list<string> 形如 https://host 或 https://host:8443（无尾斜杠）
     */
    private static function collectPublicBaseUrls(int $websiteId): array
    {
        $bases = [];
        if ($websiteId > 0) {
            $w = clone ObjectManager::getInstance(Website::class);
            $w->clear()->load($websiteId);
            if ($w->getId()) {
                $u = (string)($w->getData(Website::schema_fields_URL) ?? '');
                if ($u !== '') {
                    $bases = array_merge($bases, self::basesFromWebsiteUrl($u));
                }
            }

            $dm = ObjectManager::getInstance(WebsiteDomain::class);
            $rows = $dm->clear()
                ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
                ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
                ->pagination(1, 200)
                ->select()
                ->fetchArray();
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $host = strtolower(trim((string)($row[WebsiteDomain::schema_fields_DOMAIN] ?? '')));
                    if ($host === '') {
                        continue;
                    }
                    $sub = self::normalizeSubPath((string)($row[WebsiteDomain::schema_fields_SUB_PATH] ?? ''));
                    $https = (int)($row[WebsiteDomain::schema_fields_HTTPS_ENABLED] ?? 0) === 1;
                    $schemes = $https ? ['https', 'http'] : ['http', 'https'];
                    foreach ($schemes as $sch) {
                        $bases[] = $sch . '://' . $host . $sub;
                    }
                }
            }
        }

        if ($bases === []) {
            $bases[] = 'http://127.0.0.1';
            $bases[] = 'https://127.0.0.1';
        }

        return array_values(array_unique($bases));
    }

    /**
     * @return list<string>
     */
    private static function basesFromWebsiteUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return [];
        }
        $host = isset($parts['host']) ? (string)$parts['host'] : '';
        if ($host === '') {
            return [];
        }
        $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : 'https';
        $port = isset($parts['port']) ? (int)$parts['port'] : 0;
        $path = isset($parts['path']) ? self::normalizeSubPath((string)$parts['path']) : '';
        $portSuffix = '';
        if ($port > 0 && !(($port === 80 && $scheme === 'http') || ($port === 443 && $scheme === 'https'))) {
            $portSuffix = ':' . $port;
        }
        $root = $scheme . '://' . $host . $portSuffix . $path;

        return array_values(array_unique([$root, ($scheme === 'https' ? 'http' : 'https') . '://' . $host . $portSuffix . $path]));
    }

    private static function normalizeSubPath(string $sub): string
    {
        $sub = trim($sub);
        if ($sub === '') {
            return '';
        }

        return '/' . trim($sub, '/');
    }

    /**
     * @return list<string> REQUEST_URI 路径段（以 / 开头），可含 query
     */
    private static function collectPublicPathSuffixes(int $websiteId, int $pageId): array
    {
        $paths = [];

        /** @var UrlRewrite $model */
        $model = ObjectManager::getInstance(UrlRewrite::class);
        $rows = $model->clear()
            ->where(UrlRewrite::schema_fields_URL_ID, "pagebuilder_page_{$pageId}")
            ->pagination(1, 32)
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            $rows = [];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rw = trim((string)($row[UrlRewrite::schema_fields_REWRITE] ?? ''));
            if ($rw === '') {
                $paths[] = '/';
            } else {
                $paths[] = '/' . trim($rw, '/');
            }
        }
        if ($paths === []) {
            $paths[] = '/';
        }

        $q = 'page_id=' . $pageId;
        if ($websiteId > 0) {
            $q .= '&website_id=' . $websiteId;
        }
        $paths[] = '/pagebuilder/frontend/page/view?' . $q;

        return array_values(array_unique($paths));
    }

    /**
     * @param string $pathSuffix 以 / 开头路径，或含 ? 的 path+query（如 /page/view?a=1）
     */
    private static function absoluteUriFromBaseAndSuffix(string $base, string $pathSuffix): string
    {
        if (str_contains($pathSuffix, '://')) {
            return $pathSuffix;
        }
        $s = $pathSuffix === '' ? '/' : $pathSuffix;
        if (!str_starts_with($s, '/') && !str_starts_with($s, '?')) {
            $s = '/' . $s;
        }
        if (str_starts_with($s, '?')) {
            $s = '/' . $s;
        }
        $full = rtrim($base, '/') . $s;

        return str_contains($full, '://') ? $full : '';
    }

    private static function notifyWlsWorkersPageInvalidate(
        string $instance,
        int $websiteId,
        string $handle,
        bool $isHomePage
    ): bool {
        if (!\class_exists(\Weline\Server\IPC\ControlMessage::class)
            || !\class_exists(\Weline\Server\Service\Control\IpcControlGateway::class)) {
            return false;
        }
        try {
            $gw = ObjectManager::getInstance(\Weline\Server\Service\Control\IpcControlGateway::class);
            $res = $gw->command(
                $instance,
                \Weline\Server\IPC\ControlMessage::ACTION_PAGEBUILDER_PAGE_INVALIDATE,
                '',
                [
                    'website_id' => $websiteId,
                    'handle' => $handle,
                    'is_home_page' => $isHomePage,
                ],
                4.0
            );

            return (bool)($res['success'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function notifyWlsGlobalCacheClear(): void
    {
        if (!\class_exists(\Weline\Server\Service\Control\BroadcastControlDispatchService::class)) {
            return;
        }

        try {
            ObjectManager::getInstance(\Weline\Server\Service\Control\BroadcastControlDispatchService::class)
                ->cacheClear();
        } catch (\Throwable) {
        }
    }
}
