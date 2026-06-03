<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAssetManifestService;
use GuoLaiRen\PageBuilder\Service\AiSiteAutoAssetGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteReferenceImageInsightService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;

final class AiSiteAutoAssetGenerationServiceTest extends TestCase
{
    private ?string $previousSkipInlineImagesEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $previous = \getenv('PAGEBUILDER_AI_SITE_SKIP_INLINE_IMAGES');
        $this->previousSkipInlineImagesEnv = $previous === false ? null : (string)$previous;
        \putenv('PAGEBUILDER_AI_SITE_SKIP_INLINE_IMAGES');
        RequestContext::remove('pagebuilder.ai.inline_image_generation.disabled');
    }

    protected function tearDown(): void
    {
        RequestContext::remove('pagebuilder.ai.inline_image_generation.disabled');
        if ($this->previousSkipInlineImagesEnv === null) {
            \putenv('PAGEBUILDER_AI_SITE_SKIP_INLINE_IMAGES');
        } else {
            \putenv('PAGEBUILDER_AI_SITE_SKIP_INLINE_IMAGES=' . $this->previousSkipInlineImagesEnv);
        }
        parent::tearDown();
    }

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
            self::assertStringStartsWith('pub/media/page-build/ai-generated/' . $publicId . '/', $relativePath);
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

    public function testGenerateSlotAssetUsesPreverifiedAssetWithoutCallingImageProvider(): void
    {
        $publicId = 'asset-preverified-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $slotId = 'home:hero';
        $finalUrl = '/pub/media/page-build/ai-generated/' . $publicId . '/home-hero.png';
        $generatorCalled = false;
        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static function () use (&$generatorCalled): array {
                $generatorCalled = true;
                throw new \RuntimeException('Preverified asset should bypass image generation.');
            }
        );

        $result = $service->generateSlotAsset($session, 2, [
            'site_title' => 'Preverified Asset Test',
            'verified_assets' => [
                $slotId => $finalUrl,
            ],
            'asset_manifest' => [
                'slots' => [
                    $slotId => [
                        'slot_id' => $slotId,
                        'slot_type' => 'hero_image',
                        'page_type' => 'home',
                        'label' => 'Hero visual',
                        'brief' => 'A preverified homepage hero image.',
                        'required' => 1,
                        'desired_image' => 1,
                        'status' => 'pending',
                        'source' => 'build_plan_v2',
                    ],
                ],
            ],
        ], $slotId);

        $resultScope = $result['scope'];
        $slot = $resultScope['asset_manifest']['slots'][$slotId] ?? [];

        self::assertFalse($generatorCalled);
        self::assertFalse($result['generated']);
        self::assertSame($slotId, $result['slot_id']);
        self::assertSame($finalUrl, $result['final_url']);
        self::assertSame($finalUrl, (string)($slot['final_url'] ?? ''));
        self::assertSame($finalUrl, (string)($resultScope['verified_assets'][$slotId] ?? ''));
        self::assertSame([], $resultScope['asset_image_generation_failures'] ?? []);
    }

    public function testPrepareBuildAssetsSkipsImageProviderWhenRequestContextDisablesImages(): void
    {
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, 'asset-skip-build');
        $called = false;
        RequestContext::set('pagebuilder.ai.inline_image_generation.disabled', true);

        try {
            $service = new AiSiteAutoAssetGenerationService(
                new AiSiteAssetManifestService(),
                null,
                static function () use (&$called): array {
                    $called = true;
                    throw new \RuntimeException('Image provider must not be called.');
                }
            );
            $result = $service->prepareBuildAssets($session, 2, [
                'site_title' => 'Asset Skip Test',
                'asset_manifest' => [
                    'slots' => [
                        'home:hero' => [
                            'slot_id' => 'home:hero',
                            'slot_type' => 'hero_image',
                            'brief' => 'Hero image should be deferred.',
                        ],
                    ],
                ],
            ], 1);

            self::assertFalse($called);
            self::assertSame([], $result['generated_slots']);
            self::assertSame([], $result['failed_slots']);
            self::assertSame('disabled_by_test_switch', $result['scope']['asset_image_generation_deferred']['reason'] ?? null);
        } finally {
            RequestContext::remove('pagebuilder.ai.inline_image_generation.disabled');
        }
    }

    public function testGenerateSlotAssetSkipsImageProviderWhenRequestContextDisablesImages(): void
    {
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, 'asset-slot-skip');
        $called = false;
        RequestContext::set('pagebuilder.ai.inline_image_generation.disabled', true);

        try {
            $service = new AiSiteAutoAssetGenerationService(
                new AiSiteAssetManifestService(),
                null,
                static function () use (&$called): array {
                    $called = true;
                    throw new \RuntimeException('Image provider must not be called.');
                }
            );
            $result = $service->generateSlotAsset($session, 2, [
                'site_title' => 'Slot Skip Test',
                'asset_manifest' => [
                    'slots' => [
                        'home:hero' => [
                            'slot_id' => 'home:hero',
                            'slot_type' => 'hero_image',
                            'brief' => 'Hero image should be deferred.',
                        ],
                    ],
                ],
            ], 'home:hero');

            self::assertFalse($called);
            self::assertFalse($result['generated']);
            self::assertSame('', $result['final_url']);
            self::assertArrayNotHasKey('failed_slot', $result);
            self::assertSame('disabled_by_test_switch', $result['scope']['asset_image_generation_deferred']['reason'] ?? null);
            self::assertSame('home:hero', $result['scope']['asset_image_generation_deferred']['slot_id'] ?? null);
        } finally {
            RequestContext::remove('pagebuilder.ai.inline_image_generation.disabled');
        }
    }

    public function testGenerateSlotAssetHonorsSlotImageAttemptLimit(): void
    {
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, 'asset-attempt-limit');
        $slotId = 'home:hero';
        $calls = 0;

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static function () use (&$calls): array {
                $calls++;
                throw new \RuntimeException('VectorEngine API failed (HTTP: 0): Operation timed out after 180010 milliseconds with 0 bytes received');
            }
        );

        $result = $service->generateSlotAsset($session, 2, [
            'site_title' => 'Attempt Limit Test',
        ], $slotId, [
            'slot_id' => $slotId,
            'slot_type' => 'hero_image',
            'page_type' => 'home',
            'label' => 'Hero visual',
            'prompt_brief' => 'A premium homepage hero image.',
            'image_generation_max_attempts' => 1,
        ]);

        self::assertSame(1, $calls);
        self::assertFalse($result['generated']);
        self::assertSame($slotId, (string)($result['failed_slot']['slot_id'] ?? ''));
        self::assertStringContainsString('Operation timed out', (string)($result['failed_slot']['message'] ?? ''));
    }

    public function testPrepareBuildAssetsGeneratesLogoAndTitleIconBeforeHeroWhenLimited(): void
    {
        $publicId = 'asset-identity-first-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $scope = [
            'site_title' => 'Royal Indian Games',
            'auto_generate_identity_assets_first' => 1,
            'website_profile' => [
                'site_title' => 'Royal Indian Games',
                'brief_description' => 'A premium card game website.',
            ],
            'asset_manifest' => [
                'slots' => [
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home_page',
                        'label' => 'Hero visual',
                        'brief' => 'A premium homepage hero image.',
                    ],
                ],
            ],
        ];

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static fn(string $prompt, int $adminId, string $slotId): array => [
                'images' => [[
                    'b64_json' => \base64_encode(self::transparentIdentitySvg($slotId)),
                    'mime_type' => 'image/svg+xml',
                ]],
                'model' => 'fake-image-model',
            ]
        );
        $result = $service->prepareBuildAssets($session, 2, $scope, 2);
        $generatedSlots = $result['generated_slots'];
        $slots = $result['scope']['asset_manifest']['slots'] ?? [];

        try {
            self::assertCount(2, $generatedSlots);
            self::assertNotContains('home:hero', $generatedSlots);
            foreach ($generatedSlots as $slotId) {
                self::assertSame('logo_icon', (string)($slots[$slotId]['slot_type'] ?? ''), 'Expected identity slots before hero image.');
            }
            self::assertSame('pending', (string)($slots['home:hero']['status'] ?? 'pending'));
        } finally {
            foreach ($generatedSlots as $slotId) {
                $slot = \is_array($slots[$slotId] ?? null) ? $slots[$slotId] : [];
                $variant = \is_array($slot['variants'][0] ?? null) ? $slot['variants'][0] : [];
                $relativePath = (string)($variant['path'] ?? '');
                $absolutePath = $relativePath !== '' ? BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath) : '';
                if ($absolutePath !== '' && \is_file($absolutePath)) {
                    \unlink($absolutePath);
                }
            }
        }
    }

    public function testPrepareBuildAssetsPrioritizesPageImagesBeforeIdentityByDefault(): void
    {
        $publicId = 'asset-page-images-first-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $scope = [
            'site_title' => 'Royal Indian Games',
            'website_profile' => [
                'site_title' => 'Royal Indian Games',
                'brief_description' => 'A premium card game website.',
            ],
            'asset_manifest' => [
                'slots' => [
                    'identity:website-logo' => [
                        'slot_id' => 'identity:website-logo',
                        'slot_type' => 'logo_icon',
                        'required' => 1,
                        'label' => 'Website logo',
                        'brief' => 'A transparent website logo.',
                    ],
                    'identity:site-title-icon' => [
                        'slot_id' => 'identity:site-title-icon',
                        'slot_type' => 'logo_icon',
                        'required' => 1,
                        'label' => 'Site title icon',
                        'brief' => 'A transparent title icon.',
                    ],
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home_page',
                        'required' => 1,
                        'label' => 'Hero visual',
                        'brief' => 'A premium homepage hero image.',
                    ],
                    'about:story' => [
                        'slot_id' => 'about:story',
                        'slot_type' => 'section_image',
                        'page_type' => 'about_page',
                        'required' => 1,
                        'label' => 'Origin story visual',
                        'brief' => 'A card-room origin story image.',
                    ],
                ],
            ],
        ];

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static fn(string $prompt, int $adminId, string $slotId): array => [
                'images' => [[
                    'b64_json' => \base64_encode('page-image-' . $slotId),
                    'mime_type' => 'image/png',
                ]],
                'model' => 'fake-image-model',
            ]
        );
        $result = $service->prepareBuildAssets($session, 2, $scope, 2);
        $generatedSlots = $result['generated_slots'];
        $slots = $result['scope']['asset_manifest']['slots'] ?? [];

        try {
            self::assertSame(['home:hero', 'about:story'], $generatedSlots);
            self::assertSame('pending', (string)($slots['identity:website-logo']['status'] ?? 'pending'));
            self::assertSame('pending', (string)($slots['identity:site-title-icon']['status'] ?? 'pending'));
            foreach ($generatedSlots as $slotId) {
                self::assertSame('done', (string)($slots[$slotId]['status'] ?? ''));
            }
        } finally {
            foreach ($generatedSlots as $slotId) {
                $slot = \is_array($slots[$slotId] ?? null) ? $slots[$slotId] : [];
                $variant = \is_array($slot['variants'][0] ?? null) ? $slot['variants'][0] : [];
                $relativePath = (string)($variant['path'] ?? '');
                $absolutePath = $relativePath !== '' ? BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath) : '';
                if ($absolutePath !== '' && \is_file($absolutePath)) {
                    \unlink($absolutePath);
                }
            }
        }
    }

    public function testPrepareBuildAssetsStoresGeneratedFilesUnderDomainHandle(): void
    {
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, 'asset-domain-path');

        $scope = [
            'target_domain' => 'https://cards.example.com/path?x=1',
            'site_title' => '印度市场的棋牌网站',
            'asset_manifest' => [
                'slots' => [
                    'home:hero' => [
                        'slot_id' => 'home:hero',
                        'slot_type' => 'hero_image',
                        'page_type' => 'home',
                        'label' => 'Hero visual',
                        'brief' => 'A premium homepage hero image.',
                    ],
                ],
            ],
        ];

        $service = new AiSiteAutoAssetGenerationService(
            new AiSiteAssetManifestService(),
            null,
            static fn(): array => [
                'images' => [[
                    'b64_json' => \base64_encode('domain-path-image'),
                    'mime_type' => 'image/jpeg',
                ]],
                'model' => 'fake-image-model',
            ]
        );
        $result = $service->prepareBuildAssets($session, 2, $scope, 1);
        $slot = $result['scope']['asset_manifest']['slots']['home:hero'] ?? [];
        $variant = $slot['variants'][0] ?? [];
        $relativePath = (string)($variant['path'] ?? '');
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);

        try {
            self::assertStringStartsWith('pub/media/page-build/ai-generated/cards.example.com/', $relativePath);
            self::assertSame('/' . $relativePath, (string)($slot['final_url'] ?? ''));
        } finally {
            if ($relativePath !== '' && \is_file($absolutePath)) {
                \unlink($absolutePath);
            }
        }
    }

    public function testPrepareBuildAssetsStopsBeforeImagesWhenBuildStructureGateFails(): void
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
                    'key' => 'render_data_quality',
                    'label' => 'render data has missing sections',
                    'ok' => false,
                    'blocking' => true,
                ]],
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Build structure gate must pass before image generation');

        try {
            $service->prepareBuildAssets($session, 2, [
                'site_title' => 'Content Gate Test',
                'image_generation_requires_build_ready' => 1,
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
            static fn(string $prompt, int $adminId, string $slotId): array => [
                'images' => [[
                    'b64_json' => \base64_encode(self::transparentIdentitySvg($slotId)),
                    'mime_type' => 'image/svg+xml',
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
            self::assertStringEndsWith('.svg', $finalUrl);
            self::assertSame($finalUrl, (string)($resultScope['verified_assets']['identity:website-logo'] ?? ''));
            self::assertSame($finalUrl, (string)($resultScope['logo'] ?? ''));
            self::assertSame($finalUrl, (string)($resultScope['website_profile']['logo'] ?? ''));
            self::assertStringContainsString('<svg', (string)\file_get_contents($absolutePath));
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
            self::assertStringContainsString('Block-only image artifact contract', $seenPrompt);
            self::assertStringContainsString('DO NOT include website chrome', $seenPrompt);
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
                    'b64_json' => \base64_encode(self::transparentIdentitySvg($slotId)),
                    'mime_type' => 'image/svg+xml',
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
                    'b64_json' => \base64_encode(self::transparentIdentitySvg($slotId)),
                    'mime_type' => 'image/svg+xml',
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

    public function testPrepareBuildAssetsCompactsHistoricalFailureTrail(): void
    {
        $publicId = 'asset-failure-compact-' . \bin2hex(\random_bytes(4));
        $session = new AiSiteAgentSession();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId);

        $failureTrail = [];
        for ($i = 0; $i < 100; $i++) {
            $failureTrail[] = [
                'slotId' => 'old-slot-' . $i,
                'message' => \str_repeat((string)($i % 10), 900),
                'updated_at' => '2026-05-24 08:00:00',
            ];
        }

        $scope = [
            'site_title' => 'Asset Failure Compaction Test',
            'asset_image_generation_failures' => $failureTrail,
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
                throw new \RuntimeException(\str_repeat('provider failure ', 90));
            }
        );

        $result = $service->prepareBuildAssets($session, 2, $scope, 1);
        $failTrail = \is_array($result['scope']['asset_image_generation_failures'] ?? null)
            ? $result['scope']['asset_image_generation_failures']
            : [];

        self::assertCount(80, $failTrail);
        self::assertSame('old-slot-21', (string)($failTrail[0]['slot_id'] ?? ''));
        self::assertArrayNotHasKey('slotId', $failTrail[0]);
        self::assertContains((string)($failTrail[79]['slot_id'] ?? ''), \array_column($result['failed_slots'], 'slot_id'));
        self::assertLessThanOrEqual(803, \mb_strlen((string)($failTrail[79]['message'] ?? '')));
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

    private static function transparentIdentitySvg(string $slotId): string
    {
        $label = \str_contains($slotId, 'site-title-icon') ? 'I' : 'L';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">'
            . '<circle cx="64" cy="64" r="42" fill="#00F5FF"/>'
            . '<path d="M36 72 L64 24 L92 72 Z" fill="#FF2BD6"/>'
            . '<text x="64" y="88" text-anchor="middle" font-size="34" font-family="Arial" fill="#FFFFFF">'
            . $label
            . '</text>'
            . '</svg>';
    }
}
