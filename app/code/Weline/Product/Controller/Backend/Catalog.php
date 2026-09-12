<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Api\ProductAdminReadInterface;
use Weline\Product\Service\ProductAdminMutationService;
use Weline\Product\Service\ProductAdminViewService;
use Weline\Product\Service\ProductBrandAdminService;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductSiteContentAdminService;
use Weline\Product\Service\ProductSupplierAdminService;

final class Catalog extends BackendController
{
    private const TITLES = [
        'products' => '万能产品',
        'offers' => '销售规格（高级维护）',
        'sku-registry' => 'SKU 身份（高级维护）',
        'categories' => '商品分类',
        'brands' => '品牌管理',
        'suppliers' => '供应商管理',
        'media' => '商品媒体',
        'site-content' => '站点文案',
        'store-copy' => '网站迁移与复制',
        'shards' => '商品分片',
    ];

    private const ROUTES = [
        'products' => 'products',
        'offers' => 'offers',
        'sku-registry' => 'skuRegistry',
        'categories' => 'categories',
        'brands' => 'brands',
        'suppliers' => 'suppliers',
        'media' => 'media',
        'site-content' => 'siteContent',
    ];

    public function __construct(
        private readonly ProductAdminReadInterface $productAdminRead,
        private readonly ProductAdminCommandInterface $productAdminCommands,
        private readonly ProductAdminViewService $legacyView,
        private readonly ProductAdminMutationService $legacyMutations,
        private readonly ProductSiteContentAdminService $siteContent,
        private readonly ProductCategoryAdminService $categoryAdmin,
        private readonly ProductBrandAdminService $brandAdmin,
        private readonly ProductSupplierAdminService $supplierAdmin,
    ) {
    }

    /**
     * Match <w:form csrf="auto"> which emits hidden input name="csrf" via Token::create().
     * Returning form_key here made every Catalog POST without form_key hit noRouter(404).
     */
    protected function csrf(): string
    {
        return 'csrf';
    }

    #[Acl('Weline_Product::commerce:catalog:products', '万能产品管理', 'box', '查看和运营万能产品目录')]
    public function products(): string
    {
        $websiteId = max(0, (int)$this->request->getGet('website_id', 0));
        $filters = $this->productFilters();
        $error = '';
        $context = ['product_types' => [], 'stores' => [], 'default_store_ids' => []];
        $rows = [];
        $categoryBulkOptions = [];
        try {
            $context = $this->productAdminRead->creationContext($websiteId);
            $rows = $this->productAdminRead->search($websiteId, $filters);
            $categoryBulkOptions = $this->flattenCategoryTree(
                $this->categoryAdmin->tree($websiteId, (string)State::getLangLocal()),
            );
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setCode(503);
            $error = (string)__('商品目录读取失败：%{1}', [$exception->getMessage()]);
        }

        $sourcePlatformOptions = [];
        $optionFilters = $filters;
        unset($optionFilters['source'], $optionFilters['source_platform']);
        $optionRows = $rows;
        if ($optionFilters !== $filters && $error === '') {
            try {
                $optionRows = $this->productAdminRead->search($websiteId, $optionFilters);
            } catch (\Throwable) {
                $optionRows = $rows;
            }
        }
        foreach ($optionRows as $row) {
            $platform = strtolower(trim((string)($row['source_platform'] ?? '')));
            if ($platform !== '') {
                $sourcePlatformOptions[$platform] = strtoupper($platform);
            }
        }
        $selectedSource = strtolower(trim((string)($filters['source'] ?? $filters['source_platform'] ?? '')));
        if ($selectedSource !== '' && $selectedSource !== '__none__' && $selectedSource !== 'none'
            && !isset($sourcePlatformOptions[$selectedSource])
        ) {
            $sourcePlatformOptions[$selectedSource] = strtoupper($selectedSource);
        }
        ksort($sourcePlatformOptions);

        $this->assignCommon('products', $websiteId, $error);
        $this->assign('filters', $filters);
        $this->assign('source_platform_options', $sourcePlatformOptions);
        $this->assign('creation_context', $context);
        $this->assign('rows', $rows);
        $this->assign('category_bulk_options', $categoryBulkOptions);
        $this->assign('columns', []);
        $this->assign('product_admin_state_json', $this->json([
            'provider' => 'product_admin',
            'website_id' => $websiteId,
            'creation_context' => $context,
            'items' => $rows,
            'edit_url' => (string)$this->request->getUrlBuilder()->getUrl('*/backend/catalog/edit-product'),
        ]));

        return (string)$this->fetch('index');
    }

    #[Acl('Weline_Product::commerce:catalog:offers', '销售规格高级维护', 'tag', '查看销售规格底层投影')]
    public function offers(): string
    {
        return $this->renderLegacySection('offers');
    }

    #[Acl('Weline_Product::commerce:catalog:sku-registry', 'SKU 身份高级维护', 'code', '查看 SKU 全局身份')]
    public function skuRegistry(): string
    {
        return $this->renderLegacySection('sku-registry');
    }

    #[Acl('Weline_Product::commerce:catalog:categories', '商品分类', 'tree', '查看商品分类')]
    public function categories(): string
    {
        $params = [
            'space' => 'product',
            'scope_level' => 'website',
            'website_id' => max(0, (int)$this->request->getGet('website_id', 0)),
        ];
        foreach (['id', 'new', 'pid', 'category_id', 'parent_id'] as $key) {
            $value = $this->request->getGet($key);
            if ($value === null || $value === '') {
                continue;
            }
            if ($key === 'category_id') {
                $params['id'] = max(0, (int)$value);
                continue;
            }
            if ($key === 'parent_id') {
                $params['pid'] = max(0, (int)$value);
                continue;
            }
            $params[$key] = $value;
        }

        return (string)$this->redirect('weline_catalog/backend/category/index', $params);
    }

    #[Acl('Weline_Product::commerce:catalog:brands', '品牌管理', 'tag', '管理商品品牌目录')]
    public function brands(): string
    {
        $websiteId = max(0, (int)$this->request->getGet('website_id', 0));
        $editingId = max(0, (int)$this->request->getGet('id', 0));
        $error = '';
        $rows = [];
        $editing = [
            'brand_id' => 0,
            'code' => '',
            'name' => '',
            'logo_url' => '',
            'logo_asset_id' => '',
            'description' => '',
            'status' => 'active',
            'position' => 0,
            'supplier_ids' => [],
            'suppliers' => [],
        ];
        $supplierOptions = [];
        try {
            $rows = $this->brandAdmin->list($websiteId);
            $supplierOptions = $this->supplierAdmin->catalogOptions($websiteId);
            if ($editingId > 0) {
                $editing = $this->brandAdmin->get($websiteId, $editingId);
            }
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setCode(503);
            $error = (string)__('品牌目录读取失败：%{1}', [$exception->getMessage()]);
        }

        $this->assignCommon('brands', $websiteId, $error);
        $this->assign('rows', $rows);
        $this->assign('editing', $editing);
        $this->assign('supplier_options', $supplierOptions);
        $this->assign('columns', []);

        return (string)$this->fetch('brands');
    }

    #[Acl(
        'Weline_Product::commerce:catalog:brands:save',
        '保存品牌',
        'save',
        '创建或更新商品品牌',
        'Weline_Product::commerce:catalog:brands'
    )]
    public function postSaveBrand(): string
    {
        $websiteId = max(0, (int)$this->request->getPost('website_id', 0));
        try {
            $saved = $this->brandAdmin->save($websiteId, [
                'brand_id' => (int)$this->request->getPost('brand_id', 0),
                'name' => (string)$this->request->getPost('name', ''),
                'code' => (string)$this->request->getPost('code', ''),
                'logo_url' => (string)$this->request->getPost('logo_url', ''),
                'logo_asset_id' => (string)$this->request->getPost('logo_asset_id', ''),
                'description' => (string)$this->request->getPost('description', ''),
                'status' => (string)$this->request->getPost('status', 'active'),
                'position' => (int)$this->request->getPost('position', 0),
                'supplier_ids' => $this->request->getPost('supplier_ids', []),
            ]);
            $this->getMessageManager()->addSuccess(__('品牌已保存：%{1}', [(string)$saved['name']]));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('保存品牌失败：%{1}', [$exception->getMessage()]));
        }

        return (string)$this->redirect('*/backend/catalog/brands?website_id=' . $websiteId);
    }

    #[Acl(
        'Weline_Product::commerce:catalog:brands:disable',
        '停用品牌',
        'ban',
        '停用商品品牌（软下架）',
        'Weline_Product::commerce:catalog:brands'
    )]
    public function postDisableBrand(): string
    {
        $websiteId = max(0, (int)$this->request->getPost('website_id', 0));
        $brandId = max(0, (int)$this->request->getPost('brand_id', 0));
        try {
            $this->brandAdmin->disable($websiteId, $brandId);
            $this->getMessageManager()->addSuccess(__('品牌已停用'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('停用品牌失败：%{1}', [$exception->getMessage()]));
        }

        return (string)$this->redirect('*/backend/catalog/brands?website_id=' . $websiteId);
    }

    #[Acl('Weline_Product::commerce:catalog:suppliers', '供应商管理', 'truck', '管理商品供应商目录')]
    public function suppliers(): string
    {
        $websiteId = max(0, (int)$this->request->getGet('website_id', 0));
        $editingId = max(0, (int)$this->request->getGet('id', 0));
        $error = '';
        $rows = [];
        $editing = [
            'supplier_id' => 0,
            'code' => '',
            'name' => '',
            'store_url' => '',
            'image_url' => '',
            'image_asset_id' => '',
            'contact_name' => '',
            'contact_phone' => '',
            'contact_email' => '',
            'default_currency' => '',
            'default_payment_terms' => '',
            'default_lead_time_days' => null,
            'default_moq' => null,
            'description' => '',
            'status' => 'active',
            'position' => 0,
            'brand_ids' => [],
            'brands' => [],
        ];
        $brandOptions = [];
        try {
            $rows = $this->supplierAdmin->list($websiteId);
            $brandOptions = $this->brandAdmin->catalogOptions($websiteId);
            if ($editingId > 0) {
                $editing = $this->supplierAdmin->get($websiteId, $editingId);
            }
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setCode(503);
            $error = (string)__('供应商目录读取失败：%{1}', [$exception->getMessage()]);
        }

        $this->assignCommon('suppliers', $websiteId, $error);
        $this->assign('rows', $rows);
        $this->assign('editing', $editing);
        $this->assign('brand_options', $brandOptions);
        $this->assign('columns', []);

        return (string)$this->fetch('suppliers');
    }

    #[Acl(
        'Weline_Product::commerce:catalog:suppliers:save',
        '保存供应商',
        'save',
        '创建或更新商品供应商',
        'Weline_Product::commerce:catalog:suppliers'
    )]
    public function postSaveSupplier(): string
    {
        $websiteId = max(0, (int)$this->request->getPost('website_id', 0));
        try {
            $saved = $this->supplierAdmin->save($websiteId, [
                'supplier_id' => (int)$this->request->getPost('supplier_id', 0),
                'name' => (string)$this->request->getPost('name', ''),
                'code' => (string)$this->request->getPost('code', ''),
                'store_url' => (string)$this->request->getPost('store_url', ''),
                'image_url' => (string)$this->request->getPost('image_url', ''),
                'image_asset_id' => (string)$this->request->getPost('image_asset_id', ''),
                'contact_name' => (string)$this->request->getPost('contact_name', ''),
                'contact_phone' => (string)$this->request->getPost('contact_phone', ''),
                'contact_email' => (string)$this->request->getPost('contact_email', ''),
                'default_currency' => (string)$this->request->getPost('default_currency', ''),
                'default_payment_terms' => (string)$this->request->getPost('default_payment_terms', ''),
                'default_lead_time_days' => $this->request->getPost('default_lead_time_days', ''),
                'default_moq' => $this->request->getPost('default_moq', ''),
                'description' => (string)$this->request->getPost('description', ''),
                'status' => (string)$this->request->getPost('status', 'active'),
                'position' => (int)$this->request->getPost('position', 0),
                'brand_ids' => $this->request->getPost('brand_ids', []),
            ]);
            $this->getMessageManager()->addSuccess(__('供应商已保存：%{1}', [(string)$saved['name']]));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('保存供应商失败：%{1}', [$exception->getMessage()]));
        }

        return (string)$this->redirect('*/backend/catalog/suppliers?website_id=' . $websiteId);
    }

    #[Acl(
        'Weline_Product::commerce:catalog:suppliers:disable',
        '停用供应商',
        'ban',
        '停用商品供应商（软下架）',
        'Weline_Product::commerce:catalog:suppliers'
    )]
    public function postDisableSupplier(): string
    {
        $websiteId = max(0, (int)$this->request->getPost('website_id', 0));
        $supplierId = max(0, (int)$this->request->getPost('supplier_id', 0));
        try {
            $this->supplierAdmin->disable($websiteId, $supplierId);
            $this->getMessageManager()->addSuccess(__('供应商已停用'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('停用供应商失败：%{1}', [$exception->getMessage()]));
        }

        return (string)$this->redirect('*/backend/catalog/suppliers?website_id=' . $websiteId);
    }

    #[Acl('Weline_Product::commerce:catalog:media', '商品媒体', 'image', '查看商品媒体')]
    public function media(): string
    {
        return $this->renderLegacySection('media');
    }

    #[Acl('Weline_Product::commerce:catalog:site-content', '站点文案', 'language', '管理商品站点与 Store View 文案')]
    public function siteContent(): string
    {
        return $this->renderLegacySection('site-content');
    }

    #[Acl('Weline_Product::commerce:catalog:store-copy', '网站迁移与复制', 'copy', '查看网站迁移与复制任务')]
    public function storeCopy(): string
    {
        return $this->renderLegacySection('store-copy');
    }

    #[Acl('Weline_Product::commerce:catalog:shards', '商品分片', 'settings', '查看商品分片状态')]
    public function shards(): string
    {
        return $this->renderLegacySection('shards');
    }

    #[Acl('Weline_Product::commerce:catalog:products', '编辑万能产品', 'edit', '编辑产品聚合')]
    public function editProduct(): string
    {
        $websiteId = max(0, (int)$this->request->getGet('website_id', 0));
        $uuid = trim((string)$this->request->getGet('global_product_uuid', ''));
        if ($uuid === '') {
            $productId = max(0, (int)$this->request->getGet('product_id', 0));
            if ($productId > 0) {
                try {
                    $legacy = $this->legacyView->loadProductEdit($websiteId, $productId);
                    $uuid = trim((string)($legacy['product']['global_product_uuid'] ?? ''));
                } catch (\Throwable) {
                    $uuid = '';
                }
            }
        }
        if ($uuid === '') {
            $this->getMessageManager()->addError(__('请选择要编辑的商品'));
            return (string)$this->redirect('*/backend/catalog/products?website_id=' . $websiteId);
        }

        try {
            $snapshot = $this->productAdminRead->snapshot(
                $websiteId,
                $uuid,
                null,
                (string)$this->request->getGet('locale', ''),
                (string)$this->request->getGet('currency', 'CNY'),
            )->toArray();
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('编辑商品失败：%{1}', [$exception->getMessage()]));
            return (string)$this->redirect('*/backend/catalog/products?website_id=' . $websiteId);
        }

        $websiteOptions = $this->loadWebsiteOptions();
        $this->assign('website_id', $websiteId);
        $this->assign('global_product_uuid', $uuid);
        $this->assign('snapshot', $snapshot);
        $this->assign('website_options', $websiteOptions);
        $this->assign('product_admin_state_json', $this->json([
            'provider' => 'product_admin',
            'website_id' => $websiteId,
            'snapshot' => $snapshot,
            'website_options' => $websiteOptions,
            'list_url' => (string)$this->request->getUrlBuilder()->getUrl('*/backend/catalog/products'),
        ]));

        return (string)$this->fetch('edit');
    }

    #[Acl('Weline_Product::commerce:catalog:products', '创建万能产品', 'plus', '创建真实商品草稿')]
    public function postCreateProduct(): string
    {
        $websiteId = max(0, (int)$this->request->getPost('website_id', 0));
        try {
            $productType = $this->postString('product_type', 64);
            $payload = [
                'name' => $this->postString('name', 255),
                'product_type' => $productType,
                'sku' => $this->postString('sku', 128),
                'currency' => strtoupper(trim((string)$this->request->getPost('currency', 'CNY'))),
                'store_ids' => $this->postIntList('store_ids'),
            ];
            if ($productType === 'configurable') {
                $payload['sku_prefix'] = $payload['sku'];
                $payload['axes'] = $this->postJsonArray('axes_json');
            }
            $result = $this->productAdminCommands->execute(new ProductAdminCommand(
                action: ProductAdminCommand::ACTION_CREATE,
                websiteId: $websiteId,
                globalProductUuid: null,
                expectedVersion: null,
                requestHash: $this->postRequestHash(),
                actorId: 0,
                payload: $payload,
            ));
            if (!$result->success) {
                throw new \RuntimeException($result->errorCode . ': ' . $result->message);
            }
            $uuid = (string)($result->data['identity']['global_product_uuid'] ?? '');
            $this->getMessageManager()->addSuccess(__('商品草稿已创建'));
            return (string)$this->redirect(
                '*/backend/catalog/edit-product?website_id=' . $websiteId
                . '&global_product_uuid=' . rawurlencode($uuid),
            );
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('创建商品失败：%{1}', [$exception->getMessage()]));
            return (string)$this->redirect('*/backend/catalog/products?website_id=' . $websiteId);
        }
    }

    #[Acl('Weline_Product::commerce:catalog:products', '保存万能产品', 'save', '保存商品聚合')]
    public function postSaveProduct(): string
    {
        return $this->executeProductCommand(ProductAdminCommand::ACTION_SAVE);
    }

    #[Acl('Weline_Product::commerce:catalog:products', '执行万能产品命令', 'play', '校验、发布、下架或归档商品')]
    public function postProductCommand(): string
    {
        $action = strtolower(trim((string)$this->request->getPost('action', '')));
        if (!in_array($action, [
            ProductAdminCommand::ACTION_VALIDATE,
            ProductAdminCommand::ACTION_PUBLISH,
            ProductAdminCommand::ACTION_DISABLE,
            ProductAdminCommand::ACTION_ARCHIVE,
        ], true)) {
            $action = ProductAdminCommand::ACTION_VALIDATE;
        }
        return $this->executeProductCommand($action);
    }

    #[Acl('Weline_Product::commerce:catalog:sku-registry', '注册 SKU', 'code', '注册 SKU 全局身份')]
    public function postRegisterSku(): string
    {
        return $this->handleLegacyMutation(
            'sku-registry',
            function (int $websiteId): void {
                $this->legacyMutations->registerSku(
                    $this->postString('sku', 128),
                    $this->postString('request_hash', 128),
                    $websiteId,
                );
            },
            'SKU 已注册',
        );
    }

    #[Acl('Weline_Product::commerce:catalog:offers', '创建销售规格', 'plus', '创建底层 Offer 投影')]
    public function postCreateOffer(): string
    {
        return $this->handleLegacyMutation(
            'offers',
            fn(int $websiteId) => $this->legacyMutations->createOffer(
                $websiteId,
                $this->postString('sku', 128),
            ),
            '销售规格已创建',
        );
    }

    #[Acl('Weline_Product::commerce:catalog:categories', '创建商品分类', 'tree', '创建商品分类')]
    public function postCreateCategory(): string
    {
        return $this->handleLegacyMutation(
            'categories',
            fn(int $websiteId) => $this->legacyMutations->createCategory(
                $websiteId,
                $this->postString('path', 255),
                $this->postNonNegativeInt('parent_id', 0),
            ),
            '商品分类已创建',
        );
    }

    #[Acl('Weline_Product::commerce:catalog:media', '创建商品媒体', 'plus', '创建商品媒体')]
    public function postCreateMedia(): string
    {
        return $this->handleLegacyMutation(
            'media',
            fn(int $websiteId) => $this->legacyMutations->createMedia(
                $websiteId,
                $this->postString('sku', 128),
                $this->postString('path', 255),
                $this->postString('blob_key', 255),
                $this->postNonNegativeInt('position', 0),
            ),
            '商品媒体已创建',
        );
    }

    #[Acl('Weline_Product::commerce:catalog:site-content', '保存站点文案', 'save', '保存商品站点与 Store View 文案')]
    public function postSaveSiteContent(): string
    {
        $websiteId = 0;
        $storeId = 0;
        $entityId = 0;
        try {
            $websiteId = $this->postNonNegativeInt('website_id', 0);
            $storeId = $this->postNonNegativeInt('store_id', 0);
            $entityId = $this->postNonNegativeInt('entity_id', 0);
            $this->siteContent->save(
                $websiteId,
                $storeId,
                $entityId,
                $this->postString('attribute_code', 64),
                trim((string)$this->request->getPost('locale', '')),
                $this->postString('value_text', 65535),
                (string)$this->request->getPost('is_required', '') === '1',
            );
            $this->getMessageManager()->addSuccess(__('站点文案已保存'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('操作失败：%{1}', [$exception->getMessage()]));
        }
        return (string)$this->redirect(
            '*/backend/catalog/siteContent?website_id=' . $websiteId
            . '&store_id=' . $storeId . '&entity_id=' . $entityId,
        );
    }

    private function executeProductCommand(string $action): string
    {
        $websiteId = max(0, (int)$this->request->getPost('website_id', 0));
        $uuid = trim((string)$this->request->getPost('global_product_uuid', ''));
        try {
            $payload = [
                'local_version' => $this->postNonNegativeInt('local_version', 0),
                'locale' => trim((string)$this->request->getPost('locale', '')),
                'currency' => strtoupper(trim((string)$this->request->getPost('currency', 'CNY'))),
            ];
            if ($action === ProductAdminCommand::ACTION_SAVE) {
                $payload['name'] = $this->postString('name', 255);
                $payload['store_ids'] = $this->postIntList('store_ids');
            }
            $result = $this->productAdminCommands->execute(new ProductAdminCommand(
                action: $action,
                websiteId: $websiteId,
                globalProductUuid: $uuid,
                expectedVersion: $this->postNonNegativeInt('expected_version', 0),
                requestHash: $this->postRequestHash(),
                actorId: 0,
                payload: $payload,
            ));
            if (!$result->success) {
                throw new \RuntimeException($result->errorCode . ': ' . $result->message);
            }
            $this->getMessageManager()->addSuccess(__('商品操作已完成'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('商品操作失败：%{1}', [$exception->getMessage()]));
        }
        return (string)$this->redirect(
            '*/backend/catalog/edit-product?website_id=' . $websiteId
            . '&global_product_uuid=' . rawurlencode($uuid),
        );
    }

    private function renderLegacySection(string $section): string
    {
        $websiteId = max(0, (int)$this->request->getGet('website_id', 0));
        $storeId = max(0, (int)$this->request->getGet('store_id', 0));
        $entityId = max(0, (int)$this->request->getGet('entity_id', 0));
        $rows = [];
        $columns = [];
        $error = '';
        try {
            $result = $section === 'site-content'
                ? $this->siteContent->load($websiteId, $storeId, $entityId)
                : $this->legacyView->load($section, $websiteId);
            $rows = $result['rows'];
            $columns = $result['columns'];
        } catch (\Throwable) {
            $this->request->getResponse()->setCode(503);
            $error = (string)__('数据读取失败，请检查商品模块状态与数据库连接');
        }
        $this->assignCommon($section, $websiteId, $error);
        $this->assign('store_id', $storeId);
        $this->assign('entity_id', $entityId);
        $this->assign('rows', $rows);
        $this->assign('columns', $columns);
        $this->assign('filters', []);
        $this->assign('creation_context', []);
        $this->assign('product_admin_state_json', '{}');

        return (string)$this->fetch('index');
    }

    private function assignCommon(string $section, int $websiteId, string $error): void
    {
        $this->assign('title', __(self::TITLES[$section]));
        $this->assign('section', $section);
        $this->assign('section_route', self::ROUTES[$section] ?? $section);
        $this->assign('website_id', $websiteId);
        $this->assign('error', $error);
        $websites = $this->loadWebsiteOptions();
        $this->assign('websiteOptionsJson', $this->json($websites));
        $this->assign('productWebsiteSelectValue', (string)$websiteId);
        $this->assign('productWebsiteSelectDisplay', (string)$websiteId);
    }

    /** @return array<string,mixed> */
    private function productFilters(): array
    {
        $filters = [];
        foreach (['name', 'sku', 'product_code', 'product_type', 'status', 'source', 'source_platform'] as $field) {
            $value = trim((string)$this->request->getGet($field, ''));
            if ($value !== '') {
                $filters[$field] = $value;
            }
        }
        if (isset($filters['source_platform']) && !isset($filters['source'])) {
            $filters['source'] = (string)$filters['source_platform'];
        }
        $storeId = max(0, (int)$this->request->getGet('store_id', 0));
        if ($storeId > 0) {
            $filters['store_id'] = $storeId;
        }
        $owner = trim((string)$this->request->getGet('owner_website_id', ''));
        if ($owner !== '' && ctype_digit($owner)) {
            $filters['owner_website_id'] = (int)$owner;
        }
        return $filters;
    }

    private function handleLegacyMutation(string $section, callable $mutation, string $success): string
    {
        $websiteId = 0;
        try {
            $websiteId = $this->postNonNegativeInt('website_id', 0);
            $mutation($websiteId);
            $this->getMessageManager()->addSuccess(__($success));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('操作失败：%{1}', [$exception->getMessage()]));
        }
        return (string)$this->redirect(
            '*/backend/catalog/' . self::ROUTES[$section] . '?website_id=' . $websiteId,
        );
    }

    private function postString(string $key, int $maxLength): string
    {
        $value = trim((string)$this->request->getPost($key, ''));
        if ($value === '' || strlen($value) > $maxLength) {
            throw new \InvalidArgumentException((string)__('%{1} 不能为空且最多 %{2} 字符', [$key, $maxLength]));
        }
        return $value;
    }

    private function postNonNegativeInt(string $key, int $default): int
    {
        $raw = trim((string)$this->request->getPost($key, (string)$default));
        if ($raw === '' || !ctype_digit($raw)) {
            throw new \InvalidArgumentException((string)__('%{1} 必须是非负整数', [$key]));
        }
        return (int)$raw;
    }

    /** @return list<int> */
    private function postIntList(string $key): array
    {
        $raw = $this->request->getPost($key, []);
        if (!is_array($raw)) {
            $raw = $raw === '' ? [] : [$raw];
        }
        $ids = [];
        foreach ($raw as $value) {
            $value = trim((string)$value);
            if ($value !== '' && ctype_digit($value) && (int)$value > 0) {
                $ids[(int)$value] = true;
            }
        }
        $result = array_keys($ids);
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function postJsonArray(string $key): array
    {
        $raw = trim((string)$this->request->getPost($key, ''));
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('product_axes_json_invalid');
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \InvalidArgumentException('product_axes_json_invalid');
        }
        return $decoded;
    }

    private function postRequestHash(): string
    {
        $hash = strtolower(trim((string)$this->request->getPost('request_hash', '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new \InvalidArgumentException('product_admin_request_hash_invalid');
        }
        return $hash;
    }

    /** @return list<array<string,mixed>> */
    private function loadWebsiteOptions(): array
    {
        try {
            $rows = w_query('websites', 'getWebsiteList', []);
        } catch (\Throwable) {
            $rows = [];
        }
        $options = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $websiteId = (int)($row['website_id'] ?? $row['id'] ?? 0);
            $code = trim((string)($row['code'] ?? $row['website_code'] ?? ''));
            $name = trim((string)($row['name'] ?? $code));
            if ($websiteId < 0 || ($websiteId === 0 && $code === '')) {
                continue;
            }
            $options[] = [
                'website_id' => $websiteId,
                'code' => $code !== '' ? $code : 'default',
                'name' => $name !== '' ? $name : ($code !== '' ? $code : 'default'),
                'url' => trim((string)($row['url'] ?? '')),
                'label' => ($name !== '' ? $name : ($code !== '' ? $code : 'default'))
                    . ' / ' . ($code !== '' ? $code : 'default'),
            ];
        }
        $hasDefault = false;
        foreach ($options as $option) {
            $hasDefault = $hasDefault || (int)$option['website_id'] === 0;
        }
        if (!$hasDefault) {
            array_unshift($options, [
                'website_id' => 0,
                'code' => 'default',
                'name' => 'default',
                'url' => '',
                'label' => 'default / default',
            ]);
        }
        return $options;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array{category_id:int,name:string,path:string,depth:int}>
     */
    private function flattenCategoryTree(array $nodes, int $depth = 0): array
    {
        $flat = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $categoryId = (int)($node['category_id'] ?? 0);
            if ($categoryId > 0) {
                $flat[] = [
                    'category_id' => $categoryId,
                    'name' => (string)($node['name'] ?? $node['path'] ?? ('#' . $categoryId)),
                    'path' => (string)($node['path'] ?? ''),
                    'depth' => $depth,
                ];
            }
            $children = is_array($node['nodes'] ?? null) ? $node['nodes'] : [];
            if ($children !== []) {
                array_push($flat, ...$this->flattenCategoryTree($children, $depth + 1));
            }
        }

        return $flat;
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );
    }
}
