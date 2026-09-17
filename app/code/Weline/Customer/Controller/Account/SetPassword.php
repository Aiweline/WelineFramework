<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Model\Customer;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * Force password setup after guest checkout conversion.
 */
class SetPassword extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account/set-password';

    public function __construct(
        private readonly Template $template,
        private readonly CustomerAccountService $accounts,
    ) {
    }

    public function getIndex(): string
    {
        if (!$this->isLoggedIn()) {
            return $this->redirect('/customer/account/login');
        }
        $user = $this->getLoginUser();
        if (!$user instanceof Customer || !$user->mustSetPassword()) {
            return $this->redirect('/customer/account/index');
        }

        $this->request->setGet('theme_page_title', (string)__('设置密码'));
        $this->assign('page_title', (string)__('设置密码'));
        $this->assign('email', $user->getEmail());

        return $this->fetch('Weline_Customer::templates/frontend/account/set-password.phtml');
    }

    public function postIndex(): string
    {
        if (!$this->isLoggedIn()) {
            return $this->redirect('/customer/account/login');
        }
        /** @var Customer|null $user */
        $user = $this->getLoginUser();
        if (!$user instanceof Customer || !(int)$user->getId()) {
            return $this->redirect('/customer/account/login');
        }

        $password = (string)$this->request->getPost('password', '');
        $confirm = (string)$this->request->getPost('confirm_password', '');
        try {
            $this->accounts->validatePasswordStrength($password);
            if ($password !== $confirm) {
                throw new \InvalidArgumentException((string)__('两次输入的密码不一致'));
            }
            $fresh = ObjectManager::getInstance(Customer::class)->reset()->load($user->getId());
            if (!$fresh instanceof Customer || !$fresh->getId()) {
                throw new \RuntimeException((string)__('账户不存在'));
            }
            $fresh->setPassword($password)->setMustSetPassword(false)->save();
            $this->getMessageManager()->addSuccess((string)__('密码已设置'));

            return $this->redirect('/customer/account/index#orders');
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());

            return $this->redirect('/customer/account/set-password');
        }
    }
}
