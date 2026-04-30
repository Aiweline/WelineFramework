<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Setup\Upgrade;

final class ThemeSetupUpgradeTest extends TestCase
{
    private const FIELD_ACTIVE = 'is_active';
    private const FIELD_ACTIVE_FRONTEND = 'is_active_frontend';
    private const FIELD_ACTIVE_BACKEND = 'is_active_backend';

    public function testCollectDefaultThemeActivationUpdatesBackfillsFrontendAndBackendWhenMissing(): void
    {
        $theme = new WelineTheme();
        $theme->setData('id', 100);

        $themeContext = $this->createMock(ThemeContextService::class);
        $themeContext->method('themeSupportsArea')->willReturn(true);

        $upgrade = new Upgrade();
        $updates = $upgrade->collectDefaultThemeActivationUpdates(
            $theme,
            $themeContext,
            static fn(string $field): bool => false
        );

        self::assertSame(1, $updates[self::FIELD_ACTIVE_FRONTEND] ?? null);
        self::assertSame(1, $updates[self::FIELD_ACTIVE_BACKEND] ?? null);
        self::assertSame(1, $updates[self::FIELD_ACTIVE] ?? null);
    }

    public function testCollectDefaultThemeActivationUpdatesDoesNotOverrideExistingBackendTheme(): void
    {
        $theme = new WelineTheme();
        $theme->setData('id', 100);

        $themeContext = $this->createMock(ThemeContextService::class);
        $themeContext->method('themeSupportsArea')->willReturn(true);

        $upgrade = new Upgrade();
        $updates = $upgrade->collectDefaultThemeActivationUpdates(
            $theme,
            $themeContext,
            static fn(string $field): bool => $field === self::FIELD_ACTIVE_BACKEND
        );

        self::assertSame(1, $updates[self::FIELD_ACTIVE_FRONTEND] ?? null);
        self::assertArrayNotHasKey(self::FIELD_ACTIVE_BACKEND, $updates);
    }
}
