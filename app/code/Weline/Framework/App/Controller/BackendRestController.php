<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Controller;

use Weline\Framework\Controller\AbstractRestController;
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

        // 检查是否已登录
        if (!$this->session->isLoggedIn()) {
            // 尝试通过session ID查找用户
            $sessionId = $this->session->getSession()->getId();
            if ($sessionId !== '') {
                $user = $this->resolveBackendUserModel();
                if ($user === null) {
                    throw new \Weline\Framework\Http\ResponseTerminateException(
                        401,
                        \json_encode(['code' => 401, 'msg' => __('请先登录'), 'data' => null], JSON_UNESCAPED_UNICODE),
                        ['Content-Type' => 'application/json; charset=utf-8']
                    );
                }
                $user->where('sess_id', $sessionId)->find()->fetch();
                
                if ($user->getId() && (bool)$user->getData('is_enabled')) {
                    // 找到有效用户，执行登录
                    $this->session->login($user);
                } else {
                    // 没有找到有效用户，抛出 401 异常
                    throw new \Weline\Framework\Http\ResponseTerminateException(
                        401,
                        \json_encode(['code' => 401, 'msg' => __('请先登录'), 'data' => null], JSON_UNESCAPED_UNICODE),
                        ['Content-Type' => 'application/json; charset=utf-8']
                    );
                }
            } else {
                // 没有session ID，抛出 401 异常
                throw new \Weline\Framework\Http\ResponseTerminateException(
                    401,
                    \json_encode(['code' => 401, 'msg' => __('请先登录'), 'data' => null], JSON_UNESCAPED_UNICODE),
                    ['Content-Type' => 'application/json; charset=utf-8']
                );
            }
        }
    }

        
    protected function success(string $msg = '请求成功！', mixed $data = '', int $code = 200): array|string
    {
        return parent::success($msg, $data, $code);
    }

    protected function error(string $msg = '请求失败！', mixed $data = '', int $code = 400, ?string $title = null): array|string
    {
        return parent::error($msg, $data, $code, $title);
    }

    protected function exception(\Throwable $exception, string $msg = '', mixed $data = '', ?int $code = null): array|string
    {
        return parent::exception($exception, $msg, $data, $code);
    }

    private function resolveBackendUserModel(): ?object
    {
        $className = self::OPTIONAL_BACKEND_USER_MODEL;
        if (!class_exists($className)) {
            return null;
        }

        $user = ObjectManager::getInstance($className);
        if (!is_object($user) || !method_exists($user, 'where')) {
            return null;
        }

        return $user;
    }
    
}
