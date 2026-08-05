<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\Core;

final class GeneratedRouterFileCacheTest extends TestCase
{
    private string $routerFile = '';

    protected function tearDown(): void
    {
        Core::resetGeneratedRouterFileCache();
        unset($GLOBALS['__generated_router_include_counter']);

        if ($this->routerFile !== '' && \is_file($this->routerFile)) {
            @\unlink($this->routerFile);
        }
    }

    public function testGeneratedRouterFileIsLoadedOnceUntilMtimeChanges(): void
    {
        $this->routerFile = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generated-router-cache-test-' . \bin2hex(\random_bytes(4)) . '.php';
        $this->writeRouterFile([
            'foo::GET' => ['class' => ['name' => 'Foo', 'method' => 'getIndex']],
        ]);

        $first = $this->loadRouterFile($this->routerFile);
        $second = $this->loadRouterFile($this->routerFile);

        self::assertSame(1, (int)($GLOBALS['__generated_router_include_counter'] ?? 0));
        self::assertSame($first, $second);

        \sleep(1);
        $this->writeRouterFile([
            'bar::GET' => ['class' => ['name' => 'Bar', 'method' => 'getIndex']],
        ]);
        @\touch($this->routerFile, \time() + 2);
        \clearstatcache(true, $this->routerFile);

        $third = $this->loadRouterFile($this->routerFile);

        self::assertSame(2, (int)($GLOBALS['__generated_router_include_counter'] ?? 0));
        self::assertArrayHasKey('bar::GET', $third);
        self::assertArrayNotHasKey('foo::GET', $third);
    }

    private function loadRouterFile(string $routerFile): array
    {
        $method = new \ReflectionMethod(Core::class, 'loadGeneratedRouterFile');
        $method->setAccessible(true);

        /** @var array $routers */
        $routers = $method->invoke(null, $routerFile);
        return $routers;
    }

    private function writeRouterFile(array $routers): void
    {
        $content = "<?php\n"
            . '$GLOBALS[\'__generated_router_include_counter\'] = ($GLOBALS[\'__generated_router_include_counter\'] ?? 0) + 1;' . "\n"
            . 'return ' . \var_export($routers, true) . ';' . "\n";

        \file_put_contents($this->routerFile, $content);
    }
}
