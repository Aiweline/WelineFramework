<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Multipass\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Message\Manager;
use Weline\Backend\Model\BackendUser;
use Weline\Frontend\Model\FrontendUser;
use Weline\Multipass\Model\MultipassSite;
use Weline\Multipass\Service\MultipassService;

#[\Weline\Framework\Acl\Acl('Weline_Multipass::account_management', 'Multipass账户管理', 'mdi mdi-account-key', '生成和管理Multipass登录令牌', 'Weline_Multipass::menu_multipass_management')]
class Account extends BackendController
{
    private MultipassSite $multipassSite;
    private MultipassService $multipassService;

    public function __construct(
        MultipassSite $multipassSite
    ) {
        $this->multipassSite = $multipassSite;
        $this->multipassService = ObjectManager::getInstance(MultipassService::class);
    }

    /**
     * 账户管理首页
     */
    #[\Weline\Framework\Acl\Acl('Weline_Multipass::account_index', '账户管理首页', 'mdi mdi-view-dashboard', '查看Multipass账户管理首页', 'Weline_Multipass::account_management')]
    public function index()
    {
        $userType = $this->request->getParam('user_type', 'backend');
        
        $this->assign('page_title', __('Multipass账户管理'));
        $this->assign('breadcrumb_parent', __('系统管理'));
        $this->assign('breadcrumb_current', __('Multipass账户管理'));
        $this->assign('user_type', $userType);
        
        // 获取对应类型的站点列表
        $sites = clone $this->multipassSite;
        $sites = $sites->clear()
            ->where('user_type', $userType)
            ->where('is_enabled', 1)
            ->select()
            ->fetch()
            ->getItems();
        
        $this->assign('sites', $sites);
        
        return $this->fetch();
    }

    /**
     * 前端账户列表
     */
    #[\Weline\Framework\Acl\Acl('Weline_Multipass::account_frontend', '前端账户列表', 'mdi mdi-account', '查看前端账户列表', 'Weline_Multipass::account_management')]
    public function getFrontend()
    {
        $userType = 'frontend';
        
        $this->assign('page_title', __('Multipass账户管理 - 前端账户'));
        $this->assign('breadcrumb_parent', __('系统管理'));
        $this->assign('breadcrumb_current', __('Multipass账户管理'));
        $this->assign('user_type', $userType);
        
        // 获取对应类型的站点列表
        $sites = clone $this->multipassSite;
        $sites = $sites->clear()
            ->where('user_type', $userType)
            ->where('is_enabled', 1)
            ->select()
            ->fetch()
            ->getItems();
        
        $this->assign('sites', $sites);
        
        return $this->fetch('index');
    }

    /**
     * 后端账户列表
     */
    #[\Weline\Framework\Acl\Acl('Weline_Multipass::account_backend', '后端账户列表', 'mdi mdi-account-cog', '查看后端账户列表', 'Weline_Multipass::account_management')]
    public function getBackend()
    {
        $userType = 'backend';
        
        $this->assign('page_title', __('Multipass账户管理 - 后端账户'));
        $this->assign('breadcrumb_parent', __('系统管理'));
        $this->assign('breadcrumb_current', __('Multipass账户管理'));
        $this->assign('user_type', $userType);
        
        // 获取对应类型的站点列表
        $sites = clone $this->multipassSite;
        $sites = $sites->clear()
            ->where('user_type', $userType)
            ->where('is_enabled', 1)
            ->select()
            ->fetch()
            ->getItems();
        
        $this->assign('sites', $sites);
        
        return $this->fetch('index');
    }

    /**
     * 获取用户列表（AJAX）
     */
    #[\Weline\Framework\Acl\Acl('Weline_Multipass::account_user_list', '获取用户列表', '', '获取用户列表数据', 'Weline_Multipass::account_management')]
    public function getUserList()
    {
        try {
            $userType = $this->request->getParam('user_type', 'backend');
            $search = trim($this->request->getParam('search', ''));
            
            if ($userType === 'frontend') {
                /** @var FrontendUser $userModel */
                $userModel = ObjectManager::getInstance(FrontendUser::class);
                $userModel->clear();
                
                if (!empty($search)) {
                    // 使用 concat_like 进行多字段模糊查询
                    $userModel->concat_like('username,email', "%$search%");
                }
                
                $users = $userModel
                    ->order(FrontendUser::fields_ID, 'DESC')
                    ->pagination(1, 20)
                    ->select()
                    ->fetch();
            } else {
                /** @var BackendUser $userModel */
                $userModel = ObjectManager::getInstance(BackendUser::class);
                $userModel->clear();
                
                // 先设置未删除条件
                $userModel->where(BackendUser::fields_is_deleted, 0);
                
                if (!empty($search)) {
                    // 使用 concat_like 进行多字段模糊查询
                    $userModel->concat_like('username,email', "%$search%");
                }
                
                $users = $userModel
                    ->order(BackendUser::fields_ID, 'DESC')
                    ->pagination(1, 20)
                    ->select()
                    ->fetch();
            }
            
            // 格式化用户数据
            $userList = [];
            foreach ($users->getItems() as $user) {
                $userList[] = [
                    'user_id' => $user->getId() ?: $user->getData('user_id'),
                    'id' => $user->getId() ?: $user->getData('user_id'),
                    'username' => $user->getData('username') ?: '',
                    'email' => $user->getData('email') ?: '',
                    'avatar' => $user->getData('avatar') ?: ''
                ];
            }
            
            return $this->success(__('获取成功'), [
                'users' => $userList,
                'pagination' => $users->getPagination()
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 生成Token
     */
    #[\Weline\Framework\Acl\Acl('Weline_Multipass::account_generate_token', '生成Token', '', '为用户生成Multipass登录令牌', 'Weline_Multipass::account_management')]
    public function postGenerateToken()
    {
        try {
            $userId = $this->request->getPost('user_id');
            $siteId = $this->request->getPost('site_id');
            $userType = $this->request->getPost('user_type', 'backend');
            
            if (empty($userId)) {
                return $this->error(__('缺少用户ID'), '', 400);
            }
            
            if (empty($siteId)) {
                return $this->error(__('缺少站点ID'), '', 400);
            }
            
            // 获取站点配置
            $site = clone $this->multipassSite;
            $site->load($siteId);
            
            if (!$site->getId()) {
                return $this->error(__('站点配置不存在'), '', 404);
            }
            
            // 验证站点类型
            if ($site->getUserType() !== $userType) {
                return $this->error(__('站点类型与用户类型不匹配'), '', 400);
            }
            
            // 验证站点是否启用
            if (!$site->getIsEnabled()) {
                return $this->error(__('站点已禁用'), '', 403);
            }
            
            // 获取用户信息
            if ($userType === 'frontend') {
                /** @var FrontendUser $user */
                $user = ObjectManager::getInstance(FrontendUser::class);
                $user->load($userId);
                
                if (!$user->getId()) {
                    return $this->error(__('用户不存在'), '', 404);
                }
                
                $userData = [
                    'username' => $user->getUsername(),
                    'avatar' => $user->getAvatar() ?: ''
                ];
            } else {
                /** @var BackendUser $user */
                $user = ObjectManager::getInstance(BackendUser::class);
                $user->load($userId);
                
                if (!$user->getId()) {
                    return $this->error(__('用户不存在'), '', 404);
                }
                
                if (!$user->getIsEnabled()) {
                    return $this->error(__('用户已被禁用'), '', 403);
                }
                
                $userData = [
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'avatar' => $user->getAvatar() ?: ''
                ];
            }
            
            // 生成 token
            $token = $this->multipassService->generateToken($site, $userData);
            
            // 生成登录URL
            $baseUrl = rtrim($site->getSiteUrl(), '/');
            if ($userType === 'frontend') {
                $loginUrl = $baseUrl . '/multipass/frontend/multipass?token=' . urlencode($token) . '&site_id=' . $siteId;
            } else {
                // 获取当前请求的基础URL
                $currentUrl = $this->request->getUrlBuilder()->getCurrentUrl();
                $currentBaseUrl = preg_replace('#/admin/.*$#', '', $currentUrl);
                $adminKey = $this->request->getEnv('ADMIN_KEY') ?? 'admin';
                $loginUrl = $currentBaseUrl . '/' . $adminKey . '/multipass/backend/multipass?token=' . urlencode($token) . '&site_id=' . $siteId;
            }
            
            return $this->success(__('Token生成成功'), [
                'token' => $token,
                'login_url' => $loginUrl,
                'site_id' => $siteId,
                'site_name' => $site->getSiteName(),
                'user_type' => $userType,
                'user_id' => $userId,
                'username' => $userData['username']
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('Token生成失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
}

