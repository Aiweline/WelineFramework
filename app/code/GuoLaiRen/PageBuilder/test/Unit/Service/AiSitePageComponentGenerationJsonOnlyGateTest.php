<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;

final class AiSitePageComponentGenerationJsonOnlyGateTest extends TestCase
{
    public function testContentBlockGenerationAllowsJsonStringEnvelopeWithResponsiveCss(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-c-root{color:#111;}#componentId .pb-c-text{display:block;}#componentId .pb-c-img{display:block;}',
            'css_responsive' => '@media (max-width: 768px){#componentId .pb-c-root{display:block;}}@media (max-width: 420px){#componentId .pb-c-root{display:block;}}',
            'html_content' => '<section class="pb-c-root"><p class="pb-c-text">Dynamic AI copy</p><img class="pb-c-img" src="https://example.com/unverified.jpg"></section>',
            'js_content' => '',
        ];

        $validated = $this->ensureContentPayloadValid($service, $payload);

        self::assertStringContainsString('Dynamic AI copy', (string)($validated['html_content'] ?? ''));
        self::assertStringContainsString('unverified.jpg', (string)($validated['html_content'] ?? ''));
        self::assertStringContainsString('pb-c-img', (string)($validated['css_extra'] ?? ''));
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

    public function testContentBlockArtifactRunsRenderedHardGatesAfterJsonEnvelopeValidation(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mismatched HTML closing tag');

        $this->buildContentArtifactFromPayload($service, [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-c-root{display:block;}#componentId .pb-c-title{font-size:clamp(2rem,5vw,3rem);letter-spacing:-.02em;}',
            'css_responsive' => '@media (max-width: 768px){#componentId .pb-c-title{font-size:32px;}}@media (max-width: 420px){#componentId .pb-c-title{font-size:28px;}}',
            'html_content' => '<section class="pb-c-root"><h2 class="pb-c-title">濞戞搩鍘介弸鍐喆閸曨偄鐏婇柡鍌氭处椤?REQUIRED_IMAGE_STRUCTURE_CONTRACT</h2><p>閻庢稒顨嗛灞剧▔鎼淬垺鐎俊妤€鐗忛弫?AI 闁圭顦伴弻鐔奉浖閸繃鍋ラ柤濂変簽閺侀亶鎮介悢绋跨亣闁?/p></section>',
            'js_content' => '',
        ], [], ['content_locale' => 'zh_Hans_CN']);

    }

    public function testEmptyVisitorControlGateRejectsPlainEmptyControls(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $reason = (function (): ?string {
            return $this->detectEmptyVisitorControlTextViolation(
                '<section class="pb-c-root"><button type="button" class="pb-c-cta" data-pb-ai-action="primary_cta"></button></section>'
            );
        })->call($service);

        self::assertIsString($reason);
        self::assertStringContainsString('button/CTA must contain meaningful visible copy', $reason);
    }

    public function testContentBlockArtifactRepairsEmptyVisitorControlsBeforeFinalHtmlGate(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $artifact = $this->buildContentArtifactFromPayload($service, [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-c-root{display:block;}#componentId .pb-c-title{font-size:52px;}#componentId .pb-c-cta{display:inline-flex;}',
            'css_responsive' => '@media (max-width: 768px){#componentId .pb-c-root{display:block;}}@media (max-width: 420px){#componentId .pb-c-root{display:block;}}',
            'html_content' => '<section class="pb-c-root"><h1 class="pb-c-title">Launch reliable AI workflows</h1><button type="button" class="pb-c-cta" data-pb-ai-action="primary_cta"></button></section>',
            'js_content' => '',
        ], [], ['content_locale' => 'en_US']);

        self::assertStringContainsString('Get Started', (string)($artifact['html'] ?? ''));
    }

    public function testEmptyVisitorControlGateAcceptsSafePhpEchoBindings(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $reason = (function (): ?string {
            return $this->detectEmptyVisitorControlTextViolation(
                '<section class="pb-c-root"><button type="button" class="pb-c-cta" data-pb-ai-action="primary_cta"><?= htmlspecialchars($ctaText ?? \'Start now\', ENT_QUOTES, \'UTF-8\') ?></button></section>'
            );
        })->call($service);

        self::assertNull($reason);
    }

    public function testFailureSpecificRecoveryPromptHandlesEmptyVisitorControlCopy(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $prompt = (function (): string {
            return $this->buildFailureSpecificRecoveryContract(
                new \RuntimeException('Generated component structure hard policy failed: button/CTA must contain meaningful visible copy'),
                'content/home-page-final-cta',
                'pb-c',
                false,
                []
            );
        })->call($service);

        self::assertStringContainsString('FAILURE_FIX_EMPTY_VISIBLE_CONTROL_COPY', $prompt);
        self::assertStringContainsString('proof.value_1', $prompt);
        self::assertStringContainsString('Never return empty tags', $prompt);
    }

    public function testContentHeroImageContractDoesNotBlockAfterJsonEnvelopeValidation(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $artifact = $this->buildContentArtifactFromPayload(
            $service,
            [
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#componentId .pb-c-root{display:grid;gap:24px;}#componentId .pb-c-title{font-size:52px;}#componentId .pb-c-text{display:block;}',
                'css_responsive' => '@media (max-width: 768px){#componentId .pb-c-root{display:block;}}@media (max-width: 420px){#componentId .pb-c-root{display:block;}}',
                'html_content' => '<section class="pb-c-root"><h1 class="pb-c-title">OpsFlow operating layer</h1><p class="pb-c-text">Image binding can be retried later; content generation should continue.</p></section>',
                'js_content' => '',
            ],
            [
                'runtime.section_template' => 'hero',
                'runtime.section_image_url' => '/pub/media/page-build/site/ai-generated/hero.webp',
                'runtime.section_image_slot_id' => 'page:home_page:content-home-page-hero',
            ],
            [
                'content_locale' => 'en_US',
                '_required_image_assets' => [
                    'page:home_page:content-home-page-hero' => '/pub/media/page-build/site/ai-generated/hero.webp',
                ],
            ]
        );

        self::assertSame('content/home-page-hero', $artifact['code']);
        self::assertStringContainsString('Image binding can be retried later', (string)($artifact['html'] ?? ''));
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
            '_plan_json_task' => [
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
    private function buildNeonCardPlanJsonScope(): array
    {
        return [
            'site_title' => "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}",
            'brief_description' => "\u{6253}\u{9020}\u{4E00}\u{4E2A}\u{9713}\u{8679}\u{68CB}\u{724C}\u{98CE}\u{683C}\u{7684}\u{7EBF}\u{4E0A}\u{5A31}\u{4E50}\u{7F51}\u{7AD9}\u{FF0C}\u{5305}\u{542B}\u{73A9}\u{5BB6}\u{8BC1}\u{660E}\u{3001}\u{73A9}\u{6CD5}\u{4EAE}\u{70B9}\u{3001}\u{653B}\u{7565}\u{5185}\u{5BB9}\u{548C}\u{5BA2}\u{670D}\u{652F}\u{6301}\u{3002}",
            'plan_json' => [
                'confirmed' => 1,
                'contract_meta' => [
                    'id' => 'test-neon-card-plan-json-v2',
                    'type' => 'plan_json',
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
                    'home_page' => [
                        'page_type' => 'home_page',
                        'title_key' => 'page.home_page.title',
                        'hero' => [
                            'block_key' => 'hero',
                            'block_id' => 'home_page.hero',
                            'page_type' => 'home_page',
                            'section_key' => 'hero',
                            'section_code' => 'content/home-page-hero',
                            'block_type' => 'hero',
                            'page_flow_role' => 'opening',
                            'status' => 0,
                            'content_keys' => ['block.home_page.hero.title', 'block.home_page.hero.copy'],
                            'visual_signature' => [
                                'composition_pattern' => 'dark neon casino chess guidance hero',
                                'surface_treatment' => 'deep casino table surface with neon cyan edges',
                            ],
                        ],
                        'final_cta' => [
                            'block_key' => 'final_cta',
                            'block_id' => 'home_page.final_cta',
                            'page_type' => 'home_page',
                            'section_key' => 'final_cta',
                            'section_code' => 'content/home-page-final-cta',
                            'block_type' => 'final_cta',
                            'page_flow_role' => 'conversion',
                            'status' => 0,
                            'content_keys' => ['block.home_page.final_cta.title', 'block.home_page.final_cta.copy'],
                            'visual_signature' => [
                                'composition_pattern' => 'dark neon casino chess guidance CTA band',
                                'surface_treatment' => 'glowing card-room panel',
                            ],
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
