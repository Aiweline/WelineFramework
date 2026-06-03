<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\QA;

use GuoLaiRen\PageBuilder\Service\AI\QA\RenderDataQualityLinter;
use PHPUnit\Framework\TestCase;

final class RenderDataQualityLinterTest extends TestCase
{
    public function testLintReturnsStructuredStructureFindings(): void
    {
        $findings = (new RenderDataQualityLinter())->lint([
            'payload' => [
                'page_type_layouts' => [],
            ],
        ]);

        $categories = \array_values(\array_unique(\array_map(static fn(array $finding): string => (string)$finding['category'], $findings)));
        $rules = \array_map(static fn(array $finding): string => (string)$finding['rule'], $findings);
        self::assertSame(['structure'], $categories);
        self::assertContains('structure.missing_page_layouts', $rules);
        self::assertContains('target_path', \array_keys($findings[0]));
        self::assertContains('rule', \array_keys($findings[0]));
    }

    public function testLintAllowsCompleteRenderDataFixture(): void
    {
        $findings = (new RenderDataQualityLinter())->lint([
            'payload' => [
                'page_type_layouts' => [
                    'home_page' => [
                        'title' => 'Premium Rummy APK Download',
                        'description' => 'Download the secure Teenipiya rummy APK and learn safe play steps.',
                        'h1' => 'Teenipiya Rummy APK',
                        'content' => [
                            [
                                'code' => 'hero',
                                'component' => 'content/home-page-hero',
                                'title' => 'Download Teenipiya Rummy APK',
                                'description' => 'A trust-first launch section for players who need safe APK access and clear game guidance.',
                                'design_tags' => [
                                    'visual' => ['trust card'],
                                    'motion' => ['subtle entrance'],
                                ],
                            ],
                        ],
                    ],
                ],
                'materialized_pages_by_type' => [
                    'home_page' => [
                        'seo_title' => 'Teenipiya Rummy APK Download Guide',
                        'seo_description' => 'Download the Teenipiya rummy APK, review safe play basics, and follow clear onboarding guidance for Indian players.',
                        'h1' => 'Teenipiya Rummy APK Download',
                    ],
                ],
            ],
        ]);

        self::assertSame([], $findings);
    }

    public function testLintFlagsMalformedHtmlStructure(): void
    {
        $findings = (new RenderDataQualityLinter())->lint([
            'payload' => [
                'page_type_layouts' => [
                    'privacy' => [
                        'title' => 'Privacy Policy',
                        'description' => 'Clear policy details for every visitor.',
                        'h1' => 'Privacy Policy',
                        'content' => [
                            [
                                'code' => 'privacy-details',
                                'component' => 'content/privacy-policy',
                                'html' => '<div class="pb-content-privacy-policy-policy-details-card</div></div><section><h2>Privacy proof</h2><p>Clear policy details.</p></section>',
                                'design_tags' => ['visual' => ['policy card']],
                            ],
                        ],
                    ],
                ],
                'materialized_pages_by_type' => [
                    'privacy' => [
                        'seo_title' => 'Teenipiya Privacy Policy',
                        'seo_description' => 'Review how Teenipiya explains privacy, safety, and policy details for visitors.',
                        'h1' => 'Teenipiya Privacy Policy',
                    ],
                ],
            ],
        ]);

        $rules = \array_map(static fn(array $finding): string => (string)$finding['rule'], $findings);
        self::assertContains('structure.malformed_html', $rules);
    }

    public function testLintDoesNotGateNonTargetLanguageCopyInsideBlockConfig(): void
    {
        $findings = (new RenderDataQualityLinter())->lint([
            'payload' => [
                'content_locale' => 'pt_BR',
                'page_type_layouts' => [
                    'home_page' => [
                        'title' => 'Teenipiya',
                        'description' => 'Baixe o APK com regras claras e suporte seguro.',
                        'h1' => 'Teen Patti de Confianca',
                        'content' => [
                            [
                                'code' => 'hero',
                                'component' => 'content/home-page-hero',
                                'title' => 'Teen Patti de Confianca',
                                'config' => [
                                    'body' => 'Teenipiya '
                                        . "\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}\u{3001}\u{7279}\u{8272}\u{5185}\u{5BB9}"
                                        . "\u{3001}\u{4FE1}\u{4EFB}\u{4FE1}\u{606F}\u{548C}\u{4E3B}\u{8981}\u{884C}\u{52A8}\u{5165}\u{53E3}",
                                ],
                                'design_tags' => ['visual' => ['trust card']],
                            ],
                        ],
                    ],
                ],
                'materialized_pages_by_type' => [
                    'home_page' => [
                        'seo_title' => 'Teenipiya APK Seguro',
                        'seo_description' => 'Baixe o APK com regras claras, suporte seguro e orientacao para jogar.',
                        'h1' => 'Teen Patti de Confianca',
                    ],
                ],
            ],
        ]);

        self::assertSame([], $findings);
    }

    public function testLintDoesNotGateShortNonTargetLanguageCtaCopyInsideBlockConfig(): void
    {
        $findings = (new RenderDataQualityLinter())->lint([
            'payload' => [
                'content_locale' => 'pt_BR',
                'page_type_layouts' => [
                    'home_page' => [
                        'content' => [[
                            'code' => 'hero',
                            'component' => 'content/home-page-hero',
                            'config' => [
                                'title' => 'Teen Patti de Confianca',
                                'cta_text' => "\u{4E0B}\u{8F7D}Teenipiya APK",
                            ],
                        ]],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $findings);
    }
}
