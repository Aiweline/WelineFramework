<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\LocalModelTranslation;

use PHPUnit\Framework\TestCase;

final class LocalModelTranslationSourceContractTest extends TestCase
{
    public function testResolveSourceTextPrefersParentTableWhenLocalEmpty(): void
    {
        $path = dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationService.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('iterateParentRows', $source);
        self::assertStringContainsString('->limit($pageSize, $offset)', $source);
        self::assertStringContainsString('fromParent', $source);
        self::assertStringContainsString('parentHasField', $source);
        self::assertStringContainsString(
            'no source-locale Local row required',
            $source,
        );
        // Parent column wins before source-locale Local fallback.
        $parentPos = strpos($source, '$fromParent');
        $localPos = strpos($source, 'loadLocalFieldValue');
        self::assertNotFalse($parentPos);
        self::assertNotFalse($localPos);
        self::assertLessThan($localPos, $parentPos);
    }
}
