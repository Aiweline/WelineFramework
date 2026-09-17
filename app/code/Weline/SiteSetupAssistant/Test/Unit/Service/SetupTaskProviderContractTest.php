<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 建站任务：抽象 Provider + Collector；浮层禁止硬编码业务 tips。
 */
final class SetupTaskProviderContractTest extends TestCase
{
    public function testAbstractProviderAndCollectorReplaceLegacyStatusOverride(): void
    {
        $root = dirname(__DIR__, 3);
        $interface = (string)file_get_contents($root . '/Api/SetupTaskProviderInterface.php');
        $abstract = (string)file_get_contents($root . '/Api/AbstractSetupTaskProvider.php');
        $collector = (string)file_get_contents($root . '/Service/SetupTaskCollector.php');
        $extends = (string)file_get_contents($root . '/extends.php');
        $float = (string)file_get_contents(
            $root . '/view/templates/backend/widgets/site-setup-assistant-float.phtml'
        );

        self::assertFileDoesNotExist($root . '/Api/SetupTaskStatusProviderInterface.php');
        self::assertFileDoesNotExist($root . '/Service/SetupTaskStatusCollector.php');

        self::assertStringContainsString('interface SetupTaskProviderInterface', $interface);
        self::assertStringContainsString('provideTasks', $interface);
        self::assertStringContainsString('abstract class AbstractSetupTaskProvider', $abstract);
        self::assertStringContainsString('implements SetupTaskProviderInterface', $abstract);
        self::assertStringContainsString('class SetupTaskCollector', $collector);
        self::assertStringContainsString('collectGlobalOverview', $collector);
        self::assertStringContainsString('summarizeIncompleteSites', $collector);
        self::assertStringContainsString('site_coverage', $collector);
        self::assertStringContainsString('SetupTaskProviderInterface::class', $collector);
        self::assertStringContainsString("'todo' => 0", $collector);
        self::assertStringContainsString('SetupTaskProviderInterface::class', $extends);

        self::assertStringContainsString('resolveConfigProvenance', $abstract);
        self::assertStringContainsString('isEffectivelyEnabled', $abstract);
        self::assertStringContainsString('anyConfigEffectivelyTruthy', $abstract);

        self::assertStringContainsString('SetupTaskCollector', $float);
        self::assertStringContainsString('collectGlobalOverview', $float);
        self::assertStringContainsString('summarizeIncompleteSites', $float);
        self::assertStringNotContainsString("'code' => 'captcha'", $float);
        self::assertStringNotContainsString("'code' => 'paypal'", $float);
        self::assertStringNotContainsString('SetupTaskStatusCollector', $float);
        self::assertStringNotContainsString('$page(\'smtp/backend/config\')', $float);
        self::assertStringNotContainsString('dashboard_website_id', $float);
    }
}
