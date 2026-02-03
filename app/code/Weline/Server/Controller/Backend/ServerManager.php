<?php
declare(strict_types=1);

/**
 * Weline Server - 服务器管理控制器
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
use Weline\Server\Service\OptimizationGuideService;

/**
 * ServerManager - 服务器管理
 * 
 * 管理 Weline Server 实例
 * 
 * 安全策略：
 * - 仅允许后台本地访问
 * - 需要后台登录验证
 */
class ServerManager extends BackendController
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
     * 服务器管理首页（容器页面）
     */
    public function getIndex(): string
    {
        // 后台控制器自动验证登录
        return $this->fetch();
    }
    
    /**
     * API: 获取服务器状态（仅限本地 AJAX 请求）
     */
    public function getStatus(): array
    {
        // 验证本地访问
        if (!$this->validateLocalAccess()) {
            \http_response_code(403);
            return [
                'success' => false,
                'error' => __('访问被拒绝：仅允许本地请求'),
            ];
        }
        
        return [
            'success' => true,
            'servers' => $this->guideService->getServerStatus(),
            'summary' => $this->guideService->getOptimizationSummary(),
            'php_info' => $this->guideService->getPhpInfo(),
            'timestamp' => \time(),
        ];
    }
    
    /**
     * 验证本地访问
     */
    protected function validateLocalAccess(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }
        return OptimizationGuideService::isLocalAccess();
    }
    
    /**
     * API: 启动服务器
     */
    public function postStart(): array
    {
        $instance = $this->request->getPost('instance', 'default');
        $workers = (int)$this->request->getPost('workers', 0);
        
        // 构建命令
        $command = PHP_BINARY . ' ' . BP . 'bin/w server:start ' . \escapeshellarg($instance);
        if ($workers > 0) {
            $command .= ' -c ' . $workers;
        }
        
        // 在后台执行
        if (\strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN') {
            \pclose(\popen("start /B {$command}", 'r'));
        } else {
            \exec("{$command} > /dev/null 2>&1 &");
        }
        
        \usleep(500000); // 等待 500ms
        
        return [
            'success' => true,
            'message' => __('服务器启动命令已发送'),
            'servers' => $this->guideService->getServerStatus(),
        ];
    }
    
    /**
     * API: 停止服务器
     */
    public function postStop(): array
    {
        $instance = $this->request->getPost('instance', 'default');
        
        // 构建命令
        $command = PHP_BINARY . ' ' . BP . 'bin/w server:stop ' . \escapeshellarg($instance);
        
        // 执行
        $output = [];
        \exec($command, $output, $exitCode);
        
        return [
            'success' => $exitCode === 0,
            'message' => $exitCode === 0 ? __('服务器已停止') : __('停止失败'),
            'output' => \implode("\n", $output),
            'servers' => $this->guideService->getServerStatus(),
        ];
    }
    
    /**
     * API: 重启服务器
     */
    public function postRestart(): array
    {
        $instance = $this->request->getPost('instance', 'default');
        
        // 先停止
        $stopResult = $this->postStop();
        
        \usleep(500000); // 等待 500ms
        
        // 再启动
        $_POST['instance'] = $instance;
        $startResult = $this->postStart();
        
        return [
            'success' => $startResult['success'],
            'message' => __('服务器已重启'),
            'servers' => $this->guideService->getServerStatus(),
        ];
    }
}
