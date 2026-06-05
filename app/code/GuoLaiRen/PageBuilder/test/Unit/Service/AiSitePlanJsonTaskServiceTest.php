<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AI\QA\RenderDataQualityLinter;
use PHPUnit\Framework\TestCase;

class AiSitePlanJsonTaskServiceTest extends TestCase
{
    public function testPlanJsonBlockStatusDrivesBuildQueueSelection(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => ['status' => 0, 'title' => 'Hero'],
                        'features' => ['status' => 1, 'title' => 'Features', 'html' => '<section>Done</section>'],
                        'cta' => ['status' => -1, 'title' => 'CTA', 'error' => 'copy failed'],
                        'gallery' => ['status' => 2, 'title' => 'Gallery'],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $pendingKeys = \array_values(\array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $service->listPendingTasks($scope)
        ));

        self::assertContains('page:home_page:content/home-page-hero', $pendingKeys);
        self::assertContains('page:home_page:content/home-page-cta', $pendingKeys);
        self::assertNotContains('page:home_page:content/home-page-features', $pendingKeys);
        self::assertNotContains('page:home_page:content/home-page-gallery', $pendingKeys);
    }

    public function testPlanJsonTasksUseScopeContentLocaleInsteadOfPageOrBlockLocale(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'content_locale' => 'pt_BR',
            'default_locale' => 'pt_BR',
            'plan_json' => [
                'content_locale' => 'de_DE',
                'pages' => [
                    'home_page' => [
                        'locale' => 'de_DE',
                        'hero' => [
                            'status' => 0,
                            'title' => 'Hero',
                            'content_locale' => 'de_DE',
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $task = $this->findTaskByKey(
            $service->listTaskDefinitions($scope),
            'page:home_page:content/home-page-hero'
        );

        self::assertSame('pt_BR', $task['runtime_context']['content_locale'] ?? null);
        self::assertSame('pt_BR', $task['runtime_context']['language_contract']['source_of_truth_locale'] ?? null);
    }

    public function testPlanJsonBlockStateWritesBackToSameNode(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:home_page:content/home-page-hero';

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => ['status' => 0, 'title' => 'Hero'],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $scope = $service->markTaskRunning($scope, $taskKey);
        self::assertSame(2, $scope['plan_json']['pages']['home_page']['hero']['status'] ?? null);

        $scope = $service->markTaskDone($scope, $taskKey, [
            'section_block' => [
                'html' => '<section><h1>Generated hero</h1></section>',
                'config' => ['headline' => 'Generated hero'],
            ],
        ]);

        self::assertSame(1, $scope['plan_json']['pages']['home_page']['hero']['status'] ?? null);
        self::assertSame(1, $scope['plan_json']['pages']['home_page']['status'] ?? null);
        self::assertSame('<section><h1>Generated hero</h1></section>', $scope['plan_json']['pages']['home_page']['hero']['html'] ?? null);
        self::assertSame(['headline' => 'Generated hero'], $scope['plan_json']['pages']['home_page']['hero']['fields'] ?? null);
    }

    public function testGeneratedBlockHtmlIsRepairedBeforePlanJsonStorage(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:home_page:content/home-page-player-reviews';

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'player_reviews' => [
                            'status' => 0,
                            'title' => 'Player reviews',
                            'section_code' => 'content/home-page-player-reviews',
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $scope = $service->markTaskRunning($scope, $taskKey);
        $scope = $service->markTaskDone($scope, $taskKey, [
            'section_block' => [
                'html' => '<section><div><article>Real player proof</section></article></div>',
                'config' => ['headline' => 'Real player proof'],
            ],
        ]);

        $html = (string)($scope['plan_json']['pages']['home_page']['player_reviews']['html'] ?? '');
        self::assertNotSame('', $html);
        self::assertStringContainsString('Real player proof', $html);

        $findings = (new RenderDataQualityLinter())->lint([
            'payload' => [
                'plan_json' => [
                    'pages' => [
                        'home_page' => [
                            'player_reviews' => $scope['plan_json']['pages']['home_page']['player_reviews'] ?? [],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $findings);
    }

    public function testPlanJsonBlockStopsAutomaticQueueSelectionAfterTwoAttempts(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $source = (string)\file_get_contents((new \ReflectionClass(AiSitePlanJsonTaskService::class))->getFileName());
        $taskKey = 'page:home_page:content/home-page-hero';

        self::assertStringContainsString('private const PLAN_JSON_TASK_MAX_AUTOMATIC_ATTEMPTS = 2;', $source);

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => ['status' => 0, 'title' => 'Hero'],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $scope = $service->markTaskRunning($scope, $taskKey);
            $scope = $service->markTaskFailed($scope, $taskKey, 'AI provider failed.');
        }

        self::assertSame(2, $scope['plan_json']['pages']['home_page']['hero']['attempt_no'] ?? null);
        self::assertSame(-1, $scope['plan_json']['pages']['home_page']['hero']['status'] ?? null);
        self::assertNotContains($taskKey, \array_column($service->listPendingTasks($scope), 'task_key'));
    }

    public function testBuildRenderDataContractCarriesCanonicalPlanJsonPages(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:home_page:content/home-page-hero';

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => [
                            'status' => 0,
                            'title' => 'Hero',
                            'section_code' => 'content/home-page-hero',
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        foreach (['header' => '<header>Generated header</header>', 'footer' => '<footer>Generated footer</footer>'] as $region => $html) {
            $scope = $service->markTaskRunning($scope, 'shared:' . $region);
            $scope = $service->markTaskDone($scope, 'shared:' . $region, [
                'region' => $region,
                'component' => [
                    'code' => $region === 'header' ? 'header/ai-site-header' : 'footer/ai-site-footer',
                    'region' => $region,
                    'html' => $html,
                    'phtml' => $html,
                    'default_config' => [],
                ],
            ]);
        }

        $scope = $service->markTaskRunning($scope, $taskKey);
        $scope = $service->markTaskDone($scope, $taskKey, [
            'section_block' => [
                'html' => '<section><h1>Generated hero</h1></section>',
                'config' => ['headline' => 'Generated hero'],
            ],
        ]);

        $scope = $service->attachBuildRenderDataContract($scope);
        $payload = $scope['render_data_contract']['payload'] ?? [];

        self::assertSame(
            '<section><h1>Generated hero</h1></section>',
            $payload['plan_json']['pages']['home_page']['hero']['html'] ?? null
        );
        self::assertSame('<header>Generated header</header>', $payload['plan_json']['shared_components']['header']['html'] ?? null);
        self::assertArrayNotHasKey('shared_components', $payload);
        self::assertContains('payload.plan_json.pages', $scope['render_data_contract']['frozen_fields'] ?? []);
        self::assertContains('payload.plan_json.shared_components', $scope['render_data_contract']['frozen_fields'] ?? []);
    }

    public function testBuildRenderDataContractClearsStalePreflightErrorAfterStructuralPass(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:home_page:content/home-page-hero';

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'quality_gate_preflight_error' => 'Plan JSON block is missing a block identity.',
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => [
                            'status' => 0,
                            'title' => 'Hero',
                            'section_code' => 'content/home-page-hero',
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        foreach (['header' => '<header>Generated header</header>', 'footer' => '<footer>Generated footer</footer>'] as $region => $html) {
            $scope = $service->markTaskRunning($scope, 'shared:' . $region);
            $scope = $service->markTaskDone($scope, 'shared:' . $region, [
                'region' => $region,
                'component' => [
                    'code' => $region === 'header' ? 'header/ai-site-header' : 'footer/ai-site-footer',
                    'region' => $region,
                    'html' => $html,
                    'phtml' => $html,
                    'default_config' => [],
                ],
            ]);
        }

        $scope = $service->markTaskRunning($scope, $taskKey);
        $scope = $service->markTaskDone($scope, $taskKey, [
            'section_block' => [
                'html' => '<section><h1>Generated hero</h1></section>',
                'config' => ['headline' => 'Generated hero'],
            ],
        ]);

        $scope = $service->attachBuildRenderDataContract($scope);

        self::assertArrayNotHasKey('quality_gate_preflight_error', $scope);
        self::assertSame('pass', $scope['qa_report_contract']['payload']['structure_quality']['status'] ?? null);
    }

    public function testGeneratedBlockHtmlHydratesEmptyTagsFromDefaultConfig(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:home_page:content/home-page-hero';

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => [
                            'status' => 0,
                            'section_code' => 'content/home-page-hero',
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $scope = $service->markTaskRunning($scope, $taskKey);
        $scope = $service->markTaskDone($scope, $taskKey, [
            'component' => [
                'html_content' => '<section><h1 class="pb-c-title"></h1><p class="pb-c-text"></p><a class="pb-c-cta" href=""></a><img src="" alt=""></section>',
                'default_config' => [
                    'content.headline' => 'Your Teen Patti table is live',
                    'content.body' => 'Verified Android APK with responsible-play guidance.',
                    'cta.text' => 'Download APK',
                    'cta.url' => '/download',
                    'media.image_url' => '/pub/media/page-build/hero.jpg',
                    'media.image_alt' => 'Teen Patti table',
                ],
            ],
        ]);

        $html = (string)($scope['plan_json']['pages']['home_page']['hero']['html'] ?? '');

        self::assertStringContainsString('Your Teen Patti table is live', $html);
        self::assertStringContainsString('Verified Android APK with responsible-play guidance.', $html);
        self::assertStringContainsString('>Download APK</a>', $html);
        self::assertStringContainsString('href="/download"', $html);
        self::assertStringContainsString('src="/pub/media/page-build/hero.jpg"', $html);
        self::assertStringContainsString('alt="Teen Patti table"', $html);
    }

    public function testBuildCompletionGateRequiresGeneratedHtmlOnDoneBlocks(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());

        $gate = $service->inspectBuildCompletionGate([
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => ['status' => 1, 'title' => 'Hero'],
                        'features' => ['status' => 1, 'title' => 'Features', 'html' => '<section>Features</section>'],
                    ],
                ],
            ],
        ]);

        self::assertFalse((bool)$gate['passed']);
        self::assertSame('missing_plan_json_block_html', $gate['reason']);
        self::assertSame(1, $gate['missing_html']);
        self::assertSame('hero', $gate['missing_html_rows'][0]['block_key'] ?? null);
    }

    public function testPageMetaNodesDoNotBecomePlanJsonBlockTasks(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'style_settings' => ['tone' => 'premium'],
                        'section_refinements' => ['hero' => ['density' => 'compact']],
                        'preview_full_url' => 'https://example.test/preview',
                        'visual_preview_url' => 'https://example.test/visual',
                        'virtual_edit_url' => 'https://example.test/edit',
                        'route_path' => '/',
                        'style_code' => 'poker-arena',
                        'ai_description' => 'Landing page preview metadata',
                        'hero' => [
                            'status' => 0,
                            'title' => 'Hero',
                            'section_code' => 'content/home-page-hero',
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $taskKeys = \array_values(\array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $service->listTaskDefinitions($scope)
        ));

        self::assertContains('page:home_page:content/home-page-hero', $taskKeys);
        self::assertNotContains('page:home_page:content/home-page-style-settings', $taskKeys);
        self::assertNotContains('page:home_page:content/home-page-section-refinements', $taskKeys);
        self::assertNotContains('page:home_page:content/home-page-preview-full-url', $taskKeys);

        $gate = $service->inspectBuildCompletionGate([
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'style_settings' => ['tone' => 'premium'],
                        'section_refinements' => ['hero' => ['density' => 'compact']],
                        'preview_full_url' => 'https://example.test/preview',
                        'visual_preview_url' => 'https://example.test/visual',
                        'virtual_edit_url' => 'https://example.test/edit',
                        'route_path' => '/',
                        'style_code' => 'poker-arena',
                        'ai_description' => 'Landing page preview metadata',
                        'hero' => [
                            'status' => 1,
                            'title' => 'Hero',
                            'html' => '<section><h1>Generated hero</h1></section>',
                        ],
                    ],
                ],
            ],
        ]);

        self::assertTrue((bool)$gate['passed']);
        self::assertSame(1, $gate['total']);
        self::assertSame(1, $gate['done']);
        self::assertSame(0, $gate['missing_html']);
    }

    public function testSharedHeaderFooterLayoutsSyncFromPlanJsonOnly(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());

        $scope = $service->syncPageTypeLayoutsWithSharedComponents([
            'shared_components' => [
                'header' => [
                    'code' => 'header/stale',
                    'phtml' => '<header>Stale</header>',
                    'default_config' => ['title' => 'Stale'],
                ],
            ],
            'plan_json' => [
                'shared_components' => [
                    'header' => [
                        'code' => 'header/ai-site-header',
                        'phtml' => '<header>Generated</header>',
                        'default_config' => ['title' => 'Generated header'],
                    ],
                    'footer' => [
                        'code' => 'footer/ai-site-footer',
                        'html' => '<footer>Generated</footer>',
                        'default_config' => ['links' => [['label' => 'Home', 'href' => '/']]],
                    ],
                ],
                'pages' => [
                    'home_page' => ['status' => 1],
                    'about_page' => ['page_type' => 'about_page', 'status' => 1],
                ],
            ],
        ]);

        self::assertSame('header/ai-site-header', $scope['page_type_layouts']['home_page']['header']['component'] ?? null);
        self::assertSame('Generated header', $scope['page_type_layouts']['home_page']['header']['config']['title'] ?? null);
        self::assertSame('footer/ai-site-footer', $scope['page_type_layouts']['about_page']['footer']['component'] ?? null);
        self::assertSame('Home', $scope['page_type_layouts']['about_page']['footer']['config']['links'][0]['label'] ?? null);
    }

    public function testSharedHeaderFooterTasksUsePlanJsonSharedComponentsAsTruth(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $scope = [
            'shared_components' => [
                'header' => [
                    'code' => 'header/stale',
                    'html' => '<header>Stale</header>',
                    'default_config' => ['title' => 'Stale'],
                ],
            ],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'page_type' => 'home_page',
                        'hero' => [
                            'status' => 0,
                            'title' => 'Hero',
                            'section_code' => 'content/home-page-hero',
                        ],
                    ],
                ],
            ],
        ];

        $picked = $service->pickConcurrentTasks($scope, 10);
        self::assertSame(['shared:header', 'shared:footer'], \array_column($picked, 'task_key'));

        $scope = $service->markTaskDone($scope, 'shared:header', [
            'region' => 'header',
            'component' => [
                'code' => 'header/ai-site-header',
                'region' => 'header',
                'html' => '<header>Generated</header>',
                'phtml' => '<header>Generated</header>',
                'default_config' => ['title' => 'Generated'],
            ],
        ]);

        self::assertSame(1, (int)($scope['plan_json']['shared_components']['header']['status'] ?? 0));
        self::assertSame('<header>Generated</header>', (string)($scope['plan_json']['shared_components']['header']['html'] ?? ''));
        self::assertArrayNotHasKey('shared_components', $scope);
    }

    public function testOldPlanSourcesDoNotSatisfyPageCoverage(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());

        self::assertSame(['home_page', 'about_page'], $service->collectMissingSelectedPlanPageTypes([
            'page_types' => ['home_page', 'about_page'],
            'plan_json' => [
                'hero' => ['status' => 0],
            ],
        ]));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private function findTaskByKey(array $tasks, string $taskKey): array
    {
        foreach ($tasks as $task) {
            if ((string)($task['task_key'] ?? '') === $taskKey) {
                return $task;
            }
        }

        return [];
    }
}
