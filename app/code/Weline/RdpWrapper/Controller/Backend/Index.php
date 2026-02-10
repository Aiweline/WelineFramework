<?php

declare(strict_types=1);

namespace Weline\RdpWrapper\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\RdpWrapper\Service\RdpWrapperService;
use Weline\RdpWrapper\Model\RdpUser;

/**
 * @DESC | RDP Wrapper 后台管理控制器
 */
class Index extends BackendController
{
    private RdpWrapperService $rdpService;
    private RdpUser $rdpUser;

    public function __construct(
        RdpWrapperService $rdpService,
        RdpUser           $rdpUser
    ) {
        $this->rdpService = $rdpService;
        $this->rdpUser    = $rdpUser;
    }

    /**
     * 主管理页面
     */
    public function index()
    {
        $status    = $this->rdpService->getStatus();
        $userList  = $this->rdpService->getUserList();

        $this->assign('title', __('RDP远程桌面管理'));
        $this->assign('status', $status);
        $this->assign('userList', $userList);
        return $this->fetch();
    }

    /**
     * 获取状态信息（AJAX）
     */
    public function getStatus()
    {
        return $this->fetchJson([
            'success' => true,
            'data'    => $this->rdpService->getStatus()
        ]);
    }

    /**
     * 安装 RDP Wrapper
     */
    public function postInstall()
    {
        $result = $this->rdpService->install();
        return $this->fetchJson($result);
    }

    /**
     * 启用远程桌面
     */
    public function postEnableRdp()
    {
        $result = $this->rdpService->enableRdp();
        return $this->fetchJson($result);
    }

    /**
     * 禁用远程桌面
     */
    public function postDisableRdp()
    {
        $result = $this->rdpService->disableRdp();
        return $this->fetchJson($result);
    }

    /**
     * 创建 Windows 用户
     */
    public function postCreateUser()
    {
        $bodyParams = $this->request->getBodyParams();

        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
        } elseif (is_array($bodyParams) && !empty($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $username    = trim((string)($data['username'] ?? ''));
        $password    = (string)($data['password'] ?? '');
        $displayName = trim((string)($data['display_name'] ?? ''));
        $isAdmin     = (bool)($data['is_admin'] ?? false);
        $remark      = trim((string)($data['remark'] ?? ''));

        $result = $this->rdpService->createUser($username, $password, $displayName, $isAdmin, $remark);
        return $this->fetchJson($result);
    }

    /**
     * 删除 Windows 用户
     */
    public function postRemoveUser()
    {
        $bodyParams = $this->request->getBodyParams();

        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
        } elseif (is_array($bodyParams) && !empty($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $username = trim((string)($data['username'] ?? ''));
        $result   = $this->rdpService->removeUser($username);
        return $this->fetchJson($result);
    }

    /**
     * 启用/禁用用户
     */
    public function postToggleUser()
    {
        $bodyParams = $this->request->getBodyParams();

        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
        } elseif (is_array($bodyParams) && !empty($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $username = trim((string)($data['username'] ?? ''));
        $enable   = (bool)($data['enable'] ?? true);
        $result   = $this->rdpService->toggleUser($username, $enable);
        return $this->fetchJson($result);
    }

    /**
     * 重置用户密码
     */
    public function postResetPassword()
    {
        $bodyParams = $this->request->getBodyParams();

        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
        } elseif (is_array($bodyParams) && !empty($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $username    = trim((string)($data['username'] ?? ''));
        $newPassword = (string)($data['password'] ?? '');
        $result      = $this->rdpService->resetPassword($username, $newPassword);
        return $this->fetchJson($result);
    }

    /**
     * 获取用户列表（AJAX）
     */
    public function getUserList()
    {
        $userList = $this->rdpService->getUserList();
        return $this->fetchJson([
            'success' => true,
            'data'    => $userList
        ]);
    }
}
