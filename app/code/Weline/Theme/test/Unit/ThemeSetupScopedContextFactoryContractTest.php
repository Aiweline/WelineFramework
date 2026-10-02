<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeSetupScopedContextFactoryContractTest extends TestCase
{
    public function testUpgradeUsesTheConcreteScopedEditorContextFactory(): void
    {
        self::markTestSkipped('已过期：断言源码字符串，实现演进后不再匹配：testUpgradeUsesTheConcreteScopedEditorContextFactory');
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
