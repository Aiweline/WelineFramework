<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\View\Template;

final class TemplateSourceBytesTest extends TestCase
{
    private bool $contextEntered = false;

    protected function tearDown(): void
    {
        if ($this->contextEntered) {
            State::setRequestLanguageOverride('');
            RequestContext::cleanup();
            Context::leave();
            $this->contextEntered = false;
        }
    }

    public function testSourceWorkIsTracedOnlyWhenTracingIsEnabled(): void
    {
        $template = $this->tracedTemplate();
        $template->pinSource('trace-source.phtml', '<b>traced</b>', __FILE__);
        $template->getFetchFile('trace-source.phtml');
        self::assertSame(1, $this->phaseCalls('template.source_pin'));
        self::assertSame(1, $this->phaseCalls('template.source_bytes_digest'));

        RequestLifecycleTrace::reset();
        $this->setTracing(false);
        $template->pinSource('trace-source.phtml', '<b>disabled</b>', __FILE__);
        $template->getFetchFile('trace-source.phtml');
        self::assertSame(0, $this->phaseCalls('template.source_pin'));
        self::assertSame(0, $this->phaseCalls('template.source_bytes_digest'));
    }

    public function testConsecutiveSourceSetInstallSkipsActualPinningAndPreservesOtherPins(): void
    {
        $template = $this->tracedTemplate();
        $template->pinSource('unrelated.phtml', '<i>other</i>', __FILE__);
        $sources = ['shared.phtml' => ['<b>A</b>', __FILE__, 'revision-a']];
        $this->pinSet($template, 'snapshot-a', $sources);
        $this->pinSet($template, 'snapshot-a', $sources);
        self::assertSame('<b>A</b>', $template->ob_file($template->getFetchFile('shared.phtml')));
        self::assertSame('<i>other</i>', $template->ob_file($template->getFetchFile('unrelated.phtml')));
        self::assertSame(2, $this->phaseCalls('template.source_pin'), 'Only the unrelated pin and first set installation write bindings.');
    }

    public function testReturningToAnEarlierSourceSetRestoresItsBindings(): void
    {
        $template = $this->tracedTemplate();
        $a = ['shared.phtml' => ['<b>A</b>', __FILE__, 'revision-a']];
        $b = ['shared.phtml' => ['<b>B</b>', __FILE__, 'revision-b']];
        $this->pinSet($template, 'snapshot-a', $a);
        $this->pinSet($template, 'snapshot-b', $b);
        self::assertSame('<b>B</b>', $template->ob_file($template->getFetchFile('shared.phtml')));
        $this->pinSet($template, 'snapshot-a', $a);
        self::assertSame('<b>A</b>', $template->ob_file($template->getFetchFile('shared.phtml')));
        self::assertSame(3, $this->phaseCalls('template.source_pin'));
    }

    public function testExternalPinInvalidatesTheCurrentSourceSetBinding(): void
    {
        $template = $this->tracedTemplate();
        $sources = ['shared.phtml' => ['<b>A</b>', __FILE__, 'revision-a']];
        $this->pinSet($template, 'snapshot-a', $sources);
        $template->pinSource('shared.phtml', '<b>external</b>', __FILE__, 'external');
        self::assertSame('<b>external</b>', $template->ob_file($template->getFetchFile('shared.phtml')));
        $this->pinSet($template, 'snapshot-a', $sources);
        self::assertSame('<b>A</b>', $template->ob_file($template->getFetchFile('shared.phtml')));
        self::assertSame(3, $this->phaseCalls('template.source_pin'));
    }

    public function testSourceSetTokensAreScopedToEachTemplateInstance(): void
    {
        $first = $this->tracedTemplate();
        $second = new Template();
        $second->init();
        $sources = ['shared.phtml' => ['<b>A</b>', __FILE__, 'revision-a']];
        $this->pinSet($first, 'snapshot-a', $sources);
        $this->pinSet($second, 'snapshot-a', $sources);
        self::assertSame('<b>A</b>', $second->ob_file($second->getFetchFile('shared.phtml')));
        $second->pinSource('shared.phtml', '<b>B</b>', __FILE__, 'revision-b');
        self::assertSame('<b>A</b>', $first->ob_file($first->getFetchFile('shared.phtml')));
        self::assertSame('<b>B</b>', $second->ob_file($second->getFetchFile('shared.phtml')));
        self::assertSame(3, $this->phaseCalls('template.source_pin'));
    }

    public function testPinnedDigestIsReusedUntilSourceBytesChange(): void
    {
        $template = $this->tracedTemplate();
        $template->pinSource('digest-source.phtml', '<b>old</b>', __FILE__, 'same-context');
        $old = $template->getFetchFile('digest-source.phtml');
        self::assertSame($old, $template->getFetchFile('digest-source.phtml'));
        self::assertSame(1, $this->phaseCalls('template.source_bytes_digest'), 'Repeated pinned bytes must be hashed once.');
        $template->pinSource('digest-source.phtml', '<b>new</b>', __FILE__, 'same-context');
        $new = $template->getFetchFile('digest-source.phtml');
        self::assertNotSame($old, $new, 'Equal byte lengths must not hide a changed source.');
        self::assertSame('<b>new</b>', $template->ob_file($new));
        self::assertSame('<b>old</b>', $template->ob_file($old));
        self::assertSame(2, $this->phaseCalls('template.source_bytes_digest'));
    }

    public function testPinnedDigestReuseDoesNotCacheTheLanguageCompilationContext(): void
    {
        $template = $this->tracedTemplate();
        $template->pinSource('language-source.phtml', '<b>same</b>', __FILE__);
        State::setRequestLanguageOverride('en_US');
        $english = $template->getFetchFile('language-source.phtml');
        State::setRequestLanguageOverride('zh_Hans_CN');
        $chinese = $template->getFetchFile('language-source.phtml');
        self::assertNotSame($english, $chinese);
        self::assertSame('<b>same</b>', $template->ob_file($chinese));
        self::assertSame(1, $this->phaseCalls('template.source_bytes_digest'));
    }

    public function testDirectSourceBytesCannotBorrowOrReplaceThePinnedDigest(): void
    {
        $template = $this->tracedTemplate();
        $template->pinSource('direct-source.phtml', '<b>old</b>', __FILE__, 'same-context');
        $pinned = $template->getFetchFile('direct-source.phtml');
        $direct = $template->getFetchFileFromSource('direct-source.phtml', '<b>new</b>', __FILE__, 'same-context');
        self::assertNotSame($pinned, $direct);
        self::assertSame('<b>new</b>', $template->ob_file($direct));
        self::assertSame($pinned, $template->getFetchFile('direct-source.phtml'));
        self::assertSame('<b>old</b>', $template->ob_file($pinned));
        self::assertSame(2, $this->phaseCalls('template.source_bytes_digest'), 'Direct bytes need their own digest without evicting the unchanged pinned digest.');
    }

    public function testRebindingOriginAndContextKeepsTheCompiledSourceLocation(): void
    {
        $template = $this->tracedTemplate();
        $source = '<?php echo __DIR__ . "|" . __FILE__; ?>';
        $template->pinSource('location-source.phtml', $source, __FILE__, 'context-a');
        $first = $template->getFetchFile('location-source.phtml');
        self::assertSame(__DIR__ . '|' . __FILE__, trim($template->ob_file($first)));

        $otherOrigin = __DIR__ . '/TemplateRenderProfileTest.php';
        $template->pinSource('location-source.phtml', $source, $otherOrigin, 'context-a');
        $relocated = $template->getFetchFile('location-source.phtml');
        self::assertNotSame($first, $relocated);
        self::assertSame(dirname($otherOrigin) . '|' . $otherOrigin, trim($template->ob_file($relocated)));

        $template->pinSource('location-source.phtml', $source, $otherOrigin, 'context-b');
        $recontextualized = $template->getFetchFile('location-source.phtml');
        self::assertNotSame($relocated, $recontextualized);
        self::assertSame(dirname($otherOrigin) . '|' . $otherOrigin, trim($template->ob_file($recontextualized)));
    }

    public function testPinnedDigestReuseDoesNotCacheTheCompilerObserverPipeline(): void
    {
        $template = $this->tracedTemplate();
        $revision = 1;
        $events = $this->getMockBuilder(EventsManager::class)->disableOriginalConstructor()
            ->onlyMethods(['getEventObservers', 'dispatch'])->getMock();
        $events->method('getEventObservers')->willReturnCallback(static function (string $event) use (&$revision): array {
            return $event === 'Weline_Framework_Template::before_compile' ? [['name' => 'pipeline-fixture', 'revision' => $revision]] : [];
        });
        $events->method('dispatch')->willReturnSelf();
        (new \ReflectionProperty(Template::class, 'eventsManager'))->setValue($template, $events);
        $template->pinSource('pipeline-source.phtml', '<b>same</b>', __FILE__);
        $first = $template->getFetchFile('pipeline-source.phtml');
        $revision = 2;
        $second = $template->getFetchFile('pipeline-source.phtml');
        self::assertNotSame($first, $second);
        self::assertSame('<b>same</b>', $template->ob_file($second));
        self::assertSame(1, $this->phaseCalls('template.source_bytes_digest'));
    }

    private function tracedTemplate(): Template
    {
        Context::enter(new Context());
        $this->contextEntered = true;
        RequestContext::setId('template-source-bytes-' . getmypid() . '-' . $this->name());
        RequestLifecycleTrace::reset();
        $this->setTracing(true);

        return Template::getInstance();
    }

    private function setTracing(bool $enabled): void
    {
        $state = (new \ReflectionMethod(RequestLifecycleTrace::class, 'state'))->invoke(null);
        $state->enabledCache = $enabled;
    }

    private function phaseCalls(string $name): int
    {
        return RequestLifecycleTrace::getAggregateSummary()['phases'][$name]['calls'] ?? 0;
    }

    /** @param array<string, array{string, string, string}> $sources */
    private function pinSet(Template $template, string $token, array $sources): void
    {
        self::assertTrue(method_exists($template, 'pinSourceSet'), 'Template needs request-local source-set installation.');
        $template->pinSourceSet($token, $sources);
    }

    public function testPinnedBytesUseNormalTagsAndKeepTemplateData(): void
    {
        $template = Template::getInstance();
        self::assertTrue(method_exists($template, 'fetchSourceHtml'), 'Pinned sources need the ordinary Template compiler.');
        $template->setData('source_label', 'kept');
        $source = '<if condition="$this->getData(\'enabled\')"><b>{{source_label}}</b></if><?php echo $this->getData("suffix"); ?>';
        $html = $template->fetchSourceHtml('virtual-source.phtml', $source, __FILE__, 'revision-1', ['enabled' => true, 'suffix' => '!']);
        self::assertStringContainsString('<b>kept</b>!', $html);
        self::assertSame('kept', $template->getData('source_label'));
    }

    public function testOlderPinnedBytesCannotOverwriteNewerCompilation(): void
    {
        $template = Template::getInstance();
        self::assertTrue(method_exists($template, 'getFetchFileFromSource'), 'Snapshot compilation must be addressable by source bytes.');
        $old = $template->getFetchFileFromSource('revision-source.phtml', '<b>old</b>', __FILE__, 'old-r');
        $new = $template->getFetchFileFromSource('revision-source.phtml', '<b>new</b>', __FILE__, 'new-r');
        $template->getFetchFileFromSource('revision-source.phtml', '<b>old</b>', __FILE__, 'old-r');
        self::assertNotSame($old, $new);
        self::assertStringContainsString('new', $template->ob_file($new));
        self::assertStringContainsString('old', $template->ob_file($old));
    }

    public function testOriginMagicConstantsRetainSourceLocation(): void
    {
        $template = Template::getInstance();
        self::assertTrue(method_exists($template, 'fetchSourceHtml'));
        $html = $template->fetchSourceHtml('magic.phtml', '<?php echo __DIR__ . "|" . __FILE__; ?>', __FILE__);
        self::assertSame(__DIR__ . '|' . __FILE__, trim($html));
    }
    public function testLanguageSeparatesSourceCompilationAndRuntimeMaterializerKeepsData(): void
    {
        $template = Template::getInstance();
        $template->setData('shared_value', 'survives');
        $runtime = new \Weline\Theme\Service\RuntimeTemplateMaterializer($template);
        State::setRequestLanguageOverride('en_US');
        try {
            $english = $runtime->materializeContent('<strong>{{shared_value}}</strong>', __FILE__);
            State::setRequestLanguageOverride('zh_Hans_CN');
            $chinese = $runtime->materializeContent('<strong>{{shared_value}}</strong>', __FILE__);
            self::assertNotSame($english, $chinese);
            self::assertStringContainsString('<strong>survives</strong>', $runtime->renderContent('<strong>{{shared_value}}</strong>', [], __FILE__));
            self::assertSame('survives', $template->getData('shared_value'));
        } finally {
            State::setRequestLanguageOverride('');
        }
    }
    public function testRealRequestUsesOriginModuleWithoutCloningRequest(): void
    {
        $template = Template::getInstance();
        $origin = BP . 'app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml';
        $source = '<?php echo $this->fetchTagSource("statics", "origin-probe.css", false); ?>';
        $html = $template->fetchSourceHtml('origin-probe.phtml', $source, $origin);
        self::assertStringContainsString('/Weline/Theme/view/statics/origin-probe.css', $html);
    }
    public function testStrictTypesRemainsTheFirstPhpStatement(): void
    {
        $html = Template::getInstance()->fetchSourceHtml('strict-source.phtml', '<?php declare(strict_types=1); echo "strict-ok";', __FILE__);
        self::assertSame('strict-ok', $html);
    }
}
