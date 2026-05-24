<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Data;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Currency\Data\CurrencyData;
use Weline\Currency\Model\Currency;
use Weline\Currency\Model\Currency\LocalDescription;
use Weline\Currency\Service\CurrencyLocalDescriptionService;

class CurrencyDataLocalDescriptionTest extends TestCase
{
    public function testLocalDescriptionUsesDedicatedPrimaryKey(): void
    {
        self::assertSame(
            LocalDescription::schema_fields_LOCAL_DESCRIPTION_ID,
            LocalDescription::schema_primary_key
        );
        self::assertNotSame(
            Currency::schema_fields_ID,
            LocalDescription::schema_primary_key
        );
    }

    public function testLocalDescriptionNameOverridesBaseName(): void
    {
        $method = new ReflectionMethod(CurrencyData::class, 'applyLocalDescription');
        $currency = $method->invoke(null, [
            Currency::schema_fields_CODE => 'USD',
            Currency::schema_fields_NAME => 'USD',
            'local_name' => 'US Dollar',
        ]);

        self::assertSame('US Dollar', $currency[Currency::schema_fields_NAME]);
    }

    public function testBackendLocalNameNormalizationIgnoresInvalidLocaleCodes(): void
    {
        $service = (new \ReflectionClass(CurrencyLocalDescriptionService::class))
            ->newInstanceWithoutConstructor();

        self::assertSame(
            [
                'en_US' => 'US Dollar',
                'zh_Hans_CN' => '',
            ],
            $service->normalizeLocalNames([
                'en_US' => ' US Dollar ',
                'zh_Hans_CN' => ' ',
                '../bad' => 'Bad',
            ])
        );
    }
}
