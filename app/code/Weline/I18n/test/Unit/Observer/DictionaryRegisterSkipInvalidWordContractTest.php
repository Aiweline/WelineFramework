<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class DictionaryRegisterSkipInvalidWordContractTest extends TestCase
{
    public function testRegisterAndLegacyCollectSkipAssertWordFailures(): void
    {
        $register = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/DictionaryRegisterObserver.php',
        );
        $legacy = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/CollectTranslations.php',
        );

        self::assertStringContainsString('Dictionary::assertWord', $register);
        self::assertStringContainsString('catch (\Throwable)', $register);
        self::assertStringContainsString('continue;', $register);

        self::assertStringContainsString('Dictionary::assertWord', $legacy);
        self::assertStringContainsString('catch (\Throwable)', $legacy);
    }
}
