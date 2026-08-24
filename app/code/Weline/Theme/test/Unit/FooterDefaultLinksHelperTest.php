<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

final class FooterDefaultLinksHelperTest extends TestCase
{
    public function testPartialLinkGroupsUseTextItems(): void
    {
        $groups = FooterDefaultLinksHelper::partialLinkGroups();

        $this->assertNotEmpty($groups);
        $this->assertArrayHasKey('title', $groups[0]);
        $this->assertArrayHasKey('items', $groups[0]);
        $this->assertArrayHasKey('text', $groups[0]['items'][0]);
        $this->assertArrayHasKey('url', $groups[0]['items'][0]);
    }

    public function testWidgetLinkGroupsUseLabelLinks(): void
    {
        $groups = FooterDefaultLinksHelper::widgetLinkGroups();

        $this->assertNotEmpty($groups);
        $this->assertArrayHasKey('links', $groups[0]);
        $this->assertArrayHasKey('label', $groups[0]['links'][0]);
        $this->assertArrayHasKey('url', $groups[0]['links'][0]);
    }
}
