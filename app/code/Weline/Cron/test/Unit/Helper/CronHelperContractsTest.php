<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Cron\Helper\CronStatus;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCronHelperContractsPass().
 */
final class CronHelperContractsTest extends TestCase
{
    public function testAllCronHelperContractsPass(): void
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
            'CronStatusQuietProgress::testDisplayProgressBarGuardsNonTty' => function () {
            $this->setUpBeforeClass_CronStatusQuietProgress();
            $this->assert_CronStatusQuietProgress_testDisplayProgressBarGuardsNonTty();
        },
            'CronStatusQuietProgress::testQuietWhenStdoutNotTty' => function () {
            $this->setUpBeforeClass_CronStatusQuietProgress();
            $this->assert_CronStatusQuietProgress_testQuietWhenStdoutNotTty();
        },
        ];
    }

private function setUpBeforeClass_CronStatusQuietProgress(): void
{
    parent::setUpBeforeClass();
    if (!\defined('BP')) {
        $dir = __DIR__;
        while ($dir !== \dirname($dir)) {
            if (\is_file($dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w')) {
                \define('BP', $dir . DIRECTORY_SEPARATOR);
                break;
            }
            $dir = \dirname($dir);
        }
    }
    self::assertTrue(\defined('BP'), 'BP must resolve to repository root');
}

private function assert_CronStatusQuietProgress_testDisplayProgressBarGuardsNonTty(): void
{
    $path = \BP . 'app/code/Weline/Cron/Helper/CronStatus.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('shouldDisplayInteractiveProgress', $src);
    self::assertStringContainsString('stream_isatty', $src);
    self::assertStringContainsString('var/cron.log', $src);
    $gatePos = \strpos($src, 'shouldDisplayInteractiveProgress');
    $echoPos = \strpos($src, 'echo "\033[34m');
    self::assertNotFalse($gatePos);
    self::assertNotFalse($echoPos);
    self::assertLessThan($echoPos, $gatePos);
}

private function assert_CronStatusQuietProgress_testQuietWhenStdoutNotTty(): void
{
    // PHPUnit / redirected runs are typically non-TTY → must stay silent.
    if (\defined('STDOUT') && \is_resource(STDOUT) && \function_exists('stream_isatty') && @\stream_isatty(STDOUT)) {
        self::markTestSkipped('STDOUT is a TTY in this runner; quiet path needs non-TTY');
    }
    self::assertFalse(CronStatus::shouldDisplayInteractiveProgress());
    \ob_start();
    CronStatus::displayProgressBar('quiet-contract', 1, 10, false);
    $out = \ob_get_clean();
    self::assertSame('', $out);
}
}
