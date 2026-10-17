<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Design;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeDesignContractsPass().
 */
final class ThemeDesignContractsTest extends TestCase
{
    public function testAllThemeDesignContractsPass(): void
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
            'EditorAreaDemoTheme::testDemoThemeShipsFrontendAndBackendSkins' => function () {
            $this->assert_EditorAreaDemoTheme_testDemoThemeShipsFrontendAndBackendSkins();
        },
        ];
    }

private function assert_EditorAreaDemoTheme_testDemoThemeShipsFrontendAndBackendSkins(): void
{
    $root = '/Users/weline/Project/Official/框架/app' . '/design/Weline/editor-area-demo';
    self::assertDirectoryExists($root, 'editor-area-demo design theme missing');
    self::assertDirectoryExists($root . '/frontend');
    self::assertDirectoryExists($root . '/backend');
    self::assertFileExists($root . '/register.php');
    self::assertFileExists($root . '/frontend/colors/_editor-area-demo.css');
    self::assertFileExists($root . '/backend/colors/_editor-area-demo.css');
    self::assertFileExists($root . '/backend/assets/css/editor-area-demo.css');
    $register = (string) file_get_contents($root . '/register.php');
    self::assertStringContainsString("'name' => 'editor-area-demo'", $register);
    self::assertStringContainsString('Weline_EditorAreaDemo', $register);
}
}
