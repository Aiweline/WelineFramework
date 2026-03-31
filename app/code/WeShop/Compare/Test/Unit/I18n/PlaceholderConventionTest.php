<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

class PlaceholderConventionTest extends TestCase
{
    public function testI18nFilesDoNotUseLegacyPercentDigitPlaceholders(): void
    {
        $baseDir = dirname(__DIR__, 3);
        $files = [
            $baseDir . '/i18n/en_US.csv',
            $baseDir . '/i18n/zh_Hans_CN.csv',
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $content = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/%\\d+/', $content, "Legacy placeholder found in {$file}");
        }
    }
}
