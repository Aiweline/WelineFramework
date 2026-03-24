<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class HookContractsTest extends TestCase
{
    public function testDiscoveryCardUsesHostDataAndCleanRoute(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Customer/frontend/account/discovery/cards.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString("getData('recently_viewed_count')", $template);
        $this->assertStringContainsString("getUrl('recently-viewed')", $template);
        $this->assertStringNotContainsString('SessionFactory', $template);
        $this->assertStringNotContainsString('ObjectManager', $template);
    }
}
