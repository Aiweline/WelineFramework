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

    /**
     * 项目运行用户必须能从 runtimeRoot 属主解析出来：root 上下文 nginx 启动时
     * 配置里的 `user` 指令就靠它，避免 worker/temp 目录再次落成 nobody。
     */
    public function testRuntimeUserNameResolvesCurrentProcessUser(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX-only project user resolution.');
        }
        $paths = $this->newPaths(18142, 18542);
        $account = \posix_getpwuid(\posix_geteuid());
        self::assertIsArray($account);
        self::assertSame((string)$account['name'], $paths->runtimeUserName());
    }

    /**
     * 托管 Nginx 主配置必须显式声明 worker 用户；否则 root 上下文启动 nginx
     * 时会把 temp 目录属主改成默认 user（nobody），weline worker 写不进 → 大 POST 500。
     */
    public function testGeneratedConfRunsWorkersAsProjectUser(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('nginx user directive is POSIX-only.');
        }
        $paths = $this->newPaths(18144, 18544);
        $paths->ensureRuntimeDirectories();
        $this->seedMimeTypes($paths);

        $written = (new ManagedNginxConfigWriter($paths))->write(19044, candidate: false);
        $conf = \file_get_contents((string)$written['conf']);
        self::assertIsString($conf);

        $account = \posix_getpwuid(\posix_geteuid());
        self::assertIsArray($account);
        $name = \preg_quote((string)$account['name'], '/');
        self::assertMatchesRegularExpression('/^user\s+' . $name . ';\s*$/m', $conf);

        $userPos = \strpos($conf, 'user ');
        $httpPos = \strpos($conf, 'http {');
        self::assertIsInt($userPos);
        self::assertIsInt($httpPos);
        self::assertLessThan($httpPos, $userPos);
    }

    /**
     * 属主自愈必须报告「修不掉的路径」，不能像 2026-10-27 事故那样静默吞异常。
     */
    public function testEnsureRuntimeDirectoriesReportsNoUnresolvedTempOwnership(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX-only temp ownership reporting.');
        }
        $paths = $this->newPaths(18146, 18546);
        $paths->ensureRuntimeDirectories();
        self::assertSame([], $paths->alignNginxTempDirectoriesOwnership());
    }

    private function newPaths(int $httpPort, int $httpsPort): ManagedNginxPaths
    {
        return new ManagedNginxPaths($this->root, [
            'runtime_root' => 'runtime',
            'install_root' => 'install',
            'listen_http' => $httpPort,
            'listen_https' => $httpsPort,
        ]);
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
