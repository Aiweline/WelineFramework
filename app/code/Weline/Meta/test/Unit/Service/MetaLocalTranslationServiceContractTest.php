<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class MetaLocalTranslationServiceContractTest extends TestCase
{
    public function testBatchWalksMetaLocalRowsWithScopeVersionAndLaneMeta(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/MetaLocalTranslationService.php';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('schema_fields_SCOPE', $src);
        self::assertStringContainsString('schema_fields_VERSION_ID', $src);
        self::assertStringContainsString('schema_fields_CONFIG_KEY', $src);
        self::assertStringContainsString("'meta'", $src);
        self::assertStringNotContainsString('word_prefix', $src);
        self::assertStringNotContainsString('@meta::', $src);
        self::assertStringNotContainsString("['name', 'description']", $src);
    }
}
