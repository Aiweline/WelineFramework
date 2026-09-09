<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StorefrontPageContext;

final class StorefrontPageContextTest extends TestCase
{
    protected function tearDown(): void
    {
        StorefrontPageContext::clear();
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testResolvedEmptyListingIsDifferentFromMissingContext(): void
    {
        self::assertNull(StorefrontPageContext::listingOffers());

        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::init();
        StorefrontPageContext::setListingOffers([]);

        self::assertSame([], StorefrontPageContext::listingOffers());
    }

    public function testListingOffersStayInsideTheCurrentRequestContext(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::init();
        StorefrontPageContext::setListingOffers([['product_id' => 42]]);
        self::assertSame([['product_id' => 42]], StorefrontPageContext::listingOffers());

        StorefrontPageContext::clear();
        self::assertNull(StorefrontPageContext::listingOffers());
    }
}
