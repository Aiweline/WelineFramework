<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Controller;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\App\Session\BackendApiSession;
use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Manager\ObjectManager;

class BackendRestController extends AbstractRestController
{
    protected BackendApiSession $session;

    public function __construct(
        BackendApiSession $backendApiSession,
    )
    {
        parent::__construct();
        $this->session = $backendApiSession;
        
        // 检查是否已登录
        if (!$this->session->isLogin()) {
            // 尝试通过session ID查找用户
            $sessionId = $this->session->getSessionId();
            if ($sessionId) {
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

        
    protected function success(string $msg = '请求成功！', mixed $data = '', int $code = 200): string
    {
        $result = $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]);
        return $result ?: '';
    }

    protected function error(string $msg = '请求失败！', mixed $data = '', int $code = 404): string
    {
        $result = $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]);
        return $result ?: '';
    }

    protected function exception(\Exception $exception, string $msg = '请求失败！', mixed $data = '', int $code = 403): mixed
    {
        $return_data['data']      = $data;
        $return_data['exception'] = DEV ? $exception : $exception->getMessage();
        return $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]);
    }
    
}
