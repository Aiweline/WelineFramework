<?php
declare(strict_types=1);

namespace Weline\AppStore\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\AppStore\Service\AccountBindService;

/**
 * 账户绑定控制器
 */
#[Acl('Weline_AppStore::account', '账户绑定', 'bi-link-45deg', '绑定官网账户', 'Weline_AppStore::appstore')]
class Account extends BackendController
{
    /**
     * 账户绑定页面
     */
    #[Acl('Weline_AppStore::account_view', '查看账户', 'bi-person', '查看账户绑定状态')]
    public function index(): string
    {
        /** @var AccountBindService $accountService */
        $accountService = ObjectManager::getInstance(AccountBindService::class);

        $isBound = $accountService->isBound();
        $account = $accountService->getCurrentAccount();
        $redirectUri = $this->getOAuthRedirectUri();
        $authorizeUrl = $accountService->getAuthorizationUrl($redirectUri, bin2hex(random_bytes(16)));

        $this->assign('is_bound', $isBound);
        $this->assign('account', $account);
        $this->assign('authorize_url', $authorizeUrl);
        $this->assign('redirect_uri', $redirectUri);
        $this->assign('platform_url', $accountService->getPlatformUrl());
        $this->assign('page_title', __('账户绑定'));

        return $this->fetch('Weline_AppStore::templates/Backend/Account/index.phtml');
    }

    /**
     * 跳转到官网授权页
     */
    #[Acl('Weline_AppStore::account_authorize', '官网授权', 'bi-shield-check', '跳转官网授权当前终端')]
    public function authorize(): string
    {
        /** @var AccountBindService $accountService */
        $accountService = ObjectManager::getInstance(AccountBindService::class);

        return (string)$this->redirect($accountService->getAuthorizationUrl($this->getOAuthRedirectUri(), bin2hex(random_bytes(16))));
    }

    /**
     * 官网 OAuth 回调
     */
    #[Acl('Weline_AppStore::account_callback', '授权回调', 'bi-arrow-left-right', '接收官网授权回调')]
    public function callback(): string
    {
        /** @var AccountBindService $accountService */
        $accountService = ObjectManager::getInstance(AccountBindService::class);
        $code = trim((string)$this->request->getParam('code', ''));

        try {
            if ($code === '') {
                throw new \Weline\Framework\App\Exception(__('缺少授权码'));
            }

            $this->assign('bind_result', $accountService->bindWithOAuth($code, $this->getOAuthRedirectUri()));
        } catch (\Throwable $e) {
            $this->assign('bind_result', [
                'success' => false,
                'message' => __('授权绑定失败：') . $e->getMessage(),
            ]);
        }

        return $this->index();
    }

    /**
     * 绑定账户
     */
    #[Acl('Weline_AppStore::account_bind', '绑定账户', 'bi-link', '绑定官网账户')]
    public function bind(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $email = $this->request->getPost('email');
            $password = $this->request->getPost('password');

            if (!$email || !$password) {
                return $this->jsonResponse(false, __('请输入邮箱和密码'));
            }

            /** @var AccountBindService $accountService */
            $accountService = ObjectManager::getInstance(AccountBindService::class);

            $result = $accountService->bind($email, $password);

            if ($result['success']) {
                return $this->jsonResponse(true, $result['message'], $result);
            } else {
                return $this->jsonResponse(false, $result['message']);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('绑定失败：') . $e->getMessage());
        }
    }

    /**
     * 解绑账户
     */
    #[Acl('Weline_AppStore::account_unbind', '解绑账户', 'bi-link-break', '解绑官网账户')]
    public function unbind(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        try {
            /** @var AccountBindService $accountService */
            $accountService = ObjectManager::getInstance(AccountBindService::class);

            $result = $accountService->unbind();

            if ($result['success']) {
                return $this->jsonResponse(true, $result['message']);
            } else {
                return $this->jsonResponse(false, $result['message']);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('解绑失败：') . $e->getMessage());
        }
    }

    /**
     * 获取绑定状态
     */
    #[Acl('Weline_AppStore::account_status', '账户状态', 'bi-info-circle', '获取账户绑定状态')]
    public function status(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        /** @var AccountBindService $accountService */
        $accountService = ObjectManager::getInstance(AccountBindService::class);

        $isBound = $accountService->isBound();
        $account = $accountService->getCurrentAccount();

        return $this->jsonResponse(true, '', [
            'is_bound' => $isBound,
            'account' => $account ? [
                'platform_email' => $account->getPlatformEmail(),
                'platform_username' => $account->getPlatformUsername(),
                'bound_domain' => $account->getBoundDomain(),
                'status' => $account->getStatus(),
                'bound_at' => $account->getBoundAt(),
                'is_active' => $account->isActive(),
            ] : null,
        ]);
    }

    /**
     * JSON 响应
     */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function getOAuthRedirectUri(): string
    {
        return $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/account/callback');
    }
}
