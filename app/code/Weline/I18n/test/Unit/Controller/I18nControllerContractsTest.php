<?php

declare(strict_types=1);

namespace Weline\I18n\test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nControllerContractsPass().
 */
final class I18nControllerContractsTest extends TestCase
{
    public function testAllI18nControllerContractsPass(): void
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
            'AiTranslationQueueResultBound::testBuildLocaleRowsBoundsQueueResultBeforeTemplateAssign' => function () {
            $this->setUp_AiTranslationQueueResultBound();
            $this->assert_AiTranslationQueueResultBound_testBuildLocaleRowsBoundsQueueResultBeforeTemplateAssign();
        },
        ];
    }

private function setUp_AiTranslationQueueResultBound(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR);
    }
}

private function assert_AiTranslationQueueResultBound_testBuildLocaleRowsBoundsQueueResultBeforeTemplateAssign(): void
{
    $controller = $this->read_AiTranslationQueueResultBound('app/code/Weline/I18n/Controller/Backend/AiTranslation.php');
    self::assertMatchesRegularExpression('/QUEUE_RESULT_DISPLAY_MAX_BYTES\s*=\s*\d+/', $controller);
    self::assertStringContainsString('boundQueueResultForDisplay', $controller);
    self::assertStringContainsString("'queue_result' => \$this->boundQueueResultForDisplay(\$queueResult)", $controller);
    self::assertLessThan(65536, $this->extractDisplayMaxBytes_AiTranslationQueueResultBound($controller), 'AI translation page must keep display result well under WLS capture_limit (16MB).');
}

private function extractDisplayMaxBytes_AiTranslationQueueResultBound(string $controller): int
{
    if (!\preg_match('/QUEUE_RESULT_DISPLAY_MAX_BYTES\s*=\s*(\d+)/', $controller, $m)) {
        self::fail('QUEUE_RESULT_DISPLAY_MAX_BYTES missing');
    }
    return (int) $m[1];
}

private function read_AiTranslationQueueResultBound(string $relativePath): string
{
    $path = BP . \ltrim($relativePath, '/\\');
    self::assertFileExists($path);
    return (string) \file_get_contents($path);
}
}
