<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * API tester native details.w-disclosure must pad summary/body so controls
 * are not flush against the disclosure border (文字贴边).
 */
final class ApiDocsDisclosurePaddingContractTest extends TestCase
{
    /** @return list<string> */
    private function cssPaths(): array
    {
        $moduleRoot = dirname(__DIR__, 3);
        $themeRoot = dirname($moduleRoot) . '/Theme';

        return [
            $themeRoot . '/view/statics/ui/pages/weline-developer-api.css',
            $moduleRoot . '/view/statics/css/api-docs.css',
        ];
    }

    public function testDisclosurePadsSummaryAndBody(): void
    {
        foreach ($this->cssPaths() as $path) {
            self::assertFileExists($path, $path);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('details.w-disclosure > summary', $src, $path);
            self::assertStringContainsString('details.w-disclosure > :not(summary)', $src, $path);
            self::assertStringContainsString('--weline-space-4', $src, $path);
            self::assertStringContainsString('overflow-wrap: break-word', $src, $path);
        }
    }
}
