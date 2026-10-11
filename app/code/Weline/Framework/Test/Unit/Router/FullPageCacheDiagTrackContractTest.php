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
 * - Product FPC path still excludes first-segment "search" (do not "fix" by
 *   enabling search FPC bodies). Tip bags warm via Clear warmUp('search') only.
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

    public function testProductFpcStillBlocksSearchPathSegment(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Router/FullPageCacheCoordinator.php'
        );
        // Keep product rule: search results never share one FPC body.
        self::assertMatchesRegularExpression(
            "/\\/\\/ Search results vary by q\\/type\\/category_id; never share one FPC body\\.\\s*'search',/",
            $source,
        );
        self::assertStringContainsString("'search',", $source);
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
