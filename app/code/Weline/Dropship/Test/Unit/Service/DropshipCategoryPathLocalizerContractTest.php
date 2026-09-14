<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;
use Weline\Dropship\Interface\DropshipCategoryPathLocalizerInterface;
use Weline\Dropship\Service\DropshipPublishService;

final class DropshipCategoryPathLocalizerContractTest extends TestCase
{
    public function testCjProviderImplementsCategoryPathLocalizer(): void
    {
        $provider = new CjProvider();
        self::assertInstanceOf(DropshipCategoryPathLocalizerInterface::class, $provider);
        self::assertSame(
            '方向盘套',
            $provider->localizeCategoryPath('Steering Covers', 'zh_Hans_CN')
        );
        self::assertSame(
            '家居、园艺与家具 / 家居收纳',
            $provider->localizeCategoryPath('Home, Garden & Furniture / Home Storage', 'zh_Hans_CN')
        );
        self::assertSame(
            'Steering Covers',
            $provider->localizeCategoryPath('Steering Covers', 'en_US')
        );
    }

    public function testPublishServiceResolveCategoryIdsLocalizesBeforeEnsure(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DropshipPublishService.php'
        );
        self::assertStringContainsString('DropshipCategoryPathLocalizerInterface', $src);
        self::assertStringContainsString('localizeCategoryPath', $src);
        self::assertStringContainsString('ensureFromRemotePath($websiteId, $snapshot->providerCode, $path, $locale)', $src);
    }
}
