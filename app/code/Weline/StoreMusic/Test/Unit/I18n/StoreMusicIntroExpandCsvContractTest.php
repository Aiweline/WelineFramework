<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Storefront chrome on non-zh locales must not leave「展开简介 / 收起简介」as Chinese.
 */
final class StoreMusicIntroExpandCsvContractTest extends TestCase
{
    /** @return list<string> */
    private function localeFiles(): array
    {
        $dir = dirname(__DIR__, 3) . '/i18n';
        $files = glob($dir . '/*.csv') ?: [];
        sort($files);

        return $files;
    }

    public function testNonZhLocalesTranslateIntroExpandCollapse(): void
    {
        $files = $this->localeFiles();
        self::assertNotEmpty($files);
        foreach ($files as $path) {
            $loc = basename($path, '.csv');
            $raw = (string)file_get_contents($path);
            self::assertStringNotContainsString("\xEF\xBB\xBF", $raw, $loc . ' must not have UTF-8 BOM');
            if ($loc === 'zh_Hans_CN') {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/^展开简介,展开简介$/mu', $raw, $loc);
            self::assertDoesNotMatchRegularExpression('/^收起简介,收起简介$/mu', $raw, $loc);
            self::assertMatchesRegularExpression('/^展开简介,.+$/mu', $raw, $loc);
            self::assertMatchesRegularExpression('/^收起简介,.+$/mu', $raw, $loc);
        }
    }

    public function testUrduLocalizesPlaylistChrome(): void
    {
        $path = dirname(__DIR__, 3) . '/i18n/ur_PK.csv';
        $raw = (string)file_get_contents($path);
        self::assertStringContainsString('播放列表,"پلے لسٹ"', $raw);
        self::assertStringContainsString('展开简介,"تعارف دیکھیں"', $raw);
        self::assertStringContainsString('收起简介,"تعارف چھپائیں"', $raw);
        self::assertStringContainsString('"{n} ٹریکس"', $raw);
    }
}
