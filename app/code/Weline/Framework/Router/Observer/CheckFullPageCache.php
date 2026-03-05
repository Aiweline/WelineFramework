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
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * 检查全页缓存 Observer
 * 在 Weline_Framework::App::run_before 事件后立即检查全页缓存，如果存在则直接返回，避免运行到更深的位置
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
        
        // 编辑器预览模式不缓存（editor_mode=1 的 iframe 请求）
        if (isset($_GET['editor_mode']) && ($_GET['editor_mode'] === '1' || $_GET['editor_mode'] === 'true')) {
            return;
        }

        // 检查 URL 是否已经解析完成
        // 使用 WELINE_URL_PARSED 标志判断，这是最可靠的方式
        // 在 WLS 模式下，GlobalsEmulator 不再设置 WELINE_IS_BACKEND，而是使用 WELINE_URL_PARSED
        if (!isset($_SERVER['WELINE_URL_PARSED']) || !$_SERVER['WELINE_URL_PARSED']) {
            // URL 解析未完成，无法生成正确的缓存键，直接返回
            // 等待 Weline_Framework::App::url_parsed_after 事件触发时再检查
            return;
        }
        
        // 检查是否是后端请求（后端请求不缓存）
        // 此时 URL 已解析完成，WELINE_IS_BACKEND 已正确设置
        if (isset($_SERVER['WELINE_IS_BACKEND']) && $_SERVER['WELINE_IS_BACKEND']) {
            return;
        }
        
        // 备用检查：通过原始 REQUEST_URI 判断是否是后端请求
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $backendPrefix = Env::getAreaRoutePrefix('backend') ?: 'admin';
        if (str_starts_with($requestUri, '/' . $backendPrefix . '/') || str_starts_with($requestUri, '/' . $backendPrefix)) {
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

        // 读前校验：fullUri 无效时跳过 FPC，避免命中错误缓存导致串台
        $fullUri = $_SERVER['WELINE_FULL_REQUEST_URI'] ?? '';
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return;
        }

        // 直接使用 WELINE_FULL_REQUEST_URI 构建全页缓存键（包含协议、域名、端口、路径、查询参数等完整信息）
        // 不需要规范化，不需要对比域名，WELINE_FULL_REQUEST_URI 已经包含了所有必要信息
        $unifiedCacheKey = KeyBuilder::buildUnifiedRequestCacheKey('', $method);

        // 获取路由缓存池
        $cacheManager = ObjectManager::getInstance(CacheManager::class);
        $cache = $cacheManager->pool('router');
        $unifiedCache = $cache->get($unifiedCacheKey);
        
        // 如果存在统一缓存且包含全页缓存，直接输出并退出
        if (is_array($unifiedCache) && isset($unifiedCache[KeyBuilder::UNIFIED_CACHE_FPC_KEY]) && !empty($unifiedCache[KeyBuilder::UNIFIED_CACHE_FPC_KEY])) {
            // 收集响应头
            $headers = ['X-Weline-FPC' => 'HIT'];
            if (isset($unifiedCache[KeyBuilder::UNIFIED_CACHE_HEADERS_KEY]) && is_array($unifiedCache[KeyBuilder::UNIFIED_CACHE_HEADERS_KEY])) {
                foreach ($unifiedCache[KeyBuilder::UNIFIED_CACHE_HEADERS_KEY] as $header) {
                    if (str_contains($header, ':')) {
                        [$headerName, $headerValue] = explode(':', $header, 2);
                        $headers[trim($headerName)] = trim($headerValue);
                    }
                }
            }
            // 使用 ResponseTerminateException 替代 exit()，由 Runtime 层统一处理
            throw new \Weline\Framework\Http\ResponseTerminateException(
                200,
                $unifiedCache[KeyBuilder::UNIFIED_CACHE_FPC_KEY],
                $headers
            );
        }
    }
}

