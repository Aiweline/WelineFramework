<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Controller;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

class BackendRestController extends AbstractRestController
{
    protected AuthenticatedSessionInterface $session;

    public function __construct()
    {
        parent::__construct();
        $this->session = SessionFactory::getInstance()->createAuthenticatedSession('rest_backend');
        
        // 检查是否已登录
        if (!$this->session->isLoggedIn()) {
            // 尝试通过session ID查找用户
            $sessionId = $this->session->getSession()->getId();
            if ($sessionId !== '') {
                /** @var BackendUser $user */
                $user = ObjectManager::getInstance(BackendUser::class);
                $user->where('sess_id', $sessionId)->find()->fetch();
                
                if ($user->getId() && $user->getIsEnabled()) {
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
    
}
