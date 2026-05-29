<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;

final class AiSitePageComponentGenerationJsonOnlyGateTest extends TestCase
{
    public function testContentBlockGenerationOnlyRequiresJsonStringEnvelope(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .hero { color:#111; broken-css',
            'css_responsive' => '',
            'html_content' => '<section class="hero">< class="odd">Dynamic AI copy <img src="https://example.com/unverified.jpg"></section>',
            'js_content' => '',
        ];

        $validated = $this->ensureContentPayloadValid($service, $payload);

        self::assertStringContainsString('Dynamic AI copy', (string)($validated['html_content'] ?? ''));
        self::assertStringContainsString('unverified.jpg', (string)($validated['html_content'] ?? ''));
        self::assertStringContainsString('broken-css', (string)($validated['css_extra'] ?? ''));
    }

    public function testContentBlockGenerationStillRejectsMissingHtmlContentJsonString(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $this->expectException(AiSiteComponentContractException::class);
        $this->expectExceptionMessage('JSON structure validation failed');

        $this->ensureContentPayloadValid($service, [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '',
            'css_responsive' => '',
            'js_content' => '',
        ]);
    }

    public function testContentBlockArtifactBypassesHardcodedRenderedGatesAfterJsonEnvelopeValidation(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $artifact = $this->buildContentArtifactFromPayload($service, [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-c-title{font-size:clamp(2rem,5vw,3rem);letter-spacing:-.02em;}',
            'css_responsive' => '',
            'html_content' => '<section class="pb-c-root"><h2 class="pb-c-title">中文规划文案 REQUIRED_IMAGE_STRUCTURE_CONTRACT</h2><p>字段与文案由 AI 按方案块自由生成。</p></section>',
            'js_content' => '',
        ]);

        self::assertSame('content/home-page-hero', $artifact['code']);
        self::assertStringContainsString('中文规划文案', (string)($artifact['html'] ?? ''));
        self::assertStringContainsString('REQUIRED_IMAGE_STRUCTURE_CONTRACT', (string)($artifact['html'] ?? ''));
    }

    public function testContentHeroImageContractDoesNotBlockAfterJsonEnvelopeValidation(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $artifact = $this->buildContentArtifactFromPayload(
            $service,
            [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '.pb-free-hero{display:grid;gap:24px;}',
                'css_responsive' => '',
                'html_content' => '<section class="pb-free-hero"><h2>OpsFlow operating layer</h2><p>Image binding can be retried later; content generation should continue.</p></section>',
                'js_content' => '',
            ],
            [
                'runtime.section_template' => 'hero',
                'runtime.section_image_url' => '/pub/media/page-build/site/ai-generated/hero.webp',
                'runtime.section_image_slot_id' => 'page:home_page:content-home-page-hero',
            ],
            [
                '_required_image_assets' => [
                    'page:home_page:content-home-page-hero' => '/pub/media/page-build/site/ai-generated/hero.webp',
                ],
            ]
        );

        self::assertSame('content/home-page-hero', $artifact['code']);
        self::assertStringContainsString('Image binding can be retried later', (string)($artifact['html'] ?? ''));
    }

    public function testSharedFooterBuildQueueUsesDeterministicSchemaOnlyPayload(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $artifact = (function (): array {
            return $this->generateComponent(
                'footer/ai-site-footer',
                'AI Site Footer',
                'footer',
                'Generate footer JSON only.',
                [
                    'brand.name' => "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}",
                    'brand.description' => "\u{6697}\u{8272}\u{9713}\u{8679}\u{724C}\u{684C}\u{3001}\u{7B79}\u{7801}\u{5149}\u{6548}\u{4E0E}\u{6E38}\u{620F}\u{623F}\u{95F4}\u{5165}\u{53E3}\u{3002}",
                    'links.column1_title' => "\u{63A8}\u{8350}\u{9875}\u{9762}",
                    'links.column1_items' => "\u{9996}\u{9875}=>/\n\u{8054}\u{7CFB}\u{652F}\u{6301}=>/contact",
                    'links.column2_title' => "\u{89C4}\u{5219}\u{4E0E}\u{653F}\u{7B56}",
                    'links.column2_items' => "\u{9690}\u{79C1}\u{653F}\u{7B56}=>/privacy",
                    'links.column3_title' => "\u{5168}\u{90E8}\u{9875}\u{9762}",
                    'links.column3_items' => "\u{9996}\u{9875}=>/",
                    'copyright.text' => "\u{4FDD}\u{7559}\u{6240}\u{6709}\u{6743}\u{5229}\u{3002}",
                    'runtime.shared_region' => 'footer',
                    'runtime.content_locale' => 'zh_Hans_CN',
                    'runtime.build_plan_task_json' => (string)\json_encode([
                        'task_key' => 'shared:footer',
                        'region' => 'footer',
                    ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                ],
                [
                    'content_locale' => 'zh_Hans_CN',
                    '_content_locale' => 'zh_Hans_CN',
                    '_scope' => [
                        'website_profile' => [
                            'site_title' => "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}",
                            'brief_description' => "\u{6697}\u{8272}\u{9713}\u{8679}\u{68CB}\u{724C}\u{5A31}\u{4E50}\u{7AD9}\u{70B9}",
                        ],
                    ],
                    'build_plan_task' => [
                        'task_key' => 'shared:footer',
                        'region' => 'footer',
                    ],
                ]
            );
        })->call($service);

        $aiData = $artifact['ai_data'] ?? [];
        self::assertSame('', $aiData['html_extra_column'] ?? null);
        self::assertSame('', $aiData['html_extra'] ?? null);
        self::assertSame('', $aiData['css_extra'] ?? null);
        self::assertSame('', $aiData['js_content'] ?? null);
        self::assertSame("\u{516C}\u{5E73}\u{724C}\u{5C40} \u{00B7} \u{6E05}\u{6670}\u{89C4}\u{5219} \u{00B7} \u{9713}\u{8679}\u{724C}\u{684C}\u{4F53}\u{9A8C}", $aiData['footer_extra_text'] ?? null);
        self::assertStringContainsString("\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}", (string)($artifact['html'] ?? ''));
        self::assertStringContainsString("\u{516C}\u{5E73}\u{724C}\u{5C40}", (string)($artifact['html'] ?? ''));
    }

    public function testFaqRulesBuildQueueUsesDeterministicAccordionPayload(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $buildPlanTask = [
            'task_key' => 'page:home_page:content/home-page-faq-or-rules',
            'page_type' => 'home_page',
            'section_code' => 'content/home-page-faq-or-rules',
            'block_key' => 'faq_or_rules',
            'block_type' => 'faq_or_rules',
            'page_flow_role' => 'details',
            'block_task' => [
                'task_goal' => "\u{73A9}\u{5BB6}\u{53EF}\u{9010}\u{6761}\u{67E5}\u{770B}\u{623F}\u{95F4}\u{89C4}\u{5219}\u{3001}\u{516C}\u{5E73}\u{6280}\u{672F}\u{8BF4}\u{660E}\u{548C}\u{8D26}\u{6237}\u{5B89}\u{5168}\u{7B56}\u{7565}\u{3002}",
            ],
        ];

        $artifact = (function (array $buildPlanTask): array {
            return $this->generateComponent(
                'content/home-page-faq-or-rules',
                'FAQ Rules',
                'content',
                'Generate FAQ/rules section JSON only.',
                [
                    'content.title' => "\u{5165}\u{5EA7}\u{524D}\u{FF0C}\u{5148}\u{770B}\u{6E05}\u{89C4}\u{5219}",
                    'content.description' => "\u{73A9}\u{5BB6}\u{53EF}\u{9010}\u{6761}\u{67E5}\u{770B}\u{623F}\u{95F4}\u{89C4}\u{5219}\u{3001}\u{516C}\u{5E73}\u{6280}\u{672F}\u{8BF4}\u{660E}\u{548C}\u{8D26}\u{6237}\u{5B89}\u{5168}\u{7B56}\u{7565}\u{3002}",
                    'runtime.content_locale' => 'zh_Hans_CN',
                    'runtime.section_name' => 'FAQ Rules',
                    'runtime.build_plan_task_json' => (string)\json_encode($buildPlanTask, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                ],
                [
                    'content_locale' => 'zh_Hans_CN',
                    '_content_locale' => 'zh_Hans_CN',
                    '_scope' => [
                        'website_profile' => [
                            'site_title' => "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}",
                            'brief_description' => "\u{6697}\u{8272}\u{9713}\u{8679}\u{68CB}\u{724C}\u{5A31}\u{4E50}\u{7AD9}\u{70B9}",
                        ],
                    ],
                    'build_plan_task' => $buildPlanTask,
                ]
            );
        })->call($service, $buildPlanTask);

        $aiData = $artifact['ai_data'] ?? [];
        self::assertStringContainsString('faq.item_1_question', (string)($aiData['extra_fields'] ?? ''));
        self::assertStringContainsString('<details', (string)($aiData['html_content'] ?? ''));
        self::assertStringContainsString("\u{89C4}\u{5219}\u{6838}\u{5BF9}", (string)($aiData['html_content'] ?? ''));
        self::assertStringNotContainsString('RULE CHECK', (string)($aiData['html_content'] ?? ''));
        self::assertStringContainsString("\u{5165}\u{5EA7}\u{524D}", (string)($artifact['html'] ?? ''));
        self::assertStringContainsString("\u{73A9}\u{5BB6}", (string)($artifact['html'] ?? ''));
    }

    public function testCardGridCompilerSynthesizesDistinctTitlesInsteadOfRepeatingPlanSentences(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $payload = (function (): array {
            return $this->buildDeterministicCardGridAiData(
                'content/blog-list-resource-grid',
                [
                    'content.title' => 'Latest operations guides',
                    'content.description' => 'Position OpsFlow as the go-to resource for operational leaders seeking approval automation insights and subscriber conversion.',
                    'runtime.section_name' => 'Latest operations guides',
                ],
                [
                    'build_plan_task' => [
                        'task_key' => 'page:blog_list:content/blog-list-resource-grid',
                        'page_type' => 'blog_list',
                        'section_code' => 'content/blog-list-resource-grid',
                        'block_key' => 'resource_grid',
                        'block_type' => 'resource_grid',
                        'page_flow_role' => 'resource_list',
                    ],
                    'visual_contract' => [
                        'page_flow_role' => 'resource_list',
                    ],
                ]
            );
        })->call($service);

        $extraFields = (string)($payload['extra_fields'] ?? '');

        self::assertStringContainsString('card.item_1_title => Card 1 title:text:Practical guide', $extraFields);
        self::assertStringContainsString('card.item_2_title => Card 2 title:text:Decision checklist', $extraFields);
        self::assertStringContainsString('card.item_3_title => Card 3 title:text:Helpful next step', $extraFields);
        self::assertStringNotContainsString('Card 1 title:text:Latest operations guides', $extraFields);
        self::assertStringNotContainsString('subscriber conversion.', $this->extractCardTitleLines($extraFields));
    }

    public function testCardGridTitleNormalizerTurnsFragmentsIntoProductLabels(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $labels = (function (): array {
            return [
                $this->normalizeDeterministicCardGridItemTitle('prove spreadsheet replacement value', 0),
                $this->isWeakDeterministicCardGridItemTitle('Not quarters', 'Operations teams see results in weeks', ''),
                $this->normalizeDeterministicCardGridItemTitle('operational philosophy', 1),
            ];
        })->call($service);

        self::assertSame('Visible proof', $labels[0]);
        self::assertTrue($labels[1]);
        self::assertSame('Operational Philosophy', $labels[2]);
    }

    public function testCardGridCompilerBackfillsUnusedTitlesWhenRawItemsCollapseToSameLabel(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $payload = (function (): array {
            return $this->buildDeterministicCardGridAiData(
                'content/home-page-proof-grid',
                [
                    'content.title' => 'Four pillars of automated ops',
                    'content.description' => 'Capture ops leaders attention, prove spreadsheet replacement value, and convert to demo requests.',
                    'card.item_1_title' => 'prove spreadsheet replacement value',
                    'card.item_1_text' => 'Show measurable workflow progress from the same operating record.',
                    'card.item_2_title' => 'Measure outcomes',
                    'card.item_2_text' => 'Report progress, exceptions, and outcome proof.',
                    'runtime.section_name' => 'Four pillars of automated ops',
                ],
                [
                    'build_plan_task' => [
                        'task_key' => 'page:home_page:content/home-page-proof-grid',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-proof-grid',
                        'block_key' => 'proof_grid',
                        'block_type' => 'proof_grid',
                        'page_flow_role' => 'proof',
                    ],
                ]
            );
        })->call($service);

        $titles = $this->extractCardTitles((string)($payload['extra_fields'] ?? ''));

        self::assertCount(3, $titles);
        self::assertCount(3, \array_unique($titles));
        self::assertContains('Visible proof', $titles);
        self::assertNotSame(['Visible proof', 'Visible proof', 'Visible proof'], $titles);
    }

    public function testCardGridCompilerRewritesGenericPlanGoalCopyIntoRoleSpecificBodyCopy(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $genericPlanCopy = 'Capture ops leaders\' attention with a product-led dashboard visual, prove spreadsheet replacement value, and convert to demo requests.';

        $payload = (function () use ($genericPlanCopy): array {
            return $this->buildDeterministicCardGridAiData(
                'content/home-page-proof-grid',
                [
                    'content.title' => 'Trusted by ops teams scaling fast',
                    'content.description' => $genericPlanCopy,
                    'card.item_1_title' => 'Workflow visibility',
                    'card.item_1_text' => $genericPlanCopy,
                    'card.item_2_title' => 'Fewer manual chasers',
                    'card.item_2_text' => $genericPlanCopy,
                    'card.item_3_title' => 'Decision-ready metrics',
                    'card.item_3_text' => $genericPlanCopy,
                    'runtime.section_name' => 'Trusted by ops teams scaling fast',
                ],
                [
                    'build_plan_task' => [
                        'task_key' => 'page:home_page:content/home-page-proof-grid',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-proof-grid',
                        'block_key' => 'proof_grid',
                        'block_type' => 'proof_grid',
                        'page_flow_role' => 'proof',
                    ],
                ]
            );
        })->call($service);

        $extraFields = (string)($payload['extra_fields'] ?? '');

        self::assertStringNotContainsString($genericPlanCopy, $extraFields);
        self::assertStringContainsString(
            'content.description => Description:textarea:Show the trust signals visitors need before they take action.',
            $extraFields
        );
        self::assertStringContainsString(
            'card.item_1_text => Card 1 text:textarea:Use proof, ratings, and clear support cues to reduce uncertainty.',
            $extraFields
        );
        self::assertStringContainsString(
            'card.item_2_text => Card 2 text:textarea:Keep rules, timing, and expectations visible before the action.',
            $extraFields
        );
        self::assertStringContainsString(
            'card.item_3_text => Card 3 text:textarea:Show where visitors can get help when they need more context.',
            $extraFields
        );
    }

    public function testHeroAndCtaCompilersRewriteGenericPlanGoalCopyIntoVisitorCopy(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $genericPlanCopy = 'Capture ops leaders\' attention with a product-led dashboard visual, prove spreadsheet replacement value, and convert to demo requests.';

        $heroPayload = (function () use ($genericPlanCopy): array {
            return $this->buildDeterministicStrictHeroAiData(
                'content/home-page-hero',
                [
                    'content.title' => 'Automate approvals, eliminate spreadsheet chaos',
                    'content.description' => $genericPlanCopy,
                    'runtime.section_name' => 'Automate approvals, eliminate spreadsheet chaos',
                ],
                [
                    'build_plan_task' => [
                        'task_key' => 'page:home_page:content/home-page-hero',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-hero',
                        'block_key' => 'hero',
                        'block_type' => 'hero',
                        'page_flow_role' => 'opening',
                    ],
                ]
            );
        })->call($service);
        $ctaPayload = (function () use ($genericPlanCopy): array {
            return $this->buildDeterministicCtaAiData(
                'content/home-page-final-cta',
                [
                    'content.title' => 'Ready to see it in action?',
                    'content.description' => $genericPlanCopy,
                    'runtime.section_name' => 'Ready to see it in action?',
                ],
                [
                    'build_plan_task' => [
                        'task_key' => 'page:home_page:content/home-page-final-cta',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-final-cta',
                        'block_key' => 'final_cta',
                        'block_type' => 'final_cta',
                        'page_flow_role' => 'conversion',
                    ],
                ]
            );
        })->call($service);

        $heroFields = (string)($heroPayload['extra_fields'] ?? '');
        $ctaFields = (string)($ctaPayload['extra_fields'] ?? '');
        $heroCss = (string)($heroPayload['css_extra'] ?? '') . "\n" . (string)($heroPayload['css_responsive'] ?? '');
        $ctaCss = (string)($ctaPayload['css_extra'] ?? '') . "\n" . (string)($ctaPayload['css_responsive'] ?? '');

        self::assertStringNotContainsString($genericPlanCopy, $heroFields);
        self::assertStringNotContainsString($genericPlanCopy, $ctaFields);
        self::assertStringContainsString(
            'content.description => Description:textarea:Introduce the offer with a focused promise, credible proof, and one clear action.',
            $heroFields
        );
        self::assertStringContainsString(
            'content.description => Description:textarea:Guide visitors toward the next step with clear context, proof, and support.',
            $ctaFields
        );
        self::assertStringContainsString('.pb-c-inner{display:block;width:100%;', $heroCss);
        self::assertStringContainsString('overflow-wrap:anywhere', $heroCss);
        self::assertStringContainsString('.pb-c-inner{width:100%;grid-template-columns:1fr;', $ctaCss);
        self::assertStringContainsString('overflow-wrap:anywhere', $ctaCss);
    }

    public function testNeonCardScopeUsesNeonGamingFallbackCopyInsteadOfOpsFlowCopy(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $genericPlanCopy = 'Capture ops leaders\' attention with a product-led dashboard visual, prove spreadsheet replacement value, and convert to demo requests.';
        $scope = $this->buildNeonCardBuildPlanV2Scope();

        $heroPayload = (function () use ($genericPlanCopy, $scope): array {
            return $this->buildDeterministicStrictHeroAiData(
                'content/home-page-hero',
                [
                    'content.description' => $genericPlanCopy,
                    'runtime.section_name' => '霓虹棋牌馆',
                ],
                [
                    '_scope' => $scope,
                    'build_plan_task' => [
                        'task_key' => 'page:home_page:content/home-page-hero',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-hero',
                        'block_key' => 'hero',
                        'block_type' => 'hero',
                        'page_flow_role' => 'opening',
                    ],
                ]
            );
        })->call($service);
        $ctaPayload = (function () use ($genericPlanCopy, $scope): array {
            return $this->buildDeterministicCtaAiData(
                'content/home-page-final-cta',
                [
                    'content.description' => $genericPlanCopy,
                    'runtime.section_name' => 'Final CTA',
                ],
                [
                    '_scope' => $scope,
                    'build_plan_task' => [
                        'task_key' => 'page:home_page:content/home-page-final-cta',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-final-cta',
                        'block_key' => 'final_cta',
                        'block_type' => 'final_cta',
                        'page_flow_role' => 'conversion',
                    ],
                ]
            );
        })->call($service);

        $heroFields = (string)($heroPayload['extra_fields'] ?? '');
        $ctaFields = (string)($ctaPayload['extra_fields'] ?? '');

        self::assertStringContainsString('霓虹棋牌馆', $heroFields);
        self::assertStringContainsString('霓虹牌桌', $heroFields);
        self::assertStringContainsString('可信玩法提示', $heroFields);
        self::assertStringContainsString('确认房间', $ctaFields);
        self::assertStringContainsString('开始游戏', $ctaFields);
        self::assertStringNotContainsString('OpsFlow', $heroFields . $ctaFields);
        self::assertStringNotContainsString('approval routing', $heroFields . $ctaFields);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function ensureContentPayloadValid(AiSitePageComponentGenerationService $service, array $payload): array
    {
        $method = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'ensureAiPayloadValid');
        $method->setAccessible(true);

        /** @var array<string,mixed> $validated */
        $validated = $method->invoke($service, $payload, 'content', 'content/test-block');

        return $validated;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function buildContentArtifactFromPayload(
        AiSitePageComponentGenerationService $service,
        array $payload,
        array $defaultConfig = [],
        array $renderContext = []
    ): array
    {
        $method = new \ReflectionMethod(AiSitePageComponentGenerationService::class, 'buildComponentArtifactFromAiData');
        $method->setAccessible(true);

        $renderContext = \array_replace([
            'content_locale' => 'pt_BR',
            '_build_plan_task' => [
                'section_template' => 'hero',
                'page_flow_role' => 'opening',
            ],
        ], $renderContext);

        /** @var array<string,mixed> $artifact */
        $artifact = $method->invoke(
            $service,
            'content/home-page-hero',
            'Hero',
            'content',
            'Generate one free-form content block.',
            $defaultConfig,
            $renderContext,
            $payload
        );

        return $artifact;
    }

    private function extractCardTitleLines(string $extraFields): string
    {
        $lines = [];
        foreach (\preg_split('/\R/u', $extraFields) ?: [] as $line) {
            if (\str_contains((string)$line, '_title =>')) {
                $lines[] = (string)$line;
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function extractCardTitles(string $extraFields): array
    {
        $titles = [];
        foreach (\preg_split('/\R/u', $extraFields) ?: [] as $line) {
            if (\preg_match('/card\\.item_\\d+_title\\s*=>[^:]+:text:(.+)$/u', (string)$line, $match) === 1) {
                $titles[] = \trim((string)$match[1]);
            }
        }

        return $titles;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildNeonCardBuildPlanV2Scope(): array
    {
        return [
            'site_title' => "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}",
            'brief_description' => "\u{6253}\u{9020}\u{4E00}\u{4E2A}\u{9713}\u{8679}\u{68CB}\u{724C}\u{98CE}\u{683C}\u{7684}\u{7EBF}\u{4E0A}\u{5A31}\u{4E50}\u{7F51}\u{7AD9}\u{FF0C}\u{5305}\u{542B}\u{73A9}\u{5BB6}\u{8BC1}\u{660E}\u{3001}\u{73A9}\u{6CD5}\u{4EAE}\u{70B9}\u{3001}\u{653B}\u{7565}\u{5185}\u{5BB9}\u{548C}\u{5BA2}\u{670D}\u{652F}\u{6301}\u{3002}",
            'build_plan_confirmed' => 1,
            'build_plan_v2' => [
                'contract_meta' => [
                    'id' => 'test-neon-card-build-plan-v2',
                    'type' => 'build_plan_v2',
                    'version' => '2.2',
                    'status' => 'confirmed',
                ],
                'i18n' => ['primary_locale' => 'zh_Hans_CN'],
                'site_brief' => [
                    'site_name' => "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}",
                    'summary' => "\u{6697}\u{8272}\u{9713}\u{8679}\u{8D4C}\u{573A}\u{548C}\u{68CB}\u{724C}\u{73A9}\u{6CD5}\u{6307}\u{5F15}\u{3002}",
                ],
                'source_of_truth' => [
                    'user_requirements' => [
                        'site_name' => "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}",
                        'site_goal' => "\u{6697}\u{8272}\u{9713}\u{8679}\u{8D4C}\u{573A}\u{548C}\u{68CB}\u{724C}\u{73A9}\u{6CD5}\u{6307}\u{5F15}\u{3002}",
                        'primary_cta' => "\u{5F00}\u{59CB}\u{6E38}\u{620F}",
                    ],
                ],
                'content_manifest' => [
                    'primary_locale' => 'zh_Hans_CN',
                    'items' => [
                        'page.home_page.title' => "\u{9996}\u{9875}",
                        'block.home_page.hero.title' => "\u{9713}\u{8679}\u{724C}\u{684C}",
                        'block.home_page.hero.copy' => "\u{8FDB}\u{5165}\u{6697}\u{8272}\u{9713}\u{8679}\u{724C}\u{684C}\u{FF0C}\u{4E86}\u{89E3}\u{623F}\u{95F4}\u{3001}\u{89C4}\u{5219}\u{548C}\u{73A9}\u{5BB6}\u{4FE1}\u{4EFB}\u{63D0}\u{793A}\u{3002}",
                        'block.home_page.final_cta.title' => "\u{786E}\u{8BA4}\u{623F}\u{95F4}",
                        'block.home_page.final_cta.copy' => "\u{786E}\u{8BA4}\u{623F}\u{95F4}\u{3001}\u{89C4}\u{5219}\u{548C}\u{652F}\u{6301}\u{5165}\u{53E3}\u{540E}\u{518D}\u{5F00}\u{59CB}\u{6E38}\u{620F}\u{3002}",
                    ],
                ],
                'pages' => [
                    [
                        'page_id' => 'home_page',
                        'page_type' => 'home_page',
                        'title_key' => 'page.home_page.title',
                        'blocks' => ['home_page.hero', 'home_page.final_cta'],
                    ],
                ],
                'blocks' => [
                    [
                        'block_id' => 'home_page.hero',
                        'page_id' => 'home_page',
                        'page_type' => 'home_page',
                        'section_key' => 'hero',
                        'block_type' => 'hero',
                        'page_flow_role' => 'opening',
                        'content_keys' => ['block.home_page.hero.title', 'block.home_page.hero.copy'],
                        'visual_signature' => [
                            'composition_pattern' => 'dark neon casino chess guidance hero',
                            'surface_treatment' => 'deep casino table surface with neon cyan edges',
                        ],
                    ],
                    [
                        'block_id' => 'home_page.final_cta',
                        'page_id' => 'home_page',
                        'page_type' => 'home_page',
                        'section_key' => 'final_cta',
                        'block_type' => 'final_cta',
                        'page_flow_role' => 'conversion',
                        'content_keys' => ['block.home_page.final_cta.title', 'block.home_page.final_cta.copy'],
                        'visual_signature' => [
                            'composition_pattern' => 'dark neon casino chess guidance CTA band',
                            'surface_treatment' => 'glowing card-room panel',
                        ],
                    ],
                ],
                'design_manifest' => [
                    'theme_style' => [
                        'style_signature' => 'dark neon casino chess guidance',
                    ],
                    'visual_contract' => [
                        'style_signature' => 'dark neon casino chess guidance',
                        'visual_keywords' => ['dark', 'neon', 'casino', 'chess', 'card table'],
                    ],
                    'palette' => [
                        'surface' => '#070914',
                        'surface_alt' => '#111827',
                        'text' => '#f8fafc',
                        'muted_text' => '#b6c3d4',
                        'primary' => '#22d3ee',
                        'accent' => '#f59e0b',
                        'shadow' => '#000000',
                    ],
                ],
            ],
        ];
    }
}
