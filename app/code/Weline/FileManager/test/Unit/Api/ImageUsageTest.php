<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\ImageUsage;

final class ImageUsageTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    public function testUsageRoundTripsAsVersionedPageContextSnapshot(): void
    {
        $usage = ImageUsage::fromArray([
            'version' => 1,
            'asset_id' => self::ASSET_ID,
            'locale_code' => 'zh_Hans_CN',
            'alt' => '商品在木桌上的正面图',
            'alt_state' => 'confirmed',
            'decorative' => false,
            'caption' => '新品图',
            'loading' => 'lazy',
            'priority' => 'auto',
            'widths' => ['480', 768, 1280],
            'sizes' => '100vw',
        ]);

        self::assertSame(self::ASSET_ID, $usage->assetId);
        self::assertSame([480, 768, 1280], $usage->widths);
        self::assertSame('zh_Hans_CN', $usage->localeCode);
        self::assertSame($usage->toArray(), ImageUsage::fromArray($usage->toArray())->toArray());
        $usage->assertPublishable('zh_Hans_CN');
        self::addToAssertionCount(1);
    }

    public function testInformativeImageRequiresConfirmedNonEmptyAlt(): void
    {
        $usage = new ImageUsage(self::ASSET_ID, 'en_US', '', ImageUsage::ALT_CONFIRMED);

        $this->expectException(\RuntimeException::class);
        $usage->assertPublishable('en_US');
    }

    public function testDecorativeImageRequiresExplicitEmptyAltAndCanPublish(): void
    {
        $usage = new ImageUsage(
            self::ASSET_ID,
            'en_US',
            '',
            ImageUsage::ALT_CONFIRMED,
            true,
        );

        $usage->assertPublishable('en_US');
        self::assertTrue($usage->decorative);
        self::assertSame('', $usage->alt);
    }

    public function testCopiedOrMachineTranslatedAltNeedsReviewBeforePublish(): void
    {
        $usage = new ImageUsage(
            self::ASSET_ID,
            'en_US',
            'Translated context',
            ImageUsage::ALT_NEEDS_REVIEW,
        );

        $this->expectException(\RuntimeException::class);
        $usage->assertPublishable('en_US');
    }

    public function testExactLocaleIsPartOfPublicationIdentity(): void
    {
        $usage = new ImageUsage(self::ASSET_ID, 'en_US', 'Product image');

        $this->expectException(\RuntimeException::class);
        $usage->assertPublishable('fr_FR');
    }

    public function testRejectsAmbiguousBooleanAndDuplicateResponsiveWidths(): void
    {
        try {
            ImageUsage::fromArray([
                'asset_id' => self::ASSET_ID,
                'locale_code' => 'en_US',
                'alt' => 'Image',
                'decorative' => 'yes',
            ]);
            self::fail('Ambiguous boolean was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        new ImageUsage(
            self::ASSET_ID,
            'en_US',
            'Image',
            widths: [480, 480],
        );
    }
}
