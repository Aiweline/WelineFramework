<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class ParserModuleDictionaryBatchTest extends TestCase
{
    private string $directory;
    private array $originalModules;
    private array $shared = [];
    private array $batches = [];
    private int $singleReads = 0;
    private int $writes = 0;
    private bool $failBatch = false;
    private CachePoolInterface $pool;

    protected function setUp(): void
    {
        RequestContext::init();
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        Parser::clearWorkerCaches();
        RequestContext::set('phrase.event_dictionary.state', [
            'locale' => 'fr_FR', 'active' => false, 'mode' => 'overlay',
            'scope_key' => '', 'words' => [], 'keyed_words' => [],
        ]);
        $this->directory = sys_get_temp_dir() . '/weline-phrase-module-' . bin2hex(random_bytes(6));
        $env = Env::getInstance();
        $property = new ReflectionProperty(Env::class, 'module_list');
        $this->originalModules = $property->getValue($env);
        $modules = $this->originalModules;
        foreach (['A', 'B', 'C'] as $name) {
            $base = $this->directory . '/' . $name;
            mkdir($base . '/i18n', 0777, true);
            file_put_contents($base . '/i18n/en_US.csv', "Source,{$name} English\nFallback,{$name} fallback\n");
            file_put_contents($base . '/i18n/fr_FR.csv', "Source,{$name} French\n");
            $modules['Weline_PhraseBatch' . $name] = ['name' => 'Weline_PhraseBatch' . $name, 'base_path' => $base];
        }
        $property->setValue($env, $modules);
        $this->pool = $this->createMock(CachePoolInterface::class);
        $this->pool->method('get')->willReturnCallback(function (string $key): mixed {
            ++$this->singleReads;
            return $this->shared[$key] ?? null;
        });
        $this->pool->method('getMultiple')->willReturnCallback(function (array $keys): array {
            $this->batches[] = $keys;
            if ($this->failBatch) {
                throw new \RuntimeException('Shared transport unavailable');
            }
            return array_intersect_key($this->shared, array_fill_keys($keys, true));
        });
        $this->pool->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
            ++$this->writes;
            $this->shared[$key] = $value;
            return true;
        });
        $this->installPool();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Env::class, 'module_list'))->setValue(Env::getInstance(), $this->originalModules);
        Parser::clearWorkerCaches();
        RequestContext::cleanup();
        Runtime::resetModeCache();
        foreach (['A', 'B', 'C'] as $name) {
            foreach (['en_US', 'fr_FR'] as $locale) {
                unlink($this->directory . '/' . $name . '/i18n/' . $locale . '.csv');
            }
            rmdir($this->directory . '/' . $name . '/i18n');
            rmdir($this->directory . '/' . $name);
        }
        rmdir($this->directory);
    }

    public function testWarmSnapshotBatchKeepsEmptyDictionaryFallbackAndModulePrecedence(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $this->seed($name, 'en_US', ['Source' => $name . ' shared English', 'Fallback' => $name . ' fallback']);
            $this->seed($name, 'fr_FR', $name === 'B' ? [] : ['Source' => $name . ' shared French']);
        }
        $layers = $this->layers(['A', 'B']);
        self::assertSame('B shared English', $this->translate('Source', $layers));
        self::assertSame('B fallback', $this->translate('Fallback', $layers));
        self::assertSame([4], array_map('count', $this->batches));
        self::assertSame(0, $this->singleReads);
        self::assertSame(0, $this->writes);

        $this->layers(['A', 'B']);
        $expanded = $this->layers(['A', 'B', 'C']);
        self::assertSame('C shared French', $this->translate('Source', $expanded));
        self::assertSame([4, 2], array_map('count', $this->batches));

        Parser::clearWorkerCaches();
        $this->installPool();
        $this->layers(['A', 'B']);
        self::assertSame([4, 2, 4], array_map('count', $this->batches));
    }

    public function testBatchMissReadsAuthoritativeCsvWithoutRepeatingSingleGets(): void
    {
        $layers = $this->layers(['A', 'B']);
        self::assertSame('B French', $this->translate('Source', $layers));
        self::assertSame('B fallback', $this->translate('Fallback', $layers));
        self::assertSame([4], array_map('count', $this->batches));
        self::assertSame(0, $this->singleReads);
        self::assertSame(4, $this->writes);
        $this->layers(['A', 'B']);
        self::assertSame(4, $this->writes);
    }

    public function testBatchTransportFailureFallsBackToCsv(): void
    {
        $this->failBatch = true;
        $layers = $this->layers(['A', 'B']);
        self::assertSame('B French', $this->translate('Source', $layers));
        self::assertSame([4], array_map('count', $this->batches));
        self::assertSame(0, $this->singleReads);
        self::assertSame(4, $this->writes);
    }

    private function seed(string $name, string $locale, array $words): void
    {
        $file = $this->directory . '/' . $name . '/i18n/' . $locale . '.csv';
        $key = $locale . '|Weline_PhraseBatch' . $name . '|' . filemtime($file) . ':' . filesize($file);
        $this->shared['module_dictionary|v1|' . sha1($key)] = $words;
    }

    private function layers(array $names): array
    {
        return (new ReflectionMethod(Parser::class, 'getLayeredWords'))->invoke(
            null, 'fr_FR', array_map(static fn(string $name): string => 'Weline_PhraseBatch' . $name, $names), false,
        );
    }

    private function translate(string $word, array $layers): string
    {
        return (new ReflectionMethod(Parser::class, 'translateWordFromLayers'))->invoke(null, $word, $layers);
    }

    private function installPool(): void
    {
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $this->pool);
    }
}
