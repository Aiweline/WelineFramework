<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Service\ExternalManagedRuleOwnership;

final class ExternalManagedRuleOwnershipTest extends TestCase
{
    public function testParsesExternalManagedDescription(): void
    {
        $svc = new ExternalManagedRuleOwnership();
        $parsed = $svc->parse([
            'description' => '[weline:external_managed=1;source=promotion_activity_theme;module=Weline_Promotion;id=1;key=deals] sync',
            'actions_serialized' => '',
        ]);
        self::assertNotNull($parsed);
        self::assertSame('Weline_Promotion', $parsed['source_module']);
        self::assertSame('promotion_activity_theme', $parsed['source_type']);
        self::assertSame('1', $parsed['source_id']);
        self::assertSame('deals', $parsed['source_key']);
        self::assertTrue($svc->isExternallyManaged([
            'description' => '[weline:external_managed=1;source=promotion_activity_theme;module=Weline_Promotion;id=1;key=deals] sync',
        ]));
    }

    public function testManualRulesAreNotManaged(): void
    {
        $svc = new ExternalManagedRuleOwnership();
        self::assertFalse($svc->isExternallyManaged([
            'description' => 'manual automatic rule',
            'actions_serialized' => json_encode([['type' => 'discount_percentage', 'discount_value' => 10]], JSON_THROW_ON_ERROR),
        ]));
    }

    public function testDetectsManagedFlagFromActions(): void
    {
        $svc = new ExternalManagedRuleOwnership();
        self::assertTrue($svc->isExternallyManaged([
            'description' => '',
            'actions_serialized' => json_encode([[
                'type' => 'discount_percentage',
                'external_managed' => 1,
                'source_module' => 'Weline_Promotion',
                'source_type' => 'promotion_activity_theme',
                'source_id' => '1',
            ]], JSON_THROW_ON_ERROR),
        ]));
    }

    public function testControllerAndModelGuardContract(): void
    {
        $controller = dirname(__DIR__, 3) . '/Controller/Backend/Rule.php';
        $model = dirname(__DIR__, 3) . '/Model/Rule/Rule.php';
        $index = dirname(__DIR__, 3) . '/view/templates/backend/rule/index.phtml';
        self::assertFileExists($controller);
        self::assertFileExists($model);
        self::assertFileExists($index);
        $ctrl = (string)file_get_contents($controller);
        $mdl = (string)file_get_contents($model);
        $tpl = (string)file_get_contents($index);
        self::assertStringContainsString('function postDelete(): string', $ctrl);
        self::assertStringContainsString('ExternalManagedRuleOwnership', $ctrl);
        self::assertStringContainsString('mutationDeniedMessage($rule, \'save\')', $ctrl);
        self::assertStringContainsString('isExternallyManaged($this)', $mdl);
        self::assertStringContainsString('marketing-rule-external-badge', $tpl);
        self::assertStringContainsString('marketing-rule-delete-blocked', $tpl);
    }
}
