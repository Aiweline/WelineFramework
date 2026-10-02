<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\View\Template;
use Weline\Meta\Api\ParamDefinitionNormalizerInterface;
use Weline\Meta\Service\ParamDefinitionNormalizer;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Block\Partials;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;

final class ThemeLayoutSourceReuseTest extends TestCase
{
    private ?Context $previousContext;
    private RuntimeProviderResolver $previousResolver;
    private ThemeVersionIdentity $identity;

    protected function setUp(): void
    {
        $this->previousContext = Context::getCurrent();
        Context::enter(new Context());
        RequestContext::init();
        RequestLifecycleTrace::clearPanelTrace();
        RequestLifecycleTrace::reset();
        $this->previousResolver = ObjectManager::getInstance(RuntimeProviderResolver::class);
        $this->identity = new ThemeVersionIdentity(3, 'source.reuse.fixture', 'normal', 'frontend', 10, 'draft', 2);
        Partials::clearMetaCache();
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance(RuntimeProviderResolver::class, $this->previousResolver);
        Partials::clearMetaCache();
        RequestLifecycleTrace::clearPanelTrace();
        RequestLifecycleTrace::reset();
        Template::resetInstance();
        RequestContext::cleanup();
        if ($this->previousContext !== null) { Context::enter($this->previousContext); }
        else { Context::leave(); }
    }

    public function testRepeatedInstallDoesNotRepeatPinsButAlwaysRestoresCurrentSnapshot(): void
    {
        $this->tracePins();
        $snapshot = $this->snapshot('A');
        $template = new SourceReuseRecordingTemplate();
        $snapshot->install($template);
        $firstPins = $this->pinWrites();
        self::assertGreaterThan(0, $firstPins);
        self::assertStringContainsString('A', $template->pinned('Weline_Theme::theme/frontend/partials/probe/default.phtml'));
        RequestContext::remove(ThemeLayoutSourceSnapshot::REQUEST_KEY);
        $snapshot->install($template);
        self::assertSame($snapshot, ThemeLayoutSourceSnapshot::current());
        self::assertSame($firstPins, $this->pinWrites(), 'The same active source installation should not write every alias again.');
    }

    public function testReturningToAnEarlierSnapshotReinstallsItsSources(): void
    {
        $a = $this->snapshot('A');
        $b = $this->snapshot('B');
        $template = new SourceReuseRecordingTemplate();
        $a->install($template);
        $b->install($template);
        self::assertStringContainsString('B', $template->pinned('Weline_Theme::theme/frontend/partials/probe/default.phtml'));
        $a->install($template);
        self::assertSame($a, ThemeLayoutSourceSnapshot::current());
        self::assertStringContainsString('A', $template->pinned('Weline_Theme::theme/frontend/partials/probe/default.phtml'));
    }

    public function testExternalPinOverwriteIsReplacedOnTheNextInstall(): void
    {
        $snapshot = $this->snapshot('CAPTURED');
        $template = new SourceReuseRecordingTemplate();
        $snapshot->install($template);
        $alias = 'Weline_Theme::theme/frontend/partials/probe/default.phtml';
        $template->pinSource($alias, 'EXTERNAL');
        self::assertSame('EXTERNAL', $template->pinned($alias));
        $snapshot->install($template);
        self::assertStringContainsString('CAPTURED', $template->pinned($alias));
    }

    public function testInstallBelongsToTheSnapshotAndTemplateInstances(): void
    {
        $this->tracePins();
        $a = $this->snapshot('SAME');
        $b = $this->snapshot('SAME');
        self::assertSame($a->fingerprint(), $b->fingerprint());
        $one = new SourceReuseRecordingTemplate();
        $two = new SourceReuseRecordingTemplate();
        $a->install($one);
        $a->install($two);
        self::assertStringContainsString('SAME', $two->pinned('Weline_Theme::theme/frontend/partials/probe/default.phtml'));
        $pins = $this->pinWrites();
        $b->install($one);
        self::assertGreaterThan($pins, $this->pinWrites(), 'A new snapshot instance must install even when its compatibility fingerprint matches.');
    }

    public function testRepeatedPartialReferencesReuseParsedSourceWithoutSharingRuntimeData(): void
    {
        $normalizer = $this->normalizer();
        $this->snapshot('CAPTURED')->install(Template::getInstance());
        $partials = new SourceReusePartials();
        self::assertSame('CAPTURED|first', $partials->renderPartials('frontend', 'probe', ['request_value' => 'first']));
        self::assertSame('CAPTURED|second', $partials->renderPartials('frontend', 'probe', ['request_value' => 'second']));
        self::assertSame(1, $normalizer->extractions, 'Parameters and cache policy must reuse the same captured source parse.');
    }

    public function testCapturedMetadataUsesTheCurrentNormalizerInstance(): void
    {
        $first = $this->normalizer('-one');
        $this->snapshot('CAPTURED')->install(Template::getInstance());
        $partials = new SourceReusePartials();
        self::assertSame('CAPTURED-one|', $partials->renderPartials('frontend', 'probe'));
        $second = $this->normalizer('-two');
        self::assertSame('CAPTURED-two|', $partials->renderPartials('frontend', 'probe'));
        self::assertSame('CAPTURED-two|', $partials->renderPartials('frontend', 'probe'));
        self::assertSame(1, $first->extractions);
        self::assertSame(1, $second->extractions);
    }

    public function testMetadataParsingStaysLazyAndDoesNotReadUnusedPartials(): void
    {
        $normalizer = $this->normalizer();
        $snapshot = $this->snapshot('CAPTURED', true);
        $snapshot->install(Template::getInstance());
        self::assertSame(0, $normalizer->extractions);
        self::assertSame('CAPTURED|', (new SourceReusePartials())->renderPartials('frontend', 'probe'));
        self::assertSame(1, $normalizer->extractions);
    }

    public function testParsedMetadataIsRequestLocalAndReturnedByValue(): void
    {
        $normalizer = $this->normalizer();
        $snapshot = $this->snapshot('CAPTURED');
        $source = $snapshot->source('/fixture/partials/probe/default.phtml');
        self::assertTrue(method_exists($snapshot, 'parseSourceMeta'));
        $parsed = $snapshot->parseSourceMeta($source);
        $parsed['params'][0]['default'] = 'CHANGED';
        self::assertSame('CAPTURED', $snapshot->parseSourceMeta($source)['params'][0]['default']);
        self::assertSame(1, $normalizer->extractions);

        RequestContext::cleanup();
        RequestContext::init();
        self::assertSame('CAPTURED', $snapshot->parseSourceMeta($source)['params'][0]['default']);
        self::assertSame(2, $normalizer->extractions, 'Even a retained snapshot must not share parsed metadata across requests.');
    }

    public function testSourcePhasesAreRecordedOnlyWhenTraceIsEnabled(): void
    {
        $root = sys_get_temp_dir() . '/weline-source-trace-' . bin2hex(random_bytes(6)) . '/theme-layout-entities';
        $paths = new ThemeLayoutEntityPaths($root);
        $path = $paths->partialPhtml($this->identity, 'probe');
        mkdir(dirname($path), 0770, true);
        file_put_contents($path, $this->source('TRACE'));
        try {
            $this->normalizer();
            ThemeLayoutSourceSnapshot::capture($paths, $this->identity, 'homepage')->install(Template::getInstance());
            self::assertSame('TRACE|', (new SourceReusePartials())->renderPartials('frontend', 'probe'));
            self::assertSame([], RequestLifecycleTrace::getSpans());
            RequestLifecycleTrace::installPanelTraceOn();
            RequestLifecycleTrace::reset();
            Partials::clearMetaCache();
            ThemeLayoutSourceSnapshot::capture($paths, $this->identity, 'homepage')->install(Template::getInstance());
            self::assertSame('TRACE|', (new SourceReusePartials())->renderPartials('frontend', 'probe'));
            $names = array_column(RequestLifecycleTrace::getSpans(), 'name');
            foreach (['theme.source.capture', 'theme.source.install', 'theme.source.meta_parse'] as $phase) {
                self::assertContains($phase, $names);
            }
        } finally {
            $paths->purgeAllEntities();
            @rmdir(dirname($root));
        }
    }

    private function snapshot(string $label, bool $unused = false): ThemeLayoutSourceSnapshot
    {
        $sources = ['/fixture/partials/probe/default.phtml' => $this->source($label)];
        if ($unused) {
            $sources['/fixture/partials/unused/default.phtml'] = $this->source('unused', 'unused', '@meta.name.value {default="INVALID"}');
        }
        return ThemeLayoutSourceSnapshot::fromCandidates($this->identity, '', $sources);
    }

    private function source(string $label, string $type = 'probe', string $extra = ''): string
    {
        $metadata = ['origin' => __FILE__ . '.' . $type, 'identity' => $this->identity->toArray(), 'resource_type' => 'partial', 'partial_type' => $type, 'partial_option' => 'default'];
        return '<?php /* weline-source:' . base64_encode(json_encode($metadata, JSON_THROW_ON_ERROR)) . ' */ ?>'
            . '<?php /** @param label {type="string", default="' . $label . '"}' . "\n"
            . ' * @meta.cache.mode {default="off"}' . "\n" . $extra . ' */ ?>';
    }

    private function normalizer(string $suffix = ''): SourceReuseNormalizer
    {
        $normalizer = new SourceReuseNormalizer($suffix);
        $resolver = new RuntimeProviderResolver(new ServiceProviderRegistry());
        (new \ReflectionProperty($resolver, 'resolved'))->setValue($resolver, [ParamDefinitionNormalizerInterface::class => $normalizer]);
        ObjectManager::setInstance(RuntimeProviderResolver::class, $resolver);
        return $normalizer;
    }

    private function tracePins(): void
    {
        RequestLifecycleTrace::installPanelTraceOn();
        RequestLifecycleTrace::reset();
    }

    private function pinWrites(): int
    {
        return RequestLifecycleTrace::getAggregateSummary()['phases']['template.source_pin']['calls'] ?? 0;
    }
}

/** Expose actual bindings while retaining Template's single and bulk installation behavior. */
final class SourceReuseRecordingTemplate extends Template
{
    public function __construct() {}
    public function pinned(string $path): string
    {
        return (new \ReflectionProperty(Template::class, 'pinnedSources'))->getValue($this)[$path][0];
    }
}

/** The test isolates metadata work from normal template compilation, which has its own runtime suite. */
final class SourceReusePartials extends Partials
{
    public function __construct() {}
    public function getFetchFile(string $fileName, ?string $module_name = ''): string { return __FILE__; }
    public function ob_file(string $filename, array $dictionary = []): string
    {
        return (string)($dictionary['meta']['label'] ?? '') . '|' . (string)($dictionary['request_value'] ?? '');
    }
}

final class SourceReuseNormalizer extends ParamDefinitionNormalizer
{
    public int $extractions = 0;
    public function __construct(private readonly string $suffix = '') {}
    public function extractParamAnnotations(string $content): array
    {
        $this->extractions++;
        $params = parent::extractParamAnnotations($content);
        if (isset($params['label'])) { $params['label']['default'] .= $this->suffix; }
        return $params;
    }
}
