<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\SystemConfig\Model\ConfigPackageConsumption;

/**
 * ORM 持久化消费账本：唯一索引 CAS，重放零写入。
 */
final class OrmConfigPackageConsumptionLedger implements ConfigPackageConsumptionLedgerInterface
{
    public function __construct(
        private readonly ConfigPackageConsumption $model = new ConfigPackageConsumption(),
    ) {
    }

    public function claim(array $row): void
    {
        $uuid = \trim((string)($row['package_uuid'] ?? ''));
        if (\preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
            $uuid,
        ) !== 1) {
            throw new \InvalidArgumentException('config_envelope_package_uuid_invalid');
        }
        $kid = \trim((string)($row['recipient_kid'] ?? ''));
        $scopeKey = \trim((string)($row['scope_key'] ?? ''));
        $sourceInstance = \trim((string)($row['source_instance'] ?? ''));
        $filename = \trim((string)($row['filename'] ?? ''));
        $payloadHash = \strtolower(\trim((string)($row['payload_hash'] ?? '')));
        if (\preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/Di', $kid) !== 1) {
            throw new \InvalidArgumentException('config_envelope_kid_invalid');
        }
        if ($scopeKey === '' || \strlen($scopeKey) > 191) {
            throw new \InvalidArgumentException('config_envelope_scope_invalid');
        }
        if (\strlen($sourceInstance) > 120 || \strlen($filename) > 255) {
            throw new \InvalidArgumentException('config_envelope_audit_identity_invalid');
        }
        if ($payloadHash !== '' && \preg_match('/^[a-f0-9]{64}$/D', $payloadHash) !== 1) {
            throw new \InvalidArgumentException('config_envelope_payload_hash_invalid');
        }
        if ($this->isConsumed($uuid)) {
            throw new \RuntimeException('config_envelope_package_replayed');
        }
        $model = clone $this->model;
        $model->clear();
        try {
            $model->setData([
                ConfigPackageConsumption::schema_fields_PACKAGE_UUID => $uuid,
                ConfigPackageConsumption::schema_fields_RECIPIENT_KID => $kid,
                ConfigPackageConsumption::schema_fields_SCOPE_KEY => $scopeKey,
                ConfigPackageConsumption::schema_fields_SOURCE_INSTANCE => $sourceInstance,
                ConfigPackageConsumption::schema_fields_FILENAME => $filename,
                ConfigPackageConsumption::schema_fields_STATUS => ConfigPackageConsumption::STATUS_CLAIMED,
                ConfigPackageConsumption::schema_fields_PAYLOAD_HASH => $payloadHash,
                ConfigPackageConsumption::schema_fields_CONSUMED_AT => \date('Y-m-d H:i:s'),
            ])->save();
        } catch (\Throwable $e) {
            if ($this->isConsumed($uuid)) {
                throw new \RuntimeException('config_envelope_package_replayed', 0, $e);
            }
            throw $e;
        }
    }

    public function markApplied(string $packageUuid): void
    {
        $this->updateStatus($packageUuid, ConfigPackageConsumption::STATUS_APPLIED);
    }

    public function markFailed(string $packageUuid): void
    {
        $this->updateStatus($packageUuid, ConfigPackageConsumption::STATUS_FAILED);
    }

    public function isConsumed(string $packageUuid): bool
    {
        $model = clone $this->model;
        $model->clear();
        $hit = $model
            ->where(ConfigPackageConsumption::schema_fields_PACKAGE_UUID, $packageUuid)
            ->find()
            ->fetch();

        return $hit instanceof ConfigPackageConsumption && (bool)$hit->getId();
    }

    private function updateStatus(string $packageUuid, string $status): void
    {
        $model = clone $this->model;
        $model->clear();
        $updated = $model
            ->where(ConfigPackageConsumption::schema_fields_PACKAGE_UUID, $packageUuid)
            ->update([
                ConfigPackageConsumption::schema_fields_STATUS => $status,
            ])
            ->fetch();
        if ((int)$updated < 1) {
            throw new \RuntimeException('config_envelope_package_not_claimed');
        }

        $model->clear();
        $hit = $model
            ->where(ConfigPackageConsumption::schema_fields_PACKAGE_UUID, $packageUuid)
            ->where(ConfigPackageConsumption::schema_fields_STATUS, $status)
            ->find()
            ->fetch();
        if (!$hit instanceof ConfigPackageConsumption || !$hit->getId()) {
            throw new \RuntimeException('config_envelope_package_status_not_persisted');
        }
    }
}
