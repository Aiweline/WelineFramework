<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;

/**
 * One-shot migration: copy storefront auth keys from the legacy admin
 * WELINE_SESSID jar into WELINE_CUSTOMER_SESSID so shoppers are not kicked
 * after the cookie split.
 */
final class FrontendSessionCookieMigrator
{
    private static bool $attempted = false;

    public static function resetRequestState(): void
    {
        self::$attempted = false;
    }

    public static function migrateIfNeeded(SessionInterface $customerSession, SessionFactory $factory): void
    {
        if (self::$attempted) {
            return;
        }
        self::$attempted = true;

        try {
            if (!$customerSession->isStarted()) {
                $customerSession->start();
            }
            $frontendConfig = new AreaConfig('frontend');
            $loginIdKey = $frontendConfig->getLoginIdKey();
            if ($customerSession->get($loginIdKey) !== null && $customerSession->get($loginIdKey) !== '') {
                return;
            }

            $legacyId = SessionCookieNameResolver::readRequestSessionId(null, 'backend');
            if ($legacyId === '') {
                return;
            }

            $storage = $factory->createStorage();
            $legacyData = $storage->read($legacyId);
            if (!\is_array($legacyData) || $legacyData === []) {
                return;
            }

            $keys = self::frontendAuthKeys($frontendConfig);
            $payload = [];
            foreach ($keys as $key) {
                if (!\array_key_exists($key, $legacyData)) {
                    continue;
                }
                $value = $legacyData[$key];
                if ($value === null || $value === '') {
                    continue;
                }
                $payload[$key] = $value;
            }
            if ($payload === [] || !isset($payload[$loginIdKey]) || $payload[$loginIdKey] === '') {
                return;
            }

            foreach ($payload as $key => $value) {
                $customerSession->set($key, $value);
            }
            $customerSession->save();

            foreach ($keys as $key) {
                unset($legacyData[$key]);
            }
            $ttl = \method_exists($customerSession, 'getDefaultTtl')
                ? max(1, (int)$customerSession->getDefaultTtl())
                : 86400;
            $storage->write($legacyId, $legacyData, $ttl);
        } catch (\Throwable) {
            // Migration is best-effort; a failed copy must not break storefront boot.
        }
    }

    /**
     * @return list<string>
     */
    private static function frontendAuthKeys(AreaConfig $frontendConfig): array
    {
        return [
            $frontendConfig->getLoginKey(),
            $frontendConfig->getLoginIdKey(),
            $frontendConfig->getUserModelKey(),
            AuthenticatedDeviceContext::sessionKeyForArea('frontend'),
        ];
    }
}
