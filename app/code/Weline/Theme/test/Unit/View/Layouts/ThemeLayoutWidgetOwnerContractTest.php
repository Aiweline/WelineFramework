<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View\Layouts;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Rules\Frontend\ThemeLayoutWidgetOwnerScanner;

final class ThemeLayoutWidgetOwnerContractTest extends TestCase
{
    public function testThemeLayoutsAndPartialsDoNotInlineNonThemeWidgets(): void
    {
        // __DIR__ = .../Weline/Theme/test/Unit/View/Layouts → app/code
        $codeRoot = dirname(__DIR__, 6);
        self::assertDirectoryExists($codeRoot . '/Weline/Theme');

        $scanner = new ThemeLayoutWidgetOwnerScanner();
        $violations = $scanner->scanProject($codeRoot);
        if ($violations !== []) {
            $messages = array_map(
                static fn (array $v): string => $scanner->formatViolation($v),
                array_slice($violations, 0, 20)
            );
            self::fail(
                "Theme layouts/partials contain non-Theme widget inlines:\n" . implode("\n", $messages)
            );
        }
        self::assertSame([], $violations);
    }
}
