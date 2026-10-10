<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Setup\Upgrade;

final class NullSourceCoveringIndexUpgradeContractTest extends TestCase
{
    public function testUpgradeDeclaresPartialCoveringIndexForNullSourceGlobalWords(): void
    {
        $source = \file_get_contents(
            (string)(new \ReflectionClass(Upgrade::class))->getFileName()
        );
        self::assertIsString($source);
        self::assertSame('idx_locale_null_source_covering', Upgrade::NULL_SOURCE_COVERING_INDEX);
        self::assertStringContainsString('CREATE INDEX IF NOT EXISTS', $source);
        self::assertStringContainsString('INCLUDE (word, translate)', $source);
        self::assertStringContainsString("source_module IS NULL OR source_module = ''", $source);
        self::assertStringContainsString('pgsql', $source);
    }
}
