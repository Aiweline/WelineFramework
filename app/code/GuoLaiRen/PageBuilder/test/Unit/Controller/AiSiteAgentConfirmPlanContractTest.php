<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiSiteAgentConfirmPlanContractTest extends TestCase
{
    public function testConfirmPlanOnlyMarksPlanJsonConfirmedAndCreatesBuildQueueWhenMissing(): void
    {
        $source = (string)\file_get_contents((new ReflectionClass(AiSiteAgent::class))->getFileName());
        $confirmPlan = $this->extractMethodSource($source, 'handleConfirmPlan');

        self::assertStringContainsString('$planArtifactKeys = [\'plan_json\'];', $confirmPlan);
        self::assertStringContainsString('$scopePatch = $planJsonEditor->setConfirmedScopePatch($planJson, true);', $confirmPlan);
        self::assertStringContainsString('$existingBuildQueue = $this->findAiSiteOperationQueueRow($fresh, \'build\', 0, true);', $confirmPlan);
        self::assertStringContainsString("'message' => (string)__('Plan JSON confirmed; build queue already exists.')", $confirmPlan);
        self::assertStringContainsString('$buildStartResult = $this->startOperation(', $confirmPlan);
        self::assertStringContainsString("'build'", $confirmPlan);
        self::assertStringContainsString('AiSiteAgentSession::STAGE_VISUAL_EDIT', $confirmPlan);

        foreach ([
            'content_manifest',
            'confirm_only',
            'build_deferred',
            'force_confirm_stale_plan',
            'prepareStageOnePlanScopeForConfirmation',
            'hydrateStageOnePlanPayloadFromPlanStageScope',
            'resolveSelectionForScope',
            'PlanJsonJsonConfirmationScopePatch',
            'buildConfirmationScopePatch',
            'collectMissingSelectedPlanPageTypes',
            'hasRetryableAiFailures',
            'ensureTaskScope(',
            'extractPlanJsonDerivedScopePatch',
            'plan_json_task_summary',
            'confirmed_plan_signature',
            'fresh_repair_failed_tasks',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $confirmPlan);
        }

        self::assertStringNotContainsString('function PlanJsonJsonConfirmationScopePatch(', $source);
        self::assertStringNotContainsString('function hasBuildQueueForPlanConfirmation(', $source);
    }

    private function extractMethodSource(string $source, string $method): string
    {
        $needle = 'function ' . $method . '(';
        $start = \strpos($source, $needle);
        self::assertIsInt($start);

        $brace = \strpos($source, '{', $start);
        self::assertIsInt($brace);
        $depth = 0;
        $length = \strlen($source);
        for ($i = $brace; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return \substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail('Unable to extract method source for ' . $method);
    }
}
