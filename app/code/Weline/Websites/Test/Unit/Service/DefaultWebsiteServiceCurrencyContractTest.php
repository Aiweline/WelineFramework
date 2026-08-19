<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\DefaultWebsiteService;

final class DefaultWebsiteServiceCurrencyContractTest extends TestCase
{
    public function testDefaultWebsiteUsesConfiguredFrameworkCurrency(): void
    {
        $service = (new \ReflectionClass(DefaultWebsiteService::class))->newInstanceWithoutConstructor();
        $expected = strtoupper(trim((string)Env::system('currency', 'CNY'))) ?: 'CNY';

        self::assertSame(
            $expected,
            $service->defaultRow()[Website::schema_fields_DEFAULT_CURRENCY] ?? null,
        );
    }

    public function testDefaultCurrencyAssociationIsNotHardCodedToCny(): void
    {
        $reflection = new \ReflectionMethod(DefaultWebsiteService::class, 'ensureDefaultCurrencyAndLanguage');
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);
        $source = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        self::assertStringContainsString('$defaultCurrency = $this->defaultCurrency();', $source);
        self::assertStringNotContainsString("in_array('CNY'", $source);
        self::assertStringNotContainsString("schema_fields_CURRENCY_CODE => 'CNY'", $source);
    }
}
