<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Data;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;

/**
 * P5：website_local 同请求只加载一次行袋（Fiber 安全 RequestContext），
 * 禁止进程静态 $localizedFieldCache；SeoHead/seo::body/footer 重复读走袋。
 */
final class WebsiteLocalRowsRequestMemoContractTest extends TestCase
{
    private const LOCAL_ROWS_BAG_KEY = 'websites.website_local_rows.v1';

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

    public function testSourceUsesRequestScopedLocalRowsBagNotProcessStaticFieldCache(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Data/WebsiteData.php'
        );
        self::assertStringContainsString("websites.website_local_rows.v1", $src);
        self::assertStringContainsString('loadLocalRowsOnce', $src);
        self::assertStringNotContainsString(
            'private static array $localizedFieldCache',
            $src,
            'process-static field cache is Fiber-unsafe and misses listConfiguredLocalNames'
        );
        self::assertMatchesRegularExpression(
            '/function\s+listConfiguredLocalNames[\s\S]*loadLocalRowsOnce\s*\(/',
            $src
        );
        self::assertMatchesRegularExpression(
            '/function\s+resolveLocalizedFieldForLocale[\s\S]*loadLocalRowsOnce\s*\(/',
            $src
        );
        self::assertMatchesRegularExpression(
            '/function\s+resetRequestState[\s\S]*LOCAL_ROWS_BAG_KEY|website_local_rows\.v1/',
            $src
        );
    }

    public function testResetRequestStateClearsLocalRowsBag(): void
    {
        RequestContext::set(self::LOCAL_ROWS_BAG_KEY, [
            '0' => [['local_code' => 'en_US', 'name' => 'EN', 'description' => '']],
        ]);
        WebsiteData::resetRequestState();
        self::assertNull(RequestContext::get(self::LOCAL_ROWS_BAG_KEY));
    }

    public function testInjectedLocalRowsBagServesAlternateNameWithoutExtraShape(): void
    {
        $website = new WebsiteLocalRowsMemoWebsiteStub([
            Website::schema_fields_ID => 0,
            Website::schema_fields_CODE => 'default',
            Website::schema_fields_NAME => '主表站名',
            Website::schema_fields_URL => 'https://default.example.test',
            Website::schema_fields_DEFAULT_CURRENCY => 'CNY',
            Website::schema_fields_DEFAULT_LANGUAGE => 'zh_Hans_CN',
            Website::schema_fields_DEFAULT_TIMEZONE => 'UTC',
            Website::schema_fields_DESCRIPTION => '',
        ]);
        WebsiteData::setWebsite($website);
        RequestContext::set(self::LOCAL_ROWS_BAG_KEY, [
            '0' => [
                [
                    'local_code' => 'zh_Hans_CN',
                    'name' => '中文站名',
                    'description' => '中文简介',
                ],
                [
                    'local_code' => 'en_US',
                    'name' => 'EN Brand',
                    'description' => 'EN about',
                ],
            ],
        ]);

        // Prefer en_US when current display looks Chinese / empty locale path.
        $alt = WebsiteData::getOrganizationAlternateName('中文站名');
        self::assertSame('EN Brand', $alt);

        // Same bag must still serve after repeated SEO-style callers.
        self::assertSame('EN Brand', WebsiteData::getOrganizationAlternateName('中文站名'));
        self::assertSame('EN Brand', WebsiteData::getOrganizationAlternateName('中文站名'));
        self::assertSame('EN Brand', WebsiteData::getOrganizationAlternateName('中文站名'));

        $bag = RequestContext::get(self::LOCAL_ROWS_BAG_KEY);
        self::assertIsArray($bag);
        self::assertCount(1, $bag, 'request bag stays one website_id entry; no process static growth');
    }
}

/**
 * Minimal Website stub for request-memo contract (no ORM).
 */
final class WebsiteLocalRowsMemoWebsiteStub extends Website
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
