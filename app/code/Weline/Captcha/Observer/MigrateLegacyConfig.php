<?php

declare(strict_types=1);

namespace Weline\Captcha\Observer;

use Weline\Captcha\Service\LegacyConfigMigrator;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

final class MigrateLegacyConfig implements ObserverInterface
{
    public function __construct(private readonly LegacyConfigMigrator $migrator)
    {
    }

    public function execute(Event &$event): void
    {
        $this->migrator->migrate();
    }
}
