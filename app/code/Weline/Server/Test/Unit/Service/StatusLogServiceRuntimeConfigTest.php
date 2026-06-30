<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Server\Service\StatusLogService;

final class StatusLogServiceRuntimeConfigTest extends TestCase
{
    private string $envPath = '';
    private string $originalEnvContent = '';

    protected function setUp(): void
    {
        $this->envPath = Env::path_ENV_FILE;
        $this->originalEnvContent = \is_file($this->envPath)
            ? (string) \file_get_contents($this->envPath)
            : "<?php return [];";

        \file_put_contents($this->envPath, "<?php return [];\n");
        Env::getInstance()->reload();
        Runtime::resetModeCache();
        StatusLogService::reset();
    }

    protected function tearDown(): void
    {
        \file_put_contents($this->envPath, $this->originalEnvContent);
        Env::getInstance()->reload();
        Runtime::resetModeCache();
        StatusLogService::reset();
    }

    public function testWlsRuntimeDisablesDatabaseStatusLoggingByDefault(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);

        self::assertFalse(StatusLogService::isEnabled());
    }

    public function testCliRuntimeKeepsDatabaseStatusLoggingEnabledByDefault(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_CLI);

        self::assertTrue(StatusLogService::isEnabled());
    }

    public function testManualOverrideStillControlsStatusLogging(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);

        StatusLogService::setEnabled(true);
        self::assertTrue(StatusLogService::isEnabled());

        StatusLogService::setEnabled(false);
        self::assertFalse(StatusLogService::isEnabled());
    }

    public function testEnvConfigCanExplicitlyEnableWlsDatabaseStatusLogging(): void
    {
        \file_put_contents($this->envPath, <<<'PHP'
<?php return [
    'wls' => [
        'status_log' => [
            'enabled' => true,
        ],
    ],
];
PHP);
        Env::getInstance()->reload();
        StatusLogService::reset();
        Runtime::setMode(RuntimeInterface::MODE_WLS);

        self::assertTrue(StatusLogService::isEnabled());
    }
}
