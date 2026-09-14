<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Test\Unit\Phrase\ParserModulePrefetchTest;
use Weline\Framework\View\Template;
use Weline\Framework\View\TraitTemplate;

require_once __DIR__ . '/../Phrase/ParserModulePrefetchTest.php';

final class TemplateHookDictionaryPrefetchTest extends TestCase
{
    private ParserModulePrefetchTest $phraseFixture;
    private object $provider;
    private object $request;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(ParserModulePrefetchTest::class);
        $this->phraseFixture = $reflection->newInstanceWithoutConstructor();
        $reflection->getMethod('setUp')->invoke($this->phraseFixture);
        $this->provider = $reflection->getProperty('provider')->getValue($this->phraseFixture);
        $this->request = new class {
            public array $modules = [];
            public function getModuleName(): string { return 'Weline_Route'; }
            public function getModules(): array { return $this->modules; }
            public function addModule(string $module): static { if (!in_array($module, $this->modules, true)) { $this->modules[] = $module; } return $this; }
            public function getBaseUrl(): string { return 'https://hook.test/'; }
        };
        ObjectManager::setInstance(Request::class, $this->request);
        HookPrefetchObjectManager::reset();
    }

    protected function tearDown(): void
    {
        (new ReflectionMethod(ParserModulePrefetchTest::class, 'tearDown'))->invoke($this->phraseFixture);
        HookPrefetchObjectManager::reset();
    }

    public function testActualFileModulesArePrefetchedBeforeRenderingWithoutChangingOrder(): void
    {
        HookPrefetchObjectManager::$files = [
            'Weline_RegistrationB' => ['file' => 'Weline_B::hooks/b.phtml'],
            'Weline_RegistrationA' => ['file' => 'Weline_A::hooks/a.phtml'],
        ];
        $template = $this->template();
        $html = $template->getHook('fixture/batch');
        self::assertSame([['en_US', ['Weline_A', 'Weline_B']], ['zh_Hans_CN', ['Weline_A', 'Weline_B']]], $this->provider->batches);
        self::assertSame(['Weline_B::hooks/b.phtml', 'Weline_A::hooks/a.phtml'], $template->rendered);
        self::assertSame([['Weline_B'], ['Weline_B', 'Weline_A']], $template->activeAtRender);
        self::assertSame([2, 2], $template->batchesAtRender);
        self::assertSame('<file>Weline_B::hooks/b.phtml</file><file>Weline_A::hooks/a.phtml</file>', $html);
    }

    public function testSoloSelectionExcludesSuppressedModuleFromPrefetch(): void
    {
        HookPrefetchObjectManager::$files = [
            'Weline_RegistrationB' => ['file' => 'Weline_B::hooks/b.phtml'],
            'Weline_RegistrationA' => ['file' => 'Weline_A::hooks/a.phtml', 'solo' => true],
        ];
        $template = $this->template();
        self::assertSame('<file>Weline_A::hooks/a.phtml</file>', $template->getHook('fixture/solo'));
        self::assertSame([['en_US', ['Weline_A']], ['zh_Hans_CN', ['Weline_A']]], $this->provider->batches);
        self::assertSame(['Weline_A'], $this->request->modules);
    }

    public function testWholeHookCacheHitsReturnBeforeFileDiscoveryOrPrefetch(): void
    {
        $template = $this->template();
        $template->policies->requestCache = true;
        $key = 'view.hook.output.' . sha1(RequestContext::getId() . ':fixture/request:fixture');
        RequestContext::set($key, '<request-cached>');
        self::assertSame('<request-cached>', $template->getHook('fixture/request'));
        $template->policies->requestCache = false;
        $template->policies->aggregatePolicy = ['fixture' => true];
        $template->aggregateResult = ['status' => 'fresh', 'html' => '<aggregate-cached>'];
        self::assertSame('<aggregate-cached>', $template->getHook('fixture/aggregate'));
        self::assertSame([], $this->provider->batches);
        self::assertSame(0, HookPrefetchObjectManager::$readerCalls);
        self::assertSame([], $template->rendered);
    }

    public function testPerFileOutputHitAndRenderOnceRemainInsideThePrefetchedBatch(): void
    {
        HookPrefetchObjectManager::$files = [
            'Weline_A' => ['file' => 'Weline_A::hooks/once.phtml'],
            'Weline_B' => ['file' => 'Weline_B::hooks/cached.phtml'],
        ];
        $template = $this->template();
        $template->policies->outputs = [
            'Weline_A::hooks/once.phtml' => ['render_once_group' => 'complete'],
            'Weline_B::hooks/cached.phtml' => [],
        ];
        self::assertSame('<file-cached>', $template->getHook('fixture/output-hit'));
        self::assertSame([['en_US', ['Weline_A', 'Weline_B']], ['zh_Hans_CN', ['Weline_A', 'Weline_B']]], $this->provider->batches);
        self::assertSame([], $template->rendered, '真实 fetchHookHtml 在批次预取后才判断文件输出缓存与 render_once。');
        self::assertSame(['Weline_A', 'Weline_B'], $this->request->modules);
    }

    public function testOlderParserWithoutPrefetchMethodStillRenders(): void
    {
        HookPrefetchObjectManager::$files = ['Weline_A' => ['file' => 'Weline_A::hooks/a.phtml']];
        $template = $this->template(true);
        self::assertSame('<file>Weline_A::hooks/a.phtml</file>', $template->getHook('fixture/old-worker'));
        self::assertSame([], $this->provider->batches);
        self::assertSame(['Weline_A'], $this->request->modules);
    }

    private function template(bool $oldParser = false): object
    {
        static $number = 0;
        $class = 'HookPrefetchExecutedTemplate' . ++$number;
        $methods = '';
        foreach ([[Template::class, 'getHook'], [Template::class, 'fetchHookHtml'], [Template::class, 'addSourceModuleToRequest'], [TraitTemplate::class, 'processModuleSourceFilePath']] as [$owner, $method]) {
            $reflection = new ReflectionMethod($owner, $method);
            $lines = file($reflection->getFileName());
            $methods .= implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1)) . "\n";
        }
        // 执行生产方法原文，仅替换外部对象创建边界；兼容用例把 Parser 依赖换成旧接口。
        if ($oldParser) {
            $methods = str_replace('\\Weline\\Framework\\Phrase\\Parser', '\\' . HookPrefetchOldParser::class, $methods);
        }
        $imports = 'use Weline\\Framework\\Context; use Weline\\Framework\\Cache\\KeyBuilder; use Weline\\Framework\\Http\\Request; use Weline\\Framework\\Runtime\\RequestContext; use Weline\\Framework\\Runtime\\RequestLifecycleTrace; use Weline\\Framework\\Runtime\\FiberOutputBuffer; use Weline\\Framework\\Test\\Unit\\View\\HookPrefetchObjectManager as ObjectManager;';
        eval('namespace Weline\\Framework\\Test\\Unit\\View; ' . $imports . ' class ' . $class . ' extends HookPrefetchRenderBoundary {' . $methods . '}');
        $name = __NAMESPACE__ . '\\' . $class;
        return new $name($this->request, $this->provider);
    }
}

final class HookPrefetchOldParser {}

final class HookPrefetchObjectManager
{
    public static array $files = [];
    public static int $readerCalls = 0;
    public static function reset(): void { self::$files = []; self::$readerCalls = 0; }
    public static function make(string $class): object
    {
        self::$readerCalls++;
        return new class {
            public function setPath(string $path): void {}
            public function getFileListWithMeta(): array { return HookPrefetchObjectManager::$files; }
            public function getFileList(): array { return []; }
        };
    }
    public static function getInstance(string $class): mixed { return ObjectManager::getInstance($class); }
}

class HookPrefetchRenderBoundary
{
    public static object $registry;
    public object $policies;
    public array $rendered = [];
    public array $activeAtRender = [];
    public array $batchesAtRender = [];
    public array $aggregateResult = ['status' => 'miss', 'html' => null];
    public function __construct(public object $request, private object $provider)
    {
        $this->policies = self::$registry = new class {
            public bool $requestCache = false;
            public ?array $aggregatePolicy = null;
            public array $outputs = [];
            public function isRequestCacheable(string $name): bool { return $this->requestCache; }
            public function aggregate(string $name): ?array { return $this->aggregatePolicy; }
            public function aggregateDigest(string $name): string { return 'fixture'; }
            public function output(string $file): ?array { return $this->outputs[$file] ?? null; }
            public function digest(): string { return 'fixture'; }
        };
    }
    public static function templateCachePolicies(): object { return self::$registry; }
    public function getRequest(): object { return $this->request; }
    public function fetchTagSource(string $type, string $source): string { return __FILE__; }
    public function fetchTagHtml(string $type, string $file): string
    {
        $this->rendered[] = $file;
        $this->activeAtRender[] = $this->request->modules;
        $this->batchesAtRender[] = count($this->provider->batches);
        return '<file>' . $file . '</file>';
    }
    public function ob_file(string $file, array $dictionary = []): string { return $this->fetchTagHtml('hooks', $file); }
    public function __call(string $method, array $arguments): mixed
    {
        return match ($method) {
            'shouldTraceAccountSidebarHook', 'shouldDecorateHookOutput', 'shouldLogHookDiagnostics' => false,
            'hookLocaleCacheContext', 'baseUrlCacheContext', 'resolveTemplateCachePolicyContext' => 'fixture',
            'staticHookAggregateCacheTtl', 'staticHookOutputCacheTtl' => 60,
            'readStaticHookAggregateCache' => $this->aggregateResult,
            'readStaticHookOutputCache' => ['status' => 'fresh', 'html' => '<file-cached>'],
            'isEmptyCacheHtml' => trim((string)$arguments[0]) === '',
            'isTemplateCacheRenderOnceComplete' => $arguments[0] === 'complete',
            'applyHookSourceDecoration' => $arguments[0],
            'markTemplateCacheRenderOnceComplete' => null,
            default => throw new \LogicException('Unexpected fixture boundary: ' . $method),
        };
    }
}
