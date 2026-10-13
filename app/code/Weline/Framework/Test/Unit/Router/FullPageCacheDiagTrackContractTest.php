<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\FullPageCacheCoordinator;

/**
 * P-fpc-ledger-diag (wls-resident-pitfalls-fix): dual-track must stay product-honest.
 *
 * - Diagnostic storm keys (_wb_nc / wls_tpl_perf) MUST NOT enter IGNORABLE —
 *   they remain build-through / cold-bypass; never promote storm MISS→HIT KPI.
 * - Search Extra is path-only shell (not hard-excluded); tip bags warm via
 *   Clear warmUp('search') only — never promote storm MISS via tip warm.
 */
final class FullPageCacheDiagTrackContractTest extends TestCase
{
    public function testIgnorableQueryParamsDoNotSwallowDiagBypassKeys(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Router/FullPageCacheCoordinator.php'
        );
        self::assertStringContainsString('IGNORABLE_QUERY_PARAM_KEYS', $source);

        $keys = $this->readPrivateConstArray('IGNORABLE_QUERY_PARAM_KEYS');
        self::assertArrayNotHasKey('_wb_nc', $keys);
        self::assertArrayNotHasKey('wls_tpl_perf', $keys);
        self::assertArrayNotHasKey('wb_nc', $keys);

        $prefixes = $this->readPrivateConstList('IGNORABLE_QUERY_PARAM_PREFIXES');
        foreach ($prefixes as $prefix) {
            self::assertFalse(
                str_starts_with('_wb_nc', (string)$prefix) || (string)$prefix === '_wb_',
                'IGNORABLE prefix must not swallow _wb_nc: ' . $prefix,
            );
            self::assertNotSame('wls_', (string)$prefix, 'IGNORABLE must not swallow wls_tpl_perf via wls_');
        }
    }

    public function testProductQueryIsPathOnlyButStormKeysRemainInCacheIdentity(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Router/FullPageCacheCoordinator.php'
        );
        self::assertStringContainsString('isCacheKeyRetainedQueryParam', $source);
        self::assertStringContainsString("'_wb_nc'", $source);
        self::assertStringContainsString("'wls_tpl_perf'", $source);
        self::assertStringContainsString('FpcPierceStamp::isValidCurrentRequest', $source);

        $coordinator = (new \ReflectionClass(FullPageCacheCoordinator::class))->newInstanceWithoutConstructor();
        $filter = new \ReflectionMethod(FullPageCacheCoordinator::class, 'filterQueryStringForCacheKey');
        $filter->setAccessible(true);
        self::assertSame('', $filter->invoke($coordinator, 'size=s&style_type=hong-se-jiu&utm_source=x'));
        // Without pierce stamp, storm keys must not retain a distinct cache identity.
        self::assertSame('', $filter->invoke($coordinator, 'size=s&_wb_nc=1'));
        self::assertSame('', $filter->invoke($coordinator, 'wls_tpl_perf=1&gclid=abc'));
    }

    public function testProductFpcDoesNotHardExcludeSearchPathSegment(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Router/FullPageCacheCoordinator.php'
        );
        // Search Extra path-only shell (ttl=3600); hard-exclude must stay removed.
        self::assertStringNotContainsString(
            "// Search results vary by q/type/category_id; never share one FPC body.\n            'search',",
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            "/blockedPrefixes\\s*=\\s*\\\\array_filter\\(\\[(?:.|\\n)*?'search',/",
            $source,
        );
    }

    public function testCanonicalizePreservesInternalRouterLayoutKeysOutOfCacheKey(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Router/FullPageCacheCoordinator.php'
        );
        self::assertStringContainsString('internalRouterGetParamKeys', $source);
        self::assertStringContainsString("'layout_type'", $source);
        self::assertStringContainsString("'layout_option'", $source);
        self::assertStringContainsString("'theme_page_title'", $source);
        // Must restore after path-only filter, not retain into cache identity.
        self::assertMatchesRegularExpression(
            '/function canonicalizeCurrentRequestForCacheBuild[\s\S]*internalRouterGetParamKeys/',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/function isCacheKeyRetainedQueryParam[\s\S]*layout_type/',
            $source
        );
    }

    /**
     * @return array<string, bool>
     */
    private function readPrivateConstArray(string $name): array
    {
        $ref = new \ReflectionClass(FullPageCacheCoordinator::class);
        $const = $ref->getReflectionConstant($name);
        self::assertNotFalse($const);
        $value = $const->getValue();
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return list<string>
     */
    private function readPrivateConstList(string $name): array
    {
        $ref = new \ReflectionClass(FullPageCacheCoordinator::class);
        $const = $ref->getReflectionConstant($name);
        self::assertNotFalse($const);
        $value = $const->getValue();
        self::assertIsArray($value);

        return array_values(array_map('strval', $value));
    }
}
