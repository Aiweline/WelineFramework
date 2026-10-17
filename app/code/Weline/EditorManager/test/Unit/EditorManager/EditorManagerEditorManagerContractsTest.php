<?php

declare(strict_types=1);

namespace Weline\EditorManager\test\Unit\EditorManager;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllEditorManagerEditorManagerContractsPass().
 */
final class EditorManagerEditorManagerContractsTest extends TestCase
{
    public function testAllEditorManagerEditorManagerContractsPass(): void
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
            'LocalPlaceholderDescription::testLocalSourceClarifiesPlaceholderNotLocalInstalledEditor' => function () {
            $this->assert_LocalPlaceholderDescription_testLocalSourceClarifiesPlaceholderNotLocalInstalledEditor();
        },
        ];
    }

private function assert_LocalPlaceholderDescription_testLocalSourceClarifiesPlaceholderNotLocalInstalledEditor(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/EditorManager' . '/EditorManager/Local.php');
    self::assertStringContainsString('占位回退', $source);
    self::assertStringContainsString('非可用富文本', $source);
    self::assertStringContainsString('不是本机已安装的本地编辑器', $source);
    self::assertStringNotContainsString('暂无标记器', $source);
}
}
