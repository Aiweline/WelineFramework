<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class FaqBackendListingContractTest extends TestCase
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

    public function testBackendTemplatesUseWelineUiNotBootstrap(): void
    {
        $root = dirname(__DIR__, 3) . '/view/templates/Backend';
        $files = [
            $root . '/Page/listing.phtml',
            $root . '/Page/edit.phtml',
            $root . '/Item/listing.phtml',
            $root . '/Item/edit.phtml',
        ];

        foreach ($files as $file) {
            $body = (string)file_get_contents($file);
            self::assertStringContainsString('w-button', $body, $file);
            self::assertStringNotContainsString('form-control', $body, $file);
            self::assertStringNotContainsString('btn-primary', $body, $file);
            self::assertStringNotContainsString('form-select', $body, $file);
            self::assertStringNotContainsString('--ha-', $body, $file);
            self::assertStringNotContainsString('table table-', $body, $file);
        }

        $pageListing = (string)file_get_contents($root . '/Page/listing.phtml');
        $itemListing = (string)file_get_contents($root . '/Item/listing.phtml');
        self::assertStringContainsString('w-toolbar', $pageListing);
        self::assertStringContainsString('w-table', $pageListing);
        self::assertStringContainsString('w-empty', $pageListing);
        self::assertStringContainsString('w-toolbar', $itemListing);
        self::assertStringContainsString('w-table', $itemListing);
        self::assertStringContainsString('w-empty', $itemListing);

        $itemEdit = (string)file_get_contents($root . '/Item/edit.phtml');
        self::assertStringContainsString('w-field', $itemEdit);
        self::assertStringContainsString('w-input', $itemEdit);
        self::assertStringContainsString('w-select', $itemEdit);
    }
}
