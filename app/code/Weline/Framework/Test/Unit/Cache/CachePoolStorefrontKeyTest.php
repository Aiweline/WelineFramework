<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\RememberOptions;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Pool\CachePool;

class CachePoolStorefrontKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_SERVER['WELINE_WEBSITE_CODE'],
            $_SERVER['WELINE_AREA'],
            $_SERVER['WELINE_USER_LANG'],
            $_SERVER['WELINE_USER_CURRENCY']
        );
        parent::tearDown();
    }

    public function testDefaultGetSetIsolatesWebsiteLangCurrency(): void
    {
        $adapter = new CachePoolStorefrontSpyAdapter();
        $pool = new CachePool('unit', $adapter, jitterRatio: 0.0);

        $_SERVER['WELINE_WEBSITE_CODE'] = 'shop_a';
        $_SERVER['WELINE_AREA'] = 'frontend';
        $_SERVER['WELINE_USER_LANG'] = 'zh_Hans_CN';
        $_SERVER['WELINE_USER_CURRENCY'] = 'CNY';
        $pool->set('product:1', 'price-a', 600);
        self::assertSame('price-a', $pool->get('product:1'));

        $_SERVER['WELINE_WEBSITE_CODE'] = 'shop_b';
        self::assertNull($pool->get('product:1'));

        $_SERVER['WELINE_WEBSITE_CODE'] = 'shop_a';
        $_SERVER['WELINE_USER_CURRENCY'] = 'USD';
        self::assertNull($pool->get('product:1'));

        self::assertCount(1, $adapter->store);
    }

    public function testCustomFullEscapeSharesAcrossWebsites(): void
    {
        $adapter = new CachePoolStorefrontSpyAdapter();
        $pool = new CachePool('unit', $adapter, jitterRatio: 0.0);

        $_SERVER['WELINE_WEBSITE_CODE'] = 'shop_a';
        $pool->setCustom('phrase:zh', ['hello' => '你好'], 600);
        $_SERVER['WELINE_WEBSITE_CODE'] = 'shop_b';
        self::assertSame(['hello' => '你好'], $pool->getCustom('phrase:zh'));
        self::assertNull($pool->get('phrase:zh'));
    }

    public function testCustomLangOnlyIsolatesLanguage(): void
    {
        $adapter = new CachePoolStorefrontSpyAdapter();
        $pool = new CachePool('unit', $adapter, jitterRatio: 0.0);

        $_SERVER['WELINE_WEBSITE_CODE'] = 'shop_a';
        $_SERVER['WELINE_USER_LANG'] = 'zh_Hans_CN';
        $pool->setCustom('menu', 'zh-menu', 600, lang: true);

        $_SERVER['WELINE_WEBSITE_CODE'] = 'shop_b';
        self::assertSame('zh-menu', $pool->getCustom('menu', lang: true));

        $_SERVER['WELINE_USER_LANG'] = 'en_US';
        self::assertNull($pool->getCustom('menu', lang: true));
    }

    public function testRememberCustomUsesStorageKeyForSingleFlightLock(): void
    {
        $adapter = new CachePoolStorefrontSpyAdapter();
        $pool = new CachePool('unit', $adapter, jitterRatio: 0.0);
        $flight = new CachePoolStorefrontSpySingleFlight();
        $pool->setSingleFlight($flight);

        $_SERVER['WELINE_WEBSITE_CODE'] = 'shop_a';
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
