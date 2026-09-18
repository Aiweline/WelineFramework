<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ActiveLocaleProcessCacheContractTest extends TestCase
{
    public function testActiveLocaleUsesProcessStaticAndResetClearsIt(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ActiveLocaleCodeProvider.php');
        self::assertStringContainsString('private static array $processInstalledActiveCodes', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertStringContainsString('self::clearProcessCache()', $src);
    }

    public function testLocaleCatalogChangeClearsActiveLocale(): void
    {
        $lifecycle = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/CountryLocaleLifecycleService.php');
        self::assertStringContainsString('ActiveLocaleCodeProvider::class)->reset()', $lifecycle);

        $observer = (string)file_get_contents(dirname(__DIR__, 3) . '/Observer/LocaleCatalogChangedClearActiveLocales.php');
        self::assertStringContainsString('ActiveLocaleCodeProvider', $observer);

        $events = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertStringContainsString('LocaleCatalogChangedClearActiveLocales', $events);
    }
}
