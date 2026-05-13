<?php

declare(strict_types=1);

namespace WeShop\Subscription\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderAccountLinksTemplateTest extends TestCase
{
    public function testHeaderAccountLinkTargetsAccountSubscriptionsSection(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/hooks/header-account-links.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringContainsString("Hook: account.sidebar", (string) file_get_contents(dirname(__DIR__, 3) . '/view/hooks/account.sidebar.phtml'));
        $this->assertStringContainsString("@url{'customer/account/index'}#subscriptions", $content);
    }
}
