<?php

declare(strict_types=1);

namespace Weline\SessionManager\Service\Persistence;

use Weline\SessionManager\Api\Persistence\DeviceRepositoryInterface;
use Weline\SessionManager\Model\AuthenticatedDevice;
use Weline\SessionManager\Model\RememberedDeviceCredential;

final class OrmDeviceRepository implements DeviceRepositoryInterface
{
    private const DEVICE_TIME_FIELDS = [
        AuthenticatedDevice::schema_fields_FIRST_SEEN_AT,
        AuthenticatedDevice::schema_fields_LAST_SEEN_AT,
        AuthenticatedDevice::schema_fields_SESSION_EXPIRES_AT,
        AuthenticatedDevice::schema_fields_REMEMBERED_UNTIL,
        AuthenticatedDevice::schema_fields_REVOKED_AT,
        AuthenticatedDevice::schema_fields_CREATED_AT,
        AuthenticatedDevice::schema_fields_UPDATED_AT,
    ];

    private const CREDENTIAL_TIME_FIELDS = [
        RememberedDeviceCredential::schema_fields_EXPIRES_AT,
        RememberedDeviceCredential::schema_fields_LAST_USED_AT,
        RememberedDeviceCredential::schema_fields_REVOKED_AT,
        RememberedDeviceCredential::schema_fields_CREATED_AT,
        RememberedDeviceCredential::schema_fields_UPDATED_AT,
    ];

    public function __construct(
        private readonly AuthenticatedDevice $devicePrototype,
        private readonly RememberedDeviceCredential $credentialPrototype,
    ) {
    }

    public function transaction(callable $callback): mixed
    {
        $transaction = $this->newDevice();
        $transaction->beginTransaction();
        try {
            $result = $callback();
            $transaction->commit();
            return $result;
        } catch (\Throwable $exception) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
            }
            throw $exception;
        }
    }

    public function findDeviceBySessionDigest(string $area, string $sessionDigest): ?array
    {
        $model = $this->newDevice()
            ->where(AuthenticatedDevice::schema_fields_AUTH_AREA, $area)
            ->where(AuthenticatedDevice::schema_fields_SESSION_DIGEST, $sessionDigest)
            ->find()
            ->fetch();
        return $model->getId() ? $this->deviceRecord((array)$model->getData()) : null;
    }

    public function findDeviceByPublicId(string $area, string $publicId): ?array
    {
        $model = $this->newDevice()
            ->where(AuthenticatedDevice::schema_fields_AUTH_AREA, $area)
            ->where(AuthenticatedDevice::schema_fields_PUBLIC_ID, $publicId)
            ->find()
            ->fetch();
        return $model->getId() ? $this->deviceRecord((array)$model->getData()) : null;
    }

    public function findDeviceById(int $deviceId): ?array
    {
        if ($deviceId <= 0) {
            return null;
        }
        $model = $this->newDevice()->load($deviceId);
        return $model->getId() ? $this->deviceRecord((array)$model->getData()) : null;
    }

    public function insertDevice(array $record): array
    {
        $model = $this->newDevice();
        $model->setData($this->deviceDatabaseData($record))->save();
        return $this->deviceRecord((array)$model->getData());
    }

    public function updateDevice(int $deviceId, array $changes): array
    {
        $model = $this->newDevice()->load($deviceId);
        if (!$model->getId()) {
            throw new \RuntimeException((string)__('设备记录不存在。'));
        }
        $model->setData($this->deviceDatabaseData($changes))->save();
        return $this->deviceRecord((array)$model->getData());
    }

    public function listDevices(string $area, string $principalId): array
    {
        $rows = $this->newDevice()
            ->where(AuthenticatedDevice::schema_fields_AUTH_AREA, $area)
            ->where(AuthenticatedDevice::schema_fields_PRINCIPAL_ID, $principalId)
            ->select()
            ->fetchArray();
        return array_map(fn(array $row): array => $this->deviceRecord($row), $rows);
    }

    public function findCredentialByDigest(string $tokenDigest): ?array
    {
        $model = $this->newCredential()
            ->where(RememberedDeviceCredential::schema_fields_TOKEN_DIGEST, $tokenDigest)
            ->find()
            ->fetch();
        return $model->getId() ? $this->credentialRecord((array)$model->getData()) : null;
    }

    public function findCredentialByDeviceId(int $deviceId): ?array
    {
        $model = $this->newCredential()
            ->where(RememberedDeviceCredential::schema_fields_DEVICE_ID, $deviceId)
            ->find()
            ->fetch();
        return $model->getId() ? $this->credentialRecord((array)$model->getData()) : null;
    }

    public function upsertCredential(int $deviceId, array $record): array
    {
        $model = $this->newCredential()
            ->where(RememberedDeviceCredential::schema_fields_DEVICE_ID, $deviceId)
            ->find()
            ->fetch();
        if (!$model->getId()) {
            $model = $this->newCredential();
            $model->setData(RememberedDeviceCredential::schema_fields_DEVICE_ID, $deviceId);
        }
        $model->setData($this->credentialDatabaseData($record))->save();
        return $this->credentialRecord((array)$model->getData());
    }

    public function updateCredential(int $credentialId, array $changes): array
    {
        $model = $this->newCredential()->load($credentialId);
        if (!$model->getId()) {
            throw new \RuntimeException((string)__('记住登录凭证不存在。'));
        }
        $model->setData($this->credentialDatabaseData($changes))->save();
        return $this->credentialRecord((array)$model->getData());
    }

    public function consumeCredential(
        int $credentialId,
        string $expectedTokenDigest,
        int $consumedAt,
        string $claim,
    ): bool {
        if ($credentialId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $expectedTokenDigest) !== 1
            || $consumedAt <= 0
            || $claim === ''
            || strlen($claim) > 64) {
            return false;
        }

        $candidate = $this->newCredential();
        $candidate->getQuery(false)
            ->where(RememberedDeviceCredential::schema_fields_ID, $credentialId)
            ->where(RememberedDeviceCredential::schema_fields_TOKEN_DIGEST, $expectedTokenDigest)
            ->where(RememberedDeviceCredential::schema_fields_REVOKED_AT, null, 'IS NULL')
            ->update([
                RememberedDeviceCredential::schema_fields_LAST_USED_AT => $this->formatTime($consumedAt),
                RememberedDeviceCredential::schema_fields_REVOKED_AT => $this->formatTime($consumedAt),
                RememberedDeviceCredential::schema_fields_REVOKE_REASON => $claim,
                RememberedDeviceCredential::schema_fields_UPDATED_AT => $this->formatTime($consumedAt),
            ])
            ->fetch();

        // UPDATE return values vary between the supported adapters. A unique,
        // non-secret claim lets the caller confirm that this request won the
        // guarded write without depending on adapter-specific row counts.
        $consumed = $this->newCredential()
            ->where(RememberedDeviceCredential::schema_fields_ID, $credentialId)
            ->find()
            ->fetch();
        return $consumed->getId()
            && hash_equals(
                $expectedTokenDigest,
                (string)$consumed->getData(RememberedDeviceCredential::schema_fields_TOKEN_DIGEST),
            )
            && hash_equals(
                $claim,
                (string)$consumed->getData(RememberedDeviceCredential::schema_fields_REVOKE_REASON),
            );
    }

    public function cleanupRetiredBefore(int $timestamp): void
    {
        $cutoff = $this->formatTime($timestamp);
        $rows = [];
        foreach ([
            [AuthenticatedDevice::schema_fields_REVOKED_AT, $cutoff],
            [AuthenticatedDevice::schema_fields_SESSION_EXPIRES_AT, $cutoff],
        ] as [$field, $value]) {
            $result = $this->newDevice()
                ->where($field, $value, '<')
                ->select()
                ->fetchArray();
            foreach ($result as $row) {
                $record = $this->deviceRecord($row);
                $rows[(int)$record['id']] = $record;
            }
        }

        $deviceIds = [];
        foreach ($rows as $deviceId => $record) {
            $revokedAt = (int)($record['revoked_at'] ?? 0);
            $lastCapabilityExpiry = max(
                (int)($record['session_expires_at'] ?? 0),
                (int)($record['remembered_until'] ?? 0),
            );
            if (($revokedAt > 0 && $revokedAt < $timestamp)
                || ($revokedAt === 0 && $lastCapabilityExpiry > 0 && $lastCapabilityExpiry < $timestamp)) {
                $deviceIds[] = $deviceId;
            }
        }
        if ($deviceIds === []) {
            return;
        }

        $this->transaction(function () use ($deviceIds): void {
            $this->newCredential()
                ->where(RememberedDeviceCredential::schema_fields_DEVICE_ID, $deviceIds, 'IN')
                ->delete()
                ->fetch();
            $this->newDevice()
                ->where(AuthenticatedDevice::schema_fields_ID, $deviceIds, 'IN')
                ->delete()
                ->fetch();
        });
    }

    private function newDevice(): AuthenticatedDevice
    {
        return (clone $this->devicePrototype)->clearData()->clearQuery();
    }

    private function newCredential(): RememberedDeviceCredential
    {
        return (clone $this->credentialPrototype)->clearData()->clearQuery();
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function deviceDatabaseData(array $record): array
    {
        unset($record['id']);
        foreach (self::DEVICE_TIME_FIELDS as $field) {
            if (array_key_exists($field, $record)) {
                $record[$field] = $this->nullableFormattedTime($record[$field]);
            }
        }
        return $record;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function credentialDatabaseData(array $record): array
    {
        unset($record['id'], $record['device_id']);
        foreach (self::CREDENTIAL_TIME_FIELDS as $field) {
            if (array_key_exists($field, $record)) {
                $record[$field] = $this->nullableFormattedTime($record[$field]);
            }
        }
        return $record;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function deviceRecord(array $row): array
    {
        $row['id'] = (int)($row[AuthenticatedDevice::schema_fields_ID] ?? 0);
        foreach (self::DEVICE_TIME_FIELDS as $field) {
            $row[$field] = $this->timestamp($row[$field] ?? null);
        }
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function credentialRecord(array $row): array
    {
        $row['id'] = (int)($row[RememberedDeviceCredential::schema_fields_ID] ?? 0);
        $row['device_id'] = (int)($row[RememberedDeviceCredential::schema_fields_DEVICE_ID] ?? 0);
        foreach (self::CREDENTIAL_TIME_FIELDS as $field) {
            $row[$field] = $this->timestamp($row[$field] ?? null);
        }
        return $row;
    }

    private function nullableFormattedTime(mixed $value): ?string
    {
        $timestamp = $this->timestamp($value);
        return $timestamp > 0 ? $this->formatTime($timestamp) : null;
    }

    private function formatTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function timestamp(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int)$value;
        }
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : $timestamp;
    }
}
