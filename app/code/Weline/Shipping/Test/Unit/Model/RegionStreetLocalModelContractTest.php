<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationCatalog;
use Weline\Shipping\Model\PostalPlace;
use Weline\Shipping\Model\PostalPlace\LocalDescription as PostalPlaceLocalDescription;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\Region\LocalDescription as RegionLocalDescription;
use Weline\Shipping\Model\Street;
use Weline\Shipping\Model\Street\LocalDescription as StreetLocalDescription;

final class RegionStreetLocalModelContractTest extends TestCase
{
    public function testAddressNameLocalDescriptionModels(): void
    {
        self::assertTrue(is_subclass_of(RegionLocalDescription::class, LocalModel::class));
        self::assertTrue(is_subclass_of(StreetLocalDescription::class, LocalModel::class));
        self::assertTrue(is_subclass_of(PostalPlaceLocalDescription::class, LocalModel::class));

        self::assertSame(Region::schema_fields_ID, RegionLocalDescription::schema_fields_ID);
        self::assertSame(Region::schema_fields_REGION_NAME, RegionLocalDescription::schema_fields_REGION_NAME);
        self::assertSame('w_shipping_region_local', RegionLocalDescription::schema_table);

        self::assertSame(Street::schema_fields_ID, StreetLocalDescription::schema_fields_ID);
        self::assertSame(Street::schema_fields_STREET_NAME, StreetLocalDescription::schema_fields_STREET_NAME);
        self::assertSame('w_shipping_street_local', StreetLocalDescription::schema_table);

        self::assertSame(PostalPlace::schema_fields_ID, PostalPlaceLocalDescription::schema_fields_ID);
        self::assertSame(PostalPlace::schema_fields_PLACE_NAME, PostalPlaceLocalDescription::schema_fields_PLACE_NAME);
        self::assertSame('w_shipping_postal_place_local', PostalPlaceLocalDescription::schema_table);
    }

    public function testLocalModelTranslationCatalogDiscoversShippingLocals(): void
    {
        require_once dirname(__DIR__) . '/bootstrap.php';

        $catalog = new LocalModelTranslationCatalog();
        $byClass = [];
        foreach ($catalog->descriptors() as $descriptor) {
            $byClass[(string)$descriptor['local_model']] = $descriptor;
        }

        self::assertArrayHasKey(RegionLocalDescription::class, $byClass);
        self::assertContains(
            Region::schema_fields_REGION_NAME,
            $byClass[RegionLocalDescription::class]['fields'],
        );
        self::assertSame(Region::class, $byClass[RegionLocalDescription::class]['parent_model']);

        self::assertArrayHasKey(StreetLocalDescription::class, $byClass);
        self::assertContains(
            Street::schema_fields_STREET_NAME,
            $byClass[StreetLocalDescription::class]['fields'],
        );
        self::assertSame(Street::class, $byClass[StreetLocalDescription::class]['parent_model']);

        self::assertArrayHasKey(PostalPlaceLocalDescription::class, $byClass);
        self::assertContains(
            PostalPlace::schema_fields_PLACE_NAME,
            $byClass[PostalPlaceLocalDescription::class]['fields'],
        );
        self::assertSame(PostalPlace::class, $byClass[PostalPlaceLocalDescription::class]['parent_model']);
    }
}
