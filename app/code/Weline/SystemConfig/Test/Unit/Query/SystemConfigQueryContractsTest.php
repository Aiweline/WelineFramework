<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Extends\Module\Weline_Framework\Query\SystemConfigQueryProvider;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSystemConfigQueryContractsPass().
 */
final class SystemConfigQueryContractsTest extends TestCase
{
    public function testAllSystemConfigQueryContractsPass(): void
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
            'SystemConfigSetScopedConfigFrontend::testSetScopedConfigIsExposedToBackendFrontendWorker' => function () {
            $this->assert_SystemConfigSetScopedConfigFrontend_testSetScopedConfigIsExposedToBackendFrontendWorker();
        },
        ];
    }

private function assert_SystemConfigSetScopedConfigFrontend_testSetScopedConfigIsExposedToBackendFrontendWorker(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/extends/module/Weline_Framework/Query/SystemConfigQueryProvider.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertMatchesRegularExpression("/'name'\\s*=>\\s*'setScopedConfig'[\\s\\S]*?'frontend'\\s*=>\\s*true/", $src);
    self::assertMatchesRegularExpression("/'name'\\s*=>\\s*'setScopedConfig'[\\s\\S]*?Weline_SystemConfig::config_center_save/", $src);
    self::assertMatchesRegularExpression("/'name'\\s*=>\\s*'setScopedConfig'[\\s\\S]*?'mode'\\s*=>\\s*'write'/", $src);
    self::assertStringContainsString('locale: array_key_exists(\'locale\', $params)', $src);
    self::assertStringContainsString('SystemConfig::LOCALE_DEFAULT', $src);
    self::assertMatchesRegularExpression("/function commonWriteParams[\\s\\S]*?'name'\\s*=>\\s*'value_type'/", $src);
}
}
