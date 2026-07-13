<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Multipass\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Multipass\Model\MultipassSite;
use Weline\Multipass\Service\AccountFacadeResolver;
use Weline\Multipass\Service\MultipassService;

/**
 * 前端 Multipass 登录控制器
 * 处理来自第三方站点的 Multipass token 登录
 */
class Multipass extends FrontendController
{
    private MultipassService $multipassService;
    private ?AccountFacadeResolver $accountFacades = null;

    public function __construct()
    {
        parent::__construct();
        $this->multipassService = ObjectManager::getInstance(MultipassService::class);
    }

    /**
     * 处理 Multipass 登录
     * 接收第三方站点传递的 token，验证后自动登录前端用户
     * 
     * 路由: /multipass/frontend/multipass?token=xxx&site_id=xxx
     */
    public function getIndex()
    {
        try {
            // 获取参数
            $token = $this->request->getParam('token');
            $siteId = $this->request->getParam('site_id');
            $returnUrl = $this->request->getParam('return_url', '/frontend/account');

            if (empty($token)) {
                $this->messageManager->addError(__('缺少 Multipass token'));
                $this->redirect('/frontend/account/login');
                return;
            }

            if (empty($siteId)) {
                $this->messageManager->addError(__('缺少站点ID'));
                $this->redirect('/frontend/account/login');
                return;
            }

            // 获取站点配置
            /** @var MultipassSite $site */
            $site = ObjectManager::getInstance(MultipassSite::class);
            $site->load($siteId);

            if (!$site->getId()) {
                $this->messageManager->addError(__('站点配置不存在'));
                $this->redirect('/frontend/account/login');
                return;
            }

            // 验证站点类型
            if ($site->getUserType() !== 'frontend') {
                $this->messageManager->addError(__('该站点不支持前端登录'));
                $this->redirect('/frontend/account/login');
                return;
            }

            // 验证站点是否启用
            if (!$site->getIsEnabled()) {
                $this->messageManager->addError(__('该站点已禁用'));
                $this->redirect('/frontend/account/login');
                return;
            }

            // 验证并解密 token
            $userData = $this->multipassService->verifyToken($site, $token);
            
            if (!$userData) {
                $this->messageManager->addError(__('Token 验证失败或已过期'));
                $this->redirect('/frontend/account/login');
                return;
            }

            // 根据用户数据查找或创建用户
            $username = $userData['username'] ?? '';
            $email = $userData['email'] ?? '';

            if (empty($username) && empty($email)) {
                $this->messageManager->addError(__('用户数据不完整'));
                $this->redirect('/frontend/account/login');
                return;
            }

            $user = $this->accountFacades()->frontend()->findByUsernameOrEmail((string) $username, (string) $email);

            // 如果用户不存在，根据配置决定是否自动创建
            if ($user === null) {
                // 这里可以根据业务需求决定是否自动创建用户
                // 暂时返回错误，提示用户需要先在系统中注册
                $this->messageManager->addError(__('用户不存在，请先在系统中注册'));
                $this->redirect('/frontend/account/login');
                return;
            }

            $avatar = isset($userData['avatar']) && !empty($userData['avatar'])
                ? (string) $userData['avatar']
                : '';
            $this->accountFacades()->frontend()->loginTrustedIdentity($user, $avatar);

            $this->messageManager->addSuccess(__('登录成功'));

            // 跳转到返回URL或默认页面
            $this->redirect($returnUrl);

        } catch (\Exception $e) {
            $this->messageManager->addError(__('登录失败：%{1}', [$e->getMessage()]));
            $this->redirect('/frontend/account/login');
        }
    }

    private function accountFacades(): AccountFacadeResolver
    {
        return $this->accountFacades ??= ObjectManager::getInstance(AccountFacadeResolver::class);
    }
}
