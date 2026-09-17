<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Identity bar defaults to one summary line; tag chips live in a collapsed panel.
 */
final class IdentityBarCollapseContractTest extends TestCase
{
    public function testManagerTemplateDefaultsCollapsedWithToggle(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Manager/manager.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('mmf-identity-bar is-collapsed', $src);
        self::assertStringContainsString('data-mmf-identity-toggle', $src);
        self::assertStringContainsString('data-mmf-identity-summary', $src);
        self::assertStringContainsString('data-mmf-identity-panel', $src);
        self::assertStringContainsString('aria-expanded="false"', $src);
        self::assertStringContainsString('data-mmf-identity-panel hidden', $src);
    }

    public function testManagerJsCollapsesByDefaultAndExpandsOnToggle(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/manager.js';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('function setIdentityBarExpanded', $src);
        self::assertStringContainsString('function identitySummaryText', $src);
        self::assertStringContainsString('setIdentityBarExpanded(false)', $src);
        self::assertStringContainsString('data-mmf-identity-toggle', $src);
    }

    public function testManagerCssHidesCollapsedPanel(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/css/manager.css';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('.mmf-identity-bar__panel[hidden]', $src);
        self::assertStringContainsString('.mmf-identity-bar__summary', $src);
        self::assertStringContainsString('.mmf-identity-bar__toggle', $src);
    }
}
