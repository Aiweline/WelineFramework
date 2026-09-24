<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache\Namespace;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Server\Service\MemoryStateFacade;

/** WS3-C + call-site: NamespaceVersion hash IN prefetch; HotCache stays on protocol MGET. */
final class NamespaceVersionPrefetchAndHotCacheMgetContractTest extends TestCase
{
    public function testPrefetchProcessVectorUsesHashIn(): void
    {
        self::assertTrue(method_exists(NamespaceGenerationRepository::class, 'prefetchProcessVector'));
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Cache/Namespace/NamespaceGenerationRepository.php',
        );
        self::assertStringContainsString('function prefetchProcessVector', $src);
        self::assertStringContainsString('readStoredRows($hashes)', $src);
        self::assertStringContainsString('replaceProcessSnapshot', $src);
        self::assertStringContainsString(
            "schema_fields_HASH, array_keys(\$expectedByHash), 'IN'",
            $src,
        );
    }

    public function testStorefrontHotCacheStillUsesProtocolMgetPath(): void
    {
        self::assertTrue(method_exists(StorefrontScopeHotCache::class, 'prefetchPolicy'));
        $hotSrc = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Cache/Service/StorefrontScopeHotCache.php',
        );
        self::assertStringContainsString('readSharedMultiple', $hotSrc);
        self::assertStringContainsString('storefront.cache.shared_read_batch', $hotSrc);
        self::assertTrue(method_exists(MemoryStateFacade::class, 'getCacheMultiple'));
        $facadeSrc = (string)file_get_contents(
            dirname(__DIR__, 5) . '/Server/Service/MemoryStateFacade.php',
        );
        self::assertStringContainsString("cache_mget", $facadeSrc);
        self::assertStringContainsString('function getCacheMultiple', $facadeSrc);
    }
}
