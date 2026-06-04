<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\E2E;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Test\Integration\AbstractAiSiteWorkbenchIntegrationHarness;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Console\Queue\Run as QueueRunCommand;

class AiSiteQueuedStageOnePlanContractTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    private bool $hadPreviousPlanJsonGenerationService = false;
    private ?object $previousPlanJsonGenerationService = null;

    protected function tearDown(): void
    {
        $this->restoreObjectManagerInstance(
            AiSitePlanJsonGenerationService::class,
            $this->hadPreviousPlanJsonGenerationService,
            $this->previousPlanJsonGenerationService
        );
        parent::tearDown();
    }

    public function testQueuedStageOnePlanRunPersistsRequirementThemeAndPageContentContracts(): void
    {
        $calls = [];
        $aiService = $this->createMock(AiService::class);
        $aiService->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode = null,
                string $scenarioCode = '',
                $locale = null,
                array $params = []
            ) use (&$calls): void {
                $calls[] = [
                    'prompt' => $prompt,
                    'scenario' => $scenarioCode,
                    'params' => $params,
                ];

                if (\str_contains($prompt, 'Stage-1 REQUIREMENT EXPANSION planner')) {
                    $callback($this->buildRequirementExpansionResponse());
                    return;
                }
                if (\str_contains($prompt, 'Page type: ' . Page::TYPE_HOME)) {
                    $callback($this->makePlanJsonPageResponse(Page::TYPE_HOME));
                    return;
                }
                if (\str_contains($prompt, 'Page type: ' . Page::TYPE_ABOUT)) {
                    $callback($this->makePlanJsonPageResponse(Page::TYPE_ABOUT));
                    return;
                }

                $callback($this->buildThemeHeaderFooterResponse());
            });
        $aiService->method('runCooperativeSessionTasksSettled')
            ->willReturnCallback(static function (array $tasks, array $options = []): array {
                $settled = [];
                foreach ($tasks as $key => $task) {
                    try {
                        $settled[(string)$key] = [
                            'status' => 'fulfilled',
                            'result' => \is_callable($task) ? $task([]) : [],
                        ];
                    } catch (\Throwable $throwable) {
                        $settled[(string)$key] = [
                            'status' => 'rejected',
                            'error' => $throwable,
                        ];
                    }
                }

                return $settled;
            });

        $this->replaceObjectManagerInstance(
            AiSitePlanJsonGenerationService::class,
            new AiSitePlanJsonGenerationService(new AiSitePageBlueprintService(), $aiService)
        );

        $createPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-create-session',
            'POST',
            'postCreateSession'
        );
        self::assertTrue((bool)($createPayload['success'] ?? false), \json_encode($createPayload, \JSON_UNESCAPED_UNICODE));
        $publicId = (string)($createPayload['public_id'] ?? '');
        self::assertNotSame('', $publicId);

        $scopePatch = [
            'fake_mode' => 0,
            'site_title' => 'Royal India Play',
            'site_tagline' => 'Real rummy and teen patti download plan',
            'target_domain' => 'royal-india-play.local.test',
            'brief_description' => 'Create an Indian gaming website for rummy and teen patti APK downloads with trust proof and a clear install CTA.',
            'user_description' => 'Rummy, teen patti, APK download, trust proof, install CTA.',
            'default_locale' => 'en_US',
            'plan_locale' => 'en_US',
            'page_types' => [
                Page::TYPE_HOME,
                Page::TYPE_ABOUT,
            ],
        ];

        $mergePayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-merge-scope',
            'POST',
            'postMergeScope',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => $scopePatch,
            ]
        );
        self::assertTrue((bool)($mergePayload['success'] ?? false), \json_encode($mergePayload, \JSON_UNESCAPED_UNICODE));

        $startPlanPayload = $this->invokeJsonAction(
            '/pagebuilder/backend/ai-site-agent/post-start-plan',
            'POST',
            'postStartPlan',
            [],
            [
                'public_id' => $publicId,
                'scope_patch' => [],
            ]
        );
        self::assertTrue((bool)($startPlanPayload['success'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool)($startPlanPayload['start_sse'] ?? false), \json_encode($startPlanPayload, \JSON_UNESCAPED_UNICODE));

        $planQueue = $this->executeActiveQueue($publicId, 'plan', true);
        self::assertSame('done', (string)($planQueue['status'] ?? ''), (string)($planQueue['result'] ?? ''));
        $queueResult = (string)($planQueue['result'] ?? '');
        self::assertStringContainsString('第一阶段方案生成完成', $queueResult);

        self::assertNotEmpty($calls, 'Stage-one plan should still call AI for theme/page contracts.');
        $requirementCalls = \array_values(\array_filter(
            $calls,
            static fn(array $call): bool => \str_contains((string)($call['prompt'] ?? ''), 'Stage-1 REQUIREMENT EXPANSION planner')
        ));
        $themeCalls = \array_values(\array_filter(
            $calls,
            static fn(array $call): bool => \str_contains((string)($call['prompt'] ?? ''), 'THEME planner')
        ));
        $pageCalls = \array_values(\array_filter(
            $calls,
            static fn(array $call): bool => \str_contains((string)($call['prompt'] ?? ''), 'Page type:')
        ));
        self::assertCount(0, $requirementCalls, \json_encode($calls, \JSON_UNESCAPED_UNICODE));
        self::assertCount(1, $themeCalls, \json_encode($calls, \JSON_UNESCAPED_UNICODE));
        self::assertGreaterThanOrEqual(2, \count($pageCalls), \json_encode($calls, \JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('Confirmed requirement expansion from step 1', (string)$themeCalls[0]['prompt']);

        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN, [
            'plan_json',
            'plan_markdown',
        ]);
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];

        $this->assertStageOnePlanContract($planJson);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeActiveQueue(string $publicId, string $operation, bool $force = false): array
    {
        $session = $this->sessionService->loadByPublicId($publicId, 1);
        self::assertNotNull($session);
        $scope = $session->getScopeArray();
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        self::assertSame($operation, (string)($activeOperation['operation'] ?? ''), \json_encode($activeOperation, \JSON_UNESCAPED_UNICODE));

        $queueId = (int)($activeOperation['queue_id'] ?? 0);
        self::assertGreaterThan(0, $queueId, \json_encode($activeOperation, \JSON_UNESCAPED_UNICODE));

        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        self::assertGreaterThan(0, (int)($queue['queue_id'] ?? 0), 'Queue record must exist before execution.');

        if ($force) {
            $takeover = w_query('queue', 'takeover', [
                'queue_id' => $queueId,
                'force' => true,
                'owner' => 'manual_cli',
                'reason' => 'phpunit_in_process_queue_execution',
                'mark_force_rebuild' => true,
                'clear_output' => true,
                'wake_scheduler' => false,
                'auto' => false,
            ]);
            self::assertIsArray($takeover);
            self::assertTrue((bool)($takeover['success'] ?? false), \json_encode($takeover, \JSON_UNESCAPED_UNICODE));
        } else {
            w_query('queue', 'update', [
                'queue_id' => $queueId,
                'patch' => ['auto' => false],
            ]);
        }
        $this->disablePlanQueueRetryForInProcessTest($queueId);

        /** @var QueueRunCommand $runner */
        $runner = ObjectManager::getInstance(QueueRunCommand::class);
        $args = ['id' => $queueId];
        $bufferLevel = \ob_get_level();
        \ob_start();
        try {
            $runner->execute($args, []);
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
            while (\ob_get_level() < $bufferLevel) {
                \ob_start();
            }
        }

        $reloadedQueue = w_query('queue', 'get', ['queue_id' => $queueId]);
        $deadline = \microtime(true) + 5.0;
        while (\is_array($reloadedQueue) && (string)($reloadedQueue['status'] ?? '') === 'running' && \microtime(true) < $deadline) {
            \usleep(200000);
            $reloadedQueue = w_query('queue', 'get', ['queue_id' => $queueId]);
        }
        self::assertIsArray($reloadedQueue);

        return $reloadedQueue;
    }

    private function disablePlanQueueRetryForInProcessTest(int $queueId): void
    {
        $queue = w_query('queue', 'get', ['queue_id' => $queueId]);
        self::assertIsArray($queue);
        $content = \json_decode((string)($queue['content'] ?? ''), true);
        if (!\is_array($content)) {
            return;
        }

        $content['_plan_queue_retry_count'] = 99;
        $content['_provider_transient_retry_count'] = 99;
        w_query('queue', 'update', [
            'queue_id' => $queueId,
            'patch' => [
                'content' => (string)(\json_encode($content, \JSON_UNESCAPED_UNICODE) ?: (string)($queue['content'] ?? '')),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function assertStageOnePlanContract(array $planJson): void
    {
        self::assertNotSame([], $planJson);
        self::assertNotSame('', (string)($planJson['requirement_expansion']['expanded_brief'] ?? ''));
        self::assertNotSame('', (string)($planJson['requirement_expansion']['planning_summary'] ?? ''));
        self::assertNotSame('', (string)($planJson['requirement_expansion']['primary_cta'] ?? ''));
        self::assertNotEmpty($planJson['requirement_expansion']['page_strategy'] ?? []);
        self::assertNotSame('', (string)($planJson['theme_design']['selection_reason'] ?? ''));
        self::assertIsArray($planJson['shared_components']['header'] ?? null);
        self::assertIsArray($planJson['shared_components']['footer'] ?? null);
        self::assertNotSame('', (string)($planJson['shared_components']['header']['goal'] ?? ''));
        self::assertNotSame('', (string)($planJson['shared_components']['footer']['goal'] ?? ''));
        self::assertIsArray($planJson['page_type_overviews'] ?? null);

        foreach ([Page::TYPE_HOME, Page::TYPE_ABOUT] as $pageType) {
            $overview = \is_array($planJson['page_type_overviews'][$pageType] ?? null) ? $planJson['page_type_overviews'][$pageType] : [];
            self::assertNotSame([], $overview, $pageType . ' overview must be generated.');
            self::assertNotSame('', \trim((string)($overview['page_role'] ?? '')), $pageType . ' page_role must exist.');
            self::assertNotSame('', \trim((string)($overview['content_focus'] ?? '')), $pageType . ' content_focus must exist.');
            self::assertNotSame('', \trim((string)($overview['theme_color_application'] ?? '')), $pageType . ' theme_color_application must exist.');
            self::assertNotSame('', \trim((string)($overview['section_layering_hint'] ?? '')), $pageType . ' section_layering_hint must exist.');
            self::assertNotSame('', \trim((string)($overview['interaction_intent'] ?? '')), $pageType . ' interaction_intent must exist.');
            self::assertNotSame('', \trim((string)($overview['differentiation_note'] ?? '')), $pageType . ' differentiation_note must exist.');
        }

        self::assertIsArray($planJson['pages'][Page::TYPE_HOME] ?? null);
        self::assertIsArray($planJson['pages'][Page::TYPE_ABOUT] ?? null);
    }

    private function looksLikeInstructionalPlaceholder(string $text): bool
    {
        $normalized = \mb_strtolower(\trim($text));
        foreach ([
            '围绕',
            '突出',
            '说明',
            '完善',
            '优化',
            '标题围绕',
            'list 2-4',
            'write the title',
            'explain the core value',
            'string explaining how',
            'do not describe what should be written',
        ] as $needle) {
            if ($needle !== '' && \mb_stripos($normalized, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function buildRequirementExpansionResponse(): string
    {
        return \json_encode([
            'requirement_expansion' => [
                'original_brief' => 'Create an Indian gaming website for rummy and teen patti APK downloads with trust proof and a clear install CTA.',
                'expanded_brief' => 'Royal India Play needs an Indian gaming website for rummy and teen patti players who want a safe APK download, visible trust proof, and a direct install CTA.',
                'planning_summary' => 'The site should move visitors from game trust signals to APK install action, with Home driving conversion and About proving credibility.',
                'site_goal' => 'Convert Indian card-game visitors into APK installers while making trust and responsible play visible.',
                'target_users' => ['Indian rummy players', 'Teen patti players', 'Mobile APK visitors'],
                'business_context' => 'Real-money-style gaming landing site with trust and installation emphasis.',
                'content_direction' => 'Use concrete game names, install copy, safety proof, and responsible-play reassurance.',
                'conversion_strategy' => 'Header install CTA, hero download CTA, trust proof before final install action.',
                'page_strategy' => [
                    [
                        'page_type' => Page::TYPE_HOME,
                        'intent' => 'Drive APK install from first-screen promise and trust proof.',
                        'content_focus' => 'Rummy, teen patti, safe APK, install CTA.',
                        'conversion_role' => 'Primary install conversion page.',
                    ],
                    [
                        'page_type' => Page::TYPE_ABOUT,
                        'intent' => 'Explain brand credibility and responsible play promise.',
                        'content_focus' => 'Security, fair-play, Indian player support.',
                        'conversion_role' => 'Trust support page.',
                    ],
                ],
                'technical_direction' => ['Mobile-first responsive layout', 'Shared Header/Footer wrapped around every page'],
            ],
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildThemeHeaderFooterResponse(): string
    {
        return \json_encode([
            'i18n' => ['locale' => 'en_US', 'labels' => ['title' => 'Stage 1 Content Plan']],
            'site_strategy' => [
                'site_display_name' => 'Royal India Play',
                'summary' => 'Indian rummy and teen patti APK download site with trust proof.',
                'website_type' => 'gaming APK landing site',
                'core_goal' => 'Convert game visitors into APK installers.',
                'target_users' => 'Indian mobile card-game players',
                'conversion_path' => 'Header CTA -> hero install -> trust proof -> final install CTA',
            ],
            'theme_style' => [
                'name' => 'Royal Game Trust',
                'visual_tone' => 'Trust-first gaming energy with clear install rhythm.',
                'font_family' => 'Inter, Noto Sans',
                'selection_reason' => 'Rummy, teen patti, and APK download visitors need a readable high-trust gaming voice before installing.',
            ],
            'palette' => [
                'name' => 'Emerald Saffron',
                'primary' => '#064e3b',
                'secondary' => '#0f766e',
                'accent' => '#f59e0b',
                'surface' => '#f8fafc',
                'text' => '#0f172a',
                'selection_reason' => 'Emerald trust and saffron action colors fit the Indian rummy and teen patti APK install requirement.',
            ],
            'theme_design' => [
                'theme_purpose' => 'Help Indian rummy and teen patti players feel safe enough to install the APK.',
                'color_scheme' => [
                    'name' => 'Emerald Saffron',
                    'primary' => '#064e3b',
                    'secondary' => '#0f766e',
                    'accent' => '#f59e0b',
                    'background' => '#f8fafc',
                    'body' => '#0f172a',
                    'button' => '#f59e0b',
                ],
                'typography_spacing_radius' => [
                    'font_family' => 'Inter, Noto Sans',
                    'heading_scale' => 'Hero 48px desktop, 34px mobile; section titles 28px.',
                    'body_scale' => '16px body with 1.7 line height for safety and install copy.',
                    'spacing_scale' => '80px desktop sections, 40px mobile sections.',
                    'radius_scale' => '16px trust cards and 999px install CTA buttons.',
                ],
                'visual_keywords' => ['secure gaming', 'Indian card table', 'APK trust proof'],
                'tone_of_voice' => 'Confident, safe, and install-focused',
                'cta_tone' => 'Direct install actions such as Download APK and Start Playing',
                'forbidden_styles' => ['generic casino glamor', 'dark unreadable layout'],
                'selection_reason' => 'The rummy, teen patti, APK download, trust proof, and install CTA requirement needs a safe gaming theme instead of a generic landing page.',
            ],
            'navigation_plan' => [
                'header_items' => [
                    ['label' => 'Home', 'href' => '/'],
                    ['label' => 'About', 'href' => '/about'],
                    ['label' => 'Download APK', 'href' => '#download-apk'],
                ],
            ],
            'footer_plan' => [
                'featured' => [
                    ['label' => 'Download APK', 'href' => '#download-apk'],
                    ['label' => 'Responsible Play', 'href' => '/about#responsible-play'],
                ],
                'policies' => [
                    ['label' => 'Privacy Policy', 'href' => '/privacy-policy'],
                ],
            ],
            'shared_components' => [
                'header' => [
                    'component' => 'header',
                    'title' => 'Royal India Play Header',
                    'goal' => 'Keep APK download action and trust navigation visible on every page.',
                    'implementation_detail' => 'Sticky responsive header with brand mark, Home/About links, and a saffron Download APK CTA.',
                    'realtime_content' => [
                        'headline' => 'Royal India Play',
                        'supporting_copy' => ['Home', 'About', 'Download APK'],
                        'cta' => [['label' => 'Download APK', 'target' => '#download-apk']],
                        'editable_slots' => ['brand_name', 'nav_items', 'primary_cta'],
                    ],
                    'editable_fields' => ['brand_name', 'nav_items', 'primary_cta'],
                    'responsive_rule' => 'Collapse links into a mobile menu while keeping Download APK visible.',
                ],
                'footer' => [
                    'component' => 'footer',
                    'title' => 'Royal India Play Footer',
                    'goal' => 'Close every page with APK, policy, and responsible-play reassurance.',
                    'implementation_detail' => 'Footer columns for Download APK, Responsible Play, contact, and policy links.',
                    'realtime_content' => [
                        'headline' => 'Play rummy and teen patti with safer APK access',
                        'supporting_copy' => ['Download APK', 'Responsible Play', 'Privacy Policy'],
                        'cta' => [['label' => 'Download APK', 'target' => '#download-apk']],
                        'editable_slots' => ['footer_links', 'policy_links', 'responsible_play_copy'],
                    ],
                    'editable_fields' => ['footer_links', 'policy_links', 'responsible_play_copy'],
                    'responsive_rule' => 'Stack columns on mobile and repeat the install CTA.',
                ],
            ],
            'seo_strategy' => [
                'core_intent' => 'rummy and teen patti APK download India',
                'primary_keywords' => ['rummy APK download', 'teen patti APK India'],
                'keyword_page_map' => [
                    ['keyword' => 'rummy APK download', 'page_type' => Page::TYPE_HOME],
                    ['keyword' => 'safe teen patti app', 'page_type' => Page::TYPE_ABOUT],
                ],
                'content_strategy' => 'Use install, game, and trust keywords in visible copy.',
                'internal_linking' => 'Header and footer link Home, About, and APK CTA.',
                'url_structure' => 'flat',
            ],
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function makePlanJsonPageResponse(string $pageType): string
    {
        $page = $pageType === Page::TYPE_ABOUT
            ? [
                'page_goal' => 'Prove Royal India Play is a safer place for Indian rummy and teen patti APK visitors.',
                'theme_alignment_summary' => 'About uses the Emerald Saffron trust palette, direct safety voice, rounded proof cards, and Download APK handoff from Header/Footer.',
                'primary_keywords' => ['safe rummy app', 'responsible teen patti'],
                'secondary_keywords' => ['APK safety', 'Indian player support'],
                'origin_story' => $this->buildPageBlock('origin_story', 'Royal India Play explains why its APK guide focuses on clear checks, readable rules, and practical mobile-first trust for Indian players.'),
                'mission_values' => $this->buildPageBlock('mission_values', 'The mission values section shows safer recommendations, responsible play reminders, and concise editorial standards before any install decision.'),
                'trust_proof' => $this->buildPageBlock('trust_proof', 'Trust proof highlights review steps, support visibility, privacy-aware guidance, and transparent download expectations.'),
                'about_cta' => $this->buildPageBlock('about_cta', 'The about CTA invites visitors to return to the main download guide after they understand the brand standards and support promise.'),
            ]
            : [
                'page_goal' => 'Turn Indian rummy and teen patti visitors into confident APK installers.',
                'theme_alignment_summary' => 'Home uses the Emerald Saffron palette, direct Download APK CTA tone, trust cards, and shared Header/Footer install path.',
                'primary_keywords' => ['rummy APK download', 'teen patti APK'],
                'secondary_keywords' => ['Indian gaming app', 'safe APK install'],
                'hero_download' => $this->buildPageBlock('hero_download', 'The download hero states the APK action, install confidence, supported game categories, and visible reassurance before visitors tap the primary button.'),
                'game_showcase_or_features' => $this->buildPageBlock('game_showcase_or_features', 'The game showcase compares rummy, teen patti, ludo, carrom, and chess options with concise benefits for Android players.'),
                'trust_security' => $this->buildPageBlock('trust_security', 'Trust and security content explains review checks, responsible-play cues, support visibility, and privacy-aware install guidance.'),
                'player_reviews' => $this->buildPageBlock('player_reviews', 'Player review cards summarize practical visitor concerns such as speed, clarity, responsible play, and support response quality.'),
                'faq_or_rules' => $this->buildPageBlock('faq_or_rules', 'FAQ and rules content answers install safety, account basics, game selection, troubleshooting, and responsible-use questions.'),
                'final_download_cta' => $this->buildPageBlock('final_download_cta', 'The final download action band gives visitors one confident APK path after they have scanned games, trust proof, reviews, and FAQ details.'),
                'bonus_steps' => $this->buildPageBlock('bonus_steps', 'Bonus steps explain the short install checklist, verification cues, and what visitors should confirm before opening the downloaded APK.'),
            ];

        return \json_encode(['page' => $page], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPageBlock(string $blockKey, string $content): array
    {
        $role = \str_contains($blockKey, 'cta') || \str_contains($blockKey, 'download')
            ? 'cta'
            : (\str_contains($blockKey, 'trust') || \str_contains($blockKey, 'review') ? 'proof' : 'content');
        $motif = \str_replace('_', ' ', $blockKey);

        return [
            'block_key' => $blockKey,
            'page_flow_role' => $role,
            'goal' => $content,
            'keywords' => ['rummy', 'teen patti', 'APK'],
            'content' => $content,
            'design_tags' => [
                'visual' => ['rounded trust cards', 'saffron CTA button', 'emerald headline'],
                'motion' => ['subtle card reveal', 'button hover lift'],
                'interaction' => ['Download APK hover state', 'trust card tap state'],
                'texture' => ['soft emerald gradient', 'card-table accent'],
                'responsive' => ['mobile stacked cards', 'desktop two-column'],
                'color_layering' => 'Emerald base, saffron action accent, and white content panels stay distinct for ' . $motif . '.',
                'implementation_note' => 'Use theme tokens and keep Download APK visible without covering content.',
            ],
            'visual_signature' => [
                'composition_pattern' => $motif . ' uses a distinct responsive section layout.',
                'spatial_rhythm' => $motif . ' alternates dense copy with compact visual accents.',
                'media_strategy' => 'CSS-only/no generated image: build a unique ' . $motif . ' motif with cards, chips, badges, or rule rows.',
                'surface_treatment' => $motif . ' uses layered surfaces with a different elevation rhythm from adjacent blocks.',
                'interaction_pattern' => $motif . ' uses subtle hover and focus states tied to its own action or proof elements.',
            ],
            'image_intent' => [
                'needs_image' => false,
                'image_role' => 'No generated image is required for this contract test block.',
                'image_subject' => 'CSS-only ' . $motif . ' interface motif.',
                'placement' => 'Inline with the block body.',
                'visual_atmosphere' => 'Polished mobile gaming guide with trust-first contrast.',
                'image_treatment' => 'CSS-only badges, cards, and table accents using the theme palette.',
                'reuse_policy' => 'Do not reuse generated images across blocks.',
                'css_motif' => 'Unique ' . $motif . ' CSS motif.',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => 'Download Royal India Play APK', 'implementation_note' => 'Use as visible section headline.'],
                ['field' => 'description', 'sample' => $content, 'implementation_note' => 'Place below the headline as real body copy.'],
                ['field' => 'button_text', 'sample' => 'Download APK', 'implementation_note' => 'Use as primary CTA label.'],
            ],
            'execution_script' => [
                'feature_points' => ['Visible APK CTA', 'Rummy and teen patti proof', 'Responsible play reassurance'],
                'core_copy' => $content,
                'typography' => 'Use Inter/Noto Sans headline and readable body copy.',
                'style_tone' => 'Safe gaming confidence.',
                'background_direction' => 'Emerald surface with saffron CTA contrast.',
            ],
            'reusable' => 'no',
            'seo_impact' => 'high',
        ];
    }

    private function replaceObjectManagerInstance(string $class, object $instance): void
    {
        $instances = $this->getObjectManagerScopedInstances();
        $this->hadPreviousPlanJsonGenerationService = \array_key_exists($class, $instances);
        $this->previousPlanJsonGenerationService = $this->hadPreviousPlanJsonGenerationService ? $instances[$class] : null;
        $instances[$class] = $instance;
        $this->setObjectManagerScopedInstances($instances);
    }

    private function restoreObjectManagerInstance(string $class, bool $hadPrevious, ?object $previous): void
    {
        $instances = $this->getObjectManagerScopedInstances();
        if ($hadPrevious && $previous !== null) {
            $instances[$class] = $previous;
        } else {
            unset($instances[$class]);
        }
        $this->setObjectManagerScopedInstances($instances);
    }

    /**
     * @return array<string, object>
     */
    private function getObjectManagerScopedInstances(): array
    {
        $method = new \ReflectionMethod(ObjectManager::class, 'getScopedInstances');
        $method->setAccessible(true);
        $instances = $method->invoke(null, false);

        return \is_array($instances) ? $instances : [];
    }

    /**
     * @param array<string, object> $instances
     */
    private function setObjectManagerScopedInstances(array $instances): void
    {
        $method = new \ReflectionMethod(ObjectManager::class, 'setScopedInstances');
        $method->setAccessible(true);
        $method->invoke(null, $instances, false);
    }

}
