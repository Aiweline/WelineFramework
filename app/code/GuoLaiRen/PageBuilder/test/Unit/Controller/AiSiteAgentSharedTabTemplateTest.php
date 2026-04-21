<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class AiSiteAgentSharedTabTemplateTest extends TestCase
{
    public function testSharedThemeTabRendersThemeDesignContractFields(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $script = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');

        self::assertIsString($script);
        self::assertStringContainsString('function renderThemeSummarySection(planRoot)', $script);
        self::assertStringContainsString('planRoot.theme_design', $script);
        self::assertStringContainsString('themeDesign && themeDesign.theme_purpose', $script);
        self::assertStringContainsString('themeDesign && themeDesign.color_scheme', $script);
        self::assertStringContainsString('themeDesign && themeDesign.selection_reason', $script);
        self::assertStringContainsString('themeDesign && themeDesign.forbidden_styles', $script);
        self::assertStringContainsString('previewLabels.themePurpose', $script);
        self::assertStringContainsString('previewLabels.colorSystem', $script);
        self::assertStringContainsString('previewLabels.selectionReason', $script);
        self::assertStringContainsString('previewLabels.forbiddenStyles', $script);
    }
}
