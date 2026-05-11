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
}
