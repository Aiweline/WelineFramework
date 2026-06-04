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

    public function testApplyReplacementUpdatesLayoutContentSource(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $scope = [
            'page_types' => ['home_page'],
            'page_type_layouts' => [
                'home_page' => [
                    'content' => [
                        [
                            'block_id' => 'hero',
                            'code' => 'content/hero',
                            'component_code' => 'content/hero',
                            'type' => 'hero',
                            'config' => ['headline' => 'Original headline'],
                            'html' => '<section><h1>Original headline</h1></section>',
                            'field_schema' => [
                                'content' => [
                                    'fields' => [
                                        ['key' => 'headline', 'type' => 'text'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'virtual_pages_by_type' => [
                'home_page' => [
                    'block_nodes' => [],
                ],
            ],
        ];
        $replacement = [
            'block_id' => 'hero',
            'type' => 'hero',
            'component_code' => 'content/hero',
            'config' => ['headline' => 'New headline'],
            'html' => '<section><h1>New headline</h1></section>',
            'field_schema' => [
                'content' => [
                    'fields' => [
                        ['key' => 'headline', 'type' => 'text'],
                    ],
                ],
            ],
        ];

        $result = $service->applyReplacementBlockToScope($scope, 'home_page', 'hero', $replacement);

        self::assertTrue($result['success'], \json_encode($result, \JSON_UNESCAPED_UNICODE));
        self::assertSame('page_type_layouts.content', $result['source']);
        self::assertSame(
            'New headline',
            $result['scope']['page_type_layouts']['home_page']['content'][0]['config']['headline'] ?? null
        );
        self::assertSame(
            '<section><h1>New headline</h1></section>',
            $result['scope']['page_type_layouts']['home_page']['content'][0]['html'] ?? null
        );
        self::assertSame([], $result['scope']['virtual_pages_by_type']['home_page']['block_nodes'] ?? null);
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
        $scope['virtual_pages_by_type']['home_page']['block_nodes'] = $currentBlocks;
        $service = new AiSiteBlockPartialPatchService();

        $result = $service->applyReplacementBlockToScope(
            $scope,
            'home_page',
            'home-page-hero-banner',
            $replacement
        );

        self::assertTrue($result['success']);
        self::assertSame('Patched Materialized Headline', $result['scope']['virtual_pages_by_type']['home_page']['block_nodes'][0]['config']['headline']);
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
        $blocks = $result['scope']['virtual_pages_by_type']['home']['block_nodes'];
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
        $scope['virtual_pages_by_type']['home']['block_nodes'][0]['section_code'] = 'home:content/hero';
        $replacement = $this->block('hero', 'Alias Headline', '<section>Alias Headline</section>', 'content/hero');

        $result = $service->applyReplacementBlockToScope($scope, 'home', 'home:content/hero', $replacement);

        self::assertTrue($result['success']);
        self::assertSame('Alias Headline', $result['scope']['virtual_pages_by_type']['home']['block_nodes'][0]['config']['headline']);
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
        self::assertSame('About headline', $result['scope']['virtual_pages_by_type']['about']['block_nodes'][0]['config']['headline']);
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
        self::assertStringContainsString('Required top-level keys: block, change_summary, changed_fields.', $captured['prompt']);
        self::assertStringContainsString('The first non-whitespace character must be {', $captured['prompt']);
        self::assertStringContainsString('JSON transport self-check', $captured['prompt']);
        self::assertStringContainsString('Template safety', $captured['prompt']);
        self::assertStringContainsString('Never copy, rewrite, invent, or emit PHP/PHTML code', $captured['prompt']);
        self::assertStringContainsString('Do not return _pb_server_template_phtml unless', $captured['prompt']);
        self::assertStringContainsString('Do not output raw HTML, CSS, PHTML, Markdown fences, comments, or prose outside JSON.', $captured['prompt']);
        self::assertStringContainsString('Do not include reason, why, or decision_reason fields', $captured['prompt']);
        self::assertStringNotContainsString('keys: block, change_summary, changed_fields, reason', $captured['prompt']);
        self::assertSame('Conversion headline', $result['block']['config']['headline'] ?? null);
    }

    public function testGenerateReplacementUsesDeterministicFakeModeWithoutAiCall(): void
    {
        $scope = $this->scope();
        $scope['fake_mode'] = '1';
        $read = (new AiSiteBlockPartialPatchService())->readCurrentBlockFromScope($scope, 'home', 'hero');

        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::never())->method('generateStream');

        $events = [];
        $service = new AiSiteBlockPartialPatchService(aiService: $aiService);
        $result = $service->generateReplacementBlock(
            $read,
            $scope,
            'Make the headline stronger.',
            static function (string $event, array $payload) use (&$events): void {
                $events[] = [$event, $payload];
            }
        );

        self::assertSame('Headline - refined', $result['block']['config']['headline'] ?? null);
        self::assertStringContainsString('Headline - refined', (string)($result['block']['html'] ?? ''));
        self::assertSame(['config.headline', 'html'], $result['changed_fields']);
        self::assertSame('fake_patch', $events[0][0] ?? null);
    }

    public function testPatchPromptOmitsServerTemplateBodySoAiDoesNotRewritePhtml(): void
    {
        $scope = $this->scope();
        $read = (new AiSiteBlockPartialPatchService())->readCurrentBlockFromScope($scope, 'home', 'hero');
        $read['block']['_pb_server_template_phtml'] = '<?php foreach ($items as $item): ?><section><?= $item ?></section><?php endforeach; ?>';

        $service = new AiSiteBlockPartialPatchService();
        $prompt = (function (array $read, array $scope, string $instruction): string {
            return $this->buildPatchPrompt($read, $scope, $instruction);
        })->call($service, $read, $scope, 'Tighten the heading spacing.');

        self::assertStringNotContainsString('<?php foreach', $prompt);
        self::assertStringNotContainsString('<?= $item ?>', $prompt);
        self::assertStringContainsString('_pb_server_template_phtml_preserved_by_backend', $prompt);
    }

    public function testGenerateReplacementRepairsMalformedJsonPatchResponse(): void
    {
        $scope = $this->scope();
        $read = (new AiSiteBlockPartialPatchService())->readCurrentBlockFromScope($scope, 'home', 'hero');
        $replacement = $this->block('hero', 'Repaired headline', '<section>Repaired headline</section>', 'content/hero');
        $blockJson = \json_encode($replacement, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $repairedResponse = '{"block":' . $blockJson
            . ',"change_summary":"Repaired invalid JSON transport."'
            . ',"changed_fields":[config.content.section_intro]}';

        $calls = 0;
        $prompts = [];
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::exactly(2))
            ->method('generateStream')
            ->willReturnCallback(static function (
                string $prompt,
                callable $callback,
                ?string $modelCode = null,
                ?string $scenarioCode = null,
                ?string $locale = null,
                array $params = []
            ) use (&$calls, &$prompts, $repairedResponse): void {
                $calls++;
                $prompts[] = $prompt;
                if ($calls === 1) {
                    $callback('{"block":{"block_id":"hero","html":"<section class="broken">Broken</section>"}}');
                    return;
                }
                $callback((string)$repairedResponse);
            });

        $events = [];
        $service = new AiSiteBlockPartialPatchService(aiService: $aiService);
        $result = $service->generateReplacementBlock(
            $read,
            $scope,
            'Make the headline stronger.',
            static function (string $event, array $payload) use (&$events): void {
                $events[] = $event;
            }
        );

        self::assertSame('Repaired headline', $result['block']['config']['headline'] ?? null);
        self::assertSame(['config.content.section_intro'], $result['changed_fields']);
        self::assertContains('json_repair', $events);
        self::assertStringContainsString('malformed PageBuilder block_partial_patch JSON', $prompts[1] ?? '');
        self::assertStringContainsString('static balanced HTML fragments', $prompts[1] ?? '');
    }

    public function testGenerateReplacementNormalizesRawTemplatePatchResponse(): void
    {
        $scope = $this->scope();
        $read = (new AiSiteBlockPartialPatchService())->readCurrentBlockFromScope($scope, 'home', 'hero');
        $rawTemplate = '<section class="hero-patched">Raw template patch</section>';

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
            ) use ($rawTemplate): void {
                $callback($rawTemplate);
            });

        $service = new AiSiteBlockPartialPatchService(aiService: $aiService);
        $result = $service->generateReplacementBlock($read, $scope, 'Make the hero stronger.');

        self::assertSame($rawTemplate, $result['block']['html'] ?? null);
        self::assertSame(['html'], $result['changed_fields']);
        self::assertSame(
            $this->block('hero', 'Headline', '<section>Headline</section>', 'content/hero')['field_schema'],
            $result['block']['field_schema'] ?? null
        );
        self::assertSame('', $result['reason']);
    }

    public function testGenerateReplacementPromptCarriesLanguageAndCurrentBlockContract(): void
    {
        $scope = $this->scopeWithPlanJsonTaskLanguageAndContract();
        $read = (new AiSiteBlockPartialPatchService())->readCurrentBlockFromScope($scope, 'home', 'hero');
        $replacement = $this->block(
            'hero',
            'Reservierung starten',
            '<section class="pb-c-root"><h2>Reservierung starten</h2></section>',
            'content/hero'
        );
        $response = \json_encode([
            'block' => $replacement,
            'change_summary' => 'Updated the localized block headline.',
            'changed_fields' => ['config.headline', 'html'],
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        self::assertIsString($response);

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
                    'locale' => $locale,
                    'params' => $params,
                ];
                $callback($response);
            });

        $service = new AiSiteBlockPartialPatchService(aiService: $aiService);
        $result = $service->generateReplacementBlock($read, $scope, 'Make the headline more direct.');

        self::assertSame('Reservierung starten', $result['block']['config']['headline'] ?? null);
        self::assertSame('de_DE', $captured['locale'] ?? null);
        self::assertStringContainsString('CTX_WEBSITE_LANGUAGE', $captured['prompt'] ?? '');
        self::assertStringContainsString('source_of_truth_locale', $captured['prompt'] ?? '');
        self::assertStringContainsString('de_DE', $captured['prompt'] ?? '');
        self::assertStringContainsString('CTX_CURRENT_BLOCK_CONTEXT', $captured['prompt'] ?? '');
        self::assertStringContainsString('current_block_context', $captured['prompt'] ?? '');
        self::assertStringContainsString('block_context_source', $captured['prompt'] ?? '');
        self::assertStringContainsString('confirmed_plan_json_task', $captured['prompt'] ?? '');
        self::assertStringContainsString('editorial_split_media', $captured['prompt'] ?? '');
        self::assertStringContainsString('Patch block-context execution rule', $captured['prompt'] ?? '');
        self::assertStringContainsString('Patch anti-repetition rule', $captured['prompt'] ?? '');
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

    public function testRejectsReplacementWithBrowserDefaultLink(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $replacement = $this->block(
            'hero',
            'New Headline',
            '<section><h2>New Headline</h2><a href="/download">Download APK</a></section>'
        );

        $result = $service->applyReplacementBlockToScope($this->scope(), 'home', 'hero', $replacement);

        self::assertFalse($result['success']);
        self::assertSame('BLOCK_VALIDATION_FAILED', $result['code']);
        self::assertContains('replacement.html contains an unstyled browser-default link', $result['details']['errors']);
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

    public function testReplacementMissingFieldSchemaInheritsCurrentSchema(): void
    {
        $service = new AiSiteBlockPartialPatchService();
        $replacement = $this->block('hero', 'New Headline', '<section>New Headline</section>');
        unset($replacement['field_schema']);

        $result = $service->applyReplacementBlockToScope($this->scope(), 'home', 'hero', $replacement);

        self::assertTrue($result['success']);
        self::assertSame(
            $this->block('hero', 'Headline', '<section>Headline</section>', 'content/hero')['field_schema'],
            $result['after_block']['field_schema']
        );
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
        $replacement['_pb_server_template_phtml'] = '<?php throw new \RuntimeException("render failed"); ?>';

        $bufferLevel = \ob_get_level();
        \ob_start();
        try {
            $result = $service->applyReplacementBlockToScope($this->scope(), 'home', 'hero', $replacement);
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
        }

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
                    'block_nodes' => [
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
            'block_nodes' => [
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
                    'block_nodes' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeWithPlanJsonTaskLanguageAndContract(): array
    {
        $scope = $this->scope();
        $scope['plan_locale'] = 'en_US';
        $scope['website_profile'] = [
            'default_locale' => 'de_DE',
            'content_locale' => 'de_DE',
        ];
        $blockContract = [
            'morphology_id' => 'editorial_split_media',
            'block_goal' => 'Convert visitors to restaurant reservations.',
            'media_strategy' => [
                'needs_real_image' => true,
                'asset_slot_id' => 'page:home:hero',
            ],
            'diversity_constraints' => [
                'must_differ_from_previous_block' => ['morphology_id', 'media_placement'],
            ],
        ];
        $scope['plan_json'] = [
            'pages' => [
                'home' => [
                    'hero' => [
                        'block_key' => 'hero',
                        'component_code' => 'content/hero',
                        'block_contract' => $blockContract,
                        'image_intent' => [
                            'needs_image' => true,
                            'asset_slot_id' => 'page:home:hero',
                        ],
                    ],
                ],
            ],
        ];
        return $scope;
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
                    'block_nodes' => [
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
