<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Service\Localization\WebsiteLanguageAssignment;

/**
 * Website locale writes must soft-trigger Theme font subset warmup (optional Theme).
 */
final class WebsiteLanguageFontWarmupHookContractTest extends TestCase
{
    public function testSetWebsiteLanguagesSourceCallsThemeFontWarmup(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Model/WebsiteLanguage.php'
        );
        self::assertStringContainsString('syncThemeFontWarmup', $src);
        self::assertStringContainsString('FontWarmupService', $src);
        self::assertStringContainsString('->warmup($codes)', $src);
    }

    public function testLanguageAssignmentSourceCallsThemeFontWarmup(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/Localization/WebsiteLanguageAssignment.php'
        );
        self::assertStringContainsString('syncThemeFontWarmup', $src);
        self::assertStringContainsString('FontWarmupService', $src);
        self::assertTrue(class_exists(WebsiteLanguage::class));
        self::assertTrue(class_exists(WebsiteLanguageAssignment::class));
    }
}
