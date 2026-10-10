<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Theme 认证舞台部件不得再 required 注入 Customer 认证页（主内容归 Customer）。
 */
final class ForeignAuthPlacementContractTest extends TestCase
{
    public function testNativeAuthWidgetsHaveNoRequiredForeignInjections(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        foreach (['login', 'register', 'challenge'] as $form) {
            $tpl = 'Weline_Theme::theme/frontend/widgets/form/account-' . $form . '/default.phtml';
            self::assertTrue(
                \Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl),
                $form
            );
            $src = (string) file_get_contents(\Weline\Widget\Test\Support\SlimWidgetPhpListing::resolveViewPath($tpl));
            self::assertStringContainsString('@widget.placement {layout}', $src, $form);
            self::assertStringContainsString('@widget.default_injections {[]}', $src, $form . ' must not required-inject foreign auth slots');
        }
    }
}
