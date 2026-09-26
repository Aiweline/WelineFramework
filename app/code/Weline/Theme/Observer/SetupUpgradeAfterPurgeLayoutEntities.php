<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityUpgradePurgeService;

/**
 * setup:upgrade / deploy:upgrade / core:update 后删除全部布局固化磁盘产物。
 *
 * 恢复主路径：当前激活主题首访 {@see \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::dynamicSolidifyPublishedPage}。
 * 禁止在此 Observer 内按 DB 旧 structure 全量 rematerialize / 注入收集后重固。
 *
 * @see app/code/Weline/Theme/doc/布局固化与默认注入.md §3.4
 */
final class SetupUpgradeAfterPurgeLayoutEntities implements ObserverInterface
{
    public function __construct(
        private readonly ThemeLayoutEntityUpgradePurgeService $purgeService,
    ) {
    }

    public function execute(Event &$event): void
    {
        $this->purgeService->runOnce($this->reasonForEvent($event));
    }

    /** @internal */
    public static function resetHasRunFlag(): void
    {
        ThemeLayoutEntityUpgradePurgeService::resetHasRunFlag();
    }

    private function reasonForEvent(Event $event): string
    {
        return match ($event->getName()) {
            'Weline_Framework_Deploy::upgrade_after' => 'deploy_upgrade_layout_entities_invalidated',
            'Weline_Deploy::core_update_after' => 'core_update_layout_entities_invalidated',
            default => 'setup_upgrade_layout_entities_invalidated',
        };
    }
}
