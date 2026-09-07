<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Api\Localization;

use PHPUnit\Framework\TestCase;

final class GlobalDictionaryProviderTest extends TestCase
{
    public function testWordsStreamsDictionaryRowsInsteadOfMaterializingAnUnboundedSelect(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Api/Localization/GlobalDictionaryProvider.php',
        );

        self::assertStringContainsString('fetchIterator', $source);
        self::assertStringContainsString('foreach ($query->select()->fetchIterator() as $row)', $source);
        self::assertStringNotContainsString('->select()->fetchArray()', $source);
    }
}
