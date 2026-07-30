<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

\defined('DS') || \define('DS', DIRECTORY_SEPARATOR);
\defined('BP') || \define('BP', \rtrim(\dirname(__DIR__, 7), '\\/') . DS);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('DEV_PATH') || \define('DEV_PATH', BP . 'dev' . DS);
\defined('PUB') || \define('PUB', BP . 'pub' . DS);

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

        $this->writeEnvContent("<?php return [];\n");
        Env::getInstance()->reload();
        Runtime::resetModeCache();
        StatusLogService::reset();
    }

    protected function tearDown(): void
    {
        $this->writeEnvContent($this->originalEnvContent);
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
        $this->writeEnvContent(<<<'PHP'
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

    private function writeEnvContent(string $content): void
    {
        \file_put_contents($this->envPath, $content);
        \clearstatcache(true, $this->envPath);
        if (\function_exists('opcache_invalidate')) {
            @\opcache_invalidate($this->envPath, true);
        }
    }
}
