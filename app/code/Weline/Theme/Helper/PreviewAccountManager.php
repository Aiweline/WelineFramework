<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Theme\Api\PreviewAccountProviderInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\SystemConfig\Api\ConfigStore as SystemConfig;

class PreviewAccountManager
{
    private const DEFAULT_USERNAME = 'preview_theme';
    private const DEFAULT_EMAIL = 'preview_theme@preview.local';
    private const PASSWORD_CONFIG_KEY = 'preview_account_password';
    /**
     * 确保预览用户存在（通过主题ID）
     *
     * @param int $themeId
     * @return AuthenticableInterface|null
     */
    public static function ensurePreviewUserByThemeId(int $themeId): ?AuthenticableInterface
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
     * @return AuthenticableInterface|null
     */
    public static function ensurePreviewUser(WelineTheme $theme): ?AuthenticableInterface
    {
        $config = $theme->getConfig();
        $previewConfig = self::normalizePreviewConfig($config['preview_user'] ?? []);
        $provider = self::getProvider();
        if (!$provider) {
            return null;
        }

        $user = $provider->ensurePreviewAccount(
            $previewConfig['username'],
            $previewConfig['email'],
            $previewConfig['password'],
        );
        if (!$user) {
            return null;
        }

        $previewConfig['user_id'] = $user->getAuthIdentifier();
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

        /** @var AuthenticatedSessionInterface $frontendSession */
        $frontendSession = SessionFactory::getInstance()->createFrontendSession();
        if (
            $frontendSession->isLoggedIn()
            && (string)$frontendSession->getUserId() === (string)$user->getAuthIdentifier()
        ) {
            return;
        }

        $frontendSession->login($user);
        $provider = self::getProvider();
        if ($provider) {
            $provider->recordPreviewLogin(
                $user,
                $frontendSession->getId(),
                (string)(\w_env('server.remote_addr', '127.0.0.1') ?? '127.0.0.1'),
            );
        }
    }

    /**
     * 退出预览用户
     *
     * @param int|null $themeId
     * @return void
     */
    public static function logoutPreviewUser(?int $themeId = null): void
    {
        /** @var AuthenticatedSessionInterface $frontendSession */
        $frontendSession = SessionFactory::getInstance()->createFrontendSession();
        if (!$frontendSession->isLoggedIn()) {
            return;
        }

        $previewUserId = self::getPreviewUserId();
        if ($previewUserId === null) {
            return;
        }
        if ((string)$frontendSession->getUserId() !== (string)$previewUserId) {
            return;
        }

        $frontendSession->logout();
    }

    /**
     * 获取配置中的预览用户 ID
     *
     * @return int|string|null
     */
    private static function getPreviewUserId(): int|string|null
    {
        return self::getProvider()?->findPreviewAccountId(self::DEFAULT_USERNAME);
    }

    private static function getProvider(): ?PreviewAccountProviderInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(PreviewAccountProviderInterface::class);

        return $provider instanceof PreviewAccountProviderInterface ? $provider : null;
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
