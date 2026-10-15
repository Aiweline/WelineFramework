<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

final class ManagedNginxConfigWriterRealIpTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-realip-'
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

    public function testHttpBlockEmitsCfConnectingIpAndSetRealIpFromWithoutLoopback(): void
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
            false,
            true,
            false,
            false,
            [],
            null,
            null,
            true,
            ['104.16.0.0/13', '203.0.113.10/32', '127.0.0.0/8'],
        );
        $config = \file_get_contents($result['conf']);
        self::assertIsString($config);
        self::assertStringContainsString('real_ip_header CF-Connecting-IP;', $config);
        self::assertStringContainsString('set_real_ip_from 104.16.0.0/13;', $config);
        self::assertStringContainsString('set_real_ip_from 203.0.113.10/32;', $config);
        self::assertStringNotContainsString('set_real_ip_from 127.0.0.0/8;', $config);

        $httpPos = \strpos($config, "http {\n");
        $realIpPos = \strpos($config, 'real_ip_header CF-Connecting-IP;');
        $upstreamPos = \strpos($config, 'upstream wls_backend');
        self::assertIsInt($httpPos);
        self::assertIsInt($realIpPos);
        self::assertIsInt($upstreamPos);
        self::assertGreaterThan($httpPos, $realIpPos);
        self::assertLessThan($upstreamPos, $realIpPos);
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $items = \scandir($path);
        if (!\is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (\is_dir($full)) {
                $this->removeTree($full);
            } else {
                @\unlink($full);
            }
        }
        @\rmdir($path);
    }
}
