<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class WelineUiElevateStackContractTest extends TestCase
{
    public function testFoundationExposesElevateStackApi(): void
    {
        $js = dirname(__DIR__, 2) . '/view/ui/js/weline-ui.js';
        $css = dirname(__DIR__, 2) . '/view/ui/css/foundation.css';
        self::assertFileExists($js);
        self::assertFileExists($css);
        $jsSrc = (string)file_get_contents($js);
        $cssSrc = (string)file_get_contents($css);

        self::assertStringContainsString('function applyElevateLayer', $jsSrc);
        self::assertStringContainsString('function installElevateLayerRuntime', $jsSrc);
        self::assertStringContainsString('elevate: applyElevateLayer', $jsSrc);
        self::assertStringContainsString('clearElevate: clearElevateLayer', $jsSrc);
        self::assertStringContainsString('function bindHoverOpenSurface', $jsSrc);
        self::assertStringContainsString("dataset.wOpenOn !== 'hover'", $jsSrc);
        self::assertStringContainsString('data-wf-host', $jsSrc);
        self::assertStringContainsString('data-wf-layer', $jsSrc);
        self::assertStringContainsString('data-wf-unclip', $jsSrc);
        self::assertStringContainsString('[data-wf-unclip]', $cssSrc);
    }

    public function testHeaderOptInUsesElevateMarkersNotHardcodedZ(): void
    {
        $header = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        $source = (string)file_get_contents($header);
        // Header flyouts use portal Menu/Popover (host+1 via floating stack), not data-wf-* markers.
        self::assertStringContainsString('data-w-component="popover"', $source);
        self::assertStringContainsString('data-w-component="menu"', $source);
        self::assertStringContainsString('data-w-open-on="hover"', $source);
        self::assertStringContainsString('header-category-panel', $source);
        self::assertStringNotContainsString('z-index: 40', $source);
        self::assertStringNotContainsString('.header-categories:has(.category-item.has-children:hover)', $source);
        self::assertStringNotContainsString('display: grid !important; /* 显示子菜单', $source);
    }
}
