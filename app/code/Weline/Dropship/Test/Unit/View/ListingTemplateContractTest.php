<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: 货源商品页选品三栏（能力门控），禁止强制 SKU / 供应商私有键。
 */
final class ListingTemplateContractTest extends TestCase
{
    public function testListingIndexUsesTabsForListedAndPick(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = (string)file_get_contents($root . '/view/templates/Backend/Listing/index.phtml');
        $ctrl = (string)file_get_contents($root . '/Controller/Backend/Listing.php');

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
        self::assertStringContainsString('getLocalDetail', $ctrl);
        self::assertStringContainsString('DropshipListedLocalDetailService', $ctrl);
        self::assertStringContainsString('data-dropship-listed-accordion="1"', $tpl);
        self::assertStringContainsString('data-weline-load="dropshipListedAccordion"', $tpl);
        self::assertStringContainsString('data-listed-expandable', $tpl);
        self::assertStringContainsString('data-listed-expand-toggle', $tpl);
        self::assertStringContainsString('data-listed-expand-label', $tpl);
        self::assertStringContainsString('role="button"', $tpl);
        self::assertStringContainsString('ensureListedAccordion', $tpl);
        self::assertStringContainsString('listed-accordion.js?v=1.0.64', $tpl);
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
        $draftSrc = (string)file_get_contents($root . '/Service/DropshipListingDraftService.php');
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

    public function testLegacySearchActionRedirectsToIndexTab(): void
    {
        $ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Listing.php');
        self::assertStringContainsString('dropship/backend/listing/index', $ctrl);
        self::assertMatchesRegularExpression("/function\\s+search\\s*\\(/", $ctrl);
        self::assertStringContainsString("'tab' => 'pick'", $ctrl);
    }

    public function testPrototypePickerExists(): void
    {
        $path = dirname(__DIR__, 3) . '/view/prototype/listing-picker.html';
        self::assertFileExists($path);
        $html = (string)file_get_contents($path);
        self::assertStringContainsString('PROTOTYPE', $html);
        self::assertStringContainsString('no_browse', $html);
        self::assertStringContainsString('browse_country_filter', $html);
        self::assertStringContainsString('toggle-filters', $html);
        self::assertStringContainsString('thumb', $html);
        self::assertStringContainsString('data-filters-collapsed', $html);
    }
}
