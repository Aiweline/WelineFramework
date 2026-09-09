<?php

declare(strict_types=1);

namespace Weline\Help\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class HelpBackendListingContractTest extends TestCase
{
    public function testListingIsWebsiteGrouped(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Page.php');
        $listing = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/Page/listing.phtml');

        self::assertStringContainsString('groupPagesByWebsite', $controller);
        self::assertStringContainsString('loadWebsiteOptions', $controller);
        self::assertStringContainsString("'website_id'", $controller);
        self::assertStringContainsString('在此网站新建', $listing);
        self::assertStringContainsString('全部网站', $listing);
        self::assertStringContainsString('site_groups', $listing);
        self::assertStringContainsString('public_path', $listing);
    }
}
