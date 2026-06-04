<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\View;

use GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader;
use PHPUnit\Framework\TestCase;

final class AiSitePlanPreviewContractTest extends TestCase
{
    public function testStageOnePreviewShowsWebsiteContentAndHidesAuxiliaryPlanningDetails(): void
    {
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertStringContainsString('function renderPlanAuxiliaryDisclosure', $script);
        self::assertStringContainsString('pb-ai-plan-preview-details', $script);
        self::assertStringContainsString('renderPlanAuxiliaryDisclosure(previewLabels.designDetails', $script);
        self::assertStringContainsString('function renderFieldPlanTable(fieldPlan, options)', $script);
        self::assertStringContainsString("+ (content ? '<div class=\"small mt-2\">' + escapeHtml(content)", $script);

        self::assertStringNotContainsString("+ (blockGoal ? '<div class=\"small text-muted mt-2\">' + blockGoal + '</div>' : '')", $script);
        self::assertStringNotContainsString("+ (goal ? '<div class=\"small mt-2\">' + goal + '</div>' : '')", $script);
        self::assertStringContainsString('function normalizeStageOneStructuredRootForPreview', $script);
        self::assertStringNotContainsString('payload.plan_' . 'book.structured', $script);
        self::assertStringContainsString('function pickFirstNonEmptyPlanObject()', $script);
        self::assertStringContainsString('payload.plan_json', $script);
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
