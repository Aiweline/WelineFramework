<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;
use Weline\Server\Service\Edge\Nginx\ManagedNginxProcessManager;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxLiveProbe;

final class ManagedNginxProcessLifecycleTest extends TestCase
{
    private string $root = '';
    private ?ManagedNginxProcessManager $manager = null;
    private int $startedPid = 0;
    private int $listenPort = 0;

    protected function setUp(): void
    {
        if ((string)\getenv('WLS_RUN_NGINX_INTEGRATION') !== '1') {
            self::markTestSkipped('Set WLS_RUN_NGINX_INTEGRATION=1 for the real Nginx lifecycle test.');
        }
        $seedProject = \realpath((string)\getenv('WLS_NGINX_SEED_PROJECT'));
        if (!\is_string($seedProject) || $seedProject === '') {
            self::markTestSkipped('WLS_NGINX_SEED_PROJECT must point at a project with managed Nginx.');
        }
        if (!\defined('BP')) {
            \define('BP', $seedProject);
        }
        $seedConfig = ['managed' => true, 'auto_start' => true];
        $seedInstallRoot = \trim((string)\getenv('WLS_NGINX_SEED_INSTALL_ROOT'));
        if ($seedInstallRoot !== '') {
            $normalizedSeedRoot = \str_replace('\\', '/', $seedInstallRoot);
            if (\str_starts_with($normalizedSeedRoot, '/')
                || \preg_match('/\A[A-Za-z]:/', $normalizedSeedRoot) === 1
                || \in_array('..', \explode('/', $normalizedSeedRoot), true)
                || \str_contains($normalizedSeedRoot, "\0")
            ) {
                self::fail('WLS_NGINX_SEED_INSTALL_ROOT must be a contained relative path.');
            }
            $seedConfig['install_root'] = $seedInstallRoot;
        }
        $seed = new ManagedNginxPaths($seedProject, $seedConfig);
        if (!$seed->isInstalled() || !\is_file($seed->manifestFile())) {
            self::markTestSkipped('Managed Nginx seed binary or manifest is unavailable.');
        }

        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-managed-nginx-lifecycle-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $paths = new ManagedNginxPaths($this->root, [
            'managed' => true,
            'auto_start' => true,
            'install_root' => 'nginx-install',
            'runtime_root' => 'nginx-runtime',
        ]);
        self::assertTrue(\mkdir(\dirname($paths->binary()), 0700, true));
        self::assertTrue(\copy($seed->binary(), $paths->binary()));
        self::assertTrue(\chmod($paths->binary(), 0700));
        $manifest = \json_decode((string)\file_get_contents($seed->manifestFile()), true);
        self::assertIsArray($manifest);
        $manifest['schema_version'] = 2;
        $manifest['role'] = 'legacy-project-nginx';
        $manifest['binary'] = $paths->binary();
        $manifest['prefix'] = $paths->installRoot();
        $manifest['binary_sha256'] = \hash_file('sha256', $paths->binary());
        $manifest['runtime_generation'] = \hash('sha256', \json_encode(
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        self::assertNotFalse(\file_put_contents(
            $paths->manifestFile(),
            \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $paths->ensureRuntimeDirectories();
        $port = $this->allocatePort();
        $this->listenPort = $port;
        $config = "worker_processes 1;\n"
            . "pid run/nginx.pid;\n"
            . "error_log logs/error.log notice;\n"
            . "events { worker_connections 128; }\n"
            . "http { server { listen 127.0.0.1:{$port}; "
            . "location = /health { add_header X-Wls-Test lifecycle always; return 200 \"ok\"; } } }\n";
        self::assertSame(\strlen($config), \file_put_contents($paths->confFile(), $config));
        $this->manager = new ManagedNginxProcessManager($paths);
    }

    protected function tearDown(): void
    {
        if ($this->manager !== null) {
            $this->manager->stop();
        }
        $this->cleanupOwnedPid();
        $this->removeTree($this->root);
    }

    public function testStartReloadAndStopRemainPidAndManifestFenced(): void
    {
        self::assertNotNull($this->manager);
        $start = $this->manager->start();
        $this->startedPid = (int)($start['pid'] ?? 0);
        self::assertTrue($start['ok'], (string)($start['message'] ?? 'start failed'));
        $running = $this->manager->status();
        self::assertTrue($running['ok']);
        self::assertTrue($running['running']);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string)($running['binary_sha256'] ?? ''),
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string)($running['runtime_generation'] ?? ''),
        );
        $probe = (new NginxLiveProbe())->probeHttp(
            address: '127.0.0.1',
            port: $this->listenPort,
            host: 'localhost',
            path: '/health',
            expectedHeaders: ['X-Wls-Test' => 'lifecycle'],
            bodyContains: 'ok',
            maxAttempts: 5,
            requiredConsecutive: 3,
        );
        self::assertTrue($probe['ok'], (string)$probe['reason']);
        self::assertSame(3, $probe['consecutive_matches']);

        $reload = $this->manager->reload();
        self::assertTrue($reload['ok'], (string)($reload['message'] ?? 'reload failed'));
        $afterReload = $this->manager->status();
        self::assertSame($running['pid'], $afterReload['pid']);
        self::assertSame($running['runtime_generation'], $afterReload['runtime_generation']);

        $stop = $this->manager->stop();
        self::assertTrue($stop['ok'], (string)($stop['message'] ?? 'stop failed'));
        $stopped = $this->manager->status();
        self::assertTrue($stopped['ok']);
        self::assertFalse($stopped['running']);
        $this->startedPid = 0;
    }

    private function allocatePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $address = \stream_socket_get_name($socket, false);
        \fclose($socket);
        self::assertIsString($address);
        $port = (int)\substr($address, (int)\strrpos($address, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);
        return $port;
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }

    private function cleanupOwnedPid(): void
    {
        if ($this->startedPid < 1 || $this->root === '') {
            return;
        }
        $output = [];
        if (\PHP_OS_FAMILY === 'Windows') {
            @\exec(
                'wmic process where processid=' . $this->startedPid . ' get CommandLine /value 2>NUL',
                $output,
            );
        } else {
            @\exec('ps -p ' . $this->startedPid . ' -o command= 2>/dev/null', $output);
        }
        $command = \implode("\n", $output);
        if (!\str_contains($command, $this->root . DIRECTORY_SEPARATOR . 'nginx-install')
            || !\str_contains($command, $this->root . DIRECTORY_SEPARATOR . 'nginx-runtime')
        ) {
            return;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            @\exec('taskkill /PID ' . $this->startedPid . ' /T /F 2>NUL');
            return;
        }
        if (\function_exists('posix_kill')) {
            @\posix_kill($this->startedPid, 15);
            for ($attempt = 0; $attempt < 20 && @\posix_kill($this->startedPid, 0); $attempt++) {
                \usleep(50_000);
            }
            if (@\posix_kill($this->startedPid, 0)) {
                @\posix_kill($this->startedPid, 9);
            }
        }
    }
}
