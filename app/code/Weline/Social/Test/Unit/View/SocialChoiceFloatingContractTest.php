<?php

declare(strict_types=1);

namespace Weline\Social\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class SocialChoiceFloatingContractTest extends TestCase
{
    public function testAdminChoiceUsesThemeFloatingAttach(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Social/index.phtml'
        );
        self::assertStringContainsString('data-w-float-surface', $src);
        self::assertStringContainsString('UI.floating', $src);
        self::assertStringContainsString('floating.attach', $src);
        self::assertStringContainsString("placement: 'bottom-start'", $src);
        self::assertStringContainsString('panel.contains(target)', $src);
    }

    public function testAdminChoiceCssSupportsFloatingPositionedPortal(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/backend/social-admin.css'
        );
        self::assertStringContainsString('[data-w-floating-positioned]', $src);
        self::assertStringContainsString('--w-floating-top', $src);
        self::assertStringContainsString('overflow: visible', $src);
    }

    public function testScopeTypeDoesNotShowTechnicalWebsiteCodeChip(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Social/index.phtml'
        );
        self::assertStringContainsString("label: String(group.scope_label || group.scope_type || '')", $src);
        self::assertStringContainsString("meta: ''", $src);
        self::assertStringContainsString('typeWrap.hidden', $src);
        self::assertStringContainsString('meta: String(item.scope_code', $src);
        self::assertStringNotContainsString(
            "meta: String(group.scope_type || '')",
            $src
        );
    }
}
