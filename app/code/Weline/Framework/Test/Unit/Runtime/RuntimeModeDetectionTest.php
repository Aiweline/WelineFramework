<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class RuntimeModeDetectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Runtime::resetModeCache();
    }

    public function testWlsModeConstantOverridesEarlierCachedCliMode(): void
    {
        $projectRoot = \dirname(__DIR__, 7);
        $autoloadPath = \var_export($projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', true);
        $script = \tempnam(\sys_get_temp_dir(), 'weline-runtime-mode-');
        self::assertIsString($script);
        $script .= '.php';

        \file_put_contents($script, <<<PHP
<?php
require {$autoloadPath};

use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

Runtime::setMode(RuntimeInterface::MODE_CLI);
define('WLS_MODE', true);

echo Runtime::isWls()
    && Runtime::isPersistent()
    && Runtime::getMode() === RuntimeInterface::MODE_WLS
        ? 'WELINE_RUNTIME_WLS_OK'
        : 'WELINE_RUNTIME_WLS_FAIL';
PHP);

        $command = \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($script) . ' 2>NUL';

        try {
            $output = [];
            $exitCode = 1;
            \exec($command, $output, $exitCode);

            self::assertContains('WELINE_RUNTIME_WLS_OK', $output);
        } finally {
            @\unlink($script);
        }
    }
}
