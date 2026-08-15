<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Backend\Api\Auth\BackendInteractiveAuthInterface;
use Weline\Backend\Api\Auth\BackendLoginAccount;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;
use Weline\Framework\Session\Auth\Device\AuthenticatedLoginContext;
use Weline\Framework\Session\Auth\Device\RememberedDeviceCredentialProviderInterface;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\Framework\Session\SessionFactory;

class BackendRememberLoginService
{
    private const LEGACY_COOKIE = 'w_ut';
    private const DEVICE_COOKIE = 'w_backend_ut';
    private const DEFAULT_TTL = 7 * 24 * 60 * 60;

    private ?object $lastRestoredSession = null;
    private ?array $lastRestoredAclContext = null;

    public function __construct(
        private readonly Request $request,
        private readonly SessionFactory $sessionFactory,
        private readonly BackendInteractiveAuthInterface $backendAuth,
        private readonly MessageManager $messageManager,
        private ?RuntimeProviderResolver $runtimeProviders = null,
    ) {
    }

    public function restoreIfNeeded(?Request $request = null): bool
    {
        $this->lastRestoredSession = null;
        $this->lastRestoredAclContext = null;
        $request ??= $this->request;
        $route = trim((string)$request->getRouteUrlPath(), '/');
        if ($route === 'admin/login/post') {
            return false;
        }

        $session = $this->getBackendSession();
        if (method_exists($session, 'isLoggedIn') && $session->isLoggedIn()) {
            $this->migrateAuthenticatedLegacy($session);
            return false;
        }

        $resolution = $this->resolver()->resolveDetailed(RememberedDeviceCredentialProviderInterface::class);
        if ($resolution->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            return $this->restoreLegacyOnly($session, $request, $route);
        }
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof RememberedDeviceCredentialProviderInterface) {
            return false;
        }
        $provider = $resolution->provider;
        try {
            $deviceToken = $this->readDeviceRememberToken();
            if ($deviceToken !== '') {
                return $this->restoreDeviceCredential($session, $request, $route, $provider, $deviceToken);
            }
            return $this->migrateLegacyCredential($session, $request, $route, $provider);
        } catch (\Throwable) {
            return false;
        }
    }

    public function configureRememberedLogin(
        BackendLoginAccount $account,
        bool $remember,
        object $session,
        int $ttl = self::DEFAULT_TTL,
    ): void {
        $ttl = max(1, $ttl);
        $resolution = $this->resolver()->resolveDetailed(RememberedDeviceCredentialProviderInterface::class);
        if ($resolution->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            if ($remember) {
                $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $expiresAt = time() + $ttl;
                $this->backendAuth->storeRememberToken($account->getId(), $rawToken, $expiresAt);
                $this->writeLegacyCookie($rawToken, $ttl);
                $session->set('remember_expire_time', $expiresAt);
            } else {
                $this->removeOwnedLegacyCredential($account->getId());
                $this->backendAuth->invalidateRememberTokenForUser($account->getId());
                $session->delete('remember_expire_time');
            }
            return;
        }
        try {
            $provider = $this->requiredProvider($resolution);
            if (!$remember) {
                $existingToken = $this->readDeviceRememberToken();
                if ($existingToken !== '') {
                    $provider->revokeCredential('backend', $existingToken, 'password_login_without_remember');
                }
                $this->clearDeviceCookie();
                $this->removeOwnedLegacyCredential($account->getId());
                $session->delete('remember_expire_time');
                return;
            }

            $oldDeviceToken = $this->readDeviceRememberToken();
            if ($oldDeviceToken !== '') {
                // A password login starts a new device record. Revoke any
                // credential left by a previous administrator before issuing
                // the replacement so partial failure cannot restore that user.
                $provider->revokeCredential('backend', $oldDeviceToken, 'password_login_replaced');
                $this->clearDeviceCookie();
            }
            $this->removeOwnedLegacyCredential($account->getId());
            $expiresAt = time() + $ttl;
            $issued = $provider->issueCredential($this->context($session, (string)$account->getId()), $expiresAt);
            $this->writeDeviceCookie($issued->token, $ttl);
            $session->set('remember_expire_time', $expiresAt);
        } catch (\Throwable) {
            try {
                $this->clearDeviceCookie();
            } catch (\Throwable) {
            }
            try {
                if (method_exists($session, 'logout')) {
                    $session->logout();
                }
            } catch (\Throwable) {
            }
            throw new \RuntimeException((string)__('认证设备服务暂时不可用，请稍后重试。'));
        }
    }

    public function clearAfterLogout(int $userId): void
    {
        $rawToken = $this->readDeviceRememberToken();
        if ($rawToken !== '') {
            try {
                $resolution = $this->resolver()->resolveDetailed(
                    RememberedDeviceCredentialProviderInterface::class,
                );
                if ($resolution->isAvailable()
                    && $resolution->provider instanceof RememberedDeviceCredentialProviderInterface) {
                    $resolution->provider->revokeCredential('backend', $rawToken, 'logout');
                }
            } catch (\Throwable) {
                // Local logout and cookie removal remain available during provider outages.
            }
        }
        $this->clearDeviceCookie();
        $this->removeOwnedLegacyCredential($userId);
        if ($userId > 0) {
            $this->backendAuth->invalidateRememberTokenForUser($userId);
        }
    }

    public function consumeRestoredSession(): ?object
    {
        $session = $this->lastRestoredSession;
        $this->lastRestoredSession = null;
        return $session;
    }

    public function consumeRestoredAclContext(): ?array
    {
        $context = $this->lastRestoredAclContext;
        $this->lastRestoredAclContext = null;
        return $context;
    }

    private function restoreDeviceCredential(
        object $session,
        Request $request,
        string $route,
        RememberedDeviceCredentialProviderInterface $provider,
        string $rawToken,
    ): bool {
        $validation = $provider->resolveCredential('backend', $rawToken);
        if (!$validation->valid || $validation->principalId === null || $validation->deviceId === null) {
            $this->clearDeviceCookie();
            $session->delete('remember_expire_time');
            return false;
        }
        $account = $this->backendAuth->find((int)$validation->principalId);
        if ($account === null || $account->getIsDeleted() || !$account->getIsEnabled()) {
            $provider->revokeCredential('backend', $rawToken, 'principal_unavailable');
            $this->clearDeviceCookie();
            return false;
        }
        try {
            $this->backendAuth->restoreRememberedSession(
                $session,
                $account,
                $validation->expiresAt,
                AuthenticatedLoginContext::remembered($validation->deviceId),
            );
            $account = $this->backendAuth->completeLogin(
                $account->getId(),
                (string)$session->getId(),
                $request->clientIP(),
            );
            $rotated = $provider->issueCredential(
                $this->context($session, (string)$account->getId()),
                $validation->expiresAt,
            );
            $this->writeDeviceCookie($rotated->token, max(1, $validation->expiresAt - time()));
            $this->markRestored($session, $account, $route);
            return true;
        } catch (\Throwable) {
            $this->clearRestoredSession($session);
            return false;
        }
    }

    private function migrateLegacyCredential(
        object $session,
        Request $request,
        string $route,
        RememberedDeviceCredentialProviderInterface $provider,
    ): bool {
        $legacyToken = $this->readRememberToken();
        if ($legacyToken === '') {
            $session->delete('remember_expire_time');
            return false;
        }
        $token = $this->backendAuth->findRememberToken($legacyToken);
        if ($token === null) {
            // Shared w_ut can belong to Customer; do not clear it.
            return false;
        }
        if ($token->getExpireAt() <= time()) {
            $this->backendAuth->invalidateRememberToken($legacyToken);
            $this->clearLegacyCookie($request);
            $this->messageManager->addWarning(__('记住登录已过期，请重新登录！'));
            return false;
        }
        $account = $this->backendAuth->find($token->getUserId());
        if ($account === null || $account->getIsDeleted() || !$account->getIsEnabled()) {
            $this->backendAuth->invalidateRememberToken($legacyToken);
            $this->clearLegacyCookie($request);
            return false;
        }
        try {
            $this->backendAuth->restoreRememberedSession(
                $session,
                $account,
                $token->getExpireAt(),
                AuthenticatedLoginContext::legacyRemembered(),
            );
            $account = $this->backendAuth->completeLogin(
                $account->getId(),
                (string)$session->getId(),
                $request->clientIP(),
            );
            $issued = $provider->issueCredential(
                $this->context($session, (string)$account->getId()),
                $token->getExpireAt(),
            );
            $this->writeDeviceCookie($issued->token, max(1, $token->getExpireAt() - time()));
            $this->backendAuth->invalidateRememberToken($legacyToken);
            $this->clearLegacyCookie($request);
            $this->markRestored($session, $account, $route);
            return true;
        } catch (\Throwable) {
            $this->clearRestoredSession($session);
            return false;
        }
    }

    private function restoreLegacyOnly(object $session, Request $request, string $route): bool
    {
        $rawToken = $this->readRememberToken();
        if ($rawToken === '') {
            $session->delete('remember_expire_time');
            return false;
        }
        $token = $this->backendAuth->findRememberToken($rawToken);
        if ($token === null) {
            return false;
        }
        if ($token->getExpireAt() <= time()) {
            $this->backendAuth->invalidateRememberToken($rawToken);
            $this->clearLegacyCookie($request);
            $session->delete('remember_expire_time');
            $this->messageManager->addWarning(__('记住登录已过期，请重新登录！'));
            return false;
        }
        $account = $this->backendAuth->find($token->getUserId());
        if ($account === null) {
            $this->backendAuth->invalidateRememberToken($rawToken);
            $this->clearLegacyCookie($request);
            return false;
        }
        $this->backendAuth->restoreRememberedSession($session, $account, $token->getExpireAt());
        $account = $this->backendAuth->completeLogin(
            $account->getId(),
            (string)$session->getId(),
            $request->clientIP(),
        );
        $this->markRestored($session, $account, $route);
        return true;
    }

    private function migrateAuthenticatedLegacy(object $session): void
    {
        if ($this->readDeviceRememberToken() !== '') {
            return;
        }
        $legacyToken = $this->readRememberToken();
        $userId = (int)($session->getUserId() ?? 0);
        if ($legacyToken === '' || $userId <= 0) {
            return;
        }
        $token = $this->backendAuth->findRememberToken($legacyToken);
        if ($token === null || $token->getUserId() !== $userId || $token->getExpireAt() <= time()) {
            return;
        }
        $resolution = $this->resolver()->resolveDetailed(RememberedDeviceCredentialProviderInterface::class);
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof RememberedDeviceCredentialProviderInterface) {
            return;
        }
        try {
            $issued = $resolution->provider->issueCredential(
                $this->context($session, (string)$userId),
                $token->getExpireAt(),
            );
            $this->writeDeviceCookie($issued->token, max(1, $token->getExpireAt() - time()));
            $this->backendAuth->invalidateRememberToken($legacyToken);
            $this->clearLegacyCookie($this->request);
        } catch (\Throwable) {
            // Preserve the confirmed legacy cookie until migration is complete.
        }
    }

    private function markRestored(object $session, BackendLoginAccount $account, string $route): void
    {
        w_auth_log('remember_login_restored', 'Remember-me restored backend session before controller flow', [
            'user_id' => $account->getId(),
            'route' => $route,
        ]);
        $this->lastRestoredSession = $session;
        $this->lastRestoredAclContext = [
            'user_id' => $account->getId(),
            'role_id' => $account->getRoleId(),
            'is_enabled' => $account->getIsEnabled() ? 1 : 0,
        ];
    }

    private function clearRestoredSession(object $session): void
    {
        try {
            $session->logout();
        } catch (\Throwable) {
        }
        $this->lastRestoredSession = null;
        $this->lastRestoredAclContext = null;
    }

    private function context(object $session, string $userId): AuthenticatedDeviceContext
    {
        $sessionId = (string)$session->getId();
        if ($sessionId === '') {
            throw new \RuntimeException((string)__('当前认证会话无效。'));
        }
        $rawSession = $session->getSession();
        $ttl = method_exists($rawSession, 'getDefaultTtl')
            ? max(1, (int)$rawSession->getDefaultTtl())
            : 3600;
        $deviceId = $session->get(AuthenticatedDeviceContext::sessionKeyForArea('backend'));
        return new AuthenticatedDeviceContext(
            area: 'backend',
            principalId: $userId,
            sessionId: $sessionId,
            sessionExpiresAt: time() + $ttl,
            deviceId: is_string($deviceId) && trim($deviceId) !== '' ? trim($deviceId) : null,
        );
    }

    private function removeOwnedLegacyCredential(int $userId): void
    {
        $rawToken = $this->readRememberToken();
        if ($rawToken === '') {
            return;
        }
        $token = $this->backendAuth->findRememberToken($rawToken);
        if ($token === null) {
            return;
        }
        // A matching backend token confirms the realm. Remove credentials for
        // a previously used administrator as well, so switching accounts in
        // one browser cannot later restore the older administrator.
        $this->backendAuth->invalidateRememberToken($rawToken);
        $this->clearLegacyCookie($this->request);
    }

    private function resolver(): RuntimeProviderResolver
    {
        return $this->runtimeProviders ??= ObjectManager::getInstance(RuntimeProviderResolver::class);
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

    protected function readRememberToken(): string
    {
        return (string)Cookie::get(SessionCookieNameResolver::resolveFor(self::LEGACY_COOKIE), '');
    }

    protected function readDeviceRememberToken(): string
    {
        return (string)Cookie::get(SessionCookieNameResolver::resolveUnscopedFor(self::DEVICE_COOKIE), '');
    }

    protected function getBackendSession(): object
    {
        if ((defined('ENV_TEST') && ENV_TEST === true)
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__')) {
            return $this->sessionFactory->createBackendSession();
        }
        return SessionFactory::getInstance()->createBackendSession();
    }

    private function writeLegacyCookie(string $token, int $lifetime): void
    {
        Cookie::set(SessionCookieNameResolver::resolveFor(self::LEGACY_COOKIE), $token, $lifetime, ['path' => '/']);
    }

    private function writeDeviceCookie(string $token, int $lifetime): void
    {
        $secure = \w_env('server.https') === 'on';
        Cookie::set(
            SessionCookieNameResolver::resolveUnscopedFor(self::DEVICE_COOKIE),
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
        $name = SessionCookieNameResolver::resolveUnscopedFor(self::DEVICE_COOKIE);
        $secure = \w_env('server.https') === 'on';
        $options = [
            'secure' => $secure,
            'httponly' => true,
            'samesite' => SessionCookieNameResolver::resolveSameSite($secure),
        ];
        Cookie::set($name, '', -3600, ['path' => '/'] + $options);
        Cookie::set($name, '', -3600, ['path' => '/' . $this->request->getAreaRouter()] + $options);
    }

    private function clearLegacyCookie(Request $request): void
    {
        $name = SessionCookieNameResolver::resolveFor(self::LEGACY_COOKIE);
        Cookie::set($name, '', -1, ['path' => '/']);
        Cookie::set($name, '', -1, ['path' => '/' . $request->getAreaRouter()]);
    }
}
