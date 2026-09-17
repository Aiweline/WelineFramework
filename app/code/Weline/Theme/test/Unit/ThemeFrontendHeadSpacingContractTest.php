<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeFrontendHeadSpacingContractTest extends TestCase
{
    public function testFrontendHeadLoadsSpacingVariablesBeforeFoundation(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/head/default.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            '<theme:css>Weline_Theme::theme/frontend/variables/_spacing.css</theme:css>',
            $content
        );
        $this->assertStringContainsString(
            '<theme:css>Weline_Theme::theme/frontend/variables/_typography.css</theme:css>',
            $content
        );
        $this->assertLessThan(
            strpos($content, 'href="@static(Weline_Theme::ui/weline-foundation.css)'),
            strpos($content, 'variables/_spacing.css'),
            'Spacing variables must load before foundation so layout width tokens exist.'
        );
        $this->assertLessThan(
            strpos($content, 'href="@static(Weline_Theme::ui/weline-foundation.css)'),
            strpos($content, 'variables/_typography.css'),
            'Typography variables must load before foundation so classical font tokens exist.'
        );
    }
}
