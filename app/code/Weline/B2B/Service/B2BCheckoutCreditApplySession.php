<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * Persist tob wholesale-credit apply choice on the storefront PHP session
 * (same durability class as MarketingCheckoutCouponSession — survives refresh).
 */
class B2BCheckoutCreditApplySession
{
    private const SESSION_KEY_BY_TYPE = 'weline_b2b_credit_apply_by_type';

    public function __construct(private readonly SessionFactory $sessionFactory)
    {
    }

    public function normalizeCartType(?string $cartType): string
    {
        $code = strtolower(trim((string)$cartType));

        return $code !== '' ? $code : 'tob';
    }

    /**
     * @return array{enabled:bool,apply_minor:int,currency:string,cart_type:string}|null
     */
    public function get(?string $cartType = 'tob'): ?array
    {
        $type = $this->normalizeCartType($cartType);
        $map = $this->readMap();
        $row = $map[$type] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $apply = max(0, (int)($row['apply_minor'] ?? 0));
        $enabled = !empty($row['enabled']) && $apply > 0;
        if (!$enabled) {
            return [
                'enabled' => false,
                'apply_minor' => 0,
                'currency' => strtoupper(trim((string)($row['currency'] ?? ''))) ?: '',
                'cart_type' => $type,
            ];
        }

        return [
            'enabled' => true,
            'apply_minor' => $apply,
            'currency' => strtoupper(trim((string)($row['currency'] ?? ''))) ?: 'CNY',
            'cart_type' => $type,
        ];
    }

    /**
     * Save or clear the shopper's credit apply for a cart type.
     *
     * @param array<string, mixed> $params
     * @return array{success:bool,ok:bool,saved_apply:array<string,mixed>,message?:string}
     */
    public function save(array $params = []): array
    {
        $type = $this->normalizeCartType(
            (string)($params['cart_type'] ?? $params['selling_mode'] ?? 'tob')
        );
        if ($type !== 'tob') {
            return [
                'success' => false,
                'ok' => false,
                'saved_apply' => [
                    'enabled' => false,
                    'apply_minor' => 0,
                    'currency' => '',
                    'cart_type' => $type,
                ],
                'message' => (string)__('仅批发购物车可使用信用抵扣'),
            ];
        }

        $apply = max(0, (int)($params['apply_minor'] ?? 0));
        $currency = strtoupper(trim((string)($params['currency'] ?? 'CNY'))) ?: 'CNY';
        $enabledFlag = $params['enabled'] ?? ($apply > 0);
        $enabled = $apply > 0 && !in_array($enabledFlag, [false, 0, '0', 'false', ''], true);

        $map = $this->readMap();
        if (!$enabled || $apply <= 0) {
            unset($map[$type]);
            $this->writeMap($map);
            $saved = [
                'enabled' => false,
                'apply_minor' => 0,
                'currency' => $currency,
                'cart_type' => $type,
            ];

            return [
                'success' => true,
                'ok' => true,
                'saved_apply' => $saved,
                'message' => (string)__('已取消批发信用抵扣'),
            ];
        }

        $map[$type] = [
            'enabled' => true,
            'apply_minor' => $apply,
            'currency' => $currency,
        ];
        $this->writeMap($map);
        $saved = [
            'enabled' => true,
            'apply_minor' => $apply,
            'currency' => $currency,
            'cart_type' => $type,
        ];

        return [
            'success' => true,
            'ok' => true,
            'saved_apply' => $saved,
            'message' => (string)__('批发信用抵扣已保存到购物车会话'),
        ];
    }

    /**
     * @return array<string, array{enabled:bool,apply_minor:int,currency:string}>
     */
    private function readMap(): array
    {
        $session = $this->frontendSession();
        $raw = $this->sessionGet($session, self::SESSION_KEY_BY_TYPE);
        $map = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        foreach ($raw as $type => $row) {
            $typeKey = $this->normalizeCartType((string)$type);
            if (!is_array($row)) {
                continue;
            }
            $apply = max(0, (int)($row['apply_minor'] ?? 0));
            if ($apply <= 0) {
                continue;
            }
            $map[$typeKey] = [
                'enabled' => true,
                'apply_minor' => $apply,
                'currency' => strtoupper(trim((string)($row['currency'] ?? ''))) ?: 'CNY',
            ];
        }

        return $map;
    }

    /**
     * @param array<string, array{enabled:bool,apply_minor:int,currency:string}> $map
     */
    private function writeMap(array $map): void
    {
        $clean = [];
        foreach ($map as $type => $row) {
            $typeKey = $this->normalizeCartType((string)$type);
            if (!is_array($row)) {
                continue;
            }
            $apply = max(0, (int)($row['apply_minor'] ?? 0));
            if ($apply <= 0) {
                continue;
            }
            $clean[$typeKey] = [
                'enabled' => true,
                'apply_minor' => $apply,
                'currency' => strtoupper(trim((string)($row['currency'] ?? ''))) ?: 'CNY',
            ];
        }
        $this->sessionSet($this->frontendSession(), self::SESSION_KEY_BY_TYPE, $clean);
    }

    private function sessionGet(AuthenticatedSessionInterface $session, string $key): mixed
    {
        if (method_exists($session, 'getData')) {
            /** @phpstan-ignore-next-line */
            return $session->getData($key);
        }

        return $session->get($key);
    }

    private function sessionSet(AuthenticatedSessionInterface $session, string $key, mixed $value): void
    {
        if (method_exists($session, 'setData')) {
            /** @phpstan-ignore-next-line */
            $session->setData($key, $value);

            return;
        }
        $session->set($key, $value);
    }

    private function frontendSession(): AuthenticatedSessionInterface
    {
        return $this->sessionFactory->createFrontendSession();
    }
}
