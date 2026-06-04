<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AiSiteStageOnePagePromptContractTest extends TestCase
{
    public function testRealStageOnePagePromptLocksDirectPageShape(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/AiSitePlanJsonGenerationService.php'
        );

        self::assertStringContainsString('$exactBlockKeys = $this->exactStageOnePageBlockKeys($pageContract);', $source);
        self::assertStringContainsString('The value of "page" is plan_json.pages.', $source);
        self::assertStringContainsString('Exact dynamic block keys required for this page:', $source);
        self::assertStringContainsString('Complete page return skeleton:', $source);
        self::assertStringContainsString('Forbidden wrappers: do not output plan_json, pages, page, ', $source);
        self::assertStringContainsString('Forbidden single-block artifact:', $source);
        self::assertStringContainsString('execution_core_copy', $source);
        self::assertStringContainsString('Field plan hard rule:', $source);
        self::assertStringContainsString('Field plan copy rule:', $source);
        self::assertStringContainsString("'field' => 'headline'", $source);
        self::assertStringContainsString('Trusted play starts here.', $source);
        self::assertStringContainsString('Output exactly ', $source);
        self::assertStringContainsString('stageOnePageReturnSkeleton', $source);
    }

    public function testRealStageOneThemePromptLocksRequiredThemeDesignFields(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/AiSitePlanJsonGenerationService.php'
        );

        self::assertStringContainsString("'theme_required_fields' => \$contract['theme_required_fields'] ?? []", $source);
        self::assertStringContainsString('theme_design must include every required field from theme_required_fields.theme_design', $source);
        self::assertStringContainsString('Do not return only theme_design, only palette, or only navigation/footer.', $source);
        self::assertStringContainsString('Complete root theme artifact skeleton:', $source);
        self::assertStringContainsString('stageOneThemeRootSkeleton', $source);
        self::assertStringContainsString("'theme_style' => [", $source);
        self::assertStringContainsString("if (!\\is_array(\$payload[\$sectionKey] ?? null) || \$payload[\$sectionKey] === [])", $source);
        self::assertStringContainsString('theme_design.typography_spacing_radius must include font_family, heading_scale, body_scale, spacing_scale, and radius_scale.', $source);
        self::assertStringContainsString('Complete theme_design skeleton:', $source);
        self::assertStringContainsString('stageOneThemeDesignSkeleton', $source);
        self::assertStringContainsString("'forbidden_styles' => ['generic placeholder copy', 'invented routes', 'oversized decoration']", $source);
    }
}
