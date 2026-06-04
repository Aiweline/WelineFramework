<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSiteQualityGateService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use PHPUnit\Framework\TestCase;

final class AiSiteQualityGateServiceTest extends TestCase
{
    public function testInspectScopePassesWithCompletedPlanJsonRenderedPageAndSharedBlockNodes(): void
    {
        $service = $this->createService();
        $report = $service->inspectScope($this->buildScope(), [
            'home_page' => $this->pageHtml('<section><h1>Neon chess room</h1><p>Fast tables, sharp cards, dark glow.</p></section>'),
        ]);

        self::assertTrue($report['passed'], \json_encode($report, \JSON_UNESCAPED_UNICODE));
        self::assertSame(
            ['plan_json_block_nodes_done', 'required_pages_render', 'shared_block_nodes_ready', 'responsive_support', 'render_data_quality'],
            \array_map(static fn(array $item): string => (string)$item['key'], $report['items'])
        );
    }

    public function testInspectScopeBlocksWhenPlanJsonBlockIsStillPending(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['plan_json']['pages']['home_page']['hero_banner']['status'] = 0;

        $report = $service->inspectScope($scope, [
            'home_page' => $this->pageHtml('<section><h1>Pending section</h1></section>'),
        ]);

        self::assertFalse($report['passed']);
        self::assertFalse((bool)($this->findItem($report['items'], 'plan_json_block_nodes_done')['ok'] ?? true));
    }

    public function testInspectScopeBlocksWhenRequiredPageDoesNotRender(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        unset($scope['pagebuilder_pages_by_type'], $scope['virtual_pages_by_type']);

        $report = $service->inspectScope($scope);

        self::assertFalse($report['passed']);
        self::assertFalse((bool)($this->findItem($report['items'], 'required_pages_render')['ok'] ?? true));
    }

    public function testInspectScopeBlocksWhenSharedFooterIsMissing(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['virtual_pages_by_type']['home_page']['block_nodes'] = [
            ['block_id' => 'header-ai-site-header', 'type' => 'ai_generated_shared_header'],
            ['block_id' => 'home-page-hero-banner', 'section_code' => 'content/home-page-hero-banner', 'code' => 'content/home-page-hero-banner', 'type' => 'ai_generated_section'],
        ];
        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Header</header><main><section><h1>No footer</h1></section></main>',
        ]);

        self::assertFalse($report['passed']);
        self::assertFalse((bool)($this->findItem($report['items'], 'shared_block_nodes_ready')['ok'] ?? true));
    }

    public function testInspectScopeBlocksWhenResponsiveBreakpointsAreMissing(): void
    {
        $service = $this->createService();
        $report = $service->inspectScope($this->buildScope(), [
            'home_page' => '<header data-region="header">Neon Chess Casino</header><main><section><h1>No responsive CSS</h1></section></main><footer data-region="footer">Support</footer>',
        ]);

        self::assertFalse($report['passed']);
        self::assertFalse((bool)($this->findItem($report['items'], 'responsive_support')['ok'] ?? true));
    }


    public function testInspectScopeBlocksMalformedGeneratedHtmlOnlyAsStructureError(): void
    {
        $service = $this->createService();
        $report = $service->inspectScope($this->buildScope(), [
            'home_page' => $this->pageHtml('<section>< class="broken">Bad tag</class></section>'),
        ]);

        $item = $this->findItem($report['items'], 'render_data_quality');
        self::assertFalse($report['passed']);
        self::assertFalse((bool)($item['ok'] ?? true));
        self::assertStringContainsString('malformed', (string)($item['detail'] ?? ''));
    }

    public function testInspectScopeBlocksStructuralQaFinding(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['qa_report_contract'] = [
            'payload' => [
                'structure_quality' => [
                    'status' => 'failed',
                    'findings' => [[
                        'severity' => 'error',
                        'category' => 'structure',
                        'rule' => 'design.missing_section_identity',
                        'message' => 'Missing section code.',
                        'target_path' => 'payload.page_type_layouts.home_page.content.0',
                    ]],
                ],
            ],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => $this->pageHtml('<section><h1>Structured</h1></section>'),
        ]);

        self::assertFalse($report['passed']);
        self::assertFalse((bool)($this->findItem($report['items'], 'render_data_quality')['ok'] ?? true));
    }

    public function testInspectScopeDoesNotBlockCreativeImageLanguageOrSeoFindings(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['qa_report_contract'] = [
            'payload' => [
                'structure_quality' => [
                    'status' => 'failed',
                    'findings' => [
                        [
                            'severity' => 'error',
                            'category' => 'copy',
                            'rule' => 'copy.placeholder_text',
                            'message' => 'Creative placeholder copy is a model-quality signal, not a hard gate.',
                        ],
                        [
                            'severity' => 'error',
                            'category' => 'asset_manifest',
                            'rule' => 'image.missing_real_asset',
                            'message' => 'Image can be retried after the page structure is generated.',
                        ],
                        [
                            'severity' => 'error',
                            'category' => 'seo',
                            'rule' => 'seo.meta_description_missing',
                            'message' => 'SEO is not part of the hard structure gate.',
                        ],
                    ],
                ],
            ],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => $this->pageHtml('<section><h1>Neon casino chess</h1><p>AI has room to create.</p></section>'),
        ]);

        self::assertTrue($report['passed'], \json_encode($report, \JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool)($this->findItem($report['items'], 'render_data_quality')['ok'] ?? false));
    }

    public function testInspectBuildReadinessGateReturnsTheSameStructureOnlyItems(): void
    {
        $service = $this->createService();
        $report = $service->inspectBuildReadinessGate($this->buildScope(), [
            'home_page' => $this->pageHtml('<section><h1>One source of truth</h1></section>'),
        ]);

        self::assertTrue($report['passed'], \json_encode($report, \JSON_UNESCAPED_UNICODE));
        self::assertSame(
            ['plan_json_block_nodes_done', 'required_pages_render', 'shared_block_nodes_ready', 'responsive_support', 'render_data_quality'],
            \array_map(static fn(array $item): string => (string)$item['key'], $report['items'])
        );
    }

    private function createService(): AiSiteQualityGateService
    {
        return new AiSiteQualityGateService(
            planJsonTaskService: new AiSitePlanJsonTaskService(new AiSitePageBlueprintService()),
            scopeCompatibilityService: new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScope(): array
    {
        return [
            'page_types' => ['home_page'],
            'pagebuilder_pages_by_type' => [
                'home_page' => ['page_id' => 0],
            ],
            'page_type_layouts' => [
                'home_page' => [
                    'content' => [
                        [
                            'component' => 'content/home-page-hero-banner',
                            'code' => 'content/home-page-hero-banner',
                            'block_id' => 'home-page-hero-banner',
                            'type' => 'ai_generated_section',
                            'html' => '<section class="pb-c-root"><h2>Neon chess room</h2></section>',
                        ],
                    ],
                ],
            ],
            'virtual_pages_by_type' => [
                'home_page' => [
                    'title' => 'Neon Chess Casino',
                    'handle' => 'home',
                    'style_code' => 'neon_chess_casino_dark',
                    'block_nodes' => [
                        ['block_id' => 'header-ai-site-header', 'type' => 'ai_generated_shared_header'],
                        ['block_id' => 'home-page-hero-banner', 'section_code' => 'content/home-page-hero-banner', 'code' => 'content/home-page-hero-banner', 'type' => 'ai_generated_section', 'html' => '<section class="pb-c-root"><h2>Neon chess room</h2></section>'],
                        ['block_id' => 'footer-ai-site-footer', 'type' => 'ai_generated_shared_footer'],
                    ],
                ],
            ],
            'shared_components' => [
                'header' => [
                    'code' => 'header/ai-site-header',
                    'html' => '<header data-region="header">Neon Chess Casino</header>',
                ],
                'footer' => [
                    'code' => 'footer/ai-site-footer',
                    'html' => '<footer data-region="footer">Support</footer>',
                ],
            ],
            'plan_json' => [
                'confirmed' => 1,
                'pages' => [
                    'home_page' => [
                        'status' => 1,
                        'hero_banner' => [
                            'status' => 1,
                            'section_code' => 'content/home-page-hero-banner',
                            'html' => '<section class="pb-c-root"><h2>Neon chess room</h2></section>',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function findItem(array $items, string $key): array
    {
        foreach ($items as $item) {
            if (\is_array($item) && (string)($item['key'] ?? '') === $key) {
                return $item;
            }
        }

        return [];
    }

    private function pageHtml(string $main): string
    {
        return '<header data-region="header">Neon Chess Casino</header><main>' . $main . '</main><footer data-region="footer">Support</footer><style>@media (max-width: 768px){.pb-c-root{max-width:100%;}}@media (max-width: 420px){.pb-c-root{width:100%;}}</style>';
    }
}
