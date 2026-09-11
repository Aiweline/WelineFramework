<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AiTranslationDbOnlyCandidatesContractTest extends TestCase
{
    public function testCandidateWordsComeFromDictionaryOnlyAndSkipFileTranslationGates(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AiTranslationService.php',
        );

        $collectStart = strpos($source, 'private function collectCandidateWords');
        $collectEnd = strpos($source, 'private function appendDictionaryWords');
        self::assertNotFalse($collectStart);
        self::assertNotFalse($collectEnd);
        $collectBody = substr($source, $collectStart, $collectEnd - $collectStart);
        self::assertStringContainsString('appendDictionaryWords', $collectBody);
        self::assertStringNotContainsString('appendBackendMenuWords', $collectBody);
        self::assertStringNotContainsString('appendModuleCsvWords', $collectBody);

        $shouldStart = strpos($source, 'private function shouldTranslateWord');
        $shouldEnd = strpos($source, 'private function hasTranslatableText');
        self::assertNotFalse($shouldStart);
        self::assertNotFalse($shouldEnd);
        $shouldBody = substr($source, $shouldStart, $shouldEnd - $shouldStart);
        self::assertStringContainsString('translationExists', $shouldBody);
        self::assertStringNotContainsString('hasGeneratedTranslation', $shouldBody);
        self::assertStringNotContainsString('hasCsvTranslation', $shouldBody);
    }
}
