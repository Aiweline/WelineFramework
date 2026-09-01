<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeFrontendContentGridContractTest extends TestCase
{
    public function testContentGridIgnoresWhitespaceOnlySidebars(): void
    {
        $paths = [
            dirname(__DIR__, 2) . '/view/ui/css/frontend.css',
            dirname(__DIR__, 2) . '/view/statics/ui/weline-frontend.css',
        ];

        foreach ($paths as $path) {
            $this->assertFileExists($path);
            $content = (string) file_get_contents($path);

            $this->assertStringContainsString('.w-frontend-sidebar:blank { display: none; }', $content, $path);
            $this->assertStringContainsString(
                '.w-frontend-content-grid:has(> .w-frontend-sidebar:not(:blank):first-child)',
                $content,
                $path
            );
            $this->assertStringNotContainsString(
                '.w-frontend-content-grid:has(> .w-frontend-sidebar:not(:empty):first-child)',
                $content,
                $path
            );
        }
    }
}
