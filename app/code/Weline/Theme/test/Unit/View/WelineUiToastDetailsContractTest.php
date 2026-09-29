<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WelineUiToastDetailsContractTest extends TestCase
{
    public function testToastSupportsCodeAndExpandableDetails(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/ui/js/weline-ui.js',
        );
        self::assertStringContainsString('w-toast__details', $script);
        self::assertStringContainsString('w-toast__code', $script);
        self::assertStringContainsString('options.details', $script);
        self::assertStringContainsString('detailsEl.open', $script);
        self::assertStringContainsString("toast_view_details", $script);

        $css = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/ui/css/foundation.css',
        );
        self::assertStringContainsString('.w-toast__details', $css);
        self::assertStringContainsString('.w-toast__details-body', $css);
        self::assertStringContainsString('.w-toast__code', $css);
    }
}
