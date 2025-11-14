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
                    // 没有找到有效用户，返回未登录错误
                    return $this->error(__('请先登录'), '', 401);
                }
            } else {
                // 没有session ID，返回未登录错误
                return $this->error(__('请先登录'), '', 401);
            }
        }
    }

        
    protected function success(string $msg = '请求成功！', mixed $data = '', int $code = 200)
    {
        return $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]);
    }

    protected function error(string $msg = '请求失败！', mixed $data = '', int $code = 404)
    {
        return $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]);

    }

    protected function exception(\Exception $exception, string $msg = '请求失败！', mixed $data = '', int $code = 403): mixed
    {
        $return_data['data']      = $data;
        $return_data['exception'] = DEV ? $exception : $exception->getMessage();
        return $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]);
    }
    
}
