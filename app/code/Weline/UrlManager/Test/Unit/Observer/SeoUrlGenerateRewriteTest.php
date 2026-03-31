<?php

declare(strict_types=1);

namespace Weline\UrlManager\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\Event;
use Weline\UrlManager\Model\UrlRewrite;
use Weline\UrlManager\Observer\SeoUrlGenerateRewrite;

class SeoUrlGenerateRewriteTest extends TestCase
{
    public function testFindRewriteOrdersByLatestRewriteIdDesc(): void
    {
        $urlRewrite = new class extends UrlRewrite {
            /** @var array<int, array<int, mixed>> */
            public array $calls = [];

            public function __construct()
            {
            }

            public function reset(): static
            {
                $this->calls[] = ['reset'];
                return $this;
            }

            public function clearQuery(): static
            {
                $this->calls[] = ['clearQuery'];
                return $this;
            }

            public function where(string $field, mixed $value, string $condition = '='): static
            {
                $this->calls[] = ['where', $field, $value, $condition];
                return $this;
            }

            public function order(string $field, string $order = 'ASC'): static
            {
                $this->calls[] = ['order', $field, strtoupper($order)];
                return $this;
            }

            public function find(string $field = ''): static
            {
                $this->calls[] = ['find', $field];
                return $this;
            }

            public function fetch(bool $fetchONE = true): static
            {
                $this->calls[] = ['fetch', $fetchONE];
                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return 99;
            }

            public function getData(string $key = '', $index = null): mixed
            {
                return match ($key) {
                    'rewrite' => 'newest-target',
                    default => null,
                };
            }
        };

        $observer = new SeoUrlGenerateRewrite($urlRewrite);
        $method = new \ReflectionMethod(SeoUrlGenerateRewrite::class, 'findRewrite');
        $method->setAccessible(true);

        $result = $method->invoke($observer, 7, 'sample/path');

        self::assertSame([
            'rewrite' => 'newest-target',
            'matched_uri' => 'sample/path',
        ], $result);

        self::assertContains(
            ['order', UrlRewrite::schema_fields_ID, 'DESC'],
            $urlRewrite->calls
        );
    }

    public function testExecuteSkipsStaticAssetsWithoutTouchingRewriteStorage(): void
    {
        $urlRewrite = new class extends UrlRewrite {
            public function __construct()
            {
            }

            public function __call($name, $arguments)
            {
                throw new \RuntimeException('Static assets should never hit URL rewrite storage.');
            }
        };

        $observer = new SeoUrlGenerateRewrite($urlRewrite);
        $event = new Event(['data' => 'https://127.0.0.1:9982/statics/backend/app.js']);

        $observer->execute($event);

        self::assertSame('https://127.0.0.1:9982/statics/backend/app.js', $event->getData('data'));
    }

    public function testResolveCachedRewriteTreatsNullCacheMissAsFalse(): void
    {
        $urlRewrite = new class extends UrlRewrite {
            public function __construct()
            {
            }
        };

        $observer = new SeoUrlGenerateRewrite($urlRewrite);

        $cache = new class implements CachePoolInterface {
            public function get(string $key): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value, int $ttl = 0): bool
            {
                return true;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return false;
            }

            public function getIdentity(): string
            {
                return 'test';
            }

            public function getTip(): string
            {
                return 'test';
            }

            public function isPermanent(): bool
            {
                return false;
            }

            public function getMultiple(array $keys): array
            {
                return [];
            }

            public function setMultiple(array $values, int $ttl = 0): bool
            {
                return true;
            }

            public function deleteMultiple(array $keys): bool
            {
                return true;
            }

            public function getStats(): array
            {
                return [
                    'identity' => 'test',
                    'hits' => 0,
                    'misses' => 0,
                    'hit_ratio' => 0.0,
                    'permanent' => false,
                ];
            }
        };

        $cacheProperty = new \ReflectionProperty(SeoUrlGenerateRewrite::class, 'cache');
        $cacheProperty->setValue($observer, $cache);

        $method = new \ReflectionMethod(SeoUrlGenerateRewrite::class, 'resolveCachedRewrite');
        $result = $method->invoke($observer, 1, 'theme/frontend/theme-preview/gateway', 'theme/frontend/theme-preview/content');

        self::assertFalse($result);
    }
}
