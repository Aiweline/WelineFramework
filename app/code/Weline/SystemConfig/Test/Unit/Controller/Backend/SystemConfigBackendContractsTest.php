<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSystemConfigBackendContractsPass().
 */
final class SystemConfigBackendContractsTest extends TestCase
{
    public function testAllSystemConfigBackendContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ConfigCenterShareUrl::testIndexCanonicalizesShareQueryWithTargetScope' => function () {
            $this->assert_ConfigCenterShareUrl_testIndexCanonicalizesShareQueryWithTargetScope();
        },
        ];
    }

private function assert_ConfigCenterShareUrl_testIndexCanonicalizesShareQueryWithTargetScope(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Controller/Backend/Config.php');
    self::assertStringContainsString('buildConfigCenterShareQuery', $src);
    self::assertStringContainsString('configCenterShareQueryNeedsSync', $src);
    self::assertStringContainsString('configCenterScopeInputFromGet', $src);
    self::assertStringContainsString("'target_scope' => \$storageScope", $src);
    self::assertStringContainsString('weline_systemconfig/backend/config', $src);
    self::assertStringContainsString("return \$this->redirect('weline_systemconfig/backend/config'", $src);
    self::assertStringNotContainsString("return \$this->redirect(\n                \$this->request->getUrlBuilder()->getBackendUrl(", $src);
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/templates/backend/config/index.phtml');
    self::assertStringContainsString('id="wsc-filter-target-scope"', $tpl);
    self::assertStringContainsString('name="target_scope"', $tpl);
    self::assertStringContainsString('wscOnWebsiteFilterChange', $tpl);
    self::assertStringContainsString('wsc-website-code_value', $tpl);
    self::assertStringContainsString('default.default.default', $tpl);
    self::assertStringContainsString('w-system-config__adapter-callback', $tpl);
    self::assertStringContainsString('system-config-adapter-callback-url', $tpl);
    self::assertStringContainsString('adapterCallbackUrl', $tpl);
    self::assertStringContainsString('withoutStorefrontLocalizationPrefix', $tpl);
    self::assertStringContainsString('callback-append-scope', $tpl);
    self::assertStringContainsString('$appendScope', $tpl);
    self::assertStringContainsString('callback-as-origin', $tpl);
    self::assertStringContainsString('callback-as-host', $tpl);
    self::assertStringContainsString('$asOrigin', $tpl);
    self::assertStringContainsString('SCOPE_GLOBAL', $src);
    self::assertStringContainsString('显式 Global storage scope', $src);
}
}
