<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller;

use GuoLaiRen\PageBuilder\Model\Page;
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
        // 1. 跳过已经匹配的路由
        if (!empty($rule['module'])) {
            return;
        }
        
        // 2. 跳过系统路由和媒体文件
        if (self::isSystemPath($path)) {
            return;
        }
        
        // 3. 处理空路径：如果路径为空，检查当前站点是否有首页页面
        $trimmedPath = trim($path, '/');
        if (empty($trimmedPath)) {
            // 获取当前站点ID
            $websiteId = self::getCurrentWebsiteId();
            
            if ($websiteId > 0) {
                // 查询当前站点的首页页面（handle不为空）
                $homePageHandle = self::getHomePageHandle($websiteId);
                
                if (!empty($homePageHandle)) {
                    // 如果找到首页handle，重定向到该首页
                    $path = '/pagebuilder/frontend/page/view';
                    $rule['module'] = 'GuoLaiRen_PageBuilder';
                    $rule['handle'] = $homePageHandle;
                    $_GET['handle'] = $homePageHandle;
                    return;
                }
            }
            
            // 如果没有找到首页，不处理，让其他路由处理
            return;
        }
        
        // 4. 清理路径，提取可能的handle
        $handle = self::extractHandle($path);
        
        if (empty($handle)) {
            return;
        }
        
        // 5. 检查当前站点下 handle 是否存在（同一站点内唯一）
        $websiteId = self::getCurrentWebsiteId();
        $isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';
        if (!self::handleExists($handle, $websiteId, $isPreview)) {
            return;
        }
        
        // 6. 重写路由到页面查看控制器
        $path = '/pagebuilder/frontend/page/view';
        // 设置路由参数
        $rule['module'] = 'GuoLaiRen_PageBuilder';
        $rule['handle'] = $handle;
        
        // 将handle参数写入$_GET，确保控制器能接收到
        $_GET['handle'] = $handle;
        
        // 保留原始URL参数（如 preview、locale 等）
        // $_GET中的参数会自动保留，无需特殊处理
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
     * 从路径中提取handle
     */
    private static function extractHandle(string $path): string
    {
        // 移除开头和结尾的斜杠
        $handle = trim($path, '/');
        
        // 空路径不处理
        if (empty($handle)) {
            return '';
        }
        
        // 移除查询字符串（如果有）
        if (str_contains($handle, '?')) {
            $handle = substr($handle, 0, strpos($handle, '?'));
        }
        
        // 替换斜杠为连字符（支持多级路径）
        // 例如：/products/new -> products-new
        $handle = str_replace('/', '-', $handle);
        
        // 验证handle格式（只允许字母、数字、连字符、下划线）
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
     * 获取当前站点ID
     * 
     * @return int
     */
    private static function getCurrentWebsiteId(): int
    {
        try {
            // 优先从WebsiteData获取
            $websiteData = \Weline\Websites\Data\WebsiteData::getWebsiteId();
            if ($websiteData !== null) {
                return (int)$websiteData;
            }
            
            // 如果WebsiteData没有，尝试从UrlRewrite获取
            $websiteId = \Weline\UrlManager\Model\UrlRewrite::getCurrentWebsiteId();
            if ($websiteId > 0) {
                return $websiteId;
            }
            
            // 最后尝试从$_SERVER获取
            return (int)($_SERVER['WELINE_WEBSITE_ID'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * 获取指定站点的首页handle
     * 
     * @param int $websiteId 站点ID
     * @return string|null 首页handle，如果不存在或handle为空则返回null
     */
    private static function getHomePageHandle(int $websiteId): ?string
    {
        try {
            /** @var Page $pageModel */
            $pageModel = ObjectManager::getInstance(Page::class);
            $page = clone $pageModel;
            
            // 查询当前站点的首页页面（已发布状态）
            $page->clear()
                ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Page::schema_fields_TYPE, Page::TYPE_HOME)
                ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
                ->where(Page::schema_fields_HANDLE, '', '!=')
                ->find()
                ->fetch();
            
            // 如果找到首页且handle不为空，返回handle
            if ($page->getId()) {
                $handle = $page->getData(Page::schema_fields_HANDLE);
                if (!empty($handle)) {
                    return $handle;
                }
            }
            
            // 如果当前站点没有首页，尝试查找全局首页（website_id = 0）
            if ($websiteId !== 0) {
                $page->clear()
                    ->where(Page::schema_fields_WEBSITE_ID, 0)
                    ->where(Page::schema_fields_TYPE, Page::TYPE_HOME)
                    ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
                    ->where(Page::schema_fields_HANDLE, '', '!=')
                    ->find()
                    ->fetch();
                
                if ($page->getId()) {
                    $handle = $page->getData(Page::schema_fields_HANDLE);
                    if (!empty($handle)) {
                        return $handle;
                    }
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

