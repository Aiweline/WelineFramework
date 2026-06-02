<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;

final class AiSitePageComponentGenerationSchemaGuardTest extends TestCase
{
    public function testComponentGenerationRetryBudgetIsProductBounded(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/AiSitePageComponentGenerationService.php');

        self::assertStringContainsString('private const COMPONENT_GENERATION_MAX_ATTEMPTS = 3;', $source);
        self::assertStringContainsString('private const AI_REQUEST_TIMEOUT_SECONDS = 180;', $source);
        self::assertStringNotContainsString('private const COMPONENT_GENERATION_MAX_ATTEMPTS = 5;', $source);
        self::assertStringNotContainsString('private const AI_REQUEST_TIMEOUT_SECONDS = 600;', $source);
    }

    public function testInlineImageGenerationIsFastFailEnhancement(): void
    {
        $moduleDir = \dirname(__DIR__, 3);
        $assetSource = (string)\file_get_contents($moduleDir . '/Service/AiSiteAutoAssetGenerationService.php');
        $controllerSource = (string)\file_get_contents($moduleDir . '/Controller/Backend/AiSiteAgent.php');
        $service = new AiSitePageComponentGenerationService();

        $defaultAttempts = (function (): int {
            return $this->resolveInlineImageGenerationMaxAttempts([], []);
        })->call($service);

        self::assertSame(1, $defaultAttempts);
        self::assertStringContainsString('private const IMAGE_GENERATION_TIMEOUT_SECONDS = 20;', $assetSource);
        self::assertStringContainsString('private const IMAGE_GENERATION_MAX_ATTEMPTS = 1;', $assetSource);
        self::assertStringContainsString("'image_generation_max_attempts' => 1", $controllerSource);
        self::assertStringContainsString("'image_timeout' => \$imageTimeout", $assetSource);
        self::assertStringContainsString("'timeout' => \$imageTimeout", $assetSource);
    }

    public function testBuildQueuePromptUsesCompactOnePassContractInsteadOfDuplicatingLongContract(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $prompt = (function (): string {
            return $this->appendComponentCssScopeInstruction(
                "Base section prompt\nStage-2 component output contract V3\nCTX_CURRENT_ASSET\nCTX_FROZEN_TASK",
                'content/home-page-hero',
                [
                    '_build_plan_task' => ['task_key' => 'page:home_page:content/home-page-hero'],
                    '_visual_contract' => ['strict_hero_cover' => 1],
                ]
            );
        })->call($service);

        self::assertStringContainsString('PAGEBUILDER_ONE_PASS_FAST_CONTRACT', $prompt);
        self::assertStringContainsString('PRODUCT_LATENCY_OUTPUT_BUDGET', $prompt);
        self::assertStringContainsString('html_content <= 1800 chars', $prompt);
        self::assertStringContainsString('css_extra <= 3000 chars', $prompt);
        self::assertStringNotContainsString('Component-specific strong contract', $prompt);
    }

    public function testBuildQueueComponentGenerationUsesBoundedOutputTokens(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $tokens = (function (): array {
            return [
                'header' => $this->resolveComponentGenerationMaxTokens('header', [], false),
                'content_build_first' => $this->resolveComponentGenerationMaxTokens(
                    'content',
                    ['_build_plan_task' => ['task_key' => 'page:home_page:content/home-page-hero']],
                    false
                ),
                'content_build_retry' => $this->resolveComponentGenerationMaxTokens(
                    'content',
                    ['_build_plan_task' => ['task_key' => 'page:home_page:content/home-page-hero']],
                    true
                ),
                'content_non_build' => $this->resolveComponentGenerationMaxTokens('content', [], false),
            ];
        })->call($service);

        self::assertSame(1024, $tokens['header']);
        self::assertSame(4096, $tokens['content_build_first']);
        self::assertSame(3584, $tokens['content_build_retry']);
        self::assertSame(6144, $tokens['content_non_build']);
    }

    public function testBuildQueueContentDeterministicCompilersRequireExplicitOptIn(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $matches = (function (): array {
            $heroContext = [
                'build_plan_task' => [
                    'task_key' => 'page:home_page:content/home-page-hero',
                    'block_key' => 'hero',
                    'block_type' => 'hero',
                    'page_flow_role' => 'opening',
                ],
                'visual_contract' => [
                    'strict_hero_cover' => 1,
                    'page_flow_role' => 'opening',
                    'section_template' => 'hero',
                ],
            ];
            $proofContext = [
                'build_plan_task' => [
                    'task_key' => 'page:home_page:content/home-page-brand-promise',
                    'block_key' => 'brand_promise',
                    'block_type' => 'brand_promise',
                    'page_flow_role' => 'proof',
                ],
                'visual_contract' => [
                    'page_flow_role' => 'proof',
                    'section_template' => 'section',
                ],
            ];

            return [
                'hero' => $this->shouldCompileDeterministicStrictHeroComponent(
                    'content/home-page-hero',
                    'content',
                    [],
                    $heroContext
                ),
                'proof_grid' => $this->shouldCompileDeterministicCardGridComponent(
                    'content/home-page-brand-promise',
                    'content',
                    [],
                    $proofContext
                ),
            ];
        })->call($service);

        self::assertFalse($matches['hero']);
        self::assertFalse($matches['proof_grid']);
    }

    public function testStrictHeroBuildQueueUsesDeterministicCompilerPayload(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $defaultConfig = [
            'content.title' => 'Automate approvals without spreadsheet drift',
            'content.description' => 'Ops teams map handoffs, catch exceptions, and prove ROI from one command center.',
            'cta.text' => 'Request a demo',
            'runtime.section_name' => 'Home hero',
        ];
        $renderContext = [
            '_build_plan_task' => [
                'task_key' => 'page:home_page:content/home-page-hero',
                'page_type' => 'home_page',
                'block_key' => 'home-page-hero',
            ],
            '_visual_contract' => [
                'strict_hero_cover' => 1,
                'slot_id' => '',
                'final_url' => '',
            ],
        ];

        $aiData = (function () use ($defaultConfig, $renderContext): array {
            return $this->buildDeterministicStrictHeroAiData(
                'content/home-page-hero',
                $defaultConfig,
                $renderContext
            );
        })->call($service);

        self::assertStringContainsString('content.title => Title:text:Automate approvals without spreadsheet drift', $aiData['extra_fields']);
        self::assertStringContainsString('$contentTitle = $getConfig(\'content.title\'', $aiData['php_variables']);
        self::assertStringContainsString("class='pb-c-media-stage'", $aiData['html_content']);
        self::assertStringContainsString("class='pb-c-media-label'><?= htmlspecialchars(\$mediaLabel", $aiData['html_content']);
        self::assertStringContainsString('#componentId{padding:0;', $aiData['css_extra']);
        self::assertStringContainsString('@media (max-width: 768px)', $aiData['css_responsive']);
        self::assertStringContainsString('@media (max-width: 420px)', $aiData['css_responsive']);
    }

    public function testStrictHeroBuildQueueGenerateComponentDoesNotNeedAiProvider(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $result = (function (): array {
            return $this->generateComponent(
                'content/home-page-hero',
                'Home hero',
                'content',
                'Prompt that would normally call the provider',
                [
                    'content.title' => 'Automate approvals without spreadsheet drift',
                    'content.description' => 'Ops teams map handoffs, catch exceptions, and prove ROI from one command center.',
                    'cta.text' => 'Request a demo',
                    'cta.url' => '/contact',
                    'runtime.section_name' => 'Home hero',
                ],
                [
                    AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
                    '_build_plan_task' => [
                        'task_key' => 'page:home_page:content/home-page-hero',
                        'page_type' => 'home_page',
                        'block_key' => 'home-page-hero',
                    ],
                    '_visual_contract' => ['strict_hero_cover' => 1],
                ]
            );
        })->call($service);

        self::assertSame('content/home-page-hero', $result['code']);
        self::assertStringContainsString('Automate approvals without spreadsheet drift', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('pb-c-root', $result['phtml']);
        self::assertStringContainsString('pb-c-root', $result['html']);
    }

    public function testOpeningHeroBuildQueueGenerateComponentDoesNotNeedAiProviderWhenNotStrictCover(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $result = (function (): array {
            return $this->generateComponent(
                'content/home-page-hero',
                'Home hero',
                'content',
                'Prompt that would normally call the provider',
                [
                    'content.title' => 'Automate approvals without spreadsheet drift',
                    'content.description' => 'Ops teams map handoffs, catch exceptions, and prove ROI from one command center.',
                    'cta.text' => 'Request a demo',
                    'cta.url' => '/contact',
                    'runtime.section_name' => 'Home hero',
                ],
                [
                    AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
                    'build_plan_task' => [
                        'task_key' => 'page:home_page:content/home-page-hero',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-hero',
                        'block_key' => 'hero',
                        'block_type' => 'hero',
                        'page_flow_role' => 'opening',
                    ],
                    'visual_contract' => [
                        'strict_hero_cover' => 0,
                        'page_flow_role' => 'opening',
                        'slot_id' => 'page:home_page:content-home-page-hero',
                        'final_url' => '/pub/media/page-build/ai-generated/home-hero.jpg',
                    ],
                ]
            );
        })->call($service);

        self::assertSame('content/home-page-hero', $result['code']);
        self::assertStringContainsString('Automate approvals without spreadsheet drift', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('cta.url => CTA URL:text:/contact', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('$ctaUrl = $getConfig(\'cta.url\', \'/contact\');', $result['ai_data']['php_variables'] ?? '');
        self::assertStringContainsString('media.image_url => Image:image:/pub/media/page-build/ai-generated/home-hero.jpg', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString("class='pb-c-img'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString("class='pb-c-cta' href='<?= htmlspecialchars(\$ctaUrl ?? '/contact'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString('pb-c-root', $result['phtml']);
    }

    public function testOpeningHeroDeterministicCompilerDoesNotUseColorTokenAsDescription(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $defaultConfig = [
            'content.title' => 'Automate approvals without spreadsheet drift',
            'content.description' => '',
            'cta.text' => 'Request a demo',
            'cta.url' => '/contact',
            'runtime.section_name' => 'Home hero',
        ];
        $renderContext = [
            'build_plan_task' => [
                'task_key' => 'page:home_page:content/home-page-hero',
                'page_type' => 'home_page',
                'section_code' => 'content/home-page-hero',
                'block_type' => 'hero',
                'page_flow_role' => 'opening',
                'plan_context' => [
                    'color_roles' => [
                        'body' => '#FFFFFF',
                    ],
                    'block_goal' => 'Show operations leaders how approval routing, exception alerts, and dashboard proof replace spreadsheet chaos.',
                ],
            ],
            'visual_contract' => [
                'strict_hero_cover' => 0,
                'page_flow_role' => 'opening',
            ],
        ];

        $aiData = (function () use ($defaultConfig, $renderContext): array {
            return $this->buildDeterministicStrictHeroAiData(
                'content/home-page-hero',
                $defaultConfig,
                $renderContext
            );
        })->call($service);

        self::assertStringContainsString('content.description => Description:textarea:Show operations leaders how approval routing', $aiData['extra_fields']);
        self::assertStringNotContainsString('content.description => Description:textarea:#FFFFFF', $aiData['extra_fields']);
    }

    public function testOpeningNarrativeBuildQueueGenerateComponentDoesNotNeedAiProvider(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $result = (function (): array {
            return $this->generateComponent(
                'content/about-page-origin-story',
                'We saw ops teams drowning in spreadsheets. So we built the workflow layer.',
                'content',
                'Prompt that would normally call the provider',
                [
                    'content.title' => 'We saw ops teams drowning in spreadsheets. So we built the workflow layer.',
                    'content.description' => '',
                    'cta.text' => 'Request a demo',
                    'cta.url' => '/contact',
                    'runtime.section_name' => 'Origin story',
                ],
                [
                    AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
                    'build_plan_task' => [
                        'task_key' => 'page:about_page:content/about-page-origin-story',
                        'page_type' => 'about_page',
                        'section_code' => 'content/about-page-origin-story',
                        'block_key' => 'origin_story',
                        'block_type' => 'origin_story',
                        'page_flow_role' => 'opening',
                        'content_items' => [
                            'block.about_page.origin_story.title' => 'We saw ops teams drowning in spreadsheets. So we built the workflow layer.',
                            'block.about_page.origin_story.copy' => 'OpsFlow started when we mapped 140+ manual handoffs and built the workflow layer operations teams deserved.',
                        ],
                        'image_intent' => [
                            'image_subject' => 'Two operations leaders reviewing a workflow automation dashboard on a large wall-mounted display',
                        ],
                    ],
                    'visual_contract' => [
                        'strict_hero_cover' => 0,
                        'page_flow_role' => 'opening',
                        'section_template' => 'section',
                        'slot_id' => 'page:about_page:content-about-page-origin-story',
                        'final_url' => '',
                    ],
                ]
            );
        })->call($service);

        self::assertSame('content/about-page-origin-story', $result['code']);
        self::assertStringContainsString('content.description => Description:textarea:OpsFlow started when we mapped 140+ manual handoffs', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('proof.item_1_label => Proof 1 label:text:', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('media.label => Media label:text:Two operations leaders reviewing a workflow automation dashboard', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('cta.url => CTA URL:text:/contact', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString("<h1 class='pb-c-title'>", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString("class='pb-c-screen'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString('pb-c-root', $result['phtml']);
    }

    public function testContactSupportFormBuildQueueGenerateComponentDoesNotNeedAiProvider(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $result = (function (): array {
            return $this->generateComponent(
                'content/contact-page-support-form-guidance',
                'Tell us what workflow should improve first',
                'content',
                'Prompt that would normally call the provider',
                [
                    'content.title' => 'Tell us what workflow should improve first',
                    'content.description' => 'Share one stuck approval path and we will recommend the next automation step.',
                    'cta.text' => 'Send message',
                    'cta.url' => '/contact',
                    'form.label_1' => 'Work email',
                    'form.placeholder_1' => 'Your work email',
                    'form.label_2' => 'Workflow priority',
                    'form.placeholder_2' => 'Approval routing or exception follow-up',
                    'form.label_3' => 'What should we help automate?',
                    'form.placeholder_3' => 'Describe the process your team wants to improve',
                    'runtime.section_name' => 'Support form guidance',
                ],
                [
                    AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
                    'build_plan_task' => [
                        'task_key' => 'page:contact_page:content/contact-page-support-form-guidance',
                        'page_type' => 'contact_page',
                        'section_code' => 'content/contact-page-support-form-guidance',
                        'block_key' => 'support_form_guidance',
                        'block_type' => 'support_form_guidance',
                        'page_flow_role' => 'support',
                    ],
                    'visual_contract' => [
                        'strict_hero_cover' => 0,
                        'page_flow_role' => 'support',
                        'section_template' => 'form',
                    ],
                ]
            );
        })->call($service);

        self::assertSame('content/contact-page-support-form-guidance', $result['code']);
        self::assertStringContainsString('form.label_1 => Form label 1:text:Work email', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('form.note_text => Form note:textarea:', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('$formLabel1 = $getConfig(\'form.label_1\', \'Work email\');', $result['ai_data']['php_variables'] ?? '');
        self::assertStringContainsString("class='pb-c-form'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString("class='pb-c-label'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString("class='pb-c-input'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString("class='pb-c-textarea'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString("type='submit' class='pb-c-cta'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString('pb-c-root', $result['phtml']);
    }

    public function testContactSupportFaqBuildQueueGenerateComponentDoesNotNeedAiProvider(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $result = (function (): array {
            return $this->generateComponent(
                'content/contact-page-support-faq',
                'Answers before the first workflow review',
                'content',
                'Prompt that would normally call the provider',
                [
                    'content.title' => 'Answers before the first workflow review',
                    'content.description' => 'The common questions operations teams ask before they move beyond spreadsheets.',
                    'faq.question_1' => 'How quickly can we start?',
                    'faq.answer_1' => 'Start with one approval path, then expand once the first workflow is visible.',
                    'runtime.section_name' => 'Support FAQ',
                ],
                [
                    AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
                    'build_plan_task' => [
                        'task_key' => 'page:contact_page:content/contact-page-support-faq',
                        'page_type' => 'contact_page',
                        'section_code' => 'content/contact-page-support-faq',
                        'block_key' => 'support_faq',
                        'block_type' => 'support_faq',
                        'page_flow_role' => 'support',
                    ],
                    'visual_contract' => [
                        'strict_hero_cover' => 0,
                        'page_flow_role' => 'support',
                        'section_template' => 'faq',
                    ],
                ]
            );
        })->call($service);

        self::assertSame('content/contact-page-support-faq', $result['code']);
        self::assertStringContainsString('faq.question_1 => FAQ question 1:text:How quickly can we start?', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('faq.answer_1 => FAQ answer 1:textarea:Start with one approval path', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringNotContainsString('cta.text => CTA text', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString("class='pb-c-faq-item'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString("class='pb-c-question'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString("class='pb-c-answer'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString('pb-c-root', $result['phtml']);
    }

    public function testContactSupportFaqKeepsRequiredCtaFieldsWhenPlanRequiresThem(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $requiredFields = (string)\json_encode([
            ['key' => 'content.title', 'label' => 'Title', 'type' => 'text', 'default' => 'Frequently asked questions'],
            ['key' => 'cta.text', 'label' => 'CTA text', 'type' => 'text', 'default' => 'Contact Us'],
            ['key' => 'cta.url', 'label' => 'CTA URL', 'type' => 'text', 'default' => '/contact'],
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        $result = (function () use ($requiredFields): array {
            return $this->generateComponent(
                'content/contact-page-support-faq',
                'Frequently asked questions',
                'content',
                'Prompt that would normally call the provider',
                [
                    'content.title' => 'Frequently asked questions',
                    'content.description' => 'Get instant answers about your operational automation needs.',
                    'cta.text' => 'Contact Us',
                    'cta.url' => '/contact',
                    'runtime.required_editable_fields' => $requiredFields,
                    'runtime.section_name' => 'Frequently asked questions',
                ],
                [
                    AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
                    'build_plan_task' => [
                        'task_key' => 'page:contact_page:content/contact-page-support-faq',
                        'page_type' => 'contact_page',
                        'section_code' => 'content/contact-page-support-faq',
                        'block_key' => 'support_faq',
                        'block_type' => 'support_faq',
                        'page_flow_role' => 'support',
                    ],
                    '_required_editable_fields' => $requiredFields,
                    'visual_contract' => [
                        'strict_hero_cover' => 0,
                        'page_flow_role' => 'support',
                        'section_template' => 'faq',
                    ],
                ]
            );
        })->call($service);

        self::assertStringContainsString('cta.text => CTA text:text:Contact Us', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('cta.url => CTA URL:text:/contact', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('$ctaText = $getConfig(\'cta.text\', \'Contact Us\');', $result['ai_data']['php_variables'] ?? '');
        self::assertStringContainsString('$ctaUrl = $getConfig(\'cta.url\', \'/contact\');', $result['ai_data']['php_variables'] ?? '');
        self::assertStringContainsString("class='pb-c-cta' href='<?= htmlspecialchars(\$ctaUrl ?? '/contact'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString("class='pb-c-faq-item'", $result['ai_data']['html_content'] ?? '');
    }

    public function testContactSupportCompilerOwnsOnlyContactSupportBlocks(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $probe = static function (string $componentCode, array $task, array $visualContract = []) use ($service): bool {
            return (function () use ($componentCode, $task, $visualContract): bool {
                return $this->shouldCompileDeterministicContactSupportComponent(
                    $componentCode,
                    'content',
                    [],
                    [
                        AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
                        'build_plan_task' => $task,
                        'visual_contract' => $visualContract,
                    ]
                );
            })->call($service);
        };

        self::assertTrue($probe('content/contact-page-contact-methods', [
            'task_key' => 'page:contact_page:content/contact-page-contact-methods',
            'block_key' => 'contact_methods',
            'block_type' => 'contact_methods',
            'page_flow_role' => 'support',
        ]));
        self::assertTrue($probe('content/contact-page-support-form-guidance', [
            'task_key' => 'page:contact_page:content/contact-page-support-form-guidance',
            'block_key' => 'support_form_guidance',
            'block_type' => 'support_form_guidance',
            'page_flow_role' => 'support',
        ], ['section_template' => 'form']));
        self::assertTrue($probe('content/contact-page-support-faq', [
            'task_key' => 'page:contact_page:content/contact-page-support-faq',
            'block_key' => 'support_faq',
            'block_type' => 'support_faq',
            'page_flow_role' => 'support',
        ], ['section_template' => 'faq']));
        self::assertFalse($probe('content/contact-page-contact-cta', [
            'task_key' => 'page:contact_page:content/contact-page-contact-cta',
            'block_key' => 'contact_cta',
            'block_type' => 'contact_cta',
            'page_flow_role' => 'cta',
        ], ['section_template' => 'cta']));
    }

    public function testCtaBuildQueueGenerateComponentDoesNotNeedAiProvider(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $result = (function (): array {
            return $this->generateComponent(
                'content/home-page-final-cta',
                'Ready to see it in action?',
                'content',
                'Prompt that would normally call the provider',
                [
                    'content.title' => 'Ready to see it in action?',
                    'content.description' => 'See how automated approvals replace spreadsheet follow-up.',
                    'cta.text' => 'Request a demo',
                    'cta.url' => '/contact',
                    'runtime.section_name' => 'Final CTA',
                ],
                [
                    AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
                    'build_plan_task' => [
                        'task_key' => 'page:home_page:content/home-page-final-cta',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-final-cta',
                        'block_key' => 'final_cta',
                        'block_type' => 'final_cta',
                        'page_flow_role' => 'cta',
                    ],
                    'visual_contract' => [
                        'strict_hero_cover' => 0,
                        'page_flow_role' => 'cta',
                        'section_template' => 'cta',
                    ],
                ]
            );
        })->call($service);

        self::assertSame('content/home-page-final-cta', $result['code']);
        self::assertStringContainsString('content.title => Title:text:Ready to see it in action?', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('cta.url => CTA URL:text:/contact', $result['ai_data']['extra_fields'] ?? '');
        self::assertStringContainsString('$ctaUrl = $getConfig(\'cta.url\', \'/contact\');', $result['ai_data']['php_variables'] ?? '');
        self::assertStringContainsString("class='pb-c-cta' href='<?= htmlspecialchars(\$ctaUrl ?? '/contact'", $result['ai_data']['html_content'] ?? '');
        self::assertStringContainsString('pb-c-root', $result['phtml']);
    }

    public function testProofCardGridBuildQueueGenerateComponentDoesNotNeedAiProvider(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $defaultConfig = [
            'content.title' => 'Map, automate, measure',
            'content.description' => '',
            'runtime.section_name' => 'Map, automate, measure',
        ];
        $renderContext = [
            AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
            'build_plan_task' => [
                'task_key' => 'page:home_page:content/home-page-brand-promise',
                'page_type' => 'home_page',
                'section_code' => 'content/home-page-brand-promise',
                'block_key' => 'brand_promise',
                'block_type' => 'brand_promise',
                'page_flow_role' => 'proof',
                'block_task' => [
                    'task_goal' => 'Replace manual handoff tracking with AI that maps, routes, and reports.',
                ],
                'visual_signature' => [
                    'composition_pattern' => 'three-column card grid with teal left accents',
                    'spatial_rhythm' => 'three compact cards in a row with equal height',
                ],
            ],
            'visual_contract' => [
                'strict_hero_cover' => 0,
                'page_flow_role' => 'proof',
                'section_template' => 'section',
            ],
        ];

        $probe = (function () use ($defaultConfig, $renderContext): array {
            $matches = $this->shouldCompileDeterministicCardGridComponent(
                'content/home-page-brand-promise',
                'content',
                $defaultConfig,
                $renderContext
            );
            $payload = $this->buildDeterministicCardGridAiData(
                'content/home-page-brand-promise',
                $defaultConfig,
                $renderContext
            );
            $this->assertRenderedHtmlPassesBuildQualityGate(
                'content/home-page-brand-promise',
                (string)($payload['html_content'] ?? ''),
                'content/home-page-brand-promise page_flow_role: proof block_type: brand_promise',
                \trim((string)($payload['css_extra'] ?? '') . "\n" . (string)($payload['css_responsive'] ?? '')),
                $renderContext
            );

            return [$matches, $payload];
        })->call($service);
        [$matches, $aiData] = $probe;

        self::assertTrue($matches);
        self::assertStringContainsString('content.description => Description:textarea:Replace manual handoff tracking', $aiData['extra_fields'] ?? '');
        self::assertStringContainsString('card.item_1_title => Card 1 title:text:Clear Path', $aiData['extra_fields'] ?? '');
        self::assertStringContainsString('card.item_2_title => Card 2 title:text:Guided Action', $aiData['extra_fields'] ?? '');
        self::assertStringContainsString('card.item_3_title => Card 3 title:text:Visitor confidence', $aiData['extra_fields'] ?? '');
        self::assertStringContainsString('$cardItem1Title = $getConfig(\'card.item_1_title\', \'Clear Path\');', $aiData['php_variables'] ?? '');
        self::assertStringContainsString("class='pb-c-grid'", $aiData['html_content'] ?? '');
    }

    public function testCardGridCompilerDoesNotOwnSupportFaqContactOrCtaBlocks(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $probe = static function (string $componentCode, array $task, array $visualContract = []) use ($service): bool {
            return (function () use ($componentCode, $task, $visualContract): bool {
                return $this->shouldCompileDeterministicCardGridComponent(
                    $componentCode,
                    'content',
                    [],
                    [
                        AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
                        'build_plan_task' => $task,
                        'visual_contract' => $visualContract,
                    ]
                );
            })->call($service);
        };

        self::assertFalse($probe('content/contact-page-contact-methods', [
            'task_key' => 'page:contact_page:content/contact-page-contact-methods',
            'block_key' => 'contact_methods',
            'block_type' => 'contact_methods',
            'page_flow_role' => 'support',
        ]));
        self::assertFalse($probe('content/contact-page-support-faq', [
            'task_key' => 'page:contact_page:content/contact-page-support-faq',
            'block_key' => 'support_faq',
            'block_type' => 'support_faq',
            'page_flow_role' => 'support',
        ]));
        self::assertFalse($probe('content/home-page-final-cta', [
            'task_key' => 'page:home_page:content/home-page-final-cta',
            'block_key' => 'final_cta',
            'block_type' => 'final_cta',
            'page_flow_role' => 'cta',
        ], ['section_template' => 'cta']));
    }

    public function testNarrativePanelCompilerOwnsOnlyStoryIntroBlocks(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $probe = static function (string $componentCode, array $task, array $visualContract = []) use ($service): bool {
            return (function () use ($componentCode, $task, $visualContract): bool {
                return $this->shouldCompileDeterministicNarrativePanelComponent(
                    $componentCode,
                    'content',
                    [],
                    [
                        AiSitePageComponentGenerationService::RENDER_CONTEXT_ALLOW_DETERMINISTIC_CONTENT_COMPILER => true,
                        'build_plan_task' => $task,
                        'visual_contract' => $visualContract,
                    ]
                );
            })->call($service);
        };

        self::assertTrue($probe('content/about-page-origin-story', [
            'task_key' => 'page:about_page:content/about-page-origin-story',
            'block_key' => 'origin_story',
            'block_type' => 'origin_story',
            'page_flow_role' => 'opening',
        ]));
        self::assertTrue($probe('content/custom-page-primary-story', [
            'task_key' => 'page:custom_page:content/custom-page-primary-story',
            'block_key' => 'primary_story',
            'block_type' => 'primary_story',
            'page_flow_role' => 'details',
        ]));
        self::assertFalse($probe('content/contact-page-contact-methods', [
            'task_key' => 'page:contact_page:content/contact-page-contact-methods',
            'block_key' => 'contact_methods',
            'block_type' => 'contact_methods',
            'page_flow_role' => 'support',
        ]));
        self::assertFalse($probe('content/contact-page-support-faq', [
            'task_key' => 'page:contact_page:content/contact-page-support-faq',
            'block_key' => 'support_faq',
            'block_type' => 'support_faq',
            'page_flow_role' => 'support',
        ]));
        self::assertFalse($probe('content/home-page-final-cta', [
            'task_key' => 'page:home_page:content/home-page-final-cta',
            'block_key' => 'final_cta',
            'block_type' => 'final_cta',
            'page_flow_role' => 'cta',
        ], ['section_template' => 'cta']));
    }

    public function testComponentGenerationRequestsStrictJsonSchemaEnvelope(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $format = (function (): array {
            return $this->buildComponentResponseFormat('content');
        })->call($service);

        self::assertSame('json_schema', $format['type'] ?? null);
        self::assertTrue((bool)($format['json_schema']['strict'] ?? false));
        self::assertFalse((bool)($format['json_schema']['schema']['additionalProperties'] ?? true));
        self::assertSame(
            ['extra_fields', 'php_variables', 'css_extra', 'css_responsive', 'html_content', 'js_content'],
            $format['json_schema']['schema']['required'] ?? []
        );
    }

    public function testComponentJsonGuardForbidsTopLevelPhpTransport(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $prompt = (function (): string {
            return $this->prependComponentJsonOnlyGuard('Base prompt', true);
        })->call($service);

        self::assertStringContainsString('never start the response with `<?php`', $prompt);
        self::assertStringContainsString('The raw final response must start with `{`', $prompt);
        self::assertStringContainsString('php_variables` is a JSON string containing assignment lines only', $prompt);
        self::assertStringContainsString('Never output bare locale words such as', $prompt);
    }

    public function testActionContractFailuresAreRetryable(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $retryable = (function (): bool {
            return $this->shouldRetryAiComponentGeneration(
                new \RuntimeException(
                    'AI component CTA/action contract failed: CTA must be a real anchor with href or button with data-pb-ai-action'
                )
            );
        })->call($service);

        self::assertTrue($retryable);
    }

    public function testCtaActionContractAcceptsPhpHrefBeforeClassAttribute(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $html = <<<'HTML'
<section class='pb-c-root'><div class='pb-c-action'><a href='<?= htmlspecialchars($ctaUrl ?? '/contact', ENT_QUOTES, 'UTF-8') ?>' class='pb-c-cta'><?= htmlspecialchars($ctaText ?? 'Contact Us', ENT_QUOTES, 'UTF-8') ?></a></div></section>
HTML;

        $reason = (function (string $html): ?string {
            return $this->detectCtaActionContractViolation($html, 'content/contact-page-contact-cta');
        })->call($service, $html);

        self::assertNull($reason);
    }

    public function testGeneratedCssContrastGateDoesNotBlockDarkTextOnDarkBackground(): void
    {
        $service = new AiSitePageComponentGenerationService();

        (function (): void {
            $this->assertGeneratedCssTextContrastContract([
                'css_extra' => '#componentId .pb-c-root{background:#111827;color:#1f2937;}#componentId .pb-c-title{color:#0f172a;}',
                'css_content' => '',
            ], 'content/home-hero');
        })->call($service);

        self::assertTrue(true);
    }

    public function testGeneratedCssContrastGateAcceptsLightTextOnDarkBackground(): void
    {
        $service = new AiSitePageComponentGenerationService();

        (function (): void {
            $this->assertGeneratedCssTextContrastContract([
                'css_extra' => '#componentId .pb-c-root{background:#111827;color:#f8fafc;}#componentId .pb-c-title{color:#ffffff;}',
                'css_content' => '',
            ], 'content/home-hero');
        })->call($service);

        self::assertTrue(true);
    }

    public function testRenderedQualityGateRejectsFluidFontSizeCss(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('font-size must use fixed breakpoint values');

        (function (): void {
            $this->assertRenderedHtmlPassesBuildQualityGate(
                'content/support-faq',
                '<section class="pb-c-root"><h2 class="pb-c-title">Support questions</h2></section>',
                '',
                '#componentId .pb-c-title{font-size:clamp(2rem,5vw,2.8rem);}',
                []
            );
        })->call($service);
    }

    public function testRenderedQualityGateRequiresH1ForOpeningSections(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('opening/page-intro section must render one h1 heading');

        (function (): void {
            $this->assertRenderedHtmlPassesBuildQualityGate(
                'content/home-page-hero',
                '<section class="pb-c-root"><h2 class="pb-c-title">Launch reliable AI workflows</h2></section>',
                '',
                '#componentId .pb-c-title{font-size:52px;}',
                [
                    '_build_plan_task' => [
                        'section_template' => 'hero',
                        'page_flow_role' => 'opening',
                    ],
                ]
            );
        })->call($service);
    }

    public function testRenderedQualityGateAcceptsFixedCssAndH1ForOpeningSections(): void
    {
        $service = new AiSitePageComponentGenerationService();

        (function (): void {
            $this->assertRenderedHtmlPassesBuildQualityGate(
                'content/home-page-hero',
                '<section class="pb-c-root"><h1 class="pb-c-title">Launch reliable AI workflows</h1></section>',
                '',
                '#componentId .pb-c-title{font-size:52px;letter-spacing:0;}',
                [
                    '_build_plan_task' => [
                        'section_template' => 'hero',
                        'page_flow_role' => 'opening',
                    ],
                ]
            );
        })->call($service);

        self::assertTrue(true);
    }

    public function testOpeningPayloadH2TitleIsNormalizedToH1BeforeRendering(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $payload = [
            'html_content' => '<section class="pb-c-root"><h2 class="pb-c-title">Launch reliable AI workflows</h2><p>Ready.</p></section>',
        ];

        $fixed = (function (array $payload): array {
            return $this->normalizeRequiredPrimaryHeadingInAiPayload(
                $payload,
                'content/home-page-hero',
                'content/home-page-hero',
                [
                    '_build_plan_task' => [
                        'section_template' => 'hero',
                        'page_flow_role' => 'opening',
                    ],
                ]
            );
        })->call($service, $payload);

        self::assertStringContainsString('<h1 class="pb-c-title">Launch reliable AI workflows</h1>', (string)($fixed['html_content'] ?? ''));
        self::assertStringNotContainsString('<h2 class="pb-c-title">Launch reliable AI workflows</h2>', (string)($fixed['html_content'] ?? ''));
    }

    public function testFirstPageSectionRequiresH1EvenWhenPlanRoleIsSupport(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $scope = $this->buildPlanV2ScopeForContactFirstSection(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('opening/page-intro section must render one h1 heading');

        (function () use ($scope): void {
            $this->assertRenderedHtmlPassesBuildQualityGate(
                'content/contact-page-contact-methods',
                '<section class="pb-c-root"><h2 class="pb-c-title">Contact our operations team</h2></section>',
                '',
                '#componentId .pb-c-title{font-size:52px;}',
                [
                    '_build_plan_task' => [
                        'task_key' => 'page:contact_page:content/contact-page-contact-methods',
                        'task_type' => 'page_section',
                        'page_type' => 'contact_page',
                        'section_code' => 'content/contact-page-contact-methods',
                        'page_flow_role' => 'support',
                        'sort_order' => 260,
                    ],
                    '_scope' => $scope,
                ]
            );
        })->call($service);
    }

    public function testFirstPageSectionPayloadH2TitleIsNormalizedToH1(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $scope = $this->buildPlanV2ScopeForContactFirstSection(true);
        $payload = [
            'html_content' => '<section class="pb-c-root"><h2 class="pb-c-title">Contact our operations team</h2><p>Reach us.</p></section>',
        ];

        $fixed = (function (array $payload) use ($scope): array {
            return $this->normalizeRequiredPrimaryHeadingInAiPayload(
                $payload,
                'content/contact-page-contact-methods',
                'content/contact-page-contact-methods',
                [
                    '_build_plan_task' => [
                        'task_key' => 'page:contact_page:content/contact-page-contact-methods',
                        'task_type' => 'page_section',
                        'page_type' => 'contact_page',
                        'section_code' => 'content/contact-page-contact-methods',
                        'page_flow_role' => 'support',
                        'sort_order' => 260,
                    ],
                    '_scope' => $scope,
                ]
            );
        })->call($service, $payload);

        self::assertStringContainsString('<h1 class="pb-c-title">Contact our operations team</h1>', (string)($fixed['html_content'] ?? ''));
        self::assertStringNotContainsString('<h2 class="pb-c-title">Contact our operations team</h2>', (string)($fixed['html_content'] ?? ''));
    }

    public function testFirstPageSectionPayloadPlainH2IsNormalizedToH1(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $scope = $this->buildPlanV2ScopeForContactFirstSection(false);
        $payload = [
            'html_content' => '<section class="pb-c-root"><div class="pb-c-text-panel"><h2>Contact our operations team</h2><p>Reach us.</p></div></section>',
        ];

        $fixed = (function (array $payload) use ($scope): array {
            return $this->normalizeRequiredPrimaryHeadingInAiPayload(
                $payload,
                'content/contact-page-contact-methods',
                'content/contact-page-contact-methods',
                [
                    '_build_plan_task' => [
                        'task_key' => 'page:contact_page:content/contact-page-contact-methods',
                        'task_type' => 'page_section',
                        'page_type' => 'contact_page',
                        'section_code' => 'content/contact-page-contact-methods',
                        'page_flow_role' => 'support',
                        'sort_order' => 260,
                    ],
                    '_scope' => $scope,
                ]
            );
        })->call($service, $payload);

        self::assertStringContainsString('<h1>Contact our operations team</h1>', (string)($fixed['html_content'] ?? ''));
        self::assertStringNotContainsString('<h2>Contact our operations team</h2>', (string)($fixed['html_content'] ?? ''));
    }

    public function testContrastHardGateIsPresentInComponentPrompt(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../Service/AiSitePageComponentGenerationService.php'
        );

        self::assertStringContainsString('TEXT_CONTRAST_HARD_GATE', $source);
        self::assertStringContainsString('Normal text contrast must be >= 4.5:1', $source);
    }

    public function testComponentGenerationHasEnoughRecoveryAttemptsForJsonTransportFailures(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../Service/AiSitePageComponentGenerationService.php'
        );

        self::assertStringContainsString('private const COMPONENT_GENERATION_MAX_ATTEMPTS = 3;', $source);
        self::assertStringContainsString('$attempt < self::COMPONENT_GENERATION_MAX_ATTEMPTS', $source);
        self::assertStringContainsString('FAILURE_FIX_JSON_TRANSPORT_PREFIX', $source);
    }

    public function testPhpPrefixedJsonFailureAddsTransportRecoveryContract(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $prompt = (function (): string {
            return $this->buildFailureSpecificRecoveryContract(
                new \RuntimeException('AI did not return a valid component JSON payload: component_json.parse found=<?php {"extra_fields": "..."}'),
                'content/blog-post-related-resources',
                'pb-c',
                false,
                []
            );
        })->call($service);

        self::assertStringContainsString('FAILURE_FIX_JSON_TRANSPORT_PREFIX', $prompt);
        self::assertStringContainsString('first byte must be `{`', $prompt);
        self::assertStringContainsString('Do not output `<?php`', $prompt);
    }

    public function testFormEmailPlaceholdersAreExplicitlyForbidden(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../Service/AiSitePageComponentGenerationService.php'
        );

        self::assertStringContainsString('FAILURE_FIX_FORM_EMAIL_PLACEHOLDER', $source);
        self::assertStringContainsString('Form email inputs may exist', $source);
        self::assertStringContainsString('email placeholders/defaults must be localized words with no `@`', $source);
    }

    public function testFormGuidanceNotesAreExplicitEditableFields(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../Service/AiSitePageComponentGenerationService.php'
        );

        self::assertStringContainsString('form.note_text', $source);
        self::assertStringContainsString('privacy/security note', $source);
        self::assertStringContainsString('small microcopy', $source);
    }

    public function testEditableFieldHardcodedTextGuardIgnoresBoundPhpEchoAttributes(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $html = <<<'HTML'
<section class='pb-c-root'><div class='pb-c-inner'><h2 class='pb-c-title'><?= htmlspecialchars($contentTitle ?? 'Send us a message', ENT_QUOTES, 'UTF-8') ?></h2><form class='pb-c-form' action='<?= htmlspecialchars($ctaUrl ?? '/contact', ENT_QUOTES, 'UTF-8') ?>' method='post'><label class='pb-c-label'><?= htmlspecialchars($formLabel1 ?? 'Name', ENT_QUOTES, 'UTF-8') ?></label><input class='pb-c-input' type='text' placeholder='<?= htmlspecialchars($formPlaceholder1 ?? 'Your full name', ENT_QUOTES, 'UTF-8') ?>'><button type='submit' class='pb-c-cta'><?= htmlspecialchars($ctaText ?? 'Send Message', ENT_QUOTES, 'UTF-8') ?></button></form></div></section>
HTML;

        $literalText = (function (string $html): string {
            return $this->extractVirtualThemeHardcodedVisibleText($html);
        })->call($service, $html);

        self::assertSame('', $literalText);

        $encodedLiteralText = (function (string $html): string {
            return $this->extractVirtualThemeHardcodedVisibleText($html);
        })->call($service, \htmlspecialchars($html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));

        self::assertSame('', $encodedLiteralText);
    }

    public function testGeneratedCssNormalizationClipsOnlyAtCompleteRuleBoundary(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $css = '#componentId .pb-c-root{display:block;}'
            . '#componentId .pb-c-cta{display:inline-flex;width:auto;max-width:' . \str_repeat('1', 120) . 'px;}';

        $normalized = (function (string $css): string {
            return $this->normalizeVirtualThemeCssForValidation($css, 78, 'css_extra');
        })->call($service, $css);

        self::assertStringContainsString('.pb-c-root', $normalized);
        self::assertStringNotContainsString('max-width:', $normalized);
        self::assertStringEndsWith('}', $normalized);
    }

    public function testResponsiveContractRejectsFaqSplitLayoutWithoutMobileStack(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('multi-column generated layouts must collapse to one readable mobile column');

        (function (): void {
            $this->assertGeneratedComponentResponsiveContract([
                'css_extra' => '#componentId .pb-c-content{display:grid;grid-template-columns:1fr 1.6fr;gap:32px;}',
                'css_responsive' => '@media (max-width:768px){#componentId .pb-c-root{padding:40px 18px;}}'
                    . '@media (max-width:420px){#componentId .pb-c-root{padding:32px 14px;}}',
            ], 'content', 'content/contact-page-support-faq');
        })->call($service);
    }

    public function testResponsiveContractAcceptsFaqReadableMobileStack(): void
    {
        $service = new AiSitePageComponentGenerationService();

        (function (): void {
            $this->assertGeneratedComponentResponsiveContract([
                'css_extra' => '#componentId .pb-c-content{display:grid;grid-template-columns:1fr 1.6fr;gap:32px;}',
                'css_responsive' => '@media (max-width:768px){#componentId .pb-c-content{grid-template-columns:1fr;}}'
                    . '@media (max-width:420px){#componentId .pb-c-root{padding:32px 14px;}#componentId .pb-c-content{grid-template-columns:1fr;}}',
            ], 'content', 'content/contact-page-support-faq');
        })->call($service);

        self::assertTrue(true);
    }

    public function testHeaderFrameworkCtaTextColorIsContrastAware(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../view/templates/style/_ai_frameworks/header_framework.phtml'
        );

        self::assertStringContainsString("style.cta_text_color", $source);
        self::assertStringContainsString('$pickReadableTextColor', $source);
        self::assertStringContainsString('$ensureReadableColor', $source);
        self::assertStringContainsString('$contrastRatio($background, $candidate) >= 4.5', $source);
        self::assertStringContainsString('color: <?= htmlspecialchars($ctaTextColor) ?>;', $source);
        self::assertStringNotContainsString("-cta {\n    padding: 10px 22px;\n    background: var(--header-primary);\n    color: #ffffff;", $source);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPlanV2ScopeForContactFirstSection(bool $includeFaq): array
    {
        $blocks = [
            [
                'block_id' => 'contact_page.contact_methods',
                'page_id' => 'contact_page',
                'page_type' => 'contact_page',
                'section_key' => 'contact_methods',
                'block_type' => 'contact_methods',
                'page_flow_role' => 'support',
                'sort_order' => 260,
            ],
        ];
        $pageBlocks = ['contact_page.contact_methods'];

        if ($includeFaq) {
            $blocks[] = [
                'block_id' => 'contact_page.support_faq',
                'page_id' => 'contact_page',
                'page_type' => 'contact_page',
                'section_key' => 'support_faq',
                'block_type' => 'support_faq',
                'page_flow_role' => 'support',
                'sort_order' => 280,
            ];
            $pageBlocks[] = 'contact_page.support_faq';
        }

        return [
            'build_plan_confirmed' => 1,
            'build_plan_v2' => [
                'contract_meta' => [
                    'id' => 'test-build-plan-v2',
                    'type' => 'build_plan_v2',
                    'version' => '2.2',
                    'status' => 'confirmed',
                ],
                'i18n' => ['primary_locale' => 'en_US'],
                'site_brief' => ['site_name' => 'Example Site'],
                'content_manifest' => [
                    'primary_locale' => 'en_US',
                    'items' => [
                        'page.contact_page.title' => 'Contact',
                        'block.contact_page.contact_methods.title' => 'Contact our operations team',
                        'block.contact_page.support_faq.title' => 'Answers before the first workflow review',
                    ],
                ],
                'pages' => [
                    [
                        'page_id' => 'contact_page',
                        'page_type' => 'contact_page',
                        'title_key' => 'page.contact_page.title',
                        'blocks' => $pageBlocks,
                    ],
                ],
                'blocks' => $blocks,
                'design_manifest' => [
                    'palette' => [
                        'surface' => '#111827',
                        'surface_alt' => '#1f2937',
                        'text' => '#f8fafc',
                        'primary' => '#22d3ee',
                        'accent' => '#f59e0b',
                    ],
                ],
            ],
        ];
    }
}
