<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class LocaleCatalogChangedObserverContractTest extends TestCase
{
    public function testLocaleCatalogChangedEventRepublishesMaintenanceStaticPages(): void
    {
        $root = \dirname(__DIR__, 7);
        $lifecycleSource = (string) \file_get_contents($root . '/app/code/Weline/I18n/Service/CountryLocaleLifecycleService.php');
        $eventXml = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/etc/event.xml');
        $observerSource = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Observer/LocaleCatalogChangedObserver.php');
        $templateSource = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/view/templates/maintenance.phtml');
        $generatorSource = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Service/MaintenanceStaticGenerator.php');

        self::assertStringContainsString("dispatch('Weline_I18n::locale_catalog_changed')", $lifecycleSource);
        self::assertStringContainsString('Weline_I18n::locale_catalog_changed', $eventXml);
        self::assertStringContainsString('Weline_Framework_Setup::upgrade_after', $eventXml);
        self::assertStringContainsString('LocaleCatalogChangedObserver', $observerSource);
        self::assertStringContainsString('publishAll', $observerSource);
        self::assertStringContainsString('data-lang=', $templateSource);
        self::assertStringContainsString('buildLocalePath', $templateSource);
        self::assertStringNotContainsString('MaintenanceStaticPage::publicHtmlUrl', $templateSource);
        self::assertStringContainsString('ActiveLocaleCodeProvider', $generatorSource);
        self::assertStringContainsString('pruneObsoleteStaticFiles', $generatorSource);
    }
}
