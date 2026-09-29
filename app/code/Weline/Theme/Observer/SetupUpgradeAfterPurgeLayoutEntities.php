<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityUpgradeSolidifyService;

/**
 * setup:upgrade / deploy:upgrade / core:update 后：迁到 generated/ 并全主题预固化布局模板（§0 R5/R6）。
 *
 * @see app/code/Weline/Theme/doc/布局固化与默认注入.md §0 · §3.4
 */
final class SetupUpgradeAfterPurgeLayoutEntities implements ObserverInterface
{
    public function __construct(
        private readonly ThemeLayoutEntityUpgradeSolidifyService $solidifyService,
    ) {
    }

    public function execute(Event &$event): void
    {
        $this->solidifyService->runOnce($this->reasonForEvent($event));
    }

    /** @internal */
    public static function resetHasRunFlag(): void
    {
        ThemeLayoutEntityUpgradeSolidifyService::resetHasRunFlag();
    }

    private function reasonForEvent(Event $event): string
    {
        return match ($event->getName()) {
            'Weline_Framework_Deploy::upgrade_after' => 'deploy_upgrade_layout_entities_solidified',
            'Weline_Deploy::core_update_after' => 'core_update_layout_entities_solidified',
            default => 'setup_upgrade_layout_entities_solidified',
        };
    }
}
