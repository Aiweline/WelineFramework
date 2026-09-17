<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\UnpaidOrderSignalService;

final class UnpaidOrderSignalLocaleTest extends TestCase
{
    public function testExtractsLocaleFromScopeSnapshot(): void
    {
        $locale = UnpaidOrderSignalService::localeFromScopeSnapshotJson(
            json_encode(['website_id' => 1, 'store_id' => 2, 'currency' => 'USD', 'locale' => 'en_US'], JSON_THROW_ON_ERROR)
        );
        self::assertSame('en_US', $locale);
    }

    public function testAcceptsLanguageAliasAndIgnoresDefault(): void
    {
        self::assertSame(
            'zh_Hans_CN',
            UnpaidOrderSignalService::localeFromScopeSnapshotJson('{"language":"zh_Hans_CN"}')
        );
        self::assertSame('', UnpaidOrderSignalService::localeFromScopeSnapshotJson('{"locale":"default"}'));
        self::assertSame('', UnpaidOrderSignalService::localeFromScopeSnapshotJson(''));
        self::assertSame('', UnpaidOrderSignalService::localeFromScopeSnapshotJson('{broken'));
    }

    public function testResolveCreatedAtPrefersCreateTimeAndStripsMicros(): void
    {
        self::assertSame(
            '2026-09-02 13:17:56',
            UnpaidOrderSignalService::resolveCreatedAtDisplay('', '2026-09-02 13:17:56.338489')
        );
        self::assertSame(
            '2026-09-02 13:17:56',
            UnpaidOrderSignalService::resolveCreatedAtDisplay('2026-09-02 13:17:56', '')
        );
        self::assertSame(
            '2026-09-02 13:17:56',
            UnpaidOrderSignalService::resolveCreatedAtDisplay('2026-09-02 10:00:00', '2026-09-02 13:17:56.1')
        );
        self::assertSame('', UnpaidOrderSignalService::resolveCreatedAtDisplay('', ''));
    }
}
