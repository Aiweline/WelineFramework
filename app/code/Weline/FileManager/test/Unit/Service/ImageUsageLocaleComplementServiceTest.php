<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\FileManager\Service\ImageUsageLocaleComplementService;

final class ImageUsageLocaleComplementServiceTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    private ImageUsageLocaleComplementService $service;

    protected function setUp(): void
    {
        $this->service = new ImageUsageLocaleComplementService();
    }

    public function testUsageAltWinsWhileEmptyCaptionComplementsFromLocale(): void
    {
        $usage = new ImageUsage(self::ASSET_ID, 'zh_Hans_CN', 'Banner 标题');
        $locale = $this->localeRow([
            FileAssetLocale::schema_fields_DEFAULT_ALT => '资源默认 alt',
            FileAssetLocale::schema_fields_DEFAULT_CAPTION => '资源默认说明',
        ], reviewed: true);

        $effective = $this->service->resolve($usage, $locale, false);

        self::assertSame('Banner 标题', $effective['alt']);
        self::assertSame('资源默认说明', $effective['caption']);
    }

    public function testFillsAltFromReviewedLocaleWhenUsageAltEmpty(): void
    {
        $usage = new ImageUsage(self::ASSET_ID, 'en_US', '', ImageUsage::ALT_CONFIRMED);
        $locale = $this->localeRow([
            FileAssetLocale::schema_fields_DEFAULT_ALT => 'Product on table',
        ], reviewed: true);

        $effective = $this->service->resolve($usage, $locale, false);

        self::assertSame('Product on table', $effective['alt']);
    }

    public function testFillsCaptionFromLocaleWhenUsageCaptionMissing(): void
    {
        $usage = new ImageUsage(
            self::ASSET_ID,
            'en_US',
            'Product',
            ImageUsage::ALT_CONFIRMED,
            false,
            'Page caption',
        );
        $locale = $this->localeRow([
            FileAssetLocale::schema_fields_DEFAULT_CAPTION => 'Asset caption',
        ], reviewed: true);

        $effective = $this->service->resolve($usage, $locale, false);

        self::assertSame('Product', $effective['alt']);
        self::assertSame('Page caption', $effective['caption']);
    }

    public function testFillsCaptionFromLocaleWhenUsageCaptionEmpty(): void
    {
        $usage = new ImageUsage(self::ASSET_ID, 'en_US', 'Product', ImageUsage::ALT_CONFIRMED);
        $locale = $this->localeRow([
            FileAssetLocale::schema_fields_DEFAULT_CAPTION => 'Asset caption',
        ], reviewed: true);

        $effective = $this->service->resolve($usage, $locale, false);

        self::assertSame('Asset caption', $effective['caption']);
    }

    public function testSkipsLocaleFallbackWhenComplementDisabled(): void
    {
        $usage = new ImageUsage(
            self::ASSET_ID,
            'en_US',
            '',
            ImageUsage::ALT_CONFIRMED,
            complement: false,
        );
        $locale = $this->localeRow([
            FileAssetLocale::schema_fields_DEFAULT_ALT => 'Asset alt',
        ], reviewed: true);

        $effective = $this->service->resolve($usage, $locale, false);

        self::assertSame('', $effective['alt']);
    }

    public function testDraftLocaleOnlyComplementsInPreviewMode(): void
    {
        $usage = new ImageUsage(self::ASSET_ID, 'en_US', '', ImageUsage::ALT_CONFIRMED);
        $locale = $this->localeRow([
            FileAssetLocale::schema_fields_DEFAULT_ALT => 'Draft alt',
        ], reviewed: false);

        self::assertSame('', $this->service->resolve($usage, $locale, false)['alt']);
        self::assertSame('Draft alt', $this->service->resolve($usage, $locale, true)['alt']);
    }

    public function testDecorativeImageIgnoresLocaleDefaults(): void
    {
        $usage = new ImageUsage(
            self::ASSET_ID,
            'en_US',
            '',
            ImageUsage::ALT_CONFIRMED,
            true,
        );
        $locale = $this->localeRow([
            FileAssetLocale::schema_fields_DEFAULT_ALT => 'Should not apply',
            FileAssetLocale::schema_fields_DEFAULT_CAPTION => 'Should not apply',
        ], reviewed: true);

        $effective = $this->service->resolve($usage, $locale, false);

        self::assertSame('', $effective['alt']);
        self::assertNull($effective['caption']);
    }

    /** @param array<string,mixed> $data */
    private function localeRow(array $data, bool $reviewed): FileAssetLocale
    {
        $locale = new FileAssetLocale();
        $locale->setData(array_merge([
            FileAssetLocale::schema_fields_ASSET_ID => self::ASSET_ID,
            FileAssetLocale::schema_fields_LOCALE_CODE => 'en_US',
            FileAssetLocale::schema_fields_TRANSLATION_STATE => $reviewed
                ? FileAssetLocale::STATE_REVIEWED
                : FileAssetLocale::STATE_DRAFT,
        ], $data));

        return $locale;
    }
}
