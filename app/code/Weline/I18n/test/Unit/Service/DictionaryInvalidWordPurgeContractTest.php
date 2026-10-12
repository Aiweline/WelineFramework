<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DictionaryInvalidWordPurgeContractTest extends TestCase
{
    public function testPurgeServiceAndConsoleExist(): void
    {
        $root = dirname(__DIR__, 3);
        $service = $root . '/Service/DictionaryInvalidWordPurgeService.php';
        $console = $root . '/Console/I18n/Dictionary/PurgeInvalid.php';
        self::assertFileExists($service);
        self::assertFileExists($console);
        $src = (string)file_get_contents($service);
        self::assertStringContainsString('CHAR_LENGTH(word) >', $src);
        self::assertStringContainsString('bad_locale', $src);
        self::assertStringContainsString('UNHEX(\'EFBBBF\')', $src);
    }
}
