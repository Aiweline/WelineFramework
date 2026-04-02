<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;

/**
 * 独立内存服务提供者（强一致共享内存）。
 *
 * 说明：
 * - 与 Session Server 复用同一协议与服务端脚本
 * - 通过 role=memory_server 与独立端口进行逻辑隔离
 */
class MemoryServerProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-memory';

    public function getRole(): string
    {
        return ControlMessage::ROLE_MEMORY_SERVER;
    }

    public function getDisplayName(): string
    {
        return 'Memory Service';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        $m = ($context->envConfig['wls'] ?? [])['memory_service'] ?? [];
        return (bool) ($m['enabled'] ?? true);
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return 1;
    }

    public function getPriority(): int
    {
        return 12;
    }

    public function getResurrectionPriority(): int
    {
        return 1;
    }

    public function getReloadStrategy(): string
    {
        return 'none';
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
        $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $context->instanceName);
        $tokenFileName = $this->getTokenFileName($context);

        $arguments = [
            '127.0.0.1',
            (string) $port,
            $context->instanceName,
            '--instance-name=' . $context->instanceName,
            '--role=' . ControlMessage::ROLE_MEMORY_SERVER,
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
        $ms = ($context->envConfig['wls'] ?? [])['memory_service'] ?? [];
        // 默认端口 19971 + 项目偏移量，确保多项目不冲突
        $defaultPort = 19971 + MasterProcess::getProjectPortOffset();
        return (int) ($ms['port'] ?? $defaultPort);
    }

    private function getTokenFileName(ServiceContext $context): string
    {
        $memory = ($context->envConfig['wls'] ?? [])['memory_service'] ?? [];
        $tokenFileName = (string) ($memory['token_file_name'] ?? 'memory_server.token');

        return $tokenFileName !== '' ? $tokenFileName : 'memory_server.token';
    }
}
