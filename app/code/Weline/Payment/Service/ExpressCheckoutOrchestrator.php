<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\PaymentExpressAddressSinkInterface;
use Weline\Payment\Api\PaymentExpressFacadeInterface;
use Weline\Payment\Model\PaymentMethod;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Shell orchestrator for 快捷智能支付 — capability discovery + address sink apply.
 * Does not create orders; Checkout/PDP still freeze/submit then pay with express context.
 */
final class ExpressCheckoutOrchestrator implements PaymentExpressFacadeInterface
{
    public const CAPABILITY_EXPRESS = 'express_checkout';

    /** 壳级总开关（SystemConfig / 支付核心配置） */
    public const CONFIG_SHELL_EXPRESS_ENABLED = 'payment/general/express_checkout_enabled';

    public const META_AWAITING_CONFIRM = PaymentExpressFacadeInterface::META_AWAITING_CONFIRM;

    public function __construct(
        private readonly ?PaymentMethodManager $methodManager = null,
        private readonly ?ObjectManager $objectManager = null,
        private readonly ?ConfigStore $configStore = null,
    ) {
    }

    public function listExpressMethods(array $context = []): array
    {
        if (!$this->isShellExpressEnabled()) {
            return [];
        }

        $out = [];
        foreach ($this->methods()->getActiveMethods($context) as $method) {
            if (!$method instanceof PaymentMethod) {
                continue;
            }
            $code = strtolower(trim((string) $method->getData(PaymentMethod::schema_fields_CODE)));
            if ($code === '' || !$this->supportsExpress($code, $context)) {
                continue;
            }
            $display = $this->methods()->getEffectiveDisplayMetadata($method, $context);
            $caps = $this->methods()->getRuntimeCapabilities($method, $context);
            $modes = $caps['express_modes'] ?? ['redirect'];
            if (!is_array($modes) || $modes === []) {
                $modes = ['redirect'];
            }
            $out[] = [
                'method_code' => $code,
                'title' => (string) ($display['title'] ?? $code),
                'icon_url' => (string) ($display['icon_url'] ?? $display['icon'] ?? ''),
                'express_modes' => array_values(array_map('strval', $modes)),
                'next_action_hint' => (string) ($caps['express_next_action'] ?? 'redirect'),
            ];
        }

        return $out;
    }

    public function supportsExpress(string $methodCode, array $context = []): bool
    {
        if (!$this->isShellExpressEnabled()) {
            return false;
        }
        $methodCode = strtolower(trim($methodCode));
        if ($methodCode === '') {
            return false;
        }
        try {
            $method = $this->methods()->getMethodByCode($methodCode);
            if ($method === null || !$this->methods()->isMethodActiveForScope($method, $context)) {
                return false;
            }
            $caps = $this->methods()->getRuntimeCapabilities($method, $context);

            return !empty($caps[self::CAPABILITY_EXPRESS]);
        } catch (\Throwable) {
            return false;
        }
    }

    public function withExpressContext(array $paymentContext, string $methodCode = ''): array
    {
        $methodCode = strtolower(trim($methodCode !== '' ? $methodCode : (string) ($paymentContext['method_code'] ?? '')));
        if ($methodCode !== '' && !$this->supportsExpress($methodCode, $paymentContext)) {
            return $paymentContext;
        }
        $paymentContext['express_checkout'] = true;
        $meta = is_array($paymentContext['metadata'] ?? null) ? $paymentContext['metadata'] : [];
        $meta['express_checkout'] = true;
        $paymentContext['metadata'] = $meta;

        return $paymentContext;
    }

    public function evaluateExpressProfile(array $profile, bool $requiresShipping = true): array
    {
        $missing = [];
        if (!$requiresShipping) {
            return [
                'complete' => true,
                'missing_fields' => [],
                'requires_shipping' => false,
            ];
        }

        $name = trim((string) ($profile['contact_name'] ?? $profile['name'] ?? ''));
        $street = trim((string) ($profile['street'] ?? $profile['address1'] ?? ''));
        $country = strtoupper(trim((string) ($profile['country_code'] ?? '')));
        $phone = trim((string) ($profile['contact_phone'] ?? $profile['phone'] ?? ''));
        $email = trim((string) ($profile['email'] ?? ''));

        if ($name === '') {
            $missing[] = 'contact_name';
        }
        if ($street === '') {
            $missing[] = 'address1';
        }
        if ($country === '') {
            $missing[] = 'country_code';
        }
        if ($phone === '') {
            $missing[] = 'contact_phone';
        }
        if ($email === '') {
            $missing[] = 'email';
        }

        // Core address (name/street/country) required for complete; phone/email are gaps but still listed.
        $coreOk = $name !== '' && $street !== '' && $country !== '';

        return [
            'complete' => $coreOk,
            'missing_fields' => array_values(array_unique($missing)),
            'requires_shipping' => true,
        ];
    }

    /**
     * @param array<string, mixed> $requestOrMeta
     */
    public static function isExpressAwaitingConfirm(array $requestOrMeta): bool
    {
        if (!empty($requestOrMeta[self::META_AWAITING_CONFIRM])) {
            return true;
        }
        $meta = is_array($requestOrMeta['metadata'] ?? null) ? $requestOrMeta['metadata'] : [];

        return !empty($meta[self::META_AWAITING_CONFIRM]);
    }

    public function applyExpressProfileFromPaymentResult(array $resultPayload, array $transactionContext = []): array
    {
        $payload = is_array($resultPayload['payload'] ?? null)
            ? $resultPayload['payload']
            : $resultPayload;
        $profile = is_array($payload['express_profile'] ?? null) ? $payload['express_profile'] : null;
        if ($profile === null || $profile === []) {
            return ['applied' => false, 'sinks' => 0];
        }

        $express = !empty($transactionContext['express_checkout'])
            || !empty($transactionContext['metadata']['express_checkout'])
            || !empty($payload['express_checkout']);
        if (!$express) {
            return ['applied' => false, 'sinks' => 0];
        }

        $methodCode = strtolower(trim((string) (
            $transactionContext['method_code']
            ?? $resultPayload['method_code']
            ?? ''
        )));
        $body = [
            'method_code' => $methodCode,
            'transaction_no' => (string) ($transactionContext['transaction_no'] ?? ''),
            'order_id' => (string) ($transactionContext['order_id'] ?? $transactionContext['payable_id'] ?? ''),
            'profile' => $profile,
        ];

        $applied = false;
        $sinks = 0;
        foreach ($this->addressSinks() as $sink) {
            ++$sinks;
            try {
                $result = $sink->applyExpressAddress($body);
                if (!empty($result['applied'])) {
                    $applied = true;
                }
            } catch (\Throwable) {
                // Best-effort: payment already succeeded; address sync must not fail return.
            }
        }

        return ['applied' => $applied, 'sinks' => $sinks];
    }

    private function methods(): PaymentMethodManager
    {
        if ($this->methodManager instanceof PaymentMethodManager) {
            return $this->methodManager;
        }
        $om = $this->objectManager ?? ObjectManager::getInstance();
        $mgr = $om->getInstance(PaymentMethodManager::class);
        if ($mgr instanceof PaymentMethodManager) {
            return $mgr;
        }

        throw new \RuntimeException('PaymentMethodManager unavailable for express checkout.');
    }

    private function isShellExpressEnabled(): bool
    {
        try {
            $store = $this->configStore;
            if (!$store instanceof ConfigStore) {
                $om = $this->objectManager ?? ObjectManager::getInstance();
                $store = $om->getInstance(ConfigStore::class);
            }
            if (!$store instanceof ConfigStore) {
                return true;
            }
            $raw = $store->getConfig(
                self::CONFIG_SHELL_EXPRESS_ENABLED,
                'Weline_Payment',
                ConfigStore::area_BACKEND,
                '1'
            );

            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return list<PaymentExpressAddressSinkInterface>
     */
    private function addressSinks(): array
    {
        $list = [];
        try {
            $om = $this->objectManager ?? ObjectManager::getInstance();
            $registry = $om->getInstance(ServiceProviderRegistry::class);
            if (!$registry instanceof ServiceProviderRegistry) {
                return [];
            }
            foreach ($registry->implementationsWithPrefix(PaymentExpressAddressSinkInterface::CAPABILITY_PREFIX) as $class) {
                $sink = $om->getInstance((string) $class);
                if ($sink instanceof PaymentExpressAddressSinkInterface) {
                    $list[] = $sink;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $list;
    }
}
