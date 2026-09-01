<?php

declare(strict_types=1);

namespace Weline\I18n\test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AiTranslationLocaleIndexPagingContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
    }

    public function testLocaleTranslatedWordIndexUsesBoundedPaging(): void
    {
        $source = $this->read('app/code/Weline/I18n/Service/AiTranslationService.php');

        self::assertStringContainsString('private function getLocaleTranslatedWordIndex(string $localeCode): array',
            $source);
        self::assertStringContainsString('->limit(self::DEFAULT_SCAN_PAGE_SIZE, $offset)', $source);
        self::assertStringNotContainsString(
            "->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)\n            ->select()\n            ->fetchArray();",
            $source,
        );
    }

    public function testCountUntranslatedWordsSkipsSourceLocale(): void
    {
        $service = $this->read('app/code/Weline/I18n/Service/AiTranslationService.php');
        $controller = $this->read('app/code/Weline/I18n/Controller/Backend/AiTranslation.php');

        self::assertStringContainsString('if ($targetLocale === \'\' || $targetLocale === $sourceLocale)', $service);
        self::assertStringContainsString('$pending = $isSource', $controller);
    }

    private function read(string $relativePath): string
    {
        $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
