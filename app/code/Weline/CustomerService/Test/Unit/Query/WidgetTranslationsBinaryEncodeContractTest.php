<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Service\WidgetTranslationService;
use Weline\Framework\Binary\Limits;
use Weline\Framework\Binary\WelineBinaryCodec;

/**
 * Regression: query-bin WQB1 map key cap used to be 100; CustomerService chrome
 * dictionaries exceed that and surfaced as EmergencyPacket "Internal server error."
 */
final class WidgetTranslationsBinaryEncodeContractTest extends TestCase
{
    public function testFullWidgetTranslationBagEncodesUnderCurrentMapKeyLimit(): void
    {
        $service = new WidgetTranslationService();
        $translations = $service->getWidgetTranslations();

        self::assertNotEmpty($translations);
        foreach ($translations as $locale => $bag) {
            self::assertIsArray($bag);
            self::assertLessThanOrEqual(
                Limits::MAP_KEYS,
                \count($bag),
                'Locale ' . $locale . ' chrome bag exceeds WQB1 MAP_KEYS'
            );
        }

        $codec = new WelineBinaryCodec();
        $packet = $codec->encodePacket([
            'success' => true,
            'data' => [
                'translations' => $translations,
            ],
        ]);
        $decoded = $codec->decodePacket($packet);

        self::assertTrue($decoded['success']);
        self::assertSame(
            \count($translations),
            \count($decoded['data']['translations'] ?? [])
        );
    }

    public function testWidgetKeyCountDocumentsWhyMapLimitWasRaised(): void
    {
        $ref = new \ReflectionClass(WidgetTranslationService::class);
        $keys = $ref->getConstant('WIDGET_KEYS');
        self::assertIsArray($keys);
        // Historical failure mode: 100-key WQB1 map cap < chrome dictionary size.
        self::assertGreaterThan(100, \count($keys));
        self::assertLessThanOrEqual(Limits::MAP_KEYS, \count($keys));
    }
}
