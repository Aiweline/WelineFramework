<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Service\WebsiteChangeSnapshotFactory;

/** Plan coverage: RC01, ZERO01, WEB02, WEB03, WEB04. */
final class WebsiteResourceChangeContractTest extends TestCase
{
    public function testWeb02DefaultWebsiteImpactKeepsCurrentAndPreviousNamespacesAndUrls(): void
    {
        $factory = $this->factory();
        $before = $this->snapshot('legacy-default', 'https://old.example.test/', [
            ['domain' => 'old.example.test', 'sub_path' => '/shop'],
        ]);
        $after = $this->snapshot('default', 'https://new.example.test/', [
            ['domain' => 'new.example.test', 'sub_path' => 'store'],
        ]);

        $impact = $factory->impact($before, $after);

        self::assertSame(0, $before[Website::schema_fields_ID]);
        self::assertSame(0, $after[Website::schema_fields_ID]);
        self::assertContains('website/default', $impact['namespaces']);
        self::assertContains('website/default/domain', $impact['namespaces']);
        self::assertContains('global/websites-registry', $impact['namespaces']);
        self::assertContains('website/legacy-default', $impact['previous_namespaces']);
        self::assertContains('website/legacy-default/config/start-page', $impact['previous_namespaces']);
        self::assertContains('https://new.example.test/', $impact['urls']);
        self::assertContains('https://new.example.test/store', $impact['urls']);
        self::assertContains('https://old.example.test/', $impact['previous_urls']);
        self::assertContains('https://old.example.test/shop', $impact['previous_urls']);
    }

    public function testWeb03DeleteImpactPreservesCompletePreviousSurface(): void
    {
        $factory = $this->factory();
        $before = $this->snapshot('shop', 'https://shop.example.test/', [
            ['domain' => 'shop.example.test', 'sub_path' => 'catalog'],
        ]);

        $impact = $factory->impact($before, null);

        self::assertSame([], $impact['namespaces']);
        self::assertSame([], $impact['urls']);
        self::assertContains('website/shop', $impact['previous_namespaces']);
        self::assertContains('website/shop/language', $impact['previous_namespaces']);
        self::assertContains('https://shop.example.test/', $impact['previous_urls']);
        self::assertContains('https://shop.example.test/catalog', $impact['previous_urls']);
    }

    public function testWeb04ChangedFieldsAreDeterministicForCreateUpdateAndDelete(): void
    {
        $factory = $this->factory();

        self::assertSame(['code', 'name'], $factory->changedFields(
            ['name' => 'Before', 'code' => 'default'],
            ['name' => 'After', 'code' => 'shop'],
        ));
        self::assertSame(['code', 'name'], $factory->changedFields(
            null,
            ['name' => 'After', 'code' => 'shop'],
        ));
        self::assertSame(['code', 'name'], $factory->changedFields(
            ['name' => 'Before', 'code' => 'default'],
            null,
        ));
        self::assertSame([], $factory->changedFields(
            ['name' => 'Same', 'code' => 'default'],
            ['code' => 'default', 'name' => 'Same'],
        ));
    }

    private function factory(): WebsiteChangeSnapshotFactory
    {
        $config = (new \ReflectionClass(ConfigStore::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(ConfigStore::class, $config);

        return new WebsiteChangeSnapshotFactory(
            $this->createStub(Website::class),
            $this->createStub(WebsiteDomain::class),
            $this->createStub(WebsiteCurrency::class),
            $this->createStub(WebsiteLanguage::class),
            $config,
            new NamespacePath(),
        );
    }

    /** @param list<array{domain:string,sub_path:string}> $domains @return array<string,mixed> */
    private function snapshot(string $code, string $url, array $domains): array
    {
        return [
            Website::schema_fields_ID => 0,
            Website::schema_fields_NAME => 'Default website',
            Website::schema_fields_CODE => $code,
            Website::schema_fields_URL => $url,
            'domains' => $domains,
            'currency' => ['CNY'],
            'language' => ['zh_Hans_CN'],
            'start_page_config' => ['frontend' => 'index', 'backend' => 'dashboard'],
        ];
    }
}
