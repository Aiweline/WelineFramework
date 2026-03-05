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
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Multipass\Model\MultipassSite;
use Weline\Multipass\Service\MultipassService;

/**
 * 后端 Multipass 登录控制器
 * 处理来自第三方站点的 Multipass token 登录
 */
class Multipass extends BackendController
{
    protected AuthenticatedSessionInterface $session;
    private MultipassService $multipassService;

    public function __construct() {
        parent::__construct();
        $this->session = SessionFactory::getInstance()->createBackendSession();
        $this->multipassService = ObjectManager::getInstance(MultipassService::class);
    }

    /**
     * 处理 Multipass 登录
     * 接收第三方站点传递的 token，验证后自动登录后端用户
     * 
     * 路由: /admin/multipass/backend/multipass?token=xxx&site_id=xxx
     */
    public function getIndex()
    {
        try {
            // 获取参数
            $token = $this->request->getParam('token');
            $siteId = $this->request->getParam('site_id');
            $returnUrl = $this->request->getParam('return_url', $this->_url->getBackendUrl('admin'));

            if (empty($token)) {
                $this->messageManager->addError(__('缺少 Multipass token'));
                $this->redirect($this->_url->getBackendUrl('admin/login'));
                return;
            }

            if (empty($siteId)) {
                $this->messageManager->addError(__('缺少站点ID'));
                $this->redirect($this->_url->getBackendUrl('admin/login'));
                return;
            }

            // 获取站点配置
            /** @var MultipassSite $site */
            $site = ObjectManager::getInstance(MultipassSite::class);
            $site->load($siteId);

            if (!$site->getId()) {
                $this->messageManager->addError(__('站点配置不存在'));
                $this->redirect($this->_url->getBackendUrl('admin/login'));
                return;
            }

            // 验证站点类型
            if ($site->getUserType() !== 'backend') {
                $this->messageManager->addError(__('该站点不支持后端登录'));
                $this->redirect($this->_url->getBackendUrl('admin/login'));
                return;
            }

            // 验证站点是否启用
            if (!$site->getIsEnabled()) {
                $this->messageManager->addError(__('该站点已禁用'));
                $this->redirect($this->_url->getBackendUrl('admin/login'));
                return;
            }

            // 验证并解密 token
            $userData = $this->multipassService->verifyToken($site, $token);
            
            if (!$userData) {
                $this->messageManager->addError(__('Token 验证失败或已过期'));
                $this->redirect($this->_url->getBackendUrl('admin/login'));
                return;
            }

            // 根据用户数据查找用户
            $username = $userData['username'] ?? '';
            $email = $userData['email'] ?? '';

            if (empty($username) && empty($email)) {
                $this->messageManager->addError(__('用户数据不完整'));
                $this->redirect($this->_url->getBackendUrl('admin/login'));
                return;
            }

            /** @var BackendUser $user */
            $user = ObjectManager::getInstance(BackendUser::class);
            
            // 优先使用用户名查找，其次使用邮箱
            if (!empty($username)) {
                $user->where(BackendUser::schema_fields_username, $username)->find()->fetch();
            }
            
            if (!$user->getId() && !empty($email)) {
                $user->clear()->where(BackendUser::schema_fields_email, $email)->find()->fetch();
            }

            // 如果用户不存在，返回错误
            if (!$user->getId()) {
                $this->messageManager->addError(__('用户不存在，请联系管理员'));
                $this->redirect($this->_url->getBackendUrl('admin/login'));
                return;
            }

            // 检查用户状态
            if (!$user->getIsEnabled()) {
                $this->messageManager->addError(__('用户已被禁用'));
                $this->redirect($this->_url->getBackendUrl('admin/login'));
                return;
            }

            // 执行登录
            $this->session->login($user);
            $user->setSessionId($this->session->getSessionId())
                ->setLoginIp($this->request->clientIP())
                ->resetAttemptTimes()
                ->save();

            // 更新用户信息（如果 token 中包含额外信息）
            $updated = false;
            if (isset($userData['avatar']) && !empty($userData['avatar'])) {
                $user->setAvatar($userData['avatar']);
                $updated = true;
            }
            if ($updated) {
                $user->save();
            }

            $this->messageManager->addSuccess(__('登录成功'));

            // 跳转到返回URL或默认页面
            $this->redirect($returnUrl);

        } catch (\Exception $e) {
            $this->messageManager->addError(__('登录失败：%{1}', [$e->getMessage()]));
            $this->redirect($this->_url->getBackendUrl('admin/login'));
        }
    }
}

