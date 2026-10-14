<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\TranslationService;

final class TranslateWordToLocalesContractTest extends TestCase
{
    public function testServiceExposesMultiLocaleApi(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/TranslationService.php'
        );
        self::assertStringContainsString('function translateWordToLocales', $source);
        self::assertStringContainsString('buildMultiLocaleTranslationPrompt', $source);
        self::assertStringContainsString('parseMultiLocaleTranslationResponse', $source);
        self::assertStringContainsString('Brand-like Latin tokens', $source);
    }

    public function testParseMultiLocaleTranslationResponseMapsLocales(): void
    {
        $json = '{"en_US":"Store","hr_HR":"Trgovina","ja_JP":"ストア"}';
        $parsed = TranslationService::parseMultiLocaleTranslationResponse(
            $json,
            ['en_US', 'hr_HR', 'ja_JP', 'de_DE']
        );
        self::assertSame('Store', $parsed['en_US']);
        self::assertSame('Trgovina', $parsed['hr_HR']);
        self::assertSame('ストア', $parsed['ja_JP']);
        self::assertArrayNotHasKey('de_DE', $parsed);
    }

    public function testObserverHandlesMultiLocaleMode(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/TranslationRequestObserver.php'
        );
        self::assertStringContainsString("mode === 'multi_locale'", $source);
        self::assertStringContainsString('translateWordToLocales', $source);
        self::assertStringContainsString('target_locales', $source);
    }
}
