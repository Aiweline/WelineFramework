<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Data;

use Fiber;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;

final class WebsiteDataRequestIsolationTest extends TestCase
{
    private const STATE_KEY = 'websites.website_data.state.v1';

    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        WebsiteData::resetRequestState();
    }

    protected function tearDown(): void
    {
        WebsiteData::resetRequestState();
        Context::leave();
        parent::tearDown();
    }

    public function testSetWebsiteKeepsDetachedSnapshotAndPreservesZeroWebsiteId(): void
    {
        $source = $this->website(0, 'default', 'CNY', 'zh_Hans_CN');

        WebsiteData::setWebsite($source);

        self::assertNotSame($source, WebsiteData::getWebsite());
        self::assertSame(0, WebsiteData::getWebsiteId());
        self::assertSame('default', WebsiteData::getCode());

        $source->setWebsiteId(9)->setCode('mutated');

        self::assertSame(0, WebsiteData::getWebsiteId());
        self::assertSame('default', WebsiteData::getCode());
    }

    public function testSettingAnotherWebsiteInvalidatesOnlyCurrentContextCaches(): void
    {
        WebsiteData::setWebsite($this->website(0, 'default', 'CNY', 'zh_Hans_CN'));
        self::primeCurrentCaches('zero');

        WebsiteData::setWebsite($this->website(7, 'shop_b', 'USD', 'en_US'));

        $state = RequestContext::get(self::STATE_KEY);
        self::assertIsArray($state);
        self::assertSame(7, WebsiteData::getWebsiteId());
        self::assertSame('shop_b', WebsiteData::getCode());
        self::assertNull($state['data'] ?? null);
        self::assertNull($state['currency_codes'] ?? null);
        self::assertNull($state['language_codes'] ?? null);
        self::assertNull($state['currencies'] ?? null);
    }

    public function testFiberContextsKeepWebsiteAndCachesIsolatedWhenPeerResets(): void
    {
        $observed = [];
        $websiteA = $this->website(0, 'default', 'CNY', 'zh_Hans_CN');
        $websiteB = $this->website(7, 'shop_b', 'USD', 'en_US');

        $fiberA = new Fiber(function () use (&$observed, $websiteA): void {
            Context::enter(new Context());
            try {
                WebsiteData::setWebsite($websiteA);
                self::primeCurrentCaches('a');
                Fiber::suspend('a-ready');

                $observed['a_after_b'] = self::currentSnapshot();
                WebsiteData::resetRequestState();
                $observed['a_after_reset'] = [
                    'website' => WebsiteData::getWebsite(),
                    'website_id' => WebsiteData::getWebsiteId(),
                    'state' => RequestContext::get(self::STATE_KEY),
                ];
                Fiber::suspend('a-reset');
            } finally {
                WebsiteData::resetRequestState();
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use (&$observed, $websiteB): void {
            Context::enter(new Context());
            try {
                WebsiteData::setWebsite($websiteB);
                self::primeCurrentCaches('b');
                Fiber::suspend('b-ready');

                $observed['b_after_a_reset'] = self::currentSnapshot();
                Fiber::suspend('b-verified');
            } finally {
                WebsiteData::resetRequestState();
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        self::assertSame('a-reset', $fiberA->resume());
        self::assertSame('b-verified', $fiberB->resume());

        self::assertSame(self::expectedSnapshot(0, 'default', 'a'), $observed['a_after_b']);
        self::assertSame([
            'website' => null,
            'website_id' => null,
            'state' => null,
        ], $observed['a_after_reset']);
        self::assertSame(self::expectedSnapshot(7, 'shop_b', 'b'), $observed['b_after_a_reset']);

        $fiberA->resume();
        $fiberB->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    private function website(int $id, string $code, string $currency, string $language): WebsiteDataSnapshotStub
    {
        return new WebsiteDataSnapshotStub([
            Website::schema_fields_ID => $id,
            Website::schema_fields_CODE => $code,
            Website::schema_fields_NAME => 'Site ' . $code,
            Website::schema_fields_URL => 'https://' . $code . '.example.test',
            Website::schema_fields_DEFAULT_CURRENCY => $currency,
            Website::schema_fields_DEFAULT_LANGUAGE => $language,
            Website::schema_fields_DEFAULT_TIMEZONE => 'UTC',
        ]);
    }

    private static function primeCurrentCaches(string $marker): void
    {
        $state = RequestContext::get(self::STATE_KEY);
        self::assertIsArray($state);
        $state['data'] = ['marker' => $marker];
        $state['currency_codes'] = [\strtoupper($marker) . '_CURRENCY'];
        $state['language_codes'] = [$marker . '_LANGUAGE'];
        $state['currencies'] = [[
            'code' => \strtoupper($marker),
            'name' => $marker,
            'format' => '1,0',
            'symbol' => $marker,
            'position' => 'left',
            'rate' => 1.0,
            'status' => true,
        ]];
        RequestContext::set(self::STATE_KEY, $state);
    }

    /** @return array<string, mixed> */
    private static function currentSnapshot(): array
    {
        return [
            'website_id' => WebsiteData::getWebsiteId(),
            'code' => WebsiteData::getCode(),
            'data' => WebsiteData::getData(),
            'currency_codes' => WebsiteData::getCurrencyCodes(),
            'language_codes' => WebsiteData::getLanguageCodes(),
            'currencies' => WebsiteData::getCurrencies(),
        ];
    }

    /** @return array<string, mixed> */
    private static function expectedSnapshot(int $websiteId, string $code, string $marker): array
    {
        return [
            'website_id' => $websiteId,
            'code' => $code,
            'data' => ['marker' => $marker],
            'currency_codes' => [\strtoupper($marker) . '_CURRENCY'],
            'language_codes' => [$marker . '_LANGUAGE'],
            'currencies' => [[
                'code' => \strtoupper($marker),
                'name' => $marker,
                'format' => '1,0',
                'symbol' => $marker,
                'position' => 'left',
                'rate' => 1.0,
                'status' => true,
            ]],
        ];
    }
}

final class WebsiteDataSnapshotStub extends Website
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
