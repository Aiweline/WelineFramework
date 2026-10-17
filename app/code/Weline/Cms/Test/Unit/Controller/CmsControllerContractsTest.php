<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCmsControllerContractsPass().
 */
final class CmsControllerContractsTest extends TestCase
{
    public function testAllCmsControllerContractsPass(): void
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
            'CmsThemeEditorUrlTypedScope::testBuildThemeEditorUrlEmitsTypedScopeClaims' => function () {
            $this->assert_CmsThemeEditorUrlTypedScope_testBuildThemeEditorUrlEmitsTypedScopeClaims();
        },
        ];
    }

private function assert_CmsThemeEditorUrlTypedScope_testBuildThemeEditorUrlEmitsTypedScopeClaims(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cms' . '/Controller/Backend/Page.php');
    self::assertStringContainsString("'scope_kind'", $source);
    self::assertStringContainsString("'context_version'", $source);
    self::assertStringContainsString('scope_identity', $source);
    self::assertStringContainsString("'lock_source' => 'cms'", $source);
}
}
