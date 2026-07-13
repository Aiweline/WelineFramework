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
use Weline\Framework\Acl\Acl;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\OptimizationGuideService;
use Weline\Server\Service\Control\BackendStatusService;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
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
    private BroadcastControlDispatchService $broadcastControlDispatchService;
    private SharedStateAdminService $sharedStateAdminService;
    
    /**
     * 构造函数
     */
    public function __construct(
        OptimizationGuideService $guideService,
        BackendStatusService $statusService,
        IpcControlGateway $ipcGateway,
        BroadcastControlDispatchService $broadcastControlDispatchService,
        SharedStateAdminService $sharedStateAdminService
    )
    {
        $this->guideService = $guideService;
        $this->statusService = $statusService;
        $this->ipcGateway = $ipcGateway;
        $this->broadcastControlDispatchService = $broadcastControlDispatchService;
        $this->sharedStateAdminService = $sharedStateAdminService;
    }

    /**
     * ServerManager 只读数据接口允许本机直连，避免后台页内 AJAX 因登录态抖动被 302 拦截。
     * 破坏性操作必须继续走后台登录与方法级 ACL。
     */
    protected function loginCheck(): void
    {
        if ($this->isLocalReadOnlyDataApiRoute() && $this->validateLocalAccess()) {
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
        
        $instance = $this->getValidatedInstance((string)$this->request->getGet('instance', 'default'));
        if ($instance === null) {
            \http_response_code(400);
            return [
                'success' => false,
                'error' => __('实例名称不合法'),
            ];
        }
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

    private function isLocalReadOnlyDataApiRoute(): bool
    {
        if (!$this->request->isGet()) {
            return false;
        }
        $path = \trim((string)$this->request->getRouteUrlPath(), '/');
        if (!\str_starts_with($path, 'server/backend/server-manager/')) {
            return false;
        }
        $apiActions = [
            'status',
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
    #[Acl('Weline_Server::server_manager_start', '启动 Weline Server', 'mdi-play', '启动 Weline Server 实例', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postStart(): array
    {
        $instance = $this->getValidatedInstance((string)$this->request->getPost('instance', 'default'));
        if ($instance === null) {
            return $this->invalidInputResponse(__('实例名称不合法'));
        }
        $workers = $this->getValidatedInt((string)$this->request->getPost('workers', '0'), 0, 256, 0);

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
    #[Acl('Weline_Server::server_manager_stop', '停止 Weline Server', 'mdi-stop', '停止 Weline Server 实例', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postStop(): array
    {
        $instance = $this->getValidatedInstance((string)$this->request->getPost('instance', 'default'));
        if ($instance === null) {
            return $this->invalidInputResponse(__('实例名称不合法'));
        }
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
    #[Acl('Weline_Server::server_manager_restart', '重启 Weline Server', 'mdi-restart', '强制重启 Weline Server 实例', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postRestart(): array
    {
        $instance = $this->getValidatedInstance((string)$this->request->getPost('instance', 'default'));
        if ($instance === null) {
            return $this->invalidInputResponse(__('实例名称不合法'));
        }
        $result = $this->broadcastControlDispatchService->reloadAsync($instance, ControlMessage::RELOAD_TYPE_FORCE, 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('重启命令已发送')),
            'servers' => $this->guideService->getServerStatus(),
        ];
    }

    #[Acl('Weline_Server::server_manager_reload', '热重载 Weline Server', 'mdi-refresh', '热重载 Weline Server 代码', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postReload(): array
    {
        $instance = $this->getValidatedInstance((string)$this->request->getPost('instance', 'default'));
        if ($instance === null) {
            return $this->invalidInputResponse(__('实例名称不合法'));
        }
        $result = $this->broadcastControlDispatchService->reloadAsync($instance, ControlMessage::RELOAD_TYPE_CODE, 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('热重载命令已发送')),
            'servers' => $this->guideService->getServerStatus(),
        ];
    }

    #[Acl('Weline_Server::server_manager_maintenance', '切换 Weline Server 维护模式', 'mdi-tools', '启用 Weline Server 维护模式', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postMaintenanceEnable(): array
    {
        $instance = $this->getValidatedInstance((string)$this->request->getPost('instance', 'default'));
        if ($instance === null) {
            return $this->invalidInputResponse(__('实例名称不合法'));
        }
        $result = $this->ipcGateway->setMaintenanceMode($instance, true, 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('维护模式已启用')),
            'data' => $result['data'] ?? [],
        ];
    }

    #[Acl('Weline_Server::server_manager_maintenance', '切换 Weline Server 维护模式', 'mdi-tools', '禁用 Weline Server 维护模式', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postMaintenanceDisable(): array
    {
        $instance = $this->getValidatedInstance((string)$this->request->getPost('instance', 'default'));
        if ($instance === null) {
            return $this->invalidInputResponse(__('实例名称不合法'));
        }
        $result = $this->ipcGateway->setMaintenanceMode($instance, false, 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('维护模式已禁用')),
            'data' => $result['data'] ?? [],
        ];
    }

    #[Acl('Weline_Server::server_manager_session_read', '查看 Weline Server Session', 'mdi-list-box-outline', '查看 Weline Server Session 列表', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_READ)]
    public function getSessionList(): array
    {
        $limit = $this->getValidatedInt((string)$this->request->getGet('limit', '50'), 1, 500, 50);
        return [
            'success' => true,
            'message' => (string)__('Session 列表加载完成'),
            'data' => $this->sharedStateAdminService->listSessions([], $limit),
        ];
    }

    #[Acl('Weline_Server::server_manager_session_read', '查看 Weline Server Session', 'mdi-list-box-outline', '查看 Weline Server Session 详情', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_READ)]
    public function getSessionDetail(): array
    {
        $sessionId = $this->getValidatedOpaqueKey((string)$this->request->getGet('session_id', ''), 128);
        if ($sessionId === null) {
            return [
                'success' => false,
                'message' => (string)__('Session ID 参数不合法'),
                'data' => [],
            ];
        }
        $rows = $this->sharedStateAdminService->getSessionDetail($sessionId);
        return [
            'success' => true,
            'message' => (string)__('Session 详情加载完成'),
            'data' => $rows,
        ];
    }

    #[Acl('Weline_Server::server_manager_session_destroy', '销毁 Weline Server Session', 'mdi-delete-outline', '销毁指定 Weline Server Session', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postSessionDestroy(): array
    {
        $sessionId = $this->getValidatedOpaqueKey((string)$this->request->getPost('session_id', ''), 128);
        if ($sessionId === null) {
            return [
                'success' => false,
                'message' => (string)__('Session ID 不合法'),
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

    #[Acl('Weline_Server::server_manager_session_persist', '持久化 Weline Server Session', 'mdi-content-save-outline', '持久化 Weline Server Session 数据', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
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

    #[Acl('Weline_Server::server_manager_session_gc', '回收 Weline Server Session', 'mdi-delete-sweep-outline', '执行 Weline Server Session 垃圾回收', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postSessionGc(): array
    {
        $maxLifetime = $this->getValidatedInt((string)$this->request->getPost('max_lifetime', '3600'), 60, 2592000, 3600);
        $ok = $this->sharedStateAdminService->gcSession($maxLifetime);
        return [
            'success' => $ok,
            'message' => $ok
                ? (string)__('Session 垃圾回收已执行')
                : (string)__('Session 垃圾回收执行失败'),
        ];
    }

    #[Acl('Weline_Server::server_manager_memory_read', '查看 Weline Server 内存服务', 'mdi-database-eye-outline', '查看 Weline Server 内存命名空间', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_READ)]
    public function getMemoryNamespaces(): array
    {
        $limit = $this->getValidatedInt((string)$this->request->getGet('limit', '200'), 1, 1000, 200);
        return [
            'success' => true,
            'message' => (string)__('内存命名空间加载完成'),
            'data' => $this->sharedStateAdminService->listMemoryNamespaces($limit),
        ];
    }

    #[Acl('Weline_Server::server_manager_memory_read', '查看 Weline Server 内存服务', 'mdi-database-eye-outline', '查看 Weline Server 内存命名空间详情', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_READ)]
    public function getMemoryNamespaceDetail(): array
    {
        $namespace = $this->getValidatedOpaqueKey((string)$this->request->getGet('namespace', ''), 160);
        $limit = $this->getValidatedInt((string)$this->request->getGet('limit', '100'), 1, 1000, 100);
        if ($namespace === null) {
            return [
                'success' => false,
                'message' => (string)__('命名空间参数不合法'),
                'data' => [],
            ];
        }
        return [
            'success' => true,
            'message' => (string)__('命名空间详情加载完成'),
            'data' => $this->sharedStateAdminService->getMemoryNamespaceDetail($namespace, $limit),
        ];
    }

    #[Acl('Weline_Server::server_manager_memory_clear', '清理 Weline Server 内存命名空间', 'mdi-broom', '清理 Weline Server 内存命名空间', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postMemoryNamespaceClear(): array
    {
        $namespace = $this->getValidatedOpaqueKey((string)$this->request->getPost('namespace', ''), 160);
        if ($namespace === null) {
            return [
                'success' => false,
                'message' => (string)__('命名空间参数不合法'),
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

    #[Acl('Weline_Server::server_manager_memory_key_delete', '删除 Weline Server 内存键', 'mdi-key-remove-outline', '删除 Weline Server 内存服务键', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postMemoryKeyDelete(): array
    {
        $namespace = $this->getValidatedOpaqueKey((string)$this->request->getPost('namespace', ''), 160);
        $key = $this->getValidatedOpaqueKey((string)$this->request->getPost('key', ''), 255);
        if ($namespace === null || $key === null) {
            return [
                'success' => false,
                'message' => (string)__('命名空间或键名参数不合法'),
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

    #[Acl('Weline_Server::server_manager_memory_persist', '持久化 Weline Server 内存服务', 'mdi-content-save-outline', '持久化 Weline Server 内存服务数据', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
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

    #[Acl('Weline_Server::server_manager_memory_gc', '回收 Weline Server 内存服务', 'mdi-delete-sweep-outline', '执行 Weline Server 内存服务垃圾回收', 'Weline_Backend::system_service_group', accessMode: Acl::ACCESS_MODE_EDIT)]
    public function postMemoryGc(): array
    {
        $maxLifetime = $this->getValidatedInt((string)$this->request->getPost('max_lifetime', '3600'), 60, 2592000, 3600);
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

    private function getValidatedInstance(string $raw): ?string
    {
        $instance = \trim($raw);
        if ($instance === '') {
            $instance = 'default';
        }
        if (\strlen($instance) > 80 || !\preg_match('/^[A-Za-z0-9_.:-]+$/', $instance)) {
            return null;
        }
        if (\str_contains($instance, '..')) {
            return null;
        }
        return $instance;
    }

    private function getValidatedInt(string $raw, int $min, int $max, int $default): int
    {
        $value = \trim($raw);
        if ($value === '' || !\preg_match('/^-?\d+$/', $value)) {
            return $default;
        }
        $number = (int)$value;
        return \max($min, \min($max, $number));
    }

    private function getValidatedOpaqueKey(string $raw, int $maxLength): ?string
    {
        $value = \trim($raw);
        if ($value === '' || \strlen($value) > $maxLength) {
            return null;
        }
        if (\preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return null;
        }
        if (\str_contains($value, '..')
            || \strpbrk($value, "\\/|;&`$<>") !== false
        ) {
            return null;
        }
        return $value;
    }

    private function invalidInputResponse(string $message): array
    {
        \http_response_code(400);
        return [
            'success' => false,
            'message' => (string)$message,
        ];
    }

}
