<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Service\StorefrontNotFoundStaticGenerator;

/**
 * Regenerate cached storefront 404 HTML after setup:upgrade.
 *
 * Hot-path 404 serving reads pub/errors/storefront-not-found/*.html directly (zero DB).
 * Manual / CI: `php bin/w theme:publish-not-found-static [--force] [--lang=el_GR]`
 */
final class SetupUpgradeAfterPublishNotFoundStatic implements ObserverInterface
{
    public function __construct(
        private readonly StorefrontNotFoundStaticGenerator $generator,
        private readonly Printing $printing,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        if (\is_array($eventData) && !empty($eventData['is_partial_upgrade'])) {
            return;
        }

        try {
            $this->printing->note(__('开始发布前台 404 静态页（website×locale，Fiber 协作）…'));
            $written = $this->generator->publishAll();
            $this->printing->success(__('前台 404 静态页发布完成：共 %{count} 个快照', [
                'count' => count($written),
            ]));
        } catch (\Throwable $e) {
            $this->printing->warning(__('前台 404 静态页发布失败：%{msg}', ['msg' => $e->getMessage()]));
            w_log_warning(
                '[Theme] storefront 404 static publish failed: ' . $e->getMessage(),
                [],
                'theme_not_found_static'
            );
        }
    }
}
