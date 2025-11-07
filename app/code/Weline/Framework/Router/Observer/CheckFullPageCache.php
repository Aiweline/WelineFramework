<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Cache\RouterCache;

/**
 * 检查全页缓存 Observer
 * 在 App::run_before 事件后立即检查全页缓存，如果存在则直接返回，避免运行到更深的位置
 * 
 * 注意：此 Observer 在 URL 解析之前执行，使用原始 REQUEST_URI
 */
class CheckFullPageCache implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 只在生产环境且非 CLI 模式下检查
        if (CLI || !PROD) {
            return;
        }
        
        // 检查全页缓存是否启用（检查 router_cache 和 frontend_cache 配置）
        // 使用静态方法 Env::get()，使用点号分隔符访问嵌套配置
        $routerCacheEnabled = Env::get('cache.status.router_cache', 1);
        $frontendCacheEnabled = Env::get('cache.status.frontend_cache', 1);
        if (!$routerCacheEnabled || !$frontendCacheEnabled) {
            return;
        }

        // 只处理 GET 请求
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($requestMethod !== 'GET') {
            return;
        }

        // 检查是否是后端请求（后端请求不缓存）
        // 优先使用全局变量 WELINE_IS_BACKEND（在 App.php 的 URL 解析阶段已设置）
        if (isset($_SERVER['WELINE_IS_BACKEND']) && $_SERVER['WELINE_IS_BACKEND']) {
            return;
        }
        
        // 如果 WELINE_IS_BACKEND 还未设置（URL 解析之前），通过原始 REQUEST_URI 进行简单判断
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $backendArea = Env::get('admin') ?: 'admin';
        if (str_starts_with($requestUri, '/' . $backendArea . '/') || str_starts_with($requestUri, '/' . $backendArea)) {
            return;
        }

        // 检查 URL 是否已经解析完成
        // 如果 WELINE_IS_BACKEND 未设置，说明 URL 解析未完成，此时无法生成正确的缓存键
        // 应该跳过检查，等待 App::url_parsed_after 事件触发
        if (!isset($_SERVER['WELINE_IS_BACKEND'])) {
            // URL 解析未完成，无法生成正确的缓存键，直接返回
            // 等待 App::url_parsed_after 事件触发时再检查
            return;
        }
        
        // 获取 Request 实例以生成缓存键
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $method = $request->getMethod() ?: 'GET';
        } catch (\Exception $e) {
            // 如果 Request 还未初始化，无法生成正确的缓存键，直接返回
            return;
        }

        // 直接使用 WELINE_FULL_REQUEST_URI 构建全页缓存键（包含协议、域名、端口、路径、查询参数等完整信息）
        // 不需要规范化，不需要对比域名，WELINE_FULL_REQUEST_URI 已经包含了所有必要信息
        $unifiedCacheKey = RouterCache::buildUnifiedRequestCacheKey('', $method, $request);

        // 获取统一缓存实例
        $cache = ObjectManager::getInstance(RouterCache::class . 'Factory');
        $unifiedCache = $cache->get($unifiedCacheKey);
        // 如果存在统一缓存且包含全页缓存，直接输出并退出
        if (is_array($unifiedCache) && isset($unifiedCache[RouterCache::UNIFIED_CACHE_FPC_KEY]) && !empty($unifiedCache[RouterCache::UNIFIED_CACHE_FPC_KEY])) {
            // 恢复响应头（先清除已存在的响应头，避免重复）
            if (isset($unifiedCache[RouterCache::UNIFIED_CACHE_HEADERS_KEY]) && is_array($unifiedCache[RouterCache::UNIFIED_CACHE_HEADERS_KEY]) && !headers_sent()) {
                foreach ($unifiedCache[RouterCache::UNIFIED_CACHE_HEADERS_KEY] as $header) {
                    // 解析响应头名称
                    if (str_contains($header, ':')) {
                        $headerName = trim(explode(':', $header, 2)[0]);
                        // 先移除已存在的同名响应头，避免重复
                        header_remove($headerName);
                    }
                    // 设置响应头
                    header($header, true); // true 表示替换已存在的同名 header
                }
            }
            // 添加缓存命中标志 header（使用框架独有的标识）
            header('X-Weline-FPC: HIT');
            echo $unifiedCache[RouterCache::UNIFIED_CACHE_FPC_KEY];
            exit(0);
        }
    }
}

