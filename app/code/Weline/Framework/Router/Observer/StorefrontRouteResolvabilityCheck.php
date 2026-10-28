<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Handle as ModuleHandle;
use Weline\Framework\Router\Service\StorefrontRouteSelfCheck;

/**
 * after_route_collection（sort 靠后，晚于 ACL diff / 注解规则收集）：
 * 对刚提交的路由产物做店面可解析性自检；只观测留痕，绝不抛错打断收集。
 */
final class StorefrontRouteResolvabilityCheck implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (($event->getData('model_only') ?? false) === true) {
            return;
        }
        try {
            $modules = ObjectManager::getInstance(ModuleHandle::class)->getModules();
            if (!is_array($modules) || $modules === []) {
                return;
            }
            /** @var StorefrontRouteSelfCheck $selfCheck */
            $selfCheck = ObjectManager::getInstance(StorefrontRouteSelfCheck::class);
            $result = $selfCheck->check($modules);
            if ($result['unresolved'] !== [] && PHP_SAPI === 'cli') {
                try {
                    $printing = ObjectManager::getInstance(\Weline\Framework\Output\Cli\Printing::class);
                    $printing->warning(__(
                        '   - 店面路径自检：%{n} 个代表路径未能解析（详见 setup/route_integrity.log）：%{1}',
                        [
                            'n' => count($result['unresolved']),
                            1 => implode(', ', array_slice($result['unresolved'], 0, 8)),
                        ]
                    ));
                } catch (\Throwable) {
                    // Printing 不可用时日志已留痕。
                }
            }
        } catch (\Throwable $exception) {
            w_log_warning(__(
                '店面路径自检运行失败（不影响收集结果）：%{1}',
                [$exception->getMessage()]
            ), [], 'setup/route_integrity.log');
        }
    }
}
