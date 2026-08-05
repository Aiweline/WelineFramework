<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Model\VendorSplitSnapshot;
use Weline\Vendor\Model\VendorSplitSnapshotRecord;

/**
 * Write-once split snapshot store.
 *
 * The payable identity includes Vendor + Store + child Order + Payment so one
 * Checkout group may contain multiple Vendor Orders without collision.
 */
final class VendorSplitSnapshotStore
{
    public const ERROR_EXISTS = 'vendor_split_snapshot_exists';
    public const ERROR_NOT_FOUND = 'vendor_split_snapshot_not_found';
    public const ERROR_IMMUTABLE = 'vendor_split_snapshot_immutable';
    public const ERROR_CONSERVATION = 'vendor_split_amount_not_conserved';
    public const ERROR_GROSS = 'vendor_split_gross_invalid';
    public const ERROR_STORE = 'vendor_split_store_required';
    public const ERROR_CURRENCY = 'vendor_split_currency_mismatch';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $snapshots = null;
    /** @var array<string, string> */
    private array $byPayable = [];
    /** @var (\Closure(): VendorSplitSnapshotRecord)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): VendorSplitSnapshotRecord)|null $recordFactory */
    public function __construct(
        private readonly VendorEligibilityService $eligibility,
        private readonly VendorSplitRuleStore $rules,
        private readonly VendorRegistryStore $registry,
        ?callable $recordFactory = null,
        bool $useMemory = false,
    ) {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->snapshots = [];
        }
    }

    public static function forTesting(
        ?VendorEligibilityService $eligibility = null,
        ?VendorSplitRuleStore $rules = null,
        ?VendorRegistryStore $registry = null,
    ): self {
        $registry ??= VendorRegistryStore::forTesting();
        $auth = VendorAuthorizationService::forTesting();
        $eligibility ??= new VendorEligibilityService($registry, $auth);

        return new self(
            $eligibility,
            $rules ?? VendorSplitRuleStore::forTesting(),
            $registry,
            useMemory: true,
        );
    }

    /**
     * @param array{
     *   vendor_id:string,
     *   website_id:int,
     *   store_id:int,
     *   checkout_group_ref?:string,
     *   order_ref:string,
     *   payment_ref:string,
     *   gross_minor:int,
     *   currency?:string,
     *   required_environment?:string
     * } $input
     * @return array<string, mixed>
     */
    public function capture(array $input): array
    {
        $vendorId = trim((string) ($input['vendor_id'] ?? ''));
        $websiteId = (int) ($input['website_id'] ?? -1);
        $storeId = (int) ($input['store_id'] ?? 0);
        VendorIdentity::assertWebsiteId($websiteId);
        if ($storeId <= 0) {
            throw new VendorConflictException(self::ERROR_STORE, __('分账快照必须指定 Store'));
        }
        $orderRef = trim((string) ($input['order_ref'] ?? ''));
        $paymentRef = trim((string) ($input['payment_ref'] ?? ''));
        $checkoutGroupRef = trim((string) ($input['checkout_group_ref'] ?? ''));
        if ($orderRef === '' || $paymentRef === '') {
            throw new \InvalidArgumentException(__('order_ref 与 payment_ref 必填'));
        }
        if (strlen($orderRef) > 64 || strlen($paymentRef) > 64 || strlen($checkoutGroupRef) > 64) {
            throw new \InvalidArgumentException(__('Order/Payment/Checkout group reference 过长'));
        }
        $payableKey = $this->payableKey($vendorId, $storeId, $orderRef, $paymentRef);
        if ($this->findByPayable($vendorId, $storeId, $orderRef, $paymentRef) !== null) {
            throw $this->alreadyExists($payableKey, $orderRef, $paymentRef);
        }

        $req = [
            'vendor_id' => $vendorId,
            'website_id' => $websiteId,
            'store_id' => $storeId,
        ];
        if (array_key_exists('required_environment', $input) && $input['required_environment'] !== null) {
            $req['required_environment'] = (string) $input['required_environment'];
        }
        $eligible = $this->eligibility->assertEligible($req);
        $vendor = $eligible['vendor'];
        $accountBinding = $eligible['account_binding'];
        $rule = $this->rules->get($vendorId, $websiteId);

        $gross = (int) ($input['gross_minor'] ?? 0);
        if ($gross <= 0) {
            throw new VendorConflictException(
                self::ERROR_GROSS,
                __('gross_minor 必须为正：%{1}', [$gross]),
                ['gross_minor' => $gross],
            );
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? $rule['currency'])));
        if ($currency !== (string) $rule['currency']) {
            throw new VendorConflictException(
                self::ERROR_CURRENCY,
                __('快照 currency 必须与分账规则一致'),
                ['requested' => $currency, 'rule_currency' => $rule['currency']],
            );
        }
        $bps = (int) $rule['commission_bps'];
        $platformShare = $this->basisPoints($gross, $bps);
        $vendorShare = $gross - $platformShare;
        if ($vendorShare < 0
            || $platformShare < 0
            || $vendorShare > PHP_INT_MAX - $platformShare
            || $vendorShare + $platformShare !== $gross
        ) {
            throw new VendorConflictException(
                self::ERROR_CONSERVATION,
                __('分账金额不守恒'),
                [
                    'gross_minor' => $gross,
                    'vendor_share_minor' => $vendorShare,
                    'platform_share_minor' => $platformShare,
                ],
            );
        }

        $legal = [
            'legal_name' => (string) $vendor['legal_name'],
            'legal_entity' => (string) (
                $rule['legal_entity'] !== '' ? $rule['legal_entity'] : $vendor['legal_name']
            ),
            'vendor_code' => (string) $vendor['code'],
        ];
        $account = [
            'store_id' => $storeId,
            'store_mode_snapshot' => (string) $accountBinding['store_mode_snapshot'],
            'account_ref' => (string) $accountBinding['account_ref'],
            'account_ref_hash' => (string) $accountBinding['account_ref_hash'],
            'binding_version' => (int) $accountBinding['binding_version'],
            'environment' => (string) $accountBinding['environment'],
        ];
        $commission = [
            'commission_bps' => $bps,
            'rule_version' => (int) $rule['rule_version'],
            'basis' => 'gross_minor',
        ];
        $snapshotId = 'vss_' . substr(hash(
            'sha256',
            implode('|', [$vendorId, $websiteId, $storeId, $orderRef, $paymentRef, $gross, $currency]),
        ), 0, 24);
        $payload = [
            'snapshot_id' => $snapshotId,
            'schema_version' => VendorSplitSnapshot::SCHEMA_VERSION,
            'vendor_id' => $vendorId,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'store_mode_snapshot' => (string) $accountBinding['store_mode_snapshot'],
            'environment' => (string) $vendor['environment'],
            'checkout_group_ref' => $checkoutGroupRef,
            'order_ref' => $orderRef,
            'payment_ref' => $paymentRef,
            'currency' => $currency,
            'gross_minor' => $gross,
            'vendor_share_minor' => $vendorShare,
            'platform_share_minor' => $platformShare,
            'commission_bps' => $bps,
            'legal' => $legal,
            'account' => $account,
            'commission' => $commission,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        ksort($payload);
        $payload['payload_hash'] = hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        $snapshot = VendorSplitSnapshot::fromArray($payload)->toArray();

        if ($this->snapshots !== null) {
            $this->snapshots[$snapshotId] = $snapshot;
            $this->byPayable[$payableKey] = $snapshotId;
            return $snapshot;
        }

        try {
            $this->newRecord()->clear()->setData($this->recordData($snapshot))->save();
        } catch (Throwable $e) {
            if ($this->findByPayable($vendorId, $storeId, $orderRef, $paymentRef) !== null) {
                throw $this->alreadyExists($payableKey, $orderRef, $paymentRef, $e);
            }
            throw $e;
        }
        return $this->get($snapshotId);
    }

    /** @return array<string, mixed> */
    public function get(string $snapshotId): array
    {
        $snapshotId = trim($snapshotId);
        if ($this->snapshots !== null) {
            $row = $this->snapshots[$snapshotId] ?? null;
        } else {
            $row = $this->findModelById($snapshotId);
            $row = $row !== null ? $this->toSnapshot($row->getData()) : null;
        }
        if ($row === null) {
            throw new VendorConflictException(
                self::ERROR_NOT_FOUND,
                __('分账快照不存在：%{1}', [$snapshotId]),
                ['snapshot_id' => $snapshotId],
            );
        }
        return $row;
    }

    /** @param array<string, mixed> $_ignored */
    public function update(string $snapshotId, array $_ignored): never
    {
        throw new VendorConflictException(
            self::ERROR_IMMUTABLE,
            __('分账快照不可变：%{1}', [$snapshotId]),
            ['snapshot_id' => $snapshotId],
        );
    }

    public function count(): int
    {
        if ($this->snapshots !== null) {
            return count($this->snapshots);
        }
        return count($this->newRecord()->clear()->select()->fetchArray());
    }

    /** @return array<string, mixed>|null */
    private function findByPayable(
        string $vendorId,
        int $storeId,
        string $orderRef,
        string $paymentRef,
    ): ?array {
        if ($this->snapshots !== null) {
            $snapshotId = $this->byPayable[
                $this->payableKey($vendorId, $storeId, $orderRef, $paymentRef)
            ] ?? null;
            return $snapshotId !== null ? ($this->snapshots[$snapshotId] ?? null) : null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorSplitSnapshotRecord::schema_fields_VENDOR_ID, $vendorId)
            ->where(VendorSplitSnapshotRecord::schema_fields_STORE_ID, $storeId)
            ->where(VendorSplitSnapshotRecord::schema_fields_ORDER_REF, $orderRef)
            ->where(VendorSplitSnapshotRecord::schema_fields_PAYMENT_REF, $paymentRef)
            ->find()
            ->fetch();
        return $model->getId() ? $this->toSnapshot($model->getData()) : null;
    }

    private function findModelById(string $snapshotId): ?VendorSplitSnapshotRecord
    {
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorSplitSnapshotRecord::schema_fields_SNAPSHOT_ID, $snapshotId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function recordData(array $snapshot): array
    {
        return [
            VendorSplitSnapshotRecord::schema_fields_SNAPSHOT_ID => $snapshot['snapshot_id'],
            VendorSplitSnapshotRecord::schema_fields_SCHEMA_VERSION => $snapshot['schema_version'],
            VendorSplitSnapshotRecord::schema_fields_VENDOR_ID => $snapshot['vendor_id'],
            VendorSplitSnapshotRecord::schema_fields_WEBSITE_ID => $snapshot['website_id'],
            VendorSplitSnapshotRecord::schema_fields_STORE_ID => $snapshot['store_id'],
            VendorSplitSnapshotRecord::schema_fields_STORE_MODE => $snapshot['store_mode_snapshot'],
            VendorSplitSnapshotRecord::schema_fields_ENVIRONMENT => $snapshot['environment'],
            VendorSplitSnapshotRecord::schema_fields_CHECKOUT_GROUP_REF => $snapshot['checkout_group_ref'],
            VendorSplitSnapshotRecord::schema_fields_ORDER_REF => $snapshot['order_ref'],
            VendorSplitSnapshotRecord::schema_fields_PAYMENT_REF => $snapshot['payment_ref'],
            VendorSplitSnapshotRecord::schema_fields_CURRENCY => $snapshot['currency'],
            VendorSplitSnapshotRecord::schema_fields_GROSS_MINOR => $snapshot['gross_minor'],
            VendorSplitSnapshotRecord::schema_fields_VENDOR_SHARE_MINOR => $snapshot['vendor_share_minor'],
            VendorSplitSnapshotRecord::schema_fields_PLATFORM_SHARE_MINOR => $snapshot['platform_share_minor'],
            VendorSplitSnapshotRecord::schema_fields_COMMISSION_BPS => $snapshot['commission_bps'],
            VendorSplitSnapshotRecord::schema_fields_LEGAL_JSON => $this->json($snapshot['legal']),
            VendorSplitSnapshotRecord::schema_fields_ACCOUNT_JSON => $this->json($snapshot['account']),
            VendorSplitSnapshotRecord::schema_fields_COMMISSION_JSON => $this->json($snapshot['commission']),
            VendorSplitSnapshotRecord::schema_fields_PAYLOAD_HASH => $snapshot['payload_hash'],
            VendorSplitSnapshotRecord::schema_fields_CREATED_AT => $snapshot['created_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function toSnapshot(array $row): array
    {
        return VendorSplitSnapshot::fromArray([
            'snapshot_id' => $row[VendorSplitSnapshotRecord::schema_fields_SNAPSHOT_ID] ?? '',
            'schema_version' => $row[VendorSplitSnapshotRecord::schema_fields_SCHEMA_VERSION]
                ?? VendorSplitSnapshot::SCHEMA_VERSION,
            'vendor_id' => $row[VendorSplitSnapshotRecord::schema_fields_VENDOR_ID] ?? '',
            'website_id' => $row[VendorSplitSnapshotRecord::schema_fields_WEBSITE_ID] ?? -1,
            'store_id' => $row[VendorSplitSnapshotRecord::schema_fields_STORE_ID] ?? 0,
            'store_mode_snapshot' => $row[VendorSplitSnapshotRecord::schema_fields_STORE_MODE] ?? '',
            'environment' => $row[VendorSplitSnapshotRecord::schema_fields_ENVIRONMENT] ?? '',
            'checkout_group_ref' => $row[VendorSplitSnapshotRecord::schema_fields_CHECKOUT_GROUP_REF] ?? '',
            'order_ref' => $row[VendorSplitSnapshotRecord::schema_fields_ORDER_REF] ?? '',
            'payment_ref' => $row[VendorSplitSnapshotRecord::schema_fields_PAYMENT_REF] ?? '',
            'currency' => $row[VendorSplitSnapshotRecord::schema_fields_CURRENCY] ?? '',
            'gross_minor' => $row[VendorSplitSnapshotRecord::schema_fields_GROSS_MINOR] ?? 0,
            'vendor_share_minor' => $row[VendorSplitSnapshotRecord::schema_fields_VENDOR_SHARE_MINOR] ?? 0,
            'platform_share_minor' => $row[VendorSplitSnapshotRecord::schema_fields_PLATFORM_SHARE_MINOR] ?? 0,
            'commission_bps' => $row[VendorSplitSnapshotRecord::schema_fields_COMMISSION_BPS] ?? 0,
            'legal' => $this->decode($row[VendorSplitSnapshotRecord::schema_fields_LEGAL_JSON] ?? ''),
            'account' => $this->decode($row[VendorSplitSnapshotRecord::schema_fields_ACCOUNT_JSON] ?? ''),
            'commission' => $this->decode($row[VendorSplitSnapshotRecord::schema_fields_COMMISSION_JSON] ?? ''),
            'payload_hash' => $row[VendorSplitSnapshotRecord::schema_fields_PAYLOAD_HASH] ?? '',
            'created_at' => $row[VendorSplitSnapshotRecord::schema_fields_CREATED_AT] ?? '',
        ])->toArray();
    }

    private function basisPoints(int $gross, int $bps): int
    {
        $whole = intdiv($gross, 10000);
        $remainder = $gross % 10000;
        return ($whole * $bps) + intdiv($remainder * $bps, 10000);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function decode(mixed $json): array
    {
        $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function payableKey(
        string $vendorId,
        int $storeId,
        string $orderRef,
        string $paymentRef,
    ): string {
        return implode('|', [trim($vendorId), $storeId, trim($orderRef), trim($paymentRef)]);
    }

    private function alreadyExists(
        string $payableKey,
        string $orderRef,
        string $paymentRef,
        ?Throwable $previous = null,
    ): VendorConflictException {
        return new VendorConflictException(
            self::ERROR_EXISTS,
            __('分账快照已存在，禁止重算：%{1}', [$payableKey]),
            ['order_ref' => $orderRef, 'payment_ref' => $paymentRef],
            0,
            $previous,
        );
    }

    private function newRecord(): VendorSplitSnapshotRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(VendorSplitSnapshotRecord::class, [], false);
    }
}
