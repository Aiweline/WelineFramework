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
        self::assertContains('structure.missing_plan_json_pages', $rules);
        self::assertContains('target_path', \array_keys($findings[0]));
        self::assertContains('rule', \array_keys($findings[0]));
    }

    public function testLintAllowsCompleteRenderDataFixture(): void
    {
        $findings = (new RenderDataQualityLinter())->lint([
            'payload' => [
                'plan_json' => [
                    'pages' => [
                    'home_page' => [
                        'hero' => [
                            'status' => 1,
                            'code' => 'hero',
                            'html' => '<section><h1>Download Teenipiya Rummy APK</h1></section>',
                            'fields' => [
                                'description' => 'A trust-first launch section for players who need safe APK access and clear game guidance.',
                            ],
                        ],
                    ],
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
                'plan_json' => [
                    'pages' => [
                    'privacy' => [
                        'details' => [
                            'status' => 1,
                            'code' => 'privacy-details',
                            'html' => '<div class="pb-content-privacy-policy-policy-details-card</div></div><section><h2>Privacy proof</h2><p>Clear policy details.</p></section>',
                        ],
                    ],
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
                'plan_json' => [
                    'pages' => [
                    'home_page' => [
                        'hero' => [
                            'status' => 1,
                            'code' => 'hero',
                            'html' => '<section><h1>Teen Patti de Confianca</h1></section>',
                            'config' => [
                                'body' => 'Teenipiya '
                                    . "\u{805A}\u{5408}\u{6838}\u{5FC3}\u{4EF7}\u{503C}\u{3001}\u{7279}\u{8272}\u{5185}\u{5BB9}"
                                    . "\u{3001}\u{4FE1}\u{4EFB}\u{4FE1}\u{606F}\u{548C}\u{4E3B}\u{8981}\u{884C}\u{52A8}\u{5165}\u{53E3}",
                            ],
                        ],
                    ],
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
                'plan_json' => [
                    'pages' => [
                    'home_page' => [
                        'hero' => [
                            'status' => 1,
                            'code' => 'hero',
                            'html' => '<section><h1>Teen Patti de Confianca</h1></section>',
                            'config' => [
                                'title' => 'Teen Patti de Confianca',
                                'cta_text' => "\u{4E0B}\u{8F7D}Teenipiya APK",
                            ],
                        ],
                    ],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $findings);
    }

    public function testLintIgnoresPlanJsonPageMetadataAndChecksDirectBlockNodes(): void
    {
        $findings = (new RenderDataQualityLinter())->lint([
            'payload' => [
                'plan_json' => [
                    'pages' => [
                        'home_page' => [
                            'page_design_plan' => ['structure' => 'Hero, proof, action.'],
                            'primary_keywords' => ['apk', 'rummy'],
                            'secondary_keywords' => ['safe install'],
                            'seo' => ['meta_title' => 'Home'],
                            'style_settings' => ['tone' => 'premium', 'status' => 0],
                            'section_refinements' => ['hero_download' => 'More trust proof.'],
                            'preview_full_url' => 'https://example.test/preview',
                            'visual_preview_url' => 'https://example.test/visual',
                            'virtual_edit_url' => 'https://example.test/edit',
                            'route_path' => '/',
                            'style_code' => 'poker-arena',
                            'ai_description' => 'Generated page metadata.',
                            'hero_download' => [
                                'status' => 1,
                                'block_key' => 'hero_download',
                                'html' => '<section><h1>Download APK</h1></section>',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $findings);
    }

    public function testLintAcceptsCanonicalPlanJsonBlockKeyAsIdentity(): void
    {
        $findings = (new RenderDataQualityLinter())->lint([
            'payload' => [
                'plan_json' => [
                    'pages' => [
                        'home_page' => [
                            'hero_download' => [
                                'status' => 1,
                                'html' => '<section><h1>Download APK</h1><p>Safe game install details.</p></section>',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $findings);
    }
}
