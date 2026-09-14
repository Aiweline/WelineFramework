<?php

declare(strict_types=1);

namespace Weline\Payment\Integration\Order;

use Weline\Order\Api\OrderPaymentMethodCatalogInterface;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Service\PaymentMethodManager;

/**
 * Payment-side catalog for backend order payment chrome.
 */
final class OrderPaymentMethodCatalog implements OrderPaymentMethodCatalogInterface
{
    public function __construct(
        private readonly PaymentMethodManager $methods,
    ) {
    }

    public function listActiveOptions(int $websiteId = 0, int $storeId = 0): array
    {
        $context = [
            'website_id' => max(0, $websiteId),
            'store_id' => max(0, $storeId),
        ];
        $out = [];
        foreach ($this->methods->getActiveMethods($context) as $method) {
            if (!$method instanceof PaymentMethod) {
                continue;
            }
            $code = strtolower(trim((string)$method->getData(PaymentMethod::schema_fields_CODE)));
            if ($code === '') {
                continue;
            }
            $label = trim((string)$method->getData(PaymentMethod::schema_fields_NAME));
            $out[] = [
                'code' => $code,
                'label' => $label !== '' ? $label : $code,
            ];
        }

        return $out;
    }

    public function resolveLabel(string $code, int $websiteId = 0, int $storeId = 0): string
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return '';
        }
        foreach ($this->listActiveOptions($websiteId, $storeId) as $option) {
            if (($option['code'] ?? '') === $code) {
                return (string)($option['label'] ?? $code);
            }
        }
        // Include inactive admin-known codes for historical orders.
        try {
            $all = $this->methods->listMethodsForAdmin([
                'website_id' => max(0, $websiteId),
                'store_id' => max(0, $storeId),
            ]);
            foreach ($all as $method) {
                if (!$method instanceof PaymentMethod) {
                    continue;
                }
                $methodCode = strtolower(trim((string)$method->getData(PaymentMethod::schema_fields_CODE)));
                if ($methodCode === $code) {
                    $label = trim((string)$method->getData(PaymentMethod::schema_fields_NAME));

                    return $label !== '' ? $label : $code;
                }
            }
        } catch (\Throwable) {
            // Soft: label falls back to raw code.
        }

        return $code;
    }
}
