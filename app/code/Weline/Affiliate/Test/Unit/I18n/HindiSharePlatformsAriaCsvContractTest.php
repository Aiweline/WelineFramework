<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

final class HindiSharePlatformsAriaCsvContractTest extends TestCase
{
    public function testHindiCsvTranslatesSharePlatformsAria(): void
    {
        $path = dirname(__DIR__, 3) . '/i18n/hi_IN.csv';
        self::assertFileExists($path);
        $hi = (string)file_get_contents($path);
        self::assertStringContainsString('分享到社交平台,"सोशल प्लेटफ़ॉर्म पर साझा करें"', $hi);
        self::assertDoesNotMatchRegularExpression('/^分享到社交平台,分享到社交平台$/m', $hi);
    }
}
