<?php

declare(strict_types=1);

namespace Weline\Maintenance\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * CLI 切换框架维护标志后，向运行中的 WLS 广播 maintenance_enable/disable，
 * 使 Dispatcher 将流量切入维护 Worker（与 server:start 平滑流程一致）。
 */
final class WlsMaintenanceSync
{
    public static function syncAfterCliToggle(Printing $printing, bool $enabled, array $args): void
    {
        if (!\class_exists(\Weline\Server\Service\Control\BroadcastControlDispatchService::class)) {
            return;
        }

        $instanceName = self::resolveInstanceName($args);

        try {
            $dispatchService = ObjectManager::getInstance(
                \Weline\Server\Service\Control\BroadcastControlDispatchService::class
            );
            $result = $dispatchService->setMaintenanceRoutingOnly($enabled, $instanceName);

            if (($result['attempted'] ?? []) === []) {
                $printing->note(
                    __(
                        '未发现运行中的 WLS 实例；已仅更新框架维护标志。若要由维护 Worker 接管流量，请先启动 WLS 后执行 php bin/w server:maintenance enable，或使用本命令的 -n <实例名> 指定实例后重试。'
                    )
                );

                return;
            }

            if (!empty($result['success'])) {
                $printing->note(__('WLS 维护入口已同步：%{1}', [$result['message'] ?? 'ok']));

                return;
            }

            $printing->warning(
                __('WLS 维护入口同步未完全成功：%{1}', [$result['message'] ?? 'unknown'])
            );
        } catch (\Throwable $throwable) {
            $printing->warning(__('WLS 维护入口同步失败：%{1}', [$throwable->getMessage()]));
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
