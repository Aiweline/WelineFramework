<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\RememberOptions;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

class CachePoolStorefrontKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testDefaultGetSetIsolatesWebsiteLangCurrency(): void
    {
        $adapter = new CachePoolStorefrontSpyAdapter();
        $pool = new CachePool('unit', $adapter, jitterRatio: 0.0);

        $this->enterScope('shop_a', 'zh_Hans_CN', 'CNY');
        $pool->set('product:1', 'price-a', 600);
        self::assertSame('price-a', $pool->get('product:1'));

        $this->enterScope('shop_b', 'zh_Hans_CN', 'CNY');
        self::assertNull($pool->get('product:1'));

        $this->enterScope('shop_a', 'zh_Hans_CN', 'USD');
        self::assertNull($pool->get('product:1'));

        self::assertCount(1, $adapter->store);
    }

    public function testCustomFullEscapeSharesAcrossWebsites(): void
    {
        $adapter = new CachePoolStorefrontSpyAdapter();
        $pool = new CachePool('unit', $adapter, jitterRatio: 0.0);

        $this->enterScope('shop_a', 'zh_Hans_CN', 'CNY');
        $pool->setCustom('phrase:zh', ['hello' => '你好'], 600);
        $this->enterScope('shop_b', 'zh_Hans_CN', 'CNY');
        self::assertSame(['hello' => '你好'], $pool->getCustom('phrase:zh'));
        self::assertNull($pool->get('phrase:zh'));
    }

    public function testCustomLangOnlyIsolatesLanguage(): void
    {
        $adapter = new CachePoolStorefrontSpyAdapter();
        $pool = new CachePool('unit', $adapter, jitterRatio: 0.0);

        $this->enterScope('shop_a', 'zh_Hans_CN', 'CNY');
        $pool->setCustom('menu', 'zh-menu', 600, lang: true);

        $this->enterScope('shop_b', 'zh_Hans_CN', 'CNY');
        self::assertSame('zh-menu', $pool->getCustom('menu', lang: true));

        $this->enterScope('shop_b', 'en_US', 'CNY');
        self::assertNull($pool->getCustom('menu', lang: true));
    }

    public function testRememberCustomUsesStorageKeyForSingleFlightLock(): void
    {
        $adapter = new CachePoolStorefrontSpyAdapter();
        $pool = new CachePool('unit', $adapter, jitterRatio: 0.0);
        $flight = new CachePoolStorefrontSpySingleFlight();
        $pool->setSingleFlight($flight);

        $value = $pool->rememberCustom(
            'dict',
            600,
            static fn(): string => 'built',
            false,
            false,
            false,
            new RememberOptions(singleFlight: true, hotKeyTrack: false)
        );

        self::assertSame('built', $value);
        self::assertNotSame('dict', $flight->lastAcquireKey);
        self::assertNotEmpty($flight->lastAcquireKey);
        self::assertSame($flight->lastAcquireKey, $flight->lastReleaseKey);
    }

    private function enterScope(string $website, string $lang, string $currency): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('cache-pool-' . $website . '-' . $lang . '-' . $currency);
        Context::current()->set('input.server.WELINE_AREA', 'frontend');
        $identity = ScopeIdentity::channel(
            $website === 'default' ? 0 : 7,
            $website,
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        );
        RequestContext::installScopeIdentity($identity);
        RequestContext::setWelineUserLang($lang);
        RequestContext::setWelineUserCurrency($currency);
        $fingerprint = hash('sha256', 'namespace|' . $website);
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            $identity,
            $lang,
            $currency,
            $fingerprint,
            $fingerprint,
            true,
        ));
    }
}

final class CachePoolStorefrontSpyAdapter implements CacheAdapterInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->store);
    }
}

final class CachePoolStorefrontSpySingleFlight implements SingleFlightInterface
{
    public ?string $lastAcquireKey = null;
    public ?string $lastReleaseKey = null;

    public function acquire(string $key, int $timeoutMs = 1500, int $ttlSeconds = 30): ?string
    {
        $this->lastAcquireKey = $key;
        return 'token';
    }

    public function release(string $key, string $token): void
    {
        $this->lastReleaseKey = $key;
    }
}
