<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;
use Weline\Framework\Session\Auth\Device\AuthenticatedLoginContext;
use Weline\Framework\Session\Auth\Device\RememberedDeviceCredentialProviderInterface;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\Framework\Session\SessionFactory;

final class CustomerRememberDeviceService
{
    private const LEGACY_COOKIE = 'w_ut';
    private const DEVICE_COOKIE = 'w_frontend_ut';
    private const LEGACY_TYPE = 'remember_me';

    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviders,
        private readonly SessionFactory $sessionFactory,
        private readonly Customer $customerPrototype,
        private readonly CustomerToken $legacyTokenPrototype,
    ) {
    }

    public function issueForAuthenticatedCustomer(
        Customer $customer,
        int $rememberDuration,
        ?AuthenticatedSessionInterface $session = null,
    ): void {
        $session ??= $this->sessionFactory->createFrontendSession();
        $resolution = $this->runtimeProviders->resolveDetailed(RememberedDeviceCredentialProviderInterface::class);
        if ($resolution->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            if ($rememberDuration > 0) {
                $this->issueLegacy((int)$customer->getId(), $rememberDuration);
            }
            return;
        }
        try {
            $provider = $this->requiredProvider($resolution);
            if ($rememberDuration <= 0) {
                $rawToken = $this->readDeviceCookie();
                if ($rawToken !== '') {
                    $provider->revokeCredential('frontend', $rawToken, 'password_login_without_remember');
                    $this->clearDeviceCookie();
                }
                $this->removeOwnedLegacyCredential((int)$customer->getId());
                return;
            }

            $previousDeviceToken = $this->readDeviceCookie();
            if ($previousDeviceToken !== '') {
                // Password login defines a new browser-profile device. Retire a
                // credential left by the previously authenticated customer
                // before issuing the replacement, so a later issuance failure
                // cannot restore the previous account from this browser.
                $provider->revokeCredential('frontend', $previousDeviceToken, 'password_login_replaced');
                $this->clearDeviceCookie();
            }
            $this->removeOwnedLegacyCredential((int)$customer->getId());
            $expiresAt = time() + $rememberDuration;
            $issued = $provider->issueCredential(
                $this->context($session, (string)$customer->getId()),
                $expiresAt,
            );
            $this->writeDeviceCookie($issued->token, $rememberDuration);
        } catch (\Throwable) {
            try {
                $this->clearDeviceCookie();
            } catch (\Throwable) {
            }
            try {
                $session->logout();
            } catch (\Throwable) {
            }
            throw new \RuntimeException((string)__('认证设备服务暂时不可用，请稍后重试。'));
        }
    }

    public function restoreIfNeeded(?AuthenticatedSessionInterface $session = null): bool
    {
        $session ??= $this->sessionFactory->createFrontendSession();
        $resolution = $this->runtimeProviders->resolveDetailed(RememberedDeviceCredentialProviderInterface::class);
        if ($resolution->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            return $this->restoreLegacyOnly($session);
        }
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof RememberedDeviceCredentialProviderInterface) {
            // Configured capability failure is fail-closed and must not fall back to w_ut.
            return false;
        }
        $provider = $resolution->provider;
        if ($session->isLoggedIn()) {
            $this->migrateOwnedLegacyForAuthenticatedSession($session, $provider);
            return false;
        }

        $deviceToken = $this->readDeviceCookie();
        if ($deviceToken !== '') {
            return $this->restoreDeviceCredential($session, $provider, $deviceToken);
        }
        return $this->migrateLegacyCredential($session, $provider);
    }

    public function clearAfterLogout(int $customerId): void
    {
        $rawToken = $this->readDeviceCookie();
        if ($rawToken !== '') {
            try {
                $resolution = $this->runtimeProviders->resolveDetailed(
                    RememberedDeviceCredentialProviderInterface::class,
                );
                if ($resolution->isAvailable()
                    && $resolution->provider instanceof RememberedDeviceCredentialProviderInterface) {
                    $resolution->provider->revokeCredential('frontend', $rawToken, 'logout');
                }
            } catch (\Throwable) {
                // Local logout and cookie removal remain available during provider outages.
            }
        }
        $this->clearDeviceCookie();
        $this->removeOwnedLegacyCredential($customerId);
    }

    private function restoreDeviceCredential(
        AuthenticatedSessionInterface $session,
        RememberedDeviceCredentialProviderInterface $provider,
        string $rawToken,
    ): bool {
        $validation = $provider->resolveCredential('frontend', $rawToken);
        if (!$validation->valid || $validation->principalId === null || $validation->deviceId === null) {
            $this->clearDeviceCookie();
            return false;
        }
        $customer = $this->newCustomer()->load((int)$validation->principalId);
        if (!$customer->getId()) {
            $provider->revokeCredential('frontend', $rawToken, 'principal_missing');
            $this->clearDeviceCookie();
            return false;
        }
        try {
            $session->login($customer, AuthenticatedLoginContext::remembered($validation->deviceId));
            $customer->setSessionId($session->getId())->save();
            $remaining = max(1, $validation->expiresAt - time());
            $rotated = $provider->issueCredential(
                $this->context($session, (string)$customer->getId()),
                $validation->expiresAt,
            );
            $this->writeDeviceCookie($rotated->token, $remaining);
            return true;
        } catch (\Throwable) {
            try {
                $session->logout();
            } catch (\Throwable) {
            }
            return false;
        }
    }

    private function migrateLegacyCredential(
        AuthenticatedSessionInterface $session,
        RememberedDeviceCredentialProviderInterface $provider,
    ): bool {
        $legacyToken = $this->readLegacyCookie();
        if ($legacyToken === '') {
            return false;
        }
        $record = $this->findLegacyToken($legacyToken);
        if ($record === null) {
            // The shared legacy cookie may belong to another authentication area.
            return false;
        }
        if ($record->isExpired()) {
            $record->delete();
            $this->clearLegacyCookie();
            return false;
        }
        $customer = $this->newCustomer()->load($record->getUserId());
        if (!$customer->getId()) {
            $record->delete();
            $this->clearLegacyCookie();
            return false;
        }
        try {
            $session->login($customer, AuthenticatedLoginContext::legacyRemembered());
            $customer->setSessionId($session->getId())->save();
            $expiresAt = $record->getTokenExpireTime();
            $issued = $provider->issueCredential(
                $this->context($session, (string)$customer->getId()),
                $expiresAt,
            );
            $this->writeDeviceCookie($issued->token, max(1, $expiresAt - time()));
            $record->delete();
            $this->clearLegacyCookie();
            return true;
        } catch (\Throwable) {
            try {
                $session->logout();
            } catch (\Throwable) {
            }
            // Migration failures preserve the shared legacy cookie for a later retry.
            return false;
        }
    }

    private function migrateOwnedLegacyForAuthenticatedSession(
        AuthenticatedSessionInterface $session,
        RememberedDeviceCredentialProviderInterface $provider,
    ): void {
        if ($this->readDeviceCookie() !== '') {
            return;
        }
        $customerId = (int)($session->getUserId() ?? 0);
        $legacyToken = $this->readLegacyCookie();
        if ($customerId <= 0 || $legacyToken === '') {
            return;
        }
        $record = $this->findLegacyToken($legacyToken);
        if ($record === null || $record->getUserId() !== $customerId || $record->isExpired()) {
            return;
        }
        try {
            $expiresAt = $record->getTokenExpireTime();
            $issued = $provider->issueCredential($this->context($session, (string)$customerId), $expiresAt);
            $this->writeDeviceCookie($issued->token, max(1, $expiresAt - time()));
            $record->delete();
            $this->clearLegacyCookie();
        } catch (\Throwable) {
            // Do not clear a shared legacy cookie on an incomplete migration.
        }
    }

    private function restoreLegacyOnly(AuthenticatedSessionInterface $session): bool
    {
        if ($session->isLoggedIn()) {
            return false;
        }
        $legacyToken = $this->readLegacyCookie();
        if ($legacyToken === '') {
            return false;
        }
        $record = $this->findLegacyToken($legacyToken);
        if ($record === null) {
            return false;
        }
        if ($record->isExpired()) {
            $record->delete();
            $this->clearLegacyCookie();
            return false;
        }
        $customer = $this->newCustomer()->load($record->getUserId());
        if (!$customer->getId()) {
            $record->delete();
            $this->clearLegacyCookie();
            return false;
        }
        $session->login($customer);
        $customer->setSessionId($session->getId())->save();
        $record->updateLastUsedAt()->save();
        return true;
    }

    private function issueLegacy(int $customerId, int $duration): void
    {
        $token = CustomerToken::generateToken();
        $record = $this->newLegacyToken();
        $record->reset()
            ->where(CustomerToken::schema_fields_user_id, $customerId)
            ->where(CustomerToken::schema_fields_type, self::LEGACY_TYPE)
            ->delete()
            ->fetch();
        $record->reset()
            ->setUserId($customerId)
            ->setToken($token)
            ->setType(self::LEGACY_TYPE)
            ->setTokenExpireTime(time() + $duration)
            ->save();
        Cookie::set(self::LEGACY_COOKIE, $token, $duration, ['path' => '/']);
    }

    private function removeOwnedLegacyCredential(int $customerId): void
    {
        $rawToken = $this->readLegacyCookie();
        if ($rawToken === '') {
            return;
        }
        $record = $this->findLegacyToken($rawToken);
        if ($record === null) {
            return;
        }
        // Finding the row proves that the shared legacy cookie belongs to the
        // frontend realm. Clear it even when it belongs to the previous
        // customer used in this browser; otherwise that account could be
        // restored after the newly authenticated customer signs out.
        $record->delete();
        $this->clearLegacyCookie();
    }

    private function findLegacyToken(string $rawToken): ?CustomerToken
    {
        $record = $this->newLegacyToken()
            ->where(CustomerToken::schema_fields_token, $rawToken)
            ->where(CustomerToken::schema_fields_type, self::LEGACY_TYPE)
            ->find()
            ->fetch();
        return $record->getId() ? $record : null;
    }

    private function context(
        AuthenticatedSessionInterface $session,
        string $customerId,
    ): AuthenticatedDeviceContext {
        $sessionId = (string)$session->getId();
        if ($sessionId === '') {
            throw new \RuntimeException((string)__('当前认证会话无效。'));
        }
        $rawSession = $session->getSession();
        $ttl = method_exists($rawSession, 'getDefaultTtl')
            ? max(1, (int)$rawSession->getDefaultTtl())
            : 3600;
        $deviceId = $session->get(AuthenticatedDeviceContext::sessionKeyForArea('frontend'));
        return new AuthenticatedDeviceContext(
            area: 'frontend',
            principalId: $customerId,
            sessionId: $sessionId,
            sessionExpiresAt: time() + $ttl,
            deviceId: is_string($deviceId) && trim($deviceId) !== '' ? trim($deviceId) : null,
        );
    }

    private function requiredProvider(
        RuntimeProviderResolution $resolution,
    ): RememberedDeviceCredentialProviderInterface {
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof RememberedDeviceCredentialProviderInterface) {
            throw new \RuntimeException((string)__('认证设备服务不可用。'));
        }
        return $resolution->provider;
    }

    private function newCustomer(): Customer
    {
        return (clone $this->customerPrototype)->clearData()->clearQuery();
    }

    private function newLegacyToken(): CustomerToken
    {
        return (clone $this->legacyTokenPrototype)->clearData()->clearQuery();
    }

    private function readDeviceCookie(): string
    {
        return (string)Cookie::get(SessionCookieNameResolver::resolveFor(self::DEVICE_COOKIE), '');
    }

    private function readLegacyCookie(): string
    {
        return (string)Cookie::get(self::LEGACY_COOKIE, '');
    }

    private function writeDeviceCookie(string $token, int $lifetime): void
    {
        $secure = \w_env('server.https') === 'on';
        Cookie::set(
            SessionCookieNameResolver::resolveFor(self::DEVICE_COOKIE),
            $token,
            $lifetime,
            [
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => SessionCookieNameResolver::resolveSameSite($secure),
            ],
        );
    }

    private function clearDeviceCookie(): void
    {
        $secure = \w_env('server.https') === 'on';
        Cookie::set(
            SessionCookieNameResolver::resolveFor(self::DEVICE_COOKIE),
            '',
            -3600,
            [
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => SessionCookieNameResolver::resolveSameSite($secure),
            ],
        );
    }

    private function clearLegacyCookie(): void
    {
        Cookie::set(self::LEGACY_COOKIE, '', -3600, ['path' => '/']);
    }
}
