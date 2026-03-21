<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Helper;

use GuoLaiRen\PageBuilder\Helper\PageBuilderUrlCacheInvalidator;
use GuoLaiRen\PageBuilder\Model\Page;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\UrlManager\Model\UrlRewrite;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

final class PageBuilderUrlCacheInvalidatorTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::removeInstance(CacheManager::class);
        ObjectManager::removeInstance(Page::class);
        ObjectManager::removeInstance(UrlRewrite::class);
        ObjectManager::removeInstance(Website::class);
        ObjectManager::removeInstance(WebsiteDomain::class);
        ObjectManager::removeInstance(\Weline\Server\Service\Control\IpcControlGateway::class);
        ObjectManager::removeInstance(\Weline\Server\Service\Control\BroadcastControlDispatchService::class);

        parent::tearDown();
    }

    public function testInvalidateForPageIdDeletesRouterFpcAndRewriteKeysAndNotifiesWls(): void
    {
        $routerPool = new FakeCachePool('router');
        $rewritePool = new FakeCachePool('url_rewrite');

        $cacheManager = new class($routerPool, $rewritePool) extends CacheManager {
            public function __construct(
                private object $routerPool,
                private object $rewritePool
            ) {
            }

            public function pool(string $identity): CachePoolInterface
            {
                return match ($identity) {
                    'router' => $this->routerPool,
                    'url_rewrite' => $this->rewritePool,
                    default => throw new \InvalidArgumentException('Unexpected pool: ' . $identity),
                };
            }
        };

        $pageModel = new class extends Page {
            private int $id = 0;
            private array $data = [];

            public function __construct()
            {
            }

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function load(int|string $field_or_pk_value, $value = null): \Weline\Framework\Database\AbstractModel
            {
                $this->id = (int) $field_or_pk_value;
                $this->data = [
                    Page::schema_fields_WEBSITE_ID => 0,
                    Page::schema_fields_HANDLE => 'about',
                    Page::schema_fields_TYPE => 'page',
                    Page::schema_fields_DEFAULT_LOCALE => 'en_US',
                    Page::schema_fields_LOCALES => json_encode(['fr_FR'], JSON_UNESCAPED_UNICODE),
                ];

                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return $this->id ?: $default;
            }

            public function getData(string $key = '', $index = null): mixed
            {
                return $this->data[$key] ?? null;
            }
        };

        $urlRewrite = new class extends UrlRewrite {
            public function __construct()
            {
            }

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                return $this;
            }

            public function pagination(int $page = 0, int $pageSize = 0, array $params = [], int $max_limit = 1000, int $total = 0): \Weline\Framework\Database\AbstractModel|static
            {
                return $this;
            }

            public function select(string $fields = ''): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                return [
                    [UrlRewrite::schema_fields_REWRITE => 'about'],
                ];
            }
        };

        $website = new class extends Website {
            public function __construct()
            {
            }

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function load(int|string $field_or_pk_value, $value = null): \Weline\Framework\Database\AbstractModel
            {
                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return $default;
            }
        };

        $websiteDomain = new class extends WebsiteDomain {
            public function __construct()
            {
            }

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                return $this;
            }

            public function pagination(int $page = 0, int $pageSize = 0, array $params = [], int $max_limit = 1000, int $total = 0): \Weline\Framework\Database\AbstractModel|static
            {
                return $this;
            }

            public function select(string $fields = ''): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                return [];
            }
        };

        $ipcGateway = new class {
            public int $calls = 0;
            public ?array $lastPayload = null;

            public function command(
                ?string $instanceName,
                string $action,
                string $message = '',
                array $payload = [],
                float $timeout = 3.0
            ): array {
                $this->calls++;
                $this->lastPayload = [
                    'instance' => $instanceName,
                    'action' => $action,
                    'payload' => $payload,
                ];

                return ['success' => true];
            }
        };

        ObjectManager::setInstance(CacheManager::class, $cacheManager);
        ObjectManager::setInstance(Page::class, $pageModel);
        ObjectManager::setInstance(UrlRewrite::class, $urlRewrite);
        ObjectManager::setInstance(Website::class, $website);
        ObjectManager::setInstance(WebsiteDomain::class, $websiteDomain);
        ObjectManager::setInstance(\Weline\Server\Service\Control\IpcControlGateway::class, $ipcGateway);

        $result = PageBuilderUrlCacheInvalidator::invalidateForPageId(5);

        $this->assertTrue($result['ok']);
        $this->assertSame(5, $result['page_id']);
        $this->assertTrue($result['wls_notified']);
        $this->assertSame(['website_0_about'], $rewritePool->deleted);
        $this->assertGreaterThan(0, \count($routerPool->deleted));
        $this->assertContains(
            KeyBuilder::build('router', 'unified:http://127.0.0.1/about:GET'),
            $routerPool->deleted
        );
        $this->assertContains(
            KeyBuilder::build('router', 'fpc:http://127.0.0.1/about:en_US'),
            $routerPool->deleted
        );
        $this->assertSame(1, $ipcGateway->calls);
        $this->assertSame('pagebuilder_page_invalidate', $ipcGateway->lastPayload['action']);
    }

    public function testInvalidateRouterAndRewriteClearsPoolsAndBroadcastsWlsCacheClear(): void
    {
        $routerPool = new FakeCachePool('router');
        $rewritePool = new FakeCachePool('url_rewrite');

        $cacheManager = new class($routerPool, $rewritePool) extends CacheManager {
            public function __construct(
                private object $routerPool,
                private object $rewritePool
            ) {
            }

            public function pool(string $identity): CachePoolInterface
            {
                return match ($identity) {
                    'router' => $this->routerPool,
                    'url_rewrite' => $this->rewritePool,
                    default => throw new \InvalidArgumentException('Unexpected pool: ' . $identity),
                };
            }
        };

        $dispatchService = new class extends \Weline\Server\Service\Control\BroadcastControlDispatchService {
            public int $cacheCalls = 0;

            public function __construct()
            {
            }

            public function cacheClear(?string $instanceName = null, float $timeout = 3.0): array
            {
                $this->cacheCalls++;

                return ['success' => true];
            }
        };

        ObjectManager::setInstance(CacheManager::class, $cacheManager);
        ObjectManager::setInstance(\Weline\Server\Service\Control\BroadcastControlDispatchService::class, $dispatchService);

        PageBuilderUrlCacheInvalidator::invalidateRouterAndRewrite();

        $this->assertSame(1, $routerPool->clearCalls);
        $this->assertSame(1, $rewritePool->clearCalls);
        $this->assertSame(1, $dispatchService->cacheCalls);
    }
}

final class FakeCachePool implements CachePoolInterface
{
    /** @var list<string> */
    public array $deleted = [];
    public int $clearCalls = 0;

    public function __construct(
        private readonly string $identity
    ) {
    }

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
        $this->deleted[] = $key;
        return true;
    }

    public function clear(): bool
    {
        $this->clearCalls++;
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    public function getTip(): string
    {
        return $this->identity;
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
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    public function getStats(): array
    {
        return [
            'identity' => $this->identity,
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }
}
