<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\SkuRegistryService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\StoreCatalog;

/**
 * Settlement facade：split rules / immutable snapshots / payout / refund reversal（P4A-002）.
 *
 * mode off：禁止新规则与新快照；既有快照的 payout/reversal 继续。
 */
final class VendorSettlementService
{
    public const CAPABILITY = VendorService::CAPABILITY;

    public const ERROR_MODE_OFF_NEW_SPLIT = 'vendor_mode_off_blocks_new_split';

    public function __construct(
        private readonly VendorService $vendors,
        private readonly VendorSplitRuleStore $rules,
        private readonly VendorSplitSnapshotStore $snapshots,
        private readonly VendorPayoutLedger $payouts,
        private readonly VendorRefundReversalService $reversals,
        private readonly CommerceRolloutGateInterface $rollout,
    ) {
    }

    public static function forTesting(?CommerceRolloutGateInterface $rollout = null): self
    {
        $vendors = VendorService::forTesting($rollout);
        $gate = $vendors->rollout();
        $rules = VendorSplitRuleStore::forTesting();
        $snapshots = new VendorSplitSnapshotStore(
            $vendors->eligibility(),
            $rules,
            $vendors->registry(),
            useMemory: true,
        );
        $payouts = VendorPayoutLedger::forTesting($snapshots);
        $reversals = VendorRefundReversalService::forTesting($payouts, $snapshots);

        return new self($vendors, $rules, $snapshots, $payouts, $reversals, $gate);
    }

    /**
     * Build a production ORM runtime after the migration target has been bound.
     */
    public static function forRuntime(CommerceRolloutGateInterface $rollout): self
    {
        $registry = new VendorRegistryStore();
        $authorization = new VendorAuthorizationService();
        $stores = new StoreCatalog(
            ObjectManager::make(Store::class),
            ObjectManager::make(Website::class),
        );
        $accounts = new VendorStoreAccountBindingService($registry, $authorization, $stores);
        $eligibility = new VendorEligibilityService($registry, $authorization, $accounts);
        $products = ObjectManager::make(SkuRegistryService::class);
        if (!$products instanceof SkuRegistryService) {
            throw new \LogicException('ProductIdentityResolver binding is unavailable');
        }
        $bindings = new VendorProductBindingService($eligibility, $products);
        $acl = ObjectManager::create(
            VendorAclGuard::class,
            [],
            false,
        );
        if (!$acl instanceof VendorAclGuard) {
            throw new \LogicException('VendorAclGuard binding is unavailable');
        }
        $vendors = new VendorService(
            $registry,
            $authorization,
            $eligibility,
            $bindings,
            $acl,
            $rollout,
            $accounts,
        );
        $rules = new VendorSplitRuleStore();
        $snapshots = new VendorSplitSnapshotStore($eligibility, $rules, $registry);
        $payouts = new VendorPayoutLedger($snapshots);
        $reversals = new VendorRefundReversalService($payouts, $snapshots);

        return new self($vendors, $rules, $snapshots, $payouts, $reversals, $rollout);
    }

    public function vendors(): VendorService
    {
        return $this->vendors;
    }

    public function rules(): VendorSplitRuleStore
    {
        return $this->rules;
    }

    public function snapshots(): VendorSplitSnapshotStore
    {
        return $this->snapshots;
    }

    public function payouts(): VendorPayoutLedger
    {
        return $this->payouts;
    }

    public function reversals(): VendorRefundReversalService
    {
        return $this->reversals;
    }

    public function rollout(): CommerceRolloutGateInterface
    {
        return $this->rollout;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function upsertRule(array $input): array
    {
        $websiteId = (int) ($input['website_id'] ?? -1);
        VendorIdentity::assertWebsiteId($websiteId);
        $this->assertNewSplitAllowed($websiteId);
        $this->vendors->registry()->get((string) ($input['vendor_id'] ?? ''));
        $this->vendors->authorization()->assertAuthorized(
            (string) $input['vendor_id'],
            $websiteId,
        );

        return $this->rules->upsert($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function captureSnapshot(array $input): array
    {
        $websiteId = (int) ($input['website_id'] ?? -1);
        VendorIdentity::assertWebsiteId($websiteId);
        $this->assertNewSplitAllowed($websiteId, (int) ($input['store_id'] ?? 0));

        return $this->snapshots->capture($input);
    }

    /**
     * @return array<string, mixed>
     */
    public function schedulePayout(string $snapshotId, ?string $idempotencyKey = null): array
    {
        // Existing settlement obligation — allowed under mode off.
        return $this->payouts->scheduleFromSnapshot($snapshotId, $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function reverseRefund(array $input): array
    {
        return $this->reversals->reverse($input);
    }

    /**
     * @return array<string, mixed>
     */
    public function reconcileReport(
        ?string $vendorId = null,
        ?string $environment = null,
        ?string $storeMode = null,
    ): array {
        $vendorId = $vendorId !== null ? trim($vendorId) : null;
        $environment = $environment !== null
            ? VendorIdentity::assertEnvironment($environment)
            : null;
        if ($storeMode !== null && !in_array($storeMode, ['normal', 'dev', 'test'], true)) {
            throw new \InvalidArgumentException(__('结算报表 Store mode 无效'));
        }
        $grossPayout = 0;
        $reversed = 0;
        $net = 0;
        $rows = [];
        foreach ($this->payouts->all() as $payout) {
            if ($vendorId !== null && (string) $payout['vendor_id'] !== $vendorId) {
                continue;
            }
            if ($environment !== null && (string) $payout['environment'] !== $environment) {
                continue;
            }
            if ($storeMode !== null
                && (string) ($payout['store_mode_snapshot'] ?? '') !== $storeMode
            ) {
                continue;
            }
            $grossPayout += (int) $payout['amount_minor'];
            $reversed += (int) $payout['reversed_minor'];
            $net += (int) $payout['net_minor'];
            $rows[] = $payout;
        }

        usort(
            $rows,
            static fn (array $left, array $right): int
                => strcmp((string) $left['payout_id'], (string) $right['payout_id']),
        );
        $reversalIds = [];
        foreach ($this->reversals->all() as $reversal) {
            if ($vendorId !== null && (string) $reversal['vendor_id'] !== $vendorId) {
                continue;
            }
            if ($environment !== null && (string) $reversal['environment'] !== $environment) {
                continue;
            }
            if ($storeMode !== null
                && (string) ($reversal['store_mode_snapshot'] ?? '') !== $storeMode
            ) {
                continue;
            }
            $reversalIds[] = (string) $reversal['reversal_id'];
        }
        sort($reversalIds);
        $reversalCount = count($reversalIds);
        $snapshotIds = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['snapshot_id'],
            $rows,
        )));
        sort($snapshotIds);
        $scope = [
            'vendor_id' => $vendorId,
            'environment' => $environment,
            'store_mode' => $storeMode,
        ];
        $report = [
            'ok' => ($grossPayout - $reversed) === $net,
            'scope' => $scope,
            'payout_count' => count($rows),
            'reversal_count' => $reversalCount,
            'snapshot_count' => count($snapshotIds),
            'gross_payout_minor' => $grossPayout,
            'reversed_minor' => $reversed,
            'net_minor' => $net,
            'conserved' => ($grossPayout - $reversed) === $net,
            'payouts' => $rows,
        ];
        $report['report_hash'] = hash('sha256', json_encode([
            'scope' => $scope,
            'payout_ids' => array_values(array_map(
                static fn (array $row): string => (string) $row['payout_id'],
                $rows,
            )),
            'snapshot_ids' => $snapshotIds,
            'reversal_ids' => $reversalIds,
            'gross_payout_minor' => $grossPayout,
            'reversed_minor' => $reversed,
            'net_minor' => $net,
            'reversal_count' => $reversalCount,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $report;
    }

    private function assertNewSplitAllowed(int $websiteId, ?int $storeId = null): void
    {
        $mode = $this->rollout->mode(self::CAPABILITY);
        if ($mode === CommerceRolloutGateInterface::MODE_OFF) {
            throw new VendorConflictException(
                self::ERROR_MODE_OFF_NEW_SPLIT,
                \__('Vendor mode off：禁止新分账规则/快照，既有结算义务仍可继续'),
                ['capability' => self::CAPABILITY, 'website_id' => $websiteId],
            );
        }
        if ($storeId !== null && $storeId > 0) {
            $storeSubject = VendorRolloutGate::scopeKey($websiteId, $storeId);
            if ($this->rollout->isEffectivelyOn(self::CAPABILITY, $storeSubject)) {
                return;
            }
        }
        $this->rollout->assertMutable(self::CAPABILITY, 'website:' . $websiteId);
    }
}
