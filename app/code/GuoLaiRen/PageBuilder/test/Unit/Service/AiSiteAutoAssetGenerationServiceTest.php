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
    public function testPrepareBuildAssetsWritesPlaceholderByDefault(): void
    {
        $publicId = 'asset-placeholder-' . \bin2hex(\random_bytes(4));
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

        $service = new AiSiteAutoAssetGenerationService(new AiSiteAssetManifestService());
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
            self::assertSame('image/svg+xml', (string)($variant['mime_type'] ?? ''));
            self::assertSame('placeholder', (string)($variant['mode'] ?? ''));
            self::assertSame(1, (int)($variant['placeholder'] ?? 0));
            self::assertStringEndsWith('.svg', $relativePath);
            self::assertFileExists($absolutePath);
            self::assertStringContainsString('Text-to-image is not connected yet', (string)\file_get_contents($absolutePath));
            self::assertSame((string)($slot['final_url'] ?? ''), (string)($resultScope['verified_assets']['home:hero'] ?? ''));
        } finally {
            if ($relativePath !== '' && \is_file($absolutePath)) {
                \unlink($absolutePath);
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

        $service = new AiSiteAutoAssetGenerationService(new AiSiteAssetManifestService(), $referenceInsightService);
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
            self::assertStringContainsString(
                'Reference style summary: Magazine-like layouts with bold imagery and layered composition.',
                (string)($variant['revised_prompt'] ?? '')
            );
            self::assertStringContainsString(
                'Reference style keywords: editorial, high contrast',
                (string)($variant['revised_prompt'] ?? '')
            );
            self::assertStringContainsString(
                'Avoid these reference mismatches: flat stock-photo look',
                (string)($variant['revised_prompt'] ?? '')
            );
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

        $service = new AiSiteAutoAssetGenerationService(new AiSiteAssetManifestService());
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

        $service = new AiSiteAutoAssetGenerationService(new AiSiteAssetManifestService());
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
}
