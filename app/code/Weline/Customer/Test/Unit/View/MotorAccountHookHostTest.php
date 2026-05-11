<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class MotorAccountHookHostTest extends TestCase
{
    public function testMotorThemeSidebarAndContentExposeCanonicalAccountHooks(): void
    {
        $sidebarTemplate = dirname(__DIR__, 6) . '/design/WeShop/motor/Weline/Customer/view/templates/frontend/account/sidebar/side.phtml';
        $indexTemplate = dirname(__DIR__, 6) . '/design/WeShop/motor/Weline/Customer/view/templates/frontend/account/index.phtml';

        $this->assertFileExists($sidebarTemplate);
        $this->assertFileExists($indexTemplate);

        $sidebar = (string) file_get_contents($sidebarTemplate);
        $index = (string) file_get_contents($indexTemplate);

        $this->assertStringContainsString('<hook>account.sidebar</hook>', $sidebar);
        $this->assertStringContainsString('data-account-nav-link="true"', $sidebar);

        $this->assertStringContainsString('<hook>account.sidebar.content</hook>', $index);
        $this->assertStringContainsString('data-account-section="overview"', $index);
        $this->assertStringContainsString('data-account-section="profile"', $index);
        $this->assertStringContainsString('data-account-section="security"', $index);
        $this->assertStringContainsString('data-account-section="login-info"', $index);
    }
}
