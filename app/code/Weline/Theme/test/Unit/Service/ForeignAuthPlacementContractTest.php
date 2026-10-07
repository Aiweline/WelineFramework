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
        $entries = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        foreach (['login', 'register', 'challenge'] as $form) {
            $matched = false;
            foreach ($entries as $entry) {
                if (!is_array($entry) || ($entry['template'] ?? '') !== 'Weline_Theme::theme/frontend/widgets/form/account-' . $form . '/default.phtml') {
                    continue;
                }
                $matched = true;
                self::assertSame('layout', $entry['placement'] ?? null);
                self::assertSame([], $entry['default_injections'] ?? null, $form . ' must not required-inject foreign auth slots');
            }
            self::assertTrue($matched, $form);
        }
    }
}
