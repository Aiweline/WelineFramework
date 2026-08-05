<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Router\Core;

final class ControllerResolutionIgnoresSharedCacheTest extends TestCase
{
    public function testBackendRequestUsesCurrentRouterInsteadOfSharedCache(): void
    {
        $core = new Core();

        $cacheStub = new class implements CachePoolInterface {
            public function get(string $key): mixed
            {
                return str_contains($key, '_controller')
                    ? 'Weline\\CustomerService\\Controller\\Frontend\\Chat'
                    : 'getServiceStatus';
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
                return true;
            }

            public function getIdentity(): string
            {
                return 'router';
            }

            public function getTip(): string
            {
                return 'stub';
            }

            public function isPermanent(): bool
            {
                return false;
            }

            public function getMultiple(array $keys): array
            {
                $values = [];
                foreach ($keys as $key) {
                    $values[$key] = $this->get($key);
                }

                return $values;
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
                    'identity' => 'router',
                    'hits' => 0,
                    'misses' => 0,
                    'hit_ratio' => 0.0,
                    'permanent' => false,
                ];
            }
        };

        $cacheProperty = new ReflectionProperty(Core::class, 'cache');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue($core, $cacheStub);

        $isBackendProperty = new ReflectionProperty(Core::class, 'is_backend');
        $isBackendProperty->setAccessible(true);
        $isBackendProperty->setValue($core, true);

        $router = [
            'class' => [
                'area' => 'BackendController',
                'name' => 'GuoLaiRen\\PageBuilder\\Controller\\Backend\\AiSiteAgent',
                'method' => 'getStreamSse',
                'controller_name' => 'Backend/AiSiteAgent',
                'request_method' => 'GET',
            ],
        ];

        self::assertSame(
            [
                'GuoLaiRen\\PageBuilder\\Controller\\Backend\\AiSiteAgent',
                'getStreamSse',
            ],
            $core->getController($router)
        );
    }

    public function testEmptyMethodFallsBackToIndex(): void
    {
        $core = new Core();

        $isBackendProperty = new ReflectionProperty(Core::class, 'is_backend');
        $isBackendProperty->setAccessible(true);
        $isBackendProperty->setValue($core, true);

        $router = [
            'class' => [
                'name' => 'GuoLaiRen\\PageBuilder\\Controller\\Backend\\AiSiteAgent',
                'method' => '',
            ],
        ];

        self::assertSame(
            [
                'GuoLaiRen\\PageBuilder\\Controller\\Backend\\AiSiteAgent',
                'index',
            ],
            $core->getController($router)
        );
    }
}
