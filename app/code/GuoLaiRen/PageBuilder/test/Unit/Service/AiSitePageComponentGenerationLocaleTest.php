<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;

final class AiSitePageComponentGenerationLocaleTest extends TestCase
{
    public function testPortugueseLocaleFilteringDropsChinesePlanningCopyAndEnglishBoilerplate(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $filter = function (string $value, string $locale): string {
            return $this->filterVisibleCopyForLocale($value, $locale);
        };

        self::assertSame('', $filter->call(
            $service,
            "Teenipiya \u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}\u{3001}\u{7279}\u{8272}\u{5185}\u{5BB9}\u{3001}\u{4FE1}\u{4EFB}\u{4FE1}\u{606F}\u{548C}\u{4E3B}\u{8981}\u{884C}\u{52A8}\u{5165}\u{53E3}\u{3002}",
            'pt_BR'
        ));
        self::assertSame('', $filter->call($service, 'Download Now', 'pt_BR'));
        self::assertSame('', $filter->call($service, "\u{4E0B}\u{8F7D}Teenipiya APK", 'pt_BR'));
        self::assertSame('Baixe o APK agora', $filter->call($service, 'Baixe o APK agora', 'pt_BR'));
    }

    public function testPortugueseFooterDefaultsUseLocalizedLabels(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $footer = (function (array $websiteProfile, array $scope, string $siteDisplayName): array {
            return $this->buildFooterDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        })->call(
            $service,
            ['site_title' => 'Teenipiya', 'default_locale' => 'pt_BR'],
            [
                'default_locale' => 'pt_BR',
                'content_locale' => 'pt_BR',
                'page_types' => ['home_page', 'about_page', 'contact_page', 'privacy_policy', 'terms_of_service'],
            ],
            'Teenipiya'
        );

        self::assertSame("P\u{00E1}ginas principais", $footer['links.column1_title'] ?? null);
        self::assertSame("Informa\u{00E7}\u{00F5}es legais", $footer['links.column2_title'] ?? null);
        self::assertSame("Todas as p\u{00E1}ginas", $footer['links.column3_title'] ?? null);
        self::assertStringContainsString("In\u{00ED}cio=>/", (string)($footer['links.column3_items'] ?? ''));
        self::assertStringContainsString("Pol\u{00ED}tica de Privacidade=>/privacy", (string)($footer['links.column3_items'] ?? ''));
        self::assertStringNotContainsString('Privacy Policy', (string)($footer['links.column3_items'] ?? ''));
    }

    public function testPortugueseBuildPlanCopyReplacesPlanningLanguageDefaults(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $planningCopy = "Teenipiya \u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}\u{3001}\u{7279}\u{8272}\u{5185}\u{5BB9}\u{3001}\u{4FE1}\u{4EFB}\u{4FE1}\u{606F}\u{548C}\u{4E3B}\u{8981}\u{884C}\u{52A8}\u{5165}\u{53E3}\u{3002}";
        $expectedBody = 'Baixe o APK em passos simples e entre na mesa de Teen Patti agora.';

        $config = (function (string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): array {
            return $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-hero-download',
                'key' => 'hero_download',
                'name' => 'Baixe em 3 Passos',
                'config' => ['description' => $planningCopy],
            ],
            ['ai_description' => $planningCopy],
            ['site_title' => 'Teenipiya', 'content_locale' => 'pt_BR'],
            [
                'content_locale' => 'pt_BR',
                'default_locale' => 'pt_BR',
                'page_types' => ['home_page'],
                'build_blueprint' => [
                    'source' => 'build_plan_v2',
                    'tasks' => [[
                        'task_type' => 'page_section',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-hero-download',
                        'block_task' => [
                            'content_plan' => [
                                'content_copy' => [
                                    ['field' => 'headline', 'copy' => 'Baixe em 3 Passos'],
                                    ['field' => 'body', 'copy' => $expectedBody],
                                ],
                                'cta_plan' => [
                                    ['label' => 'Baixar APK'],
                                ],
                            ],
                        ],
                    ]],
                ],
            ]
        );

        self::assertSame('Baixe em 3 Passos', $config['content.title'] ?? null);
        self::assertSame($expectedBody, $config['content.description'] ?? null);
        self::assertSame($expectedBody, $config['body'] ?? null);
        self::assertNotSame('Download Now', $config['cta.text'] ?? null);
        self::assertStringNotContainsString("\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}", (string)\json_encode($config, \JSON_UNESCAPED_UNICODE));
    }

    public function testScopeLocaleDrivesSectionDefaultsWhenContentLocaleIsMissing(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $planningCopy = "Teenipiya \u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}\u{3001}\u{7279}\u{8272}\u{5185}\u{5BB9}\u{3001}\u{4FE1}\u{4EFB}\u{4FE1}\u{606F}\u{548C}\u{4E3B}\u{8981}\u{884C}\u{52A8}\u{5165}\u{53E3}\u{3002}";

        $config = (function (string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): array {
            return $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
        })->call(
            $service,
            'home_page',
            [
                'code' => 'content/home-page-trust',
                'key' => 'trust',
                'name' => 'trust',
                'config' => ['description' => $planningCopy, 'section_intro' => $planningCopy],
            ],
            ['ai_description' => $planningCopy],
            ['site_title' => 'Teenipiya'],
            [
                'locale' => 'pt_BR',
                'build_blueprint' => [
                    'tasks' => [[
                        'task_type' => 'page_section',
                        'page_type' => 'home_page',
                        'section_code' => 'content/home-page-trust',
                        'plan_context' => ['block_goal' => $planningCopy],
                        'task_script' => ['story_goal' => $planningCopy],
                        'block_task' => ['task_goal' => $planningCopy],
                    ]],
                ],
            ]
        );

        self::assertSame('pt_BR', $config['runtime.content_locale'] ?? null);
        self::assertSame('', $config['content.description'] ?? null);
        self::assertSame('', $config['body'] ?? null);
        self::assertStringNotContainsString("\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}", (string)\json_encode($config, \JSON_UNESCAPED_UNICODE));
    }

    public function testPortugueseRenderedHtmlLocaleGateAllowsPortugueseCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $assertMatchesLocale = function (string $html, array $renderContext): void {
            $this->assertRenderedHtmlMatchesLocale($html, $renderContext);
        };

        self::assertNull($assertMatchesLocale->call(
            $service,
            '<section><h2>Regras Antes de Jogar</h2><p>Baixe o APK com seguranca e conheca as regras antes da mesa.</p></section>',
            ['content_locale' => 'pt_BR']
        ));
    }

    public function testPortugueseRenderedHtmlLocaleGateRejectsChineseBlockGoalCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $assertMatchesLocale = function (string $html, array $renderContext): void {
            $this->assertRenderedHtmlMatchesLocale($html, $renderContext);
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Generated component locale gate failed');

        $assertMatchesLocale->call(
            $service,
            '<section><h2>Regras Antes de Jogar</h2><p>Teenipiya '
                . "\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}\u{3001}\u{7279}\u{8272}\u{5185}\u{5BB9}"
                . "\u{3001}\u{4FE1}\u{4EFB}\u{4FE1}\u{606F}\u{548C}\u{4E3B}\u{8981}\u{884C}\u{52A8}\u{5165}\u{53E3}"
                . '</p></section>',
            ['content_locale' => 'pt_BR']
        );
    }

    public function testPortugueseHardHtmlPolicyRejectsVisibleChinesePlanningCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $detectHardPolicyViolation = function (string $html, string $locale): ?string {
            return $this->detectHardGeneratedSectionHtmlPolicyViolation($html, $locale);
        };

        $reason = $detectHardPolicyViolation->call(
            $service,
            '<section><p>Teenipiya '
                . "\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}\u{3001}\u{7279}\u{8272}\u{5185}\u{5BB9}"
                . "\u{3001}\u{4FE1}\u{4EFB}\u{4FE1}\u{606F}</p></section>",
            'pt_BR'
        );

        self::assertIsString($reason);
        self::assertStringContainsString('non-target CJK copy leaked', $reason);
        self::assertNull($detectHardPolicyViolation->call(
            $service,
            '<section><p>Baixe o APK com seguranca e consulte as regras principais.</p></section>',
            'pt_BR'
        ));
        self::assertNull($detectHardPolicyViolation->call(
            $service,
            '<section><p>Teenipiya ' . "\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}" . '</p></section>',
            'zh_Hans_CN'
        ));
    }

    public function testPortugueseRenderedHtmlLocaleGateRejectsShortChineseCtaLabel(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $assertMatchesLocale = function (string $html, array $renderContext): void {
            $this->assertRenderedHtmlMatchesLocale($html, $renderContext);
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Generated component locale gate failed');

        $assertMatchesLocale->call(
            $service,
            '<section><h2>Teen Patti de Confianca</h2><button type="button">' . "\u{4E0B}\u{8F7D}Teenipiya APK" . '</button></section>',
            ['content_locale' => 'pt_BR']
        );
    }

    public function testVirtualThemeAdaptationFrameworkPromptRequiresStableShellAndActionEvents(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $buildFrameworkPrompt = function (string $prefix): string {
            return $this->buildVirtualThemeAdaptationFrameworkPromptAddon($prefix, 'hero', true);
        };

        $prompt = $buildFrameworkPrompt->call($service, 'pb-c');

        self::assertStringContainsString('CTX_VIRTUAL_THEME_ADAPTATION_FRAMEWORK', $prompt);
        self::assertStringContainsString('pb-c-root', $prompt);
        self::assertStringContainsString('pb-c-inner', $prompt);
        self::assertStringContainsString('@media (max-width: 768px)', $prompt);
        self::assertStringContainsString('@media (max-width: 420px)', $prompt);
        self::assertStringContainsString('data-pb-ai-action', $prompt);
        self::assertStringContainsString('pb:cta', $prompt);
        self::assertStringContainsString('not visible text', $prompt);
    }

    public function testCtaActionContractRejectsStaticCtaDiv(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $detect = function (string $html, string $componentCode): ?string {
            return $this->detectCtaActionContractViolation($html, $componentCode);
        };

        $reason = $detect->call(
            $service,
            "<section class='pb-c-root'><div class='pb-c-inner'><div class='pb-c-action'><div class='pb-c-cta'>Baixar APK</div></div></div></section>",
            'content/home-page-final-cta'
        );

        self::assertIsString($reason);
        self::assertStringContainsString('actionable', $reason);
    }

    public function testCtaActionContractAllowsFrameworkScopedEventButton(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $detect = function (string $html, string $componentCode): ?string {
            return $this->detectCtaActionContractViolation($html, $componentCode);
        };

        self::assertNull($detect->call(
            $service,
            "<section class='pb-c-root'><div class='pb-c-inner'><div class='pb-c-action'><button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars(\$ctaText ?? 'Baixar APK', ENT_QUOTES, 'UTF-8') ?></button></div></div></section>",
            'content/home-page-final-cta'
        ));
    }

    public function testCtaActionContractRejectsActionButtonWithoutEventBridge(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $detect = function (string $html, string $componentCode): ?string {
            return $this->detectCtaActionContractViolation($html, $componentCode);
        };

        $reason = $detect->call(
            $service,
            "<section class='pb-c-root'><div class='pb-c-inner'><button type='button' class='hero-action'>Baixar Agora</button></div></section>",
            'content/home-page-hero-download'
        );

        self::assertIsString($reason);
        self::assertStringContainsString('data-pb-ai-action', $reason);
    }

    public function testLowQualityGateReachesCtaRoleChecksAfterLeakChecks(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $detect = function (string $html, string $componentCode, string $css): ?string {
            return $this->detectLowQualityGeneratedSectionHtmlReason($html, $componentCode, $css);
        };

        $reason = $detect->call(
            $service,
            "<section class='pb-c-root'><div class='pb-c-inner'><h2 class='pb-c-title'>Baixe Teenipiya</h2><p class='pb-c-text'>Baixe o APK com seguranca e veja as regras principais antes de jogar.</p><div class='pb-c-action'><div class='pb-c-cta'>Baixar APK</div></div></div></section>",
            'content/home-page-final-cta',
            '#componentId .pb-c-root{font-family:Georgia,serif;}#componentId .pb-c-title{font-family:Georgia,serif;}#componentId .pb-c-text{font-family:Georgia,serif;}'
        );

        self::assertIsString($reason);
        self::assertStringContainsString('CTA block role fidelity failed', $reason);
    }

    public function testContentFrameworkKeepsResponsiveCssTopLevelAndInstallsActionBridge(): void
    {
        $builder = new FrameworkBuilder();

        $phtml = $builder->buildComponent('content', ['name' => 'CTA test'], [
            'extra_fields' => 'cta.text => CTA text:text:Baixar APK',
            'php_variables' => "\$ctaText = \$getConfig('cta.text', 'Baixar APK');",
            'css_extra' => '#componentId .pb-c-root{padding:40px;}',
            'css_responsive' => '@media (max-width: 768px){#componentId .pb-c-inner{display:block;}}@media (max-width: 420px){#componentId .pb-c-root{padding:20px;}}',
            'html_content' => "<section class='pb-c-root'><div class='pb-c-inner'><button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars(\$ctaText ?? 'Baixar APK', ENT_QUOTES, 'UTF-8') ?></button></div></section>",
            'js_content' => '',
        ]);

        self::assertStringContainsString("CustomEvent('pb:cta'", $phtml);
        self::assertStringContainsString('data-pb-ai-bound', $phtml);
        self::assertStringNotContainsString("    @media (max-width: 768px){#<?= \$componentId ?> .pb-c-inner", $phtml);
        self::assertStringContainsString("\n@media (max-width: 768px){#<?= \$componentId ?> .pb-c-inner", $phtml);
    }
}
