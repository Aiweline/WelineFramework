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
use Weline\Server\Service\Control\SharedStateAdminService;

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
    private SharedStateAdminService $sharedStateAdminService;
    
    /**
     * 构造函数
     */
    public function __construct(
        OptimizationGuideService $guideService,
        BackendStatusService $statusService,
        IpcControlGateway $ipcGateway,
        SharedStateAdminService $sharedStateAdminService
    )
    {
        $this->guideService = $guideService;
        $this->statusService = $statusService;
        $this->ipcGateway = $ipcGateway;
        $this->sharedStateAdminService = $sharedStateAdminService;
    }

    /**
     * ServerManager 数据接口允许本机直连，避免后台页内 AJAX 因登录态抖动被 302 拦截。
     */
    protected function loginCheck(): void
    {
        if ($this->isLocalDataApiRoute() && $this->validateLocalAccess()) {
            return;
        }
        parent::loginCheck();
    }
    
    /**
     * 服务器管理首页（容器页面）
     */
    public function getIndex(): string
    {
        // 后台控制器自动验证登录
        $this->assign('defaultTab', 'overview');
        $this->assign('title', __('Weline Server 管理'));
        return $this->fetch('index');
    }

    public function getSession(): string
    {
        $this->assign('defaultTab', 'session');
        $this->assign('title', __('Session 管理'));
        return $this->fetch('index');
    }

    public function getMemory(): string
    {
        $this->assign('defaultTab', 'memory');
        $this->assign('title', __('内存服务管理'));
        return $this->fetch('index');
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
            'session' => $this->sharedStateAdminService->getSessionOverview(),
            'memory' => $this->sharedStateAdminService->getMemoryOverview(),
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

    private function isLocalDataApiRoute(): bool
    {
        $path = \trim((string)$this->request->getRouteUrlPath(), '/');
        if (!\str_starts_with($path, 'server/backend/server-manager/')) {
            return false;
        }
        $apiActions = [
            'status',
            'session-list',
            'session-destroy',
            'session-persist',
            'session-gc',
            'memory-namespaces',
            'memory-namespace-detail',
            'memory-namespace-clear',
            'memory-key-delete',
            'memory-persist',
            'memory-gc',
        ];
        foreach ($apiActions as $action) {
            if (\str_ends_with($path, '/' . $action)) {
                return true;
            }
        }
        return false;
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

    public function getSessionList(): array
    {
        $limit = (int)$this->request->getGet('limit', 50);
        return [
            'success' => true,
            'message' => (string)__('Session 列表加载完成'),
            'data' => $this->sharedStateAdminService->listSessions([], $limit),
        ];
    }

    public function postSessionDestroy(): array
    {
        $sessionId = (string)$this->request->getPost('session_id', '');
        if ($sessionId === '') {
            return [
                'success' => false,
                'message' => (string)__('缺少 Session ID'),
            ];
        }
        $ok = $this->sharedStateAdminService->destroySession($sessionId);
        return [
            'success' => $ok,
            'message' => $ok
                ? (string)__('Session 已销毁')
                : (string)__('Session 销毁失败，请确认服务可用后重试'),
        ];
    }

    public function postSessionPersist(): array
    {
        $ok = $this->sharedStateAdminService->persistSession();
        return [
            'success' => $ok,
            'message' => $ok
                ? (string)__('Session 数据已持久化')
                : (string)__('Session 持久化失败，请检查 Session 服务状态'),
        ];
    }

    public function postSessionGc(): array
    {
        $maxLifetime = (int)$this->request->getPost('max_lifetime', 3600);
        $ok = $this->sharedStateAdminService->gcSession($maxLifetime);
        return [
            'success' => $ok,
            'message' => $ok
                ? (string)__('Session 垃圾回收已执行')
                : (string)__('Session 垃圾回收执行失败'),
        ];
    }

    public function getMemoryNamespaces(): array
    {
        $limit = (int)$this->request->getGet('limit', 200);
        return [
            'success' => true,
            'message' => (string)__('内存命名空间加载完成'),
            'data' => $this->sharedStateAdminService->listMemoryNamespaces($limit),
        ];
    }

    public function getMemoryNamespaceDetail(): array
    {
        $namespace = (string)$this->request->getGet('namespace', '');
        $limit = (int)$this->request->getGet('limit', 100);
        if ($namespace === '') {
            return [
                'success' => false,
                'message' => (string)__('缺少命名空间参数'),
                'data' => [],
            ];
        }
        return [
            'success' => true,
            'message' => (string)__('命名空间详情加载完成'),
            'data' => $this->sharedStateAdminService->getMemoryNamespaceDetail($namespace, $limit),
        ];
    }

    public function postMemoryNamespaceClear(): array
    {
        $namespace = (string)$this->request->getPost('namespace', '');
        if ($namespace === '') {
            return [
                'success' => false,
                'message' => (string)__('缺少命名空间参数'),
            ];
        }
        $ok = $this->sharedStateAdminService->clearMemoryNamespace($namespace);
        return [
            'success' => $ok,
            'message' => $ok
                ? (string)__('内存命名空间已清空')
                : (string)__('命名空间清理失败，请检查 Memory Service 状态'),
        ];
    }

    public function postMemoryKeyDelete(): array
    {
        $namespace = (string)$this->request->getPost('namespace', '');
        $key = (string)$this->request->getPost('key', '');
        if ($namespace === '' || $key === '') {
            return [
                'success' => false,
                'message' => (string)__('缺少命名空间或键名参数'),
            ];
        }
        $ok = $this->sharedStateAdminService->deleteMemoryKey($namespace, $key);
        return [
            'success' => $ok,
            'message' => $ok
                ? (string)__('缓存键已删除')
                : (string)__('缓存键删除失败'),
        ];
    }

    public function postMemoryPersist(): array
    {
        $ok = $this->sharedStateAdminService->persistMemory();
        return [
            'success' => $ok,
            'message' => $ok
                ? (string)__('内存服务数据已持久化')
                : (string)__('内存服务持久化失败'),
        ];
    }

    public function postMemoryGc(): array
    {
        $maxLifetime = (int)$this->request->getPost('max_lifetime', 3600);
        $ok = $this->sharedStateAdminService->gcMemory($maxLifetime);
        if ($ok) {
            return [
                'success' => true,
                'message' => (string)__('内存服务垃圾回收已执行'),
            ];
        }

        $overview = $this->sharedStateAdminService->getMemoryOverview();
        $probe = \is_array($overview['probe'] ?? null) ? $overview['probe'] : [];
        $detailParts = [];
        if (isset($probe['ping_ok'])) {
            $detailParts[] = 'ping=' . ((bool)$probe['ping_ok'] ? 'ok' : 'fail');
        }
        if (isset($probe['stats_ok'])) {
            $detailParts[] = 'stats=' . ((bool)$probe['stats_ok'] ? 'ok' : 'fail');
        }
        $probeError = (string)($probe['error'] ?? '');
        if ($probeError !== '') {
            $detailParts[] = 'error=' . $probeError;
        }
        $detail = !empty($detailParts) ? (' (' . \implode(', ', $detailParts) . ')') : '';
        return [
            'success' => false,
            'message' => (string)__('内存服务垃圾回收执行失败，请检查 Memory Service 连接状态') . $detail,
        ];
    }

}
