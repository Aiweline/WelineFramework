<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class SmartDropdownHoverBridgeContractTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function themeJsPaths(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root . '/view/theme/frontend/assets/js/theme.js',
            $root . '/view/theme/backend/assets/js/theme.js',
        ];
    }

    public function testSmartDropdownKeepsHoverBridgeForInTreePanels(): void
    {
        foreach ($this->themeJsPaths() as $path) {
            self::assertFileExists($path);
            $content = (string) file_get_contents($path);

            self::assertStringContainsString("VERSION = '2.1.0'", $content);
            self::assertStringContainsString('data-weline-dropdown-hover-bridge', $content);
            self::assertStringContainsString('shouldHoverBridge', $content);
            self::assertStringContainsString('ensureHoverBridge', $content);
            self::assertStringContainsString('config.hoverBridge', $content);
            self::assertStringContainsString('anchor.contains(panel)', $content);
        }
    }
}
