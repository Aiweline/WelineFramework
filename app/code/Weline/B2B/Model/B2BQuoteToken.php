<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

/** Server-owned quote token; clients receive only its opaque identity. */
final class B2BQuoteToken
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_INVALIDATED = 'invalidated';

    private string $status;
    private ?string $consumedOrderRef;

    /**
     * @param list<string> $ruleStack
     */
    public function __construct(
        public readonly string $tokenId,
        public readonly string $customerId,
        public readonly int $websiteId,
        public readonly string $sku,
        public readonly int $retailAmountMinor,
        public readonly int $amountMinor,
        public readonly string $source,
        public readonly ?string $groupId,
        public readonly ?string $priceListId,
        public readonly ?int $version,
        public readonly ?string $channelId,
        public readonly array $ruleStack,
        public readonly string $fingerprint,
        public readonly int $issuedAtEpoch,
        public readonly int $expiresAtEpoch,
        string $status = self::STATUS_OPEN,
        ?string $consumedOrderRef = null,
    ) {
        self::assertId($tokenId, 64, __('B2B quote token_id 非法'));
        self::assertId($customerId, 64, __('B2B quote customer_id 非法'));
        self::assertId($sku, 128, __('B2B quote SKU 非法'));
        if ($websiteId < 0 || $retailAmountMinor < 0 || $amountMinor < 0) {
            throw new \InvalidArgumentException(__('B2B quote Website/amount 非法'));
        }
        self::assertId($source, 32, __('B2B quote source 非法'));
        self::assertOptionalId($groupId, 64, __('B2B quote group_id 非法'));
        self::assertOptionalId($priceListId, 64, __('B2B quote price_list_id 非法'));
        self::assertOptionalId($channelId, 64, __('B2B quote channel_id 非法'));
        self::assertOptionalId($consumedOrderRef, 64, __('B2B quote order_ref 非法'));
        if ($version !== null && $version < 1) {
            throw new \InvalidArgumentException(__('B2B quote version 非法'));
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw new \InvalidArgumentException(__('B2B quote fingerprint 非法'));
        }
        if ($issuedAtEpoch < 1 || $expiresAtEpoch <= $issuedAtEpoch) {
            throw new \InvalidArgumentException(__('B2B quote 有效期非法'));
        }
        if (!in_array($status, [self::STATUS_OPEN, self::STATUS_CONSUMED, self::STATUS_INVALIDATED], true)) {
            throw new \InvalidArgumentException(__('B2B quote status 非法：%{1}', [$status]));
        }
        if (($status === self::STATUS_CONSUMED) !== ($consumedOrderRef !== null)) {
            throw new \InvalidArgumentException(__('B2B quote 消费状态与 order_ref 不一致'));
        }
        foreach ($ruleStack as $rule) {
            self::assertId($rule, 255, __('B2B quote rule stack 非法'));
        }
        if (!hash_equals($fingerprint, self::calculateFingerprint([
            'token_id' => $tokenId,
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'sku' => $sku,
            'retail_amount_minor' => $retailAmountMinor,
            'amount_minor' => $amountMinor,
            'source' => $source,
            'group_id' => $groupId,
            'price_list_id' => $priceListId,
            'version' => $version,
            'channel_id' => $channelId,
            'rule_stack' => $ruleStack,
            'issued_at_epoch' => $issuedAtEpoch,
            'expires_at_epoch' => $expiresAtEpoch,
        ]))) {
            throw new \InvalidArgumentException(__('B2B quote fingerprint 校验失败'));
        }

        $this->status = $status;
        $this->consumedOrderRef = $consumedOrderRef;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function consumedOrderRef(): ?string
    {
        return $this->consumedOrderRef;
    }

    public function isExpired(int $nowEpoch): bool
    {
        return $nowEpoch >= $this->expiresAtEpoch;
    }

    public function markConsumed(string $orderRef): void
    {
        self::assertId($orderRef, 64, __('B2B quote order_ref 非法'));
        if ($this->status !== self::STATUS_OPEN) {
            throw new \LogicException('B2B quote is not open');
        }
        $this->status = self::STATUS_CONSUMED;
        $this->consumedOrderRef = $orderRef;
    }

    /** @param array<string,mixed> $facts */
    public static function calculateFingerprint(array $facts): string
    {
        $canonical = [
            'token_id' => (string)($facts['token_id'] ?? ''),
            'customer_id' => (string)($facts['customer_id'] ?? ''),
            'website_id' => (int)($facts['website_id'] ?? -1),
            'sku' => (string)($facts['sku'] ?? ''),
            'retail_amount_minor' => (int)($facts['retail_amount_minor'] ?? -1),
            'amount_minor' => (int)($facts['amount_minor'] ?? -1),
            'source' => (string)($facts['source'] ?? ''),
            'group_id' => $facts['group_id'] ?? null,
            'price_list_id' => $facts['price_list_id'] ?? null,
            'version' => $facts['version'] ?? null,
            'channel_id' => $facts['channel_id'] ?? null,
            'rule_stack' => array_values(is_array($facts['rule_stack'] ?? null) ? $facts['rule_stack'] : []),
            'issued_at_epoch' => (int)($facts['issued_at_epoch'] ?? 0),
            'expires_at_epoch' => (int)($facts['expires_at_epoch'] ?? 0),
        ];
        return hash(
            'sha256',
            json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'token_id' => $this->tokenId,
            'customer_id' => $this->customerId,
            'website_id' => $this->websiteId,
            'sku' => $this->sku,
            'retail_amount_minor' => $this->retailAmountMinor,
            'amount_minor' => $this->amountMinor,
            'source' => $this->source,
            'group_id' => $this->groupId,
            'price_list_id' => $this->priceListId,
            'version' => $this->version,
            'channel_id' => $this->channelId,
            'rule_stack' => $this->ruleStack,
            'fingerprint' => $this->fingerprint,
            'issued_at_epoch' => $this->issuedAtEpoch,
            'expires_at_epoch' => $this->expiresAtEpoch,
            'status' => $this->status,
            'consumed_order_ref' => $this->consumedOrderRef,
        ];
    }

    private static function assertId(string $value, int $maxLength, string $message): void
    {
        if (trim($value) === '' || strlen($value) > $maxLength) {
            throw new \InvalidArgumentException($message);
        }
    }

    private static function assertOptionalId(?string $value, int $maxLength, string $message): void
    {
        if ($value !== null) {
            self::assertId($value, $maxLength, $message);
        }
    }
}
