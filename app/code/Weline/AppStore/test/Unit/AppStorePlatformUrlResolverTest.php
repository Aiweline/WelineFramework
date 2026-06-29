<?php
declare(strict_types=1);

namespace Weline\AppStore\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\AppStore\Service\AppStorePlatformUrlResolver;

class AppStorePlatformUrlResolverTest extends TestCase
{
    private string|false $previousPlatformUrl;

    /** @var string[] */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPlatformUrl = getenv('WELINE_APPSTORE_PLATFORM_URL');
    }

    protected function tearDown(): void
    {
        if (is_string($this->previousPlatformUrl)) {
            putenv('WELINE_APPSTORE_PLATFORM_URL=' . $this->previousPlatformUrl);
        } else {
            putenv('WELINE_APPSTORE_PLATFORM_URL');
        }

        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    public function testLocalDeployModeUsesLocalAppStoreEnvironmentOverride(): void
    {
        $envFile = $this->writeTempFile('env.php', "<?php\nreturn ['system' => ['deploy' => 'dev']];\n");
        putenv('WELINE_APPSTORE_PLATFORM_URL=https://app.weline.test:9523/');

        $result = (new AppStorePlatformUrlResolver($envFile))->resolve();

        $this->assertSame('local', $result['environment']);
        $this->assertSame('https://app.weline.test:9523', $result['platform_url']);
        $this->assertSame('env:WELINE_APPSTORE_PLATFORM_URL', $result['source']);
    }

    public function testProductionModeIgnoresLocalEnvironmentResidueWhenDeployCurrentMissing(): void
    {
        $envFile = $this->writeTempFile('env.php', "<?php\nreturn ['system' => ['deploy' => 'prod']];\n");
        $missingDeployCurrent = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'missing-current.json';
        putenv('WELINE_APPSTORE_PLATFORM_URL=https://app.weline.test:9523');

        $result = (new AppStorePlatformUrlResolver($envFile, $missingDeployCurrent))->resolve();

        $this->assertSame('production', $result['environment']);
        $this->assertSame('https://app.aiweline.com', $result['platform_url']);
        $this->assertSame('default:production', $result['source']);
    }

    public function testProductionDeployCurrentWinsOverLocalEnvironmentResidue(): void
    {
        $envFile = $this->writeTempFile('env.php', "<?php\nreturn ['system' => ['deploy' => 'prod']];\n");
        $deployCurrent = $this->writeTempFile('current.json', json_encode([
            'appstore_environment' => 'production',
            'appstore_platform_url' => 'https://app.aiweline.com/',
            'appstore_platform_url_source' => 'production_default',
        ], JSON_THROW_ON_ERROR));
        putenv('WELINE_APPSTORE_PLATFORM_URL=https://app.weline.test:9523');

        $result = (new AppStorePlatformUrlResolver($envFile, $deployCurrent))->resolve();

        $this->assertSame('production', $result['environment']);
        $this->assertSame('https://app.aiweline.com', $result['platform_url']);
        $this->assertSame('deploy:var/deploy/current.json', $result['source']);
    }

    public function testProductionDeployCurrentRejectsLocalAppStoreAndFallsBackToProductionAppStore(): void
    {
        $envFile = $this->writeTempFile('env.php', "<?php\nreturn ['system' => ['deploy' => 'prod']];\n");
        $deployCurrent = $this->writeTempFile('current.json', json_encode([
            'appstore_environment' => 'production',
            'appstore_platform_url' => 'https://app.weline.test:9523',
            'appstore_platform_url_source' => 'production_default',
        ], JSON_THROW_ON_ERROR));

        $result = (new AppStorePlatformUrlResolver($envFile, $deployCurrent))->resolve();

        $this->assertSame('production', $result['environment']);
        $this->assertSame('https://app.aiweline.com', $result['platform_url']);
        $this->assertSame('default:production', $result['source']);
    }

    public function testLocalDeployModeRejectsOfficialWebsiteHostAndFallsBackToLocalAppStore(): void
    {
        $envFile = $this->writeTempFile('env.php', "<?php\nreturn ['system' => ['deploy' => 'local']];\n");
        putenv('WELINE_APPSTORE_PLATFORM_URL=https://www.weline.test:9518');

        $result = (new AppStorePlatformUrlResolver($envFile))->resolve();

        $this->assertSame('local', $result['environment']);
        $this->assertSame('https://app.weline.test:9523', $result['platform_url']);
        $this->assertContains($result['source'], ['config:appstore.platform_url', 'local_default']);
    }

    public function testLocalDeployModeRejectsCustomMarketplaceAndFallsBackToLocalAppStore(): void
    {
        $envFile = $this->writeTempFile('env.php', "<?php\nreturn ['system' => ['deploy' => 'local']];\n");
        putenv('WELINE_APPSTORE_PLATFORM_URL=https://staging.example.test:9443');

        $result = (new AppStorePlatformUrlResolver($envFile))->resolve();

        $this->assertSame('local', $result['environment']);
        $this->assertSame('https://app.weline.test:9523', $result['platform_url']);
        $this->assertContains($result['source'], ['config:appstore.platform_url', 'local_default']);
    }

    public function testProductionDeployCurrentRejectsOfficialWebsiteHostAndFallsBackToProductionAppStore(): void
    {
        $envFile = $this->writeTempFile('env.php', "<?php\nreturn ['system' => ['deploy' => 'prod']];\n");
        $deployCurrent = $this->writeTempFile('current.json', json_encode([
            'appstore_environment' => 'production',
            'appstore_platform_url' => 'https://www.aiweline.com/',
            'appstore_platform_url_source' => 'production_default',
        ], JSON_THROW_ON_ERROR));

        $result = (new AppStorePlatformUrlResolver($envFile, $deployCurrent))->resolve();

        $this->assertSame('production', $result['environment']);
        $this->assertSame('https://app.aiweline.com', $result['platform_url']);
        $this->assertSame('default:production', $result['source']);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-appstore-resolver-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create temp directory: ' . $dir);
        }

        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function writeTempFile(string $name, string $content): string
    {
        $dir = $this->makeTempDir();
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Cannot write temp file: ' . $path);
        }

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
