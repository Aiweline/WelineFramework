<?php

declare(strict_types=1);

namespace Weline\SessionManager\Api\Persistence;

interface DeviceRepositoryInterface
{
    public function transaction(callable $callback): mixed;

    /** @return array<string,mixed>|null */
    public function findDeviceBySessionDigest(string $area, string $sessionDigest): ?array;

    /** @return array<string,mixed>|null */
    public function findDeviceByPublicId(string $area, string $publicId): ?array;

    /** @return array<string,mixed>|null */
    public function findDeviceById(int $deviceId): ?array;

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function insertDevice(array $record): array;

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function updateDevice(int $deviceId, array $changes): array;

    /** @return list<array<string,mixed>> */
    public function listDevices(string $area, string $principalId): array;

    /** @return array<string,mixed>|null */
    public function findCredentialByDigest(string $tokenDigest): ?array;

    /** @return array<string,mixed>|null */
    public function findCredentialByDeviceId(int $deviceId): ?array;

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function upsertCredential(int $deviceId, array $record): array;

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function updateCredential(int $credentialId, array $changes): array;

    public function consumeCredential(
        int $credentialId,
        string $expectedTokenDigest,
        int $consumedAt,
        string $claim,
    ): bool;

    public function cleanupRetiredBefore(int $timestamp): void;
}
