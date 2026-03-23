<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ThemeTemplateInlineFallbackSyntaxTest extends TestCase
{
    public function testThemeTemplatesDoNotUseUnsupportedInlineFallbackSyntax(): void
    {
        $themeViewDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'theme';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($themeViewDir)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'phtml') {
                continue;
            }

            $content = (string)file_get_contents($fileInfo->getPathname());
            if (preg_match('/\{\{[^{}\n]*\?:[^{}\n]*\}\}/', $content)) {
                $violations[] = str_replace('\\', '/', $fileInfo->getPathname());
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Unsupported inline fallback syntax `{{ a ?: b }}` breaks Theme template compilation. Files: ' . implode(', ', $violations)
        );
    }
}
