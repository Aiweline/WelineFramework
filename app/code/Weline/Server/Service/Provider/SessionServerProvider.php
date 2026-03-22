<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;

/**
 * Session Server 服务提供者
 *
 * Session Server 为所有 Worker 提供共享 Session 存储，
 * 解决多 Worker 状态不一致问题。
 *
 * 优先级：10（最先启动，最后停止）
 */
class SessionServerProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-session';

    public function getRole(): string
    {
        return 'session_server';
    }

    public function getDisplayName(): string
    {
        return 'Session Server';
    }

    /**
     * 多 Worker 时启用（>1）；也可通过 wls.session_server.enabled 强制开启。
     */
    public function isEnabled(ServiceContext $context): bool
    {
        $ss = ($context->envConfig['wls'] ?? [])['session_server'] ?? [];
        $force = $ss['enabled'] ?? null;
        if ($force !== null) {
            return (bool) $force;
        }
        $workerCount = $context->getWorkerCount();
        if ($workerCount === 'auto') {
            $workerCount = $this->getAutoCpuCount();
        }
        return (int) $workerCount > 1;
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return 1;
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getResurrectionPriority(): int
    {
        return 1;
    }

    public function requiresStartupReadyBarrier(): bool
    {
        return true;
    }

    public function isCriticalRole(): bool
    {
        return true;
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $scriptDir = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin';
        $script = $scriptDir . DS . 'session_server.php';

        $port = $this->getPort($instanceId, $context);
        $processName = self::PROCESS_NAME_PREFIX . '-' . $context->instanceName;
        $tokenFileName = $this->getTokenFileName($context);

        $arguments = [
            '127.0.0.1',
            (string) $port,
            $context->instanceName,
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
            '--token-file-name=' . $tokenFileName,
        ];

        if ($context->frontend) {
            $arguments[] = '--frontend';
        }

        return new ServiceCommand(
            script: $script,
            arguments: $arguments,
            processName: $processName,
        );
    }

    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        $wlsSession = ($context->envConfig['wls'] ?? [])['session'] ?? [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];

        return (int) (
            $wlsSession['port']
            ?? $wlsServer['port']
            ?? $context->envConfig['session']['server_port']
            ?? 19970
        );
    }

    private function getTokenFileName(ServiceContext $context): string
    {
        $wlsSession = ($context->envConfig['wls'] ?? [])['session'] ?? [];
        $wlsServer = \is_array($wlsSession['wls_server'] ?? null) ? $wlsSession['wls_server'] : [];

        $tokenFileName = (string) (
            $wlsServer['token_file_name']
            ?? $wlsSession['token_file_name']
            ?? 'session_server.token'
        );

        return $tokenFileName !== '' ? $tokenFileName : 'session_server.token';
    }
}
