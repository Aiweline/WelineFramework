<?php

declare(strict_types=1);

namespace Weline\SessionManager\Service;

use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceRegistryInterface;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceValidation;
use Weline\Framework\Session\Auth\Device\AuthenticatedLoginContext;
use Weline\Framework\Session\Auth\Device\IssuedRememberedDeviceCredential;
use Weline\Framework\Session\Auth\Device\RememberedDeviceCredentialProviderInterface;
use Weline\Framework\Session\Auth\Device\RememberedDeviceCredentialValidation;
use Weline\SessionManager\Api\DeviceMetadataProviderInterface;
use Weline\SessionManager\Api\Persistence\DeviceRepositoryInterface;

final class AuthenticatedDeviceRegistry implements
    AuthenticatedDeviceRegistryInterface,
    RememberedDeviceCredentialProviderInterface
{
    private const TOUCH_INTERVAL_SECONDS = 60;
    private const RETENTION_SECONDS = 30 * 86400;

    public function __construct(
        private readonly DeviceRepositoryInterface $repository,
        private readonly DeviceMetadataProviderInterface $metadataProvider,
    ) {
    }

    public function supportsArea(string $area): bool
    {
        return $this->normalizeArea($area) !== '';
    }

    public function register(
        AuthenticatedDeviceContext $context,
        ?AuthenticatedLoginContext $loginContext = null,
    ): AuthenticatedDeviceValidation {
        $area = $this->requiredArea($context->area);
        $this->repository->cleanupRetiredBefore(time() - self::RETENTION_SECONDS);
        if ($loginContext?->source === AuthenticatedLoginContext::SOURCE_REMEMBERED) {
            return $this->resumeDevice($area, $context, (string)$loginContext->deviceId);
        }
        return $this->bindNewOrExistingSession($area, $context);
    }

    public function validate(AuthenticatedDeviceContext $context): AuthenticatedDeviceValidation
    {
        $area = $this->requiredArea($context->area);
        $digest = $this->digest($context->sessionId);
        $device = $context->deviceId !== null && trim($context->deviceId) !== ''
            ? $this->repository->findDeviceByPublicId($area, trim($context->deviceId))
            : $this->repository->findDeviceBySessionDigest($area, $digest);
        if ($device === null) {
            if ($context->deviceId !== null && trim($context->deviceId) !== '') {
                return AuthenticatedDeviceValidation::invalid('device_binding_missing');
            }
            // Deployment compatibility: an authenticated pre-1.1.0 Session is adopted
            // on first access. The unique realm/session digest index converges races.
            return $this->bindNewOrExistingSession($area, $context);
        }
        if (!$this->sameOwner($device, $context->principalId)) {
            return AuthenticatedDeviceValidation::invalid('owner_mismatch');
        }
        if ($this->isRevoked($device)) {
            return AuthenticatedDeviceValidation::invalid('revoked');
        }
        if (!hash_equals((string)$device['session_digest'], $digest)) {
            return AuthenticatedDeviceValidation::invalid('session_rebound');
        }

        $now = time();
        if ((int)($device['last_seen_at'] ?? 0) <= $now - self::TOUCH_INTERVAL_SECONDS) {
            $metadata = $this->metadataProvider->current();
            $device = $this->repository->updateDevice((int)$device['id'], [
                'device_name' => $metadata->deviceName,
                'browser' => $metadata->browser,
                'operating_system' => $metadata->operatingSystem,
                'last_ip' => $metadata->ipAddress,
                'last_seen_at' => $now,
                'session_expires_at' => max($now + 1, $context->sessionExpiresAt),
                'updated_at' => $now,
            ]);
        }
        return AuthenticatedDeviceValidation::valid((string)$device['public_id']);
    }

    public function revokeCurrent(AuthenticatedDeviceContext $context, string $reason = 'logout'): void
    {
        $area = $this->requiredArea($context->area);
        $digest = $this->digest($context->sessionId);
        $device = $context->deviceId !== null && trim($context->deviceId) !== ''
            ? $this->repository->findDeviceByPublicId($area, trim($context->deviceId))
            : $this->repository->findDeviceBySessionDigest($area, $digest);
        if ($device === null || !$this->sameOwner($device, $context->principalId) || $this->isRevoked($device)) {
            return;
        }
        if (!hash_equals((string)$device['session_digest'], $digest)) {
            return;
        }
        $this->revokeDevice($device, $reason);
    }

    public function issueCredential(
        AuthenticatedDeviceContext $context,
        int $expiresAt,
    ): IssuedRememberedDeviceCredential {
        $area = $this->requiredArea($context->area);
        $now = time();
        if ($expiresAt <= $now) {
            throw new \InvalidArgumentException((string)__('记住登录到期时间无效。'));
        }
        $device = $this->repository->findDeviceBySessionDigest($area, $this->digest($context->sessionId));
        if ($device === null || !$this->sameOwner($device, $context->principalId) || $this->isRevoked($device)) {
            throw new \RuntimeException((string)__('当前认证设备不可用。'));
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $rawToken = $this->randomPublicValue();
            try {
                $this->repository->transaction(function () use ($device, $rawToken, $expiresAt, $now): void {
                    $this->repository->upsertCredential((int)$device['id'], [
                        'token_digest' => $this->digest($rawToken),
                        'expires_at' => $expiresAt,
                        'last_used_at' => $now,
                        'revoked_at' => 0,
                        'revoke_reason' => '',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $this->repository->updateDevice((int)$device['id'], [
                        'remembered_until' => $expiresAt,
                        'updated_at' => $now,
                    ]);
                });
                return new IssuedRememberedDeviceCredential(
                    token: $rawToken,
                    deviceId: (string)$device['public_id'],
                    expiresAt: $expiresAt,
                );
            } catch (\Throwable $exception) {
                if ($attempt === 1) {
                    throw $exception;
                }
            }
        }
        throw new \RuntimeException((string)__('记住登录凭证签发失败。'));
    }

    public function resolveCredential(
        string $area,
        string $rawToken,
    ): RememberedDeviceCredentialValidation {
        $normalizedArea = $this->requiredArea($area);
        $rawToken = trim($rawToken);
        if ($rawToken === '' || strlen($rawToken) > 512) {
            return RememberedDeviceCredentialValidation::invalid('invalid_token');
        }
        $tokenDigest = $this->digest($rawToken);
        $now = time();
        $claim = $this->boundedReason('remember_consumed_' . $this->randomPublicValue());

        return $this->repository->transaction(function () use (
            $normalizedArea,
            $tokenDigest,
            $now,
            $claim,
        ): RememberedDeviceCredentialValidation {
            $credential = $this->repository->findCredentialByDigest($tokenDigest);
            if ($credential === null
                || (int)($credential['revoked_at'] ?? 0) > 0
                || (int)($credential['expires_at'] ?? 0) <= $now) {
                return RememberedDeviceCredentialValidation::invalid('invalid_or_expired');
            }
            $device = $this->repository->findDeviceById((int)$credential['device_id']);
            if ($device === null
                || (string)$device['auth_area'] !== $normalizedArea
                || $this->isRevoked($device)) {
                return RememberedDeviceCredentialValidation::invalid('device_unavailable');
            }
            if (!$this->repository->consumeCredential(
                (int)$credential['id'],
                $tokenDigest,
                $now,
                $claim,
            )) {
                return RememberedDeviceCredentialValidation::invalid('credential_already_consumed');
            }
            $this->repository->updateDevice((int)$device['id'], [
                'remembered_until' => 0,
                'updated_at' => $now,
            ]);
            return RememberedDeviceCredentialValidation::valid(
                principalId: (string)$device['principal_id'],
                deviceId: (string)$device['public_id'],
                expiresAt: (int)$credential['expires_at'],
            );
        });
    }

    public function revokeCredential(
        string $area,
        string $rawToken,
        string $reason = 'logout',
    ): void {
        $normalizedArea = $this->requiredArea($area);
        if (trim($rawToken) === '') {
            return;
        }
        $credential = $this->repository->findCredentialByDigest($this->digest($rawToken));
        if ($credential === null) {
            return;
        }
        $device = $this->repository->findDeviceById((int)$credential['device_id']);
        if ($device === null || (string)$device['auth_area'] !== $normalizedArea) {
            return;
        }
        if (!$this->isRevoked($device)) {
            // A remembered credential represents the same browser-profile
            // device. Revoking it must retire the device binding as well;
            // otherwise password-login replacement leaves a ghost active
            // session row after Session regeneration destroyed the old id.
            $this->revokeDevice($device, $reason);
            return;
        }
        if ((int)($credential['revoked_at'] ?? 0) === 0) {
            $now = time();
            $this->repository->updateCredential((int)$credential['id'], [
                'revoked_at' => $now,
                'revoke_reason' => $this->boundedReason($reason),
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,page:int,page_size:int}
     */
    public function listForOwner(
        string $area,
        string $principalId,
        AuthenticatedDeviceContext $currentContext,
        int $page = 1,
        int $pageSize = 20,
    ): array {
        $normalizedArea = $this->requiredArea($area);
        if ($normalizedArea !== $this->requiredArea($currentContext->area)
            || (string)$currentContext->principalId !== (string)$principalId) {
            throw new \RuntimeException((string)__('认证身份区域不匹配。'));
        }
        $this->repository->cleanupRetiredBefore(time() - self::RETENTION_SECONDS);
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $now = time();
        $currentDigest = $this->digest($currentContext->sessionId);
        $items = [];
        foreach ($this->repository->listDevices($normalizedArea, (string)$principalId) as $device) {
            if ($this->isRevoked($device)) {
                continue;
            }
            $sessionActive = (int)($device['session_expires_at'] ?? 0) > $now;
            $rememberedActive = (int)($device['remembered_until'] ?? 0) > $now;
            if (!$sessionActive && !$rememberedActive) {
                continue;
            }
            $isCurrent = hash_equals((string)$device['session_digest'], $currentDigest);
            $items[] = [
                'device_id' => (string)$device['public_id'],
                'name' => (string)($device['device_name'] ?? ''),
                'browser' => (string)($device['browser'] ?? ''),
                'os' => (string)($device['operating_system'] ?? ''),
                'last_ip' => (string)($device['last_ip'] ?? ''),
                'first_seen_at' => $this->isoTime((int)($device['first_seen_at'] ?? 0)),
                'last_seen_at' => $this->isoTime((int)($device['last_seen_at'] ?? 0)),
                'remembered_until' => $rememberedActive
                    ? $this->isoTime((int)$device['remembered_until'])
                    : null,
                'status' => $isCurrent ? 'current' : ($sessionActive ? 'active' : 'remembered'),
                'is_current' => $isCurrent,
                '_sort_last_seen' => (int)($device['last_seen_at'] ?? 0),
            ];
        }
        usort($items, static function (array $left, array $right): int {
            $currentOrder = ((int)$right['is_current']) <=> ((int)$left['is_current']);
            return $currentOrder !== 0
                ? $currentOrder
                : ((int)$right['_sort_last_seen'] <=> (int)$left['_sort_last_seen']);
        });
        $total = count($items);
        $items = array_slice($items, ($page - 1) * $pageSize, $pageSize);
        foreach ($items as &$item) {
            unset($item['_sort_last_seen']);
        }
        unset($item);
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /** @return array{success:bool,code:string,message:string} */
    public function revokeForOwner(
        string $area,
        string $principalId,
        string $publicDeviceId,
        AuthenticatedDeviceContext $currentContext,
    ): array {
        $normalizedArea = $this->requiredArea($area);
        if ($normalizedArea !== $this->requiredArea($currentContext->area)
            || (string)$currentContext->principalId !== (string)$principalId) {
            return $this->notFoundResult();
        }
        $publicDeviceId = trim($publicDeviceId);
        if ($publicDeviceId === '' || strlen($publicDeviceId) > 100) {
            return $this->notFoundResult();
        }
        $device = $this->repository->findDeviceByPublicId($normalizedArea, $publicDeviceId);
        if ($device === null || !$this->sameOwner($device, $principalId)) {
            return $this->notFoundResult();
        }
        if (hash_equals((string)$device['session_digest'], $this->digest($currentContext->sessionId))) {
            return [
                'success' => false,
                'code' => 'current_device_logout_required',
                'message' => (string)__('当前设备请使用正常退出登录。'),
            ];
        }
        if (!$this->isRevoked($device)) {
            $this->revokeDevice($device, 'remote_logout');
        }
        return [
            'success' => true,
            'code' => 'device_revoked',
            'message' => (string)__('设备已下线。'),
        ];
    }

    private function bindNewOrExistingSession(
        string $area,
        AuthenticatedDeviceContext $context,
    ): AuthenticatedDeviceValidation {
        $digest = $this->digest($context->sessionId);
        $existing = $this->repository->findDeviceBySessionDigest($area, $digest);
        if ($existing !== null) {
            if (!$this->sameOwner($existing, $context->principalId) || $this->isRevoked($existing)) {
                return AuthenticatedDeviceValidation::invalid('session_binding_conflict');
            }
            return AuthenticatedDeviceValidation::valid((string)$existing['public_id']);
        }

        $now = time();
        $metadata = $this->metadataProvider->current();
        $record = [
            'public_id' => $this->randomPublicValue(),
            'auth_area' => $area,
            'principal_id' => (string)$context->principalId,
            'session_digest' => $digest,
            'device_name' => $metadata->deviceName,
            'browser' => $metadata->browser,
            'operating_system' => $metadata->operatingSystem,
            'last_ip' => $metadata->ipAddress,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'session_expires_at' => max($now + 1, $context->sessionExpiresAt),
            'remembered_until' => 0,
            'revoked_at' => 0,
            'revoke_reason' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        try {
            $device = $this->repository->insertDevice($record);
        } catch (\Throwable $exception) {
            $device = $this->repository->findDeviceBySessionDigest($area, $digest);
            if ($device === null) {
                throw $exception;
            }
        }
        if (!$this->sameOwner($device, $context->principalId) || $this->isRevoked($device)) {
            return AuthenticatedDeviceValidation::invalid('session_binding_conflict');
        }
        return AuthenticatedDeviceValidation::valid((string)$device['public_id']);
    }

    private function resumeDevice(
        string $area,
        AuthenticatedDeviceContext $context,
        string $publicDeviceId,
    ): AuthenticatedDeviceValidation {
        $device = $this->repository->findDeviceByPublicId($area, trim($publicDeviceId));
        if ($device === null || !$this->sameOwner($device, $context->principalId) || $this->isRevoked($device)) {
            return AuthenticatedDeviceValidation::invalid('remembered_device_unavailable');
        }
        $now = time();
        $metadata = $this->metadataProvider->current();
        $device = $this->repository->updateDevice((int)$device['id'], [
            'session_digest' => $this->digest($context->sessionId),
            'device_name' => $metadata->deviceName,
            'browser' => $metadata->browser,
            'operating_system' => $metadata->operatingSystem,
            'last_ip' => $metadata->ipAddress,
            'last_seen_at' => $now,
            'session_expires_at' => max($now + 1, $context->sessionExpiresAt),
            'updated_at' => $now,
        ]);
        return AuthenticatedDeviceValidation::valid((string)$device['public_id']);
    }

    /** @param array<string,mixed> $device */
    private function revokeDevice(array $device, string $reason): void
    {
        $now = time();
        $this->repository->transaction(function () use ($device, $reason, $now): void {
            $this->repository->updateDevice((int)$device['id'], [
                'revoked_at' => $now,
                'revoke_reason' => $this->boundedReason($reason),
                'remembered_until' => 0,
                'updated_at' => $now,
            ]);
            $credential = $this->repository->findCredentialByDeviceId((int)$device['id']);
            if ($credential !== null && (int)($credential['revoked_at'] ?? 0) === 0) {
                $this->repository->updateCredential((int)$credential['id'], [
                    'revoked_at' => $now,
                    'revoke_reason' => $this->boundedReason($reason),
                    'updated_at' => $now,
                ]);
            }
        });
    }

    private function requiredArea(string $area): string
    {
        $normalized = $this->normalizeArea($area);
        if ($normalized === '') {
            throw new \InvalidArgumentException((string)__('不支持的认证区域。'));
        }
        return $normalized;
    }

    private function normalizeArea(string $area): string
    {
        return match (strtolower(trim($area))) {
            'backend', 'rest_backend' => 'backend',
            'frontend', 'api', 'checkout' => 'frontend',
            default => '',
        };
    }

    /** @param array<string,mixed> $device */
    private function sameOwner(array $device, int|string $principalId): bool
    {
        return (string)($device['principal_id'] ?? '') === (string)$principalId;
    }

    /** @param array<string,mixed> $device */
    private function isRevoked(array $device): bool
    {
        return (int)($device['revoked_at'] ?? 0) > 0;
    }

    private function digest(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function randomPublicValue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function boundedReason(string $reason): string
    {
        $reason = preg_replace('/[^a-z0-9_.-]+/i', '_', trim($reason)) ?? '';
        return substr($reason === '' ? 'revoked' : $reason, 0, 64);
    }

    private function isoTime(int $timestamp): ?string
    {
        return $timestamp > 0 ? date(DATE_ATOM, $timestamp) : null;
    }

    /** @return array{success:false,code:string,message:string} */
    private function notFoundResult(): array
    {
        return [
            'success' => false,
            'code' => 'device_not_found',
            'message' => (string)__('设备不存在或不可操作。'),
        ];
    }
}
