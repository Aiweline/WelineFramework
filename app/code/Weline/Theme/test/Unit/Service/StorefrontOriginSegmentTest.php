<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\StorefrontOriginSegment;

final class StorefrontOriginSegmentTest extends TestCase
{
    public function testOriginSegmentChangesWithVisitorHostEvenWhenWebsiteUrlIsFixed(): void
    {
        Context::enter(new Context());
        RequestContext::init();
        try {
            WelineEnv::set('website_url', 'https://www.example.com', 'test');
            WelineEnv::setServer('HTTP_HOST', 'a.test.weline.com', 'test');
            $a = StorefrontOriginSegment::get();

            RequestContext::remove('theme.storefront_origin_segment.v1');
            WelineEnv::setServer('HTTP_HOST', 'b.test.weline.com', 'test');
            $b = StorefrontOriginSegment::get();

            self::assertStringStartsWith('origin-', $a);
            self::assertStringStartsWith('origin-', $b);
            self::assertNotSame($a, $b, 'Visitor host must split the storefront origin cache segment.');
        } finally {
            Context::leave();
        }
    }
}
