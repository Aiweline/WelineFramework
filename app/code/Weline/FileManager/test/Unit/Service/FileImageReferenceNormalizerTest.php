<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Service\FileImageReferenceNormalizer;

final class FileImageReferenceNormalizerTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    public function testExtractAssetIdFromUuidAndAssetUri(): void
    {
        $normalizer = $this->normalizer();
        self::assertSame(self::ASSET_ID, $normalizer->extractAssetId(self::ASSET_ID));
        self::assertSame(self::ASSET_ID, $normalizer->extractAssetId('asset://' . self::ASSET_ID));
        self::assertSame(self::ASSET_ID, $normalizer->extractAssetId('ASSET://' . strtoupper(self::ASSET_ID)));
        self::assertSame('', $normalizer->extractAssetId('banner/hero.jpg'));
        self::assertSame('', $normalizer->extractAssetId(''));
    }

    public function testNormalizeMediaPathStripsPubMediaPrefix(): void
    {
        $normalizer = $this->normalizer();
        self::assertSame('banner/hero.jpg', $normalizer->normalizeMediaPath('/pub/media/banner/hero.jpg'));
        self::assertSame('banner/hero.jpg', $normalizer->normalizeMediaPath('pub/media/banner/hero.jpg'));
        self::assertSame('banner/hero.jpg', $normalizer->normalizeMediaPath('media/banner/hero.jpg'));
        self::assertSame('websites/1/s/banner/a.png', $normalizer->normalizeMediaPath('websites/1/s/banner/a.png'));
    }

    public function testNormalizeFileImageEnvelopeAndUsageArray(): void
    {
        $normalizer = $this->normalizer();
        $usage = $normalizer->normalizeToUsage([
            'type' => 'file-image',
            'usage' => $this->usagePayload(),
        ], '', '', false, 'en_US', null, 16, 9);
        self::assertInstanceOf(ImageUsage::class, $usage);
        self::assertSame(self::ASSET_ID, $usage->assetId);
        self::assertSame(16, $usage->layoutWidth);
        self::assertSame(9, $usage->layoutHeight);

        $fromJson = $normalizer->normalizeToUsage(
            json_encode(['type' => 'file-image', 'usage' => $this->usagePayload()], JSON_THROW_ON_ERROR),
            '',
            '',
            false,
            'en_US',
        );
        self::assertInstanceOf(ImageUsage::class, $fromJson);
        self::assertSame(self::ASSET_ID, $fromJson->assetId);
    }

    public function testNormalizeBareUuidAndAssetUri(): void
    {
        $normalizer = $this->normalizer();
        $fromUuid = $normalizer->normalizeToUsage(null, self::ASSET_ID, 'Hero', false, 'en_US');
        self::assertInstanceOf(ImageUsage::class, $fromUuid);
        self::assertSame('Hero', $fromUuid->alt);

        $fromUri = $normalizer->normalizeToUsage('asset://' . self::ASSET_ID, '', 'Alt', false, 'zh_Hans_CN');
        self::assertInstanceOf(ImageUsage::class, $fromUri);
        self::assertSame(self::ASSET_ID, $fromUri->assetId);
        self::assertSame('zh_Hans_CN', $fromUri->localeCode);
    }

    public function testUnresolvedPathReturnsNull(): void
    {
        $normalizer = $this->normalizer();
        self::assertNull($normalizer->normalizeToUsage(
            'banner/does-not-exist-' . bin2hex(random_bytes(4)) . '.jpg',
            '',
            '',
            false,
            'en_US',
        ));
        self::assertNull($normalizer->normalizeToUsage('', '', '', false, 'en_US'));
    }

    public function testInvalidJsonObjectStringFallsThroughToPathAndReturnsNull(): void
    {
        $normalizer = $this->normalizer();
        // Leading `{` but invalid JSON must not throw; treated as unresolved path.
        self::assertNull($normalizer->normalizeToUsage('{not-json', '', '', false, 'en_US'));
    }

    /** @return array<string,mixed> */
    private function usagePayload(): array
    {
        return [
            'version' => 1,
            'asset_id' => self::ASSET_ID,
            'locale_code' => 'en_US',
            'alt' => 'Product',
            'alt_state' => ImageUsage::ALT_CONFIRMED,
            'decorative' => false,
            'loading' => 'lazy',
            'priority' => 'auto',
            'widths' => [480, 768],
            'sizes' => '100vw',
            'complement' => true,
        ];
    }

    private function normalizer(): FileImageReferenceNormalizer
    {
        $assets = (new \ReflectionClass(FileAsset::class))->newInstanceWithoutConstructor();

        return new FileImageReferenceNormalizer($assets);
    }
}
