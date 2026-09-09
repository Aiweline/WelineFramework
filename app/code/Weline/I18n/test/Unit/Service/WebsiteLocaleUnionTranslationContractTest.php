<?php
declare(strict_types=1);

namespace Weline\I18n\test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class WebsiteLocaleUnionTranslationContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
    }

    public function testAiTranslationConfigForcesWebsiteUnionLocales(): void
    {
        $source = $this->read('app/code/Weline/I18n/Service/AiTranslationConfig.php');
        self::assertStringContainsString('getWebsiteAssignedLocaleCodes', $source);
        self::assertStringContainsString('getTranslationCandidateLocaleCodes', $source);
        self::assertStringContainsString('isWebsiteAssignedLocale', $source);
        self::assertStringContainsString('tracksWebsiteLocaleUnion', $source);
        self::assertStringContainsString('left the union (site language removed) → skip', $source);
        self::assertStringContainsString('removed from all sites → skip', $source);
    }

    public function testWebsiteLanguageChangesSyncTranslationTargets(): void
    {
        $sync = $this->read('app/code/Weline/I18n/Service/WebsiteLocaleTranslationSync.php');
        $assignment = $this->read('app/code/Weline/Websites/Service/Localization/WebsiteLanguageAssignment.php');
        $model = $this->read('app/code/Weline/Websites/Model/WebsiteLanguage.php');
        $cron = $this->read('app/code/Weline/I18n/Cron/AiTranslation.php');

        self::assertStringContainsString('ensureWebsiteUnionReady', $sync);
        self::assertStringContainsString('onWebsiteLocalesChanged', $sync);
        self::assertStringContainsString('live union', $sync);
        self::assertStringContainsString('removed union members are skipped', $sync);
        self::assertStringContainsString('WebsiteLocaleTranslationSync', $assignment);
        self::assertStringContainsString('WebsiteLocaleTranslationSync', $model);
        self::assertStringContainsString('ensureWebsiteUnionReady', $cron);
    }

    public function testTranslationPromptsPreferEcommerceUiQuality(): void
    {
        $service = $this->read('app/code/Weline/Ai/Service/TranslationService.php');
        $adapter = $this->read('app/code/Weline/Ai/Adapter/TranslationAdapter.php');

        self::assertStringContainsString('professional translator for a multi-website ecommerce', $service);
        self::assertStringContainsString('advanced maintenance', $service);
        self::assertStringContainsString('Never return the source text unchanged', $service);
        self::assertStringContainsString('ecommerce admin and storefront UI', $adapter);
        self::assertStringContainsString('advanced maintenance', $adapter);
    }

    private function read(string $relativePath): string
    {
        $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
