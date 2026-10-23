<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;
use Weline\MediaManager\Observer\RouterRunBefore;

/**
 * Pre-route static handling must hand Core the real file it already confined to its root;
 * media mode only resolves the media root, so BP-relative paths through it 404.
 */
final class RouterRunBeforeStaticPathsTest extends TestCase
{
    private mixed $previousUri = null;

    protected function setUp(): void
    {
        $this->previousUri = WelineEnv::server('REQUEST_URI');
        $spy = (new \ReflectionClass(StaticServeSpyCore::class))->newInstanceWithoutConstructor();
        ObjectManager::setInstance(Core::class, $spy);
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(Core::class);
        WelineEnv::setServer('REQUEST_URI', $this->previousUri, 'test restore');
    }

    public function testModuleStaticIsServedFromResolvedModuleFile(): void
    {
        $request = '/Weline/Frontend/view/statics/js/cookie.js';
        $served = $this->servedFor($request);

        self::assertNotNull($served, 'module static must be served before routing');
        self::assertSame(\realpath(BP . 'app/code/Weline/Frontend/view/statics/js/cookie.js'), $served['file']);
        self::assertSame($request, $served['url']);
    }

    public function testMissingModuleStaticFallsThroughToRouter(): void
    {
        self::assertNull($this->servedFor('/Weline/Frontend/view/statics/js/does-not-exist.js'));
    }

    public function testTraversalIsNotServed(): void
    {
        self::assertNull($this->servedFor('/Weline/Frontend/view/statics/%2e%2e/%2e%2e/%2e%2e/%2e%2e/%2e%2e/composer.json'));
        self::assertNull($this->servedFor('/Weline/Frontend/view/statics/../../../../../composer.json'));
    }

    /** @return array{file: string, url: string}|null */
    private function servedFor(string $requestUri): ?array
    {
        WelineEnv::setServer('REQUEST_URI', $requestUri, 'test');
        $method = new \ReflectionMethod(RouterRunBefore::class, 'handleStaticPaths');
        try {
            $method->invoke(new RouterRunBefore());
        } catch (StaticServed $served) {
            return ['file' => $served->servedFile, 'url' => $served->servedUrl];
        }

        return null;
    }
}

final class StaticServed extends \RuntimeException
{
    public function __construct(public readonly string $servedFile, public readonly string $servedUrl)
    {
        parent::__construct('served');
    }
}

class StaticServeSpyCore extends Core
{
    public function serveStaticFile(string $filename, string $url): never
    {
        throw new StaticServed($filename, $url);
    }

    public function StaticFile(string &$url, bool $is_media = false): mixed
    {
        throw new \LogicException('BP-relative static path routed through StaticFile(): ' . $url);
    }
}
