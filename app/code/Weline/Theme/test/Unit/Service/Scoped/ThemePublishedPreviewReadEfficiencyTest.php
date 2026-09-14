<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\EventDictionary;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePublishedSnapshotReaderInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Helper\CssVariableInjector;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\Disk\ThemeTokenCatalogService;
use Weline\Theme\Service\Scoped\ThemeLayoutSnapshotNormalizer;
use Weline\Theme\Service\Scoped\ThemeNodePlacementResolver;
use Weline\Theme\Service\Scoped\ThemeScopedPreviewResolver;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
use Weline\Theme\Service\ThemeLayoutService;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ThemePublishedPreviewReadEfficiencyTest extends TestCase
{
    private const UID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setId('published-preview-read-' . $this->name());
        State::setRequestLanguageOverride('en_US');
        ObjectManager::setInstance(EventsManager::class, $this->getMockBuilder(EventsManager::class)
            ->disableOriginalConstructor()->onlyMethods(['dispatch'])->getMock());
        // 真实规范化器会翻译区域名称；复用现有独占词典测试数据，
        // 让这些载荷来源用例不依赖词典数据库。
        (new \ReflectionMethod(EventDictionary::class, 'setStoredState'))->invoke(null, [
            'locale' => 'en_US', 'active' => true, 'mode' => EventDictionary::MODE_EXCLUSIVE,
            'owner' => 'published-preview-read-test', 'hash' => 'published-preview-read-test',
            'words' => [], 'keyed_words' => [], 'layers' => [],
        ]);
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        Context::leave();
    }

    public function testPublicResourcesUsePublishedSnapshotWithoutLoadingEditorState(): void
    {
        $context = $this->context();
        $reads = [];
        $workspace = $this->createMockForIntersectionOfInterfaces([
            ThemeScopedWorkspaceInterface::class,
            ThemePublishedSnapshotReaderInterface::class,
        ]);
        self::assertInstanceOf(ThemePublishedSnapshotReaderInterface::class, $workspace);
        $workspace->expects(self::never())->method('load');
        $workspace->expects(self::exactly(3))->method('readPublishedSnapshot')
            ->willReturnCallback(function (ThemeEditorContext $readContext) use (&$reads, $context): array {
                self::assertSame($context->scope, $readContext->scope);
                $reads[] = [$readContext->resourceType, $readContext->locale];
                return [
                    'payload' => $this->payload($readContext->resourceType, false),
                    'release_id' => 17,
                    'source_scope' => $context->scope->storageScope,
                ];
            });

        $resolver = $this->resolver($workspace);
        $layout = $resolver->resolveLayout($context, ThemeLayout::STATUS_PUBLISHED);
        $appearance = $resolver->resolveAppearance($context, ThemeLayout::STATUS_PUBLISHED);

        $this->assertResolvedResources($layout, $appearance, false);
        self::assertSame($this->expectedReads(), $reads);
    }

    public function testDraftResourcesKeepEditorReadsAndTheirDraftLocalePayloads(): void
    {
        $context = $this->context();
        $reads = [];
        $workspace = $this->createMockForIntersectionOfInterfaces([
            ThemeScopedWorkspaceInterface::class,
            ThemePublishedSnapshotReaderInterface::class,
        ]);
        $workspace->expects(self::never())->method('readPublishedSnapshot');
        $workspace->expects(self::exactly(3))->method('load')
            ->willReturnCallback(function (ThemeEditorContext $readContext, bool $includeDraft) use (&$reads, $context): array {
                self::assertTrue($includeDraft);
                self::assertSame($context->scope, $readContext->scope);
                $reads[] = [$readContext->resourceType, $readContext->locale];
                return [
                    'draft_payload' => $this->payload($readContext->resourceType, true),
                    'published_payload' => $this->payload($readContext->resourceType, false),
                ];
            });

        $resolver = $this->resolver($workspace);
        $layout = $resolver->resolveLayout($context, ThemeLayout::STATUS_DRAFT);
        $appearance = $resolver->resolveAppearance($context, ThemeLayout::STATUS_DRAFT);

        $this->assertResolvedResources($layout, $appearance, true);
        self::assertSame($this->expectedReads(), $reads);
    }

    public function testLegacyWorkspaceKeepsPublishedLoadAndEquivalentPublicOutput(): void
    {
        $context = $this->context();
        $reads = [];
        $workspace = $this->createMock(ThemeScopedWorkspaceInterface::class);
        self::assertNotInstanceOf(ThemePublishedSnapshotReaderInterface::class, $workspace);
        $workspace->expects(self::exactly(3))->method('load')
            ->willReturnCallback(function (ThemeEditorContext $readContext, bool $includeDraft) use (&$reads, $context): array {
                self::assertFalse($includeDraft);
                self::assertSame($context->scope, $readContext->scope);
                $reads[] = [$readContext->resourceType, $readContext->locale];
                return [
                    'draft_payload' => $this->payload($readContext->resourceType, true),
                    'published_payload' => $this->payload($readContext->resourceType, false),
                ];
            });

        $resolver = $this->resolver($workspace);
        $layout = $resolver->resolveLayout($context, ThemeLayout::STATUS_PUBLISHED);
        $appearance = $resolver->resolveAppearance($context, ThemeLayout::STATUS_PUBLISHED);

        $this->assertResolvedResources($layout, $appearance, false);
        self::assertSame($this->expectedReads(), $reads);
    }

    private function resolver(ThemeScopedWorkspaceInterface $workspace): ThemeScopedPreviewResolver
    {
        $layouts = $this->getMockBuilder(ThemeLayoutService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['decorateLayoutForRender'])
            ->getMock();
        $layouts->expects(self::once())->method('decorateLayoutForRender')
            ->willReturnCallback(static function (array $layout, string $layoutType): array {
                self::assertSame(ThemeLayout::PAGE_TYPE_HOME, $layoutType);
                return $layout;
            });

        return new ThemeScopedPreviewResolver(
            $workspace,
            new ThemeLayoutSnapshotNormalizer(new ThemeNodePlacementResolver()),
            $layouts,
            new ThemeLayoutScopeNormalizer($this->createMock(ScopeHierarchyInterface::class)),
            $this->createMock(ThemeTokenCatalogService::class),
            new CssVariableInjector(),
        );
    }

    private function context(): ThemeEditorContext
    {
        return new ThemeEditorContext(
            scope: new ScopeContext(
                ScopeIdentity::global(),
                'default.default.default',
                ScopeIdentity::MODE_NORMAL,
                ['default.default.default'],
            ),
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: 1,
            layoutType: ThemeLayout::PAGE_TYPE_HOME,
            locale: 'en_US',
            targetType: 'global',
            targetId: 0,
        );
    }

    private function payload(string $resource, bool $draft): array
    {
        $label = $draft ? 'Draft' : 'Published';
        return match ($resource) {
            ThemeEditorContext::RESOURCE_LAYOUT => [
                'theme_id' => 1,
                'selection' => ['layout_option' => 'default'],
                'nodes' => [self::UID => [
                    'node_uid' => self::UID,
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'content',
                    'widget_code' => 'image-text',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'content',
                    'config' => ['title' => $label . ' source', 'action' => ['label' => 'Source action']],
                    'sort_order' => 0,
                    'is_active' => true,
                ]],
            ],
            ThemeEditorContext::RESOURCE_I18N => [
                'translations' => [self::UID => [
                    'title' => $label . ' English title',
                    'action.label' => $label . ' English action',
                ]],
            ],
            ThemeEditorContext::RESOURCE_APPEARANCE => [
                'brand_name' => $label . ' brand',
                'tokens' => ['color.primary' => $draft ? '#112233' : '#445566'],
            ],
            default => throw new \LogicException('Unexpected resource: ' . $resource),
        };
    }

    private function assertResolvedResources(array $layout, array $appearance, bool $draft): void
    {
        self::assertCount(1, $layout[ThemeLayout::AREA_CONTENT]['widgets']);
        $widget = $layout[ThemeLayout::AREA_CONTENT]['widgets'][0];
        $label = $draft ? 'Draft' : 'Published';
        self::assertSame(self::UID, $widget['node_uid']);
        self::assertSame($draft ? ThemeLayout::STATUS_DRAFT : ThemeLayout::STATUS_PUBLISHED, $widget['status']);
        self::assertSame('', $widget['locale_code']);
        self::assertSame('global', $widget['target_type']);
        self::assertSame(0, $widget['target_id']);
        self::assertSame($label . ' English title', $widget['config']['title']);
        self::assertSame($label . ' English action', $widget['config']['action']['label']);
        self::assertTrue($widget['config']['_skip_translation_merge']);
        self::assertSame($this->payload(ThemeEditorContext::RESOURCE_APPEARANCE, $draft), $appearance);
    }

    private function expectedReads(): array
    {
        return [
            [ThemeEditorContext::RESOURCE_LAYOUT, 'default'],
            [ThemeEditorContext::RESOURCE_I18N, 'en_US'],
            [ThemeEditorContext::RESOURCE_APPEARANCE, 'default'],
        ];
    }
}
