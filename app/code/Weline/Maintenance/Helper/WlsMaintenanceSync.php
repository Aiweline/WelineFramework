<?php

declare(strict_types=1);

namespace Weline\Maintenance\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RuntimeDeploymentControlInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * CLI 切换框架维护标志后，向运行中的 WLS 广播完整 maintenance_enable/disable。
 *
 * Direct 拓扑必须同步 Worker 进程内门禁（WorkerPolicyKernel）。仅 Dispatcher
 * 分流（dispatcher_only）会把 Master 标志关掉却不通知业务 Worker，导致首页仍 503。
 */
final class WlsMaintenanceSync
{
    public static function syncAfterCliToggle(Printing $printing, bool $enabled, array $args): void
    {
        $control = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(RuntimeDeploymentControlInterface::class);
        if (!$control instanceof RuntimeDeploymentControlInterface) {
            return;
        }

        $instanceName = self::resolveInstanceName($args);

        try {
            $result = $control->setMaintenanceMode($enabled, $instanceName);

            if (($result['attempted'] ?? []) === []) {
                $printing->note(
                    __(
                        '未发现运行中的 WLS 实例；已仅更新框架维护标志。若需同步运行时维护门禁，请先启动 WLS 后执行 php bin/w server:maintenance %{1}，或使用本命令的 -n <实例名> 指定实例后重试。',
                        [$enabled ? 'enable' : 'disable']
                    )
                );

                return;
            }

            if (!empty($result['success'])) {
                $printing->note(__('WLS 维护模式已同步：%{1}', [$result['message'] ?? 'ok']));

                return;
            }

            $printing->warning(
                __('WLS 维护模式同步未完全成功：%{1}', [$result['message'] ?? 'unknown'])
            );
        } catch (\Throwable $throwable) {
            $printing->warning(__('WLS 维护模式同步失败：%{1}', [$throwable->getMessage()]));
        }
    }

    private static function resolveInstanceName(array $args): ?string
    {
        $name = $args['n'] ?? $args['name'] ?? $args['instance'] ?? null;
        if (\is_string($name)) {
            $name = \trim($name);
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
