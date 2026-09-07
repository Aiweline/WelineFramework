<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeSetupScopedContextFactoryContractTest extends TestCase
{
    public function testUpgradeUsesTheConcreteScopedEditorContextFactory(): void
    {
        $source = \file_get_contents(\dirname(__DIR__, 2) . '/Setup/Upgrade.php');

        self::assertIsString($source);
        self::assertStringContainsString(
            'use Weline\\Theme\\Service\\Scoped\\ThemeEditorContextFactory;',
            $source,
        );
        self::assertStringNotContainsString(
            'use Weline\\Theme\\Api\\Scoped\\ThemeEditorContextFactory;',
            $source,
        );
    }
}
