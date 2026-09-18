<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Console\Theme;

use PHPUnit\Framework\TestCase;

final class PublishNotFoundStaticCommandContractTest extends TestCase
{
    public function testCommandExposesForceAndLangAndCallsGenerator(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Console/Theme/PublishNotFoundStatic.php'
        );
        self::assertStringContainsString('theme:publish-not-found-static', $source);
        self::assertStringContainsString('--force', $source);
        self::assertStringContainsString('publishAll', $source);
        self::assertStringContainsString('publishOne', $source);
        self::assertStringContainsString('forgetByPrefix', $source);
        self::assertStringContainsString('reportSampleSizes', $source);
        self::assertStringContainsString('widgetTranslations', $source);
    }
}
