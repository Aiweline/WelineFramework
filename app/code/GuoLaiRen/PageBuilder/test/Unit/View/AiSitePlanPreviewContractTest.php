<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AiSitePlanPreviewContractTest extends TestCase
{
    public function testStageOnePreviewShowsWebsiteContentAndHidesAuxiliaryPlanningDetails(): void
    {
        $script = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');
        self::assertIsString($script);

        self::assertStringContainsString('function renderPlanAuxiliaryDisclosure', $script);
        self::assertStringContainsString('pb-ai-plan-preview-details', $script);
        self::assertStringContainsString('var pageDetails = renderPlanAuxiliaryDisclosure', $script);
        self::assertStringContainsString('var blockDetails = renderPlanAuxiliaryDisclosure', $script);
        self::assertStringContainsString('renderFieldPlanTable(block.field_plan, { hideReason: true })', $script);
        self::assertStringContainsString('renderFieldPlanTable(block.field_plan);', $script);
        self::assertStringContainsString("+ (content ? '<div class=\"small mt-2\">' + content + '</div>' : '')", $script);

        self::assertStringNotContainsString("+ (blockGoal ? '<div class=\"small text-muted mt-2\">' + blockGoal + '</div>' : '')", $script);
        self::assertStringNotContainsString("+ (goal ? '<div class=\"small mt-2\">' + goal + '</div>' : '')", $script);
        self::assertStringNotContainsString('renderKeywordBadges(keywords)', $this->extractFunctionBody($script, 'renderPlanPagePreviewCard'));

        self::assertStringContainsString('function normalizeStageOneStructuredRootForPreview', $script);
        self::assertStringContainsString('conf.plan_book.structured', $script);
        self::assertStringContainsString('pages: pagePlans', $script);
    }

    private function extractFunctionBody(string $script, string $functionName): string
    {
        $needle = 'function ' . $functionName . '(';
        $start = \strpos($script, $needle);
        self::assertIsInt($start, $functionName . ' must exist.');
        $next = \strpos($script, "\n    function ", $start + \strlen($needle));
        if ($next === false) {
            return \substr($script, $start);
        }

        return \substr($script, $start, $next - $start);
    }
}
