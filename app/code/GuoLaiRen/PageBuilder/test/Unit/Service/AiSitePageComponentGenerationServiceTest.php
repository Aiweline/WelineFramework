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

    public function testGenerateComponentThrowsAfterAiRetriesInsteadOfReturningStubFallback(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::exactly(3))
            ->method('generateStream')
            ->willThrowException(new \RuntimeException('model unavailable'));

        $service = new AiSitePageComponentGenerationService(
            frameworkBuilder: new FrameworkBuilder(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
            aiService: $aiService,
        );

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('AI');

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

    public function testGenerateComponentDoesNotRetryNonRetryableProviderErrors(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willThrowException(new \RuntimeException('AI API error (HTTP 402, unknown_error): Insufficient Balance'));

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

        self::assertStringContainsString('$footerNote = \'Need help?\'', (string)($validatedPayload['html_extra_column'] ?? ''));

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
                'default_locale' => 'en_US',
            ],
            [
                'default_locale' => 'en_US',
                'task_plan_structured' => [
                    'page_tasks' => [
                        'home_page' => [
                            [
                                'task_key' => 'page:home_page:content/home-page-hero',
                                'section_code' => 'content/home-page-hero',
                                'plan_context' => [
                                    'page_goal' => 'Explain value',
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
                                'runtime_context' => [
                                    'task_session_id' => 'abc123',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        self::assertStringContainsString('Stage-2 task context for this section:', $prompt);
        self::assertStringContainsString('Follow the confirmed hero task contract.', $prompt);
        self::assertStringContainsString('Grow faster with our service', $prompt);
        self::assertStringContainsString('Hero must render value proposition and CTA.', $prompt);
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
                'task_plan_structured' => [
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
            ]
        );

        self::assertSame('Grow faster with our service', (string)($config['content.title'] ?? ''));
        self::assertSame('Launch faster with a focused hero message.', (string)($config['content.description'] ?? ''));
    }
}
