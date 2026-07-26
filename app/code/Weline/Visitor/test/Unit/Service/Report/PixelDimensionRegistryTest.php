<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service\Report;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\Report\PixelDimensionRegistry;

/**
 * D01：DimensionRegistry 单测（不查库）。
 */
final class PixelDimensionRegistryTest extends TestCase
{
    public function testDefaultHourlyDimensionsMatchPlanSection25(): void
    {
        $reg = new PixelDimensionRegistry();

        self::assertSame(
            PixelDimensionRegistry::DEFAULT_HOURLY_DIMENSION_IDS,
            $reg->defaultHourlyIds()
        );
        self::assertSame([
            'traffic_type',
            'channel_code',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'event_name',
            'device_category',
        ], $reg->defaultHourlyIds());

        foreach ($reg->defaultHourlyIds() as $id) {
            $meta = $reg->get($id);
            self::assertNotNull($meta);
            self::assertTrue($meta['in_default_hourly']);
            self::assertSame(PixelDimensionRegistry::CARDINALITY_LOW, $meta['cardinality']);
        }
    }

    public function testHighCardinalityPagePathNotInDefaultHourly(): void
    {
        $reg = new PixelDimensionRegistry();

        self::assertTrue($reg->has('page_path'));
        self::assertTrue($reg->has('landing_page'));
        self::assertContains('page_path', $reg->highCardinalityIds());
        self::assertContains('landing_page', $reg->highCardinalityIds());
        self::assertNotContains('page_path', $reg->defaultHourlyIds());
        self::assertFalse($reg->get('page_path')['in_default_hourly']);
    }

    public function testRegisterAndAssertKnown(): void
    {
        $reg = new PixelDimensionRegistry(false);
        $reg->register('custom_dim', [
            'label' => 'Custom',
            'source' => PixelDimensionRegistry::SOURCE_FLAT,
            'cardinality' => PixelDimensionRegistry::CARDINALITY_LOW,
        ]);
        self::assertTrue($reg->has('custom_dim'));
        $reg->assertKnown(['custom_dim']);

        $this->expectException(\InvalidArgumentException::class);
        $reg->assertKnown(['no_such_dim']);
    }

    public function testExtractValuesReadsEventAndFlatFields(): void
    {
        $reg = new PixelDimensionRegistry();
        $values = $reg->extractValues([
            'event' => 'page_view',
            'channel_code' => 'summer',
            'traffic_type' => 'paid',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'july',
            'device_category' => 'mobile',
            'page_path' => '/promo',
        ]);

        self::assertSame('paid', $values['traffic_type']);
        self::assertSame('summer', $values['channel_code']);
        self::assertSame('google', $values['utm_source']);
        self::assertSame('cpc', $values['utm_medium']);
        self::assertSame('july', $values['utm_campaign']);
        self::assertSame('page_view', $values['event_name']);
        self::assertSame('mobile', $values['device_category']);
        self::assertSame(
            $reg->defaultHourlyIds(),
            array_keys($values)
        );
    }

    public function testExtractValuesDefaultsMissingToEmptyString(): void
    {
        $reg = new PixelDimensionRegistry();
        $values = $reg->extractValues(['event' => 'click']);

        self::assertSame('click', $values['event_name']);
        self::assertSame('', $values['channel_code']);
        self::assertSame('', $values['traffic_type']);
        self::assertSame('', $values['device_category']);
    }

    public function testDimHashStableAndSensitive(): void
    {
        $reg = new PixelDimensionRegistry();
        $row = [
            'event' => 'page_view',
            'channel_code' => 'summer',
            'traffic_type' => 'paid',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'july',
            'device_category' => 'mobile',
        ];

        $h1 = $reg->computeDimHash($row);
        $h2 = $reg->computeDimHash($row);
        self::assertSame($h1, $h2);
        self::assertSame(40, \strlen($h1));

        $changed = $row;
        $changed['channel_code'] = 'winter';
        self::assertNotSame($h1, $reg->computeDimHash($changed));

        $emptyA = $reg->computeDimHash([]);
        $emptyB = $reg->computeDimHash([
            'traffic_type' => '',
            'channel_code' => '',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'event' => '',
            'device_category' => '',
        ]);
        self::assertSame($emptyA, $emptyB);
    }

    public function testSerializeDimValuesIsOrderedJson(): void
    {
        $reg = new PixelDimensionRegistry();
        $values = $reg->extractValues([
            'event' => 'x',
            'channel_code' => 'c',
        ]);
        $json = $reg->serializeDimValues($values);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame($reg->defaultHourlyIds(), array_keys($decoded));
    }

    public function testRejectsInvalidCardinalityOnRegister(): void
    {
        $reg = new PixelDimensionRegistry(false);
        $this->expectException(\InvalidArgumentException::class);
        $reg->register('bad', ['cardinality' => 'huge']);
    }
}
