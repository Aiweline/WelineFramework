<?php
declare(strict_types=1);

/**
 * Weline Server - 性能优化指南控制器
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Server\Service\OptimizationGuideService;

/**
 * OptimizationGuide - 性能优化指南
 * 
 * 动态生成基于当前 PHP 环境的优化文档
 * 
 * 安全策略：
 * - 仅允许后台 AJAX 本地请求获取文档内容
 * - 外网访问被拒绝
 * - 需要后台登录验证
 */
class OptimizationGuide extends BackendController
{
    /**
     * 优化指南服务
     */
    private OptimizationGuideService $guideService;
    
    /**
     * 构造函数
     */
    public function __construct(OptimizationGuideService $guideService)
    {
        $this->guideService = $guideService;
    }
    
    /**
     * 优化指南首页（容器页面，通过 AJAX 加载内容）
     * 
     * @return string
     */
    public function getIndex(): string
    {
        // 后台控制器自动验证登录
        // 这里只渲染容器页面，实际内容通过 AJAX 加载
        $this->assign('title', __('Weline Server 性能优化指南'));
        return $this->fetch('index');
    }
    
    /**
     * API: 获取优化数据（仅限本地 AJAX 请求）
     */
    public function getApiData(): array
    {
        // 验证本地访问
        if (!$this->validateLocalAccess()) {
            \http_response_code(403);
            return [
                'success' => false,
                'error' => __('访问被拒绝：仅允许本地请求'),
                'code' => 403,
            ];
        }
        
        // 验证是否为 AJAX 请求
        if (!$this->isAjaxRequest()) {
            \http_response_code(400);
            return [
                'success' => false,
                'error' => __('仅支持 AJAX 请求'),
                'code' => 400,
            ];
        }
        
        return [
            'success' => true,
            'php_info' => $this->guideService->getPhpInfo(),
            'summary' => $this->guideService->getOptimizationSummary(),
            'server_status' => $this->guideService->getServerStatus(),
            'is_windows' => \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN',
            'timestamp' => \time(),
        ];
    }
    
    /**
     * 验证本地访问
     */
    protected function validateLocalAccess(): bool
    {
        // CLI 模式始终允许
        if (PHP_SAPI === 'cli') {
            return true;
        }
        
        return OptimizationGuideService::isLocalAccess();
    }
    
    /**
     * 检查是否为 AJAX 请求
     */
    protected function isAjaxRequest(): bool
    {
        // 检查 X-Requested-With 头
        $requestedWith = \w_env('http_x_requested_with', '');
        if (\strtolower($requestedWith) === 'xmlhttprequest') {
            return true;
        }

        // 检查 Accept 头是否包含 JSON
        $accept = \w_env('server.accept', '');
        if (\str_contains($accept, 'application/json')) {
            return true;
        }

        // 检查 Content-Type
        $contentType = \w_env('server.content_type', '');
        if (\str_contains($contentType, 'application/json')) {
            return true;
        }

        return false;
    }
}
