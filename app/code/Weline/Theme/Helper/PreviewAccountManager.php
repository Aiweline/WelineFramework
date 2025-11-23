<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Model\FrontendUser;
use Weline\Frontend\Session\FrontendUserSession;
use Weline\Theme\Model\WelineTheme;
use Weline\SystemConfig\Model\SystemConfig;

class PreviewAccountManager
{
    private const DEFAULT_USERNAME = 'preview_theme';
    private const DEFAULT_EMAIL = 'preview_theme@preview.local';
    private const PASSWORD_CONFIG_KEY = 'preview_account_password';
    /**
     * 确保预览用户存在（通过主题ID）
     *
     * @param int $themeId
     * @return FrontendUser|null
     */
    public static function ensurePreviewUserByThemeId(int $themeId): ?FrontendUser
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        if (!$theme->getId()) {
            return null;
        }
        return self::ensurePreviewUser($theme);
    }

    /**
     * 确保预览用户存在
     *
     * @param WelineTheme $theme
     * @return FrontendUser|null
     */
    public static function ensurePreviewUser(WelineTheme $theme): ?FrontendUser
    {
        $config = $theme->getConfig();
        $previewConfig = self::normalizePreviewConfig($config['preview_user'] ?? []);

        /** @var FrontendUser $user */
        $user = ObjectManager::getInstance(FrontendUser::class);
        $user->where('username', $previewConfig['username'])->find()->fetch();

        $isNewUser = false;

        if (!$user->getId()) {
            $user->reset();
            $user->setUsername($previewConfig['username'])
                ->setEmail($previewConfig['email'])
                ->setPassword($previewConfig['password'])
                ->setStatus(1)
                ->save();
            $isNewUser = true;
        } else {
            $updated = false;
            if ($previewConfig['email'] !== $user->getData('email')) {
                $user->setEmail($previewConfig['email']);
                $updated = true;
            }
            if (!password_verify($previewConfig['password'], $user->getPassword())) {
                $user->setPassword($previewConfig['password']);
                $updated = true;
            }
            if ($updated) {
                $user->save();
            }
        }

        if ($isNewUser) {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventsManager->dispatch(
                'Weline_Weline_Frontend_Account_Register::register_after',
                new DataObject([
                    'user' => $user,
                    'source' => 'theme_preview'
                ])
            );
        }

        $previewConfig['user_id'] = $user->getId();
        $config['preview_user'] = $previewConfig;
        $theme->setConfig($config)->save();

        return $user;
    }

    /**
     * 登录预览用户
     *
     * @param int $themeId
     * @return void
     */
    public static function loginPreviewUserByThemeId(int $themeId): void
    {
        $user = self::ensurePreviewUserByThemeId($themeId);
        if (!$user) {
            return;
        }

        /** @var FrontendUserSession $frontendSession */
        $frontendSession = ObjectManager::getInstance(FrontendUserSession::class);
        if ($frontendSession->isLogin() && $frontendSession->getLoginUserID() === $user->getId()) {
            return;
        }

        $frontendSession->login($user);
        $user->setSessionId($frontendSession->getSessionId())
            ->setLoginIp($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
            ->save();
    }

    /**
     * 退出预览用户
     *
     * @param int|null $themeId
     * @return void
     */
    public static function logoutPreviewUser(?int $themeId = null): void
    {
        /** @var FrontendUserSession $frontendSession */
        $frontendSession = ObjectManager::getInstance(FrontendUserSession::class);
        if (!$frontendSession->isLogin()) {
            return;
        }

        $previewUserId = self::getPreviewUserId();
        if ($previewUserId && $frontendSession->getLoginUserID() !== $previewUserId) {
            return;
        }

        $frontendSession->logout();
    }

    /**
     * 获取配置中的预览用户 ID
     *
     * @return int|null
     */
    private static function getPreviewUserId(): ?int
    {
        /** @var FrontendUser $user */
        $user = ObjectManager::getInstance(FrontendUser::class);
        $user->where('username', self::DEFAULT_USERNAME)->find()->fetch();
        return $user->getId() ?: null;
    }

    /**
     * 规范化预览用户配置
     *
     * @return array
     */
    private static function normalizePreviewConfig(array $previewConfig): array
    {
        $previewConfig['username'] = self::DEFAULT_USERNAME;
        $previewConfig['email'] = $previewConfig['email'] ?? self::DEFAULT_EMAIL;
        $previewConfig['password'] = self::getPreviewPassword();
        return $previewConfig;
    }

    /**
     * 获取或生成预览账号密码
     */
    private static function getPreviewPassword(): string
    {
        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        $password = (string)$systemConfig->getConfig(self::PASSWORD_CONFIG_KEY, 'Weline_Theme', SystemConfig::area_BACKEND);

        if (empty($password)) {
            $password = self::generatePassword();
            $systemConfig->setConfig(self::PASSWORD_CONFIG_KEY, $password, 'Weline_Theme', SystemConfig::area_BACKEND);
        }

        return $password;
    }

    /**
     * 生成随机密码
     */
    private static function generatePassword(): string
    {
        return 'Preview@' . bin2hex(random_bytes(3)) . random_int(100, 999);
    }
}

