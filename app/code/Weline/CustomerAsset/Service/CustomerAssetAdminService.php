<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Weline\CustomerAsset\Model\AssetAccount;

/** Backend command facade over the append-only CustomerAsset service. */
final class CustomerAssetAdminService
{
    public function __construct(private readonly CustomerAssetService $assets)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function credit(array $input): array
    {
        return $this->assets->credit($this->identityRequest($input) + [
            'amount_minor' => (int)($input['amount_minor'] ?? -1),
            'event_id' => trim((string)($input['event_id'] ?? '')),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reserve(array $input): array
    {
        return $this->assets->reserve($this->identityRequest($input) + [
            'amount_minor' => (int)($input['amount_minor'] ?? -1),
            'event_id' => trim((string)($input['event_id'] ?? '')),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function commit(array $input): array
    {
        return $this->assets->commit(
            trim((string)($input['reservation_id'] ?? '')),
            trim((string)($input['event_id'] ?? '')),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function returnCommitted(array $input): array
    {
        return $this->assets->returnCommitted(
            trim((string)($input['reservation_id'] ?? '')),
            (int)($input['amount_minor'] ?? -1),
            trim((string)($input['event_id'] ?? '')),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function identityRequest(array $input): array
    {
        return [
            'customer_id' => trim((string)($input['customer_id'] ?? '')),
            'website_id' => (int)($input['website_id'] ?? -1),
            'asset_code' => trim((string)($input['asset_code'] ?? 'credit')),
            'namespace' => trim((string)($input['namespace'] ?? AssetAccount::NS_SANDBOX)),
        ];
    }
}
