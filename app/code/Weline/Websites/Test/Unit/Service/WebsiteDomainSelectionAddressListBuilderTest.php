<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\WebsiteDomainSelectionAddressListBuilder;
use Weline\Websites\Service\WebsiteSubPathValidator;

/**
 * Domain chips without pool_ids must still build a non-empty address list.
 */
final class WebsiteDomainSelectionAddressListBuilderTest extends TestCase
{
    public function testDomainValuesAloneBuildAddressListWithoutPoolIds(): void
    {
        $builder = new WebsiteDomainSelectionAddressListBuilder(
            new WebsiteSubPathValidator(),
            static fn (int $poolId): ?array => null,
            static fn (string $domain): int => 0,
        );

        $list = $builder->build('', 'daocharms.com,www.daocharms.com,p05113ef3.test.weline.com', '');

        self::assertCount(3, $list);
        self::assertSame('daocharms.com', $list[0]['domain']);
        self::assertSame('www.daocharms.com', $list[1]['domain']);
        self::assertSame('p05113ef3.test.weline.com', $list[2]['domain']);
        self::assertSame('', $list[0]['sub_path']);
        self::assertSame(0, $list[0]['pool_id']);
    }

    public function testPoolIdsMergeWithDomainValuesWithoutDuplicatingHosts(): void
    {
        $builder = new WebsiteDomainSelectionAddressListBuilder(
            new WebsiteSubPathValidator(),
            static function (int $poolId): ?array {
                if ($poolId !== 14) {
                    return null;
                }

                return ['domain' => 'p05113ef3.test.weline.com', 'pool_id' => 14];
            },
            static function (string $domain): int {
                return $domain === 'p05113ef3.test.weline.com' ? 14 : 0;
            },
        );

        $list = $builder->build('14', 'p05113ef3.test.weline.com,daocharms.com', '/daocharms');

        $domains = array_column($list, 'domain');
        self::assertContains('p05113ef3.test.weline.com', $domains);
        self::assertContains('daocharms.com', $domains);
        self::assertCount(2, $list);
        foreach ($list as $item) {
            self::assertSame('/daocharms', $item['sub_path']);
        }
        self::assertSame(14, $list[0]['pool_id']);
        self::assertSame(0, $list[1]['pool_id']);
    }
}
