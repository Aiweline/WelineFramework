<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAssetManifestService;
use GuoLaiRen\PageBuilder\Service\AiSiteAutoAssetGenerationService;
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
}
