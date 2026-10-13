<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Cron\Helper\CronStatus;

final class CronStatusQuietProgressContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
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

    public function testDisplayProgressBarGuardsNonTty(): void
    {
        $path = \BP . 'app/code/Weline/Cron/Helper/CronStatus.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('shouldDisplayInteractiveProgress', $src);
        self::assertStringContainsString('stream_isatty', $src);
        self::assertStringContainsString('var/cron.log', $src);

        $gatePos = \strpos($src, 'shouldDisplayInteractiveProgress');
        $echoPos = \strpos($src, 'echo "\\033[34m');
        self::assertNotFalse($gatePos);
        self::assertNotFalse($echoPos);
        self::assertLessThan($echoPos, $gatePos);
    }

    public function testQuietWhenStdoutNotTty(): void
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
