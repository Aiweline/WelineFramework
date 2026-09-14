<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;

/**
 * One checkout session per cart identity; overwrite a single error snapshot.
 */
final class CheckoutSessionFaultRecorder
{
    public const CODE_MISSING_WEIGHT = 'missing_weight';
    public const CODE_FX_SKIPPED = 'fx_skipped';
    public const CODE_SHIPPING_UNAVAILABLE = 'shipping_unavailable';
    public const CODE_CHECKOUT_BLOCKED = 'checkout_blocked';
    public const CODE_FREEZE_FAILED = 'freeze_failed';
    public const CODE_SUBMIT_FAILED = 'submit_failed';

    public function __construct(
        private readonly CheckoutSessionStoreInterface $sessions,
    ) {
    }

    /**
     * @param array<string, mixed> $browsePayload
     */
    public function ensureSession(?string $clientToken, string $fingerprint, array $browsePayload): string
    {
        $token = trim((string)$clientToken);
        $existing = $token !== '' ? $this->sessions->get($token) : null;
        $state = is_array($existing) ? (string)($existing['state'] ?? '') : '';
        if ($existing === null || $state === CheckoutSession::STATE_SUBMITTED || $state === CheckoutSession::STATE_SUBMITTING) {
            $token = '';
            $existing = null;
        }
        if ($token === '' && $fingerprint !== '') {
            $found = $this->sessions->findQuotedTokenByFingerprint($fingerprint);
            if ($found !== null && $found !== '') {
                $token = $found;
                $existing = $this->sessions->get($token);
            }
        }
        if ($token === '') {
            $token = 'qt_' . bin2hex(random_bytes(12));
            $existing = null;
        }
        $payload = is_array($existing) ? $existing : [];
        if ($payload === [] || !empty($payload['browse'])) {
            $payload = $browsePayload + [
                'quote_token' => $token,
                'state' => CheckoutSession::STATE_QUOTED,
                'browse' => true,
                'cart_fingerprint' => $fingerprint,
            ];
        } else {
            $payload['cart_fingerprint'] = $fingerprint !== ''
                ? $fingerprint
                : (string)($payload['cart_fingerprint'] ?? '');
            $payload['quote_token'] = $token;
        }
        // Browse/fault path is universal checkout; keep existing attribution if already set.
        if (trim((string)($payload['checkout_entry'] ?? '')) === '') {
            $payload['checkout_entry'] = CheckoutEntry::CHECKOUT;
        }
        $this->sessions->put($token, $payload);

        return $token;
    }

    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $quoteDiagnostics
     */
    public function syncLoad(
        string $token,
        array $address,
        array $items,
        array $quoteDiagnostics,
        array $shippingMethods,
        bool $checkoutBlocked,
        string $emptyMessage,
    ): void {
        $token = trim($token);
        if ($token === '') {
            return;
        }
        $decision = $this->classify(
            $address,
            $items,
            $quoteDiagnostics,
            $shippingMethods,
            $checkoutBlocked,
        );
        if ($decision === 'leave') {
            return;
        }
        if ($decision === 'clear') {
            $this->sessions->clearErrorSnapshot($token);

            return;
        }
        $this->sessions->setErrorSnapshot(
            $token,
            $decision,
            $emptyMessage,
            $this->buildSnapshot($address, $items, $quoteDiagnostics, $emptyMessage, $decision),
        );
    }

    public function recordCode(string $token, string $code, string $message, array $snapshot = []): void
    {
        $token = trim($token);
        $code = trim($code);
        if ($token === '' || $code === '') {
            return;
        }
        $this->sessions->setErrorSnapshot($token, $code, $message, $snapshot);
    }

    public function clear(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }
        $this->sessions->clearErrorSnapshot($token);
    }

    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $items
     */
    public static function fingerprint(
        array $address,
        ?int $customerId,
        string $guestToken,
        string $cartType,
        int $websiteId,
        int $storeId,
        string $ownerKind = '',
        string $ownerId = '',
    ): string {
        $kind = $ownerKind !== '' ? $ownerKind : ($customerId !== null && $customerId > 0 ? 'customer' : 'guest');
        $id = $ownerId !== ''
            ? $ownerId
            : ($customerId !== null && $customerId > 0 ? (string)$customerId : trim($guestToken));

        return hash(
            'sha256',
            implode('|', [
                (string)$websiteId,
                (string)$storeId,
                $kind,
                $id,
                strtolower(trim($cartType)) ?: 'toc',
            ]),
        );
    }

    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $quoteDiagnostics
     * @param list<array<string, mixed>> $shippingMethods
     */
    public function classify(
        array $address,
        array $items,
        array $quoteDiagnostics,
        array $shippingMethods,
        bool $checkoutBlocked,
    ): string {
        if ($items === []) {
            return 'leave';
        }
        $country = strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return 'leave';
        }
        if ($checkoutBlocked) {
            return self::CODE_CHECKOUT_BLOCKED;
        }
        if (!empty($quoteDiagnostics['missing_weight'])) {
            return self::CODE_MISSING_WEIGHT;
        }
        if (!empty($quoteDiagnostics['fx_skipped'])) {
            return self::CODE_FX_SKIPPED;
        }
        if ($shippingMethods === [] && $this->addressComplete($address)) {
            return self::CODE_SHIPPING_UNAVAILABLE;
        }
        if ($shippingMethods !== []) {
            return 'clear';
        }

        return 'leave';
    }

    /**
     * @param array<string, mixed> $address
     */
    public function addressComplete(array $address): bool
    {
        $country = strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return false;
        }
        $province = trim((string)($address['province'] ?? $address['province_code'] ?? ''));
        $city = trim((string)($address['city'] ?? $address['city_code'] ?? ''));
        $postal = trim((string)($address['postal_code'] ?? $address['zip'] ?? ''));

        return $province !== '' && ($city !== '' || $postal !== '');
    }

    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $quoteDiagnostics
     * @return array<string, mixed>
     */
    public function buildSnapshot(
        array $address,
        array $items,
        array $quoteDiagnostics,
        string $emptyMessage,
        string $code,
    ): array {
        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lines[] = [
                'sku' => (string)($item['sku'] ?? ''),
                'product_id' => (int)($item['product_id'] ?? $item['id'] ?? 0),
                'weight_minor' => (int)($item['weight_minor'] ?? 0),
                'qty' => max(1, (int)($item['qty'] ?? $item['qty_minor'] ?? 1)),
            ];
        }

        return [
            'error_code' => $code,
            'country_code' => strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? ''))),
            'province' => (string)($address['province'] ?? ''),
            'city' => (string)($address['city'] ?? ''),
            'postal_code' => (string)($address['postal_code'] ?? ''),
            'lines' => $lines,
            'quote_diagnostics' => $quoteDiagnostics,
            'empty_message' => $emptyMessage,
        ];
    }
}
