<?php

declare(strict_types=1);

namespace WeShop\Price\Controller\Frontend;

use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class Price extends FrontendController
{
    public function index(): string
    {
        $productId = (int) ($this->request->getParam('product_id') ?? 0);
        $customerId = $this->getCustomerId();
        $quantity = max(1, (int) ($this->request->getParam('quantity') ?? 1));
        $currency = (string) ($this->request->getParam('currency') ?? $_SERVER['WELINE_USER_CURRENCY'] ?? 'CNY');

        if ($productId <= 0) {
            $this->getResponse()->setHttpResponseCode(400);
            return $this->fetchJson([
                'success' => false,
                'message' => __('Product ID is required.'),
            ]);
        }

        try {
            $priceService = ObjectManager::getInstance(PriceService::class);
            $priceData = $priceService->calculatePrice($productId, $customerId, $quantity);

            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->load($productId);
            if (!$productModel->getId()) {
                $this->getResponse()->setHttpResponseCode(404);
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Product not found.'),
                ]);
            }

            $formattedPrice = $priceService->formatPrice($priceData, $currency);

            $this->assign([
                'product_id' => $productId,
                'price' => $priceData,
                'formatted_price' => $formattedPrice,
                'currency' => $currency,
                'quantity' => $quantity,
            ]);

            return $this->fetch('WeShop_Price::templates/Frontend/Price/index.phtml');
        } catch (\InvalidArgumentException $e) {
            $this->getResponse()->setHttpResponseCode(400);
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\RuntimeException $e) {
            $this->getResponse()->setHttpResponseCode(404);
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $throwable) {
            $this->getResponse()->setHttpResponseCode(500);
            return $this->fetchJson([
                'success' => false,
                'message' => __('An error occurred while calculating the price.'),
            ]);
        }
    }

    public function calculate(): string
    {
        $productId = (int) ($this->request->getParam('product_id') ?? 0);
        $customerId = $this->getCustomerId();
        $quantity = max(1, (int) ($this->request->getParam('quantity') ?? 1));
        $currency = (string) ($this->request->getParam('currency') ?? $_SERVER['WELINE_USER_CURRENCY'] ?? 'CNY');

        if ($productId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Product ID is required.'),
            ]);
        }

        try {
            /** @var PriceService $priceService */
            $priceService = ObjectManager::getInstance(PriceService::class);
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->load($productId);

            if (!$productModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Product not found.'),
                ]);
            }

            $productData = $productModel->getData();
            $resolvedData = $priceService->resolveProductData($productData, $customerId, $quantity);
            $resolvedData['formatted_price'] = $priceService->formatPrice((float) ($resolvedData['price'] ?? 0), $currency);
            $resolvedData['formatted_original_price'] = $priceService->formatPrice((float) ($resolvedData['original_price'] ?? 0), $currency);

            return $this->fetchJson([
                'success' => true,
                'data' => $resolvedData,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('An error occurred while calculating the price.'),
            ]);
        }
    }

    public function batch(): string
    {
        $productIdsParam = $this->request->getParam('product_ids', '');
        $productIds = array_filter(array_map('intval', explode(',', $productIdsParam)), fn(int $id): bool => $id > 0);
        $customerId = $this->getCustomerId();
        $quantity = max(1, (int) ($this->request->getParam('quantity') ?? 1));
        $currency = (string) ($this->request->getParam('currency') ?? $_SERVER['WELINE_USER_CURRENCY'] ?? 'CNY');

        if ($productIds === []) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('At least one product ID is required.'),
            ]);
        }

        try {
            /** @var PriceService $priceService */
            $priceService = ObjectManager::getInstance(PriceService::class);
            $productModel = ObjectManager::getInstance(Product::class);
            $results = [];

            foreach ($productIds as $productId) {
                try {
                    $priceData = $priceService->calculatePrice($productId, $customerId, $quantity);
                    $results[$productId] = [
                        'success' => true,
                        'price' => $priceData,
                        'formatted_price' => $priceService->formatPrice($priceData, $currency),
                    ];
                } catch (\Throwable) {
                    $results[$productId] = [
                        'success' => false,
                        'message' => __('Failed to calculate price for product %1.', [$productId]),
                    ];
                }
            }

            return $this->fetchJson([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('An error occurred while batch calculating prices.'),
            ]);
        }
    }

    private function getCustomerId(): ?int
    {
        try {
            $sessionClass = '\\WeShop\\Customer\\Session\\CustomerSession';
            if (class_exists($sessionClass)) {
                /** @var \WeShop\Customer\Session\CustomerSession $session */
                $session = ObjectManager::getInstance($sessionClass);
                $customer = $session->getCustomer();
                if ($customer && $customer->getId()) {
                    return (int) $customer->getId();
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
