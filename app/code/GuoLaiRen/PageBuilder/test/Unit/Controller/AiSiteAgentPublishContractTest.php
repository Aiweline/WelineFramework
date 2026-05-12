<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class AiSiteAgentPublishContractTest extends TestCase
{
    public function testPublishStartFailuresExposeStableMachineReadableCodes(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handleStartPublish');

        foreach ([
            "'code' => 'LATEST_AI_BUILD_FAILED'",
            "'code' => 'PLAN_NOT_CONFIRMED'",
            "'code' => 'BUILD_PLAN_NOT_CONFIRMED'",
            "'code' => 'WORKSPACE_NOT_READY'",
            "'code' => 'PUBLISH_QUALITY_GATE_FAILED'",
            "'code' => 'VISUAL_THEME_CONFIRM_REQUIRED'",
        ] as $expectedCode) {
            self::assertStringContainsString($expectedCode, $methodSource);
        }
    }

    public function testPublishChecklistPayloadExposesStableResultAndItemCodes(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handlePublishChecklist');

        foreach ([
            "'code' => 'PLAN_NOT_CONFIRMED'",
            "'code' => 'BUILD_PLAN_NOT_CONFIRMED'",
            "'code' => 'LATEST_AI_BUILD_FAILED'",
            "'code' => 'DRAFT_WEBSITE_READY'",
            "'code' => 'VIRTUAL_THEME_READY'",
            "'code' => 'WEBSITE_PROFILE_READY'",
            "'code' => 'VIRTUAL_PAGES_READY'",
            "'code' => 'VISUAL_EDITOR_READY'",
            "'code' => \$passed ? 'PUBLISH_CHECKLIST_PASSED' : 'PUBLISH_CHECKLIST_BLOCKED'",
        ] as $expectedCode) {
            self::assertStringContainsString($expectedCode, $methodSource);
        }
        self::assertStringNotContainsString("'code' => 'HTML_BLOCKS_READY'", $methodSource);
        self::assertStringNotContainsString("'code' => 'SITE_READY'", $methodSource);
    }

    public function testWorkspaceSnapshotHandlerRemainsReadOnlyForWorkbenchStepStatus(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handleWorkspaceSnapshot');

        self::assertStringNotContainsString('workbench_step_status', $methodSource);
        self::assertStringNotContainsString('mergeScope(', $methodSource);
        self::assertStringNotContainsString('replaceScope(', $methodSource);
        self::assertStringContainsString('buildWorkspaceState($fresh, $adminId, 12, false)', $methodSource);
    }

    private function extractMethodSource(string $source, string $methodName): string
    {
        $start = \strpos($source, 'function ' . $methodName . '(');
        self::assertIsInt($start, $methodName . ' missing');

        $next = \strpos($source, "\n    private function ", $start + 1);
        if ($next === false) {
            $next = \strpos($source, "\n    public function ", $start + 1);
        }

        return $next === false ? \substr($source, $start) : \substr($source, $start, $next - $start);
    }
}
