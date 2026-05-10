<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Backend\Customer;

use WeShop\Customer\Service\CustomerAuthBridgeConfig;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * 后台：控制「本地无账号时是否尝试 WeShop 密码登录桥」。
 */
class AuthBridge extends BaseController
{
    public function index(): string
    {
        $enabled = ObjectManager::getInstance(CustomerAuthBridgeConfig::class)->isPasswordBridgeEnabled();

        $this->assign('weshop_password_login_enabled', $enabled ? '1' : '0');
        $this->assign('title', (string) __('WeShop login bridge'));
        $this->assign('save_url', $this->_url->getBackendUrl('*/backend/customer/auth-bridge/save'));

        return $this->fetch('customer/auth-bridge');
    }

    public function save(): string
    {
        if (!$this->getRequest()->isPost()) {
            $this->getMessageManager()->addError((string) __('Invalid request method.'));
            return $this->redirect('*/backend/customer/auth-bridge');
        }

        try {
            $raw = $this->getRequest()->getPost('weshop_password_login_enabled', '0');
            $enabledFlag = ($raw === '1' || $raw === 1 || $raw === true) ? '1' : '0';

            /** @var SystemConfig $systemConfig */
            $systemConfig = ObjectManager::getInstance(SystemConfig::class);
            $systemConfig->setConfig(
                CustomerAuthBridgeConfig::CONFIG_KEY,
                $enabledFlag,
                CustomerAuthBridgeConfig::MODULE,
                SystemConfig::area_BACKEND
            );

            /** @var Clear $cache */
            $cache = ObjectManager::getInstance(Clear::class);
            $cache->execute(['-f']);

            $this->getMessageManager()->addSuccess((string) __('Configuration saved.'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError((string) __('Save failed: %{1}', [$e->getMessage()]));
        }

        return $this->redirect('*/backend/customer/auth-bridge');
    }
}
