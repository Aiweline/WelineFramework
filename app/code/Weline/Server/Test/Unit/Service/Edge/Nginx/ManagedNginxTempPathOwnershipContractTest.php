<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Test\ProjectRoot;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxOriginMode;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
ProjectRoot::defineBp(__DIR__);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('PUB') || \define('PUB', PUB);
\defined('VAR_DIR') || \define('VAR_DIR', VAR_DIR);

/**
 * Linux must pin *_temp_path under runtime temp/ and heal legacy prefix-root
 * dirs so project-user workers can buffer POST /api/.../query-bin bodies.
 */
final class ManagedNginxTempPathOwnershipContractTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-temp-own-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        self::assertTrue(\mkdir($this->root . DIRECTORY_SEPARATOR . 'pub', 0700, true));
        self::assertNotFalse(\file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'pub' . DIRECTORY_SEPARATOR . 'index.php',
            "<?php echo 'ok';\n",
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testConfPinsBodyTempPathsUnderTempDir(): void
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'runtime',
            'install_root' => 'install',
            'listen_http' => 18140,
            'listen_https' => 18540,
            'origin_mode' => ManagedNginxOriginMode::WLS,
        ]);
        $paths->ensureRuntimeDirectories();
        $this->seedMimeTypes($paths);

        $written = (new ManagedNginxConfigWriter($paths))->write(
            19040,
            candidate: false,
        );
        $conf = \file_get_contents((string)$written['conf']);
        self::assertIsString($conf);

        $tempRoot = $paths->nginxBodyTempRoot();
        self::assertStringContainsString('client_body_temp_path', $conf);
        self::assertStringContainsString($tempRoot . DIRECTORY_SEPARATOR . 'client_body_temp', $conf);
        self::assertStringContainsString($tempRoot . DIRECTORY_SEPARATOR . 'proxy_temp', $conf);
        self::assertDirectoryExists($tempRoot . DIRECTORY_SEPARATOR . 'client_body_temp');
        self::assertSame('0700', \sprintf('%04o', \fileperms($tempRoot . DIRECTORY_SEPARATOR . 'client_body_temp') & 0777));
    }

    public function testEnsureHealsLegacyPrefixTempDirMode(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Ownership heal is POSIX-only.');
        }
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'runtime',
            'install_root' => 'install',
            'listen_http' => 18141,
            'listen_https' => 18541,
        ]);
        $legacy = $paths->runtimeRoot() . DIRECTORY_SEPARATOR . 'client_body_temp';
        self::assertTrue(\mkdir($legacy, 0700, true));
        // Intentionally loose mode before heal — ensure must tighten + keep writable by euid.
        @\chmod($legacy, 0755);
        $paths->ensureRuntimeDirectories();
        self::assertDirectoryExists($legacy);
        self::assertSame('0700', \sprintf('%04o', \fileperms($legacy) & 0777));
        $probe = $legacy . DIRECTORY_SEPARATOR . '.write_probe';
        self::assertNotFalse(\file_put_contents($probe, 'ok'));
        @\unlink($probe);
    }

    private function seedMimeTypes(ManagedNginxPaths $paths): void
    {
        $mime = $paths->installRoot() . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'mime.types';
        $dir = \dirname($mime);
        if (!\is_dir($dir)) {
            self::assertTrue(\mkdir($dir, 0700, true));
        }
        self::assertNotFalse(\file_put_contents($mime, "types {\n    text/html html;\n}\n"));
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
