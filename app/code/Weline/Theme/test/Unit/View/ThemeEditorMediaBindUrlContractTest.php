<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Theme editor media bind URL must not use an unquoted @backend-url{weline_filemanager…}
 * path — Taglib compiles that as a bare PHP constant and 500s the editor.
 */
final class ThemeEditorMediaBindUrlContractTest extends TestCase
{
    public function testThemeEditorQuotesFilemanagerMediaBindUrl(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/backend/ThemeEditor/index.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString(
            "data-media-bind-url=\"@backend-url{'weline_filemanager/backend/media-reference/bind'}\"",
            $source
        );
        self::assertStringNotContainsString(
            '@backend-url{weline_filemanager/backend/media-reference/bind}',
            $source
        );
    }
}
