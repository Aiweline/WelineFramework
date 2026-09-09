<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;

final class WebsiteLoadContextCacheContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Context::leave();
        Context::enter(new Context());
        RequestContext::init();
        WebsiteData::resetRequestState();
    }

    protected function tearDown(): void
    {
        WebsiteData::resetRequestState();
        RequestContext::resetWelineVars();
        Context::leave();
        parent::tearDown();
    }

    public function testLoadPrefersRequestContextSnapshot(): void
    {
        WebsiteData::setWebsite(new WebsiteLoadRowStub([
            Website::schema_fields_ID => 0,
            Website::schema_fields_CODE => 'default',
            Website::schema_fields_NAME => 'Default',
            Website::schema_fields_URL => 'https://default.example.test',
            Website::schema_fields_DEFAULT_CURRENCY => 'CNY',
            Website::schema_fields_DEFAULT_LANGUAGE => 'zh_Hans_CN',
            Website::schema_fields_DEFAULT_TIMEZONE => 'UTC',
        ]));

        $loaded = new WebsiteLoadSpy();
        $loaded->load(0);

        self::assertSame(0, $loaded->getWebsiteId());
        self::assertSame('default', $loaded->getCode());
        self::assertSame(0, $loaded->parentLoadCalls);
        self::assertFalse($loaded->lastForceReload);
    }

    public function testForceReloadBypassesRequestContext(): void
    {
        WebsiteData::setWebsite(new WebsiteLoadRowStub([
            Website::schema_fields_ID => 3,
            Website::schema_fields_CODE => 'shop',
            Website::schema_fields_NAME => 'Shop',
            Website::schema_fields_URL => 'https://shop.example.test',
            Website::schema_fields_DEFAULT_CURRENCY => 'USD',
            Website::schema_fields_DEFAULT_LANGUAGE => 'en_US',
            Website::schema_fields_DEFAULT_TIMEZONE => 'UTC',
        ]));

        $loaded = new WebsiteLoadSpy();
        $loaded->load(3, forceReload: true);

        self::assertSame(1, $loaded->parentLoadCalls);
        self::assertTrue($loaded->lastForceReload);
        self::assertSame('forced', $loaded->getCode());
    }

    public function testLoadByCodePrefersRequestContext(): void
    {
        WebsiteData::setWebsite(new WebsiteLoadRowStub([
            Website::schema_fields_ID => 5,
            Website::schema_fields_CODE => 'outlet',
            Website::schema_fields_NAME => 'Outlet',
            Website::schema_fields_URL => 'https://outlet.example.test',
            Website::schema_fields_DEFAULT_CURRENCY => 'USD',
            Website::schema_fields_DEFAULT_LANGUAGE => 'en_US',
            Website::schema_fields_DEFAULT_TIMEZONE => 'UTC',
        ]));

        $loaded = new WebsiteLoadSpy();
        $loaded->load(Website::schema_fields_CODE, 'outlet');

        self::assertSame(5, $loaded->getWebsiteId());
        self::assertSame('outlet', $loaded->getCode());
        self::assertSame(0, $loaded->parentLoadCalls);
    }
}

final class WebsiteLoadSpy extends Website
{
    public int $parentLoadCalls = 0;
    public bool $lastForceReload = false;

    public function load(int|string $field_or_pk_value, $value = null, bool $forceReload = false): AbstractModel
    {
        if (!$forceReload) {
            $method = new \ReflectionMethod(Website::class, 'resolveRowWithoutQuery');
            $method->setAccessible(true);
            $row = $method->invoke($this, $field_or_pk_value, $value);
            if (\is_array($row)) {
                $this->setData($row);
                return $this;
            }
        }

        $this->parentLoadCalls++;
        $this->lastForceReload = $forceReload;
        $this->setData([
            Website::schema_fields_ID => $value === null ? (int)$field_or_pk_value : 99,
            Website::schema_fields_CODE => $forceReload ? 'forced' : 'db',
            Website::schema_fields_NAME => $forceReload ? 'Forced' : 'Db',
            Website::schema_fields_URL => 'https://example.test',
            Website::schema_fields_DEFAULT_CURRENCY => 'USD',
            Website::schema_fields_DEFAULT_LANGUAGE => 'en_US',
            Website::schema_fields_DEFAULT_TIMEZONE => 'UTC',
        ]);
        return $this;
    }
}

final class WebsiteLoadRowStub extends Website
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function setData($key, $value = null, bool $is_unique = false): static
    {
        if (\is_array($key)) {
            $this->values = $key;
        } else {
            $this->values[(string)$key] = $value;
        }
        return $this;
    }

    public function getData(string $key = '', $index = null): mixed
    {
        if ($key === '') {
            return $this->values;
        }
        return $this->values[$key] ?? null;
    }

    public function hasData(string $key = ''): bool
    {
        return $key === '' ? $this->values !== [] : \array_key_exists($key, $this->values);
    }
}
