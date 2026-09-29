<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\LayoutEntity\ResolvedLayoutSlots;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityWidgetRenderer;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
use Weline\Theme\Service\ThemeComponentRenderer;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\ThemeRenderableResolver;

require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

/** Executes generated PHP against real render dispatch; only registry discovery is an in-memory fixture. */
final class ResolvedLayoutPhtmlTest extends TestCase
{
    use ResolvedPhtmlFixture;

    public function testResolvedConfigDoesNotAcquireCurrentDefinitionDefaults(): void
    {
        $definition = new ThemeComponentDefinition(module: 'Fixture', type: 'content', code: 'config', name: 'config', renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT,
            defaultConfig: ['new_default' => 'LATEST'], templateContent: '<?php echo $this->getData("text") . ":" . ($this->getData("new_default") ?? "absent");');
        $html = $this->components->render($definition, ['text' => 'SAVED'], null, ['configResolved' => true]);
        self::assertStringContainsString('SAVED:absent', $html);
    }

    public function testNativeSlotPreservesPhpAndAttributesWhileAddingOrderedCalls(): void
    {
        $source = '<?php $dynamic = "RUNTIME"; ?><main data-dynamic="<?= $dynamic ?>"><div aria-label="Keep > quote" data-wslot="main" data-wslot-append="true"><i><?= $dynamic ?></i></div></main>';
        $compiler = new LayoutRelationCompiler($this->registry);
        $first = $this->node('b', 'main', 'FIRST', 1);
        $first['layout_id'] = 8123; // A legacy projection must not replace the canonical instance identity.
        $phtml = $compiler->compile($source, [$this->node('a', 'main', 'SECOND', 2), $first]);
        $html = $this->execute($phtml);
        self::assertStringContainsString('data-dynamic="RUNTIME"', $html);
        self::assertStringContainsString('aria-label="Keep > quote"', $html);
        self::assertLessThan(strpos($html, 'FIRST'), strpos($html, '<i>RUNTIME</i>'));
        self::assertLessThan(strpos($html, 'SECOND'), strpos($html, 'FIRST'));
        self::assertStringContainsString('data-node-uid="' . str_repeat('b', 32) . '"', $html);
        self::assertStringNotContainsString('data-layout-id=', $html);
    }

    public function testParentChildrenAreInstanceSpecificAndDefaultsSurviveUntouchedSlots(): void
    {
        $parentA = $this->node('a', 'main', '', 0, 'box');
        $parentB = $this->node('b', 'main', '', 1, 'box');
        $child = $this->node('c', 'inside', 'ONE', 0);
        $child['parent_uid'] = $parentA['node_uid'];
        $phtml = (new LayoutRelationCompiler($this->registry))->compile('<div data-wslot="main"></div>', [$parentA, $parentB, $child]);
        $html = $this->execute($phtml);
        self::assertSame(1, substr_count($html, '<b>ONE</b>'));
        self::assertSame(1, substr_count($html, '<em>default</em>'));
        self::assertStringNotContainsString('data-slot-id="inside"></div><', $html);
    }

    public function testNativeRuntimeSlotsAreRequestAndFiberLocalAndRestoreAfterThrow(): void
    {
        $source = LayoutRelationCompiler::compileRuntimeSlots('<div data-wslot="same"><b>DEFAULT</b></div>');
        $one = new \Fiber(function () use ($source): string {
            return ResolvedLayoutSlots::with(['same' => static fn(): string => '<b>ONE</b>'], function () use ($source): string {
                \Fiber::suspend();
                return $this->execute($source);
            });
        });
        $one->start();
        self::assertSame('<div data-wslot="same"><b>DEFAULT</b></div>', $this->execute($source));
        $two = new \Fiber(fn(): string => ResolvedLayoutSlots::with(['same' => static fn(): string => '<b>TWO</b>'], fn(): string => $this->execute($source)));
        $two->start();
        $one->resume();
        self::assertStringContainsString('ONE', $one->getReturn());
        self::assertStringContainsString('TWO', $two->getReturn());
        try { ResolvedLayoutSlots::with(['same' => static fn(): string => 'leak'], static function (): string { throw new \RuntimeException('probe'); }); } catch (\RuntimeException) {}
        self::assertFalse(ResolvedLayoutSlots::has('same'));
    }

    public function testTaglibSlotKeepsAttributesAndExecutesOnlySelectedInstanceBody(): void
    {
        \Weline\Theme\Taglib\Slot::clearRegisteredSlots();
        $compiled = (\Weline\Theme\Taglib\Slot::callback())('tag', ['file' => 'fixture'], [null, null, '<?php echo "DEFAULT"; ?>'], ['id' => 'inside', 'wrapper' => 'aside', 'exclusive' => 'true', 'class' => 'kept', 'aria-label' => 'original']);
        $html = ResolvedLayoutSlots::with(['inside' => static fn(): string => 'SAVED'], fn(): string => $this->execute($compiled));
        self::assertStringContainsString('aria-label="original"', $html);
        self::assertStringContainsString('SAVED', $html);
        self::assertStringNotContainsString('DEFAULT', $html);
    }

    public function testCandidateGenerationDoesNotWriteAndConfigChangesReplacePhtml(): void
    {
        $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths(sys_get_temp_dir() . '/theme-phtml-candidate-' . bin2hex(random_bytes(5)));
        $materializer = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer($paths,
            ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder::class),
            ObjectManager::getInstance(ThemeLayoutEntityConfigStore::class));
        ObjectManager::setInstance(ThemePlaceableRegistry::class, $this->registry);
        $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(1, 'default.default.default', 'normal', 'frontend', 999, 'draft', 1);
        $node = $this->node('a', 'homepage-bottom', 'SAVED ONE', 0);
        $candidate = $materializer->candidatePage($identity, 'homepage', 'unused', [$node], [$node['node_uid'] => $node], 'homepage');
        self::assertCount(1, $candidate);
        $path = array_key_first($candidate);
        self::assertFileDoesNotExist($path);
        $node['config']['text'] = 'SAVED TWO';
        $next = $materializer->candidatePage($identity, 'homepage', 'unused', [$node], [$node['node_uid'] => $node], 'homepage');
        self::assertSame($path, array_key_first($next));
        self::assertNotSame($candidate[$path], $next[$path]);
        self::assertFileDoesNotExist($path);
    }

    public function testRepeatedDefaultInstancesKeepTheirOriginalPhpBranches(): void
    {
        $left = $this->node('a', 'main', 'LEFT', 0); $left['config']['template_ref'] = 'left';
        $right = $this->node('b', 'main', 'RIGHT', 1); $right['config']['template_ref'] = 'right';
        $source = '<div data-wslot="main"><?php if ($choice): ?><w:widget type="content" name="label" ref="left"/><?php else: ?><w:widget type="content" name="label" ref="right"/><?php endif; ?></div>';
        $compiled = (new LayoutRelationCompiler($this->registry))->compile($source, [$left, $right]);
        $leftHtml = $this->execute($compiled, ['choice' => true]);
        $rightHtml = $this->execute($compiled, ['choice' => false]);
        self::assertStringContainsString('<b>LEFT</b>', $leftHtml);
        self::assertStringNotContainsString('<b>RIGHT</b>', $leftHtml);
        self::assertStringContainsString('<b>RIGHT</b>', $rightHtml);
        self::assertStringNotContainsString('<b>LEFT</b>', $rightHtml);
    }

    public function testLegacyConfigVariableAndChildrenAreAvailableWithoutCurrentConfigMerge(): void
    {
        $this->registry->definitions['legacy'] = $this->definition('legacy', '<?php echo $config["text"] ?? "MISSING_CONFIG"; foreach (($children["inside"] ?? []) as $child) { echo $child["html"]; }');
        $parent = $this->node('a', 'main', 'PARENT', 0, 'legacy');
        $child = $this->node('b', 'inside', 'CHILD', 1); $child['parent_uid'] = $parent['node_uid'];
        $compiled = (new LayoutRelationCompiler($this->registry))->compile('<div data-wslot="main"></div>', [$parent, $child]);
        $html = $this->execute($compiled);
        self::assertStringContainsString('PARENT', $html);
        self::assertStringContainsString('<b>CHILD</b>', $html);
        self::assertStringNotContainsString('MISSING_CONFIG', $html);
    }

    public function testHookStillSelectsItsRuntimeBranchAroundResolvedCalls(): void
    {
        $node = $this->node('a', 'main', 'FALLBACK', 0);
        $source = '<w:slot id="main"><w:hook>seo::head<else/><w:widget type="content" name="label" /></w:hook></w:slot>';
        $phtml = (new LayoutRelationCompiler($this->registry))->compile($source, [$node]);
        $template = ObjectManager::getInstance(ResolvedPhtmlHookTemplate::class);
        $runtime = new RuntimeTemplateMaterializer($template);
        $template->suppliedHook = false;
        $default = $runtime->renderContent($phtml);
        $template->suppliedHook = true;
        $hook = $runtime->renderContent($phtml);
        self::assertStringContainsString('<b>FALLBACK</b>', $default);
        self::assertStringNotContainsString('<strong>HOOK</strong>', $default);
        self::assertStringContainsString('<strong>HOOK</strong>', $hook);
        self::assertStringNotContainsString('<b>FALLBACK</b>', $hook);
    }

    public function testResolvedFileAndBlockUseTheSameExplicitLocaleConfiguration(): void
    {
        \Weline\Framework\App\State::setRequestLanguageOverride('en_US');
        $file = tempnam(sys_get_temp_dir(), 'resolved-widget-') . '.phtml';
        file_put_contents($file, '<?php echo "<mark>" . htmlspecialchars((string)$config["text"]) . ":" . count($config["items"]) . "</mark>";');
        try {
            $this->registry->definitions['file'] = new ThemeComponentDefinition(module: 'Fixture', type: 'content', code: 'file', name: 'file', templatePath: $file);
            $this->registry->definitions['block'] = new ThemeComponentDefinition(module: 'Fixture', type: 'content', code: 'block', name: 'block', renderMode: ThemeRenderable::MODE_BLOCK_CLASS, blockClass: ResolvedPhtmlProbeBlock::class);
            $node = $this->node('a', 'main', 'BASE', 0, 'file'); $node['config']['items'] = [1, 2, 3];
            $renderer = ObjectManager::getInstance(ThemeLayoutEntityWidgetRenderer::class);
            $translated = $renderer->renderResolved($node, ['en-US' => ['text' => 'ENGLISH', 'items' => [7]]]);
            self::assertStringContainsString('<mark>ENGLISH:1</mark>', $translated);
            $node['widget_code'] = 'block';
            self::assertStringContainsString('<mark>BLOCK</mark>', $renderer->renderResolved($node, ['en_US' => ['text' => 'BLOCK']]));
        } finally { unlink($file); }
    }

    public function testTemplateParameterLocaleSnapshotIsExecutedInOrdinaryTemplate(): void
    {
        \Weline\Framework\App\State::setRequestLanguageOverride('en_US');
        $materializer = (new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($materializer, 'withTemplateParams');
        $phtml = $method->invoke($materializer, '<?php declare(strict_types=1); echo $this->getData("meta.title");', ['title' => 'BASE'], ['en_US' => ['title' => 'ENGLISH META']]);
        $html = (new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent($phtml);
        self::assertStringContainsString('ENGLISH META', $html);
    }

    public function testExclusiveReplacementRetainsHookDecisionWithoutDuplicatingFallback(): void
    {
        $source = '<?php echo "OUTSIDE"; ?><w:slot id="main" exclusive="true"><w:hook>seo::head<else/><section aria-label="original"><em>OLD FALLBACK</em><?php echo "OLD DYNAMIC"; ?></section></w:hook></w:slot>';
        $phtml = (new LayoutRelationCompiler($this->registry))->compile($source, [$this->node('a', 'main', 'NEW', 0)]);
        $template = ObjectManager::getInstance(ResolvedPhtmlHookTemplate::class);
        $runtime = new RuntimeTemplateMaterializer($template);
        $template->suppliedHook = false;
        $html = $runtime->renderContent($phtml);
        self::assertStringContainsString('OUTSIDE', $html);
        self::assertStringContainsString('<b>NEW</b>', $html);
        self::assertStringNotContainsString('OLD FALLBACK', $html);
        self::assertStringNotContainsString('OLD DYNAMIC', $html);
        $template->suppliedHook = true;
        $hook = $runtime->renderContent($phtml);
        self::assertStringContainsString('<strong>HOOK</strong>', $hook);
        self::assertStringNotContainsString('<b>NEW</b>', $hook);
    }

    public function testChildSlotsInReplacedSourceFallbackBelongToTheirContainer(): void
    {
        $parent = $this->node('a', 'main', '', 0, 'box');
        $child = $this->node('b', 'inside', 'NESTED', 0);
        $source = '<div data-wslot="main" data-wslot-exclusive="true"><aside data-wslot="inside">OLD</aside></div>';
        $html = $this->execute((new LayoutRelationCompiler($this->registry))->compile($source, [$parent, $child]));
        self::assertSame(1, substr_count($html, '<b>NESTED</b>'));
        self::assertStringNotContainsString('<em>default</em>', $html);
        self::assertStringNotContainsString('OLD', $html);
    }

    public function testRuntimeNonExclusiveSlotKeepsDefaultContent(): void
    {
        $source = LayoutRelationCompiler::compileRuntimeSlots('<div data-wslot="inside"><b>DEFAULT</b></div>');
        $html = ResolvedLayoutSlots::with(['inside' => static fn(): string => '<b>ADDED</b>'], fn(): string => $this->execute($source));
        self::assertSame('<div data-wslot="inside"><b>DEFAULT</b><b>ADDED</b></div>', $html);
    }

    public function testEmptyNodeSelectionPreservesDefaultsWhileExplicitClearRemovesThem(): void
    {
        $source = '<w:slot id="main"><w:widget type="content" name="text-block" params=\'{"title":"SOURCE DEFAULT"}\'/></w:slot>';
        $compiler = new LayoutRelationCompiler($this->registry);
        $runtime = new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class));
        self::assertStringContainsString('SOURCE DEFAULT', $runtime->renderContent($compiler->compile($source, [])));
        $marker = ['widget_code' => '__no_widget_placements__'];
        self::assertStringNotContainsString('SOURCE DEFAULT', $runtime->renderContent($compiler->compile($source, [$marker])));
    }

    public function testAutomaticRequiredNodeKeepsOriginalDefaultInvocation(): void
    {
        $source = '<w:slot id="main"><?php if ($this->getData("show_default")): ?><w:widget type="content" name="text-block" params=\'{"title":"ORIGINAL DEFAULT"}\'/><?php endif; ?></w:slot>';
        $node = $this->node('a', 'main', 'STATIC INJECTION', 0, 'text-block');
        $node['widget_module'] = 'Weline_Theme';
        $node['source'] = 'default_injection';
        $node['config'] = [];
        $this->registry->definitions['text-block'] = $this->definition('text-block', '<b>INJECTED COPY</b>');
        $phtml = (new LayoutRelationCompiler($this->registry))->compile($source, [$node]);
        $runtime = new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class));
        self::assertStringContainsString('ORIGINAL DEFAULT', $runtime->renderContent($phtml, ['show_default' => true]));
        self::assertStringNotContainsString('ORIGINAL DEFAULT', $runtime->renderContent($phtml, ['show_default' => false]));
        self::assertStringNotContainsString('INJECTED COPY', $runtime->renderContent($phtml, ['show_default' => true]));
    }

    public function testAutomaticDefaultInvocationReceivesOnlyItsInstanceChildren(): void
    {
        $source = '<w:slot id="main"><w:widget type="content" name="text-block" block-class="' . ResolvedPhtmlDefaultContainerBlock::class . '" params=\'{"title":"ORIGINAL"}\'/></w:slot>';
        $node = $this->node('a', 'main', 'STATIC', 0, 'text-block');
        $node['widget_module'] = 'Weline_Theme';
        $node['source'] = 'default_injection';
        $node['config'] = [];
        $child = $this->node('b', 'inside', 'CHILD', 0); $child['parent_uid'] = $node['node_uid'];
        $this->registry->definitions['text-block'] = $this->definition('text-block', '<b>INJECTED COPY</b>', ['inside']);
        $phtml = (new LayoutRelationCompiler($this->registry))->compile($source, [$node, $child]);
        $runtime = new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class));
        $html = $runtime->renderContent($phtml);
        self::assertStringContainsString('<aside>ORIGINAL', $html);
        self::assertStringContainsString('<b>CHILD</b>', $html);
        self::assertFalse(ResolvedLayoutSlots::has('inside'));
    }

    public function testFullSlotReplacementRunsInEitherPhpBranchAndKeepsHookDecision(): void
    {
        $node = $this->node('a', 'main', 'SAVED', 0); $node['config']['cow_full_slot'] = true;
        $source = '<w:slot id="main"><w:hook>seo::head<else/><?php if ($this->getData("choice")): ?><w:widget type="content" name="left"/><w:widget type="content" name="extra"/><?php else: ?><w:widget type="content" name="right"/><?php endif; ?></w:hook></w:slot>';
        $phtml = (new LayoutRelationCompiler($this->registry))->compile($source, [$node]);
        $template = ObjectManager::getInstance(ResolvedPhtmlHookTemplate::class);
        $runtime = new RuntimeTemplateMaterializer($template);
        foreach ([true, false] as $choice) {
            $template->suppliedHook = false;
            self::assertSame(1, substr_count($runtime->renderContent($phtml, ['choice' => $choice]), '<b>SAVED</b>'));
            $template->suppliedHook = true;
            $html = $runtime->renderContent($phtml, ['choice' => $choice]);
            self::assertStringContainsString('<strong>HOOK</strong>', $html);
            self::assertStringNotContainsString('<b>SAVED</b>', $html);
        }
    }

    public function testEditedAutomaticDefaultFreezesConfigLocaleAndSourceBlockDispatch(): void
    {
        $source = '<w:slot id="main"><?php if ($this->getData("visible")): ?><w:widget type="content" name="text-block" block-class="' . ResolvedPhtmlDefaultContainerBlock::class . '" params=\'{"title":"SOURCE TITLE"}\'/><?php endif; ?></w:slot>';
        $node = $this->node('a', 'main', '', 0, 'text-block');
        $node['widget_module'] = 'Weline_Theme'; $node['source'] = 'default_injection'; $node['config'] = ['title' => 'SAVED TITLE'];
        $child = $this->node('b', 'inside', 'CHILD', 0); $child['parent_uid'] = $node['node_uid'];
        $this->registry->definitions['text-block'] = $this->definition('text-block', '<b>WRONG DISPATCH</b>', ['inside']);
        $phtml = (new LayoutRelationCompiler($this->registry))->compile($source, [$node, $child], [], ['en_US' => [$node['node_uid'] => ['title' => 'LOCALE TITLE']]]);
        $runtime = new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class));
        foreach (['zh_CN' => 'SAVED TITLE', 'en_US' => 'LOCALE TITLE'] as $locale => $expected) {
            \Weline\Framework\App\State::setRequestLanguageOverride($locale);
            $html = $runtime->renderContent($phtml, ['visible' => true]);
            self::assertStringContainsString('<aside>' . $expected, $html);
            self::assertStringContainsString('<b>CHILD</b>', $html);
            self::assertStringContainsString('data-node-uid="' . $node['node_uid'] . '"', $html);
            self::assertStringNotContainsString('SOURCE TITLE', $html);
            self::assertStringNotContainsString($expected, $runtime->renderContent($phtml, ['visible' => false]));
        }
    }

    public function testResolvedArrayValuesNeverAcquireCurrentDefaults(): void
    {
        $definition = new ThemeComponentDefinition(module: 'Weline_Theme', type: 'theme_component', code: 'frozen-array', name: 'frozen-array',
            renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT, params: ['items' => ['type' => 'array', 'default' => ['LATEST DEFAULT']]],
            templateContent: '<?php echo "<value>" . json_encode($this->getData("meta.items")) . "</value>";');
        foreach ([null, '', false, '["SAVED"]'] as $value) {
            $expected = is_string($value) && $value !== '' ? ['SAVED'] : $value;
            foreach ([['items' => $value], ['meta' => ['items' => $value]], ['meta.items' => $value]] as $config) {
                self::assertStringContainsString('<value>' . json_encode($expected) . '</value>', $this->components->renderResolved($definition, $config));
            }
        }
    }

    public function testGenerationExposesCompleteNodeConfigurationBeforeLocaleOverlay(): void
    {
        $this->registry->definitions['label'] = new ThemeComponentDefinition(module: 'Fixture', type: 'content', code: 'label', name: 'label',
            params: ['slides' => ['type' => 'array', 'default' => '[{"image":"BASE IMAGE","link":"BASE LINK"}]'], 'items' => ['type' => 'array', 'default' => ['BASE']]],
            defaultConfig: ['title' => 'DEFAULT TITLE']);
        ObjectManager::setInstance(ThemePlaceableRegistry::class, $this->registry);
        $materializer = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer::class);
        $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(1, 'default.default.default', 'normal', 'frontend', 999, 'draft', 1);
        $node = $this->node('a', 'main', '', 0); $node['config'] = ['title' => 'SAVED', 'items' => null];
        $configs = $materializer->resolveNodeConfigurations([$node], $identity);
        self::assertSame(['slides' => [['image' => 'BASE IMAGE', 'link' => 'BASE LINK']], 'items' => null, 'title' => 'SAVED'], $configs[$node['node_uid']]);
    }

    public function testPartialCandidateKeepsOptionLocaleAndArbitrarilyNamedChildSlot(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'partial-source-');
        file_put_contents($source, '<?php echo "<title>" . $this->getData("meta.title") . "</title>"; ?><div data-wslot="generic-actions"></div>');
        $catalog = new class($source) extends \Weline\Theme\Service\ThemeResourceCatalog {
            public function __construct(private readonly string $source) {}
            public function getResources(string $type, string $area = 'frontend', ?WelineTheme $theme = null): array
            { return ['partials/sidebar/compact' => ['file_path' => $this->source]]; }
        };
        ObjectManager::setInstance(\Weline\Theme\Service\ThemeResourceCatalog::class, $catalog);
        ObjectManager::setInstance(ThemePlaceableRegistry::class, $this->registry);
        $version = clone ObjectManager::getInstance(\Weline\Theme\Model\ThemeScopeVersion::class);
        $version->setData(['theme_id' => 1, 'scope' => 'default.default.default', 'store_mode' => 'normal', 'area' => 'frontend', 'version_id' => 999,
            'lifecycle' => 'draft', 'content_revision' => 1]);
        $node = $this->node('a', 'generic-actions', 'PARTIAL CHILD', 0); $node['area'] = 'header';
        $version->setChromePayload([$node]);
        try {
            $candidates = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer::class)->candidateChrome($version,
                ['en_US' => ['partials.sidebar' => ['title' => 'LOCALIZED SIDEBAR']]], ['sidebar' => 'compact'], ['sidebar' => ['title' => 'BASE SIDEBAR']]);
            self::assertCount(1, $candidates);
            self::assertStringEndsWith('/theme/partials/sidebar/compact.phtml', (string)array_key_first($candidates));
            \Weline\Framework\App\State::setRequestLanguageOverride('en_US');
            $html = (new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent((string)reset($candidates));
            self::assertStringContainsString('<title>LOCALIZED SIDEBAR</title>', $html);
            self::assertStringContainsString('<b>PARTIAL CHILD</b>', $html);
        } finally { unlink($source); }
    }

}
