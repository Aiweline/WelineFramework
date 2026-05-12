<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;

/**
 * PageBuilder 路由重写器
 * 
 * 功能：将友好的URL路径重写为页面查看路由
 * 例如：/about-us -> /pagebuilder/frontend/page/view?handle=about-us
 */
class Router implements RouterInterface
{
    /**
     * 静态缓存，避免重复查询数据库
     */
    private static array $handleCache = [];
    
    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        $trimmedPath = trim($path, '/');

        $isRootPath = ($trimmedPath === '' || $trimmedPath === 'index' || $trimmedPath === 'index/index');

        // Keep existing module routes, but allow the published site root to be resolved by PageBuilder.
        if (!empty($rule['module']) && !$isRootPath) {
            return;
        }
        
        // 2. 跳过系统路由和媒体文件
        if (self::isSystemPath($path)) {
            return;
        }
        
        // 3. 处理空路径：域名根直接显示当前站点首页，或预览时用 query 的 handle
        if ($isRootPath) {
            $websiteId = self::resolveWebsiteIdFromCurrentHost() ?? self::getCurrentWebsiteId();
            // 无站点时按请求 host 解析站点，便于直接用域名访问首页
            if ($websiteId <= 0) {
                foreach (self::currentHostCandidates() as $host) {
                    $websiteId = self::findWebsiteIdByHost($host) ?? 0;
                    if ($websiteId > 0) {
                        WelineEnv::setServer('WELINE_WEBSITE_ID', (string)$websiteId, 'PageBuilder router host website');
                        break;
                    }
                }
            }
            // 预览模式：优先用 query 的 handle + website_id 配合 URL 解码
            $isPreview = (string)WelineEnv::getGet('preview', '') === '1';
            $queryHandleValue = WelineEnv::getGet('handle');
            $queryHandle = is_string($queryHandleValue) ? trim(rawurldecode($queryHandleValue)) : '';
            if ($isPreview && $queryHandle !== '') {
                $websiteIdParam = (int)WelineEnv::getGet('website_id', 0);
                if ($websiteIdParam > 0) {
                    WelineEnv::setServer('WELINE_WEBSITE_ID', (string)$websiteIdParam, 'PageBuilder preview website');
                }
                $path = '/pagebuilder/frontend/page/view';
                $rule['module'] = 'GuoLaiRen_PageBuilder';
                $rule['handle'] = $queryHandle;
                WelineEnv::setGet('handle', $queryHandle);
                return;
            }
            if ($websiteId > 0) {
                $homePageHandle = self::getHomePageHandle($websiteId);
                // 有首页即重写（handle 可为空，同站首页用域名即可）
                if ($homePageHandle !== null) {
                    $path = '/pagebuilder/frontend/page/view';
                    $rule['module'] = 'GuoLaiRen_PageBuilder';
                    $rule['handle'] = $homePageHandle;
                    WelineEnv::setGet('handle', $homePageHandle);
                    return;
                }
            }
            return;
        }
        
        // 4. 清理路径，提取可能的handle
        $handle = self::extractHandle($path);
        
        if (empty($handle)) {
            return;
        }
        
        // 5. 检查当前站点下 handle 是否存在；同站可省略前缀，如 /about 匹配 handle about 或 home-about
        $websiteId = self::resolveWebsiteIdFromCurrentHost() ?? self::getCurrentWebsiteId();
        $isPreview = (string)WelineEnv::getGet('preview', '') === '1';
        if (!self::handleExists($handle, $websiteId, $isPreview)) {
            // 同站短路径：先试 path 作为 handle，再试「首页handle- path」
            if ($websiteId > 0) {
                $homeHandle = self::getHomePageHandle($websiteId);
                if ($homeHandle !== null && $homeHandle !== '' && $handle !== $homeHandle) {
                    $prefixed = $homeHandle . '-' . $handle;
                    if (self::handleExists($prefixed, $websiteId, $isPreview)) {
                        $handle = $prefixed;
                    } else {
                        return;
                    }
                } else {
                    return;
                }
            } else {
                $resolvedWebsiteId = self::findWebsiteIdByHandle($handle, $isPreview);
                if ($resolvedWebsiteId !== null) {
                    WelineEnv::setServer('WELINE_WEBSITE_ID', (string)$resolvedWebsiteId, 'PageBuilder handle website');
                    $websiteId = $resolvedWebsiteId;
                } else {
                    return;
                }
            }
        }
        
        // 6. 重写路由到页面查看控制器
        $path = '/pagebuilder/frontend/page/view';
        // 设置路由参数
        $rule['module'] = 'GuoLaiRen_PageBuilder';
        $rule['handle'] = $handle;
        
        // Write handle into the request context so the controller can receive it.
        WelineEnv::setGet('handle', $handle);
        
        // 保留原始URL参数（如 preview、locale 等）
        // Existing query parameters stay in the request context.
    }
    
    /**
     * 判断是否为系统路径
     */
    private static function isSystemPath(string $path): bool
    {
        $systemPaths = [
            '/admin',
            '/api',
            '/pagebuilder',
            '/media',
            '/static',
            '/pub',
            '/errors',
            '/setup',
        ];
        
        $lowerPath = strtolower($path);
        
        foreach ($systemPaths as $systemPath) {
            if (str_starts_with($lowerPath, $systemPath)) {
                return true;
            }
        }
        
        // 跳过带文件扩展名的请求
        if (preg_match('/\.(js|css|jpg|jpeg|png|gif|svg|ico|woff|woff2|ttf|eot|pdf|zip|xml|txt|html)$/i', $path)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 从路径中提取 handle（先 URL 解码再处理）
     */
    private static function extractHandle(string $path): string
    {
        $path = rawurldecode($path);
        $handle = trim($path, '/');
        
        if (empty($handle)) {
            return '';
        }
        
        if (str_contains($handle, '?')) {
            $handle = substr($handle, 0, strpos($handle, '?'));
        }
        
        $handle = str_replace('/', '-', $handle);
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $handle)) {
            return '';
        }
        
        return $handle;
    }
    
    /**
     * 检查当前站点下 handle 是否存在于数据库（同一站点内 handle 唯一，无站点时 website_id=0）
     *
     * @param string $handle 页面句柄
     * @param int $websiteId 站点ID，无站点时为 0
     * @param bool $isPreview 是否为预览模式
     * @return bool
     */
    private static function handleExists(string $handle, int $websiteId = 0, bool $isPreview = false): bool
    {
        $cacheKey = $websiteId . '_' . $handle . ($isPreview ? '_preview' : '');
        
        if (isset(self::$handleCache[$cacheKey])) {
            return self::$handleCache[$cacheKey];
        }
        
        try {
            /** @var Page $pageModel */
            $pageModel = ObjectManager::getInstance(Page::class);
            $page = clone $pageModel;
            
            if ($isPreview) {
                $page->clear()
                    ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(Page::schema_fields_HANDLE, $handle)
                    ->find()
                    ->fetch();
            } else {
                $page->clear()
                    ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(Page::schema_fields_HANDLE, $handle)
                    ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
                    ->find()
                    ->fetch();
                
                if (!$page->getId()) {
                    $page->clear()
                        ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
                        ->where(Page::schema_fields_HANDLE, $handle)
                        ->where(Page::schema_fields_TYPE, 'test_page')
                        ->find()
                        ->fetch();
                }
            }
            
            $exists = (bool)$page->getId();
            self::$handleCache[$cacheKey] = $exists;
            return $exists;
        } catch (\Exception $e) {
            if (defined('DEV') && DEV) {
                w_log_error('PageBuilder Router Error: ' . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * 按 handle 查找任意站点下的页面，返回其 website_id（用于当前无站点ID时的兼容解析）
     *
     * @param string $handle 页面句柄
     * @param bool $isPreview 是否预览模式
     * @return int|null 站点ID，未找到返回 null
     */
    private static function findWebsiteIdByHandle(string $handle, bool $isPreview = false): ?int
    {
        try {
            /** @var Page $pageModel */
            $pageModel = ObjectManager::getInstance(Page::class);
            $page = clone $pageModel;
            $page->clear()
                ->where(Page::schema_fields_HANDLE, $handle);
            if (!$isPreview) {
                $page->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED);
            }
            $page->find()->fetch();
            if ($page->getId()) {
                $wid = (int)$page->getData(Page::schema_fields_WEBSITE_ID);
                return $wid;
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 按请求 host 解析站点 ID（用于域名根访问首页时无站点上下文的情况）
     * host 可能带端口，匹配时去掉端口以便与站点 url 对齐
     */
    private static function resolveWebsiteIdFromCurrentHost(): ?int
    {
        foreach (self::currentHostCandidates() as $host) {
            $websiteId = self::findWebsiteIdByHost($host);
            if ($websiteId !== null && $websiteId > 0) {
                WelineEnv::setServer('WELINE_WEBSITE_ID', (string)$websiteId, 'PageBuilder current host website');
                return $websiteId;
            }
        }

        return null;
    }

    /**
     * WLS 测试入口会通过 127.0.0.1:port 代理访问，真实发布域名可能只保留在原始 Host 或完整 URL 上。
     *
     * @return list<string>
     */
    private static function currentHostCandidates(): array
    {
        $candidates = [
            WelineEnv::get('server.http_host', ''),
            WelineEnv::get('http_weline_original_host', ''),
            WelineEnv::get('http_x_forwarded_host', ''),
            WelineEnv::server('HTTP_HOST', ''),
            WelineEnv::server('HTTP_WELINE_ORIGINAL_HOST', ''),
            WelineEnv::server('HTTP_X_FORWARDED_HOST', ''),
            WelineEnv::server('SERVER_NAME', ''),
            WelineEnv::server('WELINE_WEBSITE_URL', ''),
            WelineEnv::server('WELINE_FULL_REQUEST_URI', ''),
            WelineEnv::get('website_url', ''),
            WelineEnv::get('full_request_uri', ''),
        ];

        $hosts = [];
        foreach ($candidates as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $candidate = \trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            foreach (\explode(',', $candidate) as $part) {
                $host = self::normalizeHostCandidate($part);
                if ($host !== '') {
                    $hosts[$host] = $host;
                }
            }
        }

        return \array_values($hosts);
    }

    private static function normalizeHostCandidate(string $host): string
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return '';
        }

        if (\str_contains($host, '://')) {
            $parsedHost = \parse_url($host, \PHP_URL_HOST);
            if (\is_string($parsedHost) && $parsedHost !== '') {
                $host = $parsedHost;
            }
        } else {
            $host = \preg_replace('#^//#', '', $host) ?? $host;
            $host = \explode('/', $host, 2)[0] ?? $host;
        }

        $host = \trim($host);
        if ($host === '' || $host === 'localhost' || \filter_var($host, \FILTER_VALIDATE_IP)) {
            return '';
        }

        return (string)(\preg_replace('/:\d+$/', '', $host) ?? $host);
    }

    private static function findWebsiteIdByHost(string $host): ?int
    {
        if ($host === '') {
            return null;
        }
        $hostNoPort = \strtolower(\trim((string)(preg_replace('/:\d+$/', '', $host) ?? $host)));
        if ($hostNoPort === '') {
            return null;
        }
        try {
            $domainModel = ObjectManager::getInstance(\Weline\Websites\Model\WebsiteDomain::class);
            $domain = clone $domainModel;
            $domain->clearData()->clearQuery()->loadByDomain($hostNoPort);
            if ((int)$domain->getData(\Weline\Websites\Model\WebsiteDomain::schema_fields_ID) > 0
                && (string)$domain->getData(\Weline\Websites\Model\WebsiteDomain::schema_fields_STATUS) === \Weline\Websites\Model\WebsiteDomain::STATUS_ACTIVE) {
                $websiteId = (int)$domain->getData(\Weline\Websites\Model\WebsiteDomain::schema_fields_WEBSITE_ID);
                if ($websiteId > 0) {
                    return $websiteId;
                }
            }

            $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $website = clone $websiteModel;
            $website->clear()
                ->where(\Weline\Websites\Model\Website::schema_fields_URL, "%{$hostNoPort}%", 'like')
                ->find()
                ->fetch();
            if ($website->getId()) {
                return (int)$website->getData(\Weline\Websites\Model\Website::schema_fields_ID);
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 获取当前站点ID
     * 
     * @return int
     */
    private static function getCurrentWebsiteId(): int
    {
        try {
            $websiteData = \Weline\Websites\Data\WebsiteData::getWebsiteId();
            if ($websiteData !== null) {
                return (int)$websiteData;
            }
            $websiteId = \Weline\UrlManager\Model\UrlRewrite::getCurrentWebsiteId();
            if ($websiteId > 0) {
                return $websiteId;
            }
            return (int)(WelineEnv::getWebsiteId() ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 获取指定站点的首页 handle（可为空，同站首页用域名即可）
     *
     * @param int $websiteId 站点ID
     * @return string|null 首页 handle，无首页返回 null
     */
    private static function getHomePageHandle(int $websiteId): ?string
    {
        try {
            /** @var Page $pageModel */
            $pageModel = ObjectManager::getInstance(Page::class);
            $page = clone $pageModel;
            $page->clear()
                ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Page::schema_fields_TYPE, Page::TYPE_HOME)
                ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
                ->find()
                ->fetch();
            if ($page->getId()) {
                $h = $page->getData(Page::schema_fields_HANDLE);
                return $h !== null && $h !== '' ? (string)$h : '';
            }
            if ($websiteId !== 0) {
                $page->clear()
                    ->where(Page::schema_fields_WEBSITE_ID, 0)
                    ->where(Page::schema_fields_TYPE, Page::TYPE_HOME)
                    ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
                    ->find()
                    ->fetch();
                if ($page->getId()) {
                    $h = $page->getData(Page::schema_fields_HANDLE);
                    return $h !== null && $h !== '' ? (string)$h : '';
                }
            }
            return null;
        } catch (\Exception $e) {
            if (defined('DEV') && DEV) {
                w_log_error('PageBuilder Router Error (getHomePageHandle): ' . $e->getMessage());
            }
            return null;
        }
    }
    
    /**
     * 清理缓存（用于测试或手动清理）
     */
    public static function clearCache(): void
    {
        self::$handleCache = [];
    }
}

