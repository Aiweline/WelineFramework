<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsConcurrency;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\View\Taglib;

final class StateManagerPersistentEntryBaselineTest extends TestCase
{
    public function testRunWlsPersistentRequestEntryBaselineIsIdempotent(): void
    {
        StateManager::runWlsPersistentRequestEntryBaseline();
        StateManager::runWlsPersistentRequestEntryBaseline();
        $this->assertTrue(true);
    }

    public function testRunWlsPersistentRequestEntryBaselineClearsSessionRequestInstances(): void
    {
        SessionFactory::resetAll();
        $factory = SessionFactory::getInstance();
        $session = $factory->createSession();

        StateManager::runWlsPersistentRequestEntryBaseline();

        self::assertNotSame($session, SessionFactory::getInstance()->createSession());
        SessionFactory::resetAll();
    }

    public function testOmitListMatchesEntryBaselineCoverage(): void
    {
        $omit = WlsConcurrency::callbackNamesOmittableWithPeerFibers();
        self::assertNotContains('template_instance', $omit);
        self::assertNotContains('virtual_theme_context', $omit);
        self::assertContains('view_hook_runtime_cache', $omit);
        self::assertNotContains('module_request_resetters', $omit);
        self::assertNotContains('session_instances', $omit);
        self::assertNotContains('session_shutdown_queue', $omit);
        self::assertNotContains('sse_context', $omit);
        self::assertNotContains('request_context', $omit);
        self::assertNotContains('db_connection_cleanup', $omit);
        self::assertNotContains('request_instance', $omit);
    }

    public function testRunWlsPersistentRequestEntryBaselineKeepsProcessHotCaches(): void
    {
        $hookReaderReflection = new ReflectionClass(HookReader::class);
        $hookReaderStaticCache = $hookReaderReflection->getProperty('staticFileListCache');
        $hookReaderStaticCache->setAccessible(true);
        $hookReaderStaticCache->setValue(null, ['hooks::foo' => ['x' => 'y']]);

        $taglibReflection = new ReflectionClass(Taglib::class);
        $varParserCache = $taglibReflection->getProperty('varParserCache');
        $varParserCache->setAccessible(true);
        $varParserCache->setValue(null, ['expr' => '$expr']);

        $cachedTagsProperty = $taglibReflection->getProperty('cachedTags');
        $cachedTagsProperty->setAccessible(true);
        $cachedTagsProperty->setValue(null, ['stale-keep']);

        $compileDepth = $taglibReflection->getProperty('compileDepth');
        $compileDepth->setAccessible(true);
        $compileDepth->setValue(null, 3);

        StateManager::runWlsPersistentRequestEntryBaseline();

        self::assertSame(['hooks::foo' => ['x' => 'y']], $hookReaderStaticCache->getValue());
        self::assertSame(['expr' => '$expr'], $varParserCache->getValue());
        self::assertSame(['stale-keep'], $cachedTagsProperty->getValue());
        self::assertSame(0, $compileDepth->getValue());
    }

    public function testInvalidateProcessCachesClearsTaglibHotCaches(): void
    {
        $taglibReflection = new ReflectionClass(Taglib::class);
        $varParserCache = $taglibReflection->getProperty('varParserCache');
        $varParserCache->setAccessible(true);
        $varParserCache->setValue(null, ['expr' => '$expr']);

        $cachedTagsProperty = $taglibReflection->getProperty('cachedTags');
        $cachedTagsProperty->setAccessible(true);
        $cachedTagsProperty->setValue(null, ['tag']);

        Taglib::invalidateProcessCaches();

        self::assertSame([], $varParserCache->getValue());
        self::assertNull($cachedTagsProperty->getValue());
    }

    public function testCachedTagsAreProcessSharedNotFiberBucketed(): void
    {
        $taglibReflection = new ReflectionClass(Taglib::class);
        self::assertFalse(
            $taglibReflection->hasProperty('fiberCachedTags'),
            'Process-hot tag config must not be Fiber-bucketed (over-isolation tax + stale Fiber hazard)'
        );

        $cachedTagsProperty = $taglibReflection->getProperty('cachedTags');
        $cachedTagsProperty->setAccessible(true);
        $cachedTagsProperty->setValue(null, ['shared']);

        Taglib::resetRequestState();
        self::assertSame(['shared'], $cachedTagsProperty->getValue());

        Taglib::invalidateProcessCaches();
        self::assertNull($cachedTagsProperty->getValue());
    }

    public function testHookReaderInvalidateProcessCachesClearsFileList(): void
    {
        $hookReaderReflection = new ReflectionClass(HookReader::class);
        $hookReaderStaticCache = $hookReaderReflection->getProperty('staticFileListCache');
        $hookReaderStaticCache->setAccessible(true);
        $hookReaderStaticCache->setValue(null, ['hooks::bar' => ['a' => 'b']]);

        HookReader::resetRequestState();
        self::assertSame(['hooks::bar' => ['a' => 'b']], $hookReaderStaticCache->getValue());

        HookReader::invalidateProcessCaches();
        self::assertSame([], $hookReaderStaticCache->getValue());
    }
}
