<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Service\Query\BinQueryCachePolicy;
use Weline\Framework\Service\Query\BinQueryDescriptorAttributeResolver;
use Weline\I18n\Extends\Module\Weline_Framework\Query\I18nQueryProvider;

if (!\defined('BP')) {
    require \dirname(__DIR__, 6) . '/bootstrap.php';
}

final class I18nQueryProviderCacheDescriptorContractTest extends TestCase
{
    public function testPublicLocaleCatalogOpsAreCdnCacheable(): void
    {
        $provider = (new ReflectionClass(I18nQueryProvider::class))->newInstanceWithoutConstructor();
        $merged = (new BinQueryDescriptorAttributeResolver())->merge($provider, $provider->getDescriptor());
        $byName = [];
        foreach (($merged['operations'] ?? []) as $operation) {
            if (\is_array($operation) && isset($operation['name'])) {
                $byName[(string)$operation['name']] = $operation;
            }
        }

        $policy = new BinQueryCachePolicy();
        foreach (['getCountryFlags' => '7d', 'getInstalledLocales' => '1h', 'getLocaleByCode' => '1h', 'getLocaleName' => '1h'] as $name => $ttl) {
            self::assertArrayHasKey($name, $byName, $name);
            self::assertTrue(($byName[$name]['external'] ?? false) === true, $name);
            self::assertTrue($policy->isCacheableOperation($byName[$name]), $name);
            self::assertSame($ttl, $byName[$name]['cache']['ttl'] ?? null, $name);
        }

        self::assertFalse($policy->isCacheableOperation($byName['getBackendCountryFlags'] ?? [
            'external' => false,
            'mode' => 'read',
        ]));
    }
}
