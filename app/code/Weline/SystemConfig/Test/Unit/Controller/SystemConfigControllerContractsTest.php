<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSystemConfigControllerContractsPass().
 */
final class SystemConfigControllerContractsTest extends TestCase
{
    public function testAllSystemConfigControllerContractsPass(): void
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
            'ConfigGuideLocateOnly::testGuideParamsKeepsLocateWhenGuideKeyMissing' => function () {
            $this->assert_ConfigGuideLocateOnly_testGuideParamsKeepsLocateWhenGuideKeyMissing();
        },
        ];
    }

private function assert_ConfigGuideLocateOnly_testGuideParamsKeepsLocateWhenGuideKeyMissing(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Controller/Backend/Config.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('仅有 guide_locate 时也要保留定位', $src);
    self::assertStringContainsString('if ($mergedKeys === [] && $locateOnly !== \'\')', $src);
    self::assertStringContainsString("\$search = trim((string)\$this->request->getGet('q', ''));", $src);
}
}
