<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\PageLayout;
use GuoLaiRen\PageBuilder\Service\AiSiteMaterializationService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use PHPUnit\Framework\TestCase;

final class AiSiteMaterializationServiceTest extends TestCase
{
    public function testMaterializeRejectsNonPositiveWebsiteId(): void
    {
        $page = $this->createMock(Page::class);
        $page->expects(self::never())->method('save');
        $layout = $this->createMock(PageLayout::class);
        $scopeSvc = new AiSiteScopeCompatibilityService(LayoutConfigNormalizer::getInstance());
        $service = new AiSiteMaterializationService($page, $layout, $scopeSvc);

        $this->expectException(\InvalidArgumentException::class);
        $service->materialize(0, [], [Page::TYPE_HOME], []);
    }
}
