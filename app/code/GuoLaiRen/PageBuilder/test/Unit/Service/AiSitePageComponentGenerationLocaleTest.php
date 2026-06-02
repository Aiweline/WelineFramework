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
            $this->scopeWithBuildPlanV2([
                'default_locale' => 'pt_BR',
                'content_locale' => 'pt_BR',
                'page_types' => ['home_page', 'about_page', 'contact_page', 'privacy_policy', 'terms_of_service'],
            ], 'pt_BR', 'Teenipiya'),
            'Teenipiya'
        );

        self::assertSame("P\u{00E1}ginas principais", $footer['links.column1_title'] ?? null);
        self::assertSame("Informa\u{00E7}\u{00F5}es legais", $footer['links.column2_title'] ?? null);
        self::assertSame("Todas as p\u{00E1}ginas", $footer['links.column3_title'] ?? null);
        self::assertStringContainsString("In\u{00ED}cio=>/", (string)($footer['links.column3_items'] ?? ''));
        self::assertStringContainsString("Pol\u{00ED}tica de Privacidade=>/privacy", (string)($footer['links.column3_items'] ?? ''));
        self::assertStringNotContainsString('Privacy Policy', (string)($footer['links.column3_items'] ?? ''));
    }

    public function testSelectedLocaleOverridesStaleChinesePlanLocaleForComponentDefaults(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $planningCopy = "Teenipiya \u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}\u{3001}\u{7279}\u{8272}\u{5185}\u{5BB9}\u{3002}";

        $config = (function (string $pageType, array $section, array $blueprint, array $websiteProfile, array $scope): array {
            return $this->buildSectionDefaultConfig($pageType, $section, $blueprint, $websiteProfile, $scope);
        })->call(
            $service,
            'contact_page',
            [
                'code' => 'content/contact-page-support-form-guidance',
                'key' => 'support_form_guidance',
                'name' => 'Envie sua Mensagem',
                'config' => ['description' => $planningCopy],
            ],
            ['ai_description' => $planningCopy],
            [
                'site_title' => 'Teenipiya',
                'content_locale' => 'zh_Hans_CN',
                'default_locale' => 'pt_BR',
            ],
            $this->scopeWithBuildPlanV2([
                'content_locale' => 'zh_Hans_CN',
                'plan_generated_locale' => 'zh_Hans_CN',
                'default_locale' => 'pt_BR',
                'page_types' => ['contact_page'],
            ], 'pt_BR', 'Teenipiya')
        );

        self::assertSame('pt_BR', $config['runtime.content_locale'] ?? null);
        self::assertSame('', $config['content.description'] ?? null);
        self::assertStringNotContainsString("\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}", (string)\json_encode($config, \JSON_UNESCAPED_UNICODE));
    }

    public function testConfirmedPlanContentLocaleOverridesStaleWebsiteDefaultForSharedDefaults(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $config = (function (array $websiteProfile, array $scope, string $siteDisplayName): array {
            return $this->buildFooterDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        })->call(
            $service,
            [
                'site_title' => "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}",
                'content_locale' => 'en_US',
                'default_locale' => 'en_US',
            ],
            $this->scopeWithBuildPlanV2([
                'content_locale' => 'en_US',
                'default_locale' => 'en_US',
                'plan_json' => [
                    'content_locale' => 'zh_Hans_CN',
                    'stage1_contract' => ['content_locale' => 'zh_Hans_CN'],
                ],
                'page_types' => ['home_page', 'contact_page', 'privacy_policy'],
            ], 'zh_Hans_CN', "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}"),
            "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}"
        );

        self::assertSame('zh_Hans_CN', $config['runtime.content_locale'] ?? null);
        self::assertStringNotContainsString('Featured Pages', (string)($config['links.column1_title'] ?? ''));
        self::assertStringNotContainsString('All rights reserved.', (string)($config['copyright.text'] ?? ''));
    }

    public function testPlanGeneratedLocaleOverridesStaleTopLevelLocaleForSharedDefaults(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $config = (function (array $websiteProfile, array $scope, string $siteDisplayName): array {
            return $this->buildFooterDefaultConfig($websiteProfile, $scope, $siteDisplayName);
        })->call(
            $service,
            [
                'site_title' => "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}",
                'content_locale' => 'en_US',
                'default_locale' => 'en_US',
            ],
            $this->scopeWithBuildPlanV2([
                'content_locale' => 'en_US',
                'plan_generated_locale' => 'zh_Hans_CN',
                'plan_json' => ['content_locale' => 'en_US'],
                'page_types' => ['home_page', 'contact_page', 'privacy_policy'],
            ], 'zh_Hans_CN', "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}"),
            "\u{9713}\u{8679}\u{68CB}\u{724C}\u{9986}"
        );

        self::assertSame('zh_Hans_CN', $config['runtime.content_locale'] ?? null);
        self::assertSame("\u{91CD}\u{70B9}\u{9875}\u{9762}", $config['links.column1_title'] ?? null);
        self::assertStringNotContainsString('Featured Pages', (string)($config['links.column1_title'] ?? ''));
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

    public function testPortugueseRenderedHtmlLocaleGateDoesNotBlockChineseBlockGoalCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $assertMatchesLocale = function (string $html, array $renderContext): void {
            $this->assertRenderedHtmlMatchesLocale($html, $renderContext);
        };

        self::assertNull($assertMatchesLocale->call(
            $service,
            '<section><h2>Regras Antes de Jogar</h2><p>Teenipiya '
                . "\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}\u{3001}\u{7279}\u{8272}\u{5185}\u{5BB9}"
                . "\u{3001}\u{4FE1}\u{4EFB}\u{4FE1}\u{606F}\u{548C}\u{4E3B}\u{8981}\u{884C}\u{52A8}\u{5165}\u{53E3}"
                . '</p></section>',
            ['content_locale' => 'pt_BR']
        ));
    }

    public function testPortugueseHardHtmlPolicyDoesNotBlockVisibleChinesePlanningCopy(): void
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

        self::assertNull($reason);
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

    public function testPortugueseRenderedHtmlLocaleGateDoesNotBlockShortChineseCtaLabel(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $assertMatchesLocale = function (string $html, array $renderContext): void {
            $this->assertRenderedHtmlMatchesLocale($html, $renderContext);
        };

        self::assertNull($assertMatchesLocale->call(
            $service,
            '<section><h2>Teen Patti de Confianca</h2><button type="button">' . "\u{4E0B}\u{8F7D}Teenipiya APK" . '</button></section>',
            ['content_locale' => 'pt_BR']
        ));
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

    public function testStructuralGateRejectsStaticCtaRole(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $detect = function (string $html, string $componentCode, string $css): ?string {
            return $this->detectStructuralGeneratedSectionHtmlReason($html, $componentCode, $css);
        };

        $reason = $detect->call(
            $service,
            "<section class='pb-c-root'><div class='pb-c-inner'><h2 class='pb-c-title'>Baixe Teenipiya</h2><p class='pb-c-text'>Baixe o APK com seguranca e veja as regras principais antes de jogar.</p><div class='pb-c-action'><div class='pb-c-cta'>Baixar APK</div></div></div></section>",
            'content/home-page-final-cta',
            '#componentId .pb-c-root{font-family:Georgia,serif;}#componentId .pb-c-title{font-family:Georgia,serif;}#componentId .pb-c-text{font-family:Georgia,serif;}'
        );

        self::assertIsString($reason);
        self::assertStringContainsString('primary CTA class must be on an actionable', $reason);
    }

    public function testStructuralGateRejectsNestedRepeatedCardContainers(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $detect = function (string $html, string $componentCode, string $css): ?string {
            return $this->detectStructuralGeneratedSectionHtmlReason($html, $componentCode, $css);
        };

        $reason = $detect->call(
            $service,
            "<section class='pb-c-root'><div class='pb-c-rail'>"
                . "<div class='pb-c-card'><h3>Arjun</h3><p>Safe tables.</p>"
                . "<div class='pb-c-card'><h3>Priya</h3><p>Fair play.</p></div>"
                . "</div></div></section>",
            'content/home-page-customer-proof',
            '#componentId .pb-c-rail{display:flex;gap:18px;}#componentId .pb-c-card{padding:20px;}'
        );

        self::assertIsString($reason);
        self::assertStringContainsString('nested repeated content container', $reason);
    }

    public function testComponentPromptIncludesRepeatedCardStructureRecipe(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $buildPrompt = function (): string {
            return $this->buildComponentJsonPhpSafetyRulesEn();
        };

        $prompt = $buildPrompt->call($service);

        self::assertStringContainsString('Repeated-card structure recipe', $prompt);
        self::assertStringContainsString('direct sibling children of one rail/grid/list wrapper', $prompt);
        self::assertStringContainsString('never put the quote paragraph inside the star/meta row', $prompt);
        self::assertStringContainsString('Repeated-card self-check', $prompt);
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

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function scopeWithBuildPlanV2(array $scope, string $locale, string $siteName): array
    {
        $pageTypes = array_values(array_filter(array_map('strval', $scope['page_types'] ?? ['home_page'])));
        if ($pageTypes === []) {
            $pageTypes = ['home_page'];
        }

        $contentItems = [
            'site.name' => $siteName,
            'site.primary_goal' => 'Localized site defaults',
        ];
        $pages = [];

        foreach ($pageTypes as $pageType) {
            $pageId = str_replace('_', '-', $pageType);
            $titleKey = 'page.' . $pageType . '.title';
            $contentItems[$titleKey] = $this->localizedPageTitle($pageType, $locale);
            $pages[] = [
                'page_id' => $pageId,
                'page_type' => $pageType,
                'title_key' => $titleKey,
                'blocks' => [],
            ];
        }

        return array_replace_recursive([
            'build_plan_v2' => [
                'contract_meta' => [
                    'id' => 'locale-test-build-plan-v2',
                    'version' => '2.2',
                    'status' => 'confirmed',
                    'signature' => 'locale-test-signature',
                    'source_signature' => 'locale-test-source-signature',
                ],
                'site_brief' => [
                    'site_name' => $siteName,
                    'primary_goal' => 'Localized site defaults',
                    'locale' => $locale,
                ],
                'source_of_truth' => [
                    'user_requirements' => [
                        'site_name' => $siteName,
                    ],
                ],
                'i18n' => [
                    'primary_locale' => $locale,
                ],
                'content_manifest' => [
                    'primary_locale' => $locale,
                    'items' => $contentItems,
                ],
                'pages' => $pages,
                'blocks' => [],
            ],
        ], $scope);
    }

    private function localizedPageTitle(string $pageType, string $locale): string
    {
        $language = strtolower(substr($locale, 0, 2));

        if ($language === 'pt') {
            return match ($pageType) {
                'home_page' => "In\u{00ED}cio",
                'about_page' => 'Sobre',
                'contact_page' => 'Contato',
                'privacy_policy' => "Pol\u{00ED}tica de Privacidade",
                'terms_of_service' => 'Termos de Servico',
                default => str_replace('_', ' ', $pageType),
            };
        }

        if ($language === 'zh') {
            return match ($pageType) {
                'home_page' => "\u{9996}\u{9875}",
                'about_page' => "\u{5173}\u{4E8E}",
                'contact_page' => "\u{8054}\u{7CFB}\u{6211}\u{4EEC}",
                'privacy_policy' => "\u{9690}\u{79C1}\u{653F}\u{7B56}",
                'terms_of_service' => "\u{670D}\u{52A1}\u{6761}\u{6B3E}",
                default => str_replace('_', ' ', $pageType),
            };
        }

        return match ($pageType) {
            'home_page' => 'Home',
            'about_page' => 'About',
            'contact_page' => 'Contact',
            'privacy_policy' => 'Privacy Policy',
            'terms_of_service' => 'Terms of Service',
            default => str_replace('_', ' ', $pageType),
        };
    }
}
