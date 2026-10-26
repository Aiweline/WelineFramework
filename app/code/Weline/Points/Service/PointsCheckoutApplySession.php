<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Points\Api\PointsDiscountApplySessionInterface;

/**
 * Persist the shopper's points-discount choice in the storefront session.
 */
final class PointsCheckoutApplySession implements PointsDiscountApplySessionInterface
{
    private const SESSION_KEY = 'weline_points_discount_apply';

    public function __construct(private readonly SessionFactory $sessionFactory)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        $raw = $this->sessionGet($this->frontendSession(), self::SESSION_KEY);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || (int) ($raw['points'] ?? 0) <= 0) {
            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $apply
     */
    public function save(array $apply): void
    {
        $points = max(0, (int) ($apply['points'] ?? 0));
        if ($points <= 0) {
            $this->clear();

            return;
        }
        $this->sessionSet($this->frontendSession(), self::SESSION_KEY, [
            'points' => $points,
            'apply_checkout_minor' => max(0, (int) ($apply['apply_checkout_minor'] ?? 0)),
            'apply_base_minor' => max(0, (int) ($apply['apply_base_minor'] ?? 0)),
            'checkout_currency' => strtoupper(trim((string) ($apply['checkout_currency'] ?? ''))),
            'base_currency' => strtoupper(trim((string) ($apply['base_currency'] ?? ''))),
            'exchange_ratio' => (float) ($apply['exchange_ratio'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function clear(): void
    {
        $this->sessionSet($this->frontendSession(), self::SESSION_KEY, null);
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
