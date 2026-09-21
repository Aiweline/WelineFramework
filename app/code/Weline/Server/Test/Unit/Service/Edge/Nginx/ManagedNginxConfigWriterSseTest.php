<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\ResponseObservabilityPolicy;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

final class ManagedNginxConfigWriterSseTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-sse-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonicalRoot = \realpath($this->root);
        self::assertIsString($canonicalRoot);
        $this->root = $canonicalRoot;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testFrameworkStreamGetsIsolatedLongLivedProxyPolicy(): void
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'runtime',
            'install_root' => 'install',
            'listen_http' => 18080,
            'listen_https' => 18443,
            'edge_cache' => true,
            'gzip' => true,
        ]);
        $paths->ensureRuntimeDirectories();
        $result = (new ManagedNginxConfigWriter($paths))->write(
            19000,
            '127.0.0.1',
            ['_'],
        );
        $config = \file_get_contents($result['conf']);
        self::assertIsString($config);
        self::assertStringContainsString(
            'worker_shutdown_timeout 300s;',
            $config,
            'Reload recovery must preserve long-lived streams for the full drain window.',
        );
        self::assertStringContainsString(
            'client_max_body_size 512m;',
            $config,
            'Managed nginx must raise the default 1m body cap for MediaManager uploads.',
        );

        $sseStart = \strpos($config, 'location = /api/framework/stream {');
        $genericStart = \strpos($config, 'location / {');
        self::assertIsInt($sseStart);
        self::assertIsInt($genericStart);
        self::assertLessThan($genericStart, $sseStart);

        $sseBlock = \substr($config, $sseStart, $genericStart - $sseStart);
        self::assertStringContainsString('proxy_pass http://wls_backend;', $sseBlock);
        self::assertStringContainsString('proxy_buffering off;', $sseBlock);
        self::assertStringContainsString('proxy_cache off;', $sseBlock);
        self::assertStringContainsString('proxy_no_cache 1;', $sseBlock);
        self::assertStringContainsString('gzip off;', $sseBlock);
        self::assertStringContainsString('proxy_read_timeout 300s;', $sseBlock);
        self::assertStringContainsString('proxy_send_timeout 300s;', $sseBlock);

        $genericBlock = \substr($config, $genericStart);
        self::assertStringContainsString('proxy_buffering on;', $genericBlock);
        self::assertStringNotContainsString('proxy_read_timeout 300s;', $genericBlock);
        self::assertStringNotContainsString('proxy_send_timeout 300s;', $genericBlock);
    }

    public function testWlsProbeLocationAlwaysEmitsConfigGenerationHeader(): void
    {
        ResponseObservabilityPolicy::clearCache();
        self::assertFalse(
            ResponseObservabilityPolicy::identityHeadersEnabled(),
            'Fixture assumes lean identity headers so /_wls/ must not rely on that gate.',
        );

        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'runtime-probe',
            'install_root' => 'install-probe',
            'listen_http' => 18082,
            'listen_https' => 18445,
            'edge_cache' => true,
            'gzip' => true,
        ]);
        $paths->ensureRuntimeDirectories();
        $result = (new ManagedNginxConfigWriter($paths))->write(
            19002,
            '127.0.0.1',
            ['_'],
        );
        $config = \file_get_contents($result['conf']);
        self::assertIsString($config);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', (string)$result['config_generation']);

        $wlsStart = \strpos($config, 'location ^~ /_wls/ {');
        $sseStart = \strpos($config, 'location = /api/framework/stream {');
        self::assertIsInt($wlsStart);
        self::assertIsInt($sseStart);
        self::assertLessThan($sseStart, $wlsStart);

        $wlsBlock = \substr($config, $wlsStart, $sseStart - $wlsStart);
        self::assertStringContainsString(
            'add_header X-Wls-Nginx-Config ' . $result['config_generation'] . ' always;',
            $wlsBlock,
            'Publication/probe gates require X-Wls-Nginx-Config on /_wls/ even when identity_headers=false.',
        );
    }

    public function testRefreshCandidateReadsActiveConfigThroughBoundedFilesystemContract(): void
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => 'refresh-runtime',
            'install_root' => 'refresh-install',
            'listen_http' => 18081,
            'listen_https' => 18444,
        ]);
        $paths->ensureRuntimeDirectories();
        $writer = new ManagedNginxConfigWriter($paths);
        $initial = $writer->write(19001, '127.0.0.1', ['_']);

        $refreshed = $writer->refreshCandidate();

        self::assertTrue($refreshed['candidate']);
        self::assertFileExists($refreshed['conf']);
        self::assertNotSame($initial['config_generation'], $refreshed['config_generation']);
        self::assertSame(
            $refreshed['config_sha256'],
            \hash_file('sha256', $refreshed['conf']),
        );
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
}
