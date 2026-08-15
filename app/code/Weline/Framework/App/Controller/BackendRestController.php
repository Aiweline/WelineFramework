<?php

declare(strict_types=1);

namespace Weline\Framework\App\Controller;

use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Auth\BackendSessionUserProviderInterface;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceRegistryInterface;
use Weline\Framework\Session\SessionFactory;

class BackendRestController extends AbstractRestController
{
    protected AuthenticatedSessionInterface $session;

    public function __construct()
    {
        parent::__construct();
        $this->session = SessionFactory::getInstance()->createAuthenticatedSession('rest_backend');

        if ((\defined('ENV_TEST') && ENV_TEST === true) || \defined('PHPUNIT_COMPOSER_INSTALL') || \defined('__PHPUNIT_PHAR__')) {
            return;
        }

        if (!$this->session->isLoggedIn()) {
            if (!$this->legacySessionRecoveryAllowed()) {
                $this->terminateUnauthenticated();
            }
            $sessionId = $this->session->getSession()->getId();
            if ($sessionId !== '') {
                $user = $this->resolveBackendUser($sessionId);
                if ($user === null) {
                    $this->terminateUnauthenticated();
                }

                if ($user !== null) {
                    $this->session->login($user);
                } else {
                    $this->terminateUnauthenticated();
                }
            } else {
                $this->terminateUnauthenticated();
            }
        }
    }

    /**
     * The sess_id lookup is a pre-device-registry compatibility path only.
     * Once a registry is declared, both a revoked device and a provider outage
     * must remain unauthenticated instead of being converted into a new login.
     */
    private function legacySessionRecoveryAllowed(): bool
    {
        try {
            $resolution = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolveDetailed(AuthenticatedDeviceRegistryInterface::class);
            return $resolution->status === RuntimeProviderResolution::NOT_CONFIGURED;
        } catch (\Throwable) {
            return false;
        }
    }

    private function terminateUnauthenticated(): never
    {
        throw new ResponseTerminateException(
            Response::json(['code' => 401, 'msg' => __('请先登录'), 'data' => null], 401),
        );
    }

    private function resolveBackendUser(string $sessionId): ?object
    {
        try {
            $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(BackendSessionUserProviderInterface::class);
            if ($provider instanceof BackendSessionUserProviderInterface) {
                return $provider->findEnabledBySessionId($sessionId);
            }
        } catch (\Throwable) {
        }
        return null;
    }
}
