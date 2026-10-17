<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Extends\Module\Weline_Framework\Fpc\Bypass\CdnFpcDevModeBypassProvider;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCdnFpcContractsPass().
 */
final class CdnFpcContractsTest extends TestCase
{
    public function testAllCdnFpcContractsPass(): void
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
            'CdnFpcDevModeBypassProvider::testProviderDeclaresCdnFpcDevModeEnvFlag' => function () {
            $this->assert_CdnFpcDevModeBypassProvider_testProviderDeclaresCdnFpcDevModeEnvFlag();
        },
            'CdnFpcDevModeBypassProvider::testSystemConfigTemplateDeclaresScopedDevModeKey' => function () {
            $this->assert_CdnFpcDevModeBypassProvider_testSystemConfigTemplateDeclaresScopedDevModeKey();
        },
            'CdnFpcDevModeBypassProvider::testModuleProvidesFlagResolverInterface' => function () {
            $this->assert_CdnFpcDevModeBypassProvider_testModuleProvidesFlagResolverInterface();
        },
        ];
    }

private function assert_CdnFpcDevModeBypassProvider_testProviderDeclaresCdnFpcDevModeEnvFlag(): void
{
    $rules = (new CdnFpcDevModeBypassProvider())->rules();
    self::assertCount(1, $rules);
    self::assertSame('cdn.fpc_dev_mode', $rules[0]['id']);
    self::assertSame(['cdn_fpc_dev_mode'], $rules[0]['match']['env_flags']);
    self::assertSame('bypass_serve_and_publish', $rules[0]['effect']);
}

private function assert_CdnFpcDevModeBypassProvider_testSystemConfigTemplateDeclaresScopedDevModeKey(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/extends/module/Weline_SystemConfig/Config/backend/cdn-fpc-dev-mode.phtml';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('cdn/fpc/dev_mode', $src);
    self::assertStringContainsString('website,store,channel', $src);
    self::assertStringContainsString('@Extra type=fpc', $src);
}

private function assert_CdnFpcDevModeBypassProvider_testModuleProvidesFlagResolverInterface(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/etc/module.php';
    self::assertArrayHasKey(\Weline\Framework\Http\Fpc\CdnFpcDevModeFlagResolverInterface::class, $module['provides']);
}
}
