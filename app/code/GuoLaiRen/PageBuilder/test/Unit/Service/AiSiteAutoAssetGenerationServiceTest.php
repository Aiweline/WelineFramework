<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAssetManifestService;
use GuoLaiRen\PageBuilder\Service\AiSiteAutoAssetGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteReferenceImageInsightService;
use PHPUnit\Framework\TestCase;

final class AiSiteAutoAssetGenerationServiceTest extends TestCase
{
    public function testPrepareBuildAssetsUsesRealImageGenerationByDefault(): void
    {
        $publicId = 'asset-real-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $scope = [
            'site_title' => 'Asset Placeholder Test',
            'asset_manifest' => [
                'slots' => [
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home',
                        'label' => 'Hero visual',
                        'brief' => 'A premium homepage hero image for the generated site.',
                    ],
                ],
            ],
        ];

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static fn(): array => [
                'images' => [[
                    'b64_json' => \base64_encode('fake-png-bytes'),
                    'mime_type' => 'image/png',
                    'revised_prompt' => 'Generated hero visual',
                ]],
                'model' => 'fake-image-model',
            ]
        );
        $result = $service->prepareBuildAssets($session, 2, $scope, 1);
        $resultScope = $result['scope'];
        $slot = $resultScope['asset_manifest']['slots']['home:hero'] ?? [];
        $variant = $slot['variants'][0] ?? [];
        $relativePath = (string)($variant['path'] ?? '');
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);

        try {
            self::assertSame(['home:hero'], $result['generated_slots']);
            self::assertSame([], $result['failed_slots']);
            self::assertSame('done', (string)($slot['status'] ?? ''));
            self::assertSame('image/png', (string)($variant['mime_type'] ?? ''));
            self::assertSame('auto_build', (string)($variant['mode'] ?? ''));
            self::assertArrayNotHasKey('placeholder', $variant);
            self::assertStringEndsWith('.png', $relativePath);
            self::assertFileExists($absolutePath);
            self::assertSame('fake-png-bytes', (string)\file_get_contents($absolutePath));
            self::assertSame((string)($slot['final_url'] ?? ''), (string)($resultScope['verified_assets']['home:hero'] ?? ''));
        } finally {
            if ($relativePath !== '' && \is_file($absolutePath)) {
                \unlink($absolutePath);
            }
        }
    }

    public function testPrepareBuildAssetsStopsBeforeImagesWhenContentGateFails(): void
    {
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, 'asset-content-gate');

        $generatorCalled = false;
        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static function () use (&$generatorCalled): array {
                $generatorCalled = true;
                return ['images' => []];
            },
            static fn(): array => [
                'passed' => false,
                'items' => [[
                    'key' => 'content_quality',
                    'label' => 'content copy has duplicated plan text',
                    'ok' => false,
                    'blocking' => true,
                ]],
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Content quality gate must pass before image generation');

        try {
            $service->prepareBuildAssets($session, 2, [
                'site_title' => 'Content Gate Test',
                'content_generation_gate_required' => 1,
                'asset_manifest' => [
                    'slots' => [
                        'home:hero' => [
                            'slot_id' => 'home:hero',
                            'slot_type' => 'hero_image',
                            'page_type' => 'home',
                            'label' => 'Hero visual',
                            'brief' => 'A premium hero image.',
                        ],
                    ],
                ],
            ], 1);
        } finally {
            self::assertFalse($generatorCalled);
        }
    }

    public function testPrepareBuildAssetsRejectsLocalImageFallbackWhenGenerationFails(): void
    {
        $publicId = 'asset-placeholder-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $scope = [
            'site_title' => 'Asset Placeholder Test',
            'allow_placeholder_image_assets' => 1,
            'allow_local_image_fallback' => 1,
            'asset_manifest' => [
                'slots' => [
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home',
                        'label' => 'Hero visual',
                        'brief' => 'A premium homepage hero image for the generated site.',
                    ],
                ],
            ],
        ];

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static function (): array {
                throw new \RuntimeException('Image provider is not configured.');
            }
        );
        $result = $service->prepareBuildAssets($session, 2, $scope, 1);
        $resultScope = $result['scope'];
        $slot = $resultScope['asset_manifest']['slots']['home:hero'] ?? [];
        $failures = $resultScope['asset_image_generation_failures'] ?? [];

        self::assertSame([], $result['generated_slots']);
        self::assertSame('home:hero', (string)($result['failed_slots'][0]['slot_id'] ?? ''));
        self::assertSame('error', (string)($slot['status'] ?? ''));
        self::assertSame('', (string)($slot['final_url'] ?? ''));
        self::assertSame([], $slot['variants'] ?? []);
        self::assertArrayNotHasKey('home:hero', $resultScope['verified_assets'] ?? []);
        self::assertStringContainsString('Image provider is not configured', (string)($slot['error_message'] ?? ''));
        self::assertSame('home:hero', (string)($failures[0]['slot_id'] ?? ''));
    }

    public function testPrepareBuildAssetsRejectsLocalImageFallbackInFakeMode(): void
    {
        $publicId = 'asset-fake-mode-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $scope = [
            'site_title' => 'Fake Mode Build Test',
            'fake_mode' => 1,
            'allow_local_image_fallback' => 1,
            'asset_manifest' => [
                'slots' => [
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home',
                        'label' => 'Hero visual',
                        'brief' => 'A premium homepage hero image for the fake-mode test.',
                    ],
                ],
            ],
        ];

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static function (): array {
                throw new \RuntimeException('Fake-mode build must not call the image generator (forced contract).');
            }
        );
        $result = $service->prepareBuildAssets($session, 2, $scope, 1);
        $resultScope = $result['scope'];
        $slot = $resultScope['asset_manifest']['slots']['home:hero'] ?? [];

        self::assertSame([], $result['generated_slots']);
        self::assertSame('home:hero', (string)($result['failed_slots'][0]['slot_id'] ?? ''));
        self::assertSame('error', (string)($slot['status'] ?? ''));
        self::assertSame('', (string)($slot['final_url'] ?? ''));
        self::assertSame([], $slot['variants'] ?? []);
        self::assertArrayNotHasKey('home:hero', $resultScope['verified_assets'] ?? []);
        self::assertStringContainsString(
            'Fake-mode build must not call the image generator',
            (string)($slot['error_message'] ?? '')
        );
    }

    public function testPrepareBuildAssetsRegeneratesLegacyPlaceholderAssets(): void
    {
        $publicId = 'asset-placeholder-regenerate-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);
        $legacyPlaceholderUrl = '/pub/media/page-build/legacy/ai-generated/identity-website-logo-old.svg';

        $scope = [
            'site_title' => 'Legacy Placeholder Test',
            'logo' => $legacyPlaceholderUrl,
            'website_profile' => [
                'logo' => $legacyPlaceholderUrl,
            ],
            'asset_manifest' => [
                'slots' => [
                    'identity:website-logo' => [
                        'slot_id' => 'identity:website-logo',
                        'slot_type' => 'logo_icon',
                        'field' => 'logo',
                        'label' => 'Website Logo',
                        'brief' => 'Generate the website logo.',
                        'source' => 'generated',
                        'status' => 'done',
                        'final_url' => $legacyPlaceholderUrl,
                        'variants' => [[
                            'url' => $legacyPlaceholderUrl,
                            'mode' => 'placeholder',
                            'model' => 'placeholder',
                            'placeholder' => 1,
                        ]],
                    ],
                ],
            ],
        ];

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static fn(): array => [
                'images' => [[
                    'b64_json' => \base64_encode('real-logo-image'),
                    'mime_type' => 'image/png',
                    'revised_prompt' => 'Generated real logo',
                ]],
                'model' => 'fake-image-model',
            ]
        );

        $result = $service->prepareBuildAssets($session, 2, $scope, 2);
        $resultScope = $result['scope'];
        $manifest = \is_array($resultScope['asset_manifest']['slots'] ?? null) ? $resultScope['asset_manifest']['slots'] : [];
        $slot = $manifest['identity:website-logo'] ?? [];
        $finalUrl = (string)($slot['final_url'] ?? '');
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, \ltrim($finalUrl, '/'));

        try {
            self::assertContains('identity:website-logo', $result['generated_slots']);
            self::assertNotSame($legacyPlaceholderUrl, $finalUrl);
            self::assertStringEndsWith('.png', $finalUrl);
            self::assertSame($finalUrl, (string)($resultScope['verified_assets']['identity:website-logo'] ?? ''));
            self::assertSame($finalUrl, (string)($resultScope['logo'] ?? ''));
            self::assertSame($finalUrl, (string)($resultScope['website_profile']['logo'] ?? ''));
            self::assertSame('real-logo-image', (string)\file_get_contents($absolutePath));
        } finally {
            foreach ($manifest as $generatedSlot) {
                $relativePath = (string)($generatedSlot['variants'][0]['path'] ?? '');
                if ($relativePath === '') {
                    continue;
                }
                $path = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);
                if (\is_file($path)) {
                    \unlink($path);
                }
            }
        }
    }

    public function testPrepareBuildAssetsRetriesPlaceholderSectionAssetAndReplacesIt(): void
    {
        $publicId = 'asset-placeholder-section-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);
        $placeholderUrl = '/pub/media/page-build/demo/ai-generated/home-hero-old.svg';

        $scope = [
            'site_title' => 'Placeholder Section Retry Test',
            'asset_manifest' => [
                'slots' => [
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home',
                        'label' => 'Hero visual',
                        'brief' => 'Generate the homepage hero visual.',
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

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static fn(): array => [
                'images' => [[
                    'b64_json' => \base64_encode('real-hero-image'),
                    'mime_type' => 'image/png',
                    'revised_prompt' => 'Generated real hero visual',
                ]],
                'model' => 'fake-image-model',
            ]
        );

        $result = $service->prepareBuildAssets($session, 2, $scope, 1);
        $resultScope = $result['scope'];
        $slot = $resultScope['asset_manifest']['slots']['home:hero'] ?? [];
        $finalUrl = (string)($slot['final_url'] ?? '');
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, \ltrim($finalUrl, '/'));

        try {
            self::assertContains('home:hero', $result['generated_slots']);
            self::assertNotSame($placeholderUrl, $finalUrl);
            self::assertStringEndsWith('.png', $finalUrl);
            self::assertSame($finalUrl, (string)($resultScope['verified_assets']['home:hero'] ?? ''));
            self::assertSame('real-hero-image', (string)\file_get_contents($absolutePath));
        } finally {
            foreach (\is_array($slot['variants'] ?? null) ? $slot['variants'] : [] as $variant) {
                $relativePath = (string)($variant['path'] ?? '');
                if ($relativePath === '') {
                    continue;
                }
                $path = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);
                if (\is_file($path)) {
                    \unlink($path);
                }
            }
        }
    }

    public function testPrepareBuildAssetsAppendsReferenceImageInsightsToPrompt(): void
    {
        $publicId = 'asset-reference-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $scope = [
            'site_title' => 'Reference Guided Asset Test',
            'plan_locale' => 'en_US',
            'reference_images' => [[
                'url' => '/pub/media/page-build/reference/moodboard.png',
                'name' => 'Moodboard',
                'mime_type' => 'image/png',
            ]],
            'asset_manifest' => [
                'slots' => [
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home',
                        'label' => 'Hero visual',
                        'brief' => 'A premium homepage hero image for the generated site.',
                    ],
                ],
            ],
        ];

        $referenceInsightService = new class extends AiSiteReferenceImageInsightService {
            public function analyze(array $scope, string $locale = '', string $scenarioCode = self::DEFAULT_SCENARIO_CODE): array
            {
                return [
                    'summary' => 'Magazine-like layouts with bold imagery and layered composition.',
                    'style_keywords' => ['editorial', 'high contrast'],
                    'color_palette' => ['#112233', '#F0E0D0'],
                    'layout_cues' => ['Asymmetric hero framing'],
                    'component_cues' => ['Layered card stacks'],
                    'typography_cues' => ['Condensed bold headlines'],
                    'do_not_use' => ['flat stock-photo look'],
                ];
            }

            public function buildSignature(array $scope): string
            {
                return 'reference-sig';
            }
        };

        $seenPrompt = '';
        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            $referenceInsightService,
            static function (string $prompt) use (&$seenPrompt): array {
                $seenPrompt = $prompt;
                return [
                    'images' => [[
                        'b64_json' => \base64_encode('reference-guided-image'),
                        'mime_type' => 'image/png',
                        'revised_prompt' => $prompt,
                    ]],
                    'model' => 'fake-image-model',
                ];
            }
        );
        $result = $service->prepareBuildAssets($session, 2, $scope, 1);
        $resultScope = $result['scope'];
        $slot = $resultScope['asset_manifest']['slots']['home:hero'] ?? [];
        $variant = $slot['variants'][0] ?? [];
        $relativePath = (string)($variant['path'] ?? '');
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);

        try {
            self::assertSame('reference-sig', (string)($resultScope['reference_image_insights_signature'] ?? ''));
            self::assertSame(
                'Magazine-like layouts with bold imagery and layered composition.',
                (string)($resultScope['reference_image_insights']['summary'] ?? '')
            );
            // 强行契约：单 block 图像（hero/section）的 prompt 必须剥离 layout/component/typography/summary
            // cues，避免 AI 把整页 mockup 画进单 block 视觉里。
            self::assertStringNotContainsString(
                'Reference style summary',
                $seenPrompt,
                'Block-level prompt must strip "Reference style summary" to avoid AI rendering full website mockups.'
            );
            self::assertStringNotContainsString(
                'Reference layout cues',
                $seenPrompt,
                'Block-level prompt must strip layout cues that imply full-page structure.'
            );
            self::assertStringNotContainsString(
                'Reference component cues',
                $seenPrompt,
                'Block-level prompt must strip component cues to avoid AI rendering header/nav/CTA buttons.'
            );
            self::assertStringContainsString(
                'Reference style keywords (apply to subject only): editorial, high contrast',
                $seenPrompt
            );
            self::assertStringContainsString(
                'Avoid these reference mismatches: flat stock-photo look',
                $seenPrompt
            );
            // 同时确认负向 block-only 约束已注入
            self::assertStringContainsString('Block-only visual constraints', $seenPrompt);
            self::assertStringContainsString('DO NOT draw a website mockup', $seenPrompt);
            self::assertSame($seenPrompt, (string)($variant['revised_prompt'] ?? ''));
        } finally {
            if ($relativePath !== '' && \is_file($absolutePath)) {
                \unlink($absolutePath);
            }
        }
    }

    public function testPrepareBuildAssetsWritesIdentityAssetsBackToScope(): void
    {
        $publicId = 'asset-identity-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $scope = [
            'site_title' => 'Identity Asset Test',
            'asset_manifest' => [
                'slots' => [
                    'identity:website-logo' => [
                        'slot_id' => 'identity:website-logo',
                        'slot_type' => 'logo_icon',
                        'field' => 'logo',
                        'label' => 'Website Logo',
                        'brief' => 'Generate the website logo.',
                    ],
                    'identity:site-title-icon' => [
                        'slot_id' => 'identity:site-title-icon',
                        'slot_type' => 'logo_icon',
                        'field' => 'icon',
                        'label' => 'Website Title Icon',
                        'brief' => 'Generate the website title icon.',
                    ],
                ],
            ],
        ];

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static fn(string $prompt, int $adminId, string $slotId): array => [
                'images' => [[
                    'b64_json' => \base64_encode('required-' . $slotId),
                    'mime_type' => 'image/png',
                    'revised_prompt' => $prompt,
                ]],
                'model' => 'fake-image-model',
            ]
        );
        $result = $service->prepareBuildAssets($session, 2, $scope, 2);
        $resultScope = $result['scope'];
        $logoUrl = (string)($resultScope['logo'] ?? '');
        $iconUrl = (string)($resultScope['icon'] ?? '');
        $faviconUrl = (string)($resultScope['favicon'] ?? '');

        try {
            self::assertNotSame('', $logoUrl);
            self::assertNotSame('', $iconUrl);
            self::assertSame($iconUrl, $faviconUrl);
            self::assertSame($logoUrl, (string)($resultScope['website_profile']['logo'] ?? ''));
            self::assertSame($iconUrl, (string)($resultScope['website_profile']['icon'] ?? ''));
            self::assertSame($iconUrl, (string)($resultScope['website_profile']['favicon'] ?? ''));
        } finally {
            foreach ([$logoUrl, $iconUrl] as $url) {
                $relativePath = \ltrim($url, '/');
                if ($relativePath === '') {
                    continue;
                }
                $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);
                if (\is_file($absolutePath)) {
                    \unlink($absolutePath);
                }
            }
        }
    }

    public function testPrepareBuildAssetsInjectsRequiredIdentitySlots(): void
    {
        $publicId = 'asset-identity-required-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static fn(string $prompt, int $adminId, string $slotId): array => [
                'images' => [[
                    'b64_json' => \base64_encode('required-' . $slotId),
                    'mime_type' => 'image/png',
                    'revised_prompt' => $prompt,
                ]],
                'model' => 'fake-image-model',
            ]
        );
        $result = $service->prepareBuildAssets($session, 2, [
            'site_title' => 'Identity Required Slot Test',
        ], 2);
        $resultScope = $result['scope'];
        $manifest = \is_array($resultScope['asset_manifest']['slots'] ?? null) ? $resultScope['asset_manifest']['slots'] : [];

        try {
            self::assertContains('identity:website-logo', $result['generated_slots']);
            self::assertContains('identity:site-title-icon', $result['generated_slots']);
        } finally {
            foreach (['identity:website-logo', 'identity:site-title-icon'] as $slotId) {
                $relativePath = (string)($manifest[$slotId]['variants'][0]['path'] ?? '');
                if ($relativePath === '') {
                    continue;
                }
                $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);
                if (\is_file($absolutePath)) {
                    \unlink($absolutePath);
                }
            }
        }
    }

    public function testPrepareBuildAssetsRecordsFailureInsteadOfPlaceholderWhenImageGenerationFails(): void
    {
        $publicId = 'asset-failure-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $scope = [
            'site_title' => 'Asset Failure Test',
            'asset_manifest' => [
                'slots' => [
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home',
                        'label' => 'Hero visual',
                        'brief' => 'A premium homepage hero image for the generated site.',
                    ],
                ],
            ],
        ];

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static function (): array {
                throw new \RuntimeException('No text2image model configured.');
            }
        );

        $result = $service->prepareBuildAssets($session, 2, $scope, 1);
        $resultScope = $result['scope'];
        $slot = $resultScope['asset_manifest']['slots']['home:hero'] ?? [];

        self::assertSame([], $result['generated_slots']);
        self::assertCount(1, $result['failed_slots']);
        self::assertSame('home:hero', (string)($result['failed_slots'][0]['slot_id'] ?? ''));
        self::assertSame('error', (string)($slot['status'] ?? ''));
        self::assertSame('', (string)($slot['final_url'] ?? ''));
        self::assertSame([], $slot['variants'] ?? []);
        self::assertSame([], $resultScope['verified_assets'] ?? []);
        self::assertStringContainsString('No text2image model configured.', (string)($slot['error_message'] ?? ''));

        $failTrail = \is_array($resultScope['asset_image_generation_failures'] ?? null) ? $resultScope['asset_image_generation_failures'] : [];
        self::assertGreaterThanOrEqual(1, \count($failTrail));
        self::assertSame('home:hero', (string)($failTrail[\count($failTrail) - 1]['slot_id'] ?? ''));
        self::assertStringContainsString('No text2image model configured.', (string)($failTrail[\count($failTrail) - 1]['message'] ?? ''));
    }

    public function testPrepareBuildAssetsClearsHistoricalFailureWhenSlotGeneratesSuccessfully(): void
    {
        $publicId = 'asset-clear-failure-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $scope = [
            'site_title' => 'Asset Failure Cleanup Test',
            'asset_image_generation_failures' => [
                [
                    'slot_id' => 'home:hero',
                    'message' => 'Old provider failure',
                    'updated_at' => '2026-05-09 04:11:13',
                ],
                [
                    'slot_id' => 'identity:website-logo',
                    'message' => 'Keep this one',
                    'updated_at' => '2026-05-09 04:11:13',
                ],
            ],
            'asset_manifest' => [
                'slots' => [
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home',
                        'label' => 'Hero visual',
                        'brief' => 'A premium homepage hero image for the generated site.',
                    ],
                ],
            ],
        ];

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static fn(): array => [
                'images' => [[
                    'b64_json' => \base64_encode('clean-success-image'),
                    'mime_type' => 'image/png',
                    'revised_prompt' => 'Generated hero visual',
                ]],
                'model' => 'fake-image-model',
            ]
        );
        $result = $service->prepareBuildAssets($session, 2, $scope, 1);
        $resultScope = $result['scope'];
        $slot = $resultScope['asset_manifest']['slots']['home:hero'] ?? [];
        $variant = $slot['variants'][0] ?? [];
        $relativePath = (string)($variant['path'] ?? '');
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);

        try {
            $failTrail = \is_array($resultScope['asset_image_generation_failures'] ?? null) ? $resultScope['asset_image_generation_failures'] : [];
            self::assertCount(1, $failTrail);
            self::assertSame('identity:website-logo', (string)($failTrail[0]['slot_id'] ?? ''));
            self::assertSame(['home:hero'], $result['generated_slots']);
        } finally {
            if ($relativePath !== '' && \is_file($absolutePath)) {
                \unlink($absolutePath);
            }
        }
    }
}
