<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Sse\SseWriter;

#[Acl('GuoLaiRen_PageBuilder::server_manager', '服务器实例', 'mdi mdi-server', 'PageBuilder 服务器管理', 'GuoLaiRen_PageBuilder::server_management')]
class ServerManager extends BaseController
{
    #[Acl('GuoLaiRen_PageBuilder::server_manager_index', '服务器实例页面', 'mdi mdi-server')]
    public function getIndex(): string
    {
        $this->assign('title', __('服务器实例'));
        return $this->fetch();
    }

    #[Acl('GuoLaiRen_PageBuilder::server_monitor_index', '服务器监控页面', 'mdi mdi-speedometer')]
    public function getMonitor(): string
    {
        $this->assign('title', __('服务器监控'));
        return $this->fetch('monitor');
    }

    #[Acl('GuoLaiRen_PageBuilder::server_ssl_index', 'SSL 证书管理页面', 'mdi mdi-shield-lock')]
    public function getSsl(): string
    {
        $this->assign('title', __('SSL证书管理'));
        return $this->fetch('ssl');
    }

    #[Acl('GuoLaiRen_PageBuilder::server_optimization_index', '性能优化页面', 'mdi mdi-lightning-bolt')]
    public function getOptimization(): string
    {
        $this->assign('title', __('性能优化指南'));
        return $this->fetch('optimization');
    }

    public function getStatus(): string
    {
        $instance = (string)$this->request->getGet('instance', 'default');
        return $this->fetchJson(w_query('server', 'status', ['instance' => $instance]));
    }

    public function postStart(): string
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        $workers = (int)$this->request->getPost('workers', 0);
        return $this->fetchJson(w_query('server', 'start', ['instance' => $instance, 'workers' => $workers]));
    }

    public function postStop(): string
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        return $this->fetchJson(w_query('server', 'stop', ['instance' => $instance]));
    }

    public function postRestart(): string
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        return $this->fetchJson(w_query('server', 'restart', ['instance' => $instance]));
    }

    public function postReload(): string
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        return $this->fetchJson(w_query('server', 'reload', ['instance' => $instance]));
    }

    public function postMaintenanceEnable(): string
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        return $this->fetchJson(w_query('server', 'maintenanceEnable', ['instance' => $instance]));
    }

    public function postMaintenanceDisable(): string
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        return $this->fetchJson(w_query('server', 'maintenanceDisable', ['instance' => $instance]));
    }

    public function getOptimizationData(): string
    {
        return $this->fetchJson(w_query('server', 'optimizationData'));
    }

    public function getSslList(): string
    {
        return $this->fetchJson(w_query('server', 'listCertificates'));
    }

    public function getSslDomainPool(): string
    {
        return $this->fetchJson([
            'success' => true,
            'data' => w_query('websites', 'getDomainPoolList', [
                'status' => 'active',
                'limit' => 1000,
            ]),
        ]);
    }

    public function postSslRequest(): string
    {
        return $this->fetchJson(w_query('server', 'requestCertificate', [
            'domain' => (string)$this->request->getPost('domain', ''),
            'email' => (string)$this->request->getPost('email', ''),
            'provider' => (string)$this->request->getPost('provider', 'letsencrypt'),
            'website_id' => (int)$this->request->getPost('website_id', 0),
        ]));
    }

    public function postSslRenew(): string
    {
        return $this->fetchJson(w_query('server', 'renewCertificate', [
            'domain' => (string)$this->request->getPost('domain', ''),
            'email' => (string)$this->request->getPost('email', ''),
        ]));
    }

    public function postSslToggleHttps(): string
    {
        return $this->fetchJson(w_query('server', 'toggleHttps', [
            'domain' => (string)$this->request->getPost('domain', ''),
            'enabled' => (bool)$this->request->getPost('enabled', false),
        ]));
    }

    public function postSslDelete(): string
    {
        return $this->fetchJson(w_query('server', 'deleteCertificate', [
            'domain' => (string)$this->request->getPost('domain', ''),
        ]));
    }

    public function getSessionList(): string
    {
        return $this->fetchJson(w_query('server', 'sessionList', [
            'limit' => (int)$this->request->getGet('limit', 50),
        ]));
    }

    public function postSessionDestroy(): string
    {
        return $this->fetchJson(w_query('server', 'sessionDestroy', [
            'session_id' => (string)$this->request->getPost('session_id', ''),
        ]));
    }

    public function postSessionPersist(): string
    {
        return $this->fetchJson(w_query('server', 'sessionPersist'));
    }

    public function postSessionGc(): string
    {
        return $this->fetchJson(w_query('server', 'sessionGc', [
            'max_lifetime' => (int)$this->request->getPost('max_lifetime', 3600),
        ]));
    }

    public function getMemoryNamespaces(): string
    {
        return $this->fetchJson(w_query('server', 'memoryNamespaces', [
            'limit' => (int)$this->request->getGet('limit', 200),
        ]));
    }

    public function getMemoryNamespaceDetail(): string
    {
        return $this->fetchJson(w_query('server', 'memoryNamespaceDetail', [
            'namespace' => (string)$this->request->getGet('namespace', ''),
            'limit' => (int)$this->request->getGet('limit', 100),
        ]));
    }

    public function postMemoryNamespaceClear(): string
    {
        return $this->fetchJson(w_query('server', 'memoryNamespaceClear', [
            'namespace' => (string)$this->request->getPost('namespace', ''),
        ]));
    }

    public function postMemoryKeyDelete(): string
    {
        return $this->fetchJson(w_query('server', 'memoryKeyDelete', [
            'namespace' => (string)$this->request->getPost('namespace', ''),
            'key' => (string)$this->request->getPost('key', ''),
        ]));
    }

    public function postMemoryPersist(): string
    {
        return $this->fetchJson(w_query('server', 'memoryPersist'));
    }

    public function postMemoryGc(): string
    {
        return $this->fetchJson(w_query('server', 'memoryGc', [
            'max_lifetime' => (int)$this->request->getPost('max_lifetime', 3600),
        ]));
    }

    public function getOperationStream(): void
    {
        $sse = new SseWriter();
        $sse->start();

        $op = (string)$this->request->getGet('op', '');
        $instance = (string)$this->request->getGet('instance', 'default');
        $workers = (int)$this->request->getGet('workers', 0);

        $allowedOps = ['start', 'reload', 'restart'];
        if (!\in_array($op, $allowedOps, true)) {
            $sse->sendError((string)__('不支持的操作：%{1}', [$op]));
            $sse->complete(['message' => (string)__('操作终止')]);
            return;
        }

        $opLabel = match ($op) {
            'start' => (string)__('启动'),
            'reload' => (string)__('热重载'),
            default => (string)__('重启'),
        };
        $sse->sendEvent('start', [
            'message' => (string)__('开始执行%{1}，实例：%{2}', [$opLabel, $instance]),
            'progress' => 5,
        ]);

        $result = match ($op) {
            'start' => w_query('server', 'start', ['instance' => $instance, 'workers' => $workers]),
            'reload' => w_query('server', 'reload', ['instance' => $instance]),
            default => w_query('server', 'restart', ['instance' => $instance]),
        };
        $success = (bool)($result['success'] ?? false);
        $message = (string)($result['message'] ?? '');
        if (!$success) {
            $sse->sendEvent('error', [
                'message' => $message !== '' ? $message : (string)__('操作失败'),
                'progress' => -1,
            ]);
            $sse->complete(['message' => (string)__('操作执行失败')]);
            return;
        }

        $sse->sendEvent('progress', [
            'message' => $message !== '' ? $message : (string)__('命令已发送，等待状态稳定...'),
            'progress' => 20,
        ]);

        $stableRunningCount = 0;
        $maxChecks = 20;
        for ($i = 1; $i <= $maxChecks; $i++) {
            if (!$sse->isAlive()) {
                return;
            }
            $status = w_query('server', 'status', ['instance' => $instance]);
            $servers = (array)($status['servers'] ?? []);
            $server = (array)($servers[$instance] ?? []);
            $isRunning = (bool)($server['running'] ?? false);

            $stableRunningCount = $isRunning ? ($stableRunningCount + 1) : 0;
            $progress = \min(95, 20 + (int)\floor(($i / $maxChecks) * 75));
            $sse->sendEvent('progress', [
                'message' => (string)__('第 %{1} 次状态检测：%{2}', [$i, $isRunning ? (string)__('运行中') : (string)__('已停止')]),
                'progress' => $progress,
            ]);

            if ($stableRunningCount >= 2) {
                $sse->complete([
                    'message' => (string)__('%{1}完成，实例状态已稳定。', [$opLabel]),
                    'progress' => 100,
                ]);
                return;
            }
            \usleep(500000);
        }

        $sse->complete([
            'message' => (string)__('命令已执行，实例仍在切换中，请稍后刷新状态查看最终结果。'),
            'progress' => 100,
        ]);
    }
}
