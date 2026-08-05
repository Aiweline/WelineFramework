<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Model\VendorSplitRuleRecord;

/**
 * Mutable Vendor commission rules by Vendor + Website.
 *
 * Production uses durable ORM records. Process-local storage is available only
 * through explicit forTesting().
 */
final class VendorSplitRuleStore
{
    private const MAX_CAS_ATTEMPTS = 8;

    public const ERROR_BPS = 'vendor_split_bps_invalid';
    public const ERROR_CURRENCY = 'vendor_split_currency_invalid';
    public const ERROR_NOT_FOUND = 'vendor_split_rule_not_found';
    public const ERROR_CONCURRENT = 'vendor_split_rule_concurrent_update';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rules = null;
    /** @var (\Closure(): VendorSplitRuleRecord)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): VendorSplitRuleRecord)|null $recordFactory */
    public function __construct(?callable $recordFactory = null, bool $useMemory = false)
    {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->rules = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /**
     * @param array{
     *   vendor_id:string,
     *   website_id:int,
     *   commission_bps:int,
     *   currency?:string,
     *   legal_entity?:string
     * } $input
     * @return array<string, mixed>
     */
    public function upsert(array $input): array
    {
        $vendorId = trim((string) ($input['vendor_id'] ?? ''));
        $websiteId = (int) ($input['website_id'] ?? -1);
        VendorIdentity::assertWebsiteId($websiteId);
        if ($vendorId === '') {
            throw new \InvalidArgumentException(__('Vendor id 必填'));
        }
        $bps = (int) ($input['commission_bps'] ?? -1);
        if ($bps < 0 || $bps > 10000) {
            throw new VendorConflictException(
                self::ERROR_BPS,
                __('commission_bps 必须在 0..10000：%{1}', [$bps]),
                ['commission_bps' => $bps],
            );
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'CNY')));
        if (preg_match('/^[A-Z]{3,8}$/D', $currency) !== 1) {
            throw new VendorConflictException(
                self::ERROR_CURRENCY,
                __('分账规则 currency 无效：%{1}', [$currency]),
            );
        }
        $legalEntity = trim((string) ($input['legal_entity'] ?? ''));
        if (strlen($legalEntity) > 255) {
            throw new \InvalidArgumentException(__('legal_entity 过长'));
        }

        if ($this->rules !== null) {
            $key = $this->key($vendorId, $websiteId);
            $row = [
                'vendor_id' => $vendorId,
                'website_id' => $websiteId,
                'commission_bps' => $bps,
                'currency' => $currency,
                'legal_entity' => $legalEntity,
                'rule_version' => isset($this->rules[$key])
                    ? ((int) $this->rules[$key]['rule_version'] + 1)
                    : 1,
                'cas_token' => bin2hex(random_bytes(32)),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $this->rules[$key] = $row;
            return $row;
        }

        $last = null;
        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $existing = $this->findModel($vendorId, $websiteId);
            $token = bin2hex(random_bytes(32));
            $updatedAt = date('Y-m-d H:i:s');
            try {
                if ($existing === null) {
                    $this->newRecord()->clear()->setData([
                        VendorSplitRuleRecord::schema_fields_VENDOR_ID => $vendorId,
                        VendorSplitRuleRecord::schema_fields_WEBSITE_ID => $websiteId,
                        VendorSplitRuleRecord::schema_fields_COMMISSION_BPS => $bps,
                        VendorSplitRuleRecord::schema_fields_CURRENCY => $currency,
                        VendorSplitRuleRecord::schema_fields_LEGAL_ENTITY => $legalEntity,
                        VendorSplitRuleRecord::schema_fields_RULE_VERSION => 1,
                        VendorSplitRuleRecord::schema_fields_CAS_TOKEN => $token,
                        VendorSplitRuleRecord::schema_fields_UPDATED_AT => $updatedAt,
                    ])->save();
                } else {
                    $expectedVersion = (int) $existing->getData(
                        VendorSplitRuleRecord::schema_fields_RULE_VERSION,
                    );
                    $expectedToken = (string) $existing->getData(
                        VendorSplitRuleRecord::schema_fields_CAS_TOKEN,
                    );
                    $candidate = $this->newRecord();
                    $candidate->getQuery(false)
                        ->where(VendorSplitRuleRecord::schema_fields_ID, (int) $existing->getId())
                        ->where(VendorSplitRuleRecord::schema_fields_RULE_VERSION, $expectedVersion)
                        ->where(VendorSplitRuleRecord::schema_fields_CAS_TOKEN, $expectedToken)
                        ->update([
                            VendorSplitRuleRecord::schema_fields_COMMISSION_BPS => $bps,
                            VendorSplitRuleRecord::schema_fields_CURRENCY => $currency,
                            VendorSplitRuleRecord::schema_fields_LEGAL_ENTITY => $legalEntity,
                            VendorSplitRuleRecord::schema_fields_RULE_VERSION => $expectedVersion + 1,
                            VendorSplitRuleRecord::schema_fields_CAS_TOKEN => $token,
                            VendorSplitRuleRecord::schema_fields_UPDATED_AT => $updatedAt,
                        ])
                        ->fetch();
                }
                $saved = $this->findModel($vendorId, $websiteId);
                if ($saved !== null && hash_equals(
                    $token,
                    (string) $saved->getData(VendorSplitRuleRecord::schema_fields_CAS_TOKEN),
                )) {
                    return $saved->getData();
                }
            } catch (Throwable $e) {
                $last = $e;
            }
        }

        throw new VendorConflictException(
            self::ERROR_CONCURRENT,
            __('分账规则并发更新冲突'),
            ['vendor_id' => $vendorId, 'website_id' => $websiteId],
            0,
            $last,
        );
    }

    /** @return array<string, mixed> */
    public function get(string $vendorId, int $websiteId): array
    {
        VendorIdentity::assertWebsiteId($websiteId);
        if ($this->rules !== null) {
            $row = $this->rules[$this->key($vendorId, $websiteId)] ?? null;
        } else {
            $row = $this->findModel($vendorId, $websiteId)?->getData();
        }
        if ($row === null) {
            throw new VendorConflictException(
                self::ERROR_NOT_FOUND,
                __('分账规则不存在：%{1}/%{2}', [$vendorId, $websiteId]),
                ['vendor_id' => $vendorId, 'website_id' => $websiteId],
            );
        }
        return $row;
    }

    private function findModel(string $vendorId, int $websiteId): ?VendorSplitRuleRecord
    {
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorSplitRuleRecord::schema_fields_VENDOR_ID, trim($vendorId))
            ->where(VendorSplitRuleRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    private function newRecord(): VendorSplitRuleRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(VendorSplitRuleRecord::class, [], false);
    }

    private function key(string $vendorId, int $websiteId): string
    {
        return trim($vendorId) . ':' . $websiteId;
    }
}
