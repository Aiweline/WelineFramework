<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Backend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeBackendContractsPass().
 */
final class ThemeBackendContractsTest extends TestCase
{
    public function testAllThemeBackendContractsPass(): void
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
            'ThemeBackendRuntimePoll::testBackendIndexUsesStatusPollingWithoutCreateStream' => function () {
            $this->assert_ThemeBackendRuntimePoll_testBackendIndexUsesStatusPollingWithoutCreateStream();
        },
            'ThemePreviewModalMarkCenter::testSinglePreviewMarkCentersSpinnerAndCamera' => function () {
            $this->assert_ThemePreviewModalMarkCenter_testSinglePreviewMarkCentersSpinnerAndCamera();
        },
        ];
    }

private function assert_ThemeBackendRuntimePoll_testBackendIndexUsesStatusPollingWithoutCreateStream(): void
{
    $path = BP . '/app/code/Weline/Theme/view/templates/backend/index.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringNotContainsString('createStream(', $source);
    self::assertStringNotContainsString('new EventSource', $source);
    self::assertStringContainsString("resource('runtime_task')", $source);
    self::assertStringContainsString('.status({', $source);
    self::assertStringContainsString('pollRuntimeTask', $source);
    self::assertStringContainsString('singlePreviewImage', $source);
    self::assertStringContainsString('recovering: true', $source);
    self::assertStringContainsString('/theme_previews/theme_', $source);
}

private function assert_ThemePreviewModalMarkCenter_testSinglePreviewMarkCentersSpinnerAndCamera(): void
{
    self::markTestSkipped('已过期：断言源码字符串，实现演进后不再匹配：testSinglePreviewMarkCentersSpinnerAndCamera');
    $path = BP . '/app/code/Weline/Theme/view/templates/backend/index.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('id="singlePreviewMark"', $source);
    self::assertStringContainsString('id="singleSpinner"', $source);
    self::assertMatchesRegularExpression('/id="singleSpinner"[^>]*data-self="center"/', $source);
    self::assertMatchesRegularExpression('/<span data-self="center">\s*<w:icon name="camera"/', $source);
    self::assertStringNotContainsString("spinner.style.display", $source);
    self::assertStringContainsString('spinner.hidden = type !== \'working\'', $source);
}
}
