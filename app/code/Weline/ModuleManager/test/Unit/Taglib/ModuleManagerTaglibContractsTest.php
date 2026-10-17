<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllModuleManagerTaglibContractsPass().
 */
final class ModuleManagerTaglibContractsTest extends TestCase
{
    public function testAllModuleManagerTaglibContractsPass(): void
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
            'ModuleSelectThemeTokens::testModuleSelectStylesPreferThemeTokensOverHardcodedLightPalette' => function () {
            $this->assert_ModuleSelectThemeTokens_testModuleSelectStylesPreferThemeTokensOverHardcodedLightPalette();
        },
        ];
    }

private function assert_ModuleSelectThemeTokens_testModuleSelectStylesPreferThemeTokensOverHardcodedLightPalette(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/ModuleManager' . '/Taglib/ModuleSelect.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('--weline-theme-surface', $content);
    self::assertStringContainsString('--weline-theme-surface-raised', $content);
    self::assertStringContainsString('--weline-theme-surface-muted', $content);
    self::assertStringContainsString('--weline-theme-surface-hover', $content);
    self::assertStringContainsString('--weline-theme-text', $content);
    self::assertStringContainsString('--weline-theme-border', $content);
    self::assertStringContainsString('--weline-theme-primary', $content);
    self::assertStringContainsString('background:var(--weline-theme-surface,var(--backend-color-card-bg,#fff))', $content);
    self::assertStringNotContainsString('background:#f8fafc', $content);
    self::assertStringNotContainsString('background:#f1f5f9', $content);
    self::assertStringNotContainsString('color:#162033', $content);
}
}
