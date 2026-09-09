<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Model\Feed;
use Weline\Geo\Service\EnsureDefaultFeedsService;

final class EnsureDefaultFeedsServiceContractTest extends TestCase
{
    public function testSourceCodesAreStable(): void
    {
        self::assertSame('default_content', EnsureDefaultFeedsService::SOURCE_CODE_CONTENT);
        self::assertSame('default_product', EnsureDefaultFeedsService::SOURCE_CODE_PRODUCT);
        self::assertSame('default_article', EnsureDefaultFeedsService::SOURCE_CODE_ARTICLE);
        self::assertSame('content', Feed::TYPE_CONTENT);
        self::assertSame('product', Feed::TYPE_PRODUCT);
        self::assertSame('article', Feed::TYPE_ARTICLE);
    }
}
