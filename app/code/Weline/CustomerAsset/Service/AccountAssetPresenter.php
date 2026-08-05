<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Throwable;
use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\CustomerAsset\Model\AssetAccount;

/** Bounded, data-only projection for the official Customer account layout. */
final class AccountAssetPresenter
{
    private const ACCOUNT_LIMIT = 12;
    private const LEDGER_LIMIT = 8;

    public function __construct(
        private readonly CustomerAssetFacadeInterface $assets,
    ) {
    }

    /**
     * @return array{
     *   state:string,
     *   website_id:int,
     *   namespace:string,
     *   accounts:list<array<string,mixed>>,
     *   error_code:?string
     * }
     */
    public function present(
        ?int $customerId,
        int $websiteId,
        string $namespace = AssetAccount::NS_LIVE,
    ): array {
        if ($customerId === null || $customerId <= 0) {
            return $this->result('unauthenticated', $websiteId, $namespace);
        }
        try {
            $accounts = [];
            foreach ($this->assets->listAccounts(
                $customerId,
                $websiteId,
                $namespace,
                self::ACCOUNT_LIMIT,
            ) as $account) {
                if (!is_array($account)) {
                    continue;
                }
                $assetCode = trim((string)($account['asset_code'] ?? ''));
                if ($assetCode === '') {
                    continue;
                }
                $ledger = array_reverse($this->assets->listLedger(
                    $customerId,
                    $websiteId,
                    $assetCode,
                    $namespace,
                    self::LEDGER_LIMIT,
                ));
                $accounts[] = [
                    'asset_code' => $assetCode,
                    'available_minor' => (int)($account['available_minor'] ?? 0),
                    'reserved_minor' => (int)($account['reserved_minor'] ?? 0),
                    'reservable_minor' => (int)($account['reservable_minor'] ?? 0),
                    'updated_at' => (string)($account['updated_at'] ?? ''),
                    'ledger' => array_map(
                        static fn (array $entry): array => [
                            'entry_id' => (string)($entry['entry_id'] ?? ''),
                            'event_type' => (string)($entry['event_type'] ?? ''),
                            'amount_minor' => (int)($entry['amount_minor'] ?? 0),
                            'balance_after_available' => (int)(
                                $entry['balance_after_available'] ?? 0
                            ),
                            'created_at' => (string)($entry['created_at'] ?? ''),
                        ],
                        array_values(array_filter($ledger, 'is_array')),
                    ),
                ];
            }

            return $this->result(
                $accounts === [] ? 'empty' : 'ready',
                $websiteId,
                $namespace,
                $accounts,
            );
        } catch (Throwable $throwable) {
            if (function_exists('w_log_warning')) {
                w_log_warning(
                    '[CustomerAsset] account projection failed',
                    ['error_code' => 'customer_asset_account_projection_failed'],
                    'customer_asset',
                );
            }

            return $this->result(
                'error',
                $websiteId,
                $namespace,
                errorCode: 'customer_asset_account_projection_failed',
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $accounts
     * @return array{
     *   state:string,
     *   website_id:int,
     *   namespace:string,
     *   accounts:list<array<string,mixed>>,
     *   error_code:?string
     * }
     */
    private function result(
        string $state,
        int $websiteId,
        string $namespace,
        array $accounts = [],
        ?string $errorCode = null,
    ): array {
        return [
            'state' => $state,
            'website_id' => $websiteId,
            'namespace' => $namespace,
            'accounts' => $accounts,
            'error_code' => $errorCode,
        ];
    }
}
