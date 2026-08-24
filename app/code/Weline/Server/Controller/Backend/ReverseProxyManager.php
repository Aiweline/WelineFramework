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
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel/gateway');
        $this->request->getResponse()->redirect($url);
        return '';
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
        return $this->getIndex();
    }

    /**
     * 保存配置
     */
    public function postSave(): string
    {
        return $this->fetchJson([
            'success' => false,
            'message' => __('WLS 公网边缘固定使用 Nginx；已拒绝非 Nginx 适配器。'),
        ]);
    }

    /**
     * 删除配置
     */
    public function postDelete(): string
    {
        return $this->fetchJson([
            'success' => false,
            'message' => __('WLS 公网边缘固定使用 Nginx；已拒绝非 Nginx 适配器。'),
        ]);
    }

    /**
     * 切换状态
     */
    public function postToggleStatus(): string
    {
        return $this->fetchJson([
            'success' => false,
            'message' => __('WLS 公网边缘固定使用 Nginx；已拒绝非 Nginx 适配器。'),
        ]);
    }

    /**
     * 应用配置到 Gateway
     */
    public function postApply(): string
    {
        return $this->fetchJson([
            'success' => false,
            'message' => __('WLS 公网边缘固定使用 Nginx；已拒绝非 Nginx 适配器。'),
        ]);
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
