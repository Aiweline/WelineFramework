<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteBlockPartialPatchService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
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

    public function testReadCurrentBlockDoesNotFallbackToMaterializedPageAiLayout(): void
    {
        $service = new AiSiteBlockPartialPatchService();

        $result = $service->readCurrentBlockFromScope($this->materializedScope(444), 'home_page', 'home-page-hero-banner');

        self::assertFalse($result['success']);
        self::assertSame('BLOCK_NOT_FOUND', $result['code']);
        self::assertSame([], $result['details']['available_block_ids']);
    }

    public function testReadCurrentSharedHeaderComponentFromScope(): void
    {
        $service = new AiSiteBlockPartialPatchService();

        $result = $service->readCurrentBlockFromScope($this->sharedScope(), 'home_page', 'header/ai-site-header');

        self::assertTrue($result['success']);
        self::assertSame('shared_components.header', $result['source']);
        self::assertSame('header/ai-site-header', $result['block_id']);
        self::assertSame('header/ai-site-header', $result['component_code']);
        self::assertSame('Generated header', $result['config']['title'] ?? null);
        self::assertSame('<header class="hero-header">Header</header>', $result['html']);
    }

    public function testReplaceCurrentBlockOnlyUpdatesVirtualThemeEvenWhenMaterializedPageExists(): void
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
        $scope = $this->materializedScope(555);
        $scope['virtual_pages_by_type']['home_page']['blocks'] = $currentBlocks;
        $service = new AiSiteBlockPartialPatchService();

        $result = $service->applyReplacementBlockToScope(
            $scope,
            'home_page',
            'home-page-hero-banner',
            $replacement
        );

        self::assertTrue($result['success']);
        self::assertSame('Patched Materialized Headline', $result['scope']['virtual_pages_by_type']['home_page']['blocks'][0]['config']['headline']);
        self::assertArrayNotHasKey('ai_layout', $result['scope']['pagebuilder_pages_by_type']['home_page']);
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

    public function testReplaceCurrentSharedHeaderPersistsToScopeAndVirtualThemeStorage(): void
    {
        $virtualThemeService = $this->getMockBuilder(AiSiteVirtualThemeService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['saveGeneratedSharedComponent'])
            ->getMock();
        $virtualThemeService->expects(self::once())
            ->method('saveGeneratedSharedComponent')
            ->with(
                88,
                self::callback(static function (array $component): bool {
                    return ($component['region'] ?? '') === 'header'
                        && ($component['code'] ?? '') === 'header/ai-site-header'
                        && ($component['default_config']['title'] ?? '') === 'Refined header'
                        && ($component['phtml'] ?? '') === '<header class="hero-header">Refined</header>';
                })
            );
        $service = new AiSiteBlockPartialPatchService(virtualThemeService: $virtualThemeService);
        $replacement = [
            'block_id' => 'header/ai-site-header',
            'type' => 'ai_generated_shared_header',
            'html' => '<header class="hero-header">Refined</header>',
            'config' => [
                'title' => 'Refined header',
                '_pb_server_component_code' => 'header/ai-site-header',
            ],
            'field_schema' => [],
            '_pb_server_component_code' => 'header/ai-site-header',
            '_pb_server_region' => 'header',
            '_pb_server_template_phtml' => '<header class="hero-header">Refined</header>',
        ];

        $result = $service->applyReplacementBlockToScope(
            $this->sharedScope(),
            'home_page',
            'header/ai-site-header',
            $replacement,
            ['change_summary' => 'Refined shared header.']
        );

        self::assertTrue($result['success']);
        self::assertSame('Refined header', $result['scope']['shared_components']['header']['default_config']['title']);
        self::assertSame('<header class="hero-header">Refined</header>', $result['scope']['shared_components']['header']['phtml']);
        self::assertSame('Refined shared header.', $result['scope']['block_patch_history']['home_page']['header/ai-site-header'][0]['change_summary']);
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
     * @return array<string, mixed>
     */
    private function sharedScope(): array
    {
        return [
            'virtual_theme_id' => 88,
            'page_types' => ['home_page'],
            'virtual_pages_by_type' => [
                'home_page' => [
                    'title' => 'Home',
                    'blocks' => [
                        $this->block('home-page-hero', 'Headline', '<section>Headline</section>', 'content/home-page-hero'),
                    ],
                ],
            ],
            'shared_components' => [
                'header' => [
                    'code' => 'header/ai-site-header',
                    'name' => 'AI Site Header',
                    'region' => 'header',
                    'phtml' => '<header class="hero-header">Header</header>',
                    'html' => '<header class="hero-header">Header</header>',
                    'default_config' => [
                        'title' => 'Generated header',
                        '_pb_server_component_code' => 'header/ai-site-header',
                    ],
                ],
                'footer' => [
                    'code' => 'footer/ai-site-footer',
                    'name' => 'AI Site Footer',
                    'region' => 'footer',
                    'phtml' => '<footer class="site-footer">Footer</footer>',
                    'html' => '<footer class="site-footer">Footer</footer>',
                    'default_config' => [
                        'title' => 'Generated footer',
                        '_pb_server_component_code' => 'footer/ai-site-footer',
                    ],
                ],
            ],
        ];
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
