<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Service\PageLocaleService;

final class PageLocaleServiceProviderTest extends TestCase
{
    public function testWebsiteZeroIsForwardedAndEnglishIsSelectedAsSource(): void
    {
        $calls = [];
        $service = new PageLocaleService(queryExecutor: static function (
            string $provider,
            string $operation,
            array $params
        ) use (&$calls): array {
            $calls[] = [$provider, $operation, $params];
            return match ($operation) {
                'getWebsiteLanguageCodes' => ['zh_Hans_CN', 'en_US', 'en-us'],
                'getWebsiteById' => ['website_id' => 0, 'default_language' => 'zh_Hans_CN'],
                default => [],
            };
        });

        self::assertSame(['zh_Hans_CN', 'en_US'], $service->getWebsiteLocales(0));
        self::assertSame('en_US', $service->resolveSourceLocaleForWebsite(0));
        self::assertSame(
            ['websites', 'getWebsiteLanguageCodes', ['website_id' => 0]],
            $calls[0]
        );
    }

    public function testWebsiteDefaultIsUsedWhenEnglishIsUnavailable(): void
    {
        $service = new PageLocaleService(queryExecutor: static fn(
            string $provider,
            string $operation,
            array $params
        ): array => $operation === 'getWebsiteLanguageCodes'
            ? ['zh_Hans_CN', 'fr_FR']
            : ['website_id' => $params['website_id'], 'default_language' => 'fr_FR']);

        self::assertSame('fr_FR', $service->resolveSourceLocaleForWebsite(7));
        self::assertSame('fr_FR', $service->assertWebsiteLocale(7, 'fr-fr'));
    }

    public function testUnsupportedLocaleIsRejected(): void
    {
        $service = new PageLocaleService(queryExecutor: static fn(): array => ['zh_Hans_CN', 'en_US']);

        $this->expectException(\InvalidArgumentException::class);
        $service->assertWebsiteLocale(0, 'de_DE');
    }
}
