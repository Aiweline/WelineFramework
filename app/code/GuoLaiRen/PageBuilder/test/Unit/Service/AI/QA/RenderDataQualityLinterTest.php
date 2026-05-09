<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\QA;

use GuoLaiRen\PageBuilder\Service\AI\QA\RenderDataQualityLinter;
use PHPUnit\Framework\TestCase;

final class RenderDataQualityLinterTest extends TestCase
{
    public function testLintReturnsStructuredDesignCopyAndSeoFindings(): void
    {
        $findings = (new RenderDataQualityLinter())->lint([
            'payload' => [
                'page_type_layouts' => [
                    'home_page' => [
                        'content' => [
                            [
                                'code' => 'hero',
                                'title' => 'Welcome to our website',
                            ],
                        ],
                    ],
                ],
                'materialized_pages_by_type' => [
                    'home_page' => [
                        'seo_title' => 'Home',
                    ],
                ],
            ],
        ]);

        $categories = \array_values(\array_unique(\array_map(static fn(array $finding): string => (string)$finding['category'], $findings)));
        self::assertContains('design', $categories);
        self::assertContains('copy', $categories);
        self::assertContains('seo', $categories);
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
        self::assertContains('design.malformed_html_structure', $rules);
    }
}
