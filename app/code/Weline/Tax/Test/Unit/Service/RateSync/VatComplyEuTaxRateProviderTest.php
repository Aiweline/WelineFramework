<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service\RateSync;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Service\RateSync\VatComplyEuTaxRateProvider;

final class VatComplyEuTaxRateProviderTest extends TestCase
{
    public function testParsesFixtureWithoutHittingNetwork(): void
    {
        $body = (string)file_get_contents(__DIR__ . '/fixtures/vatcomply-vat-rates.json');
        $provider = new VatComplyEuTaxRateProvider(static function () use ($body): array {
            return ['ok' => true, 'status' => 200, 'body' => $body];
        });

        $rows = $provider->fetchCandidates(0);
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['class_code'] . '@' . $row['jurisdiction_key']] = $row['rate_bps'];
        }

        self::assertSame(1900, $byKey['standard@DE|']);
        self::assertSame(700, $byKey['reduced@DE|']);
        self::assertSame(2000, $byKey['standard@FR|']);
        self::assertSame(1000, $byKey['reduced@FR|'], 'conservative max reduced');
        self::assertSame(2400, $byKey['standard@GR|'], 'EL maps to GR');
        self::assertArrayNotHasKey('reduced@GR|', $byKey);
    }

    public function testHttpFailureThrows(): void
    {
        $provider = new VatComplyEuTaxRateProvider(static function (): array {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'transport_failed'];
        });
        $this->expectException(\RuntimeException::class);
        $provider->fetchCandidates(0);
    }
}
