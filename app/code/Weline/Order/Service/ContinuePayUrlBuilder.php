<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * Builds capability-gated continue-pay URLs for unpaid order signals.
 * Marketing only embeds the returned URL; it must not invent auth.
 */
final class ContinuePayUrlBuilder
{
    public function __construct(
        private readonly ?CheckoutSessionStoreInterface $sessions = null,
        private readonly ?string $storefrontBaseOverride = null,
    ) {
    }

    /**
     * @return array{continue_pay_url:string,reachable:bool,checkout_token:string}
     */
    public function build(
        string $orderUuid,
        ?int $customerId,
        ?int $websiteId = null,
    ): array {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return ['continue_pay_url' => '', 'reachable' => false, 'checkout_token' => ''];
        }

        $sessions = $this->sessions;
        if ($sessions === null && class_exists(ObjectManager::class)) {
            try {
                $resolved = ObjectManager::getInstance(CheckoutSessionStoreInterface::class);
                $sessions = $resolved instanceof CheckoutSessionStoreInterface ? $resolved : null;
            } catch (\Throwable) {
                $sessions = null;
            }
        }

        $token = '';
        if ($sessions instanceof CheckoutSessionStoreInterface) {
            $token = trim((string)($sessions->findSubmittedTokenByOrderUuid($orderUuid) ?? ''));
        }

        $base = $this->storefrontBase($websiteId);
        if ($token !== '' && $base !== '') {
            $url = rtrim($base, '/') . '/checkout/success?' . http_build_query([
                'order_uuid' => $orderUuid,
                'checkout_token' => $token,
            ], '', '&', PHP_QUERY_RFC3986);

            return [
                'continue_pay_url' => $url,
                'reachable' => true,
                'checkout_token' => $token,
            ];
        }

        // Logged-in buyers can open account orders without capability token.
        if ($customerId !== null && $customerId > 0 && $base !== '') {
            $url = rtrim($base, '/') . '/customer/account/index#orders?order_uuid=' . rawurlencode($orderUuid);

            return [
                'continue_pay_url' => $url,
                'reachable' => true,
                'checkout_token' => $token,
            ];
        }

        return [
            'continue_pay_url' => '',
            'reachable' => false,
            'checkout_token' => $token,
        ];
    }

    private function storefrontBase(?int $websiteId): string
    {
        if ($this->storefrontBaseOverride !== null && $this->storefrontBaseOverride !== '') {
            return rtrim($this->storefrontBaseOverride, '/');
        }
        $websiteId = $websiteId !== null && $websiteId > 0 ? $websiteId : 0;
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            if ($websiteId > 0) {
                $website->load($websiteId);
            } else {
                $website->clear()->where(Website::schema_fields_ID, 0, '>=')->order(Website::schema_fields_ID, 'ASC')->find()->fetch();
            }
            if ($website->getId()) {
                $url = trim((string)$website->getUrl());
                if ($url !== '') {
                    return rtrim($url, '/');
                }
            }
        } catch (\Throwable) {
        }

        $envHost = '';
        try {
            $env = \Weline\Framework\App\Env::getInstance()->getConfig('wls.host');
            $envHost = is_string($env) ? trim($env) : '';
        } catch (\Throwable) {
        }
        if ($envHost === '') {
            return '';
        }
        if (!str_starts_with($envHost, 'http://') && !str_starts_with($envHost, 'https://')) {
            $envHost = 'https://' . $envHost;
        }

        return rtrim($envHost, '/');
    }
}
