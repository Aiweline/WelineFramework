<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSitePageRouteContractService;
use PHPUnit\Framework\TestCase;

final class AiSitePageRouteContractServiceTest extends TestCase
{
    public function testHeaderRouteContractIncludesSelectedCustomPage(): void
    {
        $service = new AiSitePageRouteContractService();

        $contract = $service->build([
            Page::TYPE_HOME,
            Page::TYPE_ABOUT,
            Page::TYPE_BLOG_LIST,
            Page::TYPE_CONTACT,
            Page::TYPE_CUSTOM,
        ], [
            'virtual_pages_by_type' => [
                Page::TYPE_CUSTOM => ['title' => 'Workflow Audit'],
            ],
        ], 'en_US');

        self::assertSame(
            [
                Page::TYPE_HOME,
                Page::TYPE_ABOUT,
                Page::TYPE_BLOG_LIST,
                Page::TYPE_CONTACT,
                Page::TYPE_CUSTOM,
            ],
            $contract['header_route_types'] ?? []
        );
        self::assertContains('/page', $contract['link_groups']['navigation_plan.header_items']['allowed_paths'] ?? []);
    }
}
