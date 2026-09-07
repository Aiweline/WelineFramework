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
        self::assertStringContainsString('function editStore(): string', $controller);
        self::assertStringContainsString('function editChannel(): string', $controller);
        self::assertStringContainsString('function postUpdateStore(): string', $controller);
        self::assertStringContainsString('function postUpdateChannel(): string', $controller);
        self::assertStringContainsString('Weline_Websites::store_save_after', $controller);
        self::assertStringContainsString('Weline_Websites::channel_save_after', $controller);
        self::assertStringNotContainsString('self::STORE_SOURCE', $controller);
        self::assertStringNotContainsString('self::CHANNEL_SOURCE', $controller);
        self::assertStringContainsString("postNonNegativeInt('store_id', 0)", $controller);
        self::assertStringContainsString("postNonNegativeInt('channel_id', 0)", $controller);
        self::assertStringNotContainsString("postPositiveInt('store_id')", $controller);
        self::assertStringNotContainsString("postPositiveInt('channel_id')", $controller);
    }

    public function testWorkbenchDelegatesReadsAndWritesToExistingDomainBoundaries(): void
    {
        $service = (string)file_get_contents(BP . 'app/code/Weline/Websites/Service/StoreChannelAdminService.php');
        $template = (string)file_get_contents(BP . 'app/code/Weline/Websites/view/templates/Backend/ScopeManagement/index.phtml');
        $editStore = (string)file_get_contents(BP . 'app/code/Weline/Websites/view/templates/Backend/ScopeManagement/edit-store.phtml');
        $editChannel = (string)file_get_contents(BP . 'app/code/Weline/Websites/view/templates/Backend/ScopeManagement/edit-channel.phtml');
        foreach (['StoreCatalogInterface', 'SalesChannelCatalogInterface', 'Store $storeModel', 'SalesChannel $channelModel'] as $dependency) {
            self::assertStringContainsString($dependency, $service);
        }
        self::assertStringContainsString('function updateStore(', $service);
        self::assertStringContainsString('function updateChannel(', $service);
        self::assertStringContainsString('->save()', $service);
        self::assertStringContainsString('data-testid="store-management-create-form"', $template);
        self::assertStringContainsString('data-testid="sales-channel-management-create-form"', $template);
        self::assertStringContainsString('store-management-edit-link', $template);
        self::assertStringContainsString("getHook('Weline_Websites::backend::store::form::sections-after')", $editStore);
        self::assertStringContainsString("getHook('Weline_Websites::backend::channel::form::sections-after')", $editChannel);
        self::assertStringNotContainsString('dispatchHook', $editStore);
        self::assertStringNotContainsString('dispatchHook', $editChannel);
        self::assertStringNotContainsString('$storeId <= 0', $editStore);
        self::assertStringNotContainsString('$channelId <= 0', $editChannel);
        self::assertStringContainsString("array_key_exists('code', \$entity)", $editStore);
        self::assertStringContainsString("array_key_exists('code', \$entity)", $editChannel);
        self::assertGreaterThanOrEqual(2, substr_count($template, 'csrf="auto"'));
        self::assertStringContainsString('csrf="auto"', $editStore);
        self::assertStringContainsString('csrf="auto"', $editChannel);
        self::assertStringContainsString('id="scope-website-filter-form"', $template);
        self::assertStringContainsString('auto-submit="true"', $template);
        self::assertStringNotContainsString('切换网站', $template);
        $taglib = (string)file_get_contents(BP . 'app/code/Weline/Websites/Taglib/WebsiteSelect.php');
        self::assertStringContainsString("'auto-submit' => false", $taglib);
        self::assertStringContainsString('var autoSubmit =', $taglib);
        $hook = (string)file_get_contents(BP . 'app/code/Weline/Websites/hook.php');
        self::assertStringContainsString('Weline_Websites::backend::store::form::sections-after', $hook);
        self::assertStringContainsString('Weline_Websites::backend::channel::form::sections-after', $hook);
        $event = (string)file_get_contents(BP . 'app/code/Weline/Websites/event.php');
        self::assertStringContainsString('Weline_Websites::store_save_after', $event);
        self::assertStringContainsString('Weline_Websites::channel_save_after', $event);
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
