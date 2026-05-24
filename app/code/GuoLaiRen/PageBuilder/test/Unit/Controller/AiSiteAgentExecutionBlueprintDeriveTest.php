<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiSiteAgentExecutionBlueprintDeriveTest extends TestCase
{
    public function testLegacyTaskPlanArtifactDerivationIsDeletedFromController(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $controllerSource = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');
        $reflection = new ReflectionClass(AiSiteAgent::class);

        self::assertIsString($controllerSource);
        self::assertFalse($reflection->hasMethod('deriveTaskPlanArtifactsFromExecutionBlueprint'));
        self::assertStringContainsString('build_plan_v2', $controllerSource);
        self::assertStringContainsString('plan_projection', $controllerSource);
        self::assertStringContainsString('content_manifest', $controllerSource);
        self::assertStringNotContainsString('task_plan_structured', $controllerSource);
        self::assertStringNotContainsString('virtual_theme_plan', $controllerSource);
    }

    public function testConfirmPlanReadinessAcceptsBuildPlanV2WithoutExecutionBlueprintDraft(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $controllerSource = (string)\file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractControllerMethodSource($controllerSource, 'handleConfirmPlan');

        self::assertStringContainsString('$existingBuildPlanV2 = \is_array($scope[\'build_plan_v2\'] ?? null) ? $scope[\'build_plan_v2\'] : [];', $methodSource);
        self::assertStringContainsString('if ($executionBlueprintDraft === [] && $existingBuildPlanV2 === [])', $methodSource);
        self::assertStringNotContainsString("if (\$executionBlueprintDraft === []) {\n            return \$this->jsonError('PLAN_NOT_READY'", $methodSource);
    }

    private function extractControllerMethodSource(string $source, string $methodName): string
    {
        $needle = 'private function ' . $methodName . '(';
        $start = \strpos($source, $needle);
        self::assertNotFalse($start, 'Controller method not found: ' . $methodName);
        $braceStart = \strpos($source, '{', (int)$start);
        self::assertNotFalse($braceStart, 'Controller method body not found: ' . $methodName);

        $depth = 0;
        $length = \strlen($source);
        for ($idx = (int)$braceStart; $idx < $length; $idx++) {
            $char = $source[$idx];
            if ($char === '{') {
                $depth++;
                continue;
            }
            if ($char !== '}') {
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return \substr($source, (int)$start, $idx - (int)$start + 1);
            }
        }

        self::fail('Controller method body end not found: ' . $methodName);
    }
}
