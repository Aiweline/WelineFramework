<?php

declare(strict_types=1);

namespace Weline\Framework\App\Controller;

use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

class BackendRestController extends AbstractRestController
{
    private const OPTIONAL_BACKEND_USER_MODEL = 'Weline\\Backend\\Model\\BackendUser';

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
                $user = $this->resolveBackendUserModel();
                if ($user === null) {
                    throw new ResponseTerminateException(
                        Response::json(['code' => 401, 'msg' => __('璇峰厛鐧诲綍'), 'data' => null], 401)
                    );
                }

                $user->where('sess_id', $sessionId)->find()->fetch();

                if ($user->getId() && (bool)$user->getData('is_enabled')) {
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

    private function resolveBackendUserModel(): ?object
    {
        $className = self::OPTIONAL_BACKEND_USER_MODEL;
        if (!\class_exists($className)) {
            return null;
        }

        $user = ObjectManager::getInstance($className);
        if (!\is_object($user) || !\method_exists($user, 'where')) {
            return null;
        }

        return $user;
    }
}
