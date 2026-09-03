<?php

declare(strict_types=1);

namespace Weline\SessionManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;
use Weline\Framework\Session\Auth\Device\AuthenticatedLoginContext;
use Weline\SessionManager\Api\DeviceInstallKeyProviderInterface;
use Weline\SessionManager\Api\DeviceMetadataProviderInterface;
use Weline\SessionManager\Api\Persistence\DeviceRepositoryInterface;
use Weline\SessionManager\Data\DeviceMetadata;
use Weline\SessionManager\Service\AuthenticatedDeviceRegistry;

final class AuthenticatedDeviceRegistryTest extends TestCase
{
    private InMemoryDeviceRepository $repository;
    private FakeInstallKeyProvider $installKeys;
    private AuthenticatedDeviceRegistry $registry;

    protected function setUp(): void
    {
        $this->repository = new InMemoryDeviceRepository();
        $this->installKeys = new FakeInstallKeyProvider();
        $this->registry = new AuthenticatedDeviceRegistry(
            $this->repository,
            new FixedDeviceMetadataProvider(),
            $this->installKeys,
        );
    }

    public function testRegistersMultipleDevicesAndKeepsAreaAndOwnerIsolation(): void
    {
        $frontendA = $this->context('frontend', '7', 'frontend-a');
        $frontendB = $this->context('frontend', '7', 'frontend-b');
        $otherCustomer = $this->context('frontend', '8', 'frontend-other');
        $backend = $this->context('backend', '7', 'backend-a');

        $this->installKeys->nextRaw = 'install-browser-a';
        $deviceA = $this->registry->register($frontendA);
        usleep(1000);
        $this->installKeys->nextRaw = 'install-browser-b';
        $deviceB = $this->registry->register($frontendB);
        $this->installKeys->nextRaw = 'install-browser-other';
        $this->registry->register($otherCustomer);
        $this->installKeys->nextRaw = 'install-backend-a';
        $this->registry->register($backend);

        self::assertTrue($deviceA->valid);
        self::assertTrue($deviceB->valid);
        self::assertNotSame($deviceA->deviceId, $deviceB->deviceId);

        $page = $this->registry->listForOwner('frontend', '7', $frontendA, 1, 20);
        self::assertSame(2, $page['total']);
        self::assertCount(2, $page['items']);
        self::assertSame($deviceA->deviceId, $page['items'][0]['device_id']);
        self::assertTrue($page['items'][0]['is_current']);
        self::assertSame($deviceB->deviceId, $page['items'][1]['device_id']);
        self::assertFalse($page['items'][1]['is_current']);
    }

    public function testPasswordLoginReusesSameInstallKeyDevice(): void
    {
        $this->installKeys->nextRaw = 'same-mac-chrome';
        $first = $this->context('frontend', '42', 'session-1');
        $firstBinding = $this->registry->register($first);
        $this->registry->revokeCurrent($first->withDeviceId($firstBinding->deviceId), 'relogin');

        $second = $this->context('frontend', '42', 'session-2');
        $secondBinding = $this->registry->register($second);

        self::assertTrue($secondBinding->valid);
        self::assertSame($firstBinding->deviceId, $secondBinding->deviceId);
        $page = $this->registry->listForOwner('frontend', '42', $second, 1, 20);
        self::assertSame(1, $page['total']);
        self::assertTrue($page['items'][0]['is_current']);
    }

    public function testSharedSessionRotationRebindsFrontendDeviceInsteadOfReboundLogout(): void
    {
        $this->installKeys->nextRaw = 'shared-browser';
        $before = $this->context('frontend', '47', 'session-before-backend-login');
        $binding = $this->registry->register($before);
        self::assertTrue($binding->valid);

        // Backend login regenerates the shared Session id; AuthenticatedSession
        // then calls rebindToCurrentSession for surviving sibling device ids.
        $after = $this->context('frontend', '47', 'session-after-backend-login')
            ->withDeviceId((string)$binding->deviceId);
        $this->installKeys->nextRaw = 'shared-browser';
        $rebinding = $this->registry->rebindToCurrentSession($after);

        self::assertTrue($rebinding->valid);
        self::assertSame($binding->deviceId, $rebinding->deviceId);
        self::assertTrue($this->registry->validate($after)->valid);
        self::assertFalse($this->registry->validate($before->withDeviceId((string)$binding->deviceId))->valid);
        $device = $this->repository->findDeviceByPublicId('frontend', (string)$binding->deviceId);
        self::assertNotNull($device);
        self::assertSame(
            hash('sha256', 'session-after-backend-login'),
            (string)($device['session_digest'] ?? ''),
        );
    }

    public function testValidateKeepsSessionReboundWithoutExplicitRebind(): void
    {
        $this->installKeys->nextRaw = 'browser-a';
        $before = $this->context('frontend', '47', 'session-old');
        $binding = $this->registry->register($before);

        $stranger = $this->context('frontend', '47', 'session-stranger')
            ->withDeviceId((string)$binding->deviceId);
        $this->installKeys->nextRaw = 'browser-a';
        $validation = $this->registry->validate($stranger);

        self::assertFalse($validation->valid);
        self::assertSame('session_rebound', $validation->reason);
    }

    public function testPasswordLoginReplacedRevokesCredentialOnly(): void
    {
        $this->installKeys->nextRaw = 'keep-device-row';
        $context = $this->context('frontend', '55', 'cred-only-session');
        $binding = $this->registry->register($context);
        $context = $context->withDeviceId($binding->deviceId);
        $credential = $this->registry->issueCredential($context, time() + 7200);

        $this->registry->revokeCredential('frontend', $credential->token, 'password_login_replaced');

        self::assertTrue($this->registry->validate($context)->valid);
        self::assertFalse($this->registry->resolveCredential('frontend', $credential->token)->valid);
        $device = $this->repository->findDeviceByPublicId('frontend', (string)$binding->deviceId);
        self::assertNotNull($device);
        self::assertSame(0, (int)($device['revoked_at'] ?? 0));
        self::assertSame(0, (int)($device['remembered_until'] ?? -1));
    }

    public function testRememberRestoreRebindsOneDeviceAndRotatesOnlyItsCredential(): void
    {
        $this->installKeys->nextRaw = 'remember-a';
        $originalA = $this->context('frontend', '17', 'session-a');
        $deviceA = $this->registry->register($originalA);
        $this->installKeys->nextRaw = 'remember-b';
        $originalB = $this->context('frontend', '17', 'session-b');
        $deviceB = $this->registry->register($originalB);
        $originalA = $originalA->withDeviceId($deviceA->deviceId);
        $originalB = $originalB->withDeviceId($deviceB->deviceId);
        $credentialA = $this->registry->issueCredential($originalA, time() + 7200);
        $credentialB = $this->registry->issueCredential($originalB, time() + 7200);

        $resolvedA = $this->registry->resolveCredential('frontend', $credentialA->token);
        self::assertTrue($resolvedA->valid);
        self::assertSame($deviceA->deviceId, $resolvedA->deviceId);

        $restoredA = $this->context('frontend', '17', 'session-a-rotated');
        $this->installKeys->nextRaw = 'remember-a';
        $binding = $this->registry->register(
            $restoredA,
            AuthenticatedLoginContext::remembered((string)$resolvedA->deviceId),
        );
        $restoredA = $restoredA->withDeviceId($binding->deviceId);
        $rotatedA = $this->registry->issueCredential($restoredA, time() + 7200);

        self::assertSame($deviceA->deviceId, $binding->deviceId);
        self::assertFalse($this->registry->validate($originalA)->valid);
        self::assertTrue($this->registry->validate($restoredA)->valid);
        self::assertFalse($this->registry->resolveCredential('frontend', $credentialA->token)->valid);
        self::assertTrue($this->registry->resolveCredential('frontend', $rotatedA->token)->valid);
        $resolvedB = $this->registry->resolveCredential('frontend', $credentialB->token);
        self::assertTrue($resolvedB->valid);
        self::assertSame($deviceB->deviceId, $resolvedB->deviceId);
    }

    public function testRememberedCredentialCanOnlyBeConsumedOnce(): void
    {
        $this->installKeys->nextRaw = 'single-use';
        $context = $this->context('frontend', '18', 'single-use-session');
        $binding = $this->registry->register($context);
        $credential = $this->registry->issueCredential($context, time() + 7200);

        $first = $this->registry->resolveCredential('frontend', $credential->token);
        $second = $this->registry->resolveCredential('frontend', $credential->token);
        $device = $this->repository->findDeviceByPublicId('frontend', (string)$binding->deviceId);

        self::assertTrue($first->valid);
        self::assertFalse($second->valid);
        self::assertSame('invalid_or_expired', $second->reason);
        self::assertSame(0, (int)($device['remembered_until'] ?? -1));
    }

    public function testRemoteRevokeProtectsCurrentDeviceAndIsIdempotentForAnotherDevice(): void
    {
        $this->installKeys->nextRaw = 'backend-current';
        $current = $this->context('backend', '1', 'backend-current');
        $currentDevice = $this->registry->register($current);
        $this->installKeys->nextRaw = 'backend-other';
        $other = $this->context('backend', '1', 'backend-other');
        $otherDevice = $this->registry->register($other);
        $otherCredential = $this->registry->issueCredential($other, time() + 7200);

        $protected = $this->registry->revokeForOwner(
            'backend',
            '1',
            (string)$currentDevice->deviceId,
            $current,
        );
        self::assertFalse($protected['success']);
        self::assertSame('current_device_logout_required', $protected['code']);

        $revoked = $this->registry->revokeForOwner(
            'backend',
            '1',
            (string)$otherDevice->deviceId,
            $current,
        );
        $again = $this->registry->revokeForOwner(
            'backend',
            '1',
            (string)$otherDevice->deviceId,
            $current,
        );
        self::assertTrue($revoked['success']);
        self::assertTrue($again['success']);
        self::assertFalse($this->registry->validate($other)->valid);
        self::assertFalse($this->registry->resolveCredential('backend', $otherCredential->token)->valid);
    }

    public function testRemoteRevokeRollsBackWhenCredentialUpdateFails(): void
    {
        $this->installKeys->nextRaw = 'tx-current';
        $current = $this->context('frontend', '33', 'tx-current');
        $this->registry->register($current);
        $this->installKeys->nextRaw = 'tx-other';
        $other = $this->context('frontend', '33', 'tx-other');
        $otherDevice = $this->registry->register($other);
        $credential = $this->registry->issueCredential($other, time() + 7200);
        $this->repository->failNextCredentialUpdate = true;

        try {
            $this->registry->revokeForOwner(
                'frontend',
                '33',
                (string)$otherDevice->deviceId,
                $current,
            );
            self::fail('Credential update failure must abort the remote revoke transaction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated credential update failure', $exception->getMessage());
        }

        self::assertTrue($this->registry->validate($other)->valid);
        self::assertTrue($this->registry->resolveCredential('frontend', $credential->token)->valid);
    }

    public function testValidationPersistsActivityAtMostOncePerMinute(): void
    {
        $this->installKeys->nextRaw = 'touch-key';
        $context = $this->context('frontend', '91', 'touch-session');
        $binding = $this->registry->register($context);
        $device = $this->repository->findDeviceByPublicId('frontend', (string)$binding->deviceId);
        self::assertNotNull($device);
        $this->repository->devices[(int)$device['id']]['last_seen_at'] = time() - 61;

        self::assertTrue($this->registry->validate($context)->valid);
        self::assertSame(1, $this->repository->updateDeviceCalls);

        self::assertTrue($this->registry->validate($context)->valid);
        self::assertSame(1, $this->repository->updateDeviceCalls);
    }

    private function context(string $area, string $principalId, string $sessionId): AuthenticatedDeviceContext
    {
        return new AuthenticatedDeviceContext(
            area: $area,
            principalId: $principalId,
            sessionId: $sessionId,
            sessionExpiresAt: time() + 3600,
        );
    }
}

final class FixedDeviceMetadataProvider implements DeviceMetadataProviderInterface
{
    public function current(): DeviceMetadata
    {
        return new DeviceMetadata(
            deviceName: 'Chrome on macOS',
            browser: 'Chrome 130',
            operatingSystem: 'macOS',
            ipAddress: '127.0.0.1',
        );
    }
}

final class FakeInstallKeyProvider implements DeviceInstallKeyProviderInterface
{
    public string $nextRaw = 'default-install-key';

    public function ensure(string $area): array
    {
        $raw = $this->nextRaw;
        return [
            'raw' => $raw,
            'digest' => hash('sha256', $raw),
        ];
    }
}

final class InMemoryDeviceRepository implements DeviceRepositoryInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $devices = [];

    /** @var array<int,array<string,mixed>> */
    public array $credentials = [];

    public bool $throwDuplicateAfterNextInsert = false;
    public bool $failNextCredentialUpdate = false;
    public int $updateDeviceCalls = 0;
    private int $nextDeviceId = 1;
    private int $nextCredentialId = 1;

    public function transaction(callable $callback): mixed
    {
        $devices = $this->devices;
        $credentials = $this->credentials;
        try {
            return $callback();
        } catch (\Throwable $exception) {
            $this->devices = $devices;
            $this->credentials = $credentials;
            throw $exception;
        }
    }

    public function findDeviceBySessionDigest(string $area, string $sessionDigest): ?array
    {
        foreach ($this->devices as $device) {
            if ($device['auth_area'] === $area && $device['session_digest'] === $sessionDigest) {
                return $device;
            }
        }
        return null;
    }

    public function findDeviceByPublicId(string $area, string $publicId): ?array
    {
        foreach ($this->devices as $device) {
            if ($device['auth_area'] === $area && $device['public_id'] === $publicId) {
                return $device;
            }
        }
        return null;
    }

    public function findDeviceById(int $deviceId): ?array
    {
        return $this->devices[$deviceId] ?? null;
    }

    public function findActiveDevicesByInstallKey(
        string $area,
        string $principalId,
        string $installKeyDigest,
    ): array {
        $matches = [];
        foreach ($this->devices as $device) {
            if ($device['auth_area'] !== $area
                || (string)$device['principal_id'] !== $principalId
                || (string)($device['install_key_digest'] ?? '') !== $installKeyDigest
                || (int)($device['revoked_at'] ?? 0) > 0) {
                continue;
            }
            $matches[] = $device;
        }
        usort(
            $matches,
            static fn(array $left, array $right): int => ((int)($right['last_seen_at'] ?? 0))
                <=> ((int)($left['last_seen_at'] ?? 0)),
        );
        return $matches;
    }

    public function insertDevice(array $record): array
    {
        $record['id'] = $this->nextDeviceId++;
        $this->devices[$record['id']] = $record;
        if ($this->throwDuplicateAfterNextInsert) {
            $this->throwDuplicateAfterNextInsert = false;
            throw new \RuntimeException('simulated duplicate insert race');
        }
        return $record;
    }

    public function updateDevice(int $deviceId, array $changes): array
    {
        $this->updateDeviceCalls++;
        $this->devices[$deviceId] = array_replace($this->devices[$deviceId], $changes);
        return $this->devices[$deviceId];
    }

    public function listDevices(string $area, string $principalId): array
    {
        return array_values(array_filter(
            $this->devices,
            static fn(array $device): bool => $device['auth_area'] === $area
                && $device['principal_id'] === $principalId,
        ));
    }

    public function findCredentialByDigest(string $tokenDigest): ?array
    {
        foreach ($this->credentials as $credential) {
            if ($credential['token_digest'] === $tokenDigest) {
                return $credential;
            }
        }
        return null;
    }

    public function findCredentialByDeviceId(int $deviceId): ?array
    {
        foreach ($this->credentials as $credential) {
            if ($credential['device_id'] === $deviceId) {
                return $credential;
            }
        }
        return null;
    }

    public function upsertCredential(int $deviceId, array $record): array
    {
        $existing = $this->findCredentialByDeviceId($deviceId);
        if ($existing !== null) {
            $record['id'] = $existing['id'];
            $record['device_id'] = $deviceId;
            $this->credentials[$existing['id']] = array_replace($existing, $record);
            return $this->credentials[$existing['id']];
        }
        $record['id'] = $this->nextCredentialId++;
        $record['device_id'] = $deviceId;
        $this->credentials[$record['id']] = $record;
        return $record;
    }

    public function updateCredential(int $credentialId, array $changes): array
    {
        if ($this->failNextCredentialUpdate) {
            $this->failNextCredentialUpdate = false;
            throw new \RuntimeException('simulated credential update failure');
        }
        $this->credentials[$credentialId] = array_replace($this->credentials[$credentialId], $changes);
        return $this->credentials[$credentialId];
    }

    public function consumeCredential(
        int $credentialId,
        string $expectedTokenDigest,
        int $consumedAt,
        string $claim,
    ): bool {
        $credential = $this->credentials[$credentialId] ?? null;
        if ($credential === null
            || !hash_equals((string)$credential['token_digest'], $expectedTokenDigest)
            || (int)($credential['revoked_at'] ?? 0) > 0) {
            return false;
        }
        $this->credentials[$credentialId] = array_replace($credential, [
            'last_used_at' => $consumedAt,
            'revoked_at' => $consumedAt,
            'revoke_reason' => $claim,
            'updated_at' => $consumedAt,
        ]);
        return true;
    }

    public function cleanupRetiredBefore(int $timestamp): void
    {
        foreach ($this->devices as $deviceId => $device) {
            $revokedAt = (int)($device['revoked_at'] ?? 0);
            $expiredAt = max(
                (int)($device['session_expires_at'] ?? 0),
                (int)($device['remembered_until'] ?? 0),
            );
            if (($revokedAt > 0 && $revokedAt < $timestamp)
                || ($revokedAt === 0 && $expiredAt > 0 && $expiredAt < $timestamp)) {
                unset($this->devices[$deviceId]);
                foreach ($this->credentials as $credentialId => $credential) {
                    if ($credential['device_id'] === $deviceId) {
                        unset($this->credentials[$credentialId]);
                    }
                }
            }
        }
    }
}
