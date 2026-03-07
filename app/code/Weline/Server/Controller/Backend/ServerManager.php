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
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\OptimizationGuideService;
use Weline\Server\Service\Control\BackendStatusService;
use Weline\Server\Service\Control\IpcControlGateway;

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
    private BackendStatusService $statusService;
    private IpcControlGateway $ipcGateway;
    
    /**
     * 构造函数
     */
    public function __construct(
        OptimizationGuideService $guideService,
        BackendStatusService $statusService,
        IpcControlGateway $ipcGateway
    )
    {
        $this->guideService = $guideService;
        $this->statusService = $statusService;
        $this->ipcGateway = $ipcGateway;
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
        
        $instance = (string)$this->request->getGet('instance', 'default');
        $statusDto = $this->statusService->getStatusDto($instance, true);
        return [
            'success' => true,
            'servers' => $this->guideService->getServerStatus(),
            'summary' => $this->guideService->getOptimizationSummary(),
            'php_info' => $this->guideService->getPhpInfo(),
            'orchestrator' => $statusDto,
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
        $instance = (string)$this->request->getPost('instance', 'default');
        $workers = (int)$this->request->getPost('workers', 0);

        $result = $this->ipcGateway->startInstance($instance, $workers);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('启动命令已提交')),
            'servers' => $this->guideService->getServerStatus(),
        ];
    }
    
    /**
     * API: 停止服务器
     */
    public function postStop(): array
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        $result = $this->ipcGateway->command($instance, ControlMessage::ACTION_STOP, '', [], 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('停止命令已发送')),
            'output' => '',
            'servers' => $this->guideService->getServerStatus(),
        ];
    }
    
    /**
     * API: 重启服务器
     */
    public function postRestart(): array
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        // 后台重启语义统一映射到 Orchestrator 强制 reload（控制面统一）
        $result = $this->ipcGateway->command(
            $instance,
            ControlMessage::ACTION_RELOAD,
            ControlMessage::RELOAD_TYPE_FORCE,
            [],
            8.0
        );
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('重启命令已发送')),
            'servers' => $this->guideService->getServerStatus(),
        ];
    }

    public function postReload(): array
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        $result = $this->ipcGateway->command(
            $instance,
            ControlMessage::ACTION_RELOAD,
            ControlMessage::RELOAD_TYPE_CODE,
            [],
            8.0
        );
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('热重载命令已发送')),
            'servers' => $this->guideService->getServerStatus(),
        ];
    }

    public function postMaintenanceEnable(): array
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        $result = $this->ipcGateway->command($instance, ControlMessage::ACTION_MAINTENANCE_ENABLE, '', [], 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('维护模式已启用')),
            'data' => $result['data'] ?? [],
        ];
    }

    public function postMaintenanceDisable(): array
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        $result = $this->ipcGateway->command($instance, ControlMessage::ACTION_MAINTENANCE_DISABLE, '', [], 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('维护模式已禁用')),
            'data' => $result['data'] ?? [],
        ];
    }
}
