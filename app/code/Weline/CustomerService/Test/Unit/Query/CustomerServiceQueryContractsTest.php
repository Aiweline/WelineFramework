<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Service\WidgetTranslationService;
use Weline\Framework\Binary\Limits;
use Weline\Framework\Binary\WelineBinaryCodec;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerServiceQueryContractsPass().
 */
final class CustomerServiceQueryContractsTest extends TestCase
{
    public function testAllCustomerServiceQueryContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'WidgetTranslationsBinaryEncode::testFullWidgetTranslationBagEncodesUnderCurrentMapKeyLimit' => function () {
            $this->assert_WidgetTranslationsBinaryEncode_testFullWidgetTranslationBagEncodesUnderCurrentMapKeyLimit();
        },
            'WidgetTranslationsBinaryEncode::testWidgetKeyCountDocumentsWhyMapLimitWasRaised' => function () {
            $this->assert_WidgetTranslationsBinaryEncode_testWidgetKeyCountDocumentsWhyMapLimitWasRaised();
        },
        ];
    }

private function assert_WidgetTranslationsBinaryEncode_testFullWidgetTranslationBagEncodesUnderCurrentMapKeyLimit(): void
{
    $service = new WidgetTranslationService();
    $translations = $service->getWidgetTranslations();
    self::assertNotEmpty($translations);
    foreach ($translations as $locale => $bag) {
        self::assertIsArray($bag);
        self::assertLessThanOrEqual(Limits::MAP_KEYS, \count($bag), 'Locale ' . $locale . ' chrome bag exceeds WQB1 MAP_KEYS');
    }
    $codec = new WelineBinaryCodec();
    $packet = $codec->encodePacket(['success' => true, 'data' => ['translations' => $translations]]);
    $decoded = $codec->decodePacket($packet);
    self::assertTrue($decoded['success']);
    self::assertSame(\count($translations), \count($decoded['data']['translations'] ?? []));
}

private function assert_WidgetTranslationsBinaryEncode_testWidgetKeyCountDocumentsWhyMapLimitWasRaised(): void
{
    $ref = new \ReflectionClass(WidgetTranslationService::class);
    $keys = $ref->getConstant('WIDGET_KEYS');
    self::assertIsArray($keys);
    // Historical failure mode: 100-key WQB1 map cap < chrome dictionary size.
    self::assertGreaterThan(100, \count($keys));
    self::assertLessThanOrEqual(Limits::MAP_KEYS, \count($keys));
}
}
