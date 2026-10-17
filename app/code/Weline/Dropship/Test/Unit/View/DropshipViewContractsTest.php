<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 9 *ContractTest files).
 * Observe one overall pass/fail via testAllDropshipViewContractsPass().
 */
final class DropshipViewContractsTest extends TestCase
{
    public function testAllDropshipViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AfterSaleDead::testAfterSaleListsDeadAndCompensation' => function () {
            $this->assert_AfterSaleDead_testAfterSaleListsDeadAndCompensation();
        },
            'ChannelIndexTemplate::testChannelIndexUsesThemeComponentsAndHumanLabels' => function () {
            $this->assert_ChannelIndexTemplate_testChannelIndexUsesThemeComponentsAndHumanLabels();
        },
            'ChannelIndexTemplate::testCredentialsActionUsesProviderDeepLinkNotShellConfig' => function () {
            $this->assert_ChannelIndexTemplate_testCredentialsActionUsesProviderDeepLinkNotShellConfig();
        },
            'ConfigIndexTemplate::testConfigEmbedFieldsMatchDeclaredKeys' => function () {
            $this->assert_ConfigIndexTemplate_testConfigEmbedFieldsMatchDeclaredKeys();
        },
            'DevRelayTemplate::testTemplateAndMenuExist' => function () {
            $this->assert_DevRelayTemplate_testTemplateAndMenuExist();
        },
            'ListingTemplate::testListingIndexUsesTabsForListedAndPick' => function () {
            $this->assert_ListingTemplate_testListingIndexUsesTabsForListedAndPick();
        },
            'ListingTemplate::testLegacySearchActionRedirectsToIndexTab' => function () {
            $this->assert_ListingTemplate_testLegacySearchActionRedirectsToIndexTab();
        },
            'ListingTemplate::testPrototypePickerExists' => function () {
            $this->assert_ListingTemplate_testPrototypePickerExists();
        },
            'OrderIndexTemplate::testOrderIndexIsHumanReadable' => function () {
            $this->assert_OrderIndexTemplate_testOrderIndexIsHumanReadable();
        },
            'OrderLineImage::testOrderLinesExposeImageUrlFromListingThumb' => function () {
            $this->assert_OrderLineImage_testOrderLinesExposeImageUrlFromListingThumb();
        },
            'WarehouseIndexTemplate::testWarehouseIndexUsesProviderCoverageBoardVariantA' => function () {
            $this->assert_WarehouseIndexTemplate_testWarehouseIndexUsesProviderCoverageBoardVariantA();
        },
            'WarehouseIndexTemplate::testWarehouseControllerBuildsCoverageBoard' => function () {
            $this->assert_WarehouseIndexTemplate_testWarehouseControllerBuildsCoverageBoard();
        },
            'WarehouseMapIaPrototype::testIaPrototypeHasThreeVariantsAndRecommendsProviderCoverage' => function () {
            $this->assert_WarehouseMapIaPrototype_testIaPrototypeHasThreeVariantsAndRecommendsProviderCoverage();
        },
        ];
    }

private function assert_AfterSaleDead_testAfterSaleListsDeadAndCompensation(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $ctrl = (string) file_get_contents($root . '/Controller/Backend/AfterSale.php');
    $tpl = (string) file_get_contents($root . '/view/templates/backend/AfterSale/index.phtml');
    self::assertStringContainsString('STATUS_DEAD', $ctrl);
    self::assertStringContainsString('STATUS_ERROR', $ctrl);
    self::assertStringContainsString('compensation', $ctrl);
    self::assertStringContainsString('maybeBackfillTerminalCompensation', $ctrl);
    self::assertStringContainsString('maybeRecoverExistingFulfillment', $ctrl);
    self::assertStringContainsString('recovered_existing', $ctrl);
    self::assertStringContainsString('compensation_error_code', $ctrl);
    self::assertStringContainsString('待补偿', $ctrl);
    self::assertStringContainsString('无法履约', $ctrl);
    self::assertStringContainsString('已对齐远端订单', $ctrl);
    self::assertStringContainsString('compensation', $tpl);
    self::assertStringContainsString('data-testid="dropship-aftersale-compensation"', $tpl);
    self::assertStringContainsString('data-testid="dropship-aftersale-status"', $tpl);
    self::assertStringContainsString('data-testid="dropship-aftersale-empty"', $tpl);
    self::assertStringContainsString('data-testid="dropship-aftersale-goto-orders"', $tpl);
    self::assertStringContainsString('data-testid="dropship-aftersale-goto-config"', $tpl);
    self::assertStringContainsString('w-card', $tpl);
    self::assertStringContainsString('推单异常与补偿', $tpl);
    self::assertStringContainsString('查看履约订单', $tpl);
    self::assertStringContainsString('补偿进度', $tpl);
    self::assertStringContainsString('错误原因', $tpl);
    self::assertStringContainsString('平台代码', $tpl);
    self::assertStringContainsString('orders_url', $ctrl);
    self::assertStringContainsString('config_url', $ctrl);
}

private function assert_ChannelIndexTemplate_testChannelIndexUsesThemeComponentsAndHumanLabels(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/view/templates/backend/Channel/index.phtml';
    self::assertFileExists($path);
    $tpl = (string) file_get_contents($path);
    self::assertStringContainsString('w-table', $tpl);
    self::assertStringContainsString('w-badge', $tpl);
    self::assertStringContainsString('w-button', $tpl);
    self::assertStringContainsString('data-dropship-admin="channels"', $tpl);
    self::assertStringContainsString('已连接', $tpl);
    self::assertStringContainsString('缺少凭证', $tpl);
    self::assertStringContainsString('目录', $tpl);
    self::assertStringContainsString('履约', $tpl);
    self::assertStringContainsString('运费', $tpl);
    self::assertStringContainsString('回调', $tpl);
    self::assertStringContainsString('选品浏览', $tpl);
    self::assertStringContainsString('选品国家筛选', $tpl);
    self::assertStringContainsString('订单回调', $tpl);
    self::assertStringContainsString('商品回调', $tpl);
    self::assertStringContainsString('库存回调', $tpl);
    self::assertStringContainsString('物流回调', $tpl);
    self::assertStringContainsString('补单回调', $tpl);
    self::assertStringContainsString('私有订单回调', $tpl);
    self::assertStringContainsString('纠纷回调', $tpl);
    self::assertStringContainsString('仓映射', $tpl);
    self::assertStringContainsString('title=', $tpl);
    self::assertStringContainsString('配置凭证', $tpl);
    self::assertStringContainsString("__('配置')", $tpl);
    self::assertStringNotContainsString('!$ok && $credentialsEmbed', $tpl);
    self::assertStringNotContainsString('!$ok && $credentialsUrl', $tpl);
    self::assertStringContainsString('credentials_url', $tpl);
    self::assertStringContainsString('credentials_embed', $tpl);
    self::assertStringContainsString('dialog.open', $tpl);
    self::assertStringContainsString('w-dialog', $tpl);
    self::assertStringContainsString('w:config:embed', $tpl);
    self::assertStringContainsString('credEmbedGroup', $tpl);
    self::assertStringContainsString('w:scope', $tpl);
    self::assertStringContainsString('作用范围', $tpl);
    self::assertStringContainsString('dropship-cred-scope', $tpl);
    self::assertStringContainsString('selected_scope', $tpl);
    self::assertStringContainsString('回调地址', $tpl);
    self::assertStringContainsString('dropship-webhook-url', $tpl);
    self::assertStringContainsString('webhook_url', $tpl);
    self::assertStringContainsString('测试连接', $tpl);
    self::assertStringContainsString('dropship-cred-probe', $tpl);
    self::assertStringContainsString('data-probe-url', $tpl);
    self::assertStringNotContainsString('text-transform:uppercase', $tpl);
    self::assertStringNotContainsString('rgba(25,135,84', $tpl);
    self::assertStringNotContainsString('rgba(220,53,69', $tpl);
    self::assertStringNotContainsString('万能支付注入', $tpl);
    self::assertStringNotContainsString('json_encode($p[\'capabilities\']', $tpl);
}

private function assert_ChannelIndexTemplate_testCredentialsActionUsesProviderDeepLinkNotShellConfig(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $tpl = (string) file_get_contents($root . '/view/templates/backend/Channel/index.phtml');
    $ctrl = (string) file_get_contents($root . '/Controller/Backend/Channel.php');
    $builder = (string) file_get_contents($root . '/Service/DropshipChannelConfigDeepLinkBuilder.php');
    // Primary CTA: in-page w-dialog + config:embed; deep link secondary; shell config only top CTA.
    self::assertStringContainsString('dialog.open', $tpl);
    self::assertStringContainsString('dropship-cred-dialog', $tpl);
    self::assertStringContainsString('w:config:embed', $tpl);
    self::assertStringContainsString('data-testid="dropship-config-credentials"', $tpl);
    self::assertStringContainsString('dropship/backend/config', $tpl);
    self::assertStringContainsString('dropship-open-config', $tpl);
    self::assertStringContainsString('在统一配置中心打开', $tpl);
    self::assertStringContainsString('w:scope', $tpl);
    self::assertStringContainsString('cred_dialog', $tpl);
    self::assertStringContainsString('DropshipChannelConfigDeepLinkBuilder', $ctrl);
    self::assertStringContainsString('SystemConfigTargetScopeService', $ctrl);
    self::assertStringContainsString('config_center', $ctrl);
    self::assertStringContainsString('credentials_url', $ctrl);
    self::assertStringContainsString('credentials_embed', $ctrl);
    self::assertStringContainsString('selected_scope', $ctrl);
    self::assertStringContainsString('webhook_url', $ctrl);
    self::assertStringContainsString('endpoint_code', $ctrl);
    self::assertStringContainsString('function probe', $ctrl);
    self::assertStringContainsString('probeConnection', $ctrl);
    self::assertStringContainsString("getUrlBuilder()->getBackendUrl('dropship/backend/channel/probe')", $ctrl);
    self::assertStringNotContainsString('$this->getBackendUrl(', $ctrl);
    self::assertStringContainsString("'group'", $ctrl);
    self::assertStringContainsString('weline_systemconfig/backend/config', $builder);
    self::assertStringContainsString('guide_key', $builder);
}

private function assert_ConfigIndexTemplate_testConfigEmbedFieldsMatchDeclaredKeys(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $page = $root . '/view/templates/backend/Config/index.phtml';
    $declared = $root . '/extends/module/Weline_SystemConfig/Config/frontend/dropship-settings.phtml';
    self::assertFileExists($page);
    self::assertFileExists($declared);
    $pageTpl = (string) file_get_contents($page);
    $declTpl = (string) file_get_contents($declared);
    self::assertStringContainsString('data-dropship-admin="config"', $pageTpl);
    self::assertStringContainsString('w-stack', $pageTpl);
    self::assertStringContainsString('<w:scope', $pageTpl);
    self::assertStringContainsString('selected_scope', $pageTpl);
    self::assertStringContainsString('weline_systemconfig/backend/config', $pageTpl);
    self::assertMatchesRegularExpression('/<w:config:embed[^>]*fields="([^"]+)"/', $pageTpl, 'Config page must embed declared fields');
    preg_match('/<w:config:embed[^>]*fields="([^"]+)"/', $pageTpl, $m);
    $fields = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($m[1] ?? '')))));
    self::assertNotEmpty($fields);
    foreach ($fields as $key) {
        self::assertStringContainsString('key="' . $key . '"', $declTpl, 'embed field must be declared literally: ' . $key);
    }
    self::assertStringNotContainsString('fields="platforms_enabled', $pageTpl);
    self::assertStringNotContainsString('price_uplift_percent', $pageTpl);
    self::assertStringNotContainsString('启用的分销平台', $declTpl);
    self::assertMatchesRegularExpression('/key="dropship\/platforms\/enabled"[^>]*type="multiselect"/', $declTpl, 'platforms enabled must be multiselect tags');
    self::assertStringContainsString('options="fake:Fake 货源,cj:CJ Dropshipping"', $declTpl);
    self::assertStringNotContainsString('逗号分隔已注册 provider_code', $declTpl);
    self::assertStringContainsString('dropship/ops/follow_enabled', implode(',', $fields));
    self::assertStringContainsString('dropship/ops/auto_refund_on_push_fail', implode(',', $fields));
    self::assertMatchesRegularExpression('/key="dropship\/ops\/follow_enabled"[^>]*label="启用库存\/价格跟随"/', $declTpl, 'follow switch must be labeled for stock/price sync');
    self::assertStringContainsString('同步远程库存与价格', $declTpl);
    self::assertMatchesRegularExpression('/key="dropship\/ops\/auto_refund_on_push_fail"[^>]*default="1"/', $declTpl, 'auto refund switch must default on');
    self::assertStringContainsString('推单无法履约时自动退款并通知顾客', $declTpl);
}

private function assert_DevRelayTemplate_testTemplateAndMenuExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $tpl = file_get_contents($root . '/view/templates/backend/DevRelay/index.phtml');
    $ctrl = file_get_contents($root . '/Controller/Backend/DevRelay.php');
    $menu = file_get_contents($root . '/etc/backend/menu.xml');
    self::assertIsString($tpl);
    self::assertIsString($ctrl);
    self::assertIsString($menu);
    self::assertStringContainsString('payment/backend/dev-relay', $ctrl);
    self::assertStringContainsString('cj.sandbox.default', $ctrl);
    self::assertStringContainsString('打开支付 DevRelay 控制台', $tpl);
    self::assertStringContainsString('何时开启', $tpl);
    self::assertStringContainsString('怎么用（万能货源）', $tpl);
    self::assertStringContainsString('dropship-dev-relay-guide', $tpl);
    self::assertStringContainsString('dropship/backend/dev-relay', $menu);
}

private function assert_ListingTemplate_testListingIndexUsesTabsForListedAndPick(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $tpl = (string) file_get_contents($root . '/view/templates/backend/Listing/index.phtml');
    $ctrl = (string) file_get_contents($root . '/Controller/Backend/Listing.php');
    self::assertStringContainsString('w-tabs', $tpl);
    self::assertStringContainsString('data-testid="dropship-listing-tabs"', $tpl);
    self::assertStringContainsString('已刊登', $tpl);
    self::assertStringContainsString('选品', $tpl);
    self::assertStringContainsString('data-testid="dropship-pick-layout"', $tpl);
    self::assertStringContainsString('data-testid="dropship-pick-rail"', $tpl);
    self::assertStringContainsString('关键词（可选）', $tpl);
    self::assertStringContainsString('库存国家', $tpl);
    self::assertStringContainsString('w:theme:address', $tpl);
    self::assertStringContainsString('country-name="country_code"', $tpl);
    self::assertStringContainsString('category_id', $tpl);
    self::assertStringContainsString('选择', $tpl);
    self::assertStringContainsString('取消选择', $tpl);
    self::assertStringContainsString('刊登选中', $tpl);
    self::assertStringContainsString('minmax(240px,300px)', $tpl);
    self::assertStringContainsString('minmax(240px,320px)', $tpl);
    self::assertStringContainsString('overflow-wrap:anywhere', $tpl);
    self::assertStringContainsString('data-filters-collapsed', $tpl);
    self::assertStringContainsString('data-rail-collapsed', $tpl);
    self::assertStringContainsString('data-cats-collapsed', $tpl);
    self::assertStringContainsString('data-testid="dropship-collapse-rail"', $tpl);
    self::assertStringContainsString('data-testid="dropship-collapse-cats"', $tpl);
    self::assertStringContainsString('data-testid="dropship-toggle-filters"', $tpl);
    self::assertStringContainsString('dropship.pick.railCollapsed', $tpl);
    self::assertStringContainsString('dropship.pick.catsCollapsed', $tpl);
    self::assertStringContainsString('ds-pick-thumb', $tpl);
    self::assertStringContainsString('ds-pick-thumb-box', $tpl);
    self::assertStringContainsString('ds-pick-thumb-cell', $tpl);
    self::assertStringContainsString('min-width:3.5rem', $tpl);
    self::assertStringContainsString("['media']", $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-thumb"', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-thumb-ph"', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-local-sku"', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-edit"', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-delete"', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-delete-selected"', $tpl);
    self::assertStringContainsString('data-testid="dropship-delete-modal"', $tpl);
    self::assertStringContainsString('data-testid="dropship-delete-form"', $tpl);
    self::assertStringContainsString('data-testid="dropship-delete-local-keep"', $tpl);
    self::assertStringContainsString('data-testid="dropship-delete-local-disable"', $tpl);
    self::assertStringContainsString('data-testid="dropship-delete-local-archive"', $tpl);
    self::assertStringContainsString('name="local_product_action"', $tpl);
    self::assertStringContainsString('z-index:2147483600', $tpl);
    self::assertStringContainsString('dropship-delete-csrf', $tpl);
    self::assertStringContainsString('local_product_action', $tpl);
    self::assertStringContainsString('deleteSelected', $tpl);
    self::assertStringContainsString('postDeleteSelected', $ctrl);
    self::assertStringContainsString('DropshipListingDeleteService', $ctrl);
    self::assertStringContainsString('local_product_action', $ctrl);
    self::assertStringContainsString('enrichListedLocalMeta', $ctrl);
    self::assertStringContainsString('local_product_status', $ctrl);
    self::assertStringContainsString('productStatusPresentation', $ctrl);
    self::assertStringContainsString('getLocalDetail', $ctrl);
    self::assertStringContainsString('DropshipListedLocalDetailService', $ctrl);
    self::assertStringContainsString('data-dropship-listed-accordion="1"', $tpl);
    self::assertStringContainsString('data-weline-load="dropshipListedAccordion"', $tpl);
    self::assertStringContainsString('data-listed-expandable', $tpl);
    self::assertStringContainsString('data-listed-expand-toggle', $tpl);
    self::assertStringContainsString('data-listed-expand-label', $tpl);
    self::assertStringContainsString('role="button"', $tpl);
    self::assertStringContainsString('ensureListedAccordion', $tpl);
    self::assertStringContainsString('listed-accordion.js?v=1.0.70', $tpl);
    self::assertStringContainsString('data-i18n-status-published', $tpl);
    self::assertStringContainsString('status_label', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-product-status"', $tpl);
    self::assertStringContainsString('local_product_status', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-sale-compare"', $tpl);
    self::assertStringContainsString('sale_compare', $ctrl);
    self::assertStringContainsString('data-i18n-cost', $tpl);
    self::assertStringContainsString('data-i18n-margin', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-origin-change"', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-margin"', $tpl);
    self::assertStringContainsString('data-i18n-uplift', $tpl);
    self::assertStringContainsString('data-i18n-product', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-pricing"', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-sale"', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-uplift"', $tpl);
    self::assertStringContainsString('data-i18n-collapse', $tpl);
    self::assertStringContainsString('data-testid="dropship-listed-detail"', $tpl);
    self::assertStringContainsString('点击展开本地规格与售价', $tpl);
    self::assertStringContainsString('点击收起', $tpl);
    self::assertStringContainsString('ds-listed-detail-row.is-open', $tpl);
    self::assertStringContainsString('weline_product/backend/catalog/edit-product', $ctrl);
    self::assertStringContainsString('thumb_url', $tpl);
    self::assertStringContainsString('待刊登', $tpl);
    self::assertStringContainsString('可删除', $tpl);
    self::assertStringContainsString('全部收起', $tpl);
    self::assertStringContainsString('点击整行即选中', $tpl);
    self::assertStringContainsString('#dropship-browse-tbody tr', $tpl);
    self::assertStringContainsString('data-testid="dropship-browse-panel"', $tpl);
    self::assertStringContainsString('data-testid="dropship-browse-loading"', $tpl);
    self::assertStringContainsString('data-browse-async', $tpl);
    self::assertStringContainsString('origin_currency', $tpl);
    self::assertStringContainsString('function money(minor, currency)', $tpl);
    self::assertStringContainsString('getBrowse', $ctrl);
    self::assertStringContainsString('buildBrowsePayload', $ctrl);
    self::assertStringContainsString('browse_async', $ctrl);
    self::assertStringContainsString('正在从货源加载商品', $tpl);
    self::assertStringContainsString('data-testid="dropship-pick-float"', $tpl);
    self::assertStringContainsString('position:fixed', $tpl);
    self::assertStringContainsString('floatBar.hidden = n === 0', $tpl);
    self::assertStringContainsString('data-testid="dropship-float-select"', $tpl);
    self::assertStringContainsString('data-testid="dropship-float-cancel"', $tpl);
    self::assertStringContainsString('已拉取', $tpl);
    self::assertStringContainsString('cancelBtn.disabled = n === 0', $tpl);
    self::assertStringContainsString('data-action="basket-cancel"', $tpl);
    self::assertStringContainsString("data-pull-basket') === '1') return", $tpl);
    self::assertStringContainsString('locked || basket', $tpl);
    self::assertStringContainsString('locked || inBasket', $tpl);
    self::assertStringContainsString('basketSelect', $tpl);
    self::assertStringContainsString('postBasketCancel', $ctrl);
    self::assertStringContainsString('postBasketSelect', $ctrl);
    self::assertStringContainsString('mapPullState', $ctrl);
    self::assertStringContainsString('pull_locked', $ctrl);
    self::assertStringNotContainsString('请填写关键词', $tpl);
    self::assertStringNotContainsString('dropship-open-remote-search', $tpl);
    self::assertStringNotContainsString('form-control', $tpl);
    self::assertStringNotContainsString('fake_category', $tpl);
    self::assertStringNotContainsString('CjApiClient', $tpl);
    self::assertStringContainsString('DropshipCatalogBrowseProviderInterface', $ctrl);
    self::assertStringContainsString('DropshipCatalogCategoryCacheService', $ctrl);
    self::assertStringContainsString('categories_cache', $ctrl);
    self::assertStringContainsString('assignPublishScopeSelects', $ctrl);
    self::assertStringContainsString('resolvePublishScopeFromRequest', $ctrl);
    self::assertStringContainsString('SystemConfigTargetScopeService', $ctrl);
    $draftSrc = (string) file_get_contents($root . '/Service/DropshipListingDraftService.php');
    self::assertStringContainsString('provider_required', $draftSrc);
    self::assertStringContainsString('scope_required', $draftSrc);
    self::assertStringNotContainsString('$websiteId <= 0', $draftSrc);
    self::assertStringNotContainsString('scope_or_provider_required', $draftSrc);
    self::assertStringContainsString('<w:scope', $tpl);
    self::assertStringContainsString('dropship-publish-scope', $tpl);
    self::assertStringContainsString('name="target_scope"', $tpl);
    self::assertStringContainsString('publishSelectedScope', $tpl);
    self::assertStringContainsString('<w:dropship:provider:select', $tpl);
    self::assertStringContainsString('dropship-listed-providers', $tpl);
    self::assertStringContainsString('providerSelectOptionsJson', $tpl);
    self::assertStringContainsString('selectedSourcesCsv', $tpl);
    self::assertStringContainsString('auto-submit="true"', $tpl);
    self::assertStringContainsString('留空=全部', $tpl);
    self::assertStringNotContainsString('name="sources[]"', $tpl);
    self::assertStringNotContainsString('按供应商筛选', $tpl);
    self::assertStringNotContainsString('id="dropship-listing-sources"', $tpl);
    self::assertStringNotContainsString('w:websites:website:select', $tpl);
    self::assertStringNotContainsString('w:websites:store:select', $tpl);
    self::assertStringNotContainsString('dropship-publish-website', $tpl);
    self::assertStringNotContainsString('dropshipOnPublishWebsiteChange', $tpl);
    self::assertStringNotContainsString('StoreSelectOptions', $ctrl);
    self::assertStringNotContainsString('id="modal-website-id"', $tpl);
    self::assertStringNotContainsString('id="modal-store-id"', $tpl);
    self::assertStringNotContainsString('网站 ID', $tpl);
    self::assertStringNotContainsString('店铺 ID', $tpl);
    self::assertStringContainsString('listCategories', $ctrl);
    self::assertStringContainsString("'locale'", $ctrl);
    self::assertStringContainsString('getLangLocal', $ctrl);
    self::assertStringContainsString('DropshipListingDraftService', $ctrl);
    self::assertStringContainsString('isPlatformEnabled', $ctrl);
    self::assertStringNotContainsString('need_keyword', $ctrl);
    self::assertStringNotContainsString("fetch('Backend/Listing/search')", $ctrl);
    self::assertStringNotContainsString('CjApiClient', $ctrl);
}

private function assert_ListingTemplate_testLegacySearchActionRedirectsToIndexTab(): void
{
    $ctrl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Controller/Backend/Listing.php');
    self::assertStringContainsString('dropship/backend/listing/index', $ctrl);
    self::assertMatchesRegularExpression("/function\\s+search\\s*\\(/", $ctrl);
    self::assertStringContainsString("'tab' => 'pick'", $ctrl);
}

private function assert_ListingTemplate_testPrototypePickerExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/view/prototype/listing-picker.html';
    self::assertFileExists($path);
    $html = (string) file_get_contents($path);
    self::assertStringContainsString('PROTOTYPE', $html);
    self::assertStringContainsString('no_browse', $html);
    self::assertStringContainsString('browse_country_filter', $html);
    self::assertStringContainsString('toggle-filters', $html);
    self::assertStringContainsString('thumb', $html);
    self::assertStringContainsString('data-filters-collapsed', $html);
}

private function assert_OrderIndexTemplate_testOrderIndexIsHumanReadable(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $tpl = (string) file_get_contents($root . '/view/templates/backend/Order/index.phtml');
    $ctrl = (string) file_get_contents($root . '/Controller/Backend/Order.php');
    $mod = (string) file_get_contents($root . '/view/statics/backend/weline.modules.js');
    self::assertStringContainsString('data-testid="dropship-orders"', $tpl);
    self::assertStringContainsString('w-card', $tpl);
    self::assertStringContainsString('货源', $tpl);
    self::assertStringContainsString('本站订单', $tpl);
    self::assertStringContainsString('货源单号', $tpl);
    self::assertStringContainsString('物流', $tpl);
    self::assertStringContainsString('更新时间', $tpl);
    self::assertStringContainsString('推送队列', $tpl);
    self::assertStringContainsString('data-testid="dropship-fulfillment-status"', $tpl);
    self::assertStringContainsString('data-tone=', $tpl);
    self::assertStringNotContainsString('<th>provider</th>', $tpl);
    self::assertStringNotContainsString('<th>order</th>', $tpl);
    self::assertStringNotContainsString('>Outbox<', $tpl);
    self::assertStringContainsString('presentFulfillments', $ctrl);
    self::assertStringContainsString('status_label', $ctrl);
    self::assertStringContainsString('OrderFacadeInterface', $ctrl);
    self::assertStringContainsString('已送达', $ctrl);
    self::assertStringContainsString('待推送', $ctrl);
    self::assertStringContainsString('data-dropship-order-accordion="1"', $tpl);
    self::assertStringContainsString('data-weline-load="dropshipOrderAccordion"', $tpl);
    self::assertStringContainsString('data-order-expandable', $tpl);
    self::assertStringContainsString('data-testid="dropship-order-detail"', $tpl);
    self::assertStringContainsString('点击展开商品', $tpl);
    self::assertStringContainsString('getLines', $ctrl);
    self::assertStringContainsString('buildOrderLinesPayload', $ctrl);
    self::assertStringContainsString('dropshipOrderAccordion', $mod);
    self::assertStringContainsString('order-accordion.js?v=1.0.69', $mod);
    self::assertStringContainsString('ds-order-line__thumb', $tpl);
    self::assertStringContainsString('enrichLinesWithImages', $ctrl);
    self::assertStringContainsString('ensureOrderAccordion', $tpl);
    self::assertStringContainsString('data-dropship-order-accordion-src', $tpl);
    self::assertFileExists($root . '/view/statics/backend/order-accordion.js');
}

private function assert_OrderLineImage_testOrderLinesExposeImageUrlFromListingThumb(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $ctrl = (string) file_get_contents($root . '/Controller/Backend/Order.php');
    $js = (string) file_get_contents($root . '/view/statics/backend/order-accordion.js');
    $tpl = (string) file_get_contents($root . '/view/templates/backend/Order/index.phtml');
    $mod = (string) file_get_contents($root . '/view/statics/backend/weline.modules.js');
    self::assertStringContainsString('enrichLinesWithImages', $ctrl);
    self::assertStringContainsString('DropshipListing', $ctrl);
    self::assertStringContainsString("'image_url'", $ctrl);
    self::assertStringContainsString('schema_fields_THUMB_URL', $ctrl);
    self::assertStringContainsString('ds-order-line__thumb', $js);
    self::assertStringContainsString('ds-order-line__thumb-ph', $js);
    self::assertStringContainsString('dropship-order-line-thumb', $js);
    self::assertStringContainsString('renderLines: renderLines', $js);
    self::assertStringContainsString('ds-order-line__thumb', $tpl);
    self::assertStringContainsString('ds-order-line__product', $tpl);
    self::assertStringContainsString('--weline-space-2', $tpl);
    self::assertStringContainsString('order-accordion.js?v=1.0.69', $mod);
    self::assertStringContainsString('order-accordion.js?v=1.0.69', $tpl);
}

private function assert_WarehouseIndexTemplate_testWarehouseIndexUsesProviderCoverageBoardVariantA(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $path = $root . '/view/templates/backend/Warehouse/index.phtml';
    self::assertFileExists($path);
    $tpl = (string) file_get_contents($path);
    self::assertStringContainsString('data-variant="A"', $tpl);
    self::assertStringContainsString('data-testid="dropship-wh-rail"', $tpl);
    self::assertStringContainsString('--weline-theme-primary-surface', $tpl);
    self::assertStringContainsString('a[aria-current="true"]', $tpl);
    self::assertStringContainsString('background:var(--weline-theme-primary)', $tpl);
    self::assertStringContainsString('color:var(--weline-theme-on-primary)', $tpl);
    self::assertStringNotContainsString('rgba(255,255,255,.08)', $tpl);
    self::assertStringContainsString('data-testid="dropship-wh-coverage"', $tpl);
    self::assertStringContainsString('data-testid="dropship-wh-stats"', $tpl);
    self::assertStringContainsString('data-testid="dropship-wh-pagination"', $tpl);
    self::assertStringContainsString('w-pagination', $tpl);
    self::assertStringContainsString('远程履约覆盖', $tpl);
    self::assertStringContainsString('万能货源中心', $tpl);
    self::assertStringContainsString('按国家自动配对', $tpl);
    self::assertStringContainsString('拉取远程仓', $tpl);
    self::assertStringContainsString('w:scope', $tpl);
    self::assertStringContainsString('w:inventory:warehouse:select', $tpl);
    self::assertStringContainsString('options-json', $tpl);
    self::assertStringContainsString('whOptionsJson', $tpl);
    self::assertStringContainsString('localIdValue', $tpl);
    self::assertStringContainsString('data-bind-row', $tpl);
    self::assertStringContainsString('data-save-url', $tpl);
    self::assertStringContainsString('Weline.UI.toast', $tpl);
    self::assertStringNotContainsString('data-testid="dropship-wh-bind-panel"', $tpl);
    self::assertStringNotContainsString('保存映射', $tpl);
    self::assertStringNotContainsString('w:theme:address', $tpl);
    self::assertStringNotContainsString('w:dropship:remote-warehouse:select', $tpl);
    self::assertStringNotContainsString('window.alert(', $tpl);
    self::assertStringNotContainsString('已有映射', $tpl);
    self::assertStringNotContainsString('data-testid="dropship-wh-table"', $tpl);
}

private function assert_WarehouseIndexTemplate_testWarehouseControllerBuildsCoverageBoard(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $ctrl = (string) file_get_contents($root . '/Controller/Backend/Warehouse.php');
    self::assertStringContainsString('coverageBoard', $ctrl);
    self::assertStringContainsString('providerSummaries', $ctrl);
    self::assertStringContainsString('selected_provider', $ctrl);
    self::assertStringContainsString('coverage_ready', $ctrl);
    self::assertStringContainsString('货源履约仓对接', $ctrl);
    self::assertStringContainsString('postSyncCountryPairs', $ctrl);
    self::assertStringContainsString('resolveWorkScope', $ctrl);
    self::assertStringContainsString('resolveMapStoreId', $ctrl);
    self::assertStringContainsString('default.__website__.default', $ctrl);
    self::assertStringContainsString('websiteId === null', $ctrl);
    self::assertStringContainsString('page_size', $ctrl);
    self::assertStringContainsString('wantsJsonResponse', $ctrl);
    self::assertStringContainsString('assign(\'pagination\'', $ctrl);
    self::assertStringNotContainsString('assign(\'maps\'', $ctrl);
}

private function assert_WarehouseMapIaPrototype_testIaPrototypeHasThreeVariantsAndRecommendsProviderCoverage(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/view/prototypes/warehouse-map-ia.html';
    self::assertFileExists($path);
    $html = (string) file_get_contents($path);
    self::assertStringContainsString('PROTOTYPE', $html);
    self::assertStringContainsString('variant=A', $html);
    self::assertStringContainsString('variant=B', $html);
    self::assertStringContainsString('variant=C', $html);
    self::assertStringContainsString('货源覆盖', $html);
    self::assertStringContainsString('推荐 A', $html);
    self::assertStringContainsString('万能货源', $html);
    self::assertStringContainsString('远程履约原点', $html);
}
}
