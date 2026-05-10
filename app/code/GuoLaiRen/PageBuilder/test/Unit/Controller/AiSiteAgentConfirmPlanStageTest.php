<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class AiSiteAgentConfirmPlanStageTest extends TestCase
{
    public function testConfirmPlanReadsPlanStageScopeBeforeRequestedStageContext(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);

        $methodStart = \strpos($source, 'private function handleConfirmPlan(): string');
        self::assertNotFalse($methodStart);
        $methodBody = \substr($source, $methodStart, 2600);

        $requestedStagePos = \strpos($methodBody, '$requestedStage = $this->scopeCompatibilityService->normalizeStage(');
        $planScopeLoadPos = \strpos($methodBody, '$this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)');
        $requestedStageGuardPos = \strpos($methodBody, '$requestedStage !== \'\' && $requestedStage !== AiSiteAgentSession::STAGE_PLAN');
        $requestedStageScopeLoadPos = \strpos($methodBody, '$this->sessionService->loadScopeForStage($session, $requestedStage)');

        self::assertNotFalse($requestedStagePos);
        self::assertNotFalse($planScopeLoadPos);
        self::assertNotFalse($requestedStageGuardPos);
        self::assertNotFalse($requestedStageScopeLoadPos);
        self::assertLessThan($planScopeLoadPos, $requestedStagePos);
        self::assertLessThan($requestedStageGuardPos, $planScopeLoadPos);
        self::assertLessThan($requestedStageScopeLoadPos, $requestedStageGuardPos);
        self::assertStringContainsString("getRequestBodyValue('stage', '')", $methodBody);
        self::assertStringContainsString('$scope = $this->scopeCompatibilityService->normalizeScope(\array_replace($requestedStageScope, $scope));', $methodBody);
    }

    public function testStartTaskPlanReadsVisualEditScopeBeforeRequestedStageContext(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);

        $methodStart = \strpos($source, 'private function handleStartTaskPlan(): string');
        self::assertNotFalse($methodStart);
        $methodBody = \substr($source, $methodStart, 2800);

        $requestedStagePos = \strpos($methodBody, '$requestedStage = $this->scopeCompatibilityService->normalizeStage(');
        $visualEditScopeLoadPos = \strpos($methodBody, '$this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)');
        $requestedStageGuardPos = \strpos($methodBody, '$requestedStage !== \'\' && $requestedStage !== AiSiteAgentSession::STAGE_VISUAL_EDIT');
        $requestedStageScopeLoadPos = \strpos($methodBody, '$this->sessionService->loadScopeForStage($session, $requestedStage)');

        self::assertNotFalse($requestedStagePos);
        self::assertNotFalse($visualEditScopeLoadPos);
        self::assertNotFalse($requestedStageGuardPos);
        self::assertNotFalse($requestedStageScopeLoadPos);
        self::assertLessThan($visualEditScopeLoadPos, $requestedStagePos);
        self::assertLessThan($requestedStageGuardPos, $visualEditScopeLoadPos);
        self::assertLessThan($requestedStageScopeLoadPos, $requestedStageGuardPos);
        self::assertStringContainsString("getRequestBodyValue('stage', '')", $methodBody);
        self::assertStringContainsString('$scope = $this->normalizePlanConfirmationForTaskPlan($session, $adminId, $scope);', $methodBody);
    }
}
