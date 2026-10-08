<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DropshipWebsiteCurrencySnapshotContractTest extends TestCase
{
    public function testPublishAndFollowResolveCurrencyViaWebsiteData(): void
    {
        $publish = dirname(__DIR__, 3) . '/Service/DropshipPublishService.php';
        $follow = dirname(__DIR__, 3) . '/Service/DropshipFollowService.php';
        self::assertFileExists($publish);
        self::assertFileExists($follow);

        foreach ([$publish, $follow] as $path) {
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('WebsiteData::defaultCurrencyForWebsite', $src, $path);
            self::assertStringContainsString('WebsiteData::currencyCodesForWebsite', $src, $path);
            self::assertStringNotContainsString(
                'where(Website::schema_fields_ID',
                $src,
                $path . ' must not Website::find for default currency.',
            );
        }
    }
}
