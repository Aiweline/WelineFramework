<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

/** Immutable B2B price facts captured for one accepted Order. */
final class B2BOrderPriceSnapshot
{
    /**
     * @param list<string> $ruleStack
     */
    public function __construct(
        public readonly string $orderRef,
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
        public readonly string $hash,
        public readonly int $createdAtEpoch,
    ) {
        foreach ([
            [$orderRef, 64],
            [$tokenId, 64],
            [$customerId, 64],
            [$sku, 128],
            [$source, 32],
        ] as [$value, $maxLength]) {
            if (trim($value) === '' || strlen($value) > $maxLength) {
                throw new \InvalidArgumentException(__('B2B Order snapshot identity 非法'));
            }
        }
        foreach ([[$groupId, 64], [$priceListId, 64], [$channelId, 64]] as [$value, $maxLength]) {
            if ($value !== null && (trim($value) === '' || strlen($value) > $maxLength)) {
                throw new \InvalidArgumentException(__('B2B Order snapshot 可选 identity 非法'));
            }
        }
        if ($websiteId < 0 || $retailAmountMinor < 0 || $amountMinor < 0 || $createdAtEpoch < 1) {
            throw new \InvalidArgumentException(__('B2B Order snapshot Website/amount/time 非法'));
        }
        if ($version !== null && $version < 1) {
            throw new \InvalidArgumentException(__('B2B Order snapshot version 非法'));
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new \InvalidArgumentException(__('B2B Order snapshot hash 非法'));
        }
        foreach ($ruleStack as $rule) {
            if (!is_string($rule) || trim($rule) === '' || strlen($rule) > 255) {
                throw new \InvalidArgumentException(__('B2B Order snapshot rule stack 非法'));
            }
        }
        if (!hash_equals($hash, self::calculateHash([
            'order_ref' => $orderRef,
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
            'created_at_epoch' => $createdAtEpoch,
        ]))) {
            throw new \InvalidArgumentException(__('B2B Order snapshot hash 校验失败'));
        }
    }

    /** @param array<string,mixed> $facts */
    public static function calculateHash(array $facts): string
    {
        $canonical = [
            'order_ref' => (string)($facts['order_ref'] ?? ''),
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
            'created_at_epoch' => (int)($facts['created_at_epoch'] ?? 0),
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
            'order_ref' => $this->orderRef,
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
            'hash' => $this->hash,
            'created_at_epoch' => $this->createdAtEpoch,
        ];
    }
}
