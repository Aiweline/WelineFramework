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

        self::assertStringContainsString('private const COMPONENT_GENERATION_MAX_ATTEMPTS = 2;', $source);
        self::assertStringContainsString('private const AI_REQUEST_TIMEOUT_SECONDS = 600;', $source);
        self::assertStringContainsString('$params[\'enforce_timeout_in_stream\'] = true;', $source);
        self::assertStringNotContainsString('private const COMPONENT_GENERATION_MAX_ATTEMPTS = 5;', $source);
        self::assertStringNotContainsString('private const AI_REQUEST_TIMEOUT_SECONDS = 1800;', $source);
        self::assertStringNotContainsString('$params[\'disable_ai_timeout\'] = true;', $source);
        self::assertStringNotContainsString('$params[\'disable_cli_timeout\'] = true;', $source);
        self::assertStringNotContainsString('$params[\'enforce_timeout_in_stream\'] = false;', $source);
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
        self::assertStringContainsString('private const IMAGE_GENERATION_TIMEOUT_SECONDS = 600;', $assetSource);
        self::assertStringContainsString('private const IMAGE_GENERATION_MAX_ATTEMPTS = 1;', $assetSource);
        self::assertStringContainsString("'image_generation_max_attempts' => 1", $controllerSource);
        self::assertStringContainsString("'image_timeout' => $imageTimeout", $assetSource);
        self::assertStringContainsString("'timeout' => $imageTimeout", $assetSource);
    }

    public function testBuildQueuePromptUsesCompactOnePassContractInsteadOfDuplicatingLongContract(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $prompt = (function (): string {
            return $this->appendComponentCssScopeInstruction(
                "Base section prompt\nStage-2 component output contract V3\nCTX_CURRENT_ASSET\nCTX_FROZEN_TASK",
                'content/home-page-hero',
                [
                    '_plan_json_task' => ['task_key' => 'page:home_page:content/home-page-hero'],
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
                    ['_plan_json_task' => ['task_key' => 'page:home_page:content/home-page-hero']],
                    false
                ),
                'content_build_retry' => $this->resolveComponentGenerationMaxTokens(
                    'content',
                    ['_plan_json_task' => ['task_key' => 'page:home_page:content/home-page-hero']],
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
                    '_plan_json_task' => [
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
                    '_plan_json_task' => [
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
                    '_plan_json_task' => [
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
        $scope = $this->PlanJsonScopeForContactFirstSection(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('opening/page-intro section must render one h1 heading');

        (function () use ($scope): void {
            $this->assertRenderedHtmlPassesBuildQualityGate(
                'content/contact-page-contact-methods',
                '<section class="pb-c-root"><h2 class="pb-c-title">Contact our operations team</h2></section>',
                '',
                '#componentId .pb-c-title{font-size:52px;}',
                [
                    '_plan_json_task' => [
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
        $scope = $this->PlanJsonScopeForContactFirstSection(true);
        $payload = [
            'html_content' => '<section class="pb-c-root"><h2 class="pb-c-title">Contact our operations team</h2><p>Reach us.</p></section>',
        ];

        $fixed = (function (array $payload) use ($scope): array {
            return $this->normalizeRequiredPrimaryHeadingInAiPayload(
                $payload,
                'content/contact-page-contact-methods',
                'content/contact-page-contact-methods',
                [
                    '_plan_json_task' => [
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
        $scope = $this->PlanJsonScopeForContactFirstSection(false);
        $payload = [
            'html_content' => '<section class="pb-c-root"><div class="pb-c-text-panel"><h2>Contact our operations team</h2><p>Reach us.</p></div></section>',
        ];

        $fixed = (function (array $payload) use ($scope): array {
            return $this->normalizeRequiredPrimaryHeadingInAiPayload(
                $payload,
                'content/contact-page-contact-methods',
                'content/contact-page-contact-methods',
                [
                    '_plan_json_task' => [
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

        self::assertStringContainsString('private const COMPONENT_GENERATION_MAX_ATTEMPTS = 2;', $source);
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
        self::assertStringContainsString('first byte must be {', $prompt);
        self::assertStringContainsString('Do not output PHP markers', $prompt);
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
    private function PlanJsonScopeForContactFirstSection(bool $includeFaq): array
    {
        $pageNode = [
            'page_type' => 'contact_page',
            'title_key' => 'page.contact_page.title',
            'contact_methods' => [
                'block_key' => 'contact_methods',
                'block_id' => 'contact_page.contact_methods',
                'page_type' => 'contact_page',
                'section_key' => 'contact_methods',
                'section_code' => 'content/contact-page-contact-methods',
                'block_type' => 'contact_methods',
                'page_flow_role' => 'support',
                'sort_order' => 260,
                'status' => 0,
            ],
        ];

        if ($includeFaq) {
            $pageNode['support_faq'] = [
                'block_key' => 'support_faq',
                'block_id' => 'contact_page.support_faq',
                'page_type' => 'contact_page',
                'section_key' => 'support_faq',
                'section_code' => 'content/contact-page-support-faq',
                'block_type' => 'support_faq',
                'page_flow_role' => 'support',
                'sort_order' => 280,
                'status' => 0,
            ];
        }

        return [
            'plan_json' => [
                'confirmed' => 1,
                'contract_meta' => [
                    'id' => 'test-plan-json-v2',
                    'type' => 'plan_json',
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
                    'contact_page' => $pageNode,
                ],
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
