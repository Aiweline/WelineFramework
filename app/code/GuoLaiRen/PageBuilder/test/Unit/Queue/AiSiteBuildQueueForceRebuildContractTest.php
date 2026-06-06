<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\test\Unit\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiSiteBuildQueueForceRebuildContractTest extends TestCase
{
    public function testForceRebuildOnlyResetsPlanJsonBlockExecutionState(): void
    {
        $controllerSource = $this->classSource(AiSiteAgent::class);
        $queueSource = $this->classSource(AiSiteBuildQueue::class);
        $taskServiceSource = $this->classSource(AiSitePlanJsonTaskService::class);
        $virtualThemeServiceSource = $this->classSource(AiSiteVirtualThemeService::class);

        self::assertStringNotContainsString('clearBuildArtifactsForRegeneration', $controllerSource);
        self::assertStringNotContainsString('clearBuildArtifactsForRegeneration', $queueSource);
        self::assertStringNotContainsString('resetPlanJsonTasksToPendingForRebuild', $controllerSource);
        self::assertStringNotContainsString('resetPlanJsonTasksToPendingForRebuild', $queueSource);
        self::assertStringNotContainsString('resetGeneratedPageLayoutsForRebuild', $controllerSource);
        self::assertStringNotContainsString('resetGeneratedPageLayoutsForRebuild', $queueSource);

        self::assertStringNotContainsString('function clearBuildArtifactsForRegeneration(', $taskServiceSource);
        self::assertStringNotContainsString('function resetPlanJsonTasksToPendingForRebuild(', $taskServiceSource);
        self::assertStringNotContainsString('function resetGeneratedPageLayoutsForRebuild(', $virtualThemeServiceSource);

        $applyForcePreset = $this->extractMethodSource($queueSource, 'applyForceBuildQueuePreset');
        self::assertStringContainsString('resetBlockExecutionStateScopePatch(', $applyForcePreset);
        self::assertStringContainsString('AiSiteAgentSession::STAGE_VISUAL_EDIT', $applyForcePreset);
        self::assertStringContainsString("'_queue_force_build' => [", $applyForcePreset);
        self::assertStringNotContainsString('build_contracts', $applyForcePreset);
        self::assertStringNotContainsString('render_data_contract', $applyForcePreset);
        self::assertStringNotContainsString('content_manifest', $applyForcePreset);
        self::assertStringNotContainsString('extractPlanJsonDerivedScopePatch', $applyForcePreset);

        $startOperation = $this->extractMethodSource($controllerSource, 'startOperation');
        self::assertStringContainsString('$isQueueBackedOperation = $this->isAiSiteQueueBackedOperation($operation);', $startOperation);
        self::assertStringContainsString('$this->sessionService->loadScopeForStage($session, $stage, $isQueueBackedOperation ? [] : null)', $startOperation);
        self::assertStringContainsString('resetBlockExecutionStateScopePatch(', $startOperation);
        self::assertStringNotContainsString('loadScopeForBuildOperation($session)', $startOperation);
        self::assertStringNotContainsString('restorePlanJsonContract($scope', $startOperation);
        self::assertStringNotContainsString('extractPlanJsonDerivedScopePatch', $startOperation);

        $takeoverPolicy = $this->extractMethodSource($controllerSource, 'shouldForceQueueTakeoverForOperation');
        self::assertStringContainsString("if (\$operation === 'plan') {", $takeoverPolicy);
        self::assertStringNotContainsString("['plan', 'build']", $takeoverPolicy);
    }

    /**
     * @param class-string $className
     */
    private function classSource(string $className): string
    {
        return (string)\file_get_contents((new ReflectionClass($className))->getFileName());
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
