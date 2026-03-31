<?php

declare(strict_types=1);

namespace WeShop\Price\Controller\Backend;

use WeShop\Price\Service\PriceConfigService;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;

class Price extends BaseController
{
    public function __construct(
        private readonly PriceConfigService $priceConfigService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) ($this->request->getParam('page', 1)));
        $pageSize = max(1, (int) ($this->request->getParam('page_size', 20)));
        $searchQuery = (string) ($this->request->getParam('q', ''));

        $this->assign([
            'title' => (string) __('Price Configuration'),
            'priceIndexUrl' => $this->getUrl('*/backend/price'),
            'priceConfigUrl' => $this->getUrl('*/backend/price/config'),
            'priceCalculateUrl' => $this->getUrl('*/backend/price/calculate'),
        ]);

        try {
            $pageData = $this->priceConfigService->getPageData($page, $pageSize, $searchQuery);
            $this->assign($pageData);
        } catch (\Throwable $throwable) {
            $this->assign([
                'error_message' => $throwable->getMessage(),
                'products' => [],
                'pagination' => [],
            ]);
        }

        return (string) $this->fetchBase('WeShop_Price::templates/Backend/Price/Config/index.phtml');
    }

    public function config(): string
    {
        $productId = (int) ($this->request->getParam('product_id', 0));

        if ($productId <= 0) {
            return $this->redirect($this->getUrl('*/backend/price')) ?? '';
        }

        try {
            /** @var Product $productModel */
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->load($productId);

            if (!$productModel->getId()) {
                $this->getMessageManager()->error(__('Product not found.'));
                return $this->redirect($this->getUrl('*/backend/price')) ?? '';
            }

            $productData = $productModel->getData();
            $priceConfig = $this->priceConfigService->getProductPriceConfig($productId);

            $this->assign([
                'title' => (string) __('Configure Price for %1', [$productData['name'] ?? (string) $productId]),
                'product_id' => $productId,
                'product' => $productData,
                'price_config' => $priceConfig,
                'priceConfigSaveUrl' => $this->getUrl('*/backend/price/save'),
                'priceConfigResetUrl' => $this->getUrl('*/backend/price/reset'),
            ]);

            return (string) $this->fetchBase('WeShop_Price::templates/Backend/Price/Config/edit.phtml');
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->error($throwable->getMessage());
            return $this->redirect($this->getUrl('*/backend/price')) ?? '';
        }
    }

    public function save(): string
    {
        $productId = (int) ($this->request->getParam('product_id', 0));

        if ($productId <= 0) {
            $this->getMessageManager()->error(__('Product ID is required.'));
            return $this->redirect($this->getUrl('*/backend/price')) ?? '';
        }

        try {
            $priceData = [
                'price' => $this->request->getParam('price'),
                'special_price' => $this->request->getParam('special_price'),
                'sale_price' => $this->request->getParam('sale_price'),
                'tier_prices' => $this->parseTierPrices(),
                'customer_prices' => $this->parseCustomerPrices(),
            ];

            $this->priceConfigService->saveProductPriceConfig($productId, $priceData);
            $this->getMessageManager()->success(__('Price configuration saved successfully.'));

            return $this->redirect($this->getUrl('*/backend/price/config', ['product_id' => $productId])) ?? '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->error(__('Failed to save price configuration: %1', [$throwable->getMessage()]));
            return $this->redirect($this->getUrl('*/backend/price/config', ['product_id' => $productId])) ?? '';
        }
    }

    public function calculate(): string
    {
        $productId = (int) ($this->request->getParam('product_id', 0));
        $quantity = max(1, (int) ($this->request->getParam('quantity', 1)));
        $customerIdParam = $this->request->getParam('customer_id');

        $customerId = null;
        if ($customerIdParam !== null && $customerIdParam !== '') {
            $customerId = (int) $customerIdParam;
        }

        if ($productId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Product ID is required.'),
            ]);
        }

        try {
            /** @var PriceService $priceService */
            $priceService = ObjectManager::getInstance(PriceService::class);
            $price = $priceService->calculatePrice($productId, $customerId, $quantity);

            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->load($productId);
            $productData = $productModel->getData();

            $resolvedData = $priceService->resolveProductData($productData, $customerId, $quantity);

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'product_id' => $productId,
                    'price' => $price,
                    'resolved_data' => $resolvedData,
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function reset(): string
    {
        $productId = (int) ($this->request->getParam('product_id', 0));

        if ($productId <= 0) {
            $this->getMessageManager()->error(__('Product ID is required.'));
            return $this->redirect($this->getUrl('*/backend/price')) ?? '';
        }

        try {
            $this->priceConfigService->resetProductPriceConfig($productId);
            $this->getMessageManager()->success(__('Price configuration has been reset to default.'));

            return $this->redirect($this->getUrl('*/backend/price/config', ['product_id' => $productId])) ?? '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->error(__('Failed to reset price configuration: %1', [$throwable->getMessage()]));
            return $this->redirect($this->getUrl('*/backend/price/config', ['product_id' => $productId])) ?? '';
        }
    }

    /**
     * @return array<int, array{qty: int, price: float}>
     */
    private function parseTierPrices(): array
    {
        $tierPrices = [];
        $tierPriceQtys = $this->request->getPost('tier_price_qty', []);
        $tierPriceValues = $this->request->getPost('tier_price_value', []);

        if (!is_array($tierPriceQtys) || !is_array($tierPriceValues)) {
            return [];
        }

        foreach ($tierPriceQtys as $index => $qty) {
            $qtyInt = (int) $qty;
            $value = $tierPriceValues[$index] ?? null;

            if ($qtyInt <= 0 || $value === null || $value === '') {
                continue;
            }

            $price = (float) $value;
            if ($price <= 0) {
                continue;
            }

            $tierPrices[] = [
                'qty' => $qtyInt,
                'price' => $price,
            ];
        }

        return $tierPrices;
    }

    /**
     * @return array<int, array{customer_id: int, price: float}>
     */
    private function parseCustomerPrices(): array
    {
        $customerPrices = [];
        $customerIds = $this->request->getPost('customer_id', []);
        $customerPriceValues = $this->request->getPost('customer_price_value', []);

        if (!is_array($customerIds) || !is_array($customerPriceValues)) {
            return [];
        }

        foreach ($customerIds as $index => $customerId) {
            $customerIdInt = (int) $customerId;
            $value = $customerPriceValues[$index] ?? null;

            if ($customerIdInt <= 0 || $value === null || $value === '') {
                continue;
            }

            $price = (float) $value;
            if ($price <= 0) {
                continue;
            }

            $customerPrices[] = [
                'customer_id' => $customerIdInt,
                'price' => $price,
            ];
        }

        return $customerPrices;
    }
}
