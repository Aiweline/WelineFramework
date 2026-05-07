<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteBlockPartialPatchService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;

final class AiSiteBlockPartialPatchServiceTest extends TestCase
{
    public function testReadCurrentBlockByPageTypeAndBlockId(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $result = $service->readCurrentBlockFromScope($this->scope(), 'home', 'hero');

        self::assertTrue($result['success']);
        self::assertSame('hero', $result['block_id']);
        self::assertSame('content/hero', $result['component_code']);
        self::assertSame('Headline', $result['config']['headline']);
    }

    public function testReadCurrentBlockReturnsStableErrorWhenMissing(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $result = $service->readCurrentBlockFromScope($this->scope(), 'home', 'missing');

        self::assertFalse($result['success']);
        self::assertSame('BLOCK_NOT_FOUND', $result['code']);
        self::assertSame(['hero', 'features'], $result['details']['available_block_ids']);
    }

    public function testReadCurrentBlockHydratesOnlyTargetPage(): void
    {
        $scopeService = new class extends AiSiteScopeCompatibilityService {
            /** @var list<list<string>> */
            public array $requestedPageTypes = [];

            public function __construct()
            {
            }

            public function buildVirtualPagesByType(
                array $pageTypes,
                array $scope = [],
                bool $allowAiPlaceholderGeneration = true
            ): array {
                $this->requestedPageTypes[] = \array_values($pageTypes);
                $pages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];

                return \array_intersect_key($pages, \array_flip($pageTypes));
            }
        };
        $service = new AiSiteBlockPartialPatchService(scopeCompatibilityService: $scopeService);
        $scope = $this->scopeWithMultiplePages();

        $result = $service->readCurrentBlockFromScope($scope, 'home', 'hero');

        self::assertTrue($result['success']);
        self::assertSame([['home']], $scopeService->requestedPageTypes);
    }

    public function testReadCurrentBlockAcceptsComponentCodeAfterScopeNormalization(): void
    {
        $scopeService = new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer());
        $service = new AiSiteBlockPartialPatchService(scopeCompatibilityService: $scopeService);
        $scope = $this->scope();

        $result = $service->readCurrentBlockFromScope($scope, 'home', 'content/hero');

        self::assertTrue($result['success']);
        self::assertSame('hero', $result['block_id']);
        self::assertSame('content/hero', $result['component_code']);
    }

    public function testReadCurrentBlockFallsBackToMaterializedPageAiLayout(): void
    {
        $page = $this->createMaterializedPageMock(444, 1, [
            $this->block('home-page-hero-banner', 'Materialized Headline', '<section>Materialized</section>', 'content/home-page-hero-banner'),
        ]);
        $service = new AiSiteBlockPartialPatchService(pageModel: $page);

        $result = $service->readCurrentBlockFromScope($this->materializedScope(444), 'home_page', 'home-page-hero-banner');

        self::assertTrue($result['success']);
        self::assertSame('page.ai_layout', $result['source']);
        self::assertSame('home-page-hero-banner', $result['block_id']);
        self::assertSame('content/home-page-hero-banner', $result['component_code']);
    }

    public function testReplaceCurrentBlockPersistsMaterializedPageAiLayout(): void
    {
        $currentBlocks = [
            $this->block('home-page-hero-banner', 'Materialized Headline', '<section>Materialized</section>', 'content/home-page-hero-banner'),
            $this->block('home-page-proof', 'Proof', '<section>Proof</section>', 'content/home-page-proof'),
        ];
        $replacement = $this->block(
            'home-page-hero-banner',
            'Patched Materialized Headline',
            '<section>Patched Materialized</section>',
            'content/home-page-hero-banner'
        );
        $page = $this->createMaterializedPageMock(555, 3, $currentBlocks);
        $page->expects(self::once())
            ->method('setAiLayoutArray')
            ->with(self::callback(static function (array $layout): bool {
                $blocks = \is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];

                return \count($blocks) === 2
                    && ($blocks[0]['config']['headline'] ?? '') === 'Patched Materialized Headline'
                    && ($blocks[1]['block_id'] ?? '') === 'home-page-proof'
                    && \trim((string)($layout['updated_at'] ?? '')) !== '';
            }))
            ->willReturnSelf();
        $page->expects(self::once())->method('save')->willReturn(true);
        $service = new AiSiteBlockPartialPatchService(pageModel: $page);

        $result = $service->applyReplacementBlockToScope(
            $this->materializedScope(555),
            'home_page',
            'home-page-hero-banner',
            $replacement
        );

        self::assertTrue($result['success']);
        self::assertSame('Patched Materialized Headline', $result['scope']['virtual_pages_by_type']['home_page']['blocks'][0]['config']['headline']);
        self::assertSame('Patched Materialized Headline', $result['scope']['pagebuilder_pages_by_type']['home_page']['ai_layout']['blocks'][0]['config']['headline']);
    }

    public function testReplaceCurrentBlockOnlyChangesTargetAndKeepsHistory(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $scope = $this->scope();
        $replacement = $this->block('hero', 'New Headline', '<section>New Headline</section>');

        $result = $service->applyReplacementBlockToScope($scope, 'home', 'hero', $replacement, [
            'change_summary' => 'Updated headline.',
            'changed_fields' => ['headline'],
            'execution_token' => 'tok',
        ]);

        self::assertTrue($result['success']);
        $blocks = $result['scope']['virtual_pages_by_type']['home']['blocks'];
        self::assertCount(2, $blocks);
        self::assertSame(['hero', 'features'], \array_column($blocks, 'block_id'));
        self::assertSame('New Headline', $blocks[0]['config']['headline']);
        self::assertSame('Features', $blocks[1]['config']['headline']);
        self::assertSame('Updated headline.', $result['scope']['block_patch_history']['home']['hero'][0]['change_summary']);
    }

    public function testReplaceCurrentBlockAcceptsSectionCodeAlias(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $scope = $this->scope();
        $scope['virtual_pages_by_type']['home']['blocks'][0]['section_code'] = 'home:content/hero';
        $replacement = $this->block('hero', 'Alias Headline', '<section>Alias Headline</section>', 'content/hero');

        $result = $service->applyReplacementBlockToScope($scope, 'home', 'home:content/hero', $replacement);

        self::assertTrue($result['success']);
        self::assertSame('Alias Headline', $result['scope']['virtual_pages_by_type']['home']['blocks'][0]['config']['headline']);
    }

    public function testReplaceCurrentBlockPreservesOtherPagesWithoutHydratingThem(): void
    {
        $scopeService = new class extends AiSiteScopeCompatibilityService {
            /** @var list<list<string>> */
            public array $requestedPageTypes = [];

            public function __construct()
            {
            }

            public function buildVirtualPagesByType(
                array $pageTypes,
                array $scope = [],
                bool $allowAiPlaceholderGeneration = true
            ): array {
                $this->requestedPageTypes[] = \array_values($pageTypes);
                $pages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];

                return \array_intersect_key($pages, \array_flip($pageTypes));
            }
        };
        $service = new AiSiteBlockPartialPatchService(scopeCompatibilityService: $scopeService);
        $scope = $this->scopeWithMultiplePages();
        $replacement = $this->block('hero', 'New Headline', '<section>New Headline</section>');

        $result = $service->applyReplacementBlockToScope($scope, 'home', 'hero', $replacement);

        self::assertTrue($result['success']);
        self::assertSame([['home'], ['home']], $scopeService->requestedPageTypes);
        self::assertSame('About headline', $result['scope']['virtual_pages_by_type']['about']['blocks'][0]['config']['headline']);
    }

    public function testReadCurrentBlockCompactsPageContextForPrompt(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $scope = $this->scope();
        $scope['website_profile'] = [
            'business_name' => 'Patch Test',
            'long_description' => \str_repeat('x', 1000),
            'chinese_description' => \str_repeat('转化', 400),
            'virtual_pages_by_type' => ['should_not' => 'leak'],
        ];

        $result = $service->readCurrentBlockFromScope($scope, 'home', 'hero');

        self::assertTrue($result['success']);
        $profile = $result['page_context']['website_profile'] ?? [];
        self::assertIsArray($profile);
        self::assertSame('Patch Test', $profile['business_name'] ?? '');
        self::assertLessThanOrEqual(603, \strlen((string)($profile['long_description'] ?? '')));
        self::assertArrayNotHasKey('virtual_pages_by_type', $profile);
        self::assertNotFalse(\json_encode($result['page_context'], \JSON_UNESCAPED_UNICODE));
    }

    public function testGenerateReplacementUsesLightweightJsonPatchParams(): void
    {
        $scope = $this->scope();
        $read = (new AiSiteBlockPartialPatchService())->readCurrentBlockFromScope($scope, 'home', 'hero');
        $replacement = $this->block('hero', 'Conversion headline', '<section>Conversion headline</section>', 'content/hero');
        $response = \json_encode([
            'block' => $replacement,
            'change_summary' => 'Updated headline.',
            'changed_fields' => ['content.headline'],
            'reason' => 'Matches instruction.',
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        $captured = [];
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willReturnCallback(static function (
                string $prompt,
                callable $callback,
                ?string $modelCode = null,
                ?string $scenarioCode = null,
                ?string $locale = null,
                array $params = []
            ) use (&$captured, $response): void {
                $captured = [
                    'prompt' => $prompt,
                    'model_code' => $modelCode,
                    'scenario_code' => $scenarioCode,
                    'locale' => $locale,
                    'params' => $params,
                ];
                $callback((string)$response);
            });

        $service = new AiSiteBlockPartialPatchService(aiService: $aiService);
        $result = $service->generateReplacementBlock($read, $scope, 'Make the headline stronger.');

        self::assertSame('pagebuilder_component_generation', $captured['scenario_code']);
        self::assertSame(['type' => 'json_object'], $captured['params']['response_format'] ?? null);
        self::assertTrue($captured['params']['partial_patch_mode'] ?? false);
        self::assertTrue($captured['params']['disable_conversation_history'] ?? false);
        self::assertTrue($captured['params']['disable_conversation_persist'] ?? false);
        self::assertStringContainsString('Return JSON only', $captured['prompt']);
        self::assertSame('Conversion headline', $result['block']['config']['headline'] ?? null);
    }

    public function testRejectsReplacementWithoutHtml(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $replacement = $this->block('hero', 'New Headline', '<section>New Headline</section>');
        unset($replacement['html']);

        $result = $service->applyReplacementBlockToScope($this->scope(), 'home', 'hero', $replacement);

        self::assertFalse($result['success']);
        self::assertSame('BLOCK_VALIDATION_FAILED', $result['code']);
        self::assertContains('replacement.html must be a non-empty string', $result['details']['errors']);
    }

    public function testRejectsChangedBlockId(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $replacement = $this->block('other', 'New Headline', '<section>New Headline</section>');

        $result = $service->applyReplacementBlockToScope($this->scope(), 'home', 'hero', $replacement);

        self::assertFalse($result['success']);
        self::assertSame('BLOCK_VALIDATION_FAILED', $result['code']);
        self::assertContains('replacement.block_id must match current block_id', $result['details']['errors']);
    }

    public function testRejectsInvalidFieldSchema(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $replacement = $this->block('hero', 'New Headline', '<section>New Headline</section>');
        $replacement['field_schema'] = ['content' => ['label' => 'Content', 'fields' => 'invalid']];

        $result = $service->applyReplacementBlockToScope($this->scope(), 'home', 'hero', $replacement);

        self::assertFalse($result['success']);
        self::assertSame('BLOCK_VALIDATION_FAILED', $result['code']);
        self::assertContains('replacement.field_schema has an invalid shape', $result['details']['errors']);
    }

    public function testAcceptsListStyleFieldSchema(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $replacement = $this->block('hero', 'New Headline', '<section>New Headline</section>');
        $replacement['field_schema'] = [
            'content' => [
                'label' => 'Content',
                'fields' => [
                    ['key' => 'headline', 'type' => 'text', 'label' => 'Headline'],
                ],
            ],
        ];

        $result = $service->applyReplacementBlockToScope($this->scope(), 'home', 'hero', $replacement);

        self::assertTrue($result['success']);
    }

    public function testRejectsUnrenderablePhtml(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $replacement = $this->block('hero', 'New Headline', '<section>New Headline</section>');
        $replacement['_pb_server_template_phtml'] = '<?php if (';

        $result = $service->applyReplacementBlockToScope($this->scope(), 'home', 'hero', $replacement);

        self::assertFalse($result['success']);
        self::assertSame('BLOCK_RENDER_FAILED', $result['code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function scope(): array
    {
        return [
            'page_types' => ['home'],
            'virtual_pages_by_type' => [
                'home' => [
                    'title' => 'Home',
                    'blocks' => [
                        $this->block('hero', 'Headline', '<section>Headline</section>', 'content/hero'),
                        $this->block('features', 'Features', '<section>Features</section>', 'content/features'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeWithMultiplePages(): array
    {
        $scope = $this->scope();
        $scope['page_types'] = ['home', 'about'];
        $scope['virtual_pages_by_type']['about'] = [
            'title' => 'About',
            'blocks' => [
                $this->block('about-hero', 'About headline', '<section>About headline</section>', 'content/about-hero'),
            ],
        ];

        return $scope;
    }

    /**
     * @return array<string, mixed>
     */
    private function materializedScope(int $pageId): array
    {
        return [
            'page_types' => ['home_page'],
            'pagebuilder_pages_by_type' => [
                'home_page' => [
                    'page_id' => $pageId,
                    'type' => 'home_page',
                    'title' => 'Home',
                    'handle' => '',
                ],
            ],
            'virtual_pages_by_type' => [
                'home_page' => [
                    'title' => 'Home',
                    'materialized_page_id' => $pageId,
                    'blocks' => [],
                ],
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     */
    private function createMaterializedPageMock(int $pageId, int $loadCount, array $blocks): Page
    {
        $page = $this->getMockBuilder(Page::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearData', 'load', 'getId', 'resolveAiLayoutForFrontend', 'setAiLayoutArray', 'save'])
            ->addMethods(['clearQuery'])
            ->getMock();
        $page->method('clearData')->willReturnSelf();
        $page->method('clearQuery')->willReturnSelf();
        $page->expects(self::exactly($loadCount))->method('load')->with($pageId)->willReturnSelf();
        $page->method('getId')->willReturn($pageId);
        $page->expects(self::exactly($loadCount))
            ->method('resolveAiLayoutForFrontend')
            ->with(true)
            ->willReturn(['blocks' => $blocks]);

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function block(string $blockId, string $headline, string $html, string $componentCode = ''): array
    {
        return [
            'block_id' => $blockId,
            'type' => 'ai_generated_section',
            'html' => $html,
            'config' => [
                'headline' => $headline,
                '_pb_server_component_code' => $componentCode,
            ],
            'field_schema' => [
                'content' => [
                    'label' => 'Content',
                    'fields' => [
                        'headline' => ['type' => 'text', 'label' => 'Headline'],
                    ],
                ],
            ],
            '_pb_server_component_code' => $componentCode,
        ];
    }
}
