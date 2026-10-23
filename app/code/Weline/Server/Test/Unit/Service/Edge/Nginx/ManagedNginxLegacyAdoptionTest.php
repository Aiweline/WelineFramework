<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class ManagedNginxLegacyAdoptionTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && \is_dir($this->root)) {
            $this->removeTree($this->root);
        }
        parent::tearDown();
    }

    public function testActiveConfigUpstreamPortReadsManagedUpstreamBlock(): void
    {
        $this->root = \sys_get_temp_dir() . '/wls-managed-nginx-' . \bin2hex(\random_bytes(4));
        $confDir = $this->root . '/var/server/nginx/conf';
        \mkdir($confDir, 0777, true);
        \file_put_contents(
            $confDir . '/nginx.conf',
            "upstream wls_backend {\n    server 127.0.0.1:9555;\n    keepalive 256;\n}\n",
        );

        $service = new ManagedNginxService(new ManagedNginxPaths($this->root));
        $method = new \ReflectionMethod(ManagedNginxService::class, 'activeConfigUpstreamPort');
        $method->setAccessible(true);

        self::assertSame(9555, $method->invoke($service));
    }

    public function testActiveConfigUpstreamPortReturnsZeroWithoutUpstreamBlock(): void
    {
        $this->root = \sys_get_temp_dir() . '/wls-managed-nginx-' . \bin2hex(\random_bytes(4));
        $confDir = $this->root . '/var/server/nginx/conf';
        \mkdir($confDir, 0777, true);
        \file_put_contents($confDir . '/nginx.conf', "server {\n    listen 80;\n}\n");

        $service = new ManagedNginxService(new ManagedNginxPaths($this->root));
        $method = new \ReflectionMethod(ManagedNginxService::class, 'activeConfigUpstreamPort');
        $method->setAccessible(true);

        self::assertSame(0, $method->invoke($service));
    }

    private function removeTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($dir);
    }
}
