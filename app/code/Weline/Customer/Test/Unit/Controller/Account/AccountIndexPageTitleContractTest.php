<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;

final class AccountIndexPageTitleContractTest extends TestCase
{
    public function testAccountDashboardPublishesTheDocumentTitleToTheTheme(): void
    {
        $controllerPath = \dirname(__DIR__, 4) . '/Controller/Account/Index.php';

        self::assertFileExists($controllerPath);
        $controller = (string) \file_get_contents($controllerPath);

        self::assertStringContainsString(
            "\$this->request->setGet('theme_page_title', (string)__('个人中心'))",
            $controller,
        );
        self::assertStringContainsString("\$this->assign('page_title', __('个人中心'))", $controller);
    }
}
