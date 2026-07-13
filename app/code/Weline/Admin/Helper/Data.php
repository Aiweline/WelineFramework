<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Helper;

use Weline\Backend\Api\Auth\BackendInteractiveAuthInterface;
use Weline\Backend\Api\Auth\BackendLoginAccount;
use Weline\Framework\Http\Request;

class Data extends \Weline\Framework\App\Helper
{
    protected Request $request;
    private BackendInteractiveAuthInterface $adminUser;

    public function __construct(
        Request $_request,
        BackendInteractiveAuthInterface $adminUser
    )
    {
        $this->request   = $_request;
        $this->adminUser = $adminUser;
    }

    /**
     * @DESC          # 返回管理员
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/9 14:06
     * 参数区：
     * @return BackendLoginAccount
     */
    public function getRequestBackendUser(): BackendLoginAccount
    {
        // WLS 兼容：从 ObjectManager 获取当前请求的 Request 实例
        // 不使用缓存的 $this->request，因为在 WLS 中它可能指向旧请求
        $currentRequest = \Weline\Framework\Manager\ObjectManager::getInstance(Request::class);
        $username = $currentRequest->getParam('username');
        try {
            return $this->adminUser->findByUsername((string)$username) ?? BackendLoginAccount::empty();
        } catch (\Exception $exception) {
            return BackendLoginAccount::empty();
        }
    }
    /**
     * @DESC          # 返回管理员
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/9 14:06
     * 参数区：
     * @return BackendLoginAccount
     */
    public function getSessionUser(string $sess_id): BackendLoginAccount
    {
        try {
            return $this->adminUser->findBySessionId($sess_id) ?? BackendLoginAccount::empty();
        } catch (\Exception $exception) {
            return BackendLoginAccount::empty();
        }
    }
    /**
     * @DESC          # 返回管理员
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/9 14:06
     * 参数区：
     * @return BackendLoginAccount
     */
    public function getUser(int $user_id): BackendLoginAccount
    {
        try {
            return $this->adminUser->find($user_id) ?? BackendLoginAccount::empty();
        } catch (\Exception $exception) {
            return BackendLoginAccount::empty();
        }
    }
}
