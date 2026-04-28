<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
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
        RequestContext::remove(AiSitePageComponentGenerationService::REQUEST_KEY_ALLOW_STUB_AI_IN_TEST);
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

    public function testRunAiGenerationFallsBackToNonStreamForTransientTlsStreamFailure(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willThrowException(new \RuntimeException('AI流式生成失败: 流式API调用失败: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading'));
        $aiService->expects(self::once())
            ->method('generate')
            ->with(
                self::stringContains('Generate a simple header'),
                null,
                'pagebuilder_component_generation',
                null,
                self::callback(static function (array $params): bool {
                    return ($params['response_format']['type'] ?? null) === 'json_object'
                        && ($params['allow_zero_balance_provider'] ?? null) === true;
                })
            )
            ->willReturn('{"html_extra":"<div>Recovered header</div>","css_extra":"","php_variables":"","extra_fields":"","js_content":""}');

        $service = new AiSitePageComponentGenerationService(
            aiService: $aiService,
        );

        $payload = (function (string $region, string $prompt): array {
            return $this->runAiGeneration($region, $prompt);
        })->call($service, 'header', 'Generate a simple header');

        self::assertSame('<div>Recovered header</div>', $payload['html_extra'] ?? null);
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
        self::assertTrue((bool)($streamParams['disable_ai_timeout'] ?? false));
        self::assertTrue((bool)($streamParams['disable_cli_timeout'] ?? false));
        self::assertFalse((bool)($streamParams['enforce_timeout_in_stream'] ?? true));

        self::assertSame(180, $fallbackParams['timeout'] ?? null);
        self::assertArrayNotHasKey('disable_ai_timeout', $fallbackParams);
        self::assertArrayNotHasKey('disable_cli_timeout', $fallbackParams);
        self::assertArrayNotHasKey('enforce_timeout_in_stream', $fallbackParams);
    }

    public function testGenerateComponentThrowsAfterAiRetriesInsteadOfReturningStubFallback(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::exactly(2))
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

    public function testProductionFallbackComponentGenerationIsForbidden(): void
    {
        $service = new AiSitePageComponentGenerationService(
            frameworkBuilder: new FrameworkBuilder(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Local component fallback is forbidden');

        (function (): array {
            return $this->buildFallbackComponent(
                'content/home-page-featured-plugins-grid',
                'Featured plugin grid',
                'content',
                'Generate a featured plugin grid',
                [
                    'content.title' => 'AI Plugin Hub',
                    'content.description' => 'Browse trusted AI tools with clear fit, proof points, and fast download paths.',
                ],
                ['_content_locale' => 'en_US']
            );
        })->call($service);
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
        self::assertStringContainsString('Fix contrast explicitly', $prompt);
        self::assertStringContainsString('Fix page hierarchy explicitly', $prompt);
        self::assertStringContainsString('Fix HTML structure explicitly', $prompt);
        self::assertStringContainsString('pb-content-home-page-featured-plugins-grid', $prompt);
        self::assertStringNotContainsString('simplif', \strtolower($prompt));
        self::assertStringNotContainsString('compact', \strtolower($prompt));
        self::assertStringNotContainsString('reduced', \strtolower($prompt));
        self::assertStringNotContainsString('Keep the structure compact', $prompt);
        self::assertStringNotContainsString('Prefer one small section', $prompt);
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
            . $safetyRules->invoke($service);

        self::assertStringContainsString('Contrast is non-negotiable', $prompt);
        self::assertStringContainsString('Theme color is not a paint bucket', $prompt);
        self::assertStringContainsString('Color quality requirement', $prompt);
        self::assertStringContainsString('HTML fragments must be balanced', $prompt);
    }

    public function testResolveConcurrencyUsesTaskCountWithoutApplicationCap(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'resolveConcurrency');
        $method->setAccessible(true);

        self::assertSame(1, $method->invoke($service, 0));
        self::assertSame(1, $method->invoke($service, 1));
        self::assertSame(8, $method->invoke($service, 8));
    }

    public function testGenerateComponentEventsConcurrentlyReportsFulfilledAndRejectedTasks(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::exactly(3))
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

    public function testDecodeComponentPayloadWithRepairRetriesJsonRepairUpToThreeAttempts(): void
    {
        $parser = $this->createMock(AiResponseJsonParser::class);
        $parser->expects(self::exactly(2))
            ->method('extractAndDecode')
            ->willReturnOnConsecutiveCalls(
                null,
                ['html_extra' => '<div>ok</div>', 'css_extra' => '', 'php_variables' => '', 'extra_fields' => '', 'js_content' => '']
            );

        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generate')
            ->with(
                self::stringContains('repairing a malformed PageBuilder header component JSON'),
                null,
                'pagebuilder_component_generation'
            )
            ->willReturn('{"html_extra":"<div>ok</div>","css_extra":"","php_variables":"","extra_fields":"","js_content":""}');

        $service = new AiSitePageComponentGenerationService(
            responseJsonParser: $parser,
            aiService: $aiService,
        );

        $decoded = (function (string $content, string $region): ?array {
            return $this->decodeComponentPayloadWithRepair($content, $region);
        })->call($service, 'not-json', 'header');

        self::assertIsArray($decoded);
        self::assertSame('<div>ok</div>', $decoded['html_extra'] ?? null);
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

    public function testBuildSectionGenerationPromptIncludesStageTwoTaskContext(): void
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
                'task_plan_confirmed' => 1,
                'virtual_theme_plan' => [
                    'confirmed' => [
                        'page_tasks' => [
                            'home_page' => [
                                [
                                    'task_key' => 'page:home_page:content/home-page-hero',
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
                    ],
                ],
            ]
        );

        self::assertStringContainsString('Stage-2 task context for this section:', $prompt);
        self::assertStringContainsString('page_design_plan', $prompt);
        self::assertStringContainsString('hero panel over dark base', $prompt);
        self::assertStringContainsString('page_flow_role: opening', $prompt);
        self::assertStringContainsString('Follow the confirmed hero task contract.', $prompt);
        self::assertStringContainsString('Grow faster with our service', $prompt);
        self::assertStringContainsString('Hero must render value proposition and CTA.', $prompt);
        self::assertStringContainsString('stage1.theme_context', $prompt);
        self::assertStringContainsString('Festival Neon', $prompt);
        self::assertStringContainsString('stage1.shared_prompt_context', $prompt);
        self::assertStringContainsString('Fast game discovery with trusted checkout.', $prompt);
        self::assertStringContainsString('stage2.task_script', $prompt);
        self::assertStringContainsString('stage2.block_task', $prompt);
        self::assertStringContainsString('block_task.content_plan', $prompt);
        self::assertStringContainsString('Use a launch-ready hero promise from the block plan.', $prompt);
        self::assertStringContainsString('block_task.style_plan', $prompt);
        self::assertStringContainsString('Use a layered neon card visual with CSS depth.', $prompt);
        self::assertStringContainsString('apply page_design_plan.color_layering and section_flow before local block styling', $prompt);
        self::assertStringContainsString('Weline/PageBuilder skill contract / frontend skill contract', $prompt);
        self::assertStringContainsString('page-design-plan', $prompt);
        self::assertStringContainsString('frontend-components', $prompt);
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
        self::assertStringContainsString('inline SVG or CSS shapes', $prompt);
        self::assertStringContainsString('Images: never output broken image placeholders', $prompt);
        self::assertStringContainsString('Visual excellence system prompt for section', $prompt);
        self::assertStringContainsString('Section quality floor', $prompt);
        self::assertStringContainsString('pale background + ordinary cards + small default buttons', $prompt);
        self::assertStringContainsString('Customer-intent lock', $prompt);
        self::assertStringContainsString('Interaction/effects requirement', $prompt);
        self::assertStringContainsString('Do not leave css_extra empty', $prompt);
        self::assertStringContainsString('stage2 language rule', $prompt);
        self::assertStringContainsString('rewrite any planned text that is not in the website content language', $prompt);
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
                'task_plan_confirmed' => 1,
                'virtual_theme_plan' => [
                    'confirmed' => [
                        'page_tasks' => [
                            'home_page' => [
                                [
                                    'task_key' => 'page:home_page:content/home-page-hero',
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
                    ],
                ],
            ]
        );

        self::assertSame('Grow faster with our service', (string)($config['content.title'] ?? ''));
        self::assertSame('Launch faster with a focused hero message.', (string)($config['content.description'] ?? ''));
    }

    public function testConfirmedTaskPlanRootFallsBackToBuildBlueprintWhenSnapshotIsCompacted(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $root = (function (array $scope): array {
            return $this->resolveTaskPlanRoot($scope);
        })->call($service, [
            'task_plan_confirmed' => 1,
            'virtual_theme_plan' => [
                'confirmed' => [
                    'signature' => 'task-plan-signature',
                    '_storage_compacted' => 1,
                    'execution_blueprint_ref' => ['task_count' => 1],
                ],
            ],
            'build_blueprint' => [
                'source' => 'stage2_confirmed_task_plan',
                'signature' => 'build-blueprint-signature',
                'task_plan_signature' => 'task-plan-signature',
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

    public function testTaskPlanPromptUsesScopeLevelRuntimeContextWhenTaskSnapshotsAreCompacted(): void
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
            return $this->buildTaskPlanPromptAddon($taskPlanTask, $contextLabel, $scope);
        })->call($service, $task, 'section', $scope);

        self::assertStringContainsString('stage2-context-hash', $prompt);
        self::assertStringContainsString('Measured premium trust', $prompt);
        self::assertStringContainsString('Compact trust navigation', $prompt);
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
            'task_plan_confirmed' => 1,
            'virtual_theme_plan' => [
                'confirmed' => [
                    'shared_tasks' => [
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
        self::assertStringContainsString('primary_cta_label', $prompt);
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
            'html_content' => '<div class="pb-rich-0 pb-rich-sentinel"><svg viewBox="0 0 20 20"><circle cx="10" cy="10" r="8"/></svg><h2>AI Plugin Hub</h2><p>Browse trusted AI tools with clear fit, proof points, and fast download paths.</p></div>',
            'js_content' => '',
        ];

        $validated = (function (array $payload, string $region, string $componentCode): array {
            return $this->ensureAiPayloadValid($payload, $region, $componentCode);
        })->call($service, $payload, 'content', 'content/home-page-featured-plugins-grid');

        self::assertGreaterThan(1800, \strlen((string)($validated['css_extra'] ?? '')));
        self::assertStringContainsString('pb-rich-sentinel', (string)($validated['css_extra'] ?? ''));
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

    public function testVirtualThemeComponentPolicyDropsPageTypeEyebrowLabels(): void
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

        self::assertStringNotContainsString('hero-eyebrow', (string)($validated['html_content'] ?? ''));
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
                'task_plan_confirmed' => 1,
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
                'task_plan_confirmed' => 1,
                'virtual_theme_plan' => [
                    'confirmed' => [
                        'page_tasks' => [
                            'home_page' => [
                                [
                                    'task_key' => 'page:home_page:content/home-page-hero',
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
                    ],
                ],
            ]
        );

        self::assertSame('See campaign clarity in one dashboard', (string)($config['content.title'] ?? ''));
        self::assertSame('Turn scattered campaign signals into one clear growth view.', (string)($config['content.description'] ?? ''));
        self::assertStringNotContainsString('中文', (string)($config['content.description'] ?? ''));
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

    public function testCleanAiHtmlFragmentRepairsUnclosedHtmlTags(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $cleaned = (function (string $html): string {
            return $this->cleanAiHtmlFragment($html);
        })->call(
            $service,
            '<section class="stage"><div class="copy"><h2>Title</h2><p>Readable body</section></span>'
        );

        self::assertSame(
            '<section class="stage"><div class="copy"><h2>Title</h2><p>Readable body</p></div></section>',
            $cleaned
        );
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
            'html_content' => '<section class="pb-royal-panel"><svg viewBox="0 0 10 10"></svg><h2>访客看到三张精致卡片，从而产生下载兴趣。</h2><p>真实玩家对战，赢取真金奖励。</p></section>',
            'js_content' => '',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('planning observation copy leaked');

        (function (array $payload): array {
            return $this->ensureAiPayloadValid($payload, 'content', 'content/home-page-game-features');
        })->call($service, $payload);
    }
}
