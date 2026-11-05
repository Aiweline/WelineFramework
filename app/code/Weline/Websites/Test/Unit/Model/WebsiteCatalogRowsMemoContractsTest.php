<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Observer\DetectWebsite;

/**
 * 站点/域名目录必须是**真正的**进程内存快照。
 *
 * 两个 `fetchCatalogRows()` 的 docblock 一直写着“进程内驻留，只查一次”，但实现
 * 每次调用都整表查询（实测 Website 0.21 ms/次、WebsiteDomain 0.45 ms/次），
 * 而 Worker 启动期有 3 个预热 provider 会调它们 ⇒ 每个 Worker 启动白打四五次全表。
 *
 * 快照一旦进程化，**失效**就成了正确性的一部分：只清检测缓存不清目录快照，
 * 就会用陈旧行重推一份“看起来全新”的检测结果。这条契约把
 * 「快照存在 + 写路径显式失效 + 统一重置入口」钉成结构约束。
 */
final class WebsiteCatalogRowsMemoContractsTest extends TestCase
{
    public function testCatalogRowsAreProcessResident(): void
    {
        foreach ([Website::class, WebsiteDomain::class] as $model) {
            $source = (string)\file_get_contents((new \ReflectionClass($model))->getFileName());

            self::assertStringContainsString(
                'private static ?array $catalogRows = null;',
                $source,
                $model . ' 必须持有进程内目录快照',
            );
            self::assertStringContainsString(
                'if (self::$catalogRows !== null) {',
                $source,
                $model . '::fetchCatalogRows() 必须先读进程快照，不得每次整表查询',
            );
            self::assertStringContainsString(
                'public static function clearCatalogRowsCache(): void',
                $source,
                $model . ' 必须暴露显式清理入口',
            );
            self::assertTrue(\method_exists($model, 'clearCatalogRowsCache'));
        }
    }

    public function testOwnTableWritesDropTheSnapshot(): void
    {
        foreach ([Website::class, WebsiteDomain::class] as $model) {
            $source = (string)\file_get_contents((new \ReflectionClass($model))->getFileName());

            // 失效必须挂在模型自己的写路径上，不能靠调用方记得清。
            self::assertSame(
                2,
                \substr_count($source, 'self::clearCatalogRowsCache();'),
                $model . ' 的 save_after / delete_after 都必须丢弃本表目录快照',
            );
        }
    }

    public function testResetEntryPointsAlsoDropTheSnapshot(): void
    {
        $websiteData = (string)\file_get_contents((new \ReflectionClass(WebsiteData::class))->getFileName());
        self::assertStringContainsString('Website::clearCatalogRowsCache();', $websiteData);
        self::assertStringContainsString('WebsiteDomain::clearCatalogRowsCache();', $websiteData);

        $detectWebsite = (string)\file_get_contents((new \ReflectionClass(DetectWebsite::class))->getFileName());
        self::assertStringContainsString('Website::clearCatalogRowsCache();', $detectWebsite);
        self::assertStringContainsString('WebsiteDomain::clearCatalogRowsCache();', $detectWebsite);

        Website::clearCatalogRowsCache();
        WebsiteDomain::clearCatalogRowsCache();

        $websiteRows = new \ReflectionProperty(Website::class, 'catalogRows');
        $websiteRows->setAccessible(true);
        self::assertNull($websiteRows->getValue(), 'clearCatalogRowsCache 后站点目录快照必须归零');

        $domainRows = new \ReflectionProperty(WebsiteDomain::class, 'catalogRows');
        $domainRows->setAccessible(true);
        self::assertNull($domainRows->getValue(), 'clearCatalogRowsCache 后域名目录快照必须归零');
    }
}
