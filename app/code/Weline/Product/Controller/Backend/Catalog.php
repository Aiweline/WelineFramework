<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Product\Service\ProductAdminMutationService;
use Weline\Product\Service\ProductAdminViewService;
use Weline\Product\Service\ProductSiteContentAdminService;
use Weline\Product\Repository\ProductRepository;

final class Catalog extends BackendController
{
    private const TITLES = [
        'products' => '商品',
        'offers' => '销售报价',
        'sku-registry' => 'SKU 注册表',
        'categories' => '商品分类',
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
        'media' => 'media',
        'site-content' => 'siteContent',
    ];

    public function __construct(
        private readonly ProductAdminViewService $adminView,
        private readonly ProductAdminMutationService $mutations,
        private readonly ProductSiteContentAdminService $siteContent,
        private readonly ProductRepository $products,
    ) {
    }

    #[Acl('Weline_Product::commerce:catalog:products', '商品管理', 'box', '查看商品目录')]
    public function products(): string
    {
        return $this->renderSection('products');
    }

    #[Acl('Weline_Product::commerce:catalog:offers', '销售报价', 'tag', '查看销售报价')]
    public function offers(): string
    {
        return $this->renderSection('offers');
    }

    #[Acl('Weline_Product::commerce:catalog:sku-registry', 'SKU 注册表', 'code', '查看 SKU 全局身份')]
    public function skuRegistry(): string
    {
        return $this->renderSection('sku-registry');
    }

    #[Acl('Weline_Product::commerce:catalog:categories', '商品分类', 'tree', '查看商品分类')]
    public function categories(): string
    {
        return $this->renderSection('categories');
    }

    #[Acl('Weline_Product::commerce:catalog:media', '商品媒体', 'image', '查看商品媒体')]
    public function media(): string
    {
        return $this->renderSection('media');
    }

    #[Acl('Weline_Product::commerce:catalog:site-content', '站点文案', 'language', '管理商品站点与 Store View 文案')]
    public function siteContent(): string
    {
        return $this->renderSection('site-content');
    }

    #[Acl('Weline_Product::commerce:catalog:store-copy', '网站迁移与复制', 'copy', '查看网站迁移与复制任务')]
    public function storeCopy(): string
    {
        return $this->renderSection('store-copy');
    }

    #[Acl('Weline_Product::commerce:catalog:shards', '商品分片', 'settings', '查看商品分片状态')]
    public function shards(): string
    {
        return $this->renderSection('shards');
    }

    #[Acl('Weline_Product::commerce:catalog:sku-registry', '注册 SKU', 'code', '注册 SKU 全局身份')]
    public function postRegisterSku(): string
    {
        return $this->handleMutation(
            'sku-registry',
            function (int $websiteId): void {
                $this->mutations->registerSku(
                    $this->postString('sku', 128),
                    $this->postString('request_hash', 128),
                );
            },
            'SKU 已注册',
        );
    }

    #[Acl('Weline_Product::commerce:catalog:products', '创建商品', 'plus', '创建商品')]
    public function postCreateProduct(): string
    {
        return $this->handleMutation(
            'products',
            fn (int $websiteId) => $this->mutations->createProduct(
                $websiteId,
                $this->postString('sku', 128),
            ),
            '商品已创建',
        );
    }

    #[Acl('Weline_Product::commerce:catalog:offers', '创建销售报价', 'plus', '创建销售报价')]
    public function postCreateOffer(): string
    {
        return $this->handleMutation(
            'offers',
            fn (int $websiteId) => $this->mutations->createOffer(
                $websiteId,
                $this->postString('sku', 128),
            ),
            '销售报价已创建',
        );
    }

    #[Acl('Weline_Product::commerce:catalog:categories', '创建商品分类', 'tree', '创建商品分类')]
    public function postCreateCategory(): string
    {
        return $this->handleMutation(
            'categories',
            fn (int $websiteId) => $this->mutations->createCategory(
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
        return $this->handleMutation(
            'media',
            fn (int $websiteId) => $this->mutations->createMedia(
                $websiteId,
                $this->postString('sku', 128),
                $this->postString('path', 255),
                $this->postString('blob_key', 255),
                $this->postNonNegativeInt('position', 0),
            ),
            '商品媒体已创建',
        );
    }

    #[Acl('Weline_Product::commerce:catalog:products', '编辑商品', 'edit', '编辑商品')]
    public function editProduct(): string
    {
        $websiteId = max(0, (int)$this->request->getGet('website_id', 0));
        $productId = max(0, (int)$this->request->getGet('product_id', 0));
        if ($productId <= 0) {
            $this->getMessageManager()->addError(__('请选择要编辑的商品'));

            return (string)$this->redirect('*/backend/catalog/products?website_id=' . $websiteId);
        }

        try {
            $product = $this->products->findById($websiteId, $productId);
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('编辑商品失败：%{1}', [$exception->getMessage()]));
            return (string)$this->redirect('*/backend/catalog/products?website_id=' . $websiteId);
        }
        if ($product === null) {
            $this->getMessageManager()->addError(__('商品不存在：%{1}', [$productId]));

            return (string)$this->redirect('*/backend/catalog/products?website_id=' . $websiteId);
        }

        $this->assign('website_id', $websiteId);
        $this->assign('product_id', $productId);
        $this->assign('product_sku', (string)$product->getData('sku'));

        return (string)$this->fetch('edit');
    }

    #[Acl('Weline_Product::commerce:catalog:products', '保存商品', 'save', '保存商品设置')]
    public function postSaveProduct(): string
    {
        $websiteId = 0;
        try {
            $websiteId = $this->postNonNegativeInt('website_id', 0);
            $productId = $this->postPositiveInt('product_id', 0);
            $sku = trim((string)$this->request->getPost('sku', ''));
            $this->mutations->updateProductSku($websiteId, $productId, $sku);
            $this->getMessageManager()->addSuccess(__('商品已更新'));
            return (string)$this->redirect(
                '*/backend/catalog/edit-product?website_id=' . $websiteId . '&product_id=' . $productId,
            );
        } catch (\Throwable $exception) {
            $productId = (int)$this->request->getPost('product_id', 0);
            $this->getMessageManager()->addError(__('操作失败：%{1}', [$exception->getMessage()]));
            return (string)$this->redirect(
                '*/backend/catalog/edit-product?website_id=' . $websiteId . '&product_id=' . $productId,
            );
        }
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
                $this->postString('locale', 32),
                $this->postString('value_text', 65535),
                (string)$this->request->getPost('is_required', '') === '1',
            );
            $this->getMessageManager()->addSuccess(__('站点文案已保存'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('操作失败：%{1}', [$exception->getMessage()]));
        }

        return (string)$this->redirect(
            '*/backend/catalog/siteContent?website_id=' . $websiteId
            . '&store_id=' . $storeId
            . '&entity_id=' . $entityId,
        );
    }

    private function renderSection(string $section): string
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
                : $this->adminView->load($section, $websiteId);
            $rows = $result['rows'];
            $columns = $result['columns'];
        } catch (\Throwable) {
            $this->request->getResponse()->setCode(503);
            $error = (string)__('数据读取失败，请检查商品模块状态与数据库连接');
        }

        $this->assign('title', __(self::TITLES[$section]));
        $this->assign('section', $section);
        $this->assign('section_route', $this->resolveSectionRoute($section));
        $this->assign('website_id', $websiteId);
        $this->assign('store_id', $storeId);
        $this->assign('entity_id', $entityId);
        $this->assign('rows', $rows);
        $this->assign('columns', $columns);
        $this->assign('error', $error);

        $websites = $this->loadWebsiteOptions();
        $this->assign('websiteOptionsJson', json_encode($websites, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
        $this->assign('productWebsiteSelectValue', (string)$websiteId);
        $this->assign('productWebsiteSelectDisplay', (string)$websiteId);

        return (string)$this->fetch('index');
    }

    private function handleMutation(string $section, callable $mutation, string $success): string
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
            throw new \InvalidArgumentException(__('%{1} 不能为空且最多 %{2} 字符', [$key, $maxLength]));
        }

        return $value;
    }

    private function postNonNegativeInt(string $key, int $default): int
    {
        $raw = trim((string)$this->request->getPost($key, (string)$default));
        if ($raw === '' || !ctype_digit($raw)) {
            throw new \InvalidArgumentException(__('%{1} 必须是非负整数', [$key]));
        }

        return (int)$raw;
    }

    private function postPositiveInt(string $key, int $default): int
    {
        $value = $this->postNonNegativeInt($key, $default);
        if ($value <= 0) {
            throw new \InvalidArgumentException(__('%{1} 必须是正整数', [$key]));
        }

        return $value;
    }

    private function resolveSectionRoute(string $section): string
    {
        return self::ROUTES[$section] ?? $section;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadWebsiteOptions(): array
    {
        try {
            $rows = w_query('websites', 'getWebsiteList', []);
        } catch (\Throwable) {
            $rows = [];
        }
        if (!is_array($rows)) {
            $rows = [];
        }

        $options = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $websiteId = (int)($row['website_id'] ?? $row['id'] ?? 0);
            $code = trim((string)($row['code'] ?? $row['website_code'] ?? ''));
            if ($websiteId <= 0 && $code === '') {
                continue;
            }
            $name = trim((string)($row['name'] ?? $code));
            $url = trim((string)($row['url'] ?? ''));
            $options[] = [
                'website_id' => $websiteId,
                'code' => $code !== '' ? $code : 'default',
                'name' => $name !== '' ? $name : ($code !== '' ? $code : 'default'),
                'url' => $url,
                'label' => ($name !== '' ? $name : ($code !== '' ? $code : 'default')) . ' / ' . ($code !== '' ? $code : 'default'),
            ];
        }

        $hasGlobal = false;
        foreach ($options as $option) {
            if ((int)($option['website_id'] ?? -1) === 0) {
                $hasGlobal = true;
                break;
            }
        }

        if (!$hasGlobal) {
            array_unshift($options, [
                'website_id' => 0,
                'code' => 'default',
                'name' => 'default',
                'url' => '',
                'label' => 'default / default',
            ]);
        }

        return $options ?: [[
            'website_id' => 0,
            'code' => 'default',
            'name' => 'default',
            'url' => '',
            'label' => 'default',
        ]];
    }
}
