<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxPublicationBundle;

final class NginxPublicationBundleTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-bundle-'
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

    public function testTwoFileBundleRollsBackAsOneUnit(): void
    {
        $config = $this->root . DIRECTORY_SEPARATOR . 'nginx.conf';
        $routes = $this->root . DIRECTORY_SEPARATOR . 'routes.conf';
        self::assertSame(10, \file_put_contents($config, 'old-config'));
        self::assertSame(10, \file_put_contents($routes, 'old-routes'));
        $publisher = new NginxPublicationBundle();
        $candidates = $publisher->stage([
            $config => 'new-config',
            $routes => 'new-routes',
        ], 'test bundle');
        $bundle = $publisher->publish($candidates, \str_repeat('f', 32), 'test bundle');
        self::assertSame('new-config', \file_get_contents($config));
        self::assertSame('new-routes', \file_get_contents($routes));

        $publisher->rollback($bundle);

        self::assertSame('old-config', \file_get_contents($config));
        self::assertSame('old-routes', \file_get_contents($routes));
    }

    public function testBadSecondCandidateFailsBeforeAnyActiveMutation(): void
    {
        $config = $this->root . DIRECTORY_SEPARATOR . 'nginx.conf';
        $routes = $this->root . DIRECTORY_SEPARATOR . 'routes.conf';
        self::assertSame(10, \file_put_contents($config, 'old-config'));
        self::assertSame(10, \file_put_contents($routes, 'old-routes'));
        $publisher = new NginxPublicationBundle();
        $candidates = $publisher->stage([$config => 'new-config'], 'test bundle');
        $candidates[$routes] = $this->root . DIRECTORY_SEPARATOR . 'not-a-routes-candidate';

        try {
            $publisher->publish($candidates, \str_repeat('1', 32), 'test bundle');
            self::fail('The bundle must reject an invalid candidate before publication.');
        } catch (\Throwable) {
            self::assertSame('old-config', \file_get_contents($config));
            self::assertSame('old-routes', \file_get_contents($routes));
        }
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
