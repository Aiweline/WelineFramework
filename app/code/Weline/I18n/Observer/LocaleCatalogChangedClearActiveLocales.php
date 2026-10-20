<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\I18n\Service\Repository\CountryRepository;
use Weline\I18n\Service\Repository\LocaleRepository;

/** locale 安装/启停后清掉进程级 ActiveLocale 列表与本地化仓库 L1。 */
final class LocaleCatalogChangedClearActiveLocales implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        try {
            ObjectManager::getInstance(ActiveLocaleCodeProvider::class)->reset();
        } catch (\Throwable) {
            ActiveLocaleCodeProvider::clearProcessCache();
        }

        LocaleRepository::clearProcessCache();
        CountryRepository::clearProcessCache();
    }
}
