<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\BatchGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Framework\View\Taglib\Cache\FileCache;
use Weline\Framework\View\Taglib\Cache\MultiLevelCache;

final class TemplateTranslationPrefetchTest extends TestCase
{
    private array $instances;
    private mixed $manager;
    private TemplatePrefetchDictionary $provider;
    private TemplatePrefetchGeneration $generation;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->instances = ObjectManager::getInstances();
        $property = new ReflectionProperty(ObjectManager::class, 'instance');
        $this->manager = $property->getValue();
        $property->setValue(null, (new ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        Parser::clearWorkerCaches();
        $this->provider = new TemplatePrefetchDictionary();
        $this->generation = new TemplatePrefetchGeneration();
        ObjectManager::setInstance(NamespaceGenerationInterface::class, $this->generation);
        ObjectManager::setInstance(Request::class, new class {
            public function getModules(): array { return []; }
            public function getModuleName(): string { return ''; }
        });
        (new ReflectionProperty(Parser::class, 'globalDictionaryProviderInstance'))->setValue(null, $this->provider);
        $pool = $this->createMock(TemplatePrefetchPool::class);
        $pool->method('remember')->willReturnCallback(static fn($key, $ttl, $builder) => $builder());
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $pool);
        $this->cacheDir = sys_get_temp_dir() . '/weline-translation-prefetch-' . bin2hex(random_bytes(8));
        $this->nextRequest();
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        RequestContext::cleanup();
        (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->instances);
        (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->manager);
        State::resetRequestPathLocalizationCache();
        Runtime::resetModeCache();
        if (is_dir($this->cacheDir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($this->cacheDir);
        }
    }

    public function testStaticInlineAndPairedLangUseOneBatchBeforeTranslation(): void
    {
        $result = $this->compile('@lang{"Inline word"}|<lang>Paired word</lang>');
        self::assertSame('Inline translated|Paired translated', $this->render($result));
        self::assertSame([], $this->provider->wordCalls);
        self::assertSame([['Inline word', 'Paired word']], $this->provider->batchCalls['en_US'] ?? []);
    }

    public function testRuntimeLiteralsReuseManifestAfterNewRequestAndGeneration(): void
    {
        $compiled = $this->compile("<?= __('Runtime word') ?>|<?= __('Other word') ?>");
        $this->provider->batchCalls = [];
        $this->nextRequest();
        self::assertSame('Runtime translated|Other translated', $this->render($compiled));
        self::assertSame([], $this->provider->wordCalls);
        $this->generation->version++;
        $this->provider->suffix = ' changed';
        $this->nextRequest();
        self::assertSame('Runtime translated changed|Other translated changed', $this->render($compiled));
        self::assertSame([['Other word', 'Runtime word']], $this->provider->batchCalls['en_US'] ?? []);
    }

    public function testLoadedModuleWordsNeverEnterExactBatch(): void
    {
        $word = 'Warmup';
        Parser::parse($word);
        $layers = (new ReflectionProperty(Parser::class, 'currentRequestLayeredWords'))->getValue();
        $layers['modules'] = ['Weline_PrefetchFixture'];
        $layers['module_words'] = ['Weline_PrefetchFixture' => ['Runtime word' => 'Module translated']];
        (new ReflectionProperty(Parser::class, 'currentRequestLayeredWords'))->setValue(null, $layers);
        $this->provider->wordCalls = [];
        $this->provider->batchCalls = [];
        $compiled = $this->compile("<?= __('Runtime word') ?>");
        self::assertSame('Module translated', $this->render($compiled));
        self::assertSame([], $this->provider->batchCalls);
        self::assertSame([], $this->provider->wordCalls);
    }

    public function testDynamicWordsAreNotEvaluatedOrPrefetched(): void
    {
        $compiled = $this->compile("<?= __(\$dynamic) ?>|@lang(\$dynamic)|<?= __('prefix ' . \$dynamic) ?>");
        self::assertSame([], $this->provider->wordCalls);
        self::assertSame([], $this->provider->batchCalls);
        self::assertSame('Dynamic translated|Dynamic translated|prefix Dynamic word', $this->render($compiled, 'Dynamic word'));
        self::assertNotEmpty($this->provider->wordCalls);
        self::assertSame([], $this->provider->batchCalls);
    }

    public function testRuntimeManifestUsesCurrentLocaleAndRequestOverlay(): void
    {
        $compiled = $this->compile("<?= __('Runtime word') ?>");
        $this->nextRequest('fr_FR');
        self::assertSame('Mot traduit', $this->render($compiled));
        $this->nextRequest('en_US', ['Runtime word' => 'Scoped translation']);
        ObjectManager::setInstance(Request::class, new class {
            public function getModules(): array { return ['Weline_PrefetchOverlayFixture']; }
            public function getModuleName(): string { return 'Weline_PrefetchOverlayFixture'; }
        });
        $this->provider->moduleCalls = [];
        self::assertSame('Scoped translation', $this->render($compiled));
        self::assertSame([], $this->provider->moduleCalls);
        $this->nextRequest('en_US', ['Runtime word' => 'Exclusive translation'], 'exclusive');
        $this->provider->batchCalls = [];
        self::assertSame('Exclusive translation', $this->render($compiled));
        self::assertSame([], $this->provider->batchCalls);
    }

    public function testLiteralSourceIdentityAndNonTranslationCallsRemainIntact(): void
    {
        $source = <<<'PHP'
<?php
// __('Comment word')
$object = new class { public function __(string $word): string { return $word; } };
echo $object->__('Member word'), '|', __('It\'s ready'), '|', __("Line\nword");
?>
PHP;
        $compiled = $this->compile($source);
        self::assertSame("Member word|Ready translated|Line translated", trim($this->render($compiled)));
        self::assertSame([], $this->provider->wordCalls);
        self::assertSame([["It's ready", "Line\nword"]], $this->provider->batchCalls['en_US'] ?? []);
    }

    public function testLangLookalikesInsidePhpDataAreNotPrefetched(): void
    {
        $compiled = $this->compile('<?php $unused = "<lang>Ghost word</lang>"; echo "Unchanged"; ?>');
        self::assertSame('Unchanged', $this->render($compiled));
        self::assertSame([], $this->provider->wordCalls);
        self::assertSame([], $this->provider->batchCalls);
    }

    public function testCompileRemainsCompatibleWithParserWithoutPrefetch(): void
    {
        $this->assertLegacyParserCanRender(true);
    }

    public function testRuntimeManifestRemainsCompatibleWithParserWithoutPrefetch(): void
    {
        $this->assertLegacyParserCanRender(false);
    }

    private function assertLegacyParserCanRender(bool $compile): void
    {
        // 独立进程只移除新 API 的名称，其他 Parser 与翻译函数均运行真实源码。
        $script = <<<'PHP'
require %s;
$source = file_get_contents(%s);
$source = str_replace('public static function prefetchTemplateWords(', 'public static function unavailablePrefetchTemplateWords(', $source, $count);
if ($count !== 1) { throw new LogicException('Expected exactly one prefetch API declaration'); }
eval(substr($source, 5));
require %s;
$test = new \Weline\Framework\Test\Unit\Phrase\TemplateTranslationPrefetchTest('testRuntimeManifestRemainsCompatibleWithParserWithoutPrefetch');
$invoke = static function (string $method, ...$arguments) use ($test) {
    return (new ReflectionMethod($test, $method))->invoke($test, ...$arguments);
};
$invoke('setUp');
try {
    if (method_exists(\Weline\Framework\Phrase\Parser::class, 'prefetchTemplateWords')) {
        throw new LogicException('Legacy Parser must lack the new API');
    }
    $invoke('nextRequest', 'en_US', ['Runtime word' => 'Legacy translation'], 'exclusive');
    $compiled = %s
        ? $invoke('compile', '@lang{"Runtime word"}')
        : \Weline\Framework\View\Taglib\TemplateTranslationWords::withRuntimePrefetch("<?= __('Runtime word') ?>");
    echo $invoke('render', $compiled);
} finally {
    $invoke('tearDown');
}
PHP;
        $script = sprintf($script, var_export(BP . 'app/bootstrap_phpunit.php', true),
            var_export(BP . 'app/code/Weline/Framework/Phrase/Parser.php', true),
            var_export(__FILE__, true), $compile ? 'true' : 'false');
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BP);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame('Legacy translation', $output);
    }

    private function nextRequest(string $locale = 'en_US', array $overlay = [], string $mode = 'overlay'): void
    {
        RequestContext::init();
        State::setRequestLanguageOverride($locale);
        RequestContext::set('phrase.event_dictionary.state', [
            'locale' => $locale, 'active' => $overlay !== [], 'mode' => $mode,
            'scope_key' => 'fixture', 'words' => $overlay, 'keyed_words' => [],
        ]);
    }

    private function compile(string $source): string
    {
        $taglib = new Taglib();
        (new ReflectionProperty(Taglib::class, 'cache'))->setValue($taglib, new MultiLevelCache(l3: new FileCache($this->cacheDir)));
        $template = new Template();
        return $taglib->compile($template, $source, $this->cacheDir . '/source.phtml');
    }

    private function render(string $compiled, string $dynamic = ''): string
    {
        ob_start();
        try { eval('?>' . $compiled); return (string)ob_get_contents(); }
        finally { ob_end_clean(); }
    }
}

interface TemplatePrefetchPool extends CachePoolInterface
{
    public function remember(string $key, int $ttl, callable $builder, mixed $options = null): mixed;
}

final class TemplatePrefetchGeneration implements NamespaceGenerationInterface
{
    public int $version = 1;
    public function fingerprint(array $namespaces): string { return 'template-generation-' . $this->version; }
    public function bumpMany(array $namespaces): array { throw new \LogicException('Read only'); }
    public function bump(string $namespace): array { throw new \LogicException('Read only'); }
}

final class TemplatePrefetchDictionary implements GlobalDictionaryProviderInterface, BatchGlobalDictionaryProviderInterface
{
    public array $wordCalls = [];
    public array $batchCalls = [];
    public array $moduleCalls = [];
    public string $suffix = '';
    public function word(string $locale, string $word): ?string
    {
        $this->wordCalls[] = [$locale, $word];
        return $this->translation($locale, $word);
    }
    public function words(string $locale, array $modules = []): array { $this->moduleCalls[] = [$locale, $modules]; return []; }
    public function exactWords(string $locale, array $words): array
    {
        $this->batchCalls[$locale][] = $words;
        $result = [];
        foreach ($words as $word) { if (($translation = $this->translation($locale, $word)) !== null) { $result[$word] = $translation; } }
        return $result;
    }
    private function translation(string $locale, string $word): ?string
    {
        if ($locale === 'fr_FR' && $word === 'Runtime word') { return 'Mot traduit'; }
        if ($locale !== 'en_US') { return null; }
        $translation = [
            'Inline word' => 'Inline translated', 'Paired word' => 'Paired translated',
            'Runtime word' => 'Runtime translated', 'Other word' => 'Other translated',
            'Dynamic word' => 'Dynamic translated',
            "It's ready" => 'Ready translated', "Line\nword" => 'Line translated',
        ][$word] ?? null;
        return $translation === null ? null : $translation . $this->suffix;
    }
}
