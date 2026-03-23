<?php

declare(strict_types=1);

namespace WeShop\Auth\Controller\Backend\Security;

use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Service\TwoFactorAccountService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Url;

#[Acl('WeShop_Auth::security', 'Account Security', 'mdi mdi-shield-account-outline', 'Manage sign-in security settings.', 'Weline_Backend::user_management')]
class TwoFactor extends BackendController
{
    private const ROUTE = 'weshop/backend/security/two-factor';
    private const FLASH_BACKUP_CODES_KEY = 'weshop_auth_backend_2fa_backup_codes';

    public function __construct(
        private readonly TwoFactorAccountService $twoFactorAccountService,
        private readonly Url $url
    ) {
    }

    #[Acl('WeShop_Auth::two_factor', 'Two-Factor Authentication', 'mdi mdi-cellphone-key', 'Manage two-factor authentication for the current backend account.')]
    public function index(): string
    {
        /** @var BackendUser|null $backendUser */
        $backendUser = $this->session->getUser();
        if (!$backendUser || !(int) $backendUser->getId()) {
            $this->getMessageManager()->addError(__('Your backend session is no longer available. Please sign in again.'));
            $this->redirect($this->url->getBackendUrl('admin'));
            return '';
        }

        $userId = (int) $backendUser->getId();
        $accountLabel = $this->buildAccountLabel($backendUser);

        $this->assign('page_title', __('Two-Factor Authentication'));
        $this->assign('post_url', $this->url->getBackendUrl(self::ROUTE));
        $this->assign('flow_status', $this->twoFactorAccountService->getFlowStatus('backend'));
        $this->assign('flash_backup_codes', $this->consumeFlashBackupCodes());

        if ($this->twoFactorAccountService->isEnabled(ActorContext::ACTOR_BACKEND, $userId)) {
            $this->assign('is_enabled', true);
            $this->assign(
                'config',
                $this->twoFactorAccountService->getUserConfig(ActorContext::ACTOR_BACKEND, $userId) ?? []
            );
        } else {
            $this->assign('is_enabled', false);
            $this->assign(
                'setup',
                $this->twoFactorAccountService->initialize(
                    ActorContext::ACTOR_BACKEND,
                    $userId,
                    $accountLabel,
                    'WeShop Admin'
                )
            );
        }

        return $this->fetch('WeShop_Auth::templates/Backend/Security/two-factor.phtml');
    }

    #[Acl('WeShop_Auth::two_factor', 'Two-Factor Authentication', 'mdi mdi-cellphone-key', 'Manage two-factor authentication for the current backend account.')]
    public function postIndex(): string
    {
        /** @var BackendUser|null $backendUser */
        $backendUser = $this->session->getUser();
        if (!$backendUser || !(int) $backendUser->getId()) {
            $this->getMessageManager()->addError(__('Your backend session is no longer available. Please sign in again.'));
            $this->redirect($this->url->getBackendUrl('admin'));
            return '';
        }

        $userId = (int) $backendUser->getId();
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

        $this->redirect($this->url->getBackendUrl(self::ROUTE));
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
            ActorContext::ACTOR_BACKEND,
            $userId,
            $secret,
            $code,
            $backupCodes
        );

        if ($enabled) {
            $this->getMessageManager()->addSuccess(__('Two-factor authentication is now enabled for your backend account.'));
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

        if ($this->twoFactorAccountService->disable(ActorContext::ACTOR_BACKEND, $userId, $code)) {
            $this->getMessageManager()->addSuccess(__('Two-factor authentication has been disabled for your backend account.'));
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
            ActorContext::ACTOR_BACKEND,
            $userId,
            $code
        );

        if ($backupCodes === null) {
            $this->getMessageManager()->addError(__('The authenticator code is invalid. Backup codes were not regenerated.'));
            return;
        }

        $this->session->set(self::FLASH_BACKUP_CODES_KEY, $backupCodes);
        $this->getMessageManager()->addSuccess(__('New backup codes were generated. Save them before you leave this page.'));
    }

    private function consumeFlashBackupCodes(): array
    {
        $backupCodes = (array) ($this->session->get(self::FLASH_BACKUP_CODES_KEY) ?? []);
        $this->session->delete(self::FLASH_BACKUP_CODES_KEY);

        return $backupCodes;
    }

    private function decodeBackupCodes(string $encodedBackupCodes): array
    {
        $decoded = json_decode($encodedBackupCodes, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildAccountLabel(BackendUser $backendUser): string
    {
        $username = trim((string) ($backendUser->getUsername() ?? ''));
        $email = trim((string) ($backendUser->getEmail() ?? ''));

        if ($email !== '' && $username !== '') {
            return $username . ':' . $email;
        }

        return $email !== '' ? $email : $username;
    }
}
