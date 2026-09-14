<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** Dark header logo plate must nest default surface + theme tokens (no artwork swap). */
final class HeaderInverseLogoPlateContractTest extends TestCase
{
    public function testHeaderLogoLinkDeclaresDefaultSurfacePlate(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml'
        );
        self::assertStringContainsString('class="header-logo__link w-surface"', $src);
        self::assertStringContainsString('data-surface="default"', $src);
        self::assertStringContainsString('.header-belt.w-surface-inverse .header-logo__link', $src);
        self::assertStringContainsString('background: var(--weline-theme-surface)', $src);
        self::assertStringContainsString('color: var(--weline-theme-text)', $src);
        self::assertStringContainsString('a.header-logo__link.w-surface[data-surface="default"]', $src);
        $themeCss = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/theme/frontend/assets/css/theme.css'
        );
        self::assertStringContainsString(':not(.header-logo__link)', $themeCss);
        self::assertStringContainsString('.logo-wordmark__cn', $src);
        self::assertStringContainsString('var(--weline-theme-primary', $src);
    }
}
