<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;

/**
 * HTTP 重定向服务提供者
 *
 * 将 HTTP 请求重定向到 HTTPS。
 * 仅在启用 SSL 时启用。
 *
 * 端口选择策略：
 * 1. 用户配置了 http_redirect_port → 直接使用
 * 2. HTTPS 端口 = 443 → 必须使用 80（不可用则警告，不启动）
 * 3. HTTPS 端口 ≠ 443 → 使用 HTTPS 端口 - 1（如 9981 → 9980，不可用则回退尝试）
 *
 * 优先级：40（在 Dispatcher 之后启动）
 */
class HttpRedirectProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME = 'weline-wls-redirect';

    /**
     * 非标准端口回退尝试次数
     */
    private const FALLBACK_ATTEMPTS = 5;

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
            $context->envConfig['server']['host'] ?? '127.0.0.1',
            (string) $httpPort,
            (string) $httpsPort,
            $context->instanceName,
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
        ];

        if ($context->frontend) {
            $arguments[] = '--frontend';
        }

        return new ServiceCommand(
            script: $script,
            arguments: $arguments,
            processName: self::PROCESS_NAME,
        );
    }

    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        return $this->resolvePort($context);
    }

    /**
     * 解析 HTTP 重定向端口
     *
     * 策略：
     * 1. 用户配置了 http_redirect_port → 直接使用
     * 2. HTTPS 端口 = 443 → 必须使用 80
     * 3. HTTPS 端口 ≠ 443 → 使用 HTTPS 端口 - 1，不可用则回退尝试
     *
     * @return int 端口号，0 表示不启动
     */
    private function resolvePort(ServiceContext $context): int
    {
        $httpsPort = $context->mainPort;
        $configuredPort = $context->envConfig['server']['http_redirect_port'] ?? null;

        // 1. 用户显式配置了端口
        if ($configuredPort !== null && (int) $configuredPort > 0) {
            return (int) $configuredPort;
        }

        // 2. 标准 HTTPS 端口 443 → 必须使用 80
        if ($httpsPort === 443) {
            return 80;
        }

        // 3. 非标准端口 → 使用 httpsPort - 1，回退尝试
        return $this->findAvailableRedirectPort($httpsPort);
    }

    /**
     * 查找可用的 HTTP 重定向端口（非标准 HTTPS 端口场景）
     *
     * 从 httpsPort - 1 开始尝试，最多尝试 FALLBACK_ATTEMPTS 次。
     *
     * @param int $httpsPort HTTPS 端口
     * @return int 可用端口，0 表示全部不可用
     */
    private function findAvailableRedirectPort(int $httpsPort): int
    {
        for ($i = 1; $i <= self::FALLBACK_ATTEMPTS; $i++) {
            $candidatePort = $httpsPort - $i;

            if ($candidatePort <= 0 || $candidatePort > 65535) {
                continue;
            }

            // 检查端口是否可用（未被占用，或被框架进程占用可释放）
            if (!Processer::isPortInUse($candidatePort) || Processer::isPortUsedByWeline($candidatePort)) {
                return $candidatePort;
            }
        }

        // 全部不可用，返回默认值（Dispatcher 会使用内联 301 回退）
        return $httpsPort - 1;
    }
}
