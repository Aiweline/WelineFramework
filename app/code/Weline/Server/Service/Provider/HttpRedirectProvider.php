<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;

/**
 * HTTP 重定向服务提供者
 *
 * 将 HTTP 请求重定向到 HTTPS。
 * 仅在启用 SSL 时启用。
 *
 * 端口：以 Master 传入的 context.httpRedirectPort 为准（Start 根据 -p / 实例文件计算）；
 * 若为 0 且 HTTPS 监听 443，则默认 80；非 443 不启动独立 Worker（明文走 Dispatcher 内联 301）。
 *
 * 优先级：40（在 Dispatcher 之后启动）
 */
class HttpRedirectProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-redirect';

    public function getRole(): string
    {
        return 'redirect';
    }

    public function getDisplayName(): string
    {
        return 'HTTP Redirect';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        if (!$context->sslEnabled) {
            return false;
        }

        $httpRedirectPort = $this->resolvePort($context);
        return $httpRedirectPort > 0;
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return 1;
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function getResurrectionPriority(): int
    {
        return 4;
    }

    public function isCriticalRole(): bool
    {
        return true;
    }

    public function getReloadStrategy(): string
    {
        return 'immediate';
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $scriptDir = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin';
        $script = $scriptDir . DS . 'http_redirect_worker.php';

        $httpPort = $this->getPort($instanceId, $context);
        $httpsPort = $context->mainPort;

        $arguments = [
            ($context->envConfig['wls'] ?? [])['host'] ?? '127.0.0.1',
            (string) $httpPort,
            (string) $httpsPort,
            $context->instanceName,
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
        ];

        if ($context->frontend) {
            $arguments[] = '--frontend';
        }

        $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $context->instanceName);

        return new ServiceCommand(
            script: $script,
            arguments: $arguments,
            processName: $processName,
        );
    }

    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        return $this->resolvePort($context);
    }

    /**
     * 解析 HTTP 重定向端口
     *
     * @return int 端口号，0 表示不启动
     */
    private function resolvePort(ServiceContext $context): int
    {
        if ($context->httpRedirectPort > 0) {
            return $context->httpRedirectPort;
        }
        if (!$context->sslEnabled) {
            return 0;
        }

        return $context->mainPort === 443 ? 80 : 0;
    }
}
