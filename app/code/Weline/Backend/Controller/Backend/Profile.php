<?php

declare(strict_types=1);

namespace Weline\Backend\Controller\Backend;

use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\BackendUserConfig;
use Weline\Backend\Service\BackendPersonalLanguage;
use Weline\Backend\Service\BackendUserAdministration;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Backend::personal_profile', '我的资料', 'user', '管理基本资料', 'Weline_Backend::personal_center')]
class Profile extends BackendController
{
    #[Acl('Weline_Backend::personal_profile_index', '查看基本资料', 'user', '查看基本资料')]
    public function index(): string
    {
        if ($this->request->isPost()) {
            $this->saveBasicProfile();
        }

        $user = $this->requireCurrentUser();
        if ($user === null) {
            return $this->redirect($this->_url->getBackendUrl('admin'));
        }

        $this->assignAccountShell($user, 'profile');
        $this->assign('profile_username', (string)$user->getUsername());
        $this->assign('profile_email', (string)$user->getEmail());
        $this->assign('page_title', __('基本资料'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::personal_avatar', '头像设置', 'image', '设置个人头像', 'Weline_Backend::personal_center')]
    public function avatar(): string
    {
        if ($this->request->isPost()) {
            $this->saveAvatar();
        }

        $user = $this->requireCurrentUser();
        if ($user === null) {
            return $this->redirect($this->_url->getBackendUrl('admin'));
        }

        $this->assignAccountShell($user, 'avatar');
        $this->assign('profile_avatar', (string)$user->getAvatar());
        $this->assign('page_title', __('头像设置'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::personal_language', '个人语言', 'language', '设置个人语言', 'Weline_Backend::personal_center')]
    public function language(): string
    {
        if ($this->request->isPost()) {
            $this->saveLanguage();
        }

        $user = $this->requireCurrentUser();
        if ($user === null) {
            return $this->redirect($this->_url->getBackendUrl('admin'));
        }

        $personalLanguage = BackendPersonalLanguage::resolveForUserId((int)$user->getId());
        if ($personalLanguage === '') {
            $personalLanguage = State::resolveBackendDefaultLanguage();
        }

        $this->assignAccountShell($user, 'language');
        $this->assign('personal_language', $personalLanguage);
        $this->assign('page_title', __('个人语言'));

        return $this->fetch();
    }

    #[Acl('Weline_Backend::personal_password', '修改密码', 'lock', '修改登录密码', 'Weline_Backend::personal_center')]
    public function password(): string
    {
        if ($this->request->isPost()) {
            $this->savePassword();
        }

        $user = $this->requireCurrentUser();
        if ($user === null) {
            return $this->redirect($this->_url->getBackendUrl('admin'));
        }

        $this->assignAccountShell($user, 'password');
        $this->assign('page_title', __('修改密码'));

        return $this->fetch();
    }

    private function assignAccountShell(BackendUser $user, string $active): void
    {
        $username = (string)$user->getUsername();
        $this->assign('profile_nav_active', $active);
        $this->assign('profile_shell_username', $username);
        $this->assign('profile_shell_email', (string)$user->getEmail());
        $this->assign('profile_shell_avatar', (string)$user->getAvatar());
        $this->assign(
            'profile_shell_initial',
            $username !== '' ? \mb_strtoupper(\mb_substr($username, 0, 1)) : '?'
        );
    }

    private function requireCurrentUser(): ?BackendUser
    {
        $user = $this->loadCurrentUser();
        if ($user === null) {
            Message::error(__('无法加载当前管理员资料'));
        }

        return $user;
    }

    private function saveBasicProfile(): void
    {
        $user = $this->loadCurrentUser();
        if ($user === null) {
            Message::error(__('无法加载当前管理员资料'));
            return;
        }

        $userId = (int)$user->getId();
        $username = \trim((string)$this->request->getPost('username', ''));
        $email = \trim((string)$this->request->getPost('email', ''));

        if ($username === '' || $email === '') {
            Message::error(__('用户名和邮箱不能为空'));
            return;
        }
        if (!\filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            Message::error(__('请输入有效的邮箱地址'));
            return;
        }

        try {
            /** @var BackendUserAdministration $adminUsers */
            $adminUsers = ObjectManager::getInstance(BackendUserAdministration::class);
            $adminUsers->save($userId, $username, $email, null);
            Message::success(__('基本资料已保存'));
        } catch (\Throwable $e) {
            Message::error(__('基本资料保存失败：%{1}', [$e->getMessage()]));
        }
    }

    private function saveAvatar(): void
    {
        $user = $this->loadCurrentUser();
        if ($user === null) {
            Message::error(__('无法加载当前管理员资料'));
            return;
        }

        $userId = (int)$user->getId();
        $avatar = \trim((string)$this->request->getPost('avatar', ''));

        try {
            $user->clear()->load($userId);
            if ((int)$user->getId() !== $userId) {
                Message::error(__('无法加载当前管理员资料'));
                return;
            }
            $user->setAvatar($avatar)->save();
            Message::success(__('头像已保存'));
        } catch (\Throwable $e) {
            Message::error(__('头像保存失败：%{1}', [$e->getMessage()]));
        }
    }

    private function saveLanguage(): void
    {
        $user = $this->loadCurrentUser();
        if ($user === null) {
            Message::error(__('无法加载当前管理员资料'));
            return;
        }

        $language = \trim((string)$this->request->getPost('personal_language', ''));
        if ($language !== '' && !State::isLanguageCodeShape($language)) {
            Message::error(__('请选择一个有效的个人语言'));
            return;
        }

        $language = \str_replace('-', '_', $language);
        if ($language === '') {
            $language = State::resolveBackendDefaultLanguage();
        }

        /** @var BackendUserConfig $config */
        $config = ObjectManager::getInstance(BackendUserConfig::class);
        $saved = $config->setConfig(
            BackendPersonalLanguage::CONFIG_KEY,
            $language,
            BackendPersonalLanguage::CONFIG_MODULE,
            BackendPersonalLanguage::CONFIG_NAME
        );
        if (!$saved) {
            Message::error(__('个人语言保存失败'));
            return;
        }

        BackendPersonalLanguage::primeRuntime($language);
        Message::success(__('个人语言已保存'));
    }

    private function savePassword(): void
    {
        $user = $this->loadCurrentUser();
        if ($user === null) {
            Message::error(__('无法加载当前管理员资料'));
            return;
        }

        $userId = (int)$user->getId();
        $currentPassword = (string)$this->request->getPost('current_password', '');
        $newPassword = (string)$this->request->getPost('new_password', '');
        $confirmPassword = (string)$this->request->getPost('new_password_confirm', '');

        if ($currentPassword === '' || $newPassword === '') {
            Message::error(__('请填写当前密码和新密码'));
            return;
        }
        if (!\password_verify($currentPassword, (string)$user->getPassword())) {
            Message::error(__('当前密码不正确'));
            return;
        }
        if (\strlen($newPassword) < 6) {
            Message::error(__('新密码至少 6 位'));
            return;
        }
        if ($newPassword !== $confirmPassword) {
            Message::error(__('两次输入的新密码不一致'));
            return;
        }

        try {
            /** @var BackendUserAdministration $adminUsers */
            $adminUsers = ObjectManager::getInstance(BackendUserAdministration::class);
            $adminUsers->save(
                $userId,
                (string)$user->getUsername(),
                (string)$user->getEmail(),
                $newPassword
            );
            Message::success(__('密码已更新'));
        } catch (\Throwable $e) {
            Message::error(__('密码更新失败：%{1}', [$e->getMessage()]));
        }
    }

    private function loadCurrentUser(): ?BackendUser
    {
        $userId = (int)($this->session->getLoginUserID() ?? 0);
        if ($userId <= 0) {
            return null;
        }
        /** @var BackendUser $user */
        $user = ObjectManager::getInstance(BackendUser::class);
        $user->clear()->load($userId);
        if ((int)$user->getId() !== $userId) {
            return null;
        }

        return $user;
    }
}
