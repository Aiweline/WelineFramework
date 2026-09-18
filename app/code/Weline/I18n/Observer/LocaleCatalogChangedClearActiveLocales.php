<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\ActiveLocaleCodeProvider;

/** locale 安装/启停后清掉进程级 ActiveLocale 列表。 */
final class LocaleCatalogChangedClearActiveLocales implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        try {
            ObjectManager::getInstance(ActiveLocaleCodeProvider::class)->reset();
        } catch (\Throwable) {
            ActiveLocaleCodeProvider::clearProcessCache();
        }
    }
}
