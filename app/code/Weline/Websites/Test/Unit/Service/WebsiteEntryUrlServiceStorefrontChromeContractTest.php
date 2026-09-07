<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteEntryUrlService;

final class WebsiteEntryUrlServiceStorefrontChromeContractTest extends TestCase
{
    public function testResolveStorefrontForBackendChromePrefersLiveRequestBaseHost(): void
    {
        $service = new WebsiteEntryUrlService(new \Weline\Websites\Model\WebsiteDomain());
        $request = $this->createMock(Request::class);
        $request->method('getBaseHost')->willReturn('https://p05113ef3.test.weline.com:9555');

        $url = $service->resolveStorefrontForBackendChrome($request, [
            Website::schema_fields_URL => 'http://localhost',
        ]);

        self::assertSame('https://p05113ef3.test.weline.com:9555', $url);
    }
}
