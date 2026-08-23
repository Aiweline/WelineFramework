<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Theme\Service\PreviewTokenService;

final class PreviewTokenServiceTest extends TestCase
{
    public function testGenerateTokenFailsWhenCapabilityCannotReachSharedCache(): void
    {
        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::once())
            ->method('set')
            ->with(
                self::callback(static fn (string $key): bool => str_starts_with($key, 'preview_token_pv_')),
                self::callback(static fn (array $payload): bool => ($payload['theme_id'] ?? null) === 1),
                3600,
            )
            ->willReturn(false);

        $reflection = new ReflectionClass(PreviewTokenService::class);
        /** @var PreviewTokenService $service */
        $service = $reflection->newInstanceWithoutConstructor();
        $cacheProperty = new ReflectionProperty(PreviewTokenService::class, 'cache');
        $cacheProperty->setValue($service, $cache);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Theme 预览 Token 无法写入共享缓存。');

        $service->generateToken(1, 'cms_page');
    }
}
