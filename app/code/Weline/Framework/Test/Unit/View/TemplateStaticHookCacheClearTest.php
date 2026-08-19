<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\View\Template;

final class TemplateStaticHookCacheClearTest extends TestCase
{
    private mixed $runtimeCacheBackup;
    private bool $runtimeCacheResolvedBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeCacheBackup = $this->readStaticProperty('staticHookRuntimeCache');
        $this->runtimeCacheResolvedBackup = (bool)$this->readStaticProperty('staticHookRuntimeCacheResolved');
    }

    protected function tearDown(): void
    {
        $this->writeStaticProperty('staticHookRuntimeCache', $this->runtimeCacheBackup);
        $this->writeStaticProperty('staticHookRuntimeCacheResolved', $this->runtimeCacheResolvedBackup);
        parent::tearDown();
    }

    public function testClearStaticHookCachesAlsoClearsAndDisconnectsSharedRuntimeState(): void
    {
        $shared = new class implements SharedCacheStateInterface {
            /** @var list<string> */
            public array $clearedNamespaces = [];
            public bool $disconnected = false;

            public function get(string $namespace, string $key): mixed { return null; }
            public function set(string $namespace, string $key, mixed $value, int $ttl = 0): bool { return true; }
            public function delete(string $namespace, string $key): bool { return true; }
            public function exists(string $namespace, string $key): bool { return false; }
            public function incr(string $namespace, string $key, int $delta = 1, int $ttl = 0): ?int { return 1; }
            public function cas(string $namespace, string $key, mixed $expected, mixed $value, int $ttl = 0): bool { return true; }
            public function clearNamespace(string $namespace): bool
            {
                $this->clearedNamespaces[] = $namespace;
                return true;
            }
            public function getCache(string $poolIdentity, string $key): mixed { return null; }
            public function setCache(string $poolIdentity, string $key, mixed $value, int $ttl = 0): bool { return true; }
            public function deleteCache(string $poolIdentity, string $key): bool { return true; }
            public function hasCache(string $poolIdentity, string $key): bool { return false; }
            public function clearCache(string $poolIdentity): bool { return true; }
            public function compareAndSetCache(string $poolIdentity, string $key, mixed $expected, mixed $value, int $ttl = 0): bool { return true; }
            public function disconnect(): void { $this->disconnected = true; }
        };

        $this->writeStaticProperty('staticHookRuntimeCache', $shared);
        $this->writeStaticProperty('staticHookRuntimeCacheResolved', true);

        Template::clearStaticHookCaches();

        self::assertSame(['theme_runtime'], $shared->clearedNamespaces);
        self::assertTrue($shared->disconnected);
        self::assertNull($this->readStaticProperty('staticHookRuntimeCache'));
        self::assertFalse((bool)$this->readStaticProperty('staticHookRuntimeCacheResolved'));
    }

    private function readStaticProperty(string $name): mixed
    {
        $property = new ReflectionProperty(Template::class, $name);
        $property->setAccessible(true);
        return $property->getValue();
    }

    private function writeStaticProperty(string $name, mixed $value): void
    {
        $property = new ReflectionProperty(Template::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}
