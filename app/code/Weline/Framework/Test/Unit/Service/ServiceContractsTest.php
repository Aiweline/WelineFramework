<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\BinQueryCachePolicy;
use Weline\Framework\Service\Runtime\ResumableTaskStore;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllServiceContractsPass().
 */
final class ServiceContractsTest extends TestCase
{
    public function testAllServiceContractsPass(): void
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
            'BinQueryCachePolicy::testPublicExternalReadWithCdnIsCacheableAndEmitsSharedHeaders' => function () {
            $this->assert_BinQueryCachePolicy_testPublicExternalReadWithCdnIsCacheableAndEmitsSharedHeaders();
        },
            'BinQueryCachePolicy::testCacheTagsEmitCacheTagAndSurrogateKeyHeaders' => function () {
            $this->assert_BinQueryCachePolicy_testCacheTagsEmitCacheTagAndSurrogateKeyHeaders();
        },
            'BinQueryCachePolicy::testMissingExternalOrCdnFalseIsNotCacheable' => function () {
            $this->assert_BinQueryCachePolicy_testMissingExternalOrCdnFalseIsNotCacheable();
        },
            'BinQueryCachePolicy::testParseTtlSecondsSupportsUnitSuffixes' => function () {
            $this->assert_BinQueryCachePolicy_testParseTtlSecondsSupportsUnitSuffixes();
        },
            'BinQueryCachePolicy::testExplicitParamsOverrideScopeVaryBindings' => function () {
            $this->assert_BinQueryCachePolicy_testExplicitParamsOverrideScopeVaryBindings();
        },
            'BinQueryCachePolicy::testEmptyParamsMarkerMatchesJsObjectEncoding' => function () {
            $this->assert_BinQueryCachePolicy_testEmptyParamsMarkerMatchesJsObjectEncoding();
        },
            'QueryParamMapEmptyObject::testBinAndFrontendGatewaysAcceptEmptyArrayAsMap' => function () {
            $this->assert_QueryParamMapEmptyObject_testBinAndFrontendGatewaysAcceptEmptyArrayAsMap();
        },
            'ResumableTaskStoreWatchdogBatch::testExposesLeaseAndTaskIdBatchApis' => function () {
            $this->assert_ResumableTaskStoreWatchdogBatch_testExposesLeaseAndTaskIdBatchApis();
        },
        ];
    }

private function assert_BinQueryCachePolicy_testPublicExternalReadWithCdnIsCacheableAndEmitsSharedHeaders(): void
{
    $policy = new BinQueryCachePolicy();
    $operation = ['name' => 'getCountryFlags', 'external' => true, 'mode' => 'read', 'cache' => ['cdn' => true, 'ttl' => '7d', 'visibility' => 'public', 'key_params' => ['country_codes', 'ratio'], 'vary' => ['area']]];
    self::assertTrue($policy->isCacheableOperation($operation));
    $params = ['country_codes' => ['CN', 'US'], 'ratio' => '4x3'];
    $marker = $policy->buildMarker('frontend', 'i18n', 'getCountryFlags', $params, $operation);
    self::assertStringStartsWith('wq1.frontend.i18n.getCountryFlags.', $marker);
    $headers = $policy->cacheHeaders($operation, $marker);
    self::assertSame('public, max-age=604800, s-maxage=604800', $headers['Cache-Control']);
    self::assertSame('cdn', $headers['X-Weline-BinQuery-Cache']);
    self::assertSame($marker, $headers['X-Weline-BinQuery-Cache-Marker']);
    self::assertArrayNotHasKey('Cache-Tag', $headers);
}

private function assert_BinQueryCachePolicy_testCacheTagsEmitCacheTagAndSurrogateKeyHeaders(): void
{
    $policy = new BinQueryCachePolicy();
    $operation = ['name' => 'getLanguageSwitcherCatalog', 'external' => true, 'mode' => 'read', 'cache' => ['cdn' => true, 'ttl' => '1h', 'visibility' => 'public', 'key_params' => ['website_id'], 'vary' => ['area', 'locale'], 'tags' => ['binquery-i18n-getLanguageSwitcherCatalog']]];
    $marker = $policy->buildMarker('frontend', 'i18n', 'getLanguageSwitcherCatalog', ['website_id' => 1], $operation);
    $headers = $policy->cacheHeaders($operation, $marker);
    self::assertSame('binquery-i18n-getLanguageSwitcherCatalog', $headers['Cache-Tag'] ?? null);
    self::assertSame('binquery-i18n-getLanguageSwitcherCatalog', $headers['Surrogate-Key'] ?? null);
}

private function assert_BinQueryCachePolicy_testMissingExternalOrCdnFalseIsNotCacheable(): void
{
    $policy = new BinQueryCachePolicy();
    self::assertFalse($policy->isCacheableOperation(['external' => false, 'mode' => 'read', 'cache' => ['cdn' => true, 'ttl' => '5m', 'visibility' => 'public']]));
    self::assertFalse($policy->isCacheableOperation(['external' => true, 'mode' => 'read', 'cache' => ['cdn' => false, 'ttl' => '5m', 'visibility' => 'public']]));
    self::assertFalse($policy->isCacheableOperation(['external' => true, 'mode' => 'write', 'cache' => ['cdn' => true, 'ttl' => '5m', 'visibility' => 'public']]));
    self::assertFalse($policy->isCacheableOperation(['external' => true, 'mode' => 'read', 'cache' => ['cdn' => true, 'ttl' => '0s', 'visibility' => 'public']]));
}

private function assert_BinQueryCachePolicy_testParseTtlSecondsSupportsUnitSuffixes(): void
{
    $policy = new BinQueryCachePolicy();
    self::assertSame(300, $policy->parseTtlSeconds('5m'));
    self::assertSame(1800, $policy->parseTtlSeconds('30m'));
    self::assertSame(3600, $policy->parseTtlSeconds('1h'));
    self::assertSame(604800, $policy->parseTtlSeconds('7d'));
    self::assertSame(0, $policy->parseTtlSeconds('0s'));
}

private function assert_BinQueryCachePolicy_testExplicitParamsOverrideScopeVaryBindings(): void
{
    $policy = new BinQueryCachePolicy();
    $operation = ['external' => true, 'mode' => 'read', 'cache' => ['cdn' => true, 'ttl' => '5m', 'visibility' => 'public', 'key_params' => ['limit'], 'vary' => ['area', 'website_id', 'channel_id']]];
    $a = $policy->buildMarker('frontend', 'search', 'hotWords', ['limit' => 8, 'website_id' => 1, 'channel_id' => 2], $operation);
    $b = $policy->buildMarker('frontend', 'search', 'hotWords', ['limit' => 8, 'website_id' => 9, 'channel_id' => 2], $operation);
    self::assertNotSame($a, $b);
    self::assertStringStartsWith('wq1.frontend.search.hotWords.', $a);
}

private function assert_BinQueryCachePolicy_testEmptyParamsMarkerMatchesJsObjectEncoding(): void
{
    $policy = new BinQueryCachePolicy();
    $operation = ['external' => true, 'mode' => 'read', 'cache' => ['cdn' => true, 'ttl' => '5m', 'visibility' => 'public', 'key_params' => ['limit'], 'vary' => ['area']]];
    $marker = $policy->buildMarker('frontend', 'search', 'hotWords', [], $operation);
    $jsJson = '{"area":"frontend","provider":"search","operation":"hotWords","params":{},"vary":{"area":"frontend"}}';
    $expected = 'wq1.frontend.search.hotWords.' . \substr(\hash('sha256', $jsJson), 0, 24);
    self::assertSame($expected, $marker);
}

private function assert_QueryParamMapEmptyObject_testBinAndFrontendGatewaysAcceptEmptyArrayAsMap(): void
{
    $needle = "(\$value === [] || !\\array_is_list(\$value))";
    $queryDir = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Service/Query';
    $bin = (string) \file_get_contents($queryDir . '/BinQueryGateway.php');
    $frontend = (string) \file_get_contents($queryDir . '/FrontendQueryGateway.php');
    self::assertStringContainsString($needle, $bin);
    self::assertStringContainsString($needle, $frontend);
    self::assertTrue(\array_is_list([]));
    self::assertTrue(\array_is_list(\json_decode('{}', true)));
}

private function assert_ResumableTaskStoreWatchdogBatch_testExposesLeaseAndTaskIdBatchApis(): void
{
    self::assertTrue(method_exists(ResumableTaskStore::class, 'hasActiveLeasesForTaskIds'));
    self::assertTrue(method_exists(ResumableTaskStore::class, 'findTasksByIds'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Service/Runtime/ResumableTaskStore.php');
    self::assertStringContainsString("schema_fields_TASK_ID, array_keys(\$normalized), 'IN'", $src);
    self::assertStringContainsString('function hasActiveLeasesForTaskIds', $src);
    self::assertStringContainsString('function findTasksByIds', $src);
    self::assertStringContainsString('hasActiveLeasesForTaskIds([$taskId], $now)', $src);
}
}
