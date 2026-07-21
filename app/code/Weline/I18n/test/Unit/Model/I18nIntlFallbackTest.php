<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Model;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\I18n\Config\Reader;
use Weline\I18n\Model\I18n;

class I18nIntlFallbackTest extends TestCore
{
    public function testLocaleLookupsDoNotRequireSymfonyIntlPackage(): void
    {
        $i18n = new class(ObjectManager::getInstance(Reader::class)) extends I18n {
            protected function hasSymfonyLocales(): bool
            {
                return false;
            }

            protected function hasSymfonyCountries(): bool
            {
                return false;
            }
        };
        $i18n->i18nCache = new I18nIntlFallbackMemoryCachePool();

        self::assertSame('zh_Hans_CN', $i18n->getLocalByCode('zh_Hans_CN'));
        self::assertArrayHasKey('zh_Hans_CN', $i18n->getLocals());
        self::assertNotSame('', $i18n->getLocaleName('zh_Hans_CN', 'en'));
        self::assertTrue($i18n->localeExists('en_US'));
        self::assertArrayHasKey('CN', $i18n->getCountries('en'));
    }
}

final class I18nIntlFallbackMemoryCachePool implements CachePoolInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->values[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->values = [];
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function getIdentity(): string
    {
        return 'i18n-intl-fallback-test';
    }

    public function getTip(): string
    {
        return 'I18n Intl fallback test cache';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->values)) {
                $values[$key] = $this->values[$key];
            }
        }
        return $values;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $key => $value) {
            $this->values[(string)$key] = $value;
        }
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->values[$key]);
        }
        return true;
    }

    public function getStats(): array
    {
        return [
            'identity' => $this->getIdentity(),
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }

    public function getCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): mixed {
        return $this->get($key);
    }

    public function setCustom(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->set($key, $value, $ttl);
    }

    public function deleteCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->delete($key);
    }

    public function hasCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->has($key);
    }
}
