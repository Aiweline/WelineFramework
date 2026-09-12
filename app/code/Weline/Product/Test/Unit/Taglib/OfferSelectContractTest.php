<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Product\Taglib\OfferSelect;

final class OfferSelectContractTest extends TestCase
{
    public function testOfferSelectTagContract(): void
    {
        self::assertSame('product:offer:select', OfferSelect::name());
        self::assertTrue(OfferSelect::attr()['id']);
        self::assertFalse(OfferSelect::attr()['name']);
        self::assertTrue(OfferSelect::tag_self_close());
        self::assertStringContainsString('offer-search/search', OfferSelect::document());
    }
}
