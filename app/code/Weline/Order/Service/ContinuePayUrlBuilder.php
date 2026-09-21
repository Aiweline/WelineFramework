<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Service\ContinuePaymentRecoveryUrlBuilder;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * Builds capability-gated continue-pay URLs for unpaid order signals.
 * Prefer checkout #payment-recovery (resumePaymentV2); Marketing must not invent auth.
 */
final class ContinuePayUrlBuilder
{
    public function __construct(
        private readonly ?CheckoutSessionStoreInterface $sessions = null,
        private readonly ?string $storefrontBaseOverride = null,
        private readonly ?ContinuePaymentRecoveryUrlBuilder $recoveryUrlBuilder = null,
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
        if ($token !== '') {
            $recovery = $this->recoveryBuilder()->build($token, $orderUuid, $websiteId);
            if (!empty($recovery['reachable']) && trim((string)($recovery['continue_pay_url'] ?? '')) !== '') {
                return [
                    'continue_pay_url' => (string)$recovery['continue_pay_url'],
                    'reachable' => true,
                    'checkout_token' => $token,
                ];
            }
            if ($base !== '') {
                // Capability success URL still lets the buyer open the unpaid order shell.
                $url = rtrim($base, '/') . '/checkout/success?' . http_build_query([
                    'order_uuid' => $orderUuid,
                    'checkout_token' => $token,
                    'outcome' => 'cancel',
                ], '', '&', PHP_QUERY_RFC3986);

                return [
                    'continue_pay_url' => $url,
                    'reachable' => true,
                    'checkout_token' => $token,
                ];
            }
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

    private function recoveryBuilder(): ContinuePaymentRecoveryUrlBuilder
    {
        if ($this->recoveryUrlBuilder instanceof ContinuePaymentRecoveryUrlBuilder) {
            return $this->recoveryUrlBuilder;
        }

        return new ContinuePaymentRecoveryUrlBuilder($this->sessions, $this->storefrontBaseOverride);
    }

    private function storefrontBase(?int $websiteId): string
    {
        if ($this->storefrontBaseOverride !== null && $this->storefrontBaseOverride !== '') {
            return $this->appendDevPortIfNeeded(rtrim($this->storefrontBaseOverride, '/'));
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
                    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                        $url = 'https://' . ltrim($url, '/');
                    }

                    return $this->appendDevPortIfNeeded(rtrim($url, '/'));
                }
            }
        } catch (\Throwable) {
        }

        $envHost = '';
        try {
            $env = Env::getInstance()->getConfig('wls.host');
            $envHost = is_string($env) ? trim($env) : '';
        } catch (\Throwable) {
        }
        if ($envHost === '') {
            return '';
        }
        if (!str_starts_with($envHost, 'http://') && !str_starts_with($envHost, 'https://')) {
            $envHost = 'https://' . $envHost;
        }

        return $this->appendDevPortIfNeeded(rtrim($envHost, '/'));
    }

    /** @see ContinuePaymentRecoveryUrlBuilder — keep WLS listen_https on local hosts */
    private function appendDevPortIfNeeded(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !empty($parts['port'])) {
            return $url;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || (!str_ends_with($host, '.test.weline.com') && !str_ends_with($host, '.weline.test'))) {
            return $url;
        }
        $port = 0;
        $reqHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($reqHost !== '' && str_contains($reqHost, ':')) {
            $port = (int)explode(':', $reqHost, 2)[1];
        }
        if ($port <= 0) {
            try {
                $raw = Env::get('wls.edge.nginx.listen_https', null);
                if (is_numeric($raw)) {
                    $port = (int)$raw;
                }
            } catch (\Throwable) {
            }
        }
        if ($port <= 0 || $port === 80 || $port === 443) {
            return $url;
        }
        $scheme = (string)($parts['scheme'] ?? 'https');
        $path = (string)($parts['path'] ?? '');

        return $scheme . '://' . $host . ':' . $port . $path;
    }
}
