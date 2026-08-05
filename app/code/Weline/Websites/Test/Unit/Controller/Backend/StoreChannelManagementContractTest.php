<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class StoreChannelManagementContractTest extends TestCase
{
    public function testIndependentMenuLeavesUseExactRoutesAndAclSources(): void
    {
        $menu = (string)file_get_contents(BP . 'app/code/Weline/Websites/etc/backend/menu.xml');
        $controller = (string)file_get_contents(BP . 'app/code/Weline/Websites/Controller/Backend/ScopeManagement.php');
        $expected = [
            'Weline_Websites::store_management' => ['stores', 'postCreateStore', 'websites/backend/scope-management/stores'],
            'Weline_Websites::sales_channel_management' => ['channels', 'postCreateChannel', 'websites/backend/scope-management/channels'],
        ];
        foreach ($expected as $source => [$getMethod, $postMethod, $action]) {
            self::assertSame(1, substr_count($menu, 'source="' . $source . '"'));
            self::assertStringContainsString('action="' . $action . '"', $menu);
            self::assertMatchesRegularExpression(
                '/#\\[Acl\\(\'' . preg_quote($source, '/') . '\',.*?\'Weline_Websites::website_service\'\\)\\]\\s+public function ' . $getMethod . '\\(\\): string/s',
                $controller,
            );
            self::assertMatchesRegularExpression(
                '/#\\[Acl\\(\'' . preg_quote($source, '/') . '\',.*?\\)\\]\\s+public function ' . $postMethod . '\\(\\): string/s',
                $controller,
            );
        }
        self::assertStringNotContainsString('self::STORE_SOURCE', $controller);
        self::assertStringNotContainsString('self::CHANNEL_SOURCE', $controller);
    }

    public function testWorkbenchDelegatesReadsAndWritesToExistingDomainBoundaries(): void
    {
        $service = (string)file_get_contents(BP . 'app/code/Weline/Websites/Service/StoreChannelAdminService.php');
        $template = (string)file_get_contents(BP . 'app/code/Weline/Websites/view/templates/Backend/ScopeManagement/index.phtml');
        foreach (['StoreCatalogInterface', 'SalesChannelCatalogInterface', 'Store $storeModel', 'SalesChannel $channelModel'] as $dependency) {
            self::assertStringContainsString($dependency, $service);
        }
        self::assertStringContainsString('->save()', $service);
        self::assertStringContainsString('data-testid="store-management-create-form"', $template);
        self::assertStringContainsString('data-testid="sales-channel-management-create-form"', $template);
        self::assertSame(2, substr_count($template, 'csrf="auto"'));
    }

    public function testBothBrowserWritesAssertPostgresqlAndCleanup(): void
    {
        $spec = (string)file_get_contents(BP . 'app/code/Weline/Websites/Test/e2e/backend/Weline_Websites-menu-backend.spec.js');
        $fixture = (string)file_get_contents(BP . 'app/code/Weline/Websites/Test/e2e/backend/Weline_Websites-store-channel-fixture.php');
        self::assertStringContainsString('CK-R43-WEBSITES-STORE-001', $spec);
        self::assertStringContainsString('CK-R43-WEBSITES-CHANNEL-001', $spec);
        self::assertStringContainsString('Weline_Websites::store_management', $spec);
        self::assertStringContainsString('Weline_Websites::sales_channel_management', $spec);
        self::assertStringContainsString('r43_websites_requires_postgresql', $fixture);
        self::assertStringContainsString('r43_websites_cleanup', $fixture);
    }
}
