<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerDesiredWorkerCountTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $temporaryRoot = \realpath(\sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR . 'wls-desired-workers-' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($this->root . '/instances', 0700, true));
        self::assertTrue(\mkdir($this->root . '/config', 0700, true));
        $resolved = \realpath($this->root);
        self::assertIsString($resolved);
        $this->root = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testUpdateDesiredWorkerCountPersistsEndpointAndSavedConfig(): void
    {
        $instanceName = 'ut-scale';
        $instanceFile = $this->root . '/instances/' . $instanceName . '.json';
        \file_put_contents($instanceFile, \json_encode([
            'name' => $instanceName,
            'instance_name' => $instanceName,
            'schema_version' => 4,
            'master_pid' => 1,
            'pid' => 1,
            'host' => '127.0.0.1',
            'public_host' => '127.0.0.1',
            'port' => 9555,
            'main_port' => 9555,
            'count' => 2,
            'daemon' => false,
            'ssl_enabled' => false,
            'runtime_selection' => [
                'schema' => 'v4',
                'requested_topology' => 'direct',
                'effective_topology' => 'direct',
                'source' => 'test',
            ],
        ], JSON_THROW_ON_ERROR));

        \file_put_contents($this->root . '/config/' . $instanceName . '.json', \json_encode([
            'host' => '127.0.0.1',
            'port' => 9555,
            'worker_count' => 2,
            'worker_count_requested' => 2,
            'saved_at' => 'before',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");

        $manager = new class ($this->root) extends ServerInstanceManager {
            public function __construct(private readonly string $rootDir)
            {
            }

            public function getInstanceDir(): string
            {
                return $this->rootDir . '/instances/';
            }
        };

        $manager->updateDesiredWorkerCount($instanceName, 4, $this->root . '/config');

        $endpoint = \json_decode((string)\file_get_contents($instanceFile), true, 512, JSON_THROW_ON_ERROR);
        $saved = \json_decode(
            (string)\file_get_contents($this->root . '/config/' . $instanceName . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(4, (int)$endpoint['count']);
        self::assertSame(4, (int)$saved['worker_count']);
        self::assertSame(4, (int)$saved['worker_count_requested']);
        self::assertNotSame('before', (string)$saved['saved_at']);
    }

    public function testUpdateDesiredWorkerCountAllowsMissingSavedConfig(): void
    {
        $instanceName = 'ut-scale-endpoint-only';
        $instanceFile = $this->root . '/instances/' . $instanceName . '.json';
        \file_put_contents($instanceFile, \json_encode([
            'name' => $instanceName,
            'instance_name' => $instanceName,
            'schema_version' => 4,
            'master_pid' => 1,
            'pid' => 1,
            'host' => '127.0.0.1',
            'public_host' => '127.0.0.1',
            'port' => 9555,
            'main_port' => 9555,
            'count' => 1,
            'daemon' => false,
            'ssl_enabled' => false,
            'runtime_selection' => [
                'schema' => 'v4',
                'requested_topology' => 'direct',
                'effective_topology' => 'direct',
                'source' => 'test',
            ],
        ], JSON_THROW_ON_ERROR));

        $manager = new class ($this->root) extends ServerInstanceManager {
            public function __construct(private readonly string $rootDir)
            {
            }

            public function getInstanceDir(): string
            {
                return $this->rootDir . '/instances/';
            }
        };

        $manager->updateDesiredWorkerCount($instanceName, 3, $this->root . '/config');

        $endpoint = \json_decode((string)\file_get_contents($instanceFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(3, (int)$endpoint['count']);
        self::assertFileDoesNotExist($this->root . '/config/' . $instanceName . '.json');
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !\is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($path);
    }
}
