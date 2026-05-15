<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use GuoLaiRen\PageBuilder\Service\AI\AiSiteSkillRegistry;
use GuoLaiRen\PageBuilder\Service\AI\CodeFixer;
use GuoLaiRen\PageBuilder\Service\AI\CodeValidator;
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;
use Weline\Framework\Runtime\RequestContext;

class AiSitePageComponentGenerationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::remove(AiSitePageComponentGenerationService::REQUEST_KEY_FORCE_REAL_AI_IN_TEST);
        parent::tearDown();
    }

    public function testRunAiGenerationUsesAiInPhpunitUnlessStubIsExplicitlyAllowed(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willReturnCallback(static function (string $prompt, callable $callback): void {
                $callback('{"html_extra":"<div>AI generated header</div>","css_extra":"","php_variables":"","extra_fields":"","js_content":""}');
            });

        $service = new AiSitePageComponentGenerationService(
            aiService: $aiService,
        );

        $payload = (function (string $region, string $prompt): array {
            return $this->runAiGeneration($region, $prompt);
        })->call($service, 'header', 'Generate a simple header');

        self::assertSame('<div>AI generated header</div>', $payload['html_extra'] ?? null);
    }

    public function testRunAiGenerationFallsBackToNonStreamWhenStreamFailsBeforeParseablePayload(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willThrowException(new \RuntimeException('AI流式生成失败: 流式API调用失败: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading'));
        $aiService->expects(self::once())
            ->method('generate')
            ->willReturn('{"html_extra":"<div>Recovered component</div>","css_extra":"","php_variables":"","extra_fields":"","js_content":""}');

        $service = new AiSitePageComponentGenerationService(
            aiService: $aiService,
        );

        $payload = (function (string $region, string $prompt): array {
            return $this->runAiGeneration($region, $prompt);
        })->call($service, 'header', 'Generate a simple header');

        self::assertSame('<div>Recovered component</div>', $payload['html_extra'] ?? null);
    }

    public function testCliStreamUsesUnlimitedTransportButNonStreamFallbackKeepsFiniteTimeout(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $reflector = new \ReflectionMethod($service, 'buildAiRuntimeParams');
        $reflector->setAccessible(true);

        $streamParams = $reflector->invoke($service, [
            'timeout' => 180,
            'response_format' => ['type' => 'json_object'],
        ], true);
        $fallbackParams = $reflector->invoke($service, [
            'timeout' => 180,
            'response_format' => ['type' => 'json_object'],
        ], false);

        self::assertSame(0, $streamParams['timeout'] ?? null);
        self::assertSame(['type' => 'json_object'], $streamParams['response_format'] ?? null);
        self::assertSame(['type' => 'disabled'], $streamParams['thinking'] ?? null);
        self::assertSame('disabled', $streamParams['thinking_mode'] ?? null);
        self::assertFalse((bool)($streamParams['enable_thinking'] ?? true));
        self::assertFalse((bool)($streamParams['enable_reasoning'] ?? true));
        self::assertTrue((bool)($streamParams['disable_ai_timeout'] ?? false));
        self::assertTrue((bool)($streamParams['disable_cli_timeout'] ?? false));
        self::assertFalse((bool)($streamParams['enforce_timeout_in_stream'] ?? true));

        self::assertSame(180, $fallbackParams['timeout'] ?? null);
        self::assertSame(['type' => 'json_object'], $fallbackParams['response_format'] ?? null);
        self::assertSame(['type' => 'disabled'], $fallbackParams['thinking'] ?? null);
        self::assertSame('disabled', $fallbackParams['thinking_mode'] ?? null);
        self::assertFalse((bool)($fallbackParams['enable_thinking'] ?? true));
        self::assertFalse((bool)($fallbackParams['enable_reasoning'] ?? true));
        self::assertArrayNotHasKey('disable_ai_timeout', $fallbackParams);
        self::assertArrayNotHasKey('disable_cli_timeout', $fallbackParams);
        self::assertArrayNotHasKey('enforce_timeout_in_stream', $fallbackParams);
    }

    public function testBuildAiRuntimeParamsStripsThinkingBudgetAndReasoningEffortForStructuredJson(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $reflector = new \ReflectionMethod($service, 'buildAiRuntimeParams');
        $reflector->setAccessible(true);

        $params = $reflector->invoke($service, [
            'timeout' => 180,
            'response_format' => ['type' => 'json_object'],
            'reasoning_effort' => 'high',
            'thinking_budget' => 2048,
            'thinking_budget_tokens' => 2048,
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
            'thinking_mode' => true,
            'enable_thinking' => true,
            'enable_reasoning' => true,
        ], true);

        self::assertArrayNotHasKey('reasoning_effort', $params);
        self::assertArrayNotHasKey('thinking_budget', $params);
        self::assertArrayNotHasKey('thinking_budget_tokens', $params);
        self::assertSame(['type' => 'disabled'], $params['thinking'] ?? null);
        self::assertSame('disabled', $params['thinking_mode'] ?? null);
        self::assertFalse((bool)($params['enable_thinking'] ?? true));
        self::assertFalse((bool)($params['enable_reasoning'] ?? true));
    }

    public function testGenerateComponentThrowsAfterAiRetriesInsteadOfReturningStubFallback(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::exactly(3))
            ->method('generateStream')
            ->willThrowException(new \RuntimeException('model unavailable'));
        $aiService->expects(self::never())
            ->method('generate');

        $service = new AiSitePageComponentGenerationService(
            frameworkBuilder: new FrameworkBuilder(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
            aiService: $aiService,
        );

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('AI component generation failed after');

        (function (): array {
            return $this->generateComponent(
                'header/ai-site-header',
                'AI Site Header',
                'header',
                'Generate a simple header',
                [],
                []
            );
        })->call($service);
    }

    public function testProductionComponentGenerationDoesNotContainLocalDesignFallbackCss(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSitePageComponentGenerationService.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('REQUEST_KEY_ALLOW_STUB_AI_IN_TEST', $source);
        self::assertStringNotContainsString('function shouldUseStubAiGeneration', $source);
        self::assertStringNotContainsString('function buildStubAiPayload', $source);
        self::assertStringNotContainsString('function buildFallbackSectionPlan', $source);
        self::assertStringNotContainsString('function buildFallbackComponent', $source);
        self::assertStringNotContainsString('function buildProductionFallbackAiPayload', $source);
        self::assertStringNotContainsString('ai-site-contract-stage', $source);
        self::assertStringNotContainsString('ai-site-contract-card', $source);
        self::assertStringNotContainsString('ai-site-card-grid', $source);
        self::assertStringNotContainsString('ai-site-hero-slide', $source);
        self::assertStringNotContainsString('ai-site-css-visual', $source);
        self::assertStringContainsString('deterministic local fallback is forbidden', $source);
    }

    public function testRequiredInlineImageGenerationRetriesTransientTimeoutBeforeFailingBlock(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $calls = 0;
        $slotId = 'page:home_page:content-home-page-hero-download';

        $spec = (function (array $spec) use (&$calls, $slotId): array {
            return $this->prepareInlineImageAssetForComponentSpec($spec);
        })->call($service, [
            'region' => 'content',
            'defaultConfig' => [
                'runtime.section_image_required' => '1',
                'runtime.section_image_slot_id' => $slotId,
                'runtime.inline_image_generation_max_attempts' => '2',
            ],
            'renderContext' => [
                '_visual_contract' => [
                    'required' => 1,
                    'slot_id' => $slotId,
                ],
                '_inline_image_asset_generator' => static function (string $requestedSlotId) use (&$calls, $slotId): array {
                    self::assertSame($slotId, $requestedSlotId);
                    $calls++;
                    if ($calls === 1) {
                        throw new \RuntimeException('VectorEngine API failed (HTTP: 0): Operation timed out after 180010 milliseconds with 0 bytes received');
                    }

                    return ['final_url' => '/pub/media/page-build/generated/hero.jpg'];
                },
            ],
        ]);

        self::assertSame(2, $calls);
        self::assertSame('/pub/media/page-build/generated/hero.jpg', $spec['defaultConfig']['visual.image_url'] ?? null);
        self::assertSame(
            '/pub/media/page-build/generated/hero.jpg',
            $spec['renderContext']['_required_image_assets'][$slotId] ?? null
        );
    }

    public function testRetryPromptPreservesVisualQualityFloor(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $prompt = (function (): string {
            return $this->buildRetryGenerationPrompt(
                'content',
                'content/home-page-featured-plugins-grid',
                'Base prompt',
                'plain card grid failed quality check',
                2
            );
        })->call($service);

        self::assertStringContainsString('AI enhanced repair/rewrite mode', $prompt);
        self::assertStringContainsString('full-quality AI design repair', $prompt);
        self::assertStringContainsString('Preserve the original page/task intent', $prompt);
        self::assertStringContainsString('Do not downgrade to a generic grid', $prompt);
        self::assertStringContainsString('plain cards or a flat strip', $prompt);
        self::assertStringContainsString('For link/contact/social blocks', $prompt);
        self::assertStringContainsString('For blog/article/category blocks', $prompt);
        self::assertStringContainsString('1920x750-style banner', $prompt);
        self::assertStringContainsString('If explicit user adjustment conflicts', $prompt);
        self::assertStringContainsString('Never reuse generic CTA/category/navigation labels', $prompt);
        self::assertStringNotContainsString('Get started', $prompt);
        self::assertStringNotContainsString('Game suite', $prompt);
        self::assertStringNotContainsString('Download path', $prompt);
        self::assertStringContainsString('Fix contrast explicitly', $prompt);
        self::assertStringContainsString('Fix page hierarchy explicitly', $prompt);
        self::assertStringContainsString('Fix HTML structure explicitly', $prompt);
        self::assertStringContainsString('If the failure mentions a Required image slot', $prompt);
        self::assertStringContainsString('copy the concrete editable image template', $prompt);
        self::assertStringNotContainsString("real <img src='exact final_url'>", $prompt);
        self::assertStringContainsString('exactly one JSON object', $prompt);
        self::assertStringContainsString('Do not append a second JSON object', $prompt);
        self::assertStringContainsString('If the failed payload contains any img src that is not the supplied final_url', $prompt);
        self::assertStringContainsString('IMAGE_SRC_SELF_CHECK', $prompt);
        self::assertStringContainsString('images.unsplash.com', $prompt);
        self::assertStringContainsString('pb-content-home-page-featured-plugins-grid', $prompt);
        self::assertStringNotContainsString('simplif', \strtolower($prompt));
        self::assertStringNotContainsString('compact', \strtolower($prompt));
        self::assertStringNotContainsString('reduced', \strtolower($prompt));
        self::assertStringNotContainsString('Keep the structure compact', $prompt);
        self::assertStringNotContainsString('Prefer one small section', $prompt);
    }

    public function testRetryPromptRedactsRejectedImageUrlsFromFailedPayload(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $slotId = 'page:home_page:content-home-page-game-showcase-or-features';
        $finalUrl = '/pub/media/page-build/site/ai-generated/game-showcase.webp';

        $prompt = (function (string $slotId, string $finalUrl): string {
            return $this->buildRetryGenerationPrompt(
                'content',
                'content/home-page-game-showcase-or-features',
                'Base prompt with verified_asset_src_allowlist',
                'Required image slot is not referenced by generated block: ' . $slotId,
                2,
                [
                    'html_content' => '<img src="https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?w=800&q=80" data-pb-ai-image-role="generated-asset" data-pb-ai-asset-slot="' . $slotId . '"><p>Royal game copy.</p>',
                    'css_extra' => '#componentId .x{background-image:url("https://images.unsplash.com/bad.jpg");}',
                ],
                [$slotId => $finalUrl]
            );
        })->call($service, $slotId, $finalUrl);

        self::assertStringContainsString('[UNVERIFIED_IMAGE_URL_REMOVED_USE_VERIFIED_FINAL_URL]', $prompt);
        self::assertStringNotContainsString('photo-1556742049', $prompt);
        self::assertStringNotContainsString('bad.jpg', $prompt);
        self::assertStringContainsString($slotId, $prompt);
        self::assertStringContainsString('verified_asset_src_allowlist', $prompt);
    }

    public function testRetryPromptDiscardsFailedPayloadForStructuralGrammarFailures(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $prompt = (function (): string {
            return $this->buildRetryGenerationPrompt(
                'content',
                'content/home-page-final-download-cta',
                'Base prompt',
                'AI component HTML structure invalid: missing whitespace before next attribute near <img>',
                2,
                [
                    'html_content' => "<section class='broken'><img src='/bad.jpg'alt='broken'><p>Broken payload must not be copied.</p></section>",
                    'css_extra' => '#componentId .broken{position:relativez-index:1;}',
                ]
            );
        })->call($service);

        self::assertStringContainsString('STRUCTURAL REWRITE MODE', $prompt);
        self::assertStringContainsString('STRUCTURAL SAFE HTML SUBSET', $prompt);
        self::assertStringContainsString('STRUCTURAL SAFE CSS SUBSET', $prompt);
        self::assertStringContainsString('STRUCTURAL CSS FORMAT', $prompt);
        self::assertStringContainsString('Keep html_content under 1400 characters', $prompt);
        self::assertStringContainsString('one `property: value;` declaration per line', $prompt);
        self::assertStringContainsString('Do not reuse or patch the failed payload markup/CSS', $prompt);
        self::assertStringNotContainsString('Broken payload must not be copied', $prompt);
        self::assertStringNotContainsString("src='/bad.jpg'alt='broken'", $prompt);
        self::assertStringNotContainsString('position:relativez-index:1', $prompt);
    }

    public function testHeroVisualContractAllowsPremiumUserOverrideMediaLayout(): void
    {
        $service = new AiSitePageComponentGenerationService();

        (function (): void {
            $this->assertRenderedHtmlPassesBuildQualityGate(
                'content/home-page-hero',
                '<div class="ai-site-hero"><img class="ai-site-hero-image" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"><div class="ai-site-hero-scrim" style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(7,13,25,.82),rgba(7,13,25,.28))"></div><article class="ai-site-hero-text-panel" style="position:relative;background:rgba(7,13,25,.68);color:#fff;backdrop-filter:blur(12px);padding:32px;border-radius:24px;max-width:640px"><h2>Play Teen Patti Like a Royal</h2><p>Fast secure APK download.</p></article></div>'
            );
        })->call($service);

        self::assertTrue(true);
    }

    public function testLowQualityGateDoesNotDependOnCurrentSiteGenericTrio(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'detectLowQualityGeneratedSectionHtmlReason');
        $method->setAccessible(true);

        $reason = $method->invoke(
            $service,
            '<div class="pb-sample-grid"><article><h3>Get started</h3><p>Bring the download offer.</p></article><article><h3>Game suite</h3><p>Card games and quick entry.</p></article><article><h3>Download path</h3><p>Get started</p></article></div>'
        );

        self::assertNotSame('generic repeated three-card scaffold leaked into generated content', $reason);
    }

    public function testSectionPromptIncludesPremiumHeroAndAntiMonotonyRules(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $prompt = (function (string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): string {
            return $this->buildSectionGenerationPrompt($pageType, $section, $blueprint, $websiteProfile, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero',
                'key' => 'hero',
                'name' => 'Hero',
                'template' => 'hero',
                'config' => [],
            ],
            [
                'page_label' => 'Home',
                'page_title' => 'Premium Hero Test',
                'ai_description' => 'Explain value',
            ],
            [
                'site_title' => 'Premium Hero Test',
                'brief_description' => 'Indian real-money card game APK landing page',
                'content_locale' => 'en_US',
                'default_locale' => 'en_US',
            ],
            [
                'content_locale' => 'en_US',
                'default_locale' => 'en_US',
            ]
        );

        self::assertStringContainsString('Premium site design contract', $prompt);
        self::assertStringContainsString('HERO/BANNER DEFAULT BASELINE', $prompt);
        self::assertStringContainsString('explicit user/design instruction wins', $prompt);
        self::assertStringContainsString('real editable <img> cover layer', $prompt);
        self::assertStringContainsString('text-safe panel', $prompt);
        self::assertStringContainsString('Body copy must never be dark text directly on a busy photo', $prompt);
        self::assertStringContainsString('named overlay/scrim/veil layer and a named text/content panel class', $prompt);
        self::assertStringContainsString('Anti-monotony rule', $prompt);
        self::assertStringContainsString('tiny cartoon/SVG-looking media', $prompt);
        self::assertStringContainsString('blueprint meta-copy', $prompt);
        self::assertStringContainsString('instruction-shaped headings', $prompt);
        self::assertStringContainsString('Card/list titles must be concrete visitor labels', $prompt);
        self::assertStringContainsString('REQUIRED IMAGE SLOT BINDING', $prompt);
        self::assertStringContainsString('copy the concrete editable image template', $prompt);
        self::assertStringNotContainsString("real <img src='exact final_url'>", $prompt);
        self::assertStringContainsString('do not satisfy a required slot with CSS background-image only', $prompt);
    }

    public function testHeroQualityGateRejectsBusyImageTextWithoutTextSafePanel(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $html = '<section class="pb-home-hero">'
            . '<img class="pb-home-hero-image" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" '
            . 'src="/pub/media/page-build/site/ai-generated/hero.webp" data-pb-ai-image-role="generated-asset" data-pb-ai-asset-slot="page:home_page:content-home-page-hero" alt="Teen Patti table">'
            . '<h1>Download Teen Patti Now</h1>'
            . '<p>Experience authentic Indian gameplay with smooth graphics and royal rewards.</p>'
            . '<a class="pb-home-hero-cta">Download APK</a>'
            . '</section>';

        $reason = (function (string $html): ?string {
            return $this->detectLowQualityGeneratedSectionHtmlReason($html);
        })->call($service, $html);

        self::assertSame('hero/banner lacks a readable overlay layer for floating content', $reason);
    }

    public function testHeroQualityGateAcceptsNamedScrimAndTextSafePanel(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $html = '<section class="pb-home-hero">'
            . '<img class="pb-home-hero-image" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" '
            . 'src="/pub/media/page-build/site/ai-generated/hero.webp" data-pb-ai-image-role="generated-asset" data-pb-ai-asset-slot="page:home_page:content-home-page-hero" alt="Teen Patti table">'
            . '<div class="pb-home-hero-scrim" style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(12,8,4,.82),rgba(12,8,4,.42),rgba(12,8,4,.1))"></div>'
            . '<div class="pb-home-hero-text-panel" style="position:relative;max-width:680px;padding:34px;border-radius:24px;background:rgba(18,12,7,.68);color:#fff">'
            . '<h1>Download Teen Patti Now</h1>'
            . '<p>Experience authentic Indian gameplay with smooth graphics and royal rewards.</p>'
            . '<a class="pb-home-hero-cta">Download APK</a>'
            . '</div>'
            . '</section>';

        $reason = (function (string $html): ?string {
            return $this->detectLowQualityGeneratedSectionHtmlReason($html);
        })->call($service, $html);

        self::assertNull($reason);
    }

    public function testVisualContractPromptClosesRequiredImageUrlAllowlist(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $slotId = 'page:home_page:content-home-page-game-showcase-or-features';
        $finalUrl = '/pub/media/page-build/site/ai-generated/game-showcase.webp';

        $prompt = (function (array $visualContract): string {
            return $this->buildSectionVisualContractPromptAddon($visualContract);
        })->call($service, [
            'required' => 1,
            'slot_id' => $slotId,
            'slot_type' => 'section_image',
            'final_url' => $finalUrl,
            'usage' => 'section media',
            'placement' => 'media panel',
            'subject' => 'premium gameplay table',
            'style' => 'cinematic',
        ]);

        self::assertStringContainsString('verified_asset_src_allowlist', $prompt);
        self::assertStringContainsString('CLOSED SET', $prompt);
        self::assertStringContainsString('IMAGE_SRC_SELF_CHECK', $prompt);
        self::assertStringContainsString($finalUrl, $prompt);
        self::assertStringContainsString("<img src='" . $finalUrl . "'", $prompt);
        self::assertStringContainsString("data-pb-ai-asset-slot='" . $slotId . "'", $prompt);
        self::assertStringContainsString('REQUIRED EXACT IMAGE TAG', $prompt);
        self::assertStringContainsString('copyable_verified_asset_img_template', $prompt);
        self::assertStringContainsString('Copyable required img template', $prompt);
        self::assertStringContainsString('whose src is missing or different from the matching final_url', $prompt);
        self::assertStringContainsString('Any other src for this slot is invalid', $prompt);
        self::assertStringContainsString('images.unsplash.com', $prompt);
        self::assertStringNotContainsString("real <img src='exact final_url'>", $prompt);
        self::assertStringNotContainsString('exact slot_id', $prompt);
    }

    public function testVerifiedAssetPromptProvidesConcreteTemplateWithoutSymbolicImagePlaceholders(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $slotId = 'page:home_page:content-home-page-trust-security';
        $finalUrl = '/pub/media/page-build/site/ai-generated/trust-security.webp';

        $method = new \ReflectionMethod($service, 'buildVerifiedAssetPromptContract');
        $method->setAccessible(true);
        $prompt = (string)$method->invoke($service, [$slotId => $finalUrl]);

        self::assertStringContainsString('copyable_verified_asset_img_template', $prompt);
        self::assertStringContainsString("<img src='" . $finalUrl . "'", $prompt);
        self::assertStringContainsString("data-pb-ai-image-role='generated-asset'", $prompt);
        self::assertStringContainsString("data-pb-ai-asset-slot='" . $slotId . "'", $prompt);
        self::assertStringContainsString('Invalid examples', $prompt);
        self::assertStringContainsString('Introduce brand story and mission', $prompt);
        self::assertStringContainsString('Secure APK download badge cluster', $prompt);
        self::assertStringNotContainsString("real <img src='exact final_url'>", $prompt);
        self::assertStringNotContainsString('exact slot_id', $prompt);
        self::assertStringNotContainsString('<slot_id>', $prompt);
    }

    public function testGeneratedAssetAttributesAreBackfilledBeforeHeroSlotValidation(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $slotId = 'page:home_page:content-home-page-hero-download';
        $finalUrl = '/pub/media/page-build/site/ai-generated/hero-download.webp';
        $aiData = [
            'html_content' => "<section class='pb-content-home-page-hero-download-root'><img class='pb-content-home-page-hero-download-media' src='{$finalUrl}' alt='Royal game hero'><div class='pb-content-home-page-hero-download-wrap'><h2>Download Now</h2></div></section>",
        ];
        $renderContext = [
            '_required_image_assets' => [$slotId => $finalUrl],
        ];
        $defaultConfig = [
            'runtime.section_template' => 'hero',
            'runtime.section_image_url' => $finalUrl,
            'runtime.section_image_slot_id' => $slotId,
        ];

        $ensure = new \ReflectionMethod($service, 'ensureGeneratedAssetImageAttributes');
        $ensure->setAccessible(true);
        $enforce = new \ReflectionMethod($service, 'enforceContractHeroImageUrlsInAiPayload');
        $enforce->setAccessible(true);

        $patched = $ensure->invoke($service, $aiData, $renderContext, $defaultConfig);

        self::assertStringContainsString("data-pb-ai-image-role=\"generated-asset\"", (string)$patched['html_content']);
        self::assertStringContainsString('data-pb-ai-asset-slot="' . $slotId . '"', (string)$patched['html_content']);
        self::assertSame($patched, $enforce->invoke($service, $patched, 'content', $defaultConfig));
    }

    public function testComponentScopePromptProvidesGenericValidSkeletonsWithoutRenderLayerHardcoding(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $prompt = (function (): string {
            return $this->appendComponentCssScopeInstruction('Base prompt', 'content/home-page-trust-security');
        })->call($service);

        self::assertStringContainsString('Minimal valid HTML skeleton', $prompt);
        self::assertStringContainsString('Valid JSON envelope shape', $prompt);
        self::assertStringContainsString('Prefix self-check', $prompt);
        self::assertStringContainsString('pb-content-home-page-trust-security-root', $prompt);
        self::assertStringContainsString('@media (max-width:768px)', $prompt);
        self::assertStringContainsString('leading `pb`', $prompt);
        self::assertStringContainsString('CSS reliability mode', $prompt);
        self::assertStringContainsString('property: value;', $prompt);
        self::assertStringContainsString('Do not use color-mix()', $prompt);
        self::assertStringContainsString('never concatenate closing tags', $prompt);
        self::assertStringContainsString('</divsection>', $prompt);
        self::assertStringContainsString("`<div='...'>`", $prompt);
        self::assertStringContainsString("`content:;`", $prompt);
        self::assertStringContainsString("<img ...'alt='...'>", $prompt);
        self::assertStringContainsString('Invalid output examples to avoid', $prompt);
    }

    public function testSectionPromptIncludesManifestFinalUrlInVisualContract(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );
        $slotId = 'page:home_page:content-home-page-game-showcase-or-features';
        $finalUrl = '/pub/media/page-build/site/ai-generated/game-showcase.webp';

        $prompt = (function (string $slotId, string $finalUrl): string {
            return $this->buildSectionGenerationPrompt(
                'home_page',
                [
                    'code' => 'content/home-page-game-showcase-or-features',
                    'key' => 'game_showcase_or_features',
                    'name' => 'Game Showcase',
                    'template' => 'feature',
                ],
                [
                    'page_label' => 'Home',
                    'page_title' => 'Royal Game Site',
                    'ai_description' => 'Showcase available games.',
                ],
                [
                    'site_title' => 'Royal Game Site',
                    'brief_description' => 'Indian card game APK landing page',
                    'content_locale' => 'en_US',
                    'default_locale' => 'en_US',
                ],
                [
                    'content_locale' => 'en_US',
                    'default_locale' => 'en_US',
                    'asset_manifest' => [
                        'slots' => [
                            $slotId => [
                                'slot_id' => $slotId,
                                'slot_type' => 'section_image',
                                'page_type' => 'home_page',
                                'section_code' => 'content/home-page-game-showcase-or-features',
                                'block_key' => 'game_showcase_or_features',
                                'final_url' => $finalUrl,
                                'variants' => [[
                                    'url' => $finalUrl,
                                    'mode' => 'recovered_from_virtual_theme',
                                    'source' => 'generated',
                                ]],
                            ],
                        ],
                    ],
                ]
            );
        })->call($service, $slotId, $finalUrl);

        self::assertStringContainsString($finalUrl, $prompt);
        self::assertStringContainsString('Required final_url for this slot: ' . $finalUrl, $prompt);
        self::assertStringContainsString('REQUIRED EXACT IMAGE TAG', $prompt);
        self::assertStringContainsString("<img src='" . $finalUrl . "'", $prompt);
        self::assertStringContainsString("data-pb-ai-asset-slot='" . $slotId . "'", $prompt);
    }

    public function testThemeStyleDefaultsCorrectUnreadableDarkPaletteText(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'resolveThemeStyleDefaults');
        $method->setAccessible(true);

        $scope = [
            'theme_design' => [
                'color_scheme' => [
                    'primary' => '#0f172a',
                    'background' => '#0f172a',
                    'surface' => '#111827',
                    'text' => '#111111',
                    'accent' => '#f59e0b',
                ],
            ],
        ];

        $header = $method->invoke($service, $scope, 'header');
        $footer = $method->invoke($service, $scope, 'footer');
        $content = $method->invoke($service, $scope, 'content');

        self::assertSame('#111827', $header['style.bg_color'] ?? null);
        self::assertSame('#f8fafc', $header['style.text_color'] ?? null);
        self::assertSame('#f8fafc', $header['style.link_color'] ?? null);
        self::assertSame('#f8fafc', $footer['style.text_color'] ?? null);
        self::assertSame('#f8fafc', $content['style.title_color'] ?? null);
    }

    public function testPromptAddonsRequireContrastLayeringAndBalancedHtml(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $themeContract = new \ReflectionMethod($service, 'buildThemeContractPromptAddon');
        $themeContract->setAccessible(true);
        $visualExcellence = new \ReflectionMethod($service, 'buildVisualExcellencePromptAddon');
        $visualExcellence->setAccessible(true);
        $skillContract = new \ReflectionMethod($service, 'buildWelineSkillContractPromptAddon');
        $skillContract->setAccessible(true);
        $safetyRules = new \ReflectionMethod($service, 'buildComponentJsonPhpSafetyRulesEn');
        $safetyRules->setAccessible(true);

        $scope = [
            'theme_design' => [
                'style_signature' => 'dark editorial casino trust',
                'color_scheme' => [
                    'primary' => '#0f172a',
                    'background' => '#0f172a',
                    'surface' => '#111827',
                    'text' => '#e2e8f0',
                    'accent' => '#f59e0b',
                ],
            ],
        ];

        $prompt = $themeContract->invoke($service, $scope)
            . $visualExcellence->invoke($service, 'section')
            . $skillContract->invoke($service)
            . $safetyRules->invoke($service);

        self::assertStringContainsString('Contrast is non-negotiable', $prompt);
        self::assertStringContainsString('Theme color is not a paint bucket', $prompt);
        self::assertStringContainsString('Color quality requirement', $prompt);
        self::assertStringContainsString('HTML fragments must be balanced', $prompt);
        self::assertStringContainsString('#componentId .pb-', $prompt);
        self::assertStringContainsString('hero/banner images', $prompt);
        self::assertStringContainsString('Over-image readability contract', $prompt);
        self::assertStringContainsString('Body copy must never be placed directly on a busy image', $prompt);
        self::assertStringContainsString('Hero/banner mobile contract', $prompt);
        self::assertStringContainsString('390px', $prompt);
        self::assertStringContainsString('absolute-position overlap', $prompt);
        self::assertStringContainsString('Card readability contract', $prompt);
        self::assertStringContainsString('cannot collide', $prompt);
        self::assertStringContainsString('Typography restraint contract', $prompt);
        self::assertStringContainsString('not full headings or CTA headlines', $prompt);
        self::assertStringContainsString('shared-logo-rule', $prompt);
        self::assertStringContainsString('use the same logo asset in both header and footer by default', $prompt);
        self::assertStringContainsString('only use a separate light/dark/monochrome logo treatment', $prompt);
    }

    public function testVirtualThemeCssRejectsRootClassScopeSelectors(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'assertNoInvalidComponentRootClassSelectors');
        $method->setAccessible(true);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('AI CSS scope contract failed');

        $method->invoke($service, [
            'css_extra' => '#componentId.pb-content-home-page-hero-root{position:relative;}',
        ]);
    }

    public function testContentHtmlRejectsInnerComponentIdWrapper(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'assertGeneratedComponentHtmlScopeContract');
        $method->setAccessible(true);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('AI HTML scope contract failed');

        $method->invoke($service, [
            'html_content' => "<section id='componentId' class='pb-content-home-page-trust-security-root'>Trust</section>",
        ], 'content', 'content/home-page-trust-security');
    }

    public function testContentHtmlRejectsClassesOutsideComponentPrefix(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'assertGeneratedComponentHtmlScopeContract');
        $method->setAccessible(true);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('AI HTML class scope contract failed');

        $method->invoke($service, [
            'html_content' => "<section class='pb-contentabout-page-faq-accordion-section'>FAQ</section>",
        ], 'content', 'content/about-page-faq-accordion');
    }

    public function testComponentClassScopeNormalizationRepairsCommonPrefixTypos(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $normalize = new \ReflectionMethod($service, 'normalizeVirtualThemeCssClassScope');
        $normalize->setAccessible(true);
        $assertHtml = new \ReflectionMethod($service, 'assertGeneratedComponentHtmlScopeContract');
        $assertHtml->setAccessible(true);
        $assertCss = new \ReflectionMethod($service, 'assertGeneratedComponentCssContract');
        $assertCss->setAccessible(true);

        $data = $normalize->invoke($service, [
            'html_content' => "<section class='pb-contentabout-page-trust-badges-root'><span class='-content-about-page-trust-badges-badge'>Licensed</span></section>",
            'css_extra' => '#componentId .-content-about-page-trust-badges-badge{display:block;}',
        ], 'content/about-page-trust-badges');

        self::assertIsArray($data);
        self::assertStringContainsString('pb-content-about-page-trust-badges-root', (string)$data['html_content']);
        self::assertStringContainsString('pb-content-about-page-trust-badges-badge', (string)$data['html_content']);
        self::assertStringContainsString('.pb-content-about-page-trust-badges-badge', (string)$data['css_extra']);
        $assertHtml->invoke($service, $data, 'content', 'content/about-page-trust-badges');
        $assertCss->invoke($service, $data, 'content', 'content/about-page-trust-badges');
    }

    public function testContentCssRejectsMalformedDeclarations(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'assertGeneratedComponentCssContract');
        $method->setAccessible(true);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('AI CSS structure contract failed');

        $method->invoke($service, [
            'css_extra' => '#componentId .pb-content-home-page-trust-security-root{content:;height:%;position:relativez-index:1;}',
        ], 'content', 'content/home-page-trust-security');
    }

    public function testCssNormalizationRepairsUnbalancedParenthesesWithoutAddingDesignCss(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $normalize = new \ReflectionMethod($service, 'normalizeVirtualThemeCssForValidation');
        $normalize->setAccessible(true);
        $assert = new \ReflectionMethod($service, 'assertGeneratedComponentCssContract');
        $assert->setAccessible(true);

        $normalized = $normalize->invoke(
            $service,
            '#componentId .pb-content-home-page-hero-download-root{box-shadow:0 24px 64px rgba(0,0,0,.35));background:linear-gradient(135deg,#111827,#3b2f1f;}',
            6000
        );

        self::assertIsString($normalized);
        self::assertStringNotContainsString('));', $normalized);
        $assert->invoke($service, [
            'css_extra' => $normalized,
        ], 'content', 'content/home-page-hero-download');
    }

    public function testCssNormalizationRepairsInvalidDeclarationsWithoutAddingDesignCss(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $normalize = new \ReflectionMethod($service, 'normalizeVirtualThemeCssForValidation');
        $normalize->setAccessible(true);
        $assert = new \ReflectionMethod($service, 'assertGeneratedComponentCssContract');
        $assert->setAccessible(true);

        $normalized = $normalize->invoke(
            $service,
            "#componentId .pb-content-home-page-game-categories-root{position:relative z-index:2;-shadow:0 10px 20px #000;color:;height:%;font-family:'Royal Display;color:#fff;}",
            6000
        );

        self::assertIsString($normalized);
        self::assertStringContainsString('z-index:2', $normalized);
        self::assertStringNotContainsString('-shadow', $normalized);
        self::assertStringNotContainsString('color:;', $normalized);
        self::assertStringNotContainsString('height:%', $normalized);
        $assert->invoke($service, [
            'css_extra' => $normalized,
        ], 'content', 'content/home-page-game-categories');
    }

    public function testMalformedHtmlTagDetectsMissingAttributeNameAndGluedAttributes(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'detectMalformedHtmlTagTokenReason');
        $method->setAccessible(true);

        self::assertSame('attribute name is missing', $method->invoke($service, "<span='broken'>"));
        self::assertSame('missing whitespace before next attribute', $method->invoke($service, "<a href='#'class='broken'>"));
    }

    public function testHeroGeneratedImageMustHaveMatchingCoverCssSelector(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'assertHeroGeneratedImageCoverCssContract');
        $method->setAccessible(true);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('AI hero image cover CSS contract failed');

        $method->invoke($service, [
            'html_content' => "<div class='pb-content-home-page-hero-root'><img class='pb-content-home-page-hero-wrong-bg' src='/pub/media/page-build/demo.jpg' data-pb-ai-image-role='generated-asset' data-pb-ai-asset-slot='page:home_page:content-home-page-hero'></div>",
            'css_extra' => '#componentId .pb-content-home-page-hero-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}',
        ], 'content', 'content/home-page-hero');
    }

    public function testHeroGeneratedImageAcceptsMatchingCoverCssSelector(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'assertHeroGeneratedImageCoverCssContract');
        $method->setAccessible(true);

        $method->invoke($service, [
            'html_content' => "<div class='pb-content-home-page-hero-root'><img class='pb-content-home-page-hero-bg' src='/pub/media/page-build/demo.jpg' data-pb-ai-image-role='generated-asset' data-pb-ai-asset-slot='page:home_page:content-home-page-hero'></div>",
            'css_extra' => '#componentId .pb-content-home-page-hero-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center;}',
        ], 'content', 'content/home-page-hero');

        self::assertTrue(true);
    }

    public function testResolveConcurrencyUsesConfiguredApplicationCap(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'resolveConcurrency');
        $method->setAccessible(true);

        self::assertSame(1, $method->invoke($service, 0));
        self::assertSame(1, $method->invoke($service, 1));
        self::assertSame(4, $method->invoke($service, 8));
    }

    public function testGenerateComponentEventsConcurrentlyReportsFulfilledAndRejectedTasks(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::exactly(4))
            ->method('generateStream')
            ->willReturnCallback(static function (string $prompt, callable $callback): void {
                if (\str_contains($prompt, 'broken-batch-prompt')) {
                    throw new \RuntimeException('provider temporarily unavailable');
                }

                $callback('{"html_extra":"<div>ok</div>","css_extra":"","php_variables":"","extra_fields":"","js_content":""}');
            });

        $service = new AiSitePageComponentGenerationService(
            frameworkBuilder: new FrameworkBuilder(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
            aiService: $aiService,
        );

        $events = \iterator_to_array($service->generateComponentEventsConcurrently([
            'header-task' => [
                'componentCode' => 'header/ai-site-header',
                'name' => 'AI Site Header',
                'region' => 'header',
                'prompt' => 'good-batch-prompt',
                'defaultConfig' => [],
                'renderContext' => [],
            ],
            'footer-task' => [
                'componentCode' => 'footer/ai-site-footer',
                'name' => 'AI Site Footer',
                'region' => 'footer',
                'prompt' => 'broken-batch-prompt',
                'defaultConfig' => [],
                'renderContext' => [],
            ],
        ]), true);

        self::assertSame('fulfilled', $events['header-task']['status'] ?? null);
        self::assertIsArray($events['header-task']['result'] ?? null);
        self::assertSame('rejected', $events['footer-task']['status'] ?? null);
        self::assertInstanceOf(\Throwable::class, $events['footer-task']['error'] ?? null);
    }

    public function testFiberConcurrencyWrapsThrownComponentErrorsBeforeGetReturn(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/AiSitePageComponentGenerationService.php');

        self::assertStringContainsString("'status' => 'rejected'", $source);
        self::assertStringContainsString("'error' => \$throwable", $source);
        self::assertStringContainsString("Component fiber failed without an exception payload.", $source);
    }

    public function testGenerateComponentDoesNotRetryNonRetryableProviderErrors(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willThrowException(new \RuntimeException('AI API error (HTTP 402, unknown_error): Insufficient Balance'));
        $aiService->expects(self::never())
            ->method('generate');

        $service = new AiSitePageComponentGenerationService(
            frameworkBuilder: new FrameworkBuilder(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
            aiService: $aiService,
        );

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('AI component generation failed');
        self::expectExceptionMessage('Insufficient Balance');

        (function (): array {
            return $this->generateComponent(
                'header/ai-site-header',
                'AI Site Header',
                'header',
                'Generate a simple header',
                [],
                []
            );
        })->call($service);
    }

    public function testGenerateComponentRetriesRecoverableErrorsThenFailsWithoutPlanDerivedBaseline(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::exactly(3))
            ->method('generateStream')
            ->willThrowException(new \RuntimeException('Rendered component visible copy does not match website content locale.'));
        $aiService->expects(self::never())
            ->method('generate');

        $service = new AiSitePageComponentGenerationService(
            frameworkBuilder: new FrameworkBuilder(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
            aiService: $aiService,
        );

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('AI component generation failed after 3 real-AI attempts');

        (function (): array {
            return $this->generateComponent(
                'content/home-page-featured-plugins-grid',
                'Featured plugin grid',
                'content',
                'Generate a launch-ready featured plugin grid.',
                [],
                ['_content_locale' => 'en_US']
            );
        })->call($service);
    }

    public function testGenerateComponentStillFailsForApiKeyConfigurationErrors(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willThrowException(new \RuntimeException('AI API error (HTTP 401): missing api key'));
        $aiService->expects(self::never())
            ->method('generate');

        $service = new AiSitePageComponentGenerationService(
            frameworkBuilder: new FrameworkBuilder(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
            aiService: $aiService,
        );

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('AI component generation failed');
        self::expectExceptionMessage('missing api key');

        (function (): array {
            return $this->generateComponent(
                'header/ai-site-header',
                'AI Site Header',
                'header',
                'Generate a simple header',
                [],
                []
            );
        })->call($service);
    }

    public function testEnsureAiPayloadValidStripsInvalidHeaderPhpVariablesInsteadOfFailing(): void
    {
        $service = new AiSitePageComponentGenerationService(
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => <<<'PHP'
$navItems = $this->getData('nav_items');
foreach (($navItems ?? []) as $navItem) {
    continue;
}
```
PHP,
            'css_extra' => '#<?= $componentId ?> .ai-header-shell { display: flex; }',
            'html_extra' => '<div class="ai-header-shell"><?= htmlspecialchars($logoText ?? \'\', ENT_QUOTES, \'UTF-8\') ?></div>',
            'js_content' => '',
        ];

        $validatedPayload = (function (array $payload): array {
            return $this->ensureAiPayloadValid($payload, 'header');
        })->call($service, $payload);

        self::assertSame('', $validatedPayload['php_variables']);

        $validation = (new CodeValidator())->validateAiData($validatedPayload, 'header');
        self::assertTrue($validation['valid'], \implode('; ', $validation['errors'] ?? []));
    }

    public function testHeaderPayloadDoesNotRequireContentHtmlQualityBody(): void
    {
        $service = new AiSitePageComponentGenerationService(
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-royal-header { display:flex; align-items:center; gap:18px; background:linear-gradient(135deg,var(--section-primary),var(--section-accent)); }',
            'html_extra' => '',
            'js_content' => '',
        ];

        $validatedPayload = (function (array $payload): array {
            return $this->ensureAiPayloadValid($payload, 'header', 'header/ai-site-header');
        })->call($service, $payload);

        self::assertSame('', $validatedPayload['html_extra']);
        self::assertStringContainsString('pb-royal-header', $validatedPayload['css_extra']);
    }

    public function testDecodeComponentPayloadWithRepairCallsAiRepairForInvalidJsonTransport(): void
    {
        $parser = $this->createMock(AiResponseJsonParser::class);
        $parser->expects(self::exactly(2))
            ->method('extractAndDecode')
            ->willReturnCallback(static function (string $content): ?array {
                if ($content === 'not-json') {
                    return null;
                }
                if (\str_contains($content, '"extra_fields"')) {
                    return [
                        'extra_fields' => '',
                        'php_variables' => '',
                        'css_extra' => '',
                        'html_extra' => '<nav>Recovered</nav>',
                        'js_content' => '',
                    ];
                }

                return null;
            });

        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generate')
            ->willReturn('{"extra_fields":"","php_variables":"","css_extra":"","html_extra":"<nav>Recovered</nav>","js_content":""}');

        $service = new AiSitePageComponentGenerationService(
            responseJsonParser: $parser,
            aiService: $aiService,
            skillRegistry: new AiSiteSkillRegistry(),
        );

        $decoded = (function (string $content, string $region): ?array {
            return $this->decodeComponentPayloadWithRepair($content, $region);
        })->call($service, 'not-json', 'header');

        self::assertIsArray($decoded);
        self::assertSame('<nav>Recovered</nav>', $decoded['html_extra'] ?? null);
    }

    public function testDecodeComponentPayloadRepairsInvalidJsonBackslashEscapes(): void
    {
        $service = new AiSitePageComponentGenerationService(
            responseJsonParser: new AiResponseJsonParser(),
        );
        $content = '{"extra_fields":"","php_variables":"","css_extra":"","css_responsive":"","html_content":"<div class=\"pb-card\div class=\"pb-next\">Copy</div>","js_content":""}';

        $decoded = (function (string $content, string $region): ?array {
            return $this->decodeComponentPayloadWithRepair($content, $region);
        })->call($service, $content, 'content');

        self::assertIsArray($decoded);
        self::assertStringContainsString('pb-card\\div', (string)($decoded['html_content'] ?? ''));
    }

    public function testContentPayloadPromotesHtmlExtraToRequiredHtmlContent(): void
    {
        $parser = $this->createMock(AiResponseJsonParser::class);
        $parser->expects(self::once())
            ->method('extractAndDecode')
            ->willReturn([
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#componentId .pb-ai-real-section { padding:32px; }',
                'html_extra' => '<section class="pb-ai-real-section"><h2>Real AI Section</h2><p>Generated visitor copy.</p></section>',
                'js_content' => '',
            ]);

        $service = new AiSitePageComponentGenerationService(
            responseJsonParser: $parser,
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $decoded = (function (string $content, string $region): array {
            return $this->decodeAndNormalizeComponentContent($content, $region, 'invalid');
        })->call($service, '{}', 'content');
        $validated = (function (array $payload): array {
            return $this->ensureAiPayloadValid($payload, 'content', 'content/real-section');
        })->call($service, $decoded);

        self::assertSame(
            '<section class="pb-ai-real-section"><h2>Real AI Section</h2><p>Generated visitor copy.</p></section>',
            $validated['html_content'] ?? null
        );
    }

    public function testContentPayloadWithoutHtmlFailsInsteadOfBuildingLocalHtml(): void
    {
        $parser = $this->createMock(AiResponseJsonParser::class);
        $parser->expects(self::once())
            ->method('extractAndDecode')
            ->willReturn([
                'extra_fields' => '',
                'php_variables' => '',
                'css_extra' => '#componentId .pb-ai-structured-section { display:grid; gap:18px; padding:28px; border-radius:24px; background:linear-gradient(135deg,#111827,#f59e0b); }',
                'title' => 'Teenipiya Safe APK Games',
                'intro' => 'Players get clear download guidance, fair-play notes, and fast support before installing.',
                'cards' => [
                    ['title' => 'Verified APK path', 'body' => 'Explain safe installation steps without sending visitors to placeholder domains.'],
                    ['title' => 'Real support channels', 'body' => 'Show WhatsApp, Telegram, and help desk options in visitor-facing copy.'],
                ],
                'js_content' => '',
            ]);

        $service = new AiSitePageComponentGenerationService(
            responseJsonParser: $parser,
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $decoded = (function (string $content, string $region): array {
            return $this->decodeAndNormalizeComponentContent($content, $region, 'invalid');
        })->call($service, '{}', 'content');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty html');

        (function (array $payload): array {
            return $this->ensureAiPayloadValid($payload, 'content', 'content/structured-section');
        })->call($service, $decoded);
    }

    public function testFrameworkBuilderRendersComponentIdCssScopeForGeneratedContent(): void
    {
        $componentInfo = [
            'name' => 'Scoped Content Block',
            'name_en' => 'Scoped Content Block',
            'description' => 'renders scoped css selector',
        ];
        $aiData = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-scoped-panel { display:grid; gap:16px; }',
            'css_responsive' => '#componentId .pb-scoped-panel { grid-template-columns:1fr; }',
            'html_content' => '<div class="pb-scoped-panel"><h2>Scoped preview</h2><p>Generated section copy.</p></div>',
            'js_content' => '',
        ];

        $phtml = (new FrameworkBuilder())->buildComponent('content', $componentInfo, $aiData);

        self::assertStringContainsString('#<?= $componentId ?> .pb-scoped-panel', $phtml);
        self::assertStringNotContainsString('#componentId .pb-scoped-panel', $phtml);
    }

    public function testFrameworkBuilderRepairsMalformedComponentIdCssScopeForGeneratedContent(): void
    {
        $componentInfo = [
            'name' => 'Scoped Content Block',
            'name_en' => 'Scoped Content Block',
            'description' => 'repairs malformed scoped css selector',
        ];
        $aiData = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#= $componentId .pb-scoped-panel { display:grid; gap:16px; }',
            'html_content' => '<div class="pb-scoped-panel"><h2>Scoped preview</h2><p>Generated section copy.</p></div>',
            'js_content' => '',
        ];

        $phtml = (new FrameworkBuilder())->buildComponent('content', $componentInfo, $aiData);

        self::assertStringContainsString('#<?= $componentId ?> .pb-scoped-panel', $phtml);
        self::assertStringNotContainsString('#= $componentId', $phtml);
    }

    public function testAttemptSyntaxFixRepairsMalformedPhpEchoTagInRequiredHtmlContent(): void
    {
        $service = new AiSitePageComponentGenerationService(
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $componentInfo = [
            'name' => 'Malformed Echo Card',
            'name_en' => 'Malformed Echo Card',
            'description' => 'repair malformed php echo tags',
        ];
        $aiData = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '',
            'css_content' => '',
            'css_responsive' => '',
            'html_content' => <<<'HTML'
<div class="ai-card">
    <h3><?php = htmlspecialchars($getConfig('content.title', 'Section'), ENT_QUOTES, 'UTF-8') ?></h3>
</div>
HTML,
            'js_content' => '',
        ];

        $frameworkBuilder = new FrameworkBuilder();
        $phtml = $frameworkBuilder->buildComponent('content', $componentInfo, $aiData);
        $validator = new CodeValidator();
        $initialCheck = $validator->checkSyntax($phtml);

        self::assertFalse($initialCheck['valid']);

        $fixedPhtml = (function (string $phtml, string $region, array $componentInfo, array $aiData, array $initialCheck): string {
            return $this->attemptSyntaxFix($phtml, $region, $componentInfo, $aiData, $initialCheck);
        })->call($service, $phtml, 'content', $componentInfo, $aiData, $initialCheck);

        $fixedCheck = $validator->checkSyntax($fixedPhtml);
        self::assertTrue($fixedCheck['valid'], (string)($fixedCheck['error'] ?? 'syntax should be valid after repair'));
        self::assertStringNotContainsString('<?php =', $fixedPhtml);
        self::assertStringContainsString('<?= htmlspecialchars(', $fixedPhtml);
    }

    public function testEnsureAiPayloadValidRepairsLooseArrowAssignmentsInsideFooterMarkup(): void
    {
        $service = new AiSitePageComponentGenerationService(
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '',
            'css_content' => '',
            'css_responsive' => '',
            'html_extra_column' => <<<'HTML'
<div class="ai-footer-extra">
    <?php $footerNote => 'Need help?'; ?>
    <p><?= htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8') ?></p>
</div>
HTML,
            'html_extra' => '',
            'footer_extra_text' => '',
            'js_content' => '',
        ];

        $validatedPayload = (function (array $payload): array {
            return $this->ensureAiPayloadValid($payload, 'footer');
        })->call($service, $payload);

        self::assertSame('', (string)($validatedPayload['html_extra_column'] ?? ''));

        $componentInfo = [
            'name' => 'Footer With Inline Php',
            'name_en' => 'Footer With Inline Php',
            'description' => 'repair loose arrow assignments in embedded footer php',
        ];
        $phtml = (new FrameworkBuilder())->buildComponent('footer', $componentInfo, $validatedPayload);
        $check = (new CodeValidator())->checkSyntax($phtml);

        self::assertTrue($check['valid'], (string)($check['error'] ?? 'footer markup should stay syntax-valid after repair'));
    }

    public function testBuildSectionGenerationPromptIncludesBuildPlanTaskContext(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $prompt = (function (string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): string {
            return $this->buildSectionGenerationPrompt($pageType, $section, $blueprint, $websiteProfile, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero',
                'key' => 'hero',
                'name' => 'Hero',
                'template' => 'hero',
                'config' => [],
            ],
            [
                'page_label' => 'Home',
                'page_title' => 'Task Plan Test',
                'ai_description' => 'Explain value',
                'meta_title' => 'Task Plan Test',
                'meta_description' => 'Explain value',
                'meta_keywords' => 'task,plan',
            ],
            [
                'site_title' => 'Task Plan Test',
                'brief_description' => 'Explain value',
                'content_locale' => 'zh_Hans_CN',
                'default_locale' => 'en_US',
                'plan_locale' => 'en_US',
            ],
            [
                'content_locale' => 'zh_Hans_CN',
                'default_locale' => 'en_US',
                'plan_locale' => 'en_US',
                'build_blueprint' => [
                    'source' => 'build_plan_v2',
                    'build_plan_signature' => 'build-plan-signature',
                    'tasks' => [
                        [
                            'task_key' => 'page:home_page:content/home-page-hero',
                            'task_type' => 'page_section',
                            'page_type' => 'home_page',
                            'section_code' => 'content/home-page-hero',
                            'plan_context' => [
                                'page_goal' => 'Explain value',
                                'page_design_plan' => [
                                    'color_layering' => 'hero panel over dark base, proof cards on lighter surface, amber CTA accent',
                                    'section_flow' => ['hero impact', 'proof layer', 'final CTA'],
                                    'interaction_notes' => ['CTA hover glow', 'card lift'],
                                ],
                                'page_flow_role' => 'opening',
                                'block_goal' => 'Open with a clear value proposition.',
                            ],
                            'task_script' => [
                                'story_goal' => 'Make the hero conversion-ready.',
                                'content_fill_rule' => 'Use short headline and one CTA.',
                                'stage3_directive' => 'Follow the confirmed hero task contract.',
                                'field_content_requirements' => [
                                    ['field' => 'title', 'sample' => 'Grow faster with our service', 'reason' => 'Lead with value'],
                                ],
                            ],
                            'implementation_contract' => [
                                'acceptance' => ['Hero must render value proposition and CTA.'],
                            ],
                            'block_task' => [
                                'content_plan' => [
                                    'headline' => 'Use a launch-ready hero promise from the block plan.',
                                    'body' => 'Explain one primary benefit and one proof point.',
                                ],
                                'style_plan' => [
                                    'visual' => 'Use a layered neon card visual with CSS depth.',
                                    'page_design_plan' => [
                                        'color_layering' => 'hero panel over dark base, proof cards on lighter surface, amber CTA accent',
                                    ],
                                    'page_flow_role' => 'opening',
                                ],
                            ],
                            'runtime_context' => [
                                'task_session_id' => 'abc123',
                                'theme_context_snapshot' => [
                                    'visual_direction' => [
                                        'name' => 'Festival Neon',
                                        'visual_tone' => 'bright gaming trust',
                                    ],
                                    'palette' => [
                                        'primary' => '#101827',
                                        'accent' => '#f59e0b',
                                    ],
                                ],
                                'shared_prompt_context' => [
                                    'brand_promise' => 'Fast game discovery with trusted checkout.',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        self::assertStringContainsString('Build-plan task context for this section:', $prompt);
        self::assertStringContainsString('page_design_plan', $prompt);
        self::assertStringContainsString('BANNER MODULE DEFAULT RULE', $prompt);
        self::assertStringContainsString('FULL-WIDTH 1920x750-style banner', $prompt);
        self::assertStringContainsString('dark gradient overlay', $prompt);
        self::assertStringContainsString('page_flow_role: opening', $prompt);
        self::assertStringNotContainsString('Follow the confirmed hero task contract.', $prompt);
        self::assertStringNotContainsString('Grow faster with our service', $prompt);
        self::assertStringContainsString('build_plan.task_script', $prompt);
        self::assertStringContainsString('build_plan.block_task', $prompt);
        self::assertStringContainsString('block_task.content_plan', $prompt);
        self::assertStringContainsString('block_task.style_plan', $prompt);
        self::assertStringContainsString('apply page_design_plan.color_layering and section_flow before local block styling', $prompt);
        self::assertStringContainsString('Weline/PageBuilder skill contract / frontend skill contract', $prompt);
        self::assertStringContainsString('page-design-plan', $prompt);
        self::assertStringContainsString('frontend-components', $prompt);
        self::assertStringContainsString('AI BUILDER SKILL CAPABILITY (mandatory, default-loaded):', $prompt);
        self::assertStringContainsString('code=claude-design', $prompt);
        self::assertStringContainsString('CLAUDE-DESIGN HARD RULES', $prompt);
        self::assertStringContainsString('content_locale/default_locale: zh_Hans_CN', $prompt);
        self::assertStringContainsString('plan_locale: en_US is only an internal planning language hint', $prompt);
        self::assertStringContainsString('Visitor-visible copy must use content_locale/default_locale', $prompt);
        self::assertStringContainsString('Planned content is not exempt', $prompt);
        self::assertStringContainsString('translate/rewrite them into content_locale/default_locale before rendering', $prompt);
        self::assertStringContainsString('Never render internal identifiers or paths as visible copy', $prompt);
        self::assertStringContainsString('plan_locale, page_type, section_code, task_key', $prompt);
        self::assertStringContainsString('Rewrite planning/observation sentences into direct marketing copy', $prompt);
        self::assertStringContainsString('Visitors see', $prompt);
        self::assertStringContainsString('访客看到', $prompt);
        self::assertStringContainsString('Never render broken image placeholders', $prompt);
        self::assertStringContainsString('CSS-only shapes/pseudo-elements', $prompt);
        self::assertStringNotContainsString('inline SVG or CSS shapes', $prompt);
        self::assertStringContainsString('Images: never output broken image placeholders', $prompt);
        self::assertStringContainsString('Visual excellence system prompt for section', $prompt);
        self::assertStringContainsString('Section quality floor', $prompt);
        self::assertStringContainsString('pale background + ordinary cards + small default buttons', $prompt);
        self::assertStringContainsString('Customer-intent lock', $prompt);
        self::assertStringContainsString('Interaction/effects requirement', $prompt);
        self::assertStringContainsString('Do not leave css_extra empty', $prompt);
        self::assertStringContainsString('HTML structure contract', $prompt);
        self::assertStringContainsString('html fragment rule', $prompt);
        self::assertStringContainsString('no single-hyphen CSS properties such as `-index`', $prompt);
        self::assertStringContainsString('JSON transport self-check', $prompt);
        self::assertStringContainsString('no-overlap structure rule', $prompt);
        self::assertStringContainsString('build-plan language rule', $prompt);
        self::assertStringContainsString('rewrite any planned text that is not in the website content language', $prompt);
        self::assertStringContainsString('Brand/profile normalization', $prompt);
        self::assertStringContainsString('Instruction-shaped English copy is forbidden', $prompt);
        self::assertStringContainsString('Editable-field completeness', $prompt);
    }

    public function testBuildSectionDefaultConfigUsesTaskPlanFieldSamples(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $config = (function (string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): array {
            return $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero',
                'key' => 'hero',
                'name' => 'Hero',
                'template' => 'hero',
                'config' => [],
            ],
            [
                'page_title' => 'Task Plan Test',
                'page_label' => 'Home',
                'ai_description' => 'Explain value',
            ],
            [
                'site_title' => 'Task Plan Test',
                'brief_description' => 'Explain value',
            ],
            [
                'build_blueprint' => [
                    'source' => 'build_plan_v2',
                    'tasks' => [
                        [
                            'task_key' => 'page:home_page:content/home-page-hero',
                            'task_type' => 'page_section',
                            'page_type' => 'home_page',
                            'section_code' => 'content/home-page-hero',
                            'task_script' => [
                                'field_content_requirements' => [
                                    ['field' => 'title', 'sample' => 'Grow faster with our service', 'reason' => 'Lead with value'],
                                    ['field' => 'description', 'sample' => 'Launch faster with a focused hero message.', 'reason' => 'Clarify value'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        self::assertSame('Grow faster with our service', (string)($config['content.title'] ?? ''));
        self::assertSame('Launch faster with a focused hero message.', (string)($config['content.description'] ?? ''));
        self::assertSame('Grow faster with our service', (string)($config['title'] ?? ''));
        self::assertSame('Grow faster with our service', (string)($config['heading'] ?? ''));
        self::assertSame('Grow faster with our service', (string)($config['content.heading'] ?? ''));
        self::assertSame('Launch faster with a focused hero message.', (string)($config['description'] ?? ''));
        self::assertSame('Launch faster with a focused hero message.', (string)($config['body'] ?? ''));
        self::assertSame('Launch faster with a focused hero message.', (string)($config['content.body'] ?? ''));
    }

    public function testBuildPlanRootReadsBuildBlueprintTasks(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $root = (function (array $scope): array {
            return $this->resolveBuildPlanTaskRoot($scope);
        })->call($service, [
            'build_blueprint' => [
                'source' => 'build_plan_v2',
                'signature' => 'build-blueprint-signature',
                'build_plan_signature' => 'build-plan-signature',
                'tasks' => [
                    [
                        'task_key' => 'page:home_page:content/home-page-hero',
                        'task_type' => 'page_section',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-hero',
                        'task_script' => [
                            'story_goal' => 'Build a conversion-ready hero.',
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame(
            'Build a conversion-ready hero.',
            (string)($root['page_tasks']['home_page'][0]['task_script']['story_goal'] ?? '')
        );
    }

    public function testBuildPlanPromptUsesScopeLevelRuntimeContextWhenTaskSnapshotsAreCompacted(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $task = [
            'task_key' => 'page:home_page:content/home-page-hero',
            'plan_context' => [
                'page_goal' => 'Explain the offer clearly.',
                'block_goal' => 'Drive CTA clicks.',
            ],
            'task_script' => [
                'story_goal' => 'Create a credible conversion hero.',
            ],
            'runtime_context' => [
                'stage2_context_hash' => 'stage2-context-hash',
            ],
        ];
        $scope = [
            'execution_blueprint' => [
                'theme_context_snapshot' => [
                    'visual_tone' => 'Measured premium trust',
                    'palette' => ['primary' => '#0f172a'],
                ],
                'shared_prompt_context' => [
                    'navigation_tone' => 'Compact trust navigation',
                ],
            ],
        ];

        $prompt = (function (array $taskPlanTask, string $contextLabel, array $scope): string {
            return $this->buildBuildPlanTaskPromptAddon($taskPlanTask, $contextLabel, $scope);
        })->call($service, $task, 'section', $scope);

        self::assertStringContainsString('stage2-context-hash', $prompt);
        self::assertStringContainsString('Measured premium trust', $prompt);
        self::assertStringContainsString('Compact trust navigation', $prompt);
    }

    public function testBuildPlanPromptDoesNotTreatPlaceholderManifestAsVerifiedAsset(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $placeholderUrl = '/pub/media/page-build/demo/ai-generated/home-hero-old.svg';
        $task = [
            'task_key' => 'page:home_page:content/home-page-hero',
            'plan_context' => [
                'page_goal' => 'Explain the offer clearly.',
                'block_goal' => 'Drive CTA clicks.',
            ],
            'task_script' => [
                'story_goal' => 'Create a credible conversion hero.',
            ],
        ];
        $scope = [
            'asset_manifest' => [
                'slots' => [
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'source' => 'generated',
                        'status' => 'done',
                        'final_url' => $placeholderUrl,
                        'variants' => [[
                            'url' => $placeholderUrl,
                            'mode' => 'placeholder',
                            'model' => 'placeholder',
                            'placeholder' => 1,
                        ]],
                    ],
                ],
            ],
        ];

        $prompt = (function (array $taskPlanTask, string $contextLabel, array $scope): string {
            return $this->buildBuildPlanTaskPromptAddon($taskPlanTask, $contextLabel, $scope);
        })->call($service, $task, 'section', $scope);

        self::assertStringContainsString('verified_assets: []', $prompt);
        self::assertStringNotContainsString($placeholderUrl, $prompt);
    }

    public function testBuildPlanPromptIncludesClosedVerifiedImageAllowlist(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $slotId = 'page:home_page:content-home-page-game-showcase-or-features';
        $finalUrl = '/pub/media/page-build/demo/ai-generated/game-showcase.webp';
        $task = [
            'task_key' => 'page:home_page:game_showcase_or_features',
            'page_type' => 'home_page',
            'block_key' => 'game_showcase_or_features',
            'section_code' => 'content/home-page-game-showcase-or-features',
            'plan_context' => [
                'page_goal' => 'Explain the game suite clearly.',
                'block_goal' => 'Show the gameplay offer with a real generated visual.',
            ],
        ];
        $scope = [
            'asset_manifest' => [
                'slots' => [
                    $slotId => [
                        'slot_id' => $slotId,
                        'slot_type' => 'section_image',
                        'source' => 'generated',
                        'status' => 'done',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-game-showcase-or-features',
                        'final_url' => $finalUrl,
                    ],
                ],
            ],
        ];

        $prompt = (function (array $taskPlanTask, string $contextLabel, array $scope): string {
            return $this->buildBuildPlanTaskPromptAddon($taskPlanTask, $contextLabel, $scope);
        })->call($service, $task, 'section', $scope);

        self::assertStringContainsString('verified_asset_src_allowlist', $prompt);
        self::assertStringContainsString($finalUrl, $prompt);
        self::assertStringContainsString("<img src='" . $finalUrl . "'", $prompt);
        self::assertStringContainsString("data-pb-ai-asset-slot='" . $slotId . "'", $prompt);
        self::assertStringContainsString('External image ban', $prompt);
        self::assertStringContainsString('images.unsplash.com', $prompt);
    }

    public function testSectionPromptIgnoresUnconfirmedTaskPlanDrafts(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $prompt = (function (string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): string {
            return $this->buildSectionGenerationPrompt($pageType, $section, $blueprint, $websiteProfile, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero',
                'key' => 'hero',
                'name' => 'Hero',
                'template' => 'hero',
                'config' => [],
            ],
            [
                'page_title' => 'Task Plan Test',
                'page_label' => 'Home',
                'ai_description' => 'Explain value',
            ],
            [
                'site_title' => 'Task Plan Test',
                'brief_description' => 'Explain value',
            ],
            [
                'task_plan_confirmed' => 0,
                'task_plan_structured' => [
                    'page_tasks' => [
                        'home_page' => [
                            [
                                'task_key' => 'page:home_page:content/home-page-hero',
                                'section_code' => 'content/home-page-hero',
                                'task_script' => ['stage3_directive' => 'UNCONFIRMED STRUCTURED TASK MUST NOT BE USED'],
                            ],
                        ],
                    ],
                ],
                'virtual_theme_plan' => [
                    'draft' => [
                        'page_tasks' => [
                            'home_page' => [
                                [
                                    'task_key' => 'page:home_page:content/home-page-hero',
                                    'section_code' => 'content/home-page-hero',
                                    'task_script' => ['stage3_directive' => 'UNCONFIRMED DRAFT TASK MUST NOT BE USED'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        self::assertStringNotContainsString('UNCONFIRMED STRUCTURED TASK MUST NOT BE USED', $prompt);
        self::assertStringNotContainsString('UNCONFIRMED DRAFT TASK MUST NOT BE USED', $prompt);
        self::assertStringNotContainsString('Stage-2 task context for this section:', $prompt);
    }

    public function testSectionDefaultConfigDoesNotUsePageLabelAsVisibleEyebrow(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $config = (function (string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): array {
            return $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero',
                'key' => 'hero',
                'name' => 'Hero',
                'template' => 'hero',
                'config' => [],
            ],
            [
                'page_title' => 'Royal Indian Games',
                'page_label' => '首页',
                'ai_description' => 'Explain value',
            ],
            [
                'site_title' => 'Royal Indian Games',
                'brief_description' => 'Explain value',
            ],
            []
        );

        self::assertSame('', (string)($config['content.subtitle'] ?? ''));
    }

    public function testSharedComponentPromptAndDefaultsUseConfirmedThemeContract(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );
        $scope = [
            'build_blueprint' => [
                'source' => 'build_plan_v2',
                'tasks' => [
                        [
                            'task_key' => 'shared:header',
                            'region' => 'header',
                            'task_script' => [
                                'data_contract' => [
                                    'required_data' => [
                                        'primary_cta_label: 立即开始游戏',
                                        'primary_cta_href: /games',
                                    ],
                                ],
                                'field_content_requirements' => [
                                    ['field' => 'title', 'sample' => 'header'],
                                    ['field' => 'platform_name', 'sample' => 'Royal Indian Games'],
                                    ['field' => 'cta_text', 'sample' => '立即开始游戏'],
                                ],
                            ],
                            'runtime_context' => [
                                'theme_context_snapshot' => [
                                    'visual_direction' => [
                                        'name' => 'Midnight Ember',
                                        'visual_tone' => 'trustworthy gaming',
                                        'font_family' => 'Poppins, Inter, sans-serif',
                                    ],
                                    'style_signature' => 'arcade trust dashboard with ember card accents',
                                    'art_direction' => [
                                        'layout_motif' => 'arcade cards and trust badges',
                                        'background_system' => 'dark ember gradient with soft glow',
                                        'surface_treatment' => 'glass cards with gold borders',
                                        'visual_detail_rule' => 'use inline SVG game tokens',
                                        'motion_rule' => 'restrained hover lift',
                                    ],
                                    'palette' => [
                                        'primary' => '#111827',
                                        'accent' => '#f59e0b',
                                        'secondary' => '#dc2626',
                                        'surface' => '#1f2937',
                                        'text' => '#f9fafb',
                                    ],
                                ],
                            ],
                        ],
                ],
            ],
        ];

        $config = (function (array $websiteProfile, array $scope, string $siteDisplayName): array {
            return $this->buildHeaderDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        })->call(
            $service,
            ['site_title' => 'Royal Indian Games', 'brief_description' => 'Indian card and board games'],
            $scope,
            'Royal Indian Games'
        );
        $prompt = (function (array $websiteProfile, array $scope, string $siteDisplayName, array $headerConfig): string {
            return $this->buildHeaderGenerationPrompt($websiteProfile, $scope, $siteDisplayName, $headerConfig);
        })->call(
            $service,
            ['site_title' => 'Royal Indian Games', 'brief_description' => 'Indian card and board games'],
            $scope,
            'Royal Indian Games',
            $config
        );

        self::assertSame('#1f2937', (string)($config['style.bg_color'] ?? ''));
        self::assertSame('#f9fafb', (string)($config['style.text_color'] ?? ''));
        self::assertSame('#f59e0b', (string)($config['style.accent_color'] ?? ''));
        self::assertSame('Royal Indian Games', (string)($config['logo.text'] ?? ''));
        self::assertSame('立即开始游戏', (string)($config['cta.text'] ?? ''));
        self::assertSame('/games', (string)($config['cta.url'] ?? ''));
        self::assertStringContainsString('Confirmed visual contract', $prompt);
        self::assertStringContainsString('#f59e0b', $prompt);
        self::assertStringContainsString('style_signature', $prompt);
        self::assertStringContainsString('arcade trust dashboard', $prompt);
        self::assertStringContainsString('Visual excellence system prompt for header', $prompt);
        self::assertStringContainsString('polished enough for a paying customer preview', $prompt);
        self::assertStringContainsString('Header quality floor', $prompt);
        self::assertStringContainsString('Do not leave css_extra empty', $prompt);
        self::assertStringContainsString('Do not invent unrelated accent colors', $prompt);
        self::assertStringContainsString('Weline/PageBuilder skill contract', $prompt);
        self::assertStringContainsString('pagebuilder-style-templates', $prompt);
        self::assertStringContainsString('theme-development', $prompt);
        self::assertStringContainsString('AI BUILDER SKILL CAPABILITY (mandatory, default-loaded):', $prompt);
        self::assertStringContainsString('code=claude-design', $prompt);
        self::assertStringContainsString('CLAUDE-DESIGN HARD RULES', $prompt);
        self::assertStringContainsString('primary_cta_label', $prompt);
    }

    public function testBuildFooterGenerationPromptIncludesDefaultClaudeDesignSkill(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $prompt = (function (array $websiteProfile, array $scope, string $siteDisplayName, array $footerConfig): string {
            return $this->buildFooterGenerationPrompt($websiteProfile, $scope, $siteDisplayName, $footerConfig);
        })->call(
            $service,
            ['site_title' => 'Royal Indian Games', 'brief_description' => 'Indian card and board games'],
            [
                'content_locale' => 'en_US',
                'default_locale' => 'en_US',
                'theme_design' => [
                    'style_signature' => 'arcade trust footer',
                    'color_scheme' => [
                        'primary' => '#111827',
                        'surface' => '#1f2937',
                        'text' => '#f9fafb',
                        'accent' => '#f59e0b',
                    ],
                ],
            ],
            'Royal Indian Games',
            [
                'links.column1_items' => "Games=>/games\nRewards=>/rewards",
                'links.column2_items' => "Privacy=>/privacy\nTerms=>/terms",
                'links.column3_items' => "Support=>/contact\nFAQ=>/faq",
            ]
        );

        self::assertStringContainsString('AI BUILDER SKILL CAPABILITY (mandatory, default-loaded):', $prompt);
        self::assertStringContainsString('code=claude-design', $prompt);
        self::assertStringContainsString('CLAUDE-DESIGN HARD RULES', $prompt);
        self::assertStringContainsString('Footer quality floor', $prompt);
        self::assertStringContainsString('Footer link data', $prompt);
    }

    public function testVirtualThemeComponentPolicyRemovesFrameworkReinventionFromAiPayload(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $payload = [
            'extra_fields' => ['group:content' => '内容设置'],
            'php_variables' => '$navItems = [];',
            'css_extra' => '#<?= $componentId ?> .cta { color: red; }',
            'html_extra' => '<section>@component_start <style>.x{}</style><?= $unsafe ?></section>',
            'js_content' => 'window.x = 1;',
        ];

        $validated = (function (array $payload, string $region): array {
            return $this->ensureAiPayloadValid($payload, $region);
        })->call($service, $payload, 'header');

        self::assertSame('', $validated['extra_fields']);
        self::assertSame('', $validated['php_variables']);
        self::assertSame('', $validated['css_extra']);
        self::assertSame('', $validated['html_extra']);
        self::assertSame('', $validated['js_content']);
    }

    public function testVirtualThemeComponentPolicyPreservesContentCssBudgetForVisualPolish(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $rules = [];
        for ($index = 0; $index < 12; $index++) {
            $rules[] = '#componentId .pb-rich-' . $index . ' { min-height:1px; background:linear-gradient(135deg,var(--section-primary),var(--section-accent)); border-radius:24px; box-shadow:0 18px 44px rgba(15,23,42,.14); }';
        }
        $css = \implode("\n", $rules) . "\n#componentId .pb-rich-sentinel { color:#123456; }";
        self::assertGreaterThan(1800, \strlen($css));
        self::assertLessThan(2800, \strlen($css));

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => $css,
            'html_content' => '<div class="pb-rich-0 pb-rich-sentinel"><span class="pb-rich-css-orb" aria-hidden="true"></span><h2>Teenipiya APK Rewards</h2><p>Compare safe download paths, payout records, and support options before installing.</p></div>',
            'js_content' => '',
        ];

        $validated = (function (array $payload, string $region, string $componentCode): array {
            return $this->ensureAiPayloadValid($payload, $region, $componentCode);
        })->call($service, $payload, 'content', 'content/home-page-featured-plugins-grid');

        self::assertGreaterThan(1800, \strlen((string)($validated['css_extra'] ?? '')));
        self::assertStringContainsString('pb-rich-sentinel', (string)($validated['css_extra'] ?? ''));
    }

    public function testVirtualThemeComponentPolicyRejectsMalformedHtmlStructure(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-privacy-card { display:grid; gap:18px; padding:28px; border-radius:24px; background:linear-gradient(135deg,#111827,#f59e0b); }',
            'html_content' => '<div class="pb-privacy-card</div></div><section><h2>Privacy proof</h2><p>Clear policy details for every visitor.</p></section>',
            'js_content' => '',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI component HTML structure invalid');

        (function (array $payload, string $region): array {
            return $this->ensureAiPayloadValid($payload, $region);
        })->call($service, $payload, 'content');
    }

    public function testVirtualThemeComponentPolicyRejectsMissingTagNameBeforeAttributes(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-card { display:grid; gap:18px; padding:28px; }',
            'html_content' => '<div class="pb-card">< class="pb-card-icon" aria-hidden="true"></class><h2>Safe tables</h2><p>Download trusted card games with clear proof.</p></div>',
            'js_content' => '',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('opening tag is missing an element name');

        (function (array $payload, string $region): array {
            return $this->ensureAiPayloadValid($payload, $region);
        })->call($service, $payload, 'content');
    }

    public function testVirtualThemeComponentPolicyAllowsVisitorFormPlaceholderAttributes(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-contact-panel { display:grid; grid-template-columns:1fr 1fr; gap:24px; padding:28px; border-radius:28px; background:linear-gradient(135deg,var(--section-primary),var(--section-accent)); } #componentId .pb-contact-form { display:grid; gap:14px; } #componentId .pb-contact-card { padding:18px; border-radius:18px; background:rgba(255,255,255,.86); }',
            'css_responsive' => '#componentId .pb-contact-panel { grid-template-columns:1fr; }',
            'html_content' => '<div class="pb-contact-panel"><div class="pb-contact-card"><h2>Message Teen Patti Royal</h2><p>Ask for APK download help, safety notes, and VIP table guidance before installing.</p></div><form class="pb-contact-form" aria-label="APK support form"><label>Name<input name="name" placeholder="Your name"></label><label>WhatsApp<input name="phone" placeholder="+91 phone number"></label><button type="button">Request APK link</button></form></div>',
            'js_content' => '',
        ];

        $validated = (function (array $payload, string $region, string $componentCode): array {
            return $this->ensureAiPayloadValid($payload, $region, $componentCode);
        })->call($service, $payload, 'content', 'content/contact-page-contact-form-and-info');

        self::assertStringContainsString('placeholder="Your name"', (string)($validated['html_content'] ?? ''));
        self::assertStringContainsString('pb-contact-panel', (string)($validated['html_content'] ?? ''));
    }

    public function testVirtualThemeComponentPolicyAllowsVisitorSocialLinkCluster(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-social-links { display:flex; flex-wrap:wrap; gap:12px; padding:18px; border-radius:22px; background:linear-gradient(135deg,var(--section-primary),var(--section-accent)); } #componentId .pb-social-link { color:white; font-weight:700; text-decoration:none; }',
            'css_responsive' => '#componentId .pb-social-links { display:grid; }',
            'html_content' => '<nav class="pb-social-links" aria-label="Teen Patti social channels"><a class="pb-social-link" href="#whatsapp">WhatsApp</a><a class="pb-social-link" href="#telegram">Telegram</a><a class="pb-social-link" href="#instagram">Instagram</a></nav>',
            'js_content' => '',
        ];

        $validated = (function (array $payload, string $region, string $componentCode): array {
            return $this->ensureAiPayloadValid($payload, $region, $componentCode);
        })->call($service, $payload, 'content', 'content/contact-page-social-media-links');

        self::assertStringContainsString('WhatsApp', (string)($validated['html_content'] ?? ''));
        self::assertStringContainsString('Telegram', (string)($validated['html_content'] ?? ''));
        self::assertStringContainsString('Instagram', (string)($validated['html_content'] ?? ''));
    }

    public function testVirtualThemeComponentPolicyAllowsCompactVisitorArticleList(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-blog-list { display:flex; flex-direction:column; gap:12px; padding:18px; border-radius:18px; background:var(--section-bg); } #componentId .pb-blog-entry { display:flex; justify-content:space-between; gap:10px; }',
            'css_responsive' => '#componentId .pb-blog-entry { flex-direction:column; }',
            'html_content' => '<ul class="pb-blog-list"><li class="pb-blog-entry"><a href="#tips">Tips</a><time>Today</time></li><li class="pb-blog-entry"><a href="#news">News</a><span>3 min read</span></li></ul>',
            'js_content' => '',
        ];

        $validated = (function (array $payload, string $region, string $componentCode): array {
            return $this->ensureAiPayloadValid($payload, $region, $componentCode);
        })->call($service, $payload, 'content', 'content/blog-category-article-list');

        self::assertStringContainsString('Tips', (string)($validated['html_content'] ?? ''));
        self::assertStringContainsString('News', (string)($validated['html_content'] ?? ''));
    }

    public function testVirtualThemeComponentPolicyDoesNotRewritePageTypeEyebrowLabels(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '',
            'html_content' => '<div class="hero-eyebrow">首页</div><div class="feature-card"><h3>Royal Indian Games</h3><p>印度棋牌体验，安全公平的现代化游戏社区。</p></div>',
            'js_content' => '',
        ];

        $validated = (function (array $payload, string $region): array {
            return $this->ensureAiPayloadValid($payload, $region);
        })->call($service, $payload, 'content');

        self::assertStringContainsString('hero-eyebrow', (string)($validated['html_content'] ?? ''));
        self::assertStringContainsString('Royal Indian Games', (string)($validated['html_content'] ?? ''));
    }

    public function testVirtualThemeComponentPolicyRejectsBrokenImages(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '',
            'html_content' => '<figure><img src="" alt="Game cards"><img src="https://example.com/hero.jpg" alt="Hero visual"></figure>',
            'js_content' => '',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI');

        (function (array $payload, string $region): array {
            return $this->ensureAiPayloadValid($payload, $region);
        })->call($service, $payload, 'content');
    }

    public function testVirtualThemeComponentPolicyRejectsStockImageUrlsWithoutFileExtension(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '',
            'html_content' => '<figure><img src="://images.unsplash.com/photo-1601370690183-1c7796ecec61?w=1200&q=80" alt="Generated gameplay visual"></figure>',
            'js_content' => '',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI');

        (function (array $payload, string $region): array {
            return $this->ensureAiPayloadValid($payload, $region);
        })->call($service, $payload, 'content');
    }

    public function testVirtualThemeComponentPolicyRejectsBrokenBackgroundImages(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '',
            'html_content' => '<div class="visual" style="background-image:url(https://example.com/card.webp)">Royal Indian Games</div>',
            'js_content' => '',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI');

        (function (array $payload, string $region): array {
            return $this->ensureAiPayloadValid($payload, $region);
        })->call($service, $payload, 'content');
    }

    public function testVirtualThemeComponentPolicyRejectsBrokenCssBackgroundImages(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '.hero-card { background-image: url("https://example.com/card.webp"); }',
            'html_content' => '<div class="visual-card"><h2>Royal Indian Games</h2><p>Trusted play, fast discovery, and clear rewards for every visitor.</p></div>',
            'js_content' => '',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI');

        (function (array $payload, string $region): array {
            return $this->ensureAiPayloadValid($payload, $region);
        })->call($service, $payload, 'content');
    }

    public function testHeaderDefaultConfigUsesConfirmedSharedPromptContextNavigationForEnglishLocale(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $config = (function (array $websiteProfile, array $scope, string $siteDisplayName): array {
            return $this->buildHeaderDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        })->call(
            $service,
            ['site_title' => 'Arc Metrics'],
            [
                'default_locale' => 'en_US',
                'page_types' => ['home_page', 'about_page', 'contact_page'],
                'execution_blueprint' => [
                    'shared_prompt_context' => [
                        'header_items' => [
                            ['label' => 'Home', 'href' => '/', 'type' => 'home_page'],
                            ['label' => 'About', 'href' => '/about', 'type' => 'about_page'],
                            ['label' => 'Contact', 'href' => '/contact', 'type' => 'contact_page'],
                        ],
                        'shared_cta_strategy' => [
                            'primary_action' => 'Start Free',
                        ],
                    ],
                ],
            ],
            'Arc Metrics'
        );

        self::assertSame('Start Free', (string)($config['cta.text'] ?? ''));
        self::assertSame('Home', (string)($config['nav_items'][0]['text'] ?? ''));
        self::assertSame('/about', (string)($config['nav_items'][1]['href'] ?? ''));
        self::assertStringContainsString("Home=>/\nAbout=>/about", (string)($config['navigation.items'] ?? ''));
    }

    public function testSharedHeaderFooterUseManifestLogoInsteadOfInlineSvgProfileLogo(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );
        $websiteProfile = [
            'site_title' => 'Royal Cards',
            'logo' => 'data:image/svg+xml;base64,PHN2Zy8+',
        ];
        $scope = [
            'default_locale' => 'en_US',
            'page_types' => ['home_page', 'about_page'],
            'asset_manifest' => [
                'slots' => [
                    [
                        'kind' => 'website_logo',
                        'slot_type' => 'logo_icon',
                        'slot_id' => 'identity:website-logo',
                        'section_code' => 'identity',
                        'page_type' => 'global',
                        'field' => 'logo',
                        'final_url' => '/pub/media/page-build/site/ai-generated/identity-logo.png',
                    ],
                ],
            ],
        ];

        $header = (function (array $websiteProfile, array $scope, string $siteDisplayName): array {
            return $this->buildHeaderDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        })->call($service, $websiteProfile, $scope, 'Royal Cards');
        $footer = (function (array $websiteProfile, array $scope, string $siteDisplayName): array {
            return $this->buildFooterDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        })->call($service, $websiteProfile, $scope, 'Royal Cards');

        self::assertSame('/pub/media/page-build/site/ai-generated/identity-logo.png', $header['logo.image'] ?? null);
        self::assertSame('/pub/media/page-build/site/ai-generated/identity-logo.png', $footer['brand.logo'] ?? null);
        self::assertSame('/pub/media/page-build/site/ai-generated/identity-logo.png', $header['identity.shared_logo_asset'] ?? null);
        self::assertSame('/pub/media/page-build/site/ai-generated/identity-logo.png', $footer['identity.shared_logo_asset'] ?? null);
        self::assertStringContainsString('reuse the same verified logo asset', (string)($header['identity.logo_reuse_policy'] ?? ''));
        self::assertStringContainsString('reuse the same verified logo asset', (string)($footer['identity.logo_reuse_policy'] ?? ''));
        self::assertStringNotContainsString('data:image/svg', (string)\json_encode([$header, $footer], \JSON_UNESCAPED_SLASHES));

        $directoryLogoFooter = (function (array $websiteProfile, array $scope, string $siteDisplayName): array {
            return $this->buildFooterDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        })->call($service, [
            'site_title' => 'Royal Cards',
            'logo' => '/pub/media/page-build/site/ai-generated/',
        ], ['default_locale' => 'en_US'], 'Royal Cards');
        self::assertSame('', $directoryLogoFooter['brand.logo'] ?? null);

        $sectionImageHeader = (function (array $websiteProfile, array $scope, string $siteDisplayName): array {
            return $this->buildHeaderDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        })->call($service, [
            'site_title' => 'Royal Cards',
            'logo' => '',
        ], [
            'default_locale' => 'en_US',
            'asset_manifest' => [
                'slots' => [
                    [
                        'kind' => 'website_logo',
                        'slot_type' => 'logo_icon',
                        'slot_id' => 'identity:website-logo',
                        'section_code' => 'identity',
                        'page_type' => 'global',
                        'field' => 'logo',
                        'final_url' => '/pub/media/page-build/site/ai-generated/trust_badges-5b0447367ad0.jpg',
                    ],
                    [
                        'kind' => 'logo_icon',
                        'slot_type' => 'logo_icon',
                        'slot_id' => 'page:home_page:content-home-page-game-categories',
                        'section_code' => 'content/home-page-game-categories',
                        'page_type' => 'home_page',
                        'field' => 'category_1_title',
                        'final_url' => '/pub/media/page-build/site/ai-generated/page-home_page-content-home-page-game-categories-f38edaa5673f.jpg',
                    ],
                ],
            ],
        ], 'Royal Cards');
        self::assertSame('', $sectionImageHeader['logo.image'] ?? null);
        self::assertSame('', $sectionImageHeader['identity.shared_logo_asset'] ?? null);
    }

    public function testHeaderDefaultConfigRelocalizesSharedPromptContextNavigationWhenLabelsUseWrongLanguage(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $config = (function (array $websiteProfile, array $scope, string $siteDisplayName): array {
            return $this->buildHeaderDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        })->call(
            $service,
            ['site_title' => 'Teenipiya', 'default_locale' => 'en_US'],
            [
                'default_locale' => 'en_US',
                'page_types' => ['home_page', 'about_page', 'contact_page'],
                'execution_blueprint' => [
                    'shared_prompt_context' => [
                        'header_items' => [
                            ['label' => '首页', 'href' => '/', 'type' => 'home_page'],
                            ['label' => '关于我们', 'href' => '/about', 'type' => 'about_page'],
                            ['label' => '联系我们', 'href' => '/contact', 'type' => 'contact_page'],
                        ],
                    ],
                ],
            ],
            'Teenipiya'
        );

        self::assertSame('Home', (string)($config['nav_items'][0]['text'] ?? ''));
        self::assertSame('About', (string)($config['nav_items'][1]['text'] ?? ''));
        self::assertSame('Contact', (string)($config['nav_items'][2]['text'] ?? ''));
        self::assertStringContainsString("Home=>/\nAbout=>/about\nContact=>/contact", (string)($config['navigation.items'] ?? ''));
    }

    public function testSharedComponentSpecsUseNestedPlanChromeForEnglishContentLocale(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $websiteProfile = [
            'site_title' => "\u{5370}\u{5EA6}\u{5E02}\u{573A}\u{7684}\u{68CB}\u{724C}\u{7F51}\u{7AD9}",
            'default_locale' => 'en_US',
            'locales' => ['en_US'],
        ];
        $scope = [
            'default_locale' => 'en_US',
            'plan_generated_locale' => 'zh_Hans_CN',
            'page_types' => ['home_page', 'about_page'],
            'execution_blueprint' => [
                'shared_prompt_context' => [
                    'content_locale' => 'en_US',
                    'plan_locale' => 'zh_Hans_CN',
                    'header_plan' => [
                        'title' => 'Royal Indian Card Games',
                        'site_display_name' => 'Royal Indian Card Games',
                        'payload' => [
                            'header_items' => [
                                ['label' => 'Home', 'href' => '/'],
                                ['label' => 'About', 'href' => '/about'],
                                ['label' => 'Download', 'href' => '/download'],
                            ],
                        ],
                        'realtime_content' => [
                            'cta' => [
                                ['label' => 'Download APK', 'target' => '#download'],
                            ],
                        ],
                    ],
                    'footer_plan' => [
                        'title' => 'Footer',
                        'site_display_name' => 'Royal Indian Card Games',
                        'payload' => [
                            'featured' => [
                                ['label' => 'Teen Patti', 'href' => '/games/teen-patti'],
                                ['label' => 'Rummy', 'href' => '/games/rummy'],
                            ],
                            'policies' => [
                                ['label' => 'Privacy Policy', 'href' => '/privacy'],
                                ['label' => 'Terms of Service', 'href' => '/terms'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $components = (function (array $websiteProfile, array $scope): array {
            return $this->buildSharedComponentGenerationSpecs($websiteProfile, $scope);
        })->call($service, $websiteProfile, $scope);

        $headerConfig = $components['header']['defaultConfig'] ?? [];
        $footerConfig = $components['footer']['defaultConfig'] ?? [];
        $headerPrompt = (string)($components['header']['prompt'] ?? '');

        self::assertSame('Royal Indian Card Games', (string)($headerConfig['logo.text'] ?? ''));
        self::assertSame('Download APK', (string)($headerConfig['cta.text'] ?? ''));
        self::assertSame('#download', (string)($headerConfig['cta.url'] ?? ''));
        self::assertStringContainsString("Home=>/\nAbout=>/about\nDownload=>/download", (string)($headerConfig['navigation.items'] ?? ''));
        self::assertSame('Download', (string)($headerConfig['nav_items'][2]['text'] ?? ''));
        self::assertSame('Royal Indian Card Games', (string)($footerConfig['brand.name'] ?? ''));
        self::assertStringContainsString('Teen Patti=>/games/teen-patti', (string)($footerConfig['links.column1_items'] ?? ''));
        self::assertStringContainsString('Privacy Policy=>/privacy', (string)($footerConfig['links.column2_items'] ?? ''));
        self::assertStringContainsString('Site name: Royal Indian Card Games', $headerPrompt);
        self::assertStringNotContainsString((string)$websiteProfile['site_title'], $headerPrompt);
    }

    public function testSharedComponentSpecsUseRefactoredExecutionBlueprintChrome(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $components = (function (array $websiteProfile, array $scope): array {
            return $this->buildSharedComponentGenerationSpecs($websiteProfile, $scope);
        })->call($service, [
            'site_title' => "\u{5370}\u{5EA6}\u{5E02}\u{573A}\u{7684}\u{68CB}\u{724C}\u{7F51}\u{7AD9}",
            'default_locale' => 'en_US',
            'locales' => ['en_US'],
        ], [
            'website_profile' => [
                'default_locale' => 'en_US',
                'locales' => ['en_US'],
            ],
            'execution_blueprint' => [
                'navigation_plan' => [
                    'title' => 'Royal Indian Card Games',
                    'site_display_name' => 'Royal Indian Card Games',
                    'content_locale' => 'en_US',
                    'payload' => [
                        'header_items' => [
                            ['label' => 'Home', 'href' => '/'],
                            ['label' => 'About', 'href' => '/about'],
                            ['label' => 'Download', 'href' => '/download'],
                        ],
                    ],
                    'realtime_content' => [
                        'cta' => [
                            ['label' => 'Download APK', 'target' => '#download'],
                        ],
                    ],
                ],
                'shared_components' => [
                    'footer' => [
                        'title' => 'Footer',
                        'site_display_name' => 'Royal Indian Card Games',
                        'payload' => [
                            'featured' => [
                                ['label' => 'Teen Patti', 'href' => '/games/teen-patti'],
                            ],
                            'policies' => [
                                ['label' => 'Privacy Policy', 'href' => '/privacy'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $headerConfig = $components['header']['defaultConfig'] ?? [];
        $footerConfig = $components['footer']['defaultConfig'] ?? [];

        self::assertSame('Royal Indian Card Games', (string)($headerConfig['logo.text'] ?? ''));
        self::assertSame('Download APK', (string)($headerConfig['cta.text'] ?? ''));
        self::assertSame('#download', (string)($headerConfig['cta.url'] ?? ''));
        self::assertStringContainsString("Home=>/\nAbout=>/about\nDownload=>/download", (string)($headerConfig['navigation.items'] ?? ''));
        self::assertSame('Download', (string)($headerConfig['nav_items'][2]['text'] ?? ''));
        self::assertSame('Royal Indian Card Games', (string)($footerConfig['brand.name'] ?? ''));
        self::assertStringContainsString('Teen Patti=>/games/teen-patti', (string)($footerConfig['links.column1_items'] ?? ''));
        self::assertStringContainsString('Privacy Policy=>/privacy', (string)($footerConfig['links.column2_items'] ?? ''));
    }

    public function testSectionDefaultConfigPrefersEnglishTaskPlanSamplesOverChineseBlueprintCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $config = (function (string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): array {
            return $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero',
                'key' => 'hero',
                'name' => '首页主视觉',
                'template' => 'hero',
                'config' => [
                    'section_title' => '首页主视觉',
                    'section_intro' => '这是中文默认文案，不应进入英文站点 build。',
                ],
            ],
            [
                'page_title' => '首页',
                'ai_description' => '中文页面描述',
            ],
            [
                'site_title' => 'Arc Metrics',
                'default_locale' => 'en_US',
            ],
            [
                'default_locale' => 'en_US',
                'build_blueprint' => [
                    'source' => 'build_plan_v2',
                    'tasks' => [
                        [
                            'task_key' => 'page:home_page:content/home-page-hero',
                            'task_type' => 'page_section',
                            'page_type' => 'home_page',
                            'section_code' => 'content/home-page-hero',
                            'task_script' => [
                                'story_goal' => 'Open with a crisp analytics promise.',
                                'content_fill_rule' => 'Use one headline and one proof sentence.',
                                'field_content_requirements' => [
                                    ['field' => 'title', 'sample' => 'See campaign clarity in one dashboard'],
                                    ['field' => 'description', 'sample' => 'Turn scattered campaign signals into one clear growth view.'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        self::assertSame('See campaign clarity in one dashboard', (string)($config['content.title'] ?? ''));
        self::assertSame('Turn scattered campaign signals into one clear growth view.', (string)($config['content.description'] ?? ''));
        self::assertStringNotContainsString('中文', (string)($config['content.description'] ?? ''));
    }

    public function testPageSectionSpecsUseRefactoredExecutionBlueprintPagePlans(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $specs = $service->buildPageSectionSpecs('home_page', [
            'site_title' => 'Royal Indian Card Games',
            'default_locale' => 'en_US',
        ], [
            'website_profile' => [
                'default_locale' => 'en_US',
            ],
            'execution_blueprint' => [
                'page_plans' => [
                    'home_page' => [
                        'page_design_plan' => [
                            'color_layering' => 'gold cards over marble surface',
                        ],
                        'blocks' => [
                            [
                                'block_key' => 'game_showcase_or_features',
                                'title' => 'game showcase or features',
                                'goal' => 'Showcase available games with attractive cards to encourage exploration and download.',
                                'field_plan' => [
                                    ['field' => 'section_title', 'sample' => 'Our Royal Games'],
                                    ['field' => 'game_card_1', 'sample' => 'Teen Patti - The King of Card Games'],
                                    ['field' => 'game_card_2', 'sample' => 'Rummy - Classic Strategy Fun'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $sections = $specs['sections'] ?? [];
        self::assertCount(1, $sections);
        $config = $sections[0]['default_config'] ?? [];

        self::assertSame('content/home-page-game-showcase-or-features', (string)($sections[0]['code'] ?? ''));
        self::assertSame('Our Royal Games', (string)($sections[0]['name'] ?? ''));
        self::assertSame('Our Royal Games', (string)($config['content.title'] ?? ''));
        self::assertStringContainsString('Teen Patti - The King of Card Games', (string)($config['runtime.content_copy_rows'] ?? ''));
        self::assertStringNotContainsString('game showcase or features', (string)($config['content.title'] ?? ''));
    }

    public function testGeneratedContentArtifactLetsAiHtmlOwnSectionHeading(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $config = (function (array $defaultConfig, array $aiData): array {
            return $this->applyAiPayloadOwnershipToDefaultConfig($defaultConfig, 'content', $aiData);
        })->call($service, [
            'content.title' => 'Our Royal Games',
            'content.subtitle' => 'Featured games',
            'content.description' => 'Explore the app catalog.',
        ], [
            'html_content' => '<section class="pb-royal-games"><h2>Our Royal Games</h2><p>Teen Patti and Rummy ready for download.</p></section>',
        ]);

        self::assertSame('', (string)($config['content.title'] ?? ''));
        self::assertSame('', (string)($config['content.subtitle'] ?? ''));
        self::assertSame('', (string)($config['content.description'] ?? ''));
    }

    public function testRenderedHtmlLanguageGuardRejectsMeaningfulChineseContentForEnglishLocale(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('website content locale');

        (function (string $html, array $renderContext): void {
            $this->assertRenderedHtmlMatchesLocale($html, $renderContext);
        })->call(
            $service,
            '<section><h2>立即开始体验</h2><p>这是中文段落，长度足够，英文站点不应通过。</p></section>',
            ['_content_locale' => 'en_US']
        );
    }

    public function testResolvedLocaleFallsBackToPlanGeneratedLocaleForComponentPrompt(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $locale = (function (array $websiteProfile, array $scope): string {
            return $this->resolvePrimaryLocale($websiteProfile, $scope);
        })->call(
            $service,
            ['site_title' => 'Royal Indian Games'],
            ['plan_generated_locale' => 'zh_Hans_CN']
        );

        $prompt = (function (array $websiteProfile, array $scope): string {
            return $this->buildVisibleCopyGovernancePromptAddon($websiteProfile, $scope)
                . $this->buildStage3LocaleExecutionPromptAddon($websiteProfile, $scope);
        })->call(
            $service,
            ['site_title' => 'Royal Indian Games'],
            ['plan_generated_locale' => 'zh_Hans_CN']
        );

        self::assertSame('zh_Hans_CN', $locale);
        self::assertStringContainsString('content_locale/default_locale: zh_Hans_CN', $prompt);
        self::assertStringContainsString('source_of_truth_locale: zh_Hans_CN', $prompt);
    }

    public function testChineseLocaleGuardRejectsDominantEnglishVisitorCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('website content locale');

        (function (string $html, array $renderContext): void {
            $this->assertRenderedHtmlMatchesLocale($html, $renderContext);
        })->call(
            $service,
            '<section><h2>Play Teen Patti Like a Royal Download Now</h2><p>Fast secure APK download with smooth gameplay and premium rewards.</p></section>',
            ['_content_locale' => 'zh_Hans_CN']
        );
    }

    public function testChineseLocaleFilteringDropsEnglishProseButKeepsShortProductTerms(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $filter = function (string $value, string $locale): string {
            return $this->filterVisibleCopyForLocale($value, $locale);
        };

        self::assertSame('', $filter->call($service, 'Play Teen Patti Like a Royal Download Now', 'zh_Hans_CN'));
        self::assertSame('Teen Patti', $filter->call($service, 'Teen Patti', 'zh_Hans_CN'));
    }

    public function testSanitizeVisibleCopyDropsPlanningObservationCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $sanitize = function (string $value): string {
            return $this->sanitizeVisibleCopy($value);
        };

        self::assertSame('', $sanitize->call($service, 'Visitors see three cards before publishing.'));
        self::assertSame('', $sanitize->call($service, 'Visitors can review clear steps and proof.'));
        self::assertSame('', $sanitize->call($service, '访客看到三张精致卡片，从而产生下载兴趣。'));
        self::assertSame('', $sanitize->call($service, '信任感增强，并知道如何立即下载 Teen Patti Royal APK。'));
        self::assertSame('Trusted APK rewards', $sanitize->call($service, 'Trusted APK rewards'));
    }

    public function testSanitizeVisibleCopyDropsStageTaskInstructionCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $sanitize = function (string $value): string {
            return $this->sanitizeVisibleCopy($value);
        };
        $sanitizeConfig = function (array $config): array {
            return $this->sanitizeDefaultConfigVisibleCopy($config);
        };

        self::assertSame('', $sanitize->call($service, '优先沿用第一阶段确认的标题、正文和字段样例；例如：18+ only, no cheating；输出必须是访客可见内容。'));
        self::assertSame('', $sanitize->call($service, 'Present key terms in accordion cards and provide download CTA with checkout links.'));
        self::assertSame('', $sanitize->call($service, 'Showcase two most popular Indian card games with responsive cards.'));
        self::assertSame('', $sanitize->call($service, 'Built from plan: Welcome to Teenipiya'));
        self::assertSame('Welcome to Teenipiya – India\'s Royal Card Game Hub', $sanitize->call($service, '印度 / 棋牌 / 下载 / APK - Welcome to Teenipiya – India\'s Royal Card Game Hub'));

        $config = $sanitizeConfig->call($service, [
            'content.title' => 'Present key terms in accordion cards and provide download CTA with checkout links.',
            'content.description' => '优先沿用第一阶段确认的标题、正文和字段样例；例如：18+ only, no cheating；输出必须是访客可见内容。',
            'contract.stage1_samples' => \json_encode([
                'Terms You Should Know',
                '18+ only, no cheating, non-refundable coins',
                'Download APK - Join the Royal Court',
            ], \JSON_UNESCAPED_UNICODE),
        ]);

        self::assertSame('Terms You Should Know', $config['content.title'] ?? null);
        self::assertSame('18+ only, no cheating, non-refundable coins', $config['content.description'] ?? null);
    }

    public function testGeneratedComponentDefaultConfigStripsInternalContractBeforePersistence(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $sanitize = function (array $config): array {
            return $this->sanitizeGeneratedComponentDefaultConfig($config);
        };

        $config = $sanitize->call($service, [
            'content.title' => 'Teenipiya Royal APK',
            'content.description' => 'Download, play, and claim trusted rewards.',
            'contract.stage1_samples' => '["Built from plan: internal"]',
            'contract.theme_tokens' => '#ffcc00 #111111',
            'runtime.stage2_context_snapshot' => ['prompt' => 'internal'],
            'task_script.stage3_directive' => 'Generate the frontend block.',
        ]);

        self::assertSame('Teenipiya Royal APK', $config['content.title'] ?? null);
        self::assertSame('Download, play, and claim trusted rewards.', $config['content.description'] ?? null);
        self::assertArrayNotHasKey('contract.stage1_samples', $config);
        self::assertArrayNotHasKey('contract.theme_tokens', $config);
        self::assertArrayNotHasKey('runtime.stage2_context_snapshot', $config);
        self::assertArrayNotHasKey('task_script.stage3_directive', $config);
        self::assertStringNotContainsString('Built from plan', \json_encode($config, \JSON_UNESCAPED_UNICODE));
    }

    public function testCleanAiHtmlFragmentRejectsMalformedHtmlStructure(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI component HTML structure invalid');

        // 错位闭合标签会先被 repairHtmlFragmentTagBalance 纠正；
        // 这里用「标签内属性引号未闭合」这类无法在栈平衡阶段自动修复的错误，仍会触发断言失败。
        (function (string $html): string {
            return $this->cleanAiHtmlFragment($html);
        })->call(
            $service,
            '<section class="stage"><div aria-label="unclosed-quote-never-closes-before-next-tag><p>Readable body</p></div></section>'
        );
    }

    public function testCleanAiHtmlFragmentRepairsGeneratedImageAttributeGrammar(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $html = '<section class="stage">'
            . '<img src="/pub/media/page-build/site/ai-generated/hero.jpg" data-pb-ai-image-role="generated-asset data-pb-ai-asset-slot="page:home_page:content-home-page-hero" alt="Hero">'
            . '<p>Readable body for generated image grammar.</p></section>';

        $clean = (function (string $html): string {
            return $this->cleanAiHtmlFragment($html);
        })->call($service, $html);

        self::assertStringContainsString('data-pb-ai-image-role="generated-asset"', $clean);
        self::assertStringContainsString('data-pb-ai-asset-slot="page:home_page:content-home-page-hero"', $clean);
    }

    public function testRequiredNonHeroImageSlotIsHydratedIntoHtmlContent(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );
        $hydrate = new \ReflectionMethod($service, 'ensureRequiredNonHeroImageSlotElement');
        $hydrate->setAccessible(true);
        $assert = new \ReflectionMethod($service, 'assertRequiredImageAssetsUsed');
        $assert->setAccessible(true);

        $renderContext = [
            '_required_image_assets' => [
                'page:about_page:content-about-page-testimonials' => '/pub/media/page-build/site/ai-generated/testimonials.jpg',
            ],
        ];
        $defaultConfig = [
            'runtime.section_template' => 'testimonials',
            'runtime.section_image_alt' => 'Players sharing verified payout proof',
        ];
        $result = $hydrate->invoke($service, [
            'html_content' => '<section class="pb-content-about-page-testimonials-root"><p>Real testimonial proof.</p></section>',
        ], 'content', $renderContext, $defaultConfig, 'content/about-page-testimonials');

        self::assertIsArray($result);
        self::assertStringContainsString('src="/pub/media/page-build/site/ai-generated/testimonials.jpg"', (string)$result['html_content']);
        self::assertStringContainsString('alt="Players sharing verified payout proof"', (string)$result['html_content']);
        self::assertStringContainsString('data-pb-ai-image-role="generated-asset"', (string)$result['html_content']);
        self::assertStringContainsString('data-pb-ai-asset-slot="page:about_page:content-about-page-testimonials"', (string)$result['html_content']);
        $assert->invoke($service, $result, $renderContext, $defaultConfig);
    }

    public function testVerifiedAssetAllowlistRejectsDuplicateGeneratedSlotUrl(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );
        $assert = new \ReflectionMethod($service, 'assertGeneratedImageSourcesUseVerifiedAssets');
        $assert->setAccessible(true);

        $slotId = 'page:about_page:content-about-page-about-story';
        $finalUrl = '/pub/media/page-build/site/ai-generated/page-about_page-content-about-page-about-story.jpg';

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Generated image source is outside verified asset allowlist');

        $assert->invoke(
            $service,
            [
                'html_content' => '<section><img src="/pub/media/page-build/site/ai-generated/page-_page-content-about-page-about-story.jpg" data-pb-ai-image-role="generated-asset" data-pb-ai-asset-slot="' . $slotId . '" alt="Story image"></section>',
            ],
            [
                '_required_image_assets' => [
                    $slotId => $finalUrl,
                ],
            ],
            []
        );
    }

    public function testCleanAiHtmlFragmentRepairsAttributeSpacingWithoutChangingCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $clean = (function (string $html): string {
            return $this->cleanAiHtmlFragment($html);
        })->call(
            $service,
            '<section class="stage"><a href="#"class="pb-content-home-page-final-download-cta-link">Download now</a><p>Readable body for CTA spacing.</p></section>'
        );

        self::assertStringContainsString('<a href="#" class="pb-content-home-page-final-download-cta-link">Download now</a>', $clean);
    }

    public function testCleanAiHtmlFragmentRepairsUnclosedAttributeBeforeNextAttribute(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $clean = (function (string $html): string {
            return $this->cleanAiHtmlFragment($html);
        })->call(
            $service,
            '<section><div class="stage data-kind="trust"><p>Readable body for repaired quote boundary.</p></div></section>'
        );

        self::assertStringContainsString('<div class="stage" data-kind="trust">', $clean);
    }

    /**
     * 强行契约：cleanAiHtmlFragment 不得动 <img src=...> 等属性中合法包含
     * "home_page" / "page:home_page:..." 的 URL；只允许从可见文本中剥离这些 planning 关键字。
     * 修复前的实现会把 URL 中的 `home_page` 吞掉，造成 <img> 404。
     */
    public function testCleanAiHtmlFragmentRejectsPlanningKeywordsInVisibleTextWhileAllowingAttributes(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $verified = [
            'page:home_page:content-home-page-hero' =>
                '/pub/media/page-build/example/ai-generated/page-home_page-content-home-page-hero-abc123.jpg',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('internal task identifiers leaked');

        (function (string $html, array $verified): string {
            return $this->cleanAiHtmlFragment($html, $verified);
        })->call(
            $service,
            '<section><h2>Welcome</h2>'
            . '<img class="ai-site-visual-image" '
            . 'src="/pub/media/page-build/example/ai-generated/page-home_page-content-home-page-hero-abc123.jpg" '
            . 'data-pb-ai-asset-slot="page:home_page:content-home-page-hero" alt="Home Hero">'
            . '<p>Just visible body text. plan_locale runtime_context home_page</p>'
            . '</section>',
            $verified
        );
    }

    /**
     * 强行契约：泄漏校验只检"可见文本"——属性中合法的 URL / slot id 必须放行。
     */
    public function testDetectHardGeneratedSectionHtmlPolicyViolationAllowsLegalKeywordsInAttributes(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $html = '<section><h2>Welcome home</h2>'
            . '<img class="ai-site-visual-image" '
            . 'src="/pub/media/page-build/example/ai-generated/page-home_page-content-home-page-hero-abc123.jpg" '
            . 'data-pb-ai-asset-slot="page:home_page:content-home-page-hero" alt="Home Hero">'
            . '<p>Trust the journey, choose the path.</p></section>';

        $reason = (function (string $html): ?string {
            return $this->detectHardGeneratedSectionHtmlPolicyViolation($html);
        })->call($service, $html);

        self::assertNull(
            $reason,
            'Hard policy must allow page-scoped slot ids and asset URLs containing home_page in attributes.'
        );
    }

    /**
     * 强行契约：可见文本仍触发 internal task identifiers leaked。
     */
    public function testDetectHardGeneratedSectionHtmlPolicyViolationStillRejectsLeakInVisibleText(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $html = '<section><h2>Welcome</h2><p>page:home_page:content-home-page-hero leaked here.</p></section>';

        $reason = (function (string $html): ?string {
            return $this->detectHardGeneratedSectionHtmlPolicyViolation($html);
        })->call($service, $html);

        self::assertSame('internal task identifiers leaked', $reason);
    }

    public function testDetectHardGeneratedSectionHtmlPolicyViolationRejectsBlueprintMetaCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $html = '<section><h2>这个页面的核心亮点</h2>'
            . '<p>用更清晰的卡片层级展示卖点、差异点和信任信息，避免所有内容挤成同一种视觉。</p>'
            . '<article><h3>Download & Play</h3><p>把主行动直接放在卡片中，减少犹豫。</p></article>'
            . '</section>';

        $reason = (function (string $html): ?string {
            return $this->detectHardGeneratedSectionHtmlPolicyViolation($html);
        })->call($service, $html);

        self::assertSame('planning observation copy leaked into visitor content', $reason);
    }

    public function testIsAllowedComponentInlineJsAllowsScopedComponentJsButRejectsGlobalOrDangerousJs(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $carouselAiData = [
            'html_content' => '<div class="brand-carousel"><div class="carousel-track"></div></div>',
        ];
        $carouselJs = 'const slides=Array.from(component.querySelectorAll(".carousel-slide"));'
            . 'if(slides.length>1){let idx=0;setInterval(function(){idx=(idx+1)%slides.length;'
            . 'slides.forEach(function(s,i){s.setAttribute("aria-hidden",i===idx?"false":"true");});},5000);}';

        $allowed = (function (string $js, array $aiData): bool {
            return $this->isAllowedComponentInlineJs($js, $aiData);
        })->call($service, $carouselJs, $carouselAiData);
        self::assertTrue($allowed, 'Small JS that only touches local component DOM must be allowed.');

        $dangerousJs = 'fetch("/api/leak").then(r=>r.json());component.innerHTML="x";';
        $rejected2 = (function (string $js, array $aiData): bool {
            return $this->isAllowedComponentInlineJs($js, $aiData);
        })->call($service, $dangerousJs, $carouselAiData);
        self::assertFalse(
            $rejected2,
            'Dangerous JS (fetch/innerHTML) must be rejected.'
        );

        $globalJs = 'document.querySelectorAll(".x").forEach(e=>e.classList.add("y"));';
        $rejected3 = (function (string $js, array $aiData): bool {
            return $this->isAllowedComponentInlineJs($js, $aiData);
        })->call($service, $globalJs, $carouselAiData);
        self::assertFalse(
            $rejected3,
            'JS that does not scope to local component handle must be rejected.'
        );
    }

    public function testDetectLowQualityGeneratedSectionHtmlReasonAllowsLegalKeywordsInAttributes(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $html = '<section class="ai-site-hero">'
            . '<img class="ai-site-visual-image" '
            . 'style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" '
            . 'src="/pub/media/page-build/example/ai-generated/page-home_page-content-home-page-hero-abc123.jpg" '
            . 'data-pb-ai-asset-slot="page:home_page:content-home-page-hero" alt="Home Hero">'
            . '<div class="ai-site-hero-scrim" style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(7,13,25,.82),rgba(7,13,25,.24))"></div>'
            . '<div class="ai-site-hero-text-panel" style="position:relative;background:rgba(7,13,25,.68);color:#fff;backdrop-filter:blur(12px);padding:32px;border-radius:24px;max-width:640px">'
            . '<h2>Welcome to the launch lobby</h2>'
            . '<p>Find the safest path to start, sign up, and play with confidence today.</p></div></section>';

        $reason = (function (string $html): ?string {
            return $this->detectLowQualityGeneratedSectionHtmlReason($html);
        })->call($service, $html);

        self::assertNull(
            $reason,
            'Low-quality detector must allow page-scoped slot ids in attributes.'
        );
    }

    /**
     * 强行契约：URL 路径中的 content/home-page-hero、page:home_page:content-...
     * 在属性中合法存在，但作为可见文本（如 <p>） 必须被剥离。
     */
    public function testCleanAiHtmlFragmentRejectsPathLikeKeywordsInVisibleText(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('internal task identifiers leaked');

        (function (string $html): string {
            return $this->cleanAiHtmlFragment($html);
        })->call(
            $service,
            '<section><a href="/pub/static/content/home-page-hero/icon.svg">View hero icon</a>'
            . '<p>Reference: content/home-page-hero is the section code.</p>'
            . '</section>'
        );
    }

    public function testHeroGeneratedComponentPersistsFullBleedLayoutDefaults(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $defaultConfig = [
            'content.title' => 'Home Hero',
            'content.description' => 'Anchor the home story with a confirmed visual.',
            'visual.image_url' => '/pub/media/page-build/example/ai-generated/page-home_page-content-home-page-hero-abc123.jpg',
            'runtime.section_template' => 'hero',
            'runtime.section_image_url' => '/pub/media/page-build/example/ai-generated/page-home_page-content-home-page-hero-abc123.jpg',
            'runtime.section_image_slot_id' => 'page:home_page:content-home-page-hero',
        ];

        $persisted = (function (array $config): array {
            return $this->sanitizeGeneratedComponentDefaultConfig($config, 'content');
        })->call($service, $defaultConfig);

        self::assertSame('full', (string)($persisted['layout.container_width'] ?? ''));
        self::assertSame('0', (string)($persisted['layout.padding_top'] ?? ''));
        self::assertSame('0', (string)($persisted['layout.padding_bottom'] ?? ''));
        self::assertSame('image', (string)($persisted['style.bg_type'] ?? ''));
        self::assertSame(
            '/pub/media/page-build/example/ai-generated/page-home_page-content-home-page-hero-abc123.jpg',
            (string)($persisted['style.bg_image'] ?? '')
        );
        self::assertArrayNotHasKey('runtime.section_template', $persisted);
    }

    public function testBuildSectionDefaultConfigPrefersVerifiedAssetOverPlaceholderSlot(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $placeholderUrl = '/pub/media/page-build/example/ai-generated/home-hero-placeholder.svg';
        $verifiedUrl = '/pub/media/page-build/example/ai-generated/page-home_page-content-home-page-hero-verified.jpg';

        $scope = [
            'asset_manifest' => [
                'slots' => [
                    'page:home_page:hero' => [
                        'slot_id' => 'page:home_page:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home_page',
                        'final_url' => $placeholderUrl,
                        'status' => 'done',
                        'variants' => [[
                            'url' => $placeholderUrl,
                            'mode' => 'placeholder',
                            'placeholder' => 1,
                        ]],
                    ],
                    'page:home_page:content-home-page-hero' => [
                        'slot_id' => 'page:home_page:content-home-page-hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home_page',
                        'final_url' => $verifiedUrl,
                        'status' => 'done',
                        'variants' => [[
                            'url' => $verifiedUrl,
                            'mode' => 'auto_build',
                        ]],
                    ],
                ],
            ],
        ];

        $config = (function (string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): array {
            return $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero',
                'key' => 'hero',
                'name' => 'Home Hero',
                'template' => 'hero',
                'config' => [],
            ],
            [
                'page_title' => 'Hero asset priority',
                'page_label' => 'Home',
            ],
            [
                'site_title' => 'Hero asset priority',
            ],
            $scope
        );

        self::assertSame($verifiedUrl, (string)($config['visual.image_url'] ?? ''));
        self::assertSame($verifiedUrl, (string)($config['runtime.section_image_url'] ?? ''));
        self::assertSame('page:home_page:content-home-page-hero', (string)($config['runtime.section_image_slot_id'] ?? ''));
    }

    public function testResolveSectionAssetUrlPrefersPageScopedSlotOverLegacyKeywordHit(): void
    {
        // 真实场景复现：stage1 留下了 slot_id=5、page_type='' 的 legacy slot，
        // 其 brief 文本里包含 "Hero visual brief..." 会匹配到关键字 "hero"，
        // 但 stage2 已经为 home_page 落地了精确的 page-scoped slot
        // (slot_id=page:home_page:content-home-page-hero)。
        // resolveSectionAssetUrl 必须优先返回精确匹配的 page-scoped slot，
        // 不得被 legacy slot（即便其元数据偶然命中关键字）抢占。
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $legacyUrl = '/pub/media/page-build/example/ai-generated/5-legacy-stage1.jpg';
        $pageScopedUrl = '/pub/media/page-build/example/ai-generated/page-home_page-content-home-page-hero-stage2.jpg';

        $scope = [
            'asset_manifest' => [
                'slots' => [
                    '5' => [
                        'slot_id' => '5',
                        'slot_type' => 'hero_image',
                        'page_type' => '',
                        'block_key' => '',
                        'task_key' => '',
                        'field' => 'image',
                        'label' => '5',
                        'brief' => 'Hero visual brief: confirm the page promise on the first screen.',
                        'final_url' => $legacyUrl,
                        'variants' => [[
                            'url' => $legacyUrl,
                            'mode' => 'auto_build',
                        ]],
                    ],
                    'page:home_page:content-home-page-hero' => [
                        'slot_id' => 'page:home_page:content-home-page-hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home_page',
                        'block_key' => 'content/home-page-hero',
                        'task_key' => 'page:home_page:content/home-page-hero',
                        'field' => 'image',
                        'label' => 'Home Hero',
                        'brief' => 'Home Hero visual that illustrates the block promise.',
                        'final_url' => $pageScopedUrl,
                        'variants' => [[
                            'url' => $pageScopedUrl,
                            'mode' => 'auto_build',
                        ]],
                    ],
                ],
            ],
        ];

        $resolved = (function (string $pageType, array $section, array $scope): string {
            return $this->resolveSectionAssetUrl($pageType, $section, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero',
                'key' => 'hero',
                'name' => 'Home Hero',
                'template' => 'hero',
            ],
            $scope
        );

        self::assertSame($pageScopedUrl, $resolved, 'page-scoped slot 必须优先于含 hero 关键字的 legacy slot 被返回。');
    }

    public function testResolveSectionAssetUrlFallsBackToLegacySlotOnlyWhenPageScopedAbsent(): void
    {
        // 当 stage2 没有 page-scoped slot，仅有 stage1 legacy slot 时，
        // 仍需回退到 legacy slot，但只有在前面所有更精确的查找都 miss 时才允许。
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $legacyUrl = '/pub/media/page-build/example/ai-generated/legacy-only.jpg';
        $scope = [
            'asset_manifest' => [
                'slots' => [
                    '5' => [
                        'slot_id' => '5',
                        'slot_type' => 'hero_image',
                        'page_type' => '',
                        'brief' => 'Hero visual brief for legacy fallback.',
                        'final_url' => $legacyUrl,
                        'variants' => [[
                            'url' => $legacyUrl,
                            'mode' => 'auto_build',
                        ]],
                    ],
                ],
            ],
        ];

        $resolved = (function (string $pageType, array $section, array $scope): string {
            return $this->resolveSectionAssetUrl($pageType, $section, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero',
                'key' => 'hero',
                'name' => 'Home Hero',
                'template' => 'hero',
            ],
            $scope
        );

        self::assertSame($legacyUrl, $resolved, '没有 page-scoped slot 时应回退到 legacy slot。');
    }

    public function testResolveSectionAssetUrlPrefersCodeBasedSlotIdOverKeyBasedSlotId(): void
    {
        // 真实场景复现：blueprint 偶尔会给 section.key 复用通用 token（如把 about-page-story 的 key 设为 highlights）
        // 因此 expectedSlotIds 同时含有 page:about_page:content-about-page-story（基于 code）
        // 和 page:about_page:highlights（基于 key）。两者均为 REAL 时，必须优先返回 code-based 的精确 slot。
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $codeBasedUrl = '/pub/media/page-build/example/ai-generated/page-about_page-content-about-page-story.jpg';
        $keyBasedUrl = '/pub/media/page-build/example/ai-generated/page-about_page-highlights.jpg';

        // 注意 slot 顺序：key-based 排在前面，模拟真实 manifest 的非 specificity 顺序。
        $scope = [
            'asset_manifest' => [
                'slots' => [
                    'page:about_page:highlights' => [
                        'slot_id' => 'page:about_page:highlights',
                        'slot_type' => 'trust_brand_image',
                        'page_type' => 'about_page',
                        'block_key' => 'highlights',
                        'final_url' => $keyBasedUrl,
                        'variants' => [[
                            'url' => $keyBasedUrl,
                            'mode' => 'auto_build',
                        ]],
                    ],
                    'page:about_page:content-about-page-story' => [
                        'slot_id' => 'page:about_page:content-about-page-story',
                        'slot_type' => 'trust_brand_image',
                        'page_type' => 'about_page',
                        'block_key' => 'content/about-page-story',
                        'final_url' => $codeBasedUrl,
                        'variants' => [[
                            'url' => $codeBasedUrl,
                            'mode' => 'auto_build',
                        ]],
                    ],
                ],
            ],
        ];

        $resolved = (function (string $pageType, array $section, array $scope): string {
            return $this->resolveSectionAssetUrl($pageType, $section, $scope);
        })->call(
            $service,
            'about_page',
            [
                'code' => 'content/about-page-story',
                'key' => 'highlights',
                'name' => '品牌与团队',
                'template' => 'story',
            ],
            $scope
        );

        self::assertSame($codeBasedUrl, $resolved, 'code-based 精确匹配 (page:about_page:content-about-page-story) 必须优先于 key-based slot。');
    }

    public function testResolveSectionAssetUrlPrefersRealLegacyOverPlaceholderPageScoped(): void
    {
        // 强行契约：当 stage2 只生成了占位 page-scoped slot，
        // 但 stage1 已经留下真实 legacy slot 时，必须优先继承真实图片。
        // 用户原话："反而用了占位图，这个要处理。"
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $legacyRealUrl = '/pub/media/page-build/example/ai-generated/details-real-stage1.jpg';
        $pageScopedPlaceholderUrl = '/pub/media/page-build/example/ai-generated/page-home_page-details-placeholder.svg';

        $scope = [
            'asset_manifest' => [
                'slots' => [
                    'page:home_page:details' => [
                        'slot_id' => 'page:home_page:details',
                        'slot_type' => 'trust_brand_image',
                        'page_type' => 'home_page',
                        'block_key' => 'details',
                        'final_url' => $pageScopedPlaceholderUrl,
                        'variants' => [[
                            'url' => $pageScopedPlaceholderUrl,
                            'mode' => 'placeholder',
                            'placeholder' => 1,
                        ]],
                    ],
                    'details' => [
                        'slot_id' => 'details',
                        'slot_type' => 'trust_brand_image',
                        'page_type' => '',
                        'block_key' => 'details',
                        'brief' => 'Turn the page goal into clear on-screen content (details copy seed).',
                        'final_url' => $legacyRealUrl,
                        'variants' => [[
                            'url' => $legacyRealUrl,
                            'mode' => 'auto_build',
                        ]],
                    ],
                ],
            ],
        ];

        $resolved = (function (string $pageType, array $section, array $scope): string {
            return $this->resolveSectionAssetUrl($pageType, $section, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-details',
                'key' => 'details',
                'name' => '转化路径',
                'template' => 'details',
            ],
            $scope
        );

        self::assertSame($legacyRealUrl, $resolved, 'stage1 真实 legacy 图必须优先于 stage2 占位 page-scoped slot。');
    }

    public function testResolveSectionAssetUrlIgnoresPlaceholderSlotWhenRealSlotExists(): void
    {
        // 当同时存在 placeholder 与 real 的 page-scoped slot 时，
        // 必须优先返回非占位真实图片。
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $placeholderUrl = '/pub/media/page-build/example/ai-generated/page-home_page-hero-placeholder.svg';
        $realUrl = '/pub/media/page-build/example/ai-generated/page-home_page-content-home-page-hero.jpg';

        $scope = [
            'asset_manifest' => [
                'slots' => [
                    'page:home_page:hero' => [
                        'slot_id' => 'page:home_page:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home_page',
                        'final_url' => $placeholderUrl,
                        'variants' => [[
                            'url' => $placeholderUrl,
                            'mode' => 'placeholder',
                            'placeholder' => 1,
                        ]],
                    ],
                    'page:home_page:content-home-page-hero' => [
                        'slot_id' => 'page:home_page:content-home-page-hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home_page',
                        'final_url' => $realUrl,
                        'variants' => [[
                            'url' => $realUrl,
                            'mode' => 'auto_build',
                        ]],
                    ],
                ],
            ],
        ];

        $resolved = (function (string $pageType, array $section, array $scope): string {
            return $this->resolveSectionAssetUrl($pageType, $section, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero',
                'key' => 'hero',
                'name' => 'Home Hero',
                'template' => 'hero',
            ],
            $scope
        );

        self::assertSame($realUrl, $resolved, '真实图片必须优先于占位 SVG 被返回。');
    }

    public function testApplyBuildPlanContentPlanDefaultsAdoptsContentCopyAndCtaPlan(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $defaultConfig = [
            'content.title' => '',
            'content.description' => '',
            'cta.text' => '',
            'cta.url' => '',
        ];
        $taskPlanTask = [
            'task_script' => [],
            'block_task' => [
                'content_plan' => [
                    'content_copy' => [
                        ['field' => 'headline', 'copy' => 'Home Hero'],
                        ['field' => 'description', 'copy' => 'A confirmed stage-1 hero summary visitors can trust.'],
                    ],
                    'cta_plan' => [
                        ['label' => 'Start now', 'target' => '#contact'],
                    ],
                ],
            ],
        ];

        $config = (function (array $defaultConfig, array $taskPlanTask, string $locale): array {
            return $this->applyBuildPlanDefaults($defaultConfig, $taskPlanTask, $locale);
        })->call($service, $defaultConfig, $taskPlanTask, 'en_US');

        self::assertSame('Home Hero', $config['content.title'] ?? null);
        self::assertSame('A confirmed stage-1 hero summary visitors can trust.', $config['content.description'] ?? null);
        self::assertSame('Start now', $config['cta.text'] ?? null);
        self::assertSame('#contact', $config['cta.url'] ?? null);
    }

    public function testVirtualThemeComponentPolicyRejectsPlanningObservationCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        $payload = [
            'extra_fields' => '',
            'php_variables' => '',
            'css_extra' => '#componentId .pb-royal-panel { display:grid; gap:18px; padding:28px; border-radius:28px; background:linear-gradient(135deg,#1A1A1A,#E67E22); box-shadow:0 24px 60px rgba(0,0,0,.2); transition:transform .2s ease; }',
            'css_responsive' => '#componentId .pb-royal-panel { grid-template-columns:1fr; }',
            'html_content' => '<section class="pb-royal-panel"><span class="pb-royal-css-token" aria-hidden="true"></span><h2>访客看到三张精致卡片，从而产生下载兴趣。</h2><p>真实玩家对战，赢取真金奖励。</p></section>',
            'js_content' => '',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('planning observation copy leaked');

        (function (array $payload): array {
            return $this->ensureAiPayloadValid($payload, 'content', 'content/home-page-game-features');
        })->call($service, $payload);
    }
}
