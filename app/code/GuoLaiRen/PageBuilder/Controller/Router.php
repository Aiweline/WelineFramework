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
        
        // 3. 清理路径，提取可能的handle
        $handle = self::extractHandle($path);
        
        if (empty($handle)) {
            return;
        }
        
        // 4. 检查handle是否存在（使用缓存避免重复查询）
        if (!self::handleExists($handle)) {
            return;
        }
        
        // 5. 重写路由到页面查看控制器
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
     * 检查handle是否存在于数据库
     */
    private static function handleExists(string $handle): bool
    {
        // 使用静态缓存避免重复查询
        if (isset(self::$handleCache[$handle])) {
            return self::$handleCache[$handle];
        }
        
        try {
            /** @var Page $pageModel */
            $pageModel = ObjectManager::getInstance(Page::class);
            $page = clone $pageModel;
            $page->clear()
                ->where(Page::fields_HANDLE, $handle)
                ->where(Page::fields_STATUS, Page::STATUS_PUBLISHED)
                ->find()
                ->fetch();
            
            $exists = (bool)$page->getId();
            
            // 缓存结果
            self::$handleCache[$handle] = $exists;
            
            return $exists;
        } catch (\Exception $e) {
            // 如果查询失败，记录日志并返回false
            if (defined('DEV') && DEV) {
                error_log('PageBuilder Router Error: ' . $e->getMessage());
            }
            return false;
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

