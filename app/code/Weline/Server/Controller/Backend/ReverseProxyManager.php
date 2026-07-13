<?php
declare(strict_types=1);

/**
 * Weline Server - 反向代理后台管理控制器
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Server\Model\ReverseProxy;
use Weline\Server\Service\Control\IpcControlGateway;

class ReverseProxyManager extends BackendController
{
    protected ReverseProxy $proxyModel;
    protected IpcControlGateway $ipcGateway;

    public function __construct(
        ReverseProxy $proxyModel,
        IpcControlGateway $ipcGateway
    ) {
        $this->proxyModel = $proxyModel;
        $this->ipcGateway = $ipcGateway;
    }

    /**
     * 列表页面
     */
    public function getIndex(): string
    {
        // 获取所有代理规则
        $proxies = $this->proxyModel->getAllRules();

        // 统计信息
        $stats = [
            'total' => count($proxies),
            'active' => 0,
            'inactive' => 0,
        ];

        foreach ($proxies as $proxy) {
            if ($proxy[ReverseProxy::schema_fields_STATUS] === ReverseProxy::STATUS_ACTIVE) {
                $stats['active']++;
            } else {
                $stats['inactive']++;
            }
        }

        $this->assign('proxies', $proxies);
        $this->assign('stats', $stats);
        $this->assign('title', __('反向代理配置'));

        return $this->fetch('Weline_Server::templates/Backend/ReverseProxy/index.phtml');
    }

    /**
     * 获取列表（AJAX）
     */
    public function getList(): string
    {
        $proxies = $this->proxyModel->getAllRules();
        return $this->fetchJson(['success' => true, 'data' => $proxies]);
    }

    /**
     * 编辑页面
     */
    public function getEdit(): string
    {
        $proxyId = (int) $this->request->getGet('proxy_id', 0);

        $proxy = null;
        if ($proxyId > 0) {
            $proxy = $this->proxyModel->clearQuery()
                ->where(ReverseProxy::schema_fields_ID, $proxyId)
                ->find()
                ->fetch();

            if (!$proxy->getData(ReverseProxy::schema_fields_ID)) {
                $this->assign('error', __('代理规则不存在'));
                return $this->fetch('Weline_Server::templates/Backend/ReverseProxy/edit.phtml');
            }
        }

        $this->assign('proxy', $proxy);
        $this->assign('title', $proxyId > 0 ? __('编辑代理规则') : __('新建代理规则'));

        return $this->fetch('Weline_Server::templates/Backend/ReverseProxy/edit.phtml');
    }

    /**
     * 保存配置
     */
    public function postSave(): string
    {
        $proxyId = (int) $this->request->getPost('proxy_id', 0);
        $domain = trim((string) $this->request->getPost('domain'));
        $backendHost = trim((string) $this->request->getPost('backend_host'));
        $backendPort = (int) $this->request->getPost('backend_port', 0);
        $backendSsl = (int) $this->request->getPost('backend_ssl', 1);
        $priority = (int) $this->request->getPost('priority', 0);
        $status = trim((string) $this->request->getPost('status', ReverseProxy::STATUS_ACTIVE));
        $description = trim((string) $this->request->getPost('description', ''));

        // 验证必填字段
        if (empty($domain)) {
            return $this->fetchJson(['success' => false, 'message' => __('请输入域名')]);
        }
        if (empty($backendHost)) {
            return $this->fetchJson(['success' => false, 'message' => __('请输入后端主机')]);
        }
        if ($backendPort < 1 || $backendPort > 65535) {
            return $this->fetchJson(['success' => false, 'message' => __('端口必须在 1-65535 范围内')]);
        }

        try {
            // 加载或创建模型
            if ($proxyId > 0) {
                $proxy = $this->proxyModel->clearQuery()
                    ->where(ReverseProxy::schema_fields_ID, $proxyId)
                    ->find()
                    ->fetch();

                if (!$proxy->getData(ReverseProxy::schema_fields_ID)) {
                    return $this->fetchJson(['success' => false, 'message' => __('代理规则不存在')]);
                }
            } else {
                $proxy = $this->proxyModel->clearQuery();
            }

            // 设置数据
            $proxy->setData(ReverseProxy::schema_fields_DOMAIN, strtolower($domain));
            $proxy->setData(ReverseProxy::schema_fields_BACKEND_HOST, $backendHost);
            $proxy->setData(ReverseProxy::schema_fields_BACKEND_PORT, $backendPort);
            $proxy->setData(ReverseProxy::schema_fields_BACKEND_SSL, $backendSsl);
            $proxy->setData(ReverseProxy::schema_fields_PRIORITY, $priority);
            $proxy->setData(ReverseProxy::schema_fields_STATUS, $status);
            $proxy->setData(ReverseProxy::schema_fields_DESCRIPTION, $description);

            // 保存
            $proxy->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('保存成功'),
                'data' => [
                    'proxy_id' => $proxy->getData(ReverseProxy::schema_fields_ID),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 删除配置
     */
    public function postDelete(): string
    {
        $proxyId = (int) $this->request->getPost('proxy_id', 0);

        if ($proxyId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('无效的代理ID')]);
        }

        try {
            $proxy = $this->proxyModel->clearQuery()
                ->where(ReverseProxy::schema_fields_ID, $proxyId)
                ->find()
                ->fetch();

            if (!$proxy->getData(ReverseProxy::schema_fields_ID)) {
                return $this->fetchJson(['success' => false, 'message' => __('代理规则不存在')]);
            }

            $proxy->delete();

            return $this->fetchJson(['success' => true, 'message' => __('删除成功')]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 切换状态
     */
    public function postToggleStatus(): string
    {
        $proxyId = (int) $this->request->getPost('proxy_id', 0);
        $status = trim((string) $this->request->getPost('status'));

        if ($proxyId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('无效的代理ID')]);
        }

        if (!in_array($status, [ReverseProxy::STATUS_ACTIVE, ReverseProxy::STATUS_INACTIVE])) {
            return $this->fetchJson(['success' => false, 'message' => __('无效的状态')]);
        }

        try {
            $success = $this->proxyModel->toggleStatus($proxyId, $status);

            if ($success) {
                return $this->fetchJson(['success' => true, 'message' => __('状态已更新')]);
            } else {
                return $this->fetchJson(['success' => false, 'message' => __('代理规则不存在')]);
            }
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 应用配置到 Gateway
     */
    public function postApply(): string
    {
        try {
            // 读取所有 active 规则
            $routes = $this->proxyModel->getActiveRules();

            if (empty($routes)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('没有启用的代理规则'),
                ]);
            }

            // 构建路由数组
            $routesData = [];
            foreach ($routes as $route) {
                $routesData[] = [
                    'domain' => $route[ReverseProxy::schema_fields_DOMAIN],
                    'backend_host' => $route[ReverseProxy::schema_fields_BACKEND_HOST],
                    'backend_port' => (int) $route[ReverseProxy::schema_fields_BACKEND_PORT],
                    'backend_ssl' => (bool) $route[ReverseProxy::schema_fields_BACKEND_SSL],
                    'priority' => (int) $route[ReverseProxy::schema_fields_PRIORITY],
                ];
            }

            // 通过 IPC 发送到 Master
            $result = $this->ipcGateway->proxyApply($this->resolveControlInstance(), $routesData);

            if ($result['success'] ?? false) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('配置已应用，共 %{1} 条规则', [count($routes)]),
                    'applied_count' => count($routesData),
                    'gateway_count' => (int)($result['data']['gateways'] ?? 0),
                ]);
            } else {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $result['message'] ?? __('应用配置失败'),
                ]);
            }
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 测试后端连接
     */
    private function resolveControlInstance(): string
    {
        $candidates = [
            $this->request->getPost('instance', ''),
            $_SERVER['WLS_INSTANCE'] ?? null,
            $_SERVER['WLS_INSTANCE_NAME'] ?? null,
            $_ENV['WLS_INSTANCE'] ?? null,
            $_ENV['WLS_INSTANCE_NAME'] ?? null,
            \getenv('WLS_INSTANCE') ?: null,
            \getenv('WLS_INSTANCE_NAME') ?: null,
        ];

        foreach ($candidates as $candidate) {
            $value = \trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return 'default';
    }

    public function postTestConnection(): string
    {
        $backendHost = trim((string) $this->request->getPost('backend_host'));
        $backendPort = (int) $this->request->getPost('backend_port', 0);
        $backendSsl = (bool) $this->request->getPost('backend_ssl', false);

        if (empty($backendHost)) {
            return $this->fetchJson(['success' => false, 'message' => __('请输入后端主机')]);
        }
        if ($backendPort < 1 || $backendPort > 65535) {
            return $this->fetchJson(['success' => false, 'message' => __('端口必须在 1-65535 范围内')]);
        }

        $startTime = microtime(true);

        // 尝试建立 TCP 连接
        $conn = @stream_socket_client(
            "tcp://{$backendHost}:{$backendPort}",
            $errno,
            $errstr,
            5
        );

        $latencyMs = round((microtime(true) - $startTime) * 1000, 2);

        if ($conn) {
            fclose($conn);
            return $this->fetchJson([
                'success' => true,
                'message' => __('连接成功'),
                'latency_ms' => $latencyMs,
            ]);
        } else {
            return $this->fetchJson([
                'success' => false,
                'message' => __('连接失败: %{1}', [$errstr]),
                'latency_ms' => $latencyMs,
            ]);
        }
    }
}
