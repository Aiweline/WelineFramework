<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\CustomerGroup;

/**
 * Explicitly gated backend commands. The underlying B2B service remains the
 * owner of group, price-list, quote recheck and immutable snapshot rules.
 */
final class B2BAdminService
{
    public function __construct(private readonly B2BService $service)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createGroup(array $input): array
    {
        $websiteId = (int)($input['website_id'] ?? -1);
        $this->assertMutable($websiteId);
        return $this->service->seedGroup(
            trim((string)($input['group_id'] ?? '')),
            $websiteId,
            trim((string)($input['code'] ?? '')),
            trim((string)($input['status'] ?? CustomerGroup::STATUS_ACTIVE)),
        )->toArray();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createPriceList(array $input): array
    {
        $websiteId = (int)($input['website_id'] ?? -1);
        $this->assertMutable($websiteId);
        $sku = trim((string)($input['sku'] ?? ''));
        return $this->service->seedPriceList(
            trim((string)($input['list_id'] ?? '')),
            trim((string)($input['group_id'] ?? '')),
            $websiteId,
            (int)($input['version'] ?? 1),
            [$sku => (int)($input['amount_minor'] ?? -1)],
            trim((string)($input['channel_id'] ?? '')) ?: null,
            true,
        )->toMeta();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function approveQuote(array $input): array
    {
        $websiteId = (int)($input['website_id'] ?? -1);
        $this->assertMutable($websiteId);
        $customerId = trim((string)($input['customer_id'] ?? ''));
        $quoteRequest = [
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'sku' => trim((string)($input['sku'] ?? '')),
            'retail_amount_minor' => (int)($input['retail_amount_minor'] ?? -1),
        ];
        $channelId = trim((string)($input['channel_id'] ?? ''));
        if ($channelId !== '') {
            $quoteRequest['channel_id'] = $channelId;
        }
        $issued = $this->service->issueQuote($quoteRequest);
        $tokenId = trim((string)($issued['token']['token_id'] ?? ''));
        if ($tokenId === '') {
            throw new \RuntimeException('b2b_admin_quote_token_missing');
        }
        return $this->service->submit(
            $tokenId,
            $customerId,
            $websiteId,
            trim((string)($input['order_ref'] ?? '')),
        );
    }

    private function assertMutable(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('b2b_admin_website_invalid');
        }
        $this->service->rollout()->assertMutable(
            B2BService::CAPABILITY,
            B2BRolloutGate::scopeKey($websiteId),
        );
    }
}
