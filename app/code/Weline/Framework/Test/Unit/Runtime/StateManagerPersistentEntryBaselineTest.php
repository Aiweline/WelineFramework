<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsConcurrency;
use Weline\Framework\View\Taglib;

final class StateManagerPersistentEntryBaselineTest extends TestCase
{
    public function testRunWlsPersistentRequestEntryBaselineIsIdempotent(): void
    {
        StateManager::runWlsPersistentRequestEntryBaseline();
        StateManager::runWlsPersistentRequestEntryBaseline();
        $this->assertTrue(true);
    }

    public function testOmitListMatchesEntryBaselineCoverage(): void
    {
        $omit = WlsConcurrency::callbackNamesOmittableWithPeerFibers();
        self::assertContains('template_instance', $omit);
        self::assertContains('virtual_theme_context', $omit);
        self::assertContains('view_hook_runtime_cache', $omit);
        self::assertNotContains('session_instances', $omit);
        self::assertNotContains('sse_context', $omit);
        self::assertNotContains('request_context', $omit);
    }

    public function testRunWlsPersistentRequestEntryBaselineClearsViewHookRuntimeCaches(): void
    {
        $hookReaderReflection = new ReflectionClass(HookReader::class);
        $hookReaderStaticCache = $hookReaderReflection->getProperty('staticFileListCache');
        $hookReaderStaticCache->setAccessible(true);
        $hookReaderStaticCache->setValue(null, ['hooks::foo' => ['x' => 'y']]);

        $taglibReflection = new ReflectionClass(Taglib::class);
        foreach (['varParserCache', 'hookCheckCache', 'compiledRegexCache'] as $propertyName) {
            $property = $taglibReflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, ['stale' => 'value']);
        }
        $cachedTagsProperty = $taglibReflection->getProperty('cachedTags');
        $cachedTagsProperty->setAccessible(true);
        $cachedTagsProperty->setValue(null, ['stale']);

        StateManager::runWlsPersistentRequestEntryBaseline();

        self::assertSame([], $hookReaderStaticCache->getValue());
        self::assertSame([], $taglibReflection->getProperty('varParserCache')->getValue());
        self::assertSame([], $taglibReflection->getProperty('hookCheckCache')->getValue());
        self::assertSame([], $taglibReflection->getProperty('compiledRegexCache')->getValue());
        self::assertNull($cachedTagsProperty->getValue());
    }
}
