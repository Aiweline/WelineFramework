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
    public function testNestedLayoutIdentitySurvivesTokenGenerationAndValidation(): void
    {
        $payloads = [];
        $cache = $this->createMock(CachePoolInterface::class);
        $cache->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$payloads): bool { $payloads[$key] = $value; return true; });
        $cache->method('get')->willReturnCallback(static function (string $key) use (&$payloads): mixed { return $payloads[$key] ?? false; });
        $fallback = $this->createMock(CachePoolInterface::class);
        $fallback->method('set')->willReturn(true);
        $service = $this->serviceWithCaches($cache, $fallback);
        foreach (['account/login', 'checkout/success', 'module/nested-page', 'cms_page'] as $layout) {
            $token = $service->generateToken(1, $layout);
            self::assertSame($layout, $service->validateToken($token)['page_type']);
        }
    }

    public function testTokenRejectsUnsafeLayoutPathSegmentsBeforeCacheWrite(): void
    {
        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::never())->method('set');
        $service = $this->serviceWithCaches($cache, $this->createMock(CachePoolInterface::class));
        foreach (['../account/login', 'account/../login', 'account//login', '/account/login', 'account/login/', 'account%2Flogin', 'account\\login'] as $layout) {
            try { $service->generateToken(1, $layout); self::fail('Accepted unsafe layout: ' . $layout); }
            catch (\InvalidArgumentException $error) { self::assertNotSame('', $error->getMessage()); }
        }
    }

    public function testGenerateTokenFallsBackToDurableFileWhenPrimaryCacheRejectsWrite(): void
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

        $fallback = $this->createMock(CachePoolInterface::class);
        $fallback->expects(self::once())
            ->method('set')
            ->with(
                self::callback(static fn (string $key): bool => str_starts_with($key, 'preview_token_pv_')),
                self::callback(static fn (array $payload): bool => ($payload['theme_id'] ?? null) === 1),
                3600,
            )
            ->willReturn(true);

        $service = $this->serviceWithCaches($cache, $fallback);
        $token = $service->generateToken(1, 'cms_page');

        self::assertMatchesRegularExpression('/^pv_[A-Za-z0-9_-]{43}$/', $token);
    }

    public function testGenerateTokenFailsWhenPrimaryAndFallbackCannotWrite(): void
    {
        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::once())
            ->method('set')
            ->willReturn(false);

        $fallback = $this->createMock(CachePoolInterface::class);
        $fallback->expects(self::once())
            ->method('set')
            ->willReturn(false);

        $service = $this->serviceWithCaches($cache, $fallback);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Theme 预览 Token 无法写入共享缓存。');

        $service->generateToken(1, 'cms_page');
    }

    public function testValidateTokenReadsFromDurableFallbackWhenPrimaryMisses(): void
    {
        $token = 'pv_' . str_repeat('A', 43);
        $cacheKey = 'preview_token_' . $token;
        $now = time();
        $payload = [
            'token' => $token,
            'theme_id' => 1,
            'page_type' => 'cms_page',
            'version_id' => null,
            'context' => [],
            'created_at' => $now,
            'expires_at' => $now + 3600,
        ];

        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::once())
            ->method('get')
            ->with($cacheKey)
            ->willReturn(false);
        $cache->expects(self::once())
            ->method('set')
            ->willReturn(true);

        $fallback = $this->createMock(CachePoolInterface::class);
        $fallback->expects(self::once())
            ->method('get')
            ->with($cacheKey)
            ->willReturn($payload);
        $fallback->expects(self::once())
            ->method('set')
            ->willReturn(true);

        $service = $this->serviceWithCaches($cache, $fallback);
        $validated = $service->validateToken($token);

        self::assertIsArray($validated);
        self::assertSame(1, $validated['theme_id']);
        self::assertSame($token, $validated['token']);
    }

    /**
     * @param CachePoolInterface $cache
     * @param CachePoolInterface $fallback
     */
    private function serviceWithCaches(CachePoolInterface $cache, CachePoolInterface $fallback): PreviewTokenService
    {
        $reflection = new ReflectionClass(PreviewTokenService::class);
        /** @var PreviewTokenService $service */
        $service = $reflection->newInstanceWithoutConstructor();

        $cacheProperty = new ReflectionProperty(PreviewTokenService::class, 'cache');
        $cacheProperty->setValue($service, $cache);

        $fallbackProperty = new ReflectionProperty(PreviewTokenService::class, 'fileFallback');
        $fallbackProperty->setValue($service, $fallback);

        return $service;
    }
}
