<?php

declare(strict_types=1);

namespace WeShop\Auth\Controller\Frontend\Auth;

use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Service\TwoFactorAccountService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Http\Url;

class TwoFactor extends BaseController
{
    private const ROUTE = 'weshop/frontend/auth/two-factor';
    private const LOGIN_ROUTE = 'weshop/customer/account/login';
    private const ACCOUNT_ROUTE = 'weshop/customer/account/index';
    private const FLASH_BACKUP_CODES_KEY = 'weshop_auth_frontend_2fa_backup_codes';

    protected ?string $layoutType = 'account';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CustomerContextInterface $customerContext,
        private readonly TwoFactorAccountService $twoFactorAccountService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        if (!$this->customerSession->isLoggedIn()) {
            $this->redirect(self::LOGIN_ROUTE, ['redirect' => self::ROUTE]);
            return '';
        }

        $userId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($userId <= 0) {
            $this->getMessageManager()->addError(__('Your customer session is no longer available. Please sign in again.'));
            $this->redirect(self::LOGIN_ROUTE, ['redirect' => self::ROUTE]);
            return '';
        }

        $email = trim((string) ($this->customerContext->getEmail() ?? 'customer-' . $userId));

        $this->assign('title', __('Two-Factor Authentication'));
        $this->assign('post_url', $this->url->getFrontendUrl(self::ROUTE));
        $this->assign('account_url', $this->url->getFrontendUrl(self::ACCOUNT_ROUTE));
        $this->assign('flow_status', $this->twoFactorAccountService->getFlowStatus('frontend'));
        $this->assign('flash_backup_codes', $this->consumeFlashBackupCodes());

        if ($this->twoFactorAccountService->isEnabled(ActorContext::ACTOR_CUSTOMER, $userId)) {
            $this->assign('is_enabled', true);
            $this->assign(
                'config',
                $this->twoFactorAccountService->getUserConfig(ActorContext::ACTOR_CUSTOMER, $userId) ?? []
            );
        } else {
            $this->assign('is_enabled', false);
            $this->assign(
                'setup',
                $this->twoFactorAccountService->initialize(
                    ActorContext::ACTOR_CUSTOMER,
                    $userId,
                    $email,
                    'WeShop Storefront'
                )
            );
        }

        return $this->fetch('WeShop_Auth::templates/Frontend/Auth/two-factor.phtml');
    }

    public function postIndex(): string
    {
        if (!$this->customerSession->isLoggedIn()) {
            $this->redirect(self::LOGIN_ROUTE, ['redirect' => self::ROUTE]);
            return '';
        }

        $userId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($userId <= 0) {
            $this->getMessageManager()->addError(__('Your customer session is no longer available. Please sign in again.'));
            $this->redirect(self::LOGIN_ROUTE, ['redirect' => self::ROUTE]);
            return '';
        }

        $action = trim((string) ($this->request->getPost('form_action') ?? ''));

        try {
            match ($action) {
                'enable' => $this->handleEnable($userId),
                'disable' => $this->handleDisable($userId),
                'regenerate_backup_codes' => $this->handleRegenerate($userId),
                default => $this->getMessageManager()->addWarning(__('Unsupported two-factor action.')),
            };
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        $this->redirect(self::ROUTE);
        return '';
    }

    private function handleEnable(int $userId): void
    {
        $secret = trim((string) ($this->request->getPost('secret') ?? ''));
        $code = trim((string) ($this->request->getPost('code') ?? ''));
        $backupCodes = $this->decodeBackupCodes((string) ($this->request->getPost('backup_codes') ?? '[]'));

        if ($secret === '' || $code === '') {
            $this->getMessageManager()->addError(__('Please scan the QR code and enter the current authenticator code.'));
            return;
        }

        $enabled = $this->twoFactorAccountService->enable(
            ActorContext::ACTOR_CUSTOMER,
            $userId,
            $secret,
            $code,
            $backupCodes
        );

        if ($enabled) {
            $this->getMessageManager()->addSuccess(__('Two-factor authentication is now enabled for your storefront account.'));
            return;
        }

        $this->getMessageManager()->addError(__('The authenticator code is invalid. Please try again.'));
    }

    private function handleDisable(int $userId): void
    {
        $code = trim((string) ($this->request->getPost('code') ?? ''));
        if ($code === '') {
            $this->getMessageManager()->addError(__('Enter the current authenticator code to disable two-factor authentication.'));
            return;
        }

        if ($this->twoFactorAccountService->disable(ActorContext::ACTOR_CUSTOMER, $userId, $code)) {
            $this->getMessageManager()->addSuccess(__('Two-factor authentication has been disabled for your storefront account.'));
            return;
        }

        $this->getMessageManager()->addError(__('The authenticator code is invalid. Two-factor authentication was not disabled.'));
    }

    private function handleRegenerate(int $userId): void
    {
        $code = trim((string) ($this->request->getPost('code') ?? ''));
        if ($code === '') {
            $this->getMessageManager()->addError(__('Enter the current authenticator code to regenerate backup codes.'));
            return;
        }

        $backupCodes = $this->twoFactorAccountService->regenerateBackupCodes(
            ActorContext::ACTOR_CUSTOMER,
            $userId,
            $code
        );

        if ($backupCodes === null) {
            $this->getMessageManager()->addError(__('The authenticator code is invalid. Backup codes were not regenerated.'));
            return;
        }

        $this->customerSession->set(self::FLASH_BACKUP_CODES_KEY, $backupCodes);
        $this->getMessageManager()->addSuccess(__('New backup codes were generated. Save them before you leave this page.'));
    }

    private function consumeFlashBackupCodes(): array
    {
        $backupCodes = (array) ($this->customerSession->get(self::FLASH_BACKUP_CODES_KEY) ?? []);
        $this->customerSession->delete(self::FLASH_BACKUP_CODES_KEY);

        return $backupCodes;
    }

    private function decodeBackupCodes(string $encodedBackupCodes): array
    {
        $decoded = json_decode($encodedBackupCodes, true);
        return is_array($decoded) ? $decoded : [];
    }
}
