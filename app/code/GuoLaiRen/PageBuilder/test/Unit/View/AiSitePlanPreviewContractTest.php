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

    public function testConfirmedPlanJsonViewUsesStructuredBlockPreview(): void
    {
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        $showConfirmedPlan = $this->extractFunctionBody($script, 'showConfirmedPlanModal');

        self::assertStringContainsString('structured: planData', $showConfirmedPlan);
        self::assertStringContainsString('plan_json: planData', $showConfirmedPlan);
        self::assertStringContainsString('planJsonPreviewHtml(markdown, previewPayload)', $showConfirmedPlan);
        self::assertStringNotContainsString('JSON.stringify(planData, null, 2)', $showConfirmedPlan);
    }

    public function testPlanJsonBlockPreviewIgnoresPageMetadataKeys(): void
    {
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        $metaKeyFilter = $this->extractFunctionBody($script, 'isPlanJsonPageMetaKey');

        foreach ([
            'style_settings',
            'section_refinements',
            'preview_full_url',
            'visual_preview_url',
            'visual_edit_url',
            'virtual_preview_url',
            'virtual_edit_url',
            'route_path',
            'style_code',
            'ai_description',
            'primary_keywords',
            'secondary_keywords',
        ] as $key) {
            self::assertStringContainsString("'" . $key . "'", $metaKeyFilter);
        }
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
