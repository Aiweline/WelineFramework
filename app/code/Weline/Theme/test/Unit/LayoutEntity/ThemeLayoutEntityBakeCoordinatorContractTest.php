<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Source-string contract: bake coordinator exists and exposes structural command detection.
 */
final class ThemeLayoutEntityBakeCoordinatorContractTest extends TestCase
{
    public function testClassExistsAndCommandsAreStructuralMethodPresent(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('final class ThemeLayoutEntityBakeCoordinator', $src);
        self::assertStringContainsString('function afterLayoutWrite', $src);
        self::assertStringContainsString('function commandsAreStructural', $src);
        self::assertStringContainsString('function bakeChromeFromNodes', $src);
        self::assertStringContainsString('function bakePageFromNodes', $src);
        // Publish must force full page bake even when command list is empty/non-structural.
        self::assertStringContainsString(
            '$structural = $published || $this->commandsAreStructural($commands);',
            $src,
        );
        // Sidecar must keep full node (widget_module/code), not config-only.
        self::assertStringContainsString('$configByUid[$uid] = $node;', $src);
        self::assertTrue(\class_exists(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class));
        self::assertTrue(
            \method_exists(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class,
                'commandsAreStructural',
            ),
        );
    }
}
