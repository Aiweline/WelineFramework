<?php

declare(strict_types=1);

namespace Weline\Framework\App\Controller;

use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Auth\BackendSessionUserProviderInterface;
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
            $sessionId = $this->session->getSession()->getId();
            if ($sessionId !== '') {
                $user = $this->resolveBackendUser($sessionId);
                if ($user === null) {
                    throw new ResponseTerminateException(
                        Response::json(['code' => 401, 'msg' => __('璇峰厛鐧诲綍'), 'data' => null], 401)
                    );
                }

                if ($user !== null) {
                    $this->session->login($user);
                } else {
                    throw new ResponseTerminateException(
                        Response::json(['code' => 401, 'msg' => __('璇峰厛鐧诲綍'), 'data' => null], 401)
                    );
                }
            } else {
                throw new ResponseTerminateException(
                    Response::json(['code' => 401, 'msg' => __('璇峰厛鐧诲綍'), 'data' => null], 401)
                );
            }
        }
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
